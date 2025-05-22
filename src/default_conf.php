<?php
session_start();

/*
 * CUSTOMIZABLE CONFIGURATION VARIABLES
 * To override the default values defined below, create a file named conf/configuration.php in the service root directory and replace the value of the
 * desired variables by a custom value
 */
$GLOBALS['WS_LINK'] = "https://prevampire-api.linkcareapp.com/ServerWSDL.php";
$GLOBALS['SERVICE_USER'] = 'vampire_service';
$GLOBALS['SERVICE_PASSWORD'] = 'xxxxxx';
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
require_once 'WSAPI/WSAPI.php';
require_once 'utils.php';

require_once 'constants/AliquotStatus.php';
require_once 'constants/AliquotStatusItems.php';
require_once 'constants/AliquotTrackingItems.php';
require_once 'constants/AliquotActions.php';
require_once 'constants/AliquotDamage.php';
require_once 'ServiceFunctions.php';

date_default_timezone_set($GLOBALS['DEFAULT_TIMEZONE']);

$GLOBALS['VERSION'] = '1.0';
