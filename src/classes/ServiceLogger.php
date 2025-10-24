<?php

class ServiceLogger {
    const LEVEL_DEBUG = 'debug';
    const LEVEL_TRACE = 'trace';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_NONE = 'none';

    /** @var ServiceLogger */
    private static $instance;
    private $logLevel;
    private $logDir;
    private $asHTML = false;
    private $toSTDOUT = false;
    private $addStackInfo = false;
    private $customLogFile = null;

    private function __construct($logLevel = null, $logDir = null) {
        if (!$logLevel) {
            $logLevel = self::LEVEL_ERROR;
        }

        $logLevel = strtolower($logLevel);
        $this->logLevel = self::getLevelOrder($logLevel);

        if ($logDir) {
            $logDir = rtrim($logDir, '/') . '/';
            // If a log directory has been provided, verify that it exists and try to create it otherwise
            if (!is_dir($logDir)) {
                mkdir($logDir);
            }

            if (!is_dir($logDir)) {
                error_log('Service logger initialization error: cannot access directory ' . $logDir);
                $logDir = null;
            } else {
                // Check write permission
                $testFileName = $logDir . "testWrite.log";
                file_put_contents($testFileName, "", FILE_APPEND);
                if (file_exists($testFileName)) {
                    unlink($testFileName);
                } else {
                    error_log('Service logger initialization error: missing write permission in directory ' . $logDir . ' or disk full');
                    $logDir = null;
                }
            }
        }

        $this->logDir = $logDir;
    }

    /**
     *
     * @param int $logLevel
     * @param string $logDir
     * @return ServiceLogger
     */
    static public function init($logLevel, $logDir) {
        self::$instance = new ServiceLogger($logLevel, $logDir);
        return self::getInstance();
    }

    /**
     *
     * @return ServiceLogger
     */
    static public function getInstance() {
        if (!self::$instance) {
            self::$instance = new ServiceLogger(self::LEVEL_ERROR);
        }

        return self::$instance;
    }

    public function setCustomLogFile($filePath) {
        $this->customLogFile = $filePath;
    }

    /**
     * If set to true, the logs will be generated in HTML format
     *
     * @param boolean $asHTML
     */
    public function asHTML($asHTML) {
        $this->asHTML = $asHTML;
    }

    /**
     * If set to true, the logs will be sent to STDOUT instead of STDERR
     *
     * @param boolean $toSTDOUT
     */
    public function toSTDOUT($toSTDOUT) {
        $this->toSTDOUT = $toSTDOUT;
    }

    /**
     * If set to true, the logs will include stack information (function and line where the log was generated)
     *
     * @param boolean $toSTDOUT
     */
    public function addStackInfo($addStackInfo) {
        $this->addStackInfo = $addStackInfo;
    }

    /**
     * Generate a trace of DEBUG level
     *
     * @param string $log
     * @param int $tabulation
     */
    public function debug($log, $tabulation = 0) {
        if ($this->logLevel > self::getLevelOrder(self::LEVEL_DEBUG)) {
            return;
        }

        $this->log(self::LEVEL_DEBUG, $log, $tabulation);
    }

    /**
     * Generate a trace of TRACE level
     *
     * @param string $log
     * @param int $tabulation
     */
    public function trace($log, $tabulation = 0) {
        if ($this->logLevel > self::getLevelOrder(self::LEVEL_TRACE)) {
            return;
        }

        $this->log(self::LEVEL_TRACE, $log, $tabulation);
    }

    /**
     * Generate a trace of INFO level
     *
     * @param string $log
     * @param int $tabulation
     */
    public function info($log, $tabulation = 0) {
        if ($this->logLevel > self::getLevelOrder(self::LEVEL_INFO)) {
            return;
        }

        $this->log(self::LEVEL_INFO, $log, $tabulation);
    }

    /**
     * Generate a trace of WARNING level
     *
     * @param string $log
     * @param int $tabulation
     */
    public function warning($log, $tabulation = 0) {
        if ($this->logLevel > self::getLevelOrder(self::LEVEL_WARNING)) {
            return;
        }

        $this->log(self::LEVEL_WARNING, $log, $tabulation);
    }

    /**
     * Generate a trace of ERROR level
     *
     * @param string $log
     * @param int $tabulation
     */
    public function error($log, $tabulation = 0) {
        if ($this->logLevel > self::getLevelOrder(self::LEVEL_ERROR)) {
            return;
        }

        $this->log(self::LEVEL_ERROR, $log, $tabulation);
    }

    static private function getLevelOrder($logLevel) {
        switch ($logLevel) {
            case self::LEVEL_DEBUG :
                return 1;
            case self::LEVEL_TRACE :
                return 2;
            case self::LEVEL_INFO :
                return 3;
            case self::LEVEL_WARNING :
                return 4;
            case self::LEVEL_ERROR :
                return 5;
            case self::LEVEL_NONE :
            default :
                return 1000;
        }
    }

    /**
     * Generate a trace on STDERR
     *
     * @param string $log
     * @param number $tabLevel
     */
    private function log($logLevel, $log, $tabLevel = 0) {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $datetime = $now->format('Y-m-d H:i:s');
        $date = explode(' ', $datetime)[0];

        $message[] = $datetime;
        $message[] = str_pad(strtoupper($logLevel), 7, ' ', STR_PAD_RIGHT);

        $stackDepth = 0;
        $line = 0;
        if ($this->addStackInfo) {
            $stackTrace = debug_backtrace();
            if (count($stackTrace) <= 2) {
                $function = 'main';
            } else {
                $function = $stackTrace[2]['function'];
                if ($stackTrace[2]['class']) {
                    // If is a member of a class, add the class name
                    $function = $stackTrace[2]['class'] . '::' . $function;
                }

                $line = $stackTrace[2]['line'];
                $stackDepth = max(count($stackTrace) - 3, 0);
            }
            $maxLength = 30;

            $message[] = str_pad($line, 4, '0', STR_PAD_LEFT);
            $message[] = str_pad($function, $maxLength, ' ', STR_PAD_RIGHT);
        }

        $message[] = str_repeat(' ', 2 * ($tabLevel + $stackDepth)) . $log;
        $logMsg = implode(' ', $message);

        if ($this->logDir) {
            file_put_contents($this->logDir . $date . '.log', $logMsg . "\n", FILE_APPEND);
        }
        if ($this->customLogFile) {
            if (file_put_contents($this->customLogFile, $logMsg . "\n", FILE_APPEND) === false) {
                error_log('Service logger error: cannot write to custom log file ' . $this->customLogFile);
            }
        }

        $lineBreak = "\n";
        if ($this->asHTML) {
            $lineBreak = '<br>';
            $logMsg = htmlentities($logMsg);
            $logMsg = str_replace(' ', '&nbsp;', $logMsg);
        }

        if ($this->toSTDOUT) {
            echo ($logMsg . $lineBreak);
            ob_flush();
        } else {
            error_log($logMsg);
        }
    }
}