<?php

abstract class ShipmentErrorCodes extends BasicEnum {
    /** @var string Functionality not implemented */
    const NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';

    /** @var string Generic error */
    const UNEXPECTED_ERROR = 'UNEXPECTED_ERROR';

    /** @var string JSON string is not valid */
    const INVALID_JSON = 'INVALID_JSON';

    /** @var string An object was not found */
    const NOT_FOUND = 'NOT_FOUND';

    /** @var string Required data does not exist */
    const DATA_MISSING = 'DATA_MISSING';

    /** @var string The data provided is ambiguous */
    const AMBIGUOUS = 'AMBIGUOUS';

    /** @var string The action requested is not supported */
    const UNSUPPORTED_ACTION = 'UNSUPPORTED_ACTION';

    /** @var string An object is in an invalid status and an operation can't be performed */
    const INVALID_STATUS = 'INVALID_STATUS';

    /** @var string The data received doess't match the expected format (e.g. an invalid date) */
    const INVALID_DATA_FORMAT = 'INVALID_DATA_FORMAT';

    /** @var string The operation requested is forbidden */
    const FORBIDDEN_OPERATION = 'FORBIDDEN_OPERATION';
}