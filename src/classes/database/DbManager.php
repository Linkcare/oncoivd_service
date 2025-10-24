<?php
require_once (__DIR__ . '/../ErrorDescriptor.php');

require_once ("DbErrors.php");
require_once ("DbException.php");
require_once ("DbDataTypes.php");
require_once ("DbColumnDefinition.php");
require_once ("DbIndexDefinition.php");
require_once ("DbTableDefinition.php");
require_once ("DbFKDefinition.php");
require_once ("DbSequenceDefinition.php");
require_once ("DbSchemaDefinition.php");
require_once ("DbManagerResults.php");
require_once ("DbManagerOracle.php");
require_once ("DbManagerMySQL.php");
require_once ("DbHelper.php");

abstract class DbManager {
    /* DB types */
    const ORACLE = 'Oracle';
    const MYSQL = 'MySQL';
    const MARIADB = 'MariaDB';
    /** @var string  */
    protected $Host;
    /** @var int */
    protected $Port;
    /** @var string  */
    protected $User;
    /** @var string  */
    protected $Passwd;
    /** @var string  */
    protected $Database;
    /** @var boolean */
    protected $Persistent;
    /** @var string  */
    protected $errorDetails;

    /** @var string */
    protected $dbType;

    /** @var boolean If true, all DB exceptions will be thrown to the caller. If false, the caller is responsible for handling the error*/
    private $throwExceptions = false;

    /** @var boolean */
    private $autoCommit;
    /** @var boolean */
    private $simulation = false;

    /** @var boolean */
    private $connected = false;
    /** @var boolean */
    private $loqQueries = false;
    /** @var callable */
    private $logFunction = null;
    /** @var boolean */
    private $generateLogs = false;
    /**
     * By default, when a DB error occurs, a trace is always generated in the system log, but it is possible to disable this behavior
     *
     * @var boolean
     */
    private $logErrors = true;

    // Use DbManager::init() or DbManager::createDBConnector() to create a specific DbManager object
    protected function __construct() {}

    /**
     * Create a DB connector and initializes the connection settings from an URI expression.
     * This function only creates the connector. Call the function connect() to establish a connection.<br>
     * The URI must have the following format:<br>
     * <ul>
     * <li>dbtype://user:password@host:port/database</li>
     * </ul>
     *
     * The value of 'dbtype' determines the type of DB, and the possible values are:
     * <ul>
     * <li>oci/oracle</li>
     * <li>mysql</li>
     * <li>mariadb</li>
     * </ul>
     *
     * Example: oci://demo:lkpassword@db.linkcareapp.com:1521/linkcare<br>
     *
     * @param string $uri
     */
    final static public function init($uri) {
        $dbConnector = DbManager::createDBConnector(self::detectDbType($uri));
        $dbConnector->setURI($uri);
        return $dbConnector;
    }

    /**
     * Create a DB connector for the selected DB type.
     * Supported DB Types:
     * <ul>
     * <li>Oracle</li>
     * <li>MySQL</li>
     * <li>MariaDB</li>
     * </ul>
     * It is possible to create the connector in "simulation mode". In this case, all the queries will not be executed but will logged using the
     * configured log function
     *
     * @param string $dbType
     * @param boolean $simulationMode
     * @return DbManager
     */
    final static public function createDBConnector($dbType = self::ORACLE, $simulationMode = false) {
        switch ($dbType) {
            case self::MYSQL :
            case self::MARIADB :
                $db = new DbManagerMySQL();
                $db->dbType = $dbType;
                break;
            case self::ORACLE :
            default :
                $db = new DbManagerOracle();
                $db->dbType = self::ORACLE;
                break;
        }
        $db->simulation = $simulationMode;
        return $db;
    }

    /**
     * Detects the type of database from the URI connection string
     * The URI must have the following format:<br>
     * <ul>
     * <li>dbtype://user:password@host:port/database</li>
     * </ul>
     *
     * The value of 'dbtype' determines the type of DB, and the possible values are:
     * <ul>
     * <li>oci/oracle</li>
     * <li>mysql</li>
     * <li>mariadb</li>
     * </ul>
     *
     * Example: oci://demo:lkpassword@db.linkcareapp.com:1521/linkcare<br>
     *
     * @param string $uri
     */
    final static public function detectDbType($uri) {
        $dict = parse_url($uri);
        switch (strtoupper($dict['scheme'])) {
            case 'MYSQL' :
                return DbManager::MYSQL;
            case 'MARIADB' :
                return DbManager::MARIADB;
            case 'OCI' :
            case 'ORACLE' :
            default :
                return DbManager::ORACLE;
        }
    }

