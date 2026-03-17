<?php

class ServiceResponse {
    /** @var string */
    private $result;
    /** @var string */
    private $error;
    private $emailSubject;
    private $emailBody;

    public function __construct($result, $error) {
        $this->result = $result;
        $this->error = $error;
    }

    /**
     * ******* GETTERS *******
     */
    /**
     *
     * @return string
     */
    public function getResult() {
        return $this->result;
    }

    /**
     * Error message
     *
     * @return string
     */
    public function getError() {
        return $this->error;
    }

    /**
     * ******* SETTERS *******
     */
    /**
     *
     * @param string $value
     */
    public function setResult($value) {
        $this->result = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setError($value) {
        $this->error = $value;
    }

    /**
     * ******* METHODS *******
     */
    public function toString() {
        $serviceResponse = new stdClass();
        $serviceResponse->result = $this->result;
        $serviceResponse->error = $this->error;
        return json_encode($serviceResponse);
    }

    public function setEmailCommunication($subject, $body) {
        $this->emailSubject = $subject;
        $this->emailBody = $body;
    }

    public function getEmailSubject() {
        return $this->emailSubject;
    }

    public function getEmailBody() {
        return $this->emailBody;
    }
}