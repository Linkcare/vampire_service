<?php
// Service functions invoked from the Linkcare Platform's background daemon
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

require $_SERVER["DOCUMENT_ROOT"] . '/vendor/autoload.php';
use avadim\FastExcelReader\Excel;
use MongoDB\Driver\Exception\ServerException;

// Deactivate CORS for debug
$GLOBALS['DISABLE_CORS'] = true;
if ($GLOBALS['DISABLE_CORS']) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: X-API-token, Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');

    // If it is a preflight request, respond with 204 No Content
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}

// Link the config params
require_once ("src/default_conf.php");

setSystemTimeZone();
error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    return;
}

// Response is always returned as JSON
header('Content-type: application/json');

$function = $_GET['function'];
$logger = ServiceLogger::init($GLOBALS['LOG_LEVEL'], $GLOBALS['LOG_DIR']);

$publicFunctions = ['track_pending_shipments', 'track_pending_receptions', 'import_CQS_blood_processing_data'];

if (in_array($function, $publicFunctions)) {
    $json = file_get_contents('php://input');
    try {
        $parameters = json_decode($json);
        if (trim($json) != '' && $parameters == null) {
            throw new Exception("Invalid parameters");
        }

        // The public rest function invoked from the Linkcare Platform's PROGRAM must be executed in a service session
        WSAPI::apiConnect($GLOBALS["WS_LINK"], null, $GLOBALS["SERVICE_USER"], $GLOBALS["SERVICE_PASSWORD"], null, null, false,
                $GLOBALS['DEFAULT_LANGUAGE'], $GLOBALS['DEFAULT_TIMEZONE']);
        Database::init($GLOBALS['SERVICE_DB_URI'], $logger);
        Database::getInstance()->beginTransaction(); // Execute all commands in transactional mode
        $serviceResponse = $function($parameters);
        Database::getInstance()->commit();
    } catch (ServiceException $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
        $logger->error("Service Exception: " . $e->getErrorMessage());
        $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $e->getErrorMessage());
    } catch (Exception $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
        $logger->error("General exception: " . $e->getMessage());
        $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $e->getMessage());
    } catch (Error $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
        $logger->error("Execution error: " . $e->getMessage());
        $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $e->getMessage());
    } finally {
        WSAPI::apiDisconnect();
    }
} else {
    $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, "Function $function not implemented");
}

echo $serviceResponse->toString();
return;

/* ****************************************************************** */
/* ********************* PUBLIC REST FUNCTIONS ********************** */
/* ****************************************************************** */

/**
 * Verifies if there are new blood sample shipments created from the Shipment Control application that need to be tracked in the eCRF.
 * If true, a new "SHIPMENT TRACKING" TASK will be created in each affected ADMISSION of the eCRF
 *
 * @param stdClass $parameters
 * @return BackgroundServiceResponse
 */
function track_pending_shipments($parameters) {
    // Find the shipped aliquots that have not been tracked yet in the eCRF
    $sql = "SELECT DISTINCT sa.ID_SHIPMENT, a.ID_PATIENT FROM SHIPPED_ALIQUOTS sa, SHIPMENTS s, ALIQUOTS a
            WHERE s.ID_SHIPMENT=sa.ID_SHIPMENT AND s.ID_STATUS IN ('SHIPPED', 'RECEIVED')
                AND (sa.ID_SHIPMENT_TASK IS NULL OR sa.ID_SHIPMENT_TASK=0) AND sa.ID_ALIQUOT = a.ID_ALIQUOT
            ORDER BY s.SHIPMENT_DATE, a.ID_PATIENT";
    $rst = Database::getInstance()->executeBindQuery($sql, ['statusId' => ShipmentStatus::SHIPPED]);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
    }

    $pendingShipmentIds = [];
    while ($rst->Next()) {
        $pendingShipmentIds[$rst->GetField('ID_SHIPMENT')] = $rst->GetField('ID_PATIENT');
    }
    if (empty($pendingShipmentIds)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, 'No pending shipments to track.');
    }

    $shipment = null;
    $errorMessages = [];
    $numSuccess = 0;
    $numIgnored = 0;
    $numErrors = 0;
    foreach ($pendingShipmentIds as $shipmentId => $patientId) {
        if (!$shipment || $shipment->id != $shipmentId) {
            // Load the shipment only if it has not been loaded yet
            $shipment = Shipment::exists($shipmentId);
            if (!$shipment) {
                $errorMessages[] = "Shipment with ID $shipmentId not found.";
                $numIgnored++;
                continue;
            }
        }

        try {
            ServiceFunctions::createShipmentTrackingTask($shipment, $patientId);
            $numSuccess++;
        } catch (ServiceException $e) {
            $errorMessages[] = "Shipment with ID $shipmentId failed to be tracked in eCRF: " . $e->getErrorMessage();
            $numErrors++;
        }
    }

    if (($numErrors + $numIgnored) > 0) {
        $retCode = BackgroundServiceResponse::ERROR;
    } elseif ($numSuccess > 0) {
        $retCode = BackgroundServiceResponse::SUCCESS;
    } else {
        $retCode = BackgroundServiceResponse::IDLE;
    }
    $response = new BackgroundServiceResponse($retCode, "Shipments updated successfully: $numSuccess, Ignored: $numIgnored, Errors: $numErrors");
    foreach ($errorMessages as $errorMessage) {
        $response->addDetails($errorMessage);
    }

    return $response;
}