    /**
     * Returns the type of database of this instace (Oracle, MySQL...)
     *
     * @return string
     */
    final public function getType() {
        return $this->dbType;
    }

    /**
     * Set the DB connection parameters using a string with URI format.
     * The URI must have the following format:<br>
     * <ul>
     * <li>dbtype://user:password@host:port/database</li>
     * </ul>
     *
     * Example: oci://demo:lkpassword@db.linkcareapp.com:1521/linkcare<br>
     *
     * @param string $uri
     */
    final public function setURI($uri) {
        $dict = parse_url($uri);
        $this->Host = isset($dict['host']) ? $dict['host'] : 'localhost';
        $this->User = $dict['user'];
        $this->Passwd = $dict['pass'];
        $this->Port = isset($dict['port']) ? $dict['port'] : $this->Port;
        $this->Database = trim($dict['path'], "/");
    }

    /**
     * Generate a DB URI Connection string
     * The string mut have the following format:<br>
     *
     * Example: dbmgr://demo:lkpassword@db.linkcareapp.com:1521/linkcare<br>
     *
     *
     * @param string $dbType
     * @param string $host
     * @param int $port
     * @param string $user
     * @param string $password
     * @param string $database
     * @return string
     */
    static final public function buildConnectionURI($dbType, $host, $port, $user, $password, $database) {
        switch ($dbType) {
            case DbManager::MYSQL :
                $proto = 'mysql';
                break;
            case DbManager::MARIADB :
                $proto = 'mariadb';
                break;
            case DbManager::ORACLE :
                $proto = 'oci';
                break;
            default :
                $proto = 'dbmgr';
                break;
        }
        return "$proto://$user:$password@$host:$port/$database";
    }

    /**
     *
     * @param string $inputHost
     */
    final public function SetHost($inputHost) {
        $this->Host = $inputHost;
    }

    /**
     *
     * @param int $port
     */
    final public function SetPort($port) {
        $this->Port = $port;
    }

    /**
     *
     * @param string $inputUser
     */
    final public function SetUser($inputUser) {
        $this->User = $inputUser;
    }

    /**
     *
     * @param string $inputPasswd
     */
    final public function SetPasswd($inputPasswd) {
        $this->Passwd = $inputPasswd;
    }

    /**
     *
     * @param string $inputDatabase
     */
    final public function SetDatabase($inputDatabase) {
        $this->Database = $inputDatabase;
    }

    /**
     *
     * @param boolean $persistent
     */
    final public function SetPersistent($persistent = true) {
        $this->Persistent = $persistent;
    }

    /**
     *
     * @return string
     */
    final public function GetHost() {
        return $this->Host;
    }

    /**
     *
     * @return int
     */
    final public function GetPort() {
        return $this->Port;
    }

    /**
     *
     * @return string
     */
    final public function GetUser() {
        return $this->User;
    }

    /**
     *
     * @return string
     */
    final public function GetPasswd() {
        return $this->Passwd;
    }

    /**
     *
     * @return string
     */
    final public function GetDatabase() {
        return $this->Database;
    }

    /**
     *
     * @return boolean
     */
    final public function GetPersistent() {
        return $this->Persistent;
    }

    /**
     * By default all DB errors are sent to the system log, but it is possible to override this behavior.
     * You can use this feature if you plan to execute a query that you expect may fail but that will be handled by your code.
     *
     * @param boolean $enable
     * @return boolean
     */
    final public function enableErrorLog($enable = true) {
        $prevStatus = $this->logErrors;
        $this->logErrors = $enable;
        return $prevStatus;
    }

    /**
     * Returns an identifier (table name, column name, etc.) within the quotation marks used by the database to diffenrentiate an identifier from a
     * reserved word.
     * Not that each database has its own quotation marks.<br>
     * For example, if you have a column named ORDER, it cannot be used directly in a SQL query because the database manager will interpret it as a
     * reserved word and the execution will fail, so it is necessary to enclose it into quotes.<br>
     * <ul>
     * <li>SELECT ORDER FROM tablename; => This query will fail, because ORDER is a reserved word</li>
     * <li>SELECT "ORDER" FROM tablename; => This query will work, because ORDER has been enclosed into quotes to ensure that it is not misinterpreted
     * as a reserved word</li>
     * <ul>
     */
    abstract public function quoteIdentifier($name);

