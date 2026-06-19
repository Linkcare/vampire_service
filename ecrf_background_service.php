<?php
// Service functions invoked from the Linkcare Platform's background daemon
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

require_once $_SERVER["DOCUMENT_ROOT"] . '/vendor/autoload.php';
use avadim\FastExcelReader\Excel;

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

$publicFunctions = ['track_pending_shipments', 'track_pending_receptions', 'import_blood_processing_data', 'export_prospective_data',
        'export_retrospective_data'];

if (in_array($function, $publicFunctions)) {
    $json = file_get_contents('php://input');
    try {
        $parameters = json_decode($json);
        if (trim($json) != '' && $parameters == null) {
            throw new Exception("Invalid parameters");
        }

        // The public rest function invoked from the Linkcare Platform's PROGRAM must be executed in a service session
        $apiSession = initServiceSession();
        Database::init($GLOBALS['SERVICE_DB_URI'], $logger);
        Database::getInstance()->beginTransaction(); // Execute all commands in transactional mode
        ShipmentFunctions::setTimezone($apiSession->getTimezone());
        $serviceResponse = $function($parameters);
        Database::getInstance()->commit();
    } catch (ServiceException $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
        $logger->error("Service Exception: " . $e->getErrorMessage());
        $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $e->getErrorMessage());
    } catch (ShipmentException $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
        $logger->error("Service Exception: " . $e->getErrorMessage());
        $serviceResponse = new ServiceResponse(null, $e->getErrorMessage());
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

/**
 * Initializes an API session for the Service User configured in the system.
 *
 * @return APISession
 */
function initServiceSession() {
    /* All the operations will be performed by a "service" user */
    $api = WSAPI::apiConnect($GLOBALS["WS_LINK"], null, $GLOBALS["SERVICE_USER"], $GLOBALS["SERVICE_PASSWORD"], null, null, false,
            $GLOBALS["DEFAULT_LANGUAGE"], $GLOBALS["DEFAULT_TIMEZONE"]);
    return $api->getSession();
}

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
    $response = new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "");

    // Find the shipped aliquots that have not been tracked yet in the eCRF
    $untrackedShipments = ShipmentFunctions::untrackedShipments();

    if (empty($untrackedShipments)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, 'No shipments pending to be tracked.');
    }

    $shipment = null;
    $numSuccess = 0;
    $numErrors = 0;
    foreach ($untrackedShipments as $shipmentData) {
        /** @var Shipment $shipment */
        $shipment = $shipmentData['shipment'];
        $patientIdsInShipment = $shipmentData['patients'];
        $shipmentId = $shipment->id;

        $patientsSuccess = 0;
        $patientsError = 0;
        foreach ($patientIdsInShipment as $data) {
            $patientId = $data['patientId'];
            $patientRef = $data['patientRef'];
            try {
                ServiceFunctions::createShipmentTrackingTask($shipment, $patientId);
                $msg = "Patient $patientRef: Shipment with ID $shipmentId tracked successfully in eCRF";
                $response->addDetails($msg);
                $patientsSuccess++;
            } catch (ServiceException $e) {
                $msg = "ERROR Patient $patientRef: Shipment with ID $shipmentId failed to be tracked in eCRF" . $e->getErrorMessage();
                $response->addDetails($msg);
                $patientsError++;
            }
        }
        $msg = "SHIPMENT $shipmentId updated: patients success: $patientsSuccess, errors: $patientsError";
        $response->addDetails($msg);
        if ($patientsError > 0) {
            $numErrors++;
        } else {
            $numSuccess++;
        }
    }

    if ($numErrors > 0) {
        $retCode = BackgroundServiceResponse::ERROR;
    } elseif ($numSuccess > 0) {
        $retCode = BackgroundServiceResponse::SUCCESS;
    } else {
        $retCode = BackgroundServiceResponse::IDLE;
    }
    $response->setMessage("Shipments updated successfully: $numSuccess, Errors: $numErrors");
    $response->setCode($retCode);

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
    $response = new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "");

    // Find the shipped aliquots that have not been tracked yet in the eCRF
    $untrackedReceptions = ShipmentFunctions::untrackedReceptions();
    if (empty($untrackedReceptions)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, 'No shipment receptions pending be tracked.');
    }

    $shipment = null;
    $numSuccess = 0;
    $numErrors = 0;
    foreach ($untrackedReceptions as $shipmentData) {
        /** @var Shipment $shipment */
        $shipment = $shipmentData['shipment'];
        $patientIdsInShipment = $shipmentData['patients'];
        $shipmentId = $shipment->id;

        $patientsSuccess = 0;
        $patientsError = 0;
        foreach ($patientIdsInShipment as $data) {
            $patientId = $data['patientId'];
            $patientRef = $data['patientRef'];
            $trackingTaskId = $data['trackingTaskId'];

            try {
                ServiceFunctions::createReceptionTrackingTask($shipment, $patientId, $trackingTaskId);
                $msg = "Patient $patientRef: Shipment with ID $shipmentId tracked successfully in eCRF";
                $response->addDetails($msg);
                $patientsSuccess++;
            } catch (ServiceException $e) {
                $msg = "ERROR Patient $patientRef: Shipment with ID $shipmentId failed to be tracked in eCRF" . $e->getErrorMessage();
                $response->addDetails($msg);
                $patientsError++;
            }
        }
        $msg = "SHIPMENT $shipmentId updated: patients success: $patientsSuccess, errors: $patientsError";
        $response->addDetails($msg);
        if ($patientsError > 0) {
            $numErrors++;
        } else {
            $numSuccess++;
        }
    }

    $retCode = $numErrors > 0 ? BackgroundServiceResponse::ERROR : BackgroundServiceResponse::SUCCESS;

    $response->setMessage("Shipment receptions updated successfully: $numSuccess, Errors: $numErrors");
    $response->setCode($retCode);

    return $response;
}