/**
 * Verifies if there are blood sample shipments marked as received (from the Shipment Control application) that need to be tracked in the eCRF.
 * If true, a new "RECEPTION TRACKING" TASK will be created in each affected ADMISSION of the eCRF
 *
 * @param stdClass $parameters
 * @return BackgroundServiceResponse
 */
function track_pending_receptions($parameters) {
    // Find the shipped aliquots that have not been tracked yet in the eCRF
    $sql = "SELECT DISTINCT sa.ID_SHIPMENT, a.ID_PATIENT, sa.ID_SHIPMENT_TASK FROM SHIPPED_ALIQUOTS sa, SHIPMENTS s, ALIQUOTS a
            WHERE s.ID_SHIPMENT=sa.ID_SHIPMENT AND s.ID_STATUS='RECEIVED' 
                AND sa.ID_SHIPMENT_TASK > 0 
                AND (sa.ID_RECEPTION_TASK IS NULL OR sa.ID_RECEPTION_TASK=0)
                AND sa.ID_ALIQUOT = a.ID_ALIQUOT
            ORDER BY s.SHIPMENT_DATE, a.ID_PATIENT";
    $rst = Database::getInstance()->executeBindQuery($sql, ['statusId' => ShipmentStatus::SHIPPED]);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
    }

    $pendingShipmentIds = [];
    while ($rst->Next()) {
        $pendingShipmentIds[$rst->GetField('ID_SHIPMENT')] = ['patientId' => $rst->GetField('ID_PATIENT'),
                'trackingTaskId' => $rst->GetField('ID_SHIPMENT_TASK')];
    }
    if (empty($pendingShipmentIds)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, 'No pending receptions to track.');
    }

    $shipment = null;
    $errorMessages = [];
    $numSuccess = 0;
    $numIgnored = 0;
    $numErrors = 0;
    foreach ($pendingShipmentIds as $shipmentId => $data) {
        $patientId = $data['patientId'];
        $trackingTaskId = $data['trackingTaskId'];
        if (!$shipment || $shipment->id != $shipmentId) {
            // Load the shipment only if it has not been loaded yet
            $shipment = Shipment::exists($shipmentId);
            if (!$shipment) {
                $errorMessages[] = "Shipment with ID $shipmentId not found.";
                $numIgnored++;
                continue;
            }
        }

        try {
            ServiceFunctions::createReceptionTrackingTask($shipment, $patientId, $trackingTaskId);
            $numSuccess++;
        } catch (ServiceException $e) {
            $errorMessages[] = "Reception of shipment with ID $shipmentId failed to be tracked in eCRF: " . $e->getErrorMessage();
            $numErrors++;
        }
    }

    $retCode = ($numErrors + $numIgnored) > 0 ? BackgroundServiceResponse::ERROR : BackgroundServiceResponse::SUCCESS;
    $response = new BackgroundServiceResponse($retCode, "Shipment receptions updated successfully: $numSuccess, Ignored: $numIgnored, Errors: $numErrors");
    foreach ($errorMessages as $errorMessage) {
        $response->addDetails($errorMessage);
    }

    return $response;
}

/**
 * Imports the blood processing data of the patients from an Excel file provided by CQS and placed in the cqs_data directory.
 *
 * @param stdClass $parameters
 * @return BackgroundServiceResponse
 */
