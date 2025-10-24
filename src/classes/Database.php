<?php
include_once ('classes/database/DbManager.php');

class Database {

    /* @var DbManager $backend */
    private static $instance = null;
    private static $logger = null;

    /**
     * Function that initiates the DbMnager $backend variable
     *
     * @param string $connString
     * @param ServiceLogger $logger
     */
    static public function init($connString = null, $logger = null) {
        self::$logger = $logger;
        try {
            $dbData = DbManager::init($connString);
            $dbData->ConnectServer();
            $dbData->setLogFunction(['Database', 'log']);
            $dbData->generateLogs(textToBool($GLOBALS['SQL_LOGS']));
            $dbData->throwErrors(true);
            self::$instance = $dbData;
        } catch (Exception $e) {
            $errMsg = 'Database connection error: ' . $e->getMessage();
            if ($logger) {
                $logger->error($errMsg);
            } else {
                error_log($errMsg);
            }
            throw new Exception($errMsg);
        }
    }

    /**
     *
     * @param string $server
     * @param string $databaseName
     * @param string $user
     * @param string $password
     */
    static public function connect($server, $databaseName, $user, $password, $asSysDba = false) {
        $db = new DbManagerOracle();
        $db->setHost($server);
        $db->setUser($user);
        $db->SetPasswd($password);
        $db->SetDatabase($databaseName);
        $db->connectAsSysDba($asSysDba);
        $db->ConnectServer(false);
        self::$instance = $db;
    }

    /**
     * Returns the DbManager $backend instance in order to execute queries
     *
     * @return DbManager $backend instance
     */
    static public function getInstance() {
        return self::$instance;
    }

    /**
     *
     * @param string $type
     * @param string $function
     * @param string $parameters
     * @param string $duration
     * @param string $result
     * @param string $error_msg
     */
    static function log($type, $function, $parameters, $duration, $result = '', $error_msg = '') {
        if (!self::$logger || !$error_msg) {
            return;
        }

        self::$logger->warning($error_msg);
    }
}