/**
 * Imports the blood processing data of the patients from an Excel file provided by the clinical sites and placed in the corresponding data directory.
 *
 * @param stdClass $parameters
 * @return BackgroundServiceResponse
 */
function import_blood_processing_data($parameters) {
    $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "");
    $numErrors = 0;
    $numSuccessful = 0;
    $numSkipped = 0;
    $executionResult = BackgroundServiceResponse::SUCCESS;
    $filesProcessed = 0;

    $teamsToImport = ['CQS', 'IPIN'];
    foreach ($teamsToImport as $teamCode) {
        $importDir = null;
        $loadFunction = null;
        switch ($teamCode) {
            case 'CQS' :
                $importDir = $GLOBALS['CQS_IMPORT_DIR'];
                $loadFunction = 'loadCQSBloodProcessingData';
                break;
            case 'IPIN' :
                $importDir = $GLOBALS['IPIN_IMPORT_DIR'];
                $loadFunction = 'loadIPINBloodProcessingData';
                break;
            default :
                $msg = "Unknown team code: $teamCode. No data will be imported.";
                ServiceLogger::getInstance()->error($msg);
                $serviceResponse->addDetails($msg);
                $executionResult = BackgroundServiceResponse::ERROR;
                break;
        }
        if (!$importDir || !$loadFunction) {
            continue;
        }

        $filesToImport = glob($importDir . '/*.xlsx');
        if (empty($filesToImport)) {
            $msg = "$teamCode: No Blood Processing data files (*.xlsx) pending to import.";
            $serviceResponse->addDetails($msg);
            ServiceLogger::getInstance()->info($msg);
            continue;
        }

        $filePath = $filesToImport[0]; // Process only one file of each team at a time
        $serviceResponse->addDetails("$teamCode: Importing blood processing from file " . basename($filePath));
        $processFile = $filePath . '.processing';
        $logFile = $filePath . '.log';
        ServiceLogger::getInstance()->setCustomLogFile($logFile);

        $filesProcessed++;

        if (file_exists($processFile) && !unlink($processFile)) {
            $msg = "$teamCode: Error deleting previous file: $processFile. Verify the the directory is writable.";
            $serviceResponse->addDetails($msg);
            ServiceLogger::getInstance()->error($msg);
            $executionResult = BackgroundServiceResponse::ERROR;
            continue;
        }

        if (!rename($filePath, $processFile)) {
            $msg = "$teamCode: Error renaming $filePath to $processFile. Verify the the directory is writable.";
            $serviceResponse->addDetails($msg);
            ServiceLogger::getInstance()->error($msg);
            $executionResult = BackgroundServiceResponse::ERROR;
            continue;
        }

        $importedData = $loadFunction($processFile, $teamCode);

        foreach ($importedData as $patientSamples) {
            try {
                $patient = $patientSamples['patient'];
                $bpForm = $patientSamples['form'];
                $displayName = $patientSamples['displayName'];
                if ($loadError = $patientSamples['error']) {
                    throw new Exception($loadError);
                }

                if (!$patient || !$bpForm) {
                    continue;
                }

                $aliquotIds = [];
                foreach (array_values($patientSamples['samples']) as $ids) {
                    $aliquotIds = array_merge($aliquotIds, $ids);
                }
                $alreadyExisting = Aliquot::findAliquots($aliquotIds);
                if (count($aliquotIds) == count($alreadyExisting)) {
                    // All aliquots already exist. No need to import
                    $msg = "Aliquots of sample $displayName already loaded. Data skipped.";
                    $serviceResponse->addDetails($msg);
                    ServiceLogger::getInstance()->info($msg);
                    $numSkipped++;
                    continue;
                } elseif (count($alreadyExisting) > 0) {
                    $msg = "Aliquots of sample $displayName modified (previous import had " . count($alreadyExisting) . " aliquots). Now: " .
                            count($aliquotIds);
                } else {
                    $msg = "Sample $displayName: Imported successfully (" . count($aliquotIds) . " aliquots)";
                }

                ServiceFunctions::updateBloodProcessingData($bpForm, $patientSamples['samples']);

                $serviceResponse->addDetails($msg);
                ServiceLogger::getInstance()->info($msg);
                $numSuccessful++;
            } catch (Exception $e) {
                $executionResult = BackgroundServiceResponse::ERROR;
                $msg = "Sample $displayName: ERROR " . $e->getMessage();
                $serviceResponse->addDetails($msg);
                ServiceLogger::getInstance()->error($msg);
                $numErrors++;
            }

            echo " "; // Send space to the output buffer to avoid timeouts, because the processing of all patients can take a long time
            flush();
        }
    }

    if ($filesProcessed == 0 && $executionResult != BackgroundServiceResponse::ERROR) {
        // There were no files to process. Indicate IDLE status
        $executionResult = BackgroundServiceResponse::IDLE;
        $serviceResponse->setMessage("No Blood Processing data files pending to import.");
    } else {
        $msg = "Blood processing data import process finished. Errors: $numErrors, Successful: $numSuccessful, Skipped: $numSkipped, Total samples processed: " .
                count($importedData);
        $serviceResponse->setMessage($msg);
    }

    $serviceResponse->setCode($executionResult);

    if ($executionResult == BackgroundServiceResponse::SUCCESS) {
        rename($processFile, str_replace('.processing', '.ok', $processFile)); // Rename the processing file to .ok to avoid reprocessing);
                                                                               // unlink($processFile); // Remove the processing file after processing
    } else {
        rename($processFile, str_replace('.processing', '.error', $processFile)); // Rename the processing file to .error to avoid reprocessing);
    }

    return $serviceResponse;
}

