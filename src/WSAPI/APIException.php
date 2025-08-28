<?php

class APIException extends Exception {
    public $errorMessage;
    public $errorCode;
    public $result;

    public function __construct($errorCode, $errorMessage = null, $result = null, $previous = null) {
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        parent::__construct($this->errorMessage, null, null);
    }
}
