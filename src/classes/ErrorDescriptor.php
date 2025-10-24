<?php

class ErrorDescriptor {
    // Public members
    public $errCode;
    private $errMessage = "";

    public function __construct($errCode = null, $message = null) {
        $this->errCode = $errCode;
    }

    public function getErrCode() {
        return $this->errCode;
    }

    /**
     * Gets the error message
     *
     * @return string
     */
    public function getErrorMessage() {
        if ($this->errMessage) {
            return $this->errMessage;
        }

        return $this->errCode;
    }

    /**
     * Sets manually the error message
     *
     * @param string $message
     */
    public function setErrorMessage($message) {
        if (!is_scalar($message)) {
            // Avoid assigning Arrays or Objects as message
            return;
        }
        $this->errMessage = $message;
    }
}
