<?php

class ServiceException extends Exception {
    /** @var string */
    private $errorCode;
    private $additionalMessage;

    /**
     *
     * @param string $errorCode
     * @param string $additionalMessage
     * @param mixed $previous
     */
    public function __construct($errorCode, $additionalMessage = null, $previous = null) {
        $this->errorCode = $errorCode;
        $this->additionalMessage = $additionalMessage;
        parent::__construct($this->getErrorMessage(), null, null);
    }

    public function getErrorMessage() {
        $msg = $this->errorCode;
        if ($this->additionalMessage) {
            $msg = $msg . ': ' . $this->additionalMessage;
        }
        return $msg;
    }
}