/**
 *
 * @param stdClass $parameters
 * @return BackgroundServiceResponse
 */
function export_prospective_data($parameters) {
    $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "");
    $executionResult = BackgroundServiceResponse::SUCCESS;

    $numSuccessful = 0;
    $numErrors = 0;
    $numSkipped = 0;

    $api = LinkcareSoapAPI::getInstance();

    /* Retrieve the list of valid Admissions (do not include REJECTED or DISCHARGED for any reason different than END OF PROGRAM */
    $batchSize = 100;
    $ix = 0;

    $exporter = new DataExport($GLOBALS['PROSPECTIVE_EXPORT_DIR']);

    do {
        try {
            $admissions = $api->admission_list_program($GLOBALS['PROJECT_CODE'], 'ACTIVE,ENROLLED,DISCHARGED', null, null, $batchSize, $ix,
                    'ID_PATIENT');
        } catch (Exception $e) {
            $serviceResponse->setCode(BackgroundServiceResponse::ERROR);
            $serviceResponse->setMessage("Error retrieving admissions from API: " . $e->getMessage());
            return $serviceResponse;
        }
        $ix += count($admissions);
        foreach ($admissions as $admission) {
            $admission->refresh(); // Refresh the admission to ensure that all the information is available
            $patient = $admission->getCase();
            $patientId = $patient->getId();
            $patientRef = $patient->getNickname();
            $cohort = $admission->getTrial();

            switch (strtoupper($cohort)) {
                case 'CONTROL' :
                    $cohort = 'NON_PD';
                    break;
                case 'INTERVENTION' :
                    $cohort = 'PD';
                    break;
                default :
                    $msg = "Patient $patientRef (ID: $patientId): Unknown cohort: $cohort. Prospective data export skipped.";
                    $serviceResponse->addDetails($msg);
                    ServiceLogger::getInstance()->error($msg);
                    $numSkipped++;
                    continue 2; // Skip to the next admission
            }

            $patientData = [];
            $patientData['PATIENT_REF'] = $patientRef;
            $patientData['BIRTHDATE'] = $patient->getBirthdate();
            $patientData['GENDER'] = $patient->getGender();
            $patientData['ENROL_DATE'] = $admission->getEnrolDate();
            $patientData['COHORT'] = $cohort;
            $site = strtoupper($admission->getSubscription()->getTeam()->getName());
            $patientData['SITE'] = $site;

            if ($site == 'LINKCARE') {
                $msg = "Patient " . $patientRef . " (ID: " . $patientId .
                        ") is a test subject (created by Linkcare Team). Prospective data export skipped.";
                $serviceResponse->addDetails($msg);
                ServiceLogger::getInstance()->info($msg);
                $numSkipped++;
                continue;
            }

            if ($admission->getStatus() == APIAdmission::STATUS_DISCHARGED && $admission->getDischargeType() != APIDischargeTypes::END) {
                $msg = "Patient " . $patientRef . " (ID: " . $patientId . ") is discharged with reason '" . $admission->getDischargeDescription() .
                        "'. Prospective data export skipped.";
                $serviceResponse->addDetails($msg);
                ServiceLogger::getInstance()->info($msg);
                $numSkipped++;
                continue;
            }

            $exporter->writeDataToCSV('PATIENTS', $patientData);

            try {
                $prospectiveForms = exportableProspectiveFormList($exporter, $cohort);
                $exporter->exportFormsData($patientRef, $admission, $prospectiveForms);
                $numSuccessful++;
            } catch (Exception $e) {
                $msg = "ERROR Patient $patientRef (ID: $patientId): Failed to export prospective data. " . $e->getMessage();
                $numErrors++;
                $serviceResponse->addDetails($msg);
                ServiceLogger::getInstance()->error($msg);
                $executionResult = BackgroundServiceResponse::ERROR;
            }
        }
    } while (count($admissions) == $batchSize);

    $msg = "Export prospective data finished. Errors: $numErrors, Successful: $numSuccessful, Skipped: $numSkipped, Total patients processed: " . $ix;
    $serviceResponse->setMessage($msg);
    $serviceResponse->setCode($executionResult);

    return $serviceResponse;
}

