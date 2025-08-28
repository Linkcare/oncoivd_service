<?php

class APIResponse {
    private $errorCode;
    private $errorMessage;
    private $result;

    public function __construct($result, $errorCode, $errorMessage) {
        $this->result = $result;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    public function getResult() {
        return $this->result;
    }

    public function getErrorCode() {
        return $this->errorCode;
    }

    public function getErrorMessage() {
        return $this->errorMessage;
    }
}