<?php
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

$logger = ServiceLogger::init($GLOBALS['LOG_LEVEL'], $GLOBALS['LOG_DIR']);

$json = file_get_contents('php://input');
try {
    $parameters = json_decode($json);
    if (trim($json) != '' && $parameters == null) {
        throw new Exception("Invalid parameters");
    }

    Database::init($GLOBALS['SERVICE_DB_URI'], $logger);
    Database::getInstance()->beginTransaction(); // Execute all commands in transactional mode
    $serviceResponse = deploy_service($parameters);
    Database::getInstance()->commit();
} catch (ServiceException $e) {
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
    $serviceResponse = new ServiceResponse(null, $e->getMessage());
} catch (Error $e) {
    if (Database::getInstance()) {
        Database::getInstance()->rollback();
    }
    $logger->error("Execution error: " . $e->getMessage());
    $serviceResponse = new ServiceResponse(null, $e->getMessage());
} finally {}

echo $serviceResponse->toString();
return;