/**
 *
 * @param stdClass $parameters
 * @return BackgroundServiceResponse
 */
function export_retrospective_data($parameters) {
    $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "");
    $executionResult = BackgroundServiceResponse::SUCCESS;

    $numSuccessful = 0;
    $numErrors = 0;
    $numSkipped = 0;

    $api = LinkcareSoapAPI::getInstance();

    /* Retrieve the list of valid Admissions (do not include REJECTED or DISCHARGED for any reason different than END OF PROGRAM */
    $batchSize = 100;
    $ix = 0;

    $exporter = new DataExport($GLOBALS['RETROSPECTIVE_EXPORT_DIR'], true);

    do {
        try {
            $admissions = $api->admission_list_program('PROCARE4LIFE', 'ACTIVE,ENROLLED,DISCHARGED', null, null, $batchSize, $ix, 'ID_PATIENT');
        } catch (Exception $e) {
            $serviceResponse->setCode(BackgroundServiceResponse::ERROR);
            $serviceResponse->setMessage("Error retrieving admissions from API: " . $e->getMessage());
            return $serviceResponse;
        }
        $ix += count($admissions);
        foreach ($admissions as $admission) {
            $admission->refresh(); // Refresh the admission to ensure that all the information is available
            $patient = $admission->getCase();
            $patientId = $patient->getId();
            $patientRef = $patient->getNickname();

            if ($admission->getStatus() == APIAdmission::STATUS_DISCHARGED && $admission->getDischargeType() != APIDischargeTypes::END) {
                $msg = "Patient " . $patientRef . " (ID: " . $patientId . ") is discharged with reason '" . $admission->getDischargeDescription() .
                        "'. Prospective data export skipped.";
                $serviceResponse->addDetails($msg);
                ServiceLogger::getInstance()->info($msg);
                $numSkipped++;
                continue;
            }

            try {
                $prospectiveForms = exportableRetrospectiveFormList($exporter);
                $exporter->exportFormsData($patientRef, $admission, $prospectiveForms);
                $numSuccessful++;
            } catch (Exception $e) {
                $msg = "ERROR Patient $patientRef (ID: $patientId): Failed to export prospective data. " . $e->getMessage();
                $numErrors++;
                $serviceResponse->addDetails($msg);
                ServiceLogger::getInstance()->error($msg);
                $executionResult = BackgroundServiceResponse::ERROR;
            }
        }
    } while (count($admissions) == $batchSize);

    $msg = "Export prospective data finished. Errors: $numErrors, Successful: $numSuccessful, Skipped: $numSkipped, Total patients processed: " . $ix;
    $serviceResponse->setMessage($msg);
    $serviceResponse->setCode($executionResult);

    return $serviceResponse;
}

