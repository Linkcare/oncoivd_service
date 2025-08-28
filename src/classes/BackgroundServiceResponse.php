<?php

class BackgroundServiceResponse {
    /* Status Constants */
    const IDLE = 'idle';
    const SUCCESS = 'success';
    const ERROR = 'error';

    /** @var string */
    private $code;
    /** @var string */
    private $message;
    /** @var string[] */
    private $details = [];

    public function __construct($code, $message) {
        $this->code = $code;
        $this->message = $message;
    }

    /**
     * ******* GETTERS *******
     */
    /**
     *
     * @return string
     */
    public function getCode() {
        return $this->code;
    }

    /**
     * Id of the process that generates the log
     *
     * @return string
     */
    public function getMessage() {
        return $this->message;
    }

    /**
     * ******* SETTERS *******
     */
    /**
     *
     * @param string $value
     */
    public function setCode($value) {
        $this->code = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setMessage($value) {
        $this->message = $value;
    }

    /**
     * ******* METHODS *******
     */
    public function addDetails($message) {
        if (!trim($message)) {
            return;
        }
        $this->details[] = $message;
    }

    public function toString() {
        $serviceResponse = new stdClass();
        $serviceResponse->code = $this->code;
        $serviceResponse->message = $this->message;
        foreach ($this->details as $errMsg) {
            $serviceResponse->details[] = $errMsg;
        }
        return json_encode($serviceResponse);
    }
}