<?php

abstract class ErrorCodes extends BasicEnum {
    /** @var string Error trying to communicate with API */
    const API_COMM_ERROR = 'COMM_ERROR';

    /** @var string API function responded with an error status */
    const API_ERROR_STATUS = 'API_ERROR_STATUS';

    /** @var string The response returned by the API does not have the expected format */
    const API_INVALID_DATA_FORMAT = 'API_INVALID_DATA_FORMAT';

    /** @var string The API function returned an error message */
    const API_FUNCTION_ERROR = 'API_FUNCTION_ERROR';

    /** @var string Generic error */
    const UNEXPECTED_ERROR = 'UNEXPECTED_ERROR';

    /** @var string JSON string is not valid */
    const INVALID_JSON = 'INVALID_JSON';

    /** @var string An error happened executing a DB command */
    const DB_ERROR = 'DB_ERROR';

    /** @var string Required data does not exist */
    const DATA_MISSING = 'DATA_MISSING';

    /** @var string Required data does not exist */
    const FORM_MISSING = 'FORM_MISSING';

    /** @var string Incorrect configuration of the service */
    const CONFIG_ERROR = 'CONFIG_ERROR';

    /** @var string The action requested is not supported */
    const UNSUPPORTED_ACTION = 'UNSUPPORTED_ACTION';
}