    /**
     * Define a function to capture the logs generated by this object.
     * The prototype of the callable function must be:<br>
     * log_function($type, $function, $parameters, $duration, $query = '', $error_msg = '')
     *
     * @param callable $logFunction
     */
    final public function setLogFunction($logFunction) {
        if ($logFunction !== null && is_callable($logFunction)) {
            $this->logFunction = $logFunction;
        }
    }

    /**
     * Activates or deactivates the log generation.
     * When log generation is active, the execution of each query will generate a log trace<br>
     * You can define your own function to capture the logs using setLogFunction()
     *
     * @param boolean $active
     */
    final public function generateLogs($active) {
        $this->generateLogs = $active;
    }

    /**
     */
    final protected function clearError() {
        $this->errorDetails = null;
    }

    /**
     * Connect to the DB Server.
     *
     * @return boolean
     */
    final public function ConnectServer() {
        $this->clearError();
        $this->connected = $this->connect();
        return $this->connected;
    }

    /**
     * Disconnect from the DB Server
     */
    final public function DisconnectServer() {
        $this->clearError();
        if ($this->connected) {
            $this->disconnect();
            $this->connected = false;
        }
    }

    /**
     * Indicate whether the DB manager should throw exceptions when an error occurs or it should be handled by the caller.
     * When an error occurs, the function getError() will always return an ErrorDescriptor object with the last error details,
     *
     * @param boolean $throw
     */
    final public function throwErrors($throw) {
        $this->throwExceptions = $throw;
    }

    /**
     * Returns true if the connection to the DB server has been established
     *
     * @return boolean
     */
    final public function isConnected() {
        return $this->connected;
    }

    /**
     * Returns an ErrorDescriptor object with information about the execution of the last query
     * Note that this function always returns a non-null ErrorDescriptor object.<br>
     * To check whether an error happened is necessary to inspect the contents of the errCode property
     *
     * @return ErrorDescriptor
     */
    final public function getError() {
        $error = new ErrorDescriptor();
        if (!empty($this->errorDetails) && $this->errorDetails['code']) {
            $error = new ErrorDescriptor(DbErrors::DATABASE_EXECUTION_ERROR);
            $errMsg = $this->errorDetails['code'] . ' ' . $this->errorDetails['message'];
            $error->setErrorMessage($errMsg);
        }

        return $error;
    }

    /*
     * @return DbManagerResults
     */
    public function ExecuteQuery($query, $log = false) {
        return $this->ExecuteBindQuery($query, null, null, null, $log);
    }

