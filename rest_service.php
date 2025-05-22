<?php
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

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

$restFunctions = ['add_aliquots', 'prepare_shipment', 'prepare_reception', 'prepare_samples_for_exosomes', 'update_samples_status', 'add_exosomes'];

if (in_array($function, $restFunctions)) {
    $json = file_get_contents('php://input');
    try {
        $parameters = json_decode($json);
        if (trim($json) != '' && $parameters == null) {
            throw new Exception("Invalid parameters");
        }

        initServiceSession($parameters);
        $serviceResponse = $function($parameters);
    } catch (ServiceException $e) {
        $serviceResponse = new ServiceResponse(null, $e->getErrorMessage());
    } catch (Exception $e) {
        $serviceResponse = new ServiceResponse(null, $e->getMessage());
    } catch (Error $e) {
        $serviceResponse = new ServiceResponse(null, $e->getMessage());
    } finally {
        WSAPI::apiDisconnect();
    }
} else {
    $serviceResponse = new ServiceResponse(null, "Function $function not implemented");
}

echo $serviceResponse->toString();
return;

/**
 * Initializes an API session.
 * If a valid session token is provided, then the timezone and language of that session will be used
 * for the service session
 *
 * @param string $session
 */
function initServiceSession($parameters) {
    $userSessionToken = loadParam($parameters, 'session');

    /* Join the session of the user that is calling the service to retrieve the timezone and language */
    if ($userSessionToken) {
        $apiSession = WSAPI::apiConnect($GLOBALS["WS_LINK"], $userSessionToken);
        $userLanguage = $apiSession->getSession()->getLanguage();
        $userTimezone = $apiSession->getSession()->getTimezone();
    } else {
        $userLanguage = $GLOBALS['DEFAULT_LANGUAGE'];
        $userTimezone = $GLOBALS['DEFAULT_TIMEZONE'];
    }

    /* All the operations will be performed by a "service" user */
    WSAPI::apiConnect($GLOBALS["WS_LINK"], null, $GLOBALS["SERVICE_USER"], $GLOBALS["SERVICE_PASSWORD"], null, null, false, $userLanguage,
            $userTimezone);
}

function loadParam($parameters, $propertyName) {
    return $parameters && property_exists($parameters, $propertyName) ? $parameters->$propertyName : null;
}

/* ****************************************************************** */
/* ********************* PUBLIC REST FUNCTIONS ********************** */
/* ****************************************************************** */
/**
 * Adds a new set of aliquots of a patient.
 * This function is used after a laboratory processes the blood samples extracted from a patient.<br>
 * The function expects that the necessary FORMS to hold the new list of aliquots are already created into the same TASK, and the FORM CODES for each
 * type of blood sample are:
 * - WHOLE_BLOOD: "WHOLE_BLOOD_STATUS_FORM"
 * - PLASMA: "PLASMA_STATUS_FORM"
 * - PBMC: "PBMC_STATUS_FORM"
 * - SERUM: "SERUM_STATUS_FORM"
 *
 * @param stdClass $parameters
 */
function add_aliquots($parameters) {
    // Reference of the FORM that is initializing a shipment
    $bloodProcessingFormId = loadParam($parameters, 'processing_form');
    // Reference of the TEAM (laborarory) that has processed the blood samples and generated the aliquots
    $labTeamId = loadParam($parameters, 'lab_team');
    $procDate = loadParam($parameters, 'date');
    $procTime = loadParam($parameters, 'time');

    return ServiceFunctions::addAliquots($bloodProcessingFormId, $labTeamId, $procDate, $procTime);
}

/**
 * Adds a new set of exosomes extracted from other aliquots of a patient.
 * This function is used after a laboratory processes blood samples from a patient to extract exosomes.<br>
 * The function expects that the FORM to hold the list of aliquot status records already exists into the same TASK, and the FORM CODES is
 * "EXOSOMES_STATUS_FORM"
 *
 * @param stdClass $parameters
 */
