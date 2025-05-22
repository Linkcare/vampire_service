# VαMPiRE Service
Service for managing blood samples in the VαMPiRE Project<br>
This project implements several functions published as a REST API. All functions must be invoked using POST method.<br>

The base URL for invoking the published functions is:<br>
- https://deploy_url/rest_service<br>

Published functions can be invoked appending the name of the required function to the base URL.<br>
Example:<br>
- https://base_url/rest_service/add_aliquots
  
## Service configuration
The file /lib/default_conf.php provides a default configuration.<br>
To customize the configuration create a new file under the directory /conf (at root directory level) called "configuration.php". The default_conf.php contains explanation for the variables that can be customized.<br>
At least the following variables must be configured:<br>
<ul>
<li>$GLOBALS['WS_LINK'] = Endpoint of the Linkcare Platform API</li>
<li>$GLOBALS['SERVICE_USER'] = Username of a user with role "Service"</li>
<li>$GLOBALS['SERVICE_PASSWORD'] = password of the service user</li>
</ul>
  

## REST API
All functions return a JSON response (Content-type: application/json) with the following structure:<br>

 {<br>
   "result": "xxxx",<br>
   "error": ""<br>
 }<br>

### Published functions
<b><u>add_aliquots</u></b><br>
Adds a new set of aliquots of a patient.
This function is used after a laboratory processes the blood samples extracted from a patient.<br>
The function expects that the necessary FORMS to hold the new list of aliquots are already created into the same TASK, and the FORM CODES for each.<br>
Type of blood samples and the related FORM CODES are:
<ul>
<li>WHOLE_BLOOD: "WHOLE_BLOOD_STATUS_FORM"</li>
<li>PLASMA: "PLASMA_STATUS_FORM"</li>
<li>PBMC: "PBMC_STATUS_FORM"</li>
<li>SERUM: "SERUM_STATUS_FORM"</li>
</ul>

## Version history
1.0 (2025-05-01)
- Initial version