function import_CQS_blood_processing_data($parameters) {
    $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "");

    $cqsFiles = glob($GLOBALS['CQS_IMPORT_DIR'] . '/*.xlsx');
    if (empty($cqsFiles)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "No CQS data files (*.xlsx) pending to import.");
    }

    $executionResult = BackgroundServiceResponse::SUCCESS;

    $filePath = $cqsFiles[0]; // Get the first file found;
    $processFile = $filePath . '.processing';
    $logFile = $filePath . '.log';
    ServiceLogger::getInstance()->setCustomLogFile($logFile);

    if (file_exists($processFile) && !unlink($processFile)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "Error deleting previous file: $processFile. Verify the the directory is writable.");
    }

    if (!rename($filePath, $processFile)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "Error renaming $filePath to $processFile. Verify the the directory is writable.");
    }

    $cqsData = loadCQSBloodProcessingData($processFile);

    $numErrors = 0;
    $numSuccessful = 0;
    $numSkipped = 0;

    foreach ($cqsData as $bloodSampleRef => $aliquotsData) {
        try {
            $patient = null;
            $bpForm = null;
            $aliquotIds = [];
            foreach ($aliquotsData as $type => $ids) {
                $aliquotIds = array_merge($aliquotIds, $ids);
            }
            if (count($aliquotIds) == count(Aliquot::findAliquots($aliquotIds))) {
                // All aliquots already exist. No need to import
                $msg = "Sample $bloodSampleRef already loaded. Data skipped.";
                $serviceResponse->addDetails($msg);
                ServiceLogger::getInstance()->info($msg);
                $numSkipped++;
                continue;
            }

            /** @var APICase $patient */
            list($patient, $bpForm) = ServiceFunctions::findFormFromBloodSampleId($bloodSampleRef);

            ServiceFunctions::updateBloodProcessingData($bpForm, $aliquotsData);

            $msg = "Sample $bloodSampleRef (" . $patient->getNickname() . "): Imported successfully.";
            $serviceResponse->addDetails($msg);
            ServiceLogger::getInstance()->info($msg);
            $numSuccessful++;
        } catch (Exception $e) {
            $executionResult = BackgroundServiceResponse::ERROR;
            $patientRef = $patient ? $patient->getNickname() : 'unknown patient';
            $msg = "Sample $bloodSampleRef ($patientRef): ERROR " . $e->getMessage();
            $serviceResponse->addDetails($msg);
            ServiceLogger::getInstance()->error($msg);
            $numErrors++;
        }

        echo " "; // Send space to the output buffer to avoid timeouts, because the processing of all patients can take a long time
        flush();
    }

    $msg = "CQS blood processing data finished. Errors: $numErrors, Successful: $numSuccessful, Skipped: $numSkipped, Total samples processed: " .
            count($cqsData);
    $serviceResponse->setMessage($msg);
    $serviceResponse->setCode($executionResult);

    if ($executionResult == BackgroundServiceResponse::SUCCESS) {
        rename($processFile, str_replace('.processing', '.ok', $processFile)); // Rename the processing file to .ok to avoid reprocessing);
                                                                               // unlink($processFile); // Remove the processing file after processing
    } else {
        rename($processFile, str_replace('.processing', '.error', $processFile)); // Rename the processing file to .error to avoid reprocessing);
    }

    return $serviceResponse;
}

/* ******* Internal funcions ************************************************** */
/**
 * Loads the data provided by CQS about the blood processig of the patients from an Excel file.
 * The returned value is a 3-dimensional array indexed by patient reference and aliquot type.
 * The contents of each item is an array with the IDs of the aliquots of that type for that patient.
 * Example:
 * ['PAT001' => ['whole_blood' => [aliquot_id1, aliquot_id2, ...], ]]
 *
 * @param string $processFile
 * @return array Associative array with the IDs of the aliquots indexed by patient reference / aliquot type
 */
function loadCQSBloodProcessingData($processFile) {
    try {
        $excel = Excel::open($processFile);
    } catch (Exception $e) {
        throw new ServiceException("Error opening file: $processFile: " . $e->getMessage());
    }

    $excel->dateFormatter('Y-m-d');
    $sheet = $excel->sheet("Datos");
    if (!$sheet) {
        throw new ServiceException("Sheet 'Datos' not found in file: $processFile");
    }

    // OR
    $aliquots = [];
    foreach ($sheet->nextRow([], Excel::KEYS_FIRST_ROW) as $rowNum => $rowData) {
        // sample_id order_id sample_type collection_date plate position plate_location plate_collection plate_delivery patient_id volume haemolysis
        // plate_type key
        $bloodSampleId = $rowData['order_id'];
        if (trim($rowData['sample_id']) == '') {
            throw new ServiceException("Unknown sample type: " . $rowData['sample_type'] . "in file $processFile, row: $rowNum");
        }
        switch (strtolower($rowData['sample_type'])) {
            case 'sangre total' :
                $type = 'WHOLE_BLOOD';
                break;
            case 'plasma edta' :
                $type = 'PLASMA';
                break;
            case 'mononucleares' :
                $type = 'PBMC';
                break;
            case 'suero' :
                $type = 'SERUM';
                break;
            default :
                throw new ServiceException("Unknown sample type: " . $rowData['sample_type'] . "in file $processFile, row: $rowNum");
        }
        if (array_key_exists($bloodSampleId, $aliquots) && array_key_exists($type, $aliquots[$bloodSampleId])) {
            $aliquots[$bloodSampleId][$type][] = $rowData['sample_id'];
        } else {
            $aliquots[$bloodSampleId][$type] = [$rowData['sample_id']];
        }
    }

    return $aliquots;
}