function add_exosomes($parameters) {
    // Reference of the FORM that is initializing a shipment
    $bloodProcessingFormId = loadParam($parameters, 'processing_form');
    // Reference of the TEAM (laborarory) that has processed the blood samples and generated the aliquots
    $labTeamId = loadParam($parameters, 'lab_team');
    $procDate = loadParam($parameters, 'date');
    $procTime = loadParam($parameters, 'time');

    if (!$labTeamId) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "The ID of the laboratory Team is missing");
    }
    if (!$procDate) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "The processing date of the blood samples is missing");
    }
    if (!$procTime) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "The processing time of the blood samples is missing");
    }

    return ServiceFunctions::addExosomeAliquots($bloodProcessingFormId, $labTeamId, $procDate, $procTime);
}

/**
 * Prepares a TASK for a blood sample shipment.
 *
 * @param stdClass $parameters
 */
function prepare_shipment($parameters) {
    // Reference of the FORM that is initializing a shipment
    $preparationFormId = loadParam($parameters, 'preparation_form');
    // Reference of the FORM that will contain the aliquots that can potentially be included in a shipment
    $shipmentFormId = loadParam($parameters, 'shipment_form');
    /*
     * Reference of the Team that is performing the shipment. Only the aliquots that belong to this Team and are available can be included in the
     * shipment
     */
    $senderId = loadParam($parameters, 'sender');
    // Type of blood sample (WHOLE_BLOOD, PLASMA...)
    $sampleType = strtoupper(loadParam($parameters, 'sample_type'));

    return ServiceFunctions::prepareShipment($preparationFormId, $shipmentFormId, $sampleType, $senderId);
}

/**
 * Prepares a TASK for a blood sample shipment.
 *
 * @param stdClass $parameters
 */
function prepare_reception($parameters) {
    // Reference of the FORM that will contain the aliquots that are included included in the shipment that is being received
    $shipmentFormId = loadParam($parameters, 'shipment_form');
    // Reference of the FORM where the aliquots that are included in the shipment will be copied so that a user can indicate the reception status
    $receptionFormId = loadParam($parameters, 'reception_form');
    // Type of blood sample (WHOLE_BLOOD, PLASMA...)
    $sampleType = strtoupper(loadParam($parameters, 'sample_type'));

    return ServiceFunctions::prepareReception($shipmentFormId, $receptionFormId, $sampleType);
}

/**
 * Prepares a TASK for selecting the blood samples that have been used to extract exosomes
 *
 * @param stdClass $parameters
 */
function prepare_samples_for_exosomes($parameters) {
    // Reference of the FORM that is preparating the list of aliquots that are available to perform the exosome extraction
    $preparationFormId = loadParam($parameters, 'preparation_form');
    // Reference of the FORM where the available aliquots of the selected blood type will be copied so that a user can indicate which ones were
    // processed
    $selectionFormId = loadParam($parameters, 'selection_form');
    // Type of blood sample processed (WHOLE_BLOOD, PLASMA...)
    $sampleType = strtoupper(loadParam($parameters, 'sample_type'));
    // Reference of the TEAM that has processed the blood samples and generated the exosomes
    $labId = strtoupper(loadParam($parameters, 'lab_id'));

    return ServiceFunctions::prepareForExosomes($preparationFormId, $selectionFormId, $sampleType, $labId);
}

/**
 * Updates the status of a set of aliquots.
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function update_samples_status($parameters) {
    // Reference of the FORM that contains the aliquots that must be updated
    $modifAliquotsFormId = loadParam($parameters, 'reference_form');
    /*
     * Reference of the FORM where all all the existing aliquots will be included
     * The aliquots modified in the FORM $modifAliquotsFormId will be stored with the new status, and the rest of a liquots will be copied from its
     * previous status
     */
    $newStatusFormId = loadParam($parameters, 'status_form');
    // Type of blood sample (WHOLE_BLOOD, PLASMA...)
    $sampleType = strtoupper(loadParam($parameters, 'sample_type'));
    $action = strtoupper(loadParam($parameters, 'action'));

    if (!AliquotActions::isValidName($action)) {
        throw new ServiceException("Invalid action type: '$action'");
    }
    $action = AliquotActions::fromName($action);

    return ServiceFunctions::updateSamplesStatus($action, $modifAliquotsFormId, $sampleType, $newStatusFormId);
}