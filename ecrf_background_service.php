<?php
// Service functions invoked from the Linkcare Platform's background daemon
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

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

$publicFunctions = ['track_pending_shipments', 'track_pending_receptions'];

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
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, 'No pending shipments to track.');
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