/* ******* Internal funcions ************************************************** */
/**
 * Loads the data provided by CQS about the blood processig of the patients from an Excel file.
 * The returned value is a multi dimensional array indexed by patient reference and aliquot type.
 * The contents of each item is an array with the IDs of the aliquots of that type for that patient.
 * Example:
 * ['PAT001' => [
 * ···'patient' => APIPatient,
 * ···'form' => APIForm (blood processing form),
 * ···'samples' => [
 * ·····'whole_blood' => [aliquot_id1, aliquot_id2, ...],
 * ·····'plasma' => [aliquot_id3, aliquot_id4, ...],
 * ···]
 * ··]
 * ]
 *
 * @param string $processFile
 * @param string $teamCode Code of the team owner of the Subscription
 * @return array Associative array with the IDs of the aliquots indexed by patient reference / aliquot type
 */
function loadCQSBloodProcessingData($processFile, $teamCode) {
    try {
        $excel = Excel::open($processFile);
    } catch (Exception $e) {
        throw new ServiceException("Error opening file: $processFile: " . $e->getMessage());
    }

    $filename = basename($processFile);
    $excel->dateFormatter('Y-m-d');
    $sheet = $excel->sheet("Datos");
    if (!$sheet) {
        throw new ServiceException("Sheet 'Datos' not found in file: $filename");
    }

    // OR
    $patientSamples = [];
    foreach ($sheet->nextRow([], Excel::KEYS_FIRST_ROW) as $rowNum => $rowData) {
        // sample_id order_id sample_type collection_date plate position plate_location plate_collection plate_delivery patient_id volume haemolysis
        // plate_type key
        $bloodSampleId = $rowData['order_id'];

        $sampleType = $rowData['sample_type'];
        if (trim($sampleType) == '') {
            throw new ServiceException("Sample type not informed for blood sample $bloodSampleId in file $filename, row: $rowNum");
        }

        $aliquotId = $rowData['sample_id'];
        if (trim($aliquotId) == '') {
            throw new ServiceException("Unknown sample type: " . $rowData['sample_type'] . "in file $filename, row: $rowNum");
        }

        switch (strtolower($sampleType)) {
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
                throw new ServiceException("Unknown sample type: " . $rowData['sample_type'] . "in file $filename, row: $rowNum");
        }
        if (array_key_exists($bloodSampleId, $patientSamples) && array_key_exists($type, $patientSamples[$bloodSampleId]['samples'])) {
            $patientSamples[$bloodSampleId]['samples'][$type][] = $aliquotId;
        } else {
            $patientSamples[$bloodSampleId]['samples'][$type] = [$aliquotId];
        }
    }

    // Find in the eCRF the patient and blood processing form that corresponds to each blood sample
    foreach (array_keys($patientSamples) as $bloodSampleId) {
        try {
            list($patient, $bpForm) = ServiceFunctions::findFormFromBloodSampleId($bloodSampleId);
            $patientSamples[$bloodSampleId]['patient'] = $patient;
            $patientSamples[$bloodSampleId]['form'] = $bpForm;
            $patientSamples[$bloodSampleId]['displayName'] = $bloodSampleId . ' (' . $patient->getNickname() . ')';
        } catch (Exception $e) {
            $patientSamples[$bloodSampleId]['displayName'] = $bloodSampleId . ' (unknown patient)';
            $patientSamples[$bloodSampleId]['error'] = $e->getMessage();
        }
    }

    return $patientSamples;
}

