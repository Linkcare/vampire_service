<?php
session_start();

/*
 * CUSTOMIZABLE CONFIGURATION VARIABLES
 * To override the default values defined below, create a file named conf/configuration.php in the service root directory and replace the value of the
 * desired variables by a custom value
 */
$GLOBALS['WS_LINK'] = "https://vampire-api.linkcareapp.com/ServerWSDL.php";
$GLOBALS['SERVICE_USER'] = 'vampire_service';
$GLOBALS['SERVICE_PASSWORD'] = 'xxxxxx';

$GLOBALS['SERVICE_DB_URI'] = 'mysql://vampire_service:xxxxx@dbmysql.linkcareapp.com:/VAMPIRE_SERVICE';

$GLOBALS['LAB_TEAMS'] = ['SYNLAB' => ['is_lab' => 1, 'is_clinical_site' => 0], 'INSERM' => ['is_lab' => 1, 'is_clinical_site' => 0],
        'UNIOVI' => ['is_lab' => 1, 'is_clinical_site' => 0], 'UAB' => ['is_lab' => 1, 'is_clinical_site' => 0],
        'UMG' => ['is_lab' => 1, 'is_clinical_site' => 0], 'IGEA' => ['is_lab' => 1, 'is_clinical_site' => 1],
        'CQS' => ['is_lab' => 0, 'is_clinical_site' => 1], 'IPIN' => ['is_lab' => 0, 'is_clinical_site' => 1],
        'AUTH' => ['is_lab' => 0, 'is_clinical_site' => 0]];

$GLOBALS['CQS_IMPORT_DIR'] = '/var/www/html/services/vampire_service/cqs_import';
$GLOBALS['IPIN_IMPORT_DIR'] = '/var/www/html/services/vampire_service/ipin_import';

/**
 * ** OPTIONAL CONFIGURATION PARAMETERS ***
 */
/* Default timezone used by the service. It is used when it is necessary to generate dates in a specific timezone */
$GLOBALS['DEFAULT_TIMEZONE'] = 'Europe/Madrid';
/* Default language used by the service */
$GLOBALS['DEFAULT_LANGUAGE'] = 'EN';
/* Log level. Possible values: debug,trace,warning,error,none */
$GLOBALS['LOG_LEVEL'] = 'error';
/* Directory to store logs in disk. If null, logs will only be generated on stdout */
$GLOBALS['LOG_DIR'] = null;

/**
 * ** REQUIRED CONFIGURATION PARAMETERS ***
 */

/* LOAD CUSTOMIZED CONFIGURATION */
if (file_exists(__DIR__ . '/../conf/configuration.php')) {
    include_once __DIR__ . '/../conf/configuration.php';
}

/*
 * INTERNAL CONFIGURATION VARIABLES (not customizable)
 */
require_once 'classes/BasicEnum.php';
require_once 'classes/ErrorCodes.php';
require_once 'classes/ServiceLogger.php';
require_once 'classes/ServiceException.php';
require_once 'classes/ServiceResponse.php';
require_once 'classes/BackgroundServiceResponse.php';
require_once 'classes/database/DbManager.php';
require_once 'classes/Database.php';
require_once 'WSAPI/WSAPI.php';
require_once 'utils.php';

require_once 'ShipmentFunctions/ShipmentFunctions.php';
require_once 'constants/AliquotStatusItems.php';
require_once 'constants/TrackingItems.php';
require_once 'constants/AliquotTrackingItems.php';
require_once 'SystemFunctions.php';
require_once 'ServiceFunctions.php';

$GLOBALS['PROJECT_CODE'] = 'VAMPIRE';
$GLOBALS['SHIPMENT_TASK_CODE'] = 'SHIPMENT_TRACKING';
$GLOBALS['STATUS_FORM_CODE_SUFFIX'] = '_STATUS_FORM';
$GLOBALS['STATUS_FORM_CODE'] = 'SAMPLE_STATUS_FORM';
$GLOBALS['SHIPMENT_TRACKING_FORM'] = 'SHIPMENT_TRACKING_FORM';

date_default_timezone_set($GLOBALS['DEFAULT_TIMEZONE']);

$GLOBALS['VERSION'] = '1.5';
