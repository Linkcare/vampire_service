<?php

class DbException extends Exception {
    /** @var DbErrorDescriptor */
    private $errorDescriptor;
    private $errorCode;

    /**
     *
     * @param string|DbErrorDescriptor $errorCode An error constant (e.g. DbErrors::UNEXPECTED_ERROR) or an DbErrorDescriptor object
     * @param string $message
     * @param mixed $previous
     */
    public function __construct($errorCode, $message = null, $previous = null) {
        if ($errorCode instanceof DbErrorDescriptor) {
            $this->errorDescriptor = $errorCode;
        } else {
            $this->errorDescriptor = new DbErrorDescriptor($errorCode, $message);
        }

        parent::__construct($this->errorDescriptor->getErrorMessage(), null, $previous);
    }

    /**
     *
     * @return string
     */
    public function getErrorCode() {
        return $this->errorDescriptor->errorCode;
    }

    /**
     *
     * @return DbErrorDescriptor
     */
    public function getErrorDescriptor() {
        return $this->errorDescriptor;
    }
}