/**
 * Loads the data provided by IPIN about the blood processig of the patients from an Excel file.
 * The returned value is a multi dimensional array indexed by patient reference and aliquot type.
 * The contents of each item is an array with the IDs of the aliquots of that type for that patient.
 * Example:
 * ['PAT001' => [
 * ···'patient' => APIPatient,
 * ···'form' => APIForm (blood processing form),
 * ···'samples' => [
 * ·····'whole_blood' => [aliquot_id1, aliquot_id2, ...],
 * ·····'plasma' => [aliquot_id3, aliquot_id4, ...],
 * ···]
 * ··]
 * ]
 *
 * @param string $processFile
 * @param string $teamCode Code of the team owner of the Subscription
 * @return array Associative array with the IDs of the aliquots indexed by patient reference / aliquot type
 */
function loadIPINBloodProcessingData($processFile, $teamCode) {
    try {
        $excel = Excel::open($processFile);
    } catch (Exception $e) {
        throw new ServiceException("Error opening file: $processFile: " . $e->getMessage());
    }

    $filename = basename($processFile);
    $excel->dateFormatter('Y-m-d');
    $sheet = $excel->sheet(0);
    if (!$sheet) {
        throw new ServiceException("Sheet 'Datos' not found in file: $processFile");
    }

    // OR
    $patientSamples = [];
    foreach ($sheet->nextRow([], Excel::KEYS_FIRST_ROW) as $rowNum => $rowData) {
        // sample_id order_id sample_type collection_date plate position plate_location plate_collection plate_delivery patient_id volume haemolysis
        // plate_type key
        $patientRef = trim($rowData['PATIENT_REF']);
        if (!$patientRef) {
            throw new ServiceException("Patient reference (column PATIENT_REF) not informed in file $filename, row: $rowNum");
        }
        $sampleType = trim($rowData['SAMPLE_TYPE']);
        if (!$sampleType) {
            throw new ServiceException("Sample type (column SAMPLE_TYPE) not informed for patient $patientRef in file $filename, row: $rowNum");
        }

        $aliquotId = trim($rowData['ID_ALIQUOT']);
        if (!$aliquotId) {
            throw new ServiceException("Aliquot Id (column ID_ALIQUOT) not informed for patient $patientRef, sample: $sampleType in file $filename, row: $rowNum");
        }

        switch (strtolower(substr($sampleType, 0, 2))) {
            case 'bd' :
            case 'wh' :
                $type = 'WHOLE_BLOOD';
                break;
            case 'pl' :
                $type = 'PLASMA';
                break;
            case 'pm' :
            case 'pb' :
                $type = 'PBMC';
                break;
            case 'se' :
                $type = 'SERUM';
                break;
            default :
                throw new ServiceException("Unknown sample type: " . $sampleType . "in file $filename, row: $rowNum");
        }
        if (array_key_exists($patientRef, $patientSamples) && array_key_exists($type, $patientSamples[$patientRef]['samples'])) {
            $patientSamples[$patientRef]['samples'][$type][] = $aliquotId;
        } else {
            $patientSamples[$patientRef]['samples'][$type] = [$aliquotId];
        }
    }

    // Find in the eCRF the patient and blood processing form that corresponds to each blood sample
    foreach (array_keys($patientSamples) as $patientRef) {
        try {
            list($patient, $bpForm) = ServiceFunctions::findFormFromPatientRef($patientRef, $teamCode);
            $patientSamples[$patientRef]['patient'] = $patient;
            $patientSamples[$patientRef]['form'] = $bpForm;
            $patientSamples[$patientRef]['displayName'] = $patientRef;
        } catch (Exception $e) {
            $patientSamples[$patientRef]['displayName'] = $patientRef;
            $patientSamples[$patientRef]['error'] = $e->getMessage();
        }
    }

    return $patientSamples;
}

/**
 * Returns the list of forms and items to be exported for the prospective data export, depending on the cohort of the patient.
 *
 * @param DataExport $exporter
 * @param string $cohort ('PD' or 'NON_PD')
 * @return string[][]
 */
