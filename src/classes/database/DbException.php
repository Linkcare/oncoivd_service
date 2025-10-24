<?php

class DbException extends Exception {
    /** @var ErrorDescriptor */
    private $errorDescriptor;
    private $errorCode;

    /**
     *
     * @param string|ErrorDescriptor $errorCode An error constant (e.g. DbErrors::UNEXPECTED_ERROR) or an ErrorDescriptor object
     * @param string $message
     * @param mixed $previous
     */
    public function __construct($errorCode, $message = null, $previous = null) {
        if ($errorCode instanceof ErrorDescriptor) {
            $this->errorDescriptor = $errorCode;
        } else {
            $this->errorDescriptor = new ErrorDescriptor($errorCode, $message);
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
     * @return ErrorDescriptor
     */
    public function getErrorDescriptor() {
        return $this->errorDescriptor;
    }
}
