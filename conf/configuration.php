<?php
/*
 * CUSTOMIZABLE CONFIGURATION VARIABLES
 * To override the default values defined below, create a file named conf/configuration.php in the service root directory and replace the value of the
 * desired variables by a custom value
 */
$GLOBALS['WS_LINK'] = "https://prevampire-api.linkcareapp.com/ServerWSDL.php";
$GLOBALS['SERVICE_USER'] = 'vampire_service';
$GLOBALS['SERVICE_PASSWORD'] = 'JJ6SEjbhAQ3H4jy';

$GLOBALS['SERVICE_DB_URI'] = 'mysql://prevampire_service:Aajz3j0LCXrG92i@dbmysql.linkcareapp.com:/PREVAMPIRE_SERVICE';

$GLOBALS['LAB_TEAMS'] = ['SYNLAB' => ['is_lab' => 1, 'is_clinical_site' => 0], 'INSERM' => ['is_lab' => 1, 'is_clinical_site' => 0],
        'UNIOVI' => ['is_lab' => 1, 'is_clinical_site' => 0], 'UAB' => ['is_lab' => 1, 'is_clinical_site' => 0],
        'UMG' => ['is_lab' => 1, 'is_clinical_site' => 0], 'IGEA' => ['is_lab' => 1, 'is_clinical_site' => 1],
        'CQS' => ['is_lab' => 0, 'is_clinical_site' => 1], 'IPIN' => ['is_lab' => 0, 'is_clinical_site' => 1],
        'AUTH' => ['is_lab' => 0, 'is_clinical_site' => 0], 'TEST_TEAM' => ['is_lab' => 0, 'is_clinical_site' => 1]];

/* Log level. Possible values: debug,trace,warning,error,none */
$GLOBALS['LOG_LEVEL'] = 'debug';