function exportableProspectiveFormList($exporter, $cohort) {
    $clinicalForms = [];
    $clinicalForms[] = ['formCode' => 'CLINICAL_HISTORY_FORM', 'items' => $exporter->exportableFields('CLINICAL_HISTORY_FORM')];
    $prospectiveForms['CLINICAL_HISTORY'] = $clinicalForms;

    // Common (Non-PD and PD) clinical assessment forms
    $assesmentForms = [];
    $assesmentForms[] = ['formCode' => 'BERG_BALANCE_SCALE', 'items' => $exporter->exportableFields('BERG_BALANCE_SCALE')];
    $assesmentForms[] = ['formCode' => 'CIRS-G', 'items' => $exporter->exportableFields('CIRS-G')];
    $assesmentForms[] = ['formCode' => 'MMSE_PARKINSON_FORM', 'items' => $exporter->exportableFields('MMSE_PARKINSON_FORM')];
    $assesmentForms[] = ['formCode' => 'GDS_SHORT_FORM', 'items' => $exporter->exportableFields('GDS_SHORT_FORM')];

    if ($cohort != 'PD') {
        $prospectiveForms['NON_PD_CLINICAL_ASSESSMENT'] = $assesmentForms;
    }

    if ($cohort == 'PD') {
        // PD specific clinical assessment forms
        $assesmentForms[] = ['formCode' => 'PD-CRS', 'items' => $exporter->exportableFields('PD-CRS')];
        $assesmentForms[] = ['formCode' => 'PDQ-8', 'items' => $exporter->exportableFields('PDQ-8')];
        $assesmentForms[] = ['formCode' => 'PD-CFRS', 'items' => $exporter->exportableFields('PD-CFRS')];
        $assesmentForms[] = ['formCode' => 'MDS-UPDRS-1', 'items' => $exporter->exportableFields('MDS-UPDRS-1')];
        $assesmentForms[] = ['formCode' => 'MDS-UPDRS-2', 'items' => $exporter->exportableFields('MDS-UPDRS-2')];
        $assesmentForms[] = ['formCode' => 'MDS-UPDRS-3', 'items' => $exporter->exportableFields('MDS-UPDRS-3')];
        $assesmentForms[] = ['formCode' => 'MDS-UPDRS-4', 'items' => $exporter->exportableFields('MDS-UPDRS-4')];

        $prospectiveForms['PD_CLINICAL_ASSESSMENT'] = $assesmentForms;
    }

    return $prospectiveForms;
}

/**
 * Returns the list of forms and items to be exported for the prospective data export, depending on the cohort of the patient.
 *
 * @param DataExport $exporter
 * @return string[][]
 */
function exportableRetrospectiveFormList($exporter) {
    $demographicForm = [];
    $demographicForm[] = ['formCode' => 'DEMOGRAPHIC_DATA_FORM', 'items' => $exporter->exportableFields('DEMOGRAPHIC_DATA_FORM')];
    $retrospectiveForms['DEMOGRAPHIC'] = $demographicForm;

    $assesmentForms = [];

    $assesmentForms[] = ['formCode' => 'CIRS-G', 'items' => $exporter->exportableFields('CIRS-G')];
    $assesmentForms[] = ['formCode' => 'FES_ASSESSMENT_FORM', 'items' => $exporter->exportableFields('FES_ASSESSMENT_FORM')];
    $assesmentForms[] = ['formCode' => 'BARTHEL', 'items' => $exporter->exportableFields('BARTHEL')];
    $assesmentForms[] = ['formCode' => 'BERG_BALANCE_SCALE', 'items' => $exporter->exportableFields('BERG_BALANCE_SCALE')];
    $assesmentForms[] = ['formCode' => 'MDS-UPDRS-1', 'items' => $exporter->exportableFields('MDS-UPDRS-1')];
    $assesmentForms[] = ['formCode' => 'MDS-UPDRS-2', 'items' => $exporter->exportableFields('MDS-UPDRS-2')];
    $assesmentForms[] = ['formCode' => 'MDS-UPDRS-3', 'items' => $exporter->exportableFields('MDS-UPDRS-3')];
    $assesmentForms[] = ['formCode' => 'MDS-UPDRS-4', 'items' => $exporter->exportableFields('MDS-UPDRS-4')];
    $assesmentForms[] = ['formCode' => 'EQ_5D_5DL', 'items' => $exporter->exportableFields('EQ_5D_5DL')];
    $assesmentForms[] = ['formCode' => 'PCI_PSQIS_FORM', 'items' => $exporter->exportableFields('PCI_PSQIS_FORM')];
    $assesmentForms[] = ['formCode' => 'STAI_FORM', 'items' => $exporter->exportableFields('STAI_FORM')];

    $retrospectiveForms['PROCARE4LIFE_ASSESSMENT'] = $assesmentForms;

    return $retrospectiveForms;
}