    /**
     * Executes a SQL prepared statement with parametrized variables
     *
     * @param string $query SQL statement
     * @param mixed[] $arrVariables
     * @param int $limit Maximum number of rows returned. If null, all rows will be returned
     * @param int $offset Offset of the first row to be returned (base 1). If null, the result will start at row 1
     * @param boolean $log
     * @return DbManagerResults
     */
    public function ExecuteBindQuery($query, $arrVariables = null, $limit = null, $offset = null, $log = false) {
        $this->clearError();

        // if database is locked don't permit any INSERT, UPDATE or DELETE
        if ($GLOBALS['READ_ONLY'] && !$this->isReadQuery($query)) {
            return;
        }

        if ($arrVariables === null) {
            $arrVariables = [];
        }

        // if this is not an array with variables then this is only unique :id variable in query
        if (!is_array($arrVariables)) {
            $arrVariables = [':id' => $arrVariables];
        }

        foreach ($arrVariables as $ix => $val) {
            if ($val === '') {
                /*
                 * In Oracle epmty strings are stored as NULLs. To ensure that all DB types store the values in the same way, we convert empty strings
                 * to NULLs
                 */
                $arrVariables[$ix] = null;
            }
        }

        $isQuery = true;
        if (strtoupper(substr(trim($query), 0, 6)) == 'DELETE' || strtoupper(substr(trim($query), 0, 6)) == 'UPDATE' ||
                strtoupper(substr(trim($query), 0, 6)) == 'INSERT') {
            $isQuery = false;
        }
        // if database is locked don't permit any update or deletion
        if ($GLOBALS["READ_ONLY"]) {
            if (!$isQuery) {
                return new DbManagerResults();
            }
        }

        if ($this->simulation) {
            if ($this->logFunction) {
                ($this->logFunction)('simulation', null,
                        json_encode($arrVariables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 0, $query, null);
            }
            return new DbManagerResults();
        }

        $rst = null;
        try {
            $rst = $this->executeSQL($query, $arrVariables, $limit, $offset);
            if ($log && $this->generateLogs) {
                $this->logQuery('sql', $query, $arrVariables, $brCounter->elapsed());
            }
        } catch (DbException $e) {
            $this->setError($e->getErrorCode(), $e->getMessage(), $query, $arrVariables);
            if ($this->throwExceptions) {
                throw $e;
            } else {
                // Don't throw exceptions. The caller is responsible for handling the error
            }
        } catch (Exception $e) {
            $this->setError($e->getCode(), $e->getMessage(), $query, $arrVariables);
            if ($this->throwExceptions) {
                throw new DbException($this->getError(), $e->getMessage(), $e->getPrevious());
            } else {
                // Don't throw exceptions. The caller is responsible for handling the error
            }
        } finally {
            // If the query failed, we return an empty DbManagerResults object
            if (!$rst) {
                $rst = new DbManagerResults();
            }
        }

        return $rst;
    }

    public function executeMultiple($queryList, $arrVariables = null) {
        $this->clearError();

        if ($arrVariables === null) {
            $arrVariables = [];
        }

        // if this is not an array with variables then this is only unique :id variable in query
        if (!is_array($arrVariables)) {
            $arrVariables = [':id' => $arrVariables];
        }

        foreach ($arrVariables as $ix => $val) {
            if ($val === '') {
                /*
                 * In Oracle epmty strings are stored as NULLs. To ensure that all DB types store the values in the same way, we convert empty strings
                 * to NULLs
                 */
                $arrVariables[$ix] = null;
            }
        }

        try {
            $rst = $this->executeMultipleSQL($queryList, $arrVariables);
        } catch (DbException $e) {
            $this->setError($e->getErrorCode(), $e->getMessage(), implode("\n", $queryList), $arrVariables);
            $rst = new DbManagerResults();
        } catch (Exception $e) {
            $this->setError($e->getCode(), $e->getMessage(), implode("\n", $queryList), $arrVariables);
            $rst = new DbManagerResults();
        }

        return $rst;
    }

    /**
     */
    public function ExecuteLOBQuery($query, $arrVariables, $arrBlobNames, $log = false) {
        // if database is locked don't permit any INSERT, UPDATE or DELETE
        if ($GLOBALS['READ_ONLY'] && !$this->isReadQuery($query)) {
            return;
        }

        try {
            $this->LOBInsert($query, $arrVariables, $arrBlobNames);
        } catch (DbException $e) {
            $this->setError($e->getErrorCode(), $e->getMessage(), $query, $arrVariables);
        } catch (Exception $e) {
            $this->setError($e->getCode(), $e->getMessage(), $query, $arrVariables);
        }
    }

    /**
     * Removes parameters of the array $arrVariables that are not used in the SQL query
     *
     * @param string $query
     * @param string[] $arrVariables
     */
    protected function removeUnusedVariables($query, &$arrVariables) {
        if ($arrVariables === null) {
            return;
        }
        $names = array_keys($arrVariables);
        foreach ($names as $varName) {
            if (!preg_match('~' . $varName . '([^\w]|$)~', $query)) {
                unset($arrVariables[$varName]);
            }
        }
    }

    /**
     * Returns true if the SQL statement is a read-only query (i.e.
     * a SELECT statement)
     *
     * @param string $query
     * @return boolean
     */
    protected function isReadQuery($query) {
        $queryType = strtoupper(explode(' ', trim($query))[0]);
        return (in_array($queryType, ['SELECT', 'SHOW']));
    }

    /**
     * Generate a log about a query
     *
     * @param string $type Type of log. Possible options: "error", "sql"
     */
    final private function logQuery($type, $query = null, &$arrVariables = null, $duration = 0, $errorMessage = null, $errorCode = null) {
        $minVariables = [];
        if (is_array($arrVariables) && !empty($arrVariables))
            foreach ($arrVariables as $ix => $v) {
                if (strlen($v) > 1000) {
                    $minVariables[$ix] = substr($v, 0, 1000);
                } else {
                    $minVariables[$ix] = $v;
                }
            }

        $stackInfo = $this->getCallStack();
        $caller = $stackInfo['function'] . PHP_EOL . " (" . $stackInfo['file'] . " " . $stackInfo['line'] . ")";

        if ($this->logFunction) {
            ($this->logFunction)($type, $caller, json_encode($minVariables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                    $duration, $query, $errorMessage);
        } else {
            $msg = implode("\n", [$errorMessage, $query, "Called from $caller"]);
            error_log(todayUTC() . ' ' . strtoupper($type) . ' ' . $msg);
        }
    }

    /**
     * Set the error information ocurred after the last DB operation (if any).
     *
     * @param string|string[] $errorInfo
     */
    final private function setError($errorCode, $errorMsg, $query = null, &$arrVariables = null) {
        $this->errorDetails = ['code' => $errorCode, 'message' => $errorMsg, 'query' => $query];
        $minVariables = [];
        if (is_array($arrVariables) && !empty($arrVariables))
            foreach ($arrVariables as $ix => $v) {
                if (strlen($v) > 1000) {
                    $minVariables[$ix] = substr($v, 0, 1000);
                } else {
                    $minVariables[$ix] = $v;
                }
            }
        if ($this->logErrors) {
            $this->logQuery('error', $query, $minVariables, 0, $errorMsg, $errorCode);
        }
    }

    /**
     * Returns the information about the File, Function, Line and the contents of the stack where the external code invoked a DB function.
     * The return value is an associative array where the keys of each element are:
     * <ul>
     * <li>file</li>
     * <li>function</li>
     * <li>line</li>
     * <li>stack</li>
     * </ul>
     *
     * @return string[]
     */
    private function getCallStack() {
        $stack = debug_backtrace();
        $failurePoint = null;
        $caller = null;

        foreach ($stack as $stackPoint) {
            /*
             * Go back in the stack until the first call to the Database object so that we can know the place in the code where the DB was invoked
             */
            if ($stackPoint['class'] == __CLASS__ || $stackPoint['class'] == get_class($this)) {
                $failurePoint = $stackPoint;
            } else {
                $caller = $stackPoint;
                break;
            }
        }
        if (!$failurePoint) {
            $failurePoint = $stack[1];
        }
        $line = $failurePoint['line'];
        $file = $failurePoint['file'];
        $callerFunction = $caller ? $caller['function'] : 'main';

        $parts = explode('/', $file);
        $file = end($parts);

        return ['file' => $file, 'function' => $callerFunction, 'line' => $line, 'stack' => $stack];
    }

    /* ****************************************************************************************************************** */
    /* ********************* ABSTRACT METHODS THAT MUST BE IMPLEMENTED IN THE DERIVED CLASS ***************************** */
    /* ****************************************************************************************************************** */
    abstract protected function connect();

    /**
     *
     * /**
     * Executes a SQL statement
     * It is permitted to use parametrized queries, where the parameter names must have the format ':parameter'<br>
     * The parameters must be passed in an associative array where:
     * <ul>
     * <li>The key of each item is the name of the parameter</li>
     * <li>The value of each item is the value assigned to the parameter</li>
     * </ul>
     * Example:<br>
     * SELECT * FROM my_table WHERE ID=:param1 AND OTHER_COLUMN=:param2<br>
     * $arrVariables = [':param1' => 1234, ':param2' => 'xyz']
     *
     * @param string $query SQL statement
     * @param string[] $arrVariables Parameters used in the SQL statement
     * @param int $limit Maximum number of rows returned. If null, all rows will be returned
     * @param int $offset Offset of the first row to be returned (base 1). If null, the result will start at row 1
     * @throws Exception
     * @return DbManagerResults
     */
    abstract public function ExecuteSQL($query, $arrVariables = null, $limit = null, $offset = null);

    /**
     * Execute multiple SQL statements in an efficient way (send all the statements in one call to the DB instead of one by one).
     * It is permitted to use parametrized queries, where the parameter names must have the format ':parameter'<br>
     * The parameters must be passed in an associative array where:
     * <ul>
     * <li>The key of each item is the name of the parameter</li>
     * <li>The value of each item is the value assigned to the parameter</li>
     * </ul>
     * Example:<br>
     * SELECT * FROM my_table WHERE ID=:param1 AND OTHER_COLUMN=:param2<br>
     * $arrVariables = [':param1' => 1234, ':param2' => 'xyz']
     *
     * @param string[] $query Array of SQL statements
     * @param string[] $arrVariables
     */
    abstract public function ExecuteMultipleSQL($queryList, $arrVariables = null);

    /**
     */
    abstract public function LOBInsert($query, $arrVariables, $arrBlobNames);

    /**
     * In some DB Managers, the text search is done as "case insensitive" by default (for example MySQL), while in others it is case sensitive (for
     * example Oracle).
     * To force the to be searches case insensitive it may be necessary to append a suffix to the searched column indicating the collation.<br>
     * For example, in Oracle, a case insensitive search should have the syntax: SELECT * FROM my_table WHERE search_column COLLATE BINARY_CI =
     * 'searched text'<br>
     * This function returns the collation string (in the previous example it would be "COLLATE BINARY_CI") that must be added to the column over
     * which we want to do a case-insensitive search
     */
    abstract public function caseInsensitiveCollation();

    /**
     * In some DB Managers, the text search is done as "case sensitive" by default (for example Oracle), while in others it is case sensitive (for
     * example MySQL).
     * To force the searches to be case sensitive it may be necessary to append a suffix to the searched column indicating the collation.<br>
     * For example, in Oracle, a case sensitive search should have the syntax: SELECT * FROM my_table WHERE search_column COLLATE BINARY =
     * 'searched text'<br>
     * This function returns the collation string (in the previous example it would be "COLLATE BINARY") that must be added to the column over
     * which we want to do a case-sensitive search
     */
    abstract public function caseSensitiveCollation();

    /**
     * Returns an expression with a function (which depends on the DB Manager) that converts a date to string with the desired format.
     * The format expression can contain the following placeholders:
     * <ul>
     * <li>%Y: Year</li>
     * <li>%m: month (01-12)</li>
     * <li>%d: day (01-31)</li>
     * <li>%W: weekday name (Sunday - Saturday)</li>
     * <li>%H: hour (00-23)</li>
     * <li>%i: minutes (00-59)</li>
     * <li>%s: seconds (00-59)</li>
     * </ul>
     *
     * @param string $value
     * @param string $format
     */
    abstract public function fnDateFormat($value, $format);

    /**
     * Depending on the DB Manager, the syntax for concatenating strings may vary.
     * For example, in most DB the '||' operator can be used for concatenating strings, while in others is shpuld be necessary to use the CONCAT()
     * function<br>
     * This function generates an expression that can be added to a SQL query for concatenating the desired strings<br>
     * Remember to enclose the parameters in single quotes if they are literal texts (not column names, parameters or another SQL expression)<br>
     *
     * Examples:<br>
     *
     * <b style="color:red">WRONG</b>: regexp_likeFunction("COLUMN_NAME", "literal text")<br>
     * returns an expression where the literal text is not enclosed in single quotes which will fail if included in a SQL query:<br>
     * <i> CONCAT(COLUMN_NAME, <b style="color: red;">literal text</b>)</i><br>
     *
     * <b style="color:green">RIGHT</b>: regexp_likeFunction("COLUMN_NAME", "'literal text'")<br>
     * returns an expression like:<br>
     * <i>CONCAT(COLUMN_NAME, <b style="color: green;">'literal text'</b>)</i><br>
     *
     * <b style="color:green">RIGHT</b>: regexp_likeFunction("COLUMN_NAME", :myParam)<br>
     * returns an expression like:<br>
     * <i>CONCAT(COLUMN_NAME,<b style="color: green;">:myParam</b>)
     *
     * @param string ...$params
     * @return string
     */
    public function fnConcat(...$params) {
        $concatExpr = [];
        foreach ($params as $n) {
            $concatExpr[] = $n;
        }
        return implode(' || ', $concatExpr);
    }

    /**
     * Depending on the DB Manager, the syntax for searching text that matches a regular expression may vary.
     * For example, in ORACLE or MySQL exists the function REGEXP_LIKE(), while in MariaDB it is necessary to use the RLIKE operator.
     * This function generates an expression that can be added to a SQL query to search using a regular expression.<br>
     * Remember to enclose the parameters in single quotes if they are literal texts (not column names, parameters or another SQL expression)<br>
     *
     * Examples:<br>
     *
     * <b style="color:red">WRONG</b>: regexp_likeFunction("COLUMN_NAME", "literal text")<br>
     * returns an expression where the literal text is not enclosed in single quotes which will fail if included in a SQL query:<br>
     * <i> REGEXP_LIKE(COLUMN_NAME, <b style="color: red;">literal text</b>)</i><br>
     *
     * <b style="color:green">RIGHT</b>: regexp_likeFunction("COLUMN_NAME", "'literal text'")<br>
     * returns an expression like:<br>
     * <i>REGEXP_LIKE(COLUMN_NAME, <b style="color: green;">'literal text'</b>)</i><br>
     *
     * <b style="color:green">RIGHT</b>: regexp_likeFunction("COLUMN_NAME", :myParam)<br>
     * returns an expression like:<br>
     * <i>REGEXP_LIKE(COLUMN_NAME,<b style="color: green;">:myParam</b>)
     *
     * @param string $expression
     * @param string $pattern
     * @param boolean $caseSensitive
     * @return string
     */
    abstract public function regexp_likeFunction($expression, $pattern, $caseSensitive = false);

    /**
     * Returns an expression with a function (which depends on the DB Manager) that adds an interval to a date.
     * The units can be
     * <ul>
     * <li>Year</li>
     * <li>Month</li>
     * <li>Day</li>
     * <li>Hour</li>
     * <li>Minute</li>
     * <li>Second</li>
     * </ul>
     *
     * @param string $value
     * @param string $format
     */
    abstract public function fnDateAdd($date, $interval, $unit);

    /**
     * Returns the next value of a DB Sequence.
     * SEQUENCES are objects used in DB like Oracle, where auto-increment columns do not exists. The SEQUENCES are generally used to calculate the
     * next ID that is assigned to the primary key of a table.<br>
     * This function will set the value $seqVal = NULL in DB that support auto-increment columns. In this case, after performing an INSERT sentence
     * you will need to request the last inserted ID
     *
     * @param string $sequenceName
     * @param int $nextValue Passed by reference. If the DB supports SEQUENCES, then this parameter will be assigned with the next value of a
     *        sequence, or NULL otherwise
     */
    abstract public function getNextSequenceValue($sequenceName, &$nextValue);

    /**
     * Returns the value of the last inserted ID for the primary key of a table
     *
     * @param int $insertedId Passed by reference
     */
    abstract public function getLastInsertedId(&$insertedId);

    /**
     * Starts a new transaction that must be ended with a commit() or a rollback()
     */
    abstract public function beginTransaction();

    /**
     * Indicates if a transaction has been started using the beginTransaction() command and commit() or rollback() have not been called yet to close
     * it
     *
     * @return boolean
     */
    abstract public function transactionInCourse();

    /**
     * Persist all changes done during a transaction started with startTransaction()
     */
    abstract public function commit();

    /**
     * Discards all changes done during a transaction started with startTransaction()
     */
    abstract public function rollback();

    abstract public function getRowsAffected();

    /**
     * Returns true if a sequence exists.
     * Note that sequences are objects that exist in Oracle, but may not exist in other DM Managers like MySQL.
     *
     * @param string $sequenceName
     * @return boolean
     */
    abstract public function sequenceExists($sequenceName);

    /**
     * Returns true if a table exists
     *
     * @param string $tableName
     * @return boolean
     */
    abstract public function tableExists($tableName);

    /**
     * Returns true if an table has a PRIMARY KEY defined
     *
     * @param string $tableName
     * @return boolean
     */
    abstract public function tableHasPrimaryKey($tableName);

    /**
     * Returns true if an index exists
     *
     * @param string $tableName
     * @param string $indexName
     * @return boolean
     */
    abstract public function indexExists($tableName, $indexName);

    /**
     * Returns the information about the column of a table.
     * The function returns null if the column doesn't exist or has an unsupported data type
     *
     * @param string $tableName
     * @param string $columnName
     * @return DbColumnDefinition
     */
    abstract public function columnInfo($tableName, $columnName);

    /**
     * Returns true if a column exists in a table
     *
     * @param string $tableName
     * @param string $columnName
     * @return boolean
     */
    abstract public function columnExists($tableName, $columnName);

    /**
     * Changes the nullability of a table column
     *
     * @param string $tableName
     * @param string $columnName
     * @param boolean $nullable
     * @return ErrorDescriptor
     */
    abstract public function columnSetNullable($tableName, $columnName, $nullable);

    /**
     * Returns true if a constraint exists in a table (e.g.
     * a Foreign key)
     *
     * @param string tableName
     * @param string $constraintName
     * @return boolean
     */
    abstract public function constraintExists($tableName, $constraintName);

    /**
     * Creates a new schema
     *
     * @param DbSchemaDefinition $schema
     * @param boolean $failIfExists (default = true) Set to false if you don't want the function to fail when the schema already exists
     * @return ErrorDescriptor
     */
    abstract public function createSchema($schema, $failIfExists = true);

    /**
     * Creates a new table in the active DB Schema.
     *
     * @param DbTableDefinition $table
     * @return ErrorDescriptor;
     */
    abstract public function createTable($table);

    /**
     * Create a primary key in a table
     *
     * @param string $tableName
     * @param string[] $pkColumns
     * @return ErrorDescriptor
     */
    abstract public function createPrimaryKey($tableName, $pkColumns);

    /**
     * Creates a new index on a table
     *
     * @param DbIndexDefinition $indexDef
     * @return ErrorDescriptor
     */
    abstract public function createIndex($tableName, $indexDef);

    /**
     * Creates a foreign key on a table
     *
     * @param DbFKDefinition $fkDef
     * @return ErrorDescriptor
     */
    abstract public function createForeignKey($fkDef);

    /**
     * Creates a sequence in the DB.
     * Note that this type of object is not supported by all DBs and is only necessary when the DB doesn't support auto-incremental fields. In this
     * cases the sequences are a way of generating incremental values
     *
     * @param DbSequenceDefinition $seq
     */
    abstract public function createSequence($seq);

    /**
     * Creates a column in a table of the database.
     *
     * @param DbColumnDefinition $colDef
     */
    abstract public function createColumn($tableName, $colDef);

    /**
     * Modifies a column (data type, length....) in a table of the database.
     *
     * @param DbColumnDefinition $colDef
     */
    abstract public function modifyColumn($tableName, $colDef);

    /**
     * Creates a new user
     *
     * @param string $user
     * @param string $password
     * @param boolean $failIfExists (default = true) Set to false if you don't want the function to fail when the user already exists
     * @return ErrorDescriptor
     */
    abstract public function createUser($user, $password, $failIfExists = true);

    /**
     * Drops a schema
     *
     * @param string schema
     * @return ErrorDescriptor
     */
    abstract public function dropSchema($schema);

    /**
     * Drops a column of a table
     *
     * @param string $tableName
     * @param string $columnName
     * @return ErrorDescriptor
     */
    abstract public function dropColumn($tableName, $columnName);

    /**
     * Drops a table
     *
     * @param string $tableName
     * @return ErrorDescriptor
     */
    abstract public function dropTable($tableName);

    /**
     * Drops a sequence.
     * Note that sequences are objects that exist in Oracle, but may not exist in other DM Managers like MySQL.
     *
     * @param string $sequenceName
     * @return ErrorDescriptor
     */
    abstract public function dropSequence($sequenceName);

    /**
     * Drops the primary key of a table
     *
     * @param string $tableName
     * @return ErrorDescriptor
     */
    abstract public function dropPrimaryKey($tableName);

    /**
     * Drops an index on a table
     *
     * @param string $tableName
     * @param string $indexName
     * @return ErrorDescriptor
     */
    abstract public function dropIndex($tableName, $indexName);

    /**
     * Drops a Foreign Key in a table
     *
     * @param string $tableName
     * @param string $fkName
     * @return ErrorDescriptor
     */
    abstract public function dropForeignKey($tableName, $fkName);

    /**
     * Renames a table
     *
     * @param string $tableName
     * @param string $newTableName
     * @return ErrorDescriptor
     */
    abstract public function renameTable($tableName, $newTableName);

    /**
     * Renames a column of a table
     *
     * @param string $tableName
     * @param string $columnName
     * @param string $newColumnName
     * @return ErrorDescriptor
     */
    abstract public function renameColumn($tableName, $columnName, $newColumnName);

    /**
     * Grants the default privileges that the Linkcare platform needs to work
     *
     * @param string $user User to whom the privileges will be granted
     * @param string $schema DB Schema over which the privileges will be granted
     * @param string $table Table over which the privileges will be granted. If null, pivileges will apply to all tables of the schema
     * @return ErrorDescriptor
     */
    abstract public function grantDefaultPrivileges($user, $schema, $table = null);

    /**
     * Builds an INSERT or UPDATE query adapted to the syntax of the specific DB Manager (Oracle, MySQL...).
     *
     * @param string $tableName
     * @param string[] $keyColumns Associative array with the list of columns that represent the primary key of the table and hence the condition to
     *        find an existing record. The key of each item is the name of the column and contents is the value assigned to that column
     * @param string[] $updateColumns Associative array with the list of columns that must be updated or inserted when the row doesn't exist. The key
     *        of each item is the name of the column and contents is the value assigned to that column
     */
    abstract public function buildInsertOrUpdateQuery($tableName, $keyColumns, $updateColumns);
}

