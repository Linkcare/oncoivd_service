<?php
include_once ("DbManagerResultsMySQL.php");

class DbManagerMySQL extends DbManager {
    /** @var PDO */
    private $conn;
    private $nrows;
    private $res;
    /** @var DbManagerResultsMySQL */
    private $results;
    private $transactionStarted = false;

    public function __construct() {
        $this->Port = 3306;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::connect()
     */
    public function connect() {
        $MAX_intentos = 3;
        $intentos = 0;
        $lastException = null;
        $limitTime = microtime(true) + 5;
        $now = 0;
        if (!$this->Port) {
            $this->Port = 3306;
        }
        while ($this->conn == null && $intentos < $MAX_intentos && $now < $limitTime) {
            try {
                $dsn = 'mysql:host=' . $this->Host . ';port=' . $this->Port . ';dbname=' . $this->Database . ';charset=UTF8';

                $this->conn = new PDO($dsn, $this->User, $this->Passwd);
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $intentos++;
            } catch (PDOException $e) {
                sleep(0.01);
                $intentos++;
                $lastException = $e;
            }
            $now = microtime(true);
        }
        if ($lastException) {
            throw new Exception('Error connecting to MySQL DB [user: ' . $this->User . ', DB: ' . $this->Database . ', Host: ' . $this->Host . ' ]: ' .
                    $lastException->getMessage(), $lastException->getCode());
        } else {
            return ($this->conn);
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::disconnect()
     */
    public function disconnect() {
        $this->conn = null;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::quoteIdentifier()
     */
    public function quoteIdentifier($name) {
        return '`' . $name . '`';
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::ExecuteSQL()
     */
    public function ExecuteSQL($query, $arrVariables = null, $limit = null, $offset = null) {
        $this->clearError();
        $this->nrows = 0;
        $this->results = new DbManagerResultsMySQL();

        $isReadQuery = $this->isReadQuery($query);

        if (($limit !== null && $limit > 0) || ($offset !== null && $offset > 0)) {
            $limitConditions = ['LIMIT ' . $limit];
            if ($offset !== null && $offset > 0) {
                $limitConditions[] = 'OFFSET ' . ($offset - 1);
            }

            $query = $query . ' ' . implode(' ', $limitConditions);
        }

        $this->removeUnusedVariables($query, $arrVariables);
        $statement = $this->conn->prepare($query);

        foreach ($arrVariables as $name => $val) {
            if (startsWith(':clob_', $name) || startsWith(':blob_', $name) || startsWith(':lob_', $name)) {
                $paramType = PDO::PARAM_LOB;
            } elseif (is_null($val)) {
                $paramType = PDO::PARAM_NULL;
            } elseif (is_int($val)) {
                $paramType = PDO::PARAM_INT;
            } else {
                $paramType = PDO::PARAM_STR;
            }
            $statement->bindValue($name, $val, $paramType);
        }

        $statement->execute();

        $this->nrows = $statement->rowCount();
        if ($isReadQuery) {
            $this->results->setResultSet($statement);
        }

        return $this->results;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::ExecuteMultipleSQL()
     */
    public function ExecuteMultipleSQL($queryList, $arrVariables = null) {
        if (empty($queryList) || !is_array($queryList)) {
            return;
        }
        $query = implode(";\n", $queryList);
        $this->ExecuteSQL($query, $arrVariables);
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::LOBInsert()
     */
    public function LOBInsert($query, $arrVariables, $arrBlobNames) {
        $arrVariables = array_merge($arrVariables);
        return $this->ExecuteSQL($query, $arrVariables);
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::caseInsensitiveCollation()
     */
    public function caseInsensitiveCollation() {
        // In MySQL searches are case-insensitive by default
        return '';
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::caseSensitiveCollation()
     */
    public function caseSensitiveCollation() {
        return 'COLLATE utf8mb4_bin'; // Case insensitive and accent insensitive search
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::fnDateFormat()
     */
    public function fnDateFormat($value, $format) {
        return "DATE_FORMAT($value, '$format')";
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::fnConcat()
     */
    public function fnConcat(...$params) {
        $concatExpr = [];
        foreach ($params as $n) {
            $concatExpr[] = $n;
        }
        return 'CONCAT(' . implode(',', $concatExpr) . ')';
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::regexp_likeFunction()
     */
    public function regexp_likeFunction($expression, $pattern, $caseSensitive = false) {
        if ($caseSensitive) {
            $expression = $expression . ' ' . $this->caseInsensitiveCollation();
        }
        return "$expression RLIKE $pattern";
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::fnDateAdd()
     */
    public function fnDateAdd($date, $interval, $unit) {
        return "DATE_ADD($date, INTERVAL $interval $unit)";
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::nextSequenceValue()
     */
    public function getNextSequenceValue($sequenceName, &$nextValue) {
        $nextValue = null;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::getLastInsertedId()
     */
    public function getLastInsertedId(&$insertedId) {
        $insertedId = null;
        $sql = 'SELECT LAST_INSERT_ID() AS ID';
        $rst = $this->ExecuteBindQuery($sql, null);
        if ($rst->Next()) {
            $insertedId = intval($rst->GetField('ID'));
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::beginTransaction()
     */
    public function beginTransaction() {
        /*
         * By default, MySQL runs with autocommit mode enabled, so when a new transaction starts it is necessary to specifically indicate it
         */
        if (!$this->transactionStarted) {
            $this->conn->beginTransaction();
            $this->transactionStarted = true;
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::transactionInCourse()
     */
    public function transactionInCourse() {
        return $this->transactionStarted;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::commit()
     */
    public function commit() {
        $this->conn->commit();
        $this->transactionStarted = false;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::rollback()
     */
    public function rollback() {
        $this->conn->rollBack();
        $this->transactionStarted = false;
    }

    public function getRowsAffected() {
        return $this->nrows;
    }

    /**
     * Sequence objects don't exist in MySQL.
     * This function always returns false
     */
    public function sequenceExists($sequenceName) {
        return false;
    }

    /**
     * Returns true if a table exists
     *
     * @param string $tableName
     * @return boolean
     */
    public function tableExists($tableName) {
        $arrVariables[':tableName'] = $tableName;
        $arrVariables[':schemaName'] = $this->Database;
        $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME=:tableName AND TABLE_SCHEMA=:schemaName";
        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return true;
        }

        return false;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::tableHasPrimaryKey()
     */
    public function tableHasPrimaryKey($tableName) {
        $arrVariables[':schemaName'] = $this->Database;
        $arrVariables[':tableName'] = $tableName;
        $sql = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_NAME=:tableName AND TABLE_SCHEMA=:schemaName AND CONSTRAINT_NAME='PRIMARY'";
        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return true;
        }

        return false;
    }

    /**
     * Returns true if an index exists
     *
     * @param string $tableName
     * @param string $indexName
     * @return boolean
     */
    public function indexExists($tableName, $indexName) {
        $arrVariables[':schemaName'] = $this->Database;
        $arrVariables[':tableName'] = $tableName;
        $arrVariables[':indexName'] = $indexName;
        $sql = "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_NAME=:tableName AND INDEX_NAME=:indexName AND INDEX_SCHEMA=:schemaName";
        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return true;
        }

        return false;
    }

    /**
     * Returns true if a column exists in a table
     *
     * @param string $tableName
     * @param string $columnName
     * @return boolean
     */
    public function columnExists($tableName, $columnName) {
        $arrVariables[':schemaName'] = $this->Database;
        $arrVariables[':tableName'] = $tableName;
        $arrVariables[':colName'] = $columnName;
        $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:tableName AND COLUMN_NAME=:colName AND TABLE_SCHEMA=:schemaName";
        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return true;
        }

        return false;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::columnInfo()
     */
    public function columnInfo($tableName, $columnName) {
        $arrVariables[':schemaName'] = $this->Database;
        $arrVariables[':tableName'] = $tableName;
        $arrVariables[':colName'] = $columnName;
        $sql = "SELECT COLUMN_NAME, DATA_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION, NUMERIC_SCALE,COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:tableName AND COLUMN_NAME=:colName AND TABLE_SCHEMA=:schemaName";
        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if (!$rst->Next()) {
            return null;
        }

        $dataType = null;
        $nullable = strtoupper($rst->GetField('DATA_TYPE')) == 'Y';
        $defaultValue = NullableString('COLUMN_DEFAULT');

        switch (strtoupper($rst->GetField('DATA_TYPE'))) {
            case "BIGINT" :
                $dataType = DbDataTypes::BIGINT;
                $length = NullableInt($rst->GetField('NUMERIC_PRECISION'));
                $scale = NullableInt($rst->GetField('NUMERIC_SCALE'));
                break;
            case "INT" :
                $dataType = DbDataTypes::INT;
                $length = NullableInt($rst->GetField('NUMERIC_PRECISION'));
                $scale = NullableInt($rst->GetField('NUMERIC_SCALE'));
                break;
            case "TINYINT" :
                $dataType = DbDataTypes::TINYINT;
                $length = NullableInt($rst->GetField('NUMERIC_PRECISION'));
                $scale = NullableInt($rst->GetField('NUMERIC_SCALE'));
                break;
            case "DECIMAL" :
                $dataType = DbDataTypes::DECIMAL;
                $length = NullableInt($rst->GetField('NUMERIC_PRECISION'));
                $scale = NullableInt($rst->GetField('NUMERIC_SCALE'));
                break;
            case 'VARCHAR' :
                $dataType = DbDataTypes::VARCHAR;
                $length = NullableInt($rst->GetField('CHARACTER_MAXIMUM_LENGTH'));
                $scale = null;
                break;
            case 'CHAR' :
                $dataType = DbDataTypes::CHAR;
                $length = NullableInt($rst->GetField('CHARACTER_MAXIMUM_LENGTH'));
                $scale = null;
                break;
            case 'DATETIME' :
                $dataType = DbDataTypes::DATETIME;
                $length = null;
                $scale = null;
                break;
            case 'TEXT' :
                $dataType = DbDataTypes::TEXT;
                $length = NullableInt($rst->GetField('CHARACTER_MAXIMUM_LENGTH'));
                $scale = null;
                break;
            case 'LONGTEXT' :
                $dataType = DbDataTypes::LONGTEXT;
                $length = NullableInt($rst->GetField('CHARACTER_MAXIMUM_LENGTH'));
                $scale = null;
                break;
            case 'LONGBLOB' :
                $dataType = DbDataTypes::BLOB;
                $length = NullableInt($rst->GetField('CHARACTER_MAXIMUM_LENGTH'));
                $scale = null;
                break;
        }
        if (!$dataType) {
            // Unsupported data type
            return null;
        }
        $colInfo = new DbColumnDefinition($columnName, $dataType);
        $colInfo->length = $length;
        $colInfo->scale = $scale;
        $colInfo->nullable = $nullable;
        $colInfo->defaultValue = $defaultValue;
        return $colInfo;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::columnSetNullable()
     */
    public function columnSetNullable($tableName, $columnName, $nullable) {
        $sql = "SHOW COLUMNS FROM $tableName WHERE FIELD='$columnName'";
        $rst = $this->ExecuteBindQuery($sql);
        if (!$rst->Next()) {
            return new ErrorDescriptor(DbErrors::DATABASE_COLUMN_NOT_FOUND);
        }

        $arrVariables = [];
        $colType = $rst->GetField('TYPE');
        $nullability = $nullable ? 'NULL' : 'NOT NULL';
        $sql = "ALTER TABLE $tableName MODIFY $columnName $colType $nullability";

        $defVal = $rst->GetField('DEFAULT');
        if (!isNullOrEmpty($defVal)) {
            str_replace("'", "''", $defVal);
            $sql .= " DEFAULT '$defVal'";
            $arrVariables[':defVal'] = $rst->GetField('TYPE');
        }

        $this->ExecuteBindQuery($sql, $arrVariables);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::constraintExists()
     */
    public function constraintExists($tableName, $constraintName) {
        $arrVariables[':schemaName'] = $this->Database;
        $arrVariables[':tableName'] = $tableName;
        $arrVariables[':constraintName'] = $constraintName;
        $sql = "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA  = :schemaName AND TABLE_NAME = :tableName AND CONSTRAINT_NAME = :constraintName";

        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return true;
        }

        return false;
    }

    /**
     * Drops a column of a table
     *
     * @param string $tableName
     * @param string $columnName
     * @return ErrorDescriptor
     */
    public function dropColumn($tableName, $columnName) {
        $sql = "ALTER TABLE $tableName DROP COLUMN $columnName";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropTable()
     */
    public function dropTable($tableName) {
        $sql = "DROP TABLE $tableName";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropSchema()
     */
    public function dropSchema($schema) {
        $sql = "DROP DATABASE IF EXISTS $schema";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     * Sequence objects do not exist in MySQL.
     * This function always return a non-error response
     */
    public function dropSequence($sequenceName) {
        return new ErrorDescriptor();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropPrimaryKey()
     */
    public function dropPrimaryKey($tableName) {
        $error = new ErrorDescriptor();
        if ($this->indexExists($tableName, 'PRIMARY')) {
            $error = $this->dropIndex($tableName, 'PRIMARY');
        } else {
            $sql = "ALTER TABLE $tableName DROP PRIMARY KEY";
            $this->ExecuteQuery($sql);
            $error = $this->getError();
        }
        return $error;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropIndex()
     */
    public function dropIndex($tableName, $indexName) {
        $indexName = self::quoteIdentifier($indexName);
        $sql = "DROP INDEX $indexName ON $tableName";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropForeignKey()
     */
    public function dropForeignKey($tableName, $fkName) {
        $sql = "ALTER TABLE $tableName DROP FOREIGN KEY $fkName";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::renameTable()
     */
    public function renameTable($tableName, $newTableName) {
        $sql = "RENAME TABLE `$tableName` TO `$newTableName`";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::renameColumn()
     */
    public function renameColumn($tableName, $columnName, $newColumnName) {
        $sql = "ALTER TABLE `$tableName` RENAME COLUMN `$columnName` TO `$newColumnName`";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createSchema()
     */
    public function createSchema($schema, $failIfExists = true) {
        $existCondition = $failIfExists ? '' : 'IF NOT EXISTS';
        $sql = "CREATE DATABASE $existCondition $schema->name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $this->ExecuteBindQuery($sql);
        $error = $this->getError();
        if ($error->getErrCode()) {
            return $error;
        }

        $sql = "USE $schema->name";
        $this->ExecuteBindQuery($sql);
        $error = $this->getError();
        if (!$error->getErrCode() && !empty($schema->tables)) {
            foreach ($schema->tables as $table) {
                if (!$failIfExists && $this->tableExists($table->name)) {
                    continue;
                }
                $error = $this->createTable($table);
                if ($error->getErrCode()) {
                    break;
                }
            }
        }

        if (!$error->getErrCode() && !empty($schema->foreignKeys)) {
            foreach ($schema->foreignKeys as $fk) {
                $error = $this->createForeignKey($fk);
                if ($error->getErrCode()) {
                    break;
                }
            }
        }

        return $error;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createTable()
     */
    public function createTable($table) {
        $sql = "CREATE TABLE `" . $table->name . "`";
        $colDef = [];
        $tableCreated = false;

        foreach ($table->columns as $column) {
            $defaultValue = '';
            if ($column->defaultValue !== null) {
                $defaultValue = ' DEFAULT ' .
                        (is_string($column->defaultValue) || !is_numeric($column->defaultValue) ? "'" . DbHelper::escapeString($column->defaultValue) .
                        "'" : $column->defaultValue);
            }
            $nullable = $column->nullable ? '' : ' NOT NULL';
            $line = "`$column->name` " . self::mapDataType($column) . $defaultValue . $nullable;
            if ($table->primaryKey && count($table->primaryKey) == 1) {
                if (in_array($column->name, $table->primaryKey)) {
                    if ($table->autoIncrement) {
                        $line .= ' AUTO_INCREMENT';
                    }
                    $line .= ' PRIMARY KEY';
                }
            }
            $colDef[] = $line;
        }
        $sql .= ' (' . implode(",\n", $colDef) . ')';

        $this->ExecuteBindQuery($sql);
        $error = $this->getError();
        if (!$error->getErrCode()) {
            $tableCreated = true;
        }

        if (!$error->getErrCode() && $table->primaryKey && count($table->primaryKey) > 1) {
            $error = $this->createPrimaryKey($table->name, $table->primaryKey);
        }

        if (!$error->getErrCode() && count($table->indexes) > 0) {
            foreach ($table->indexes as $indexDef) {
                $error = $this->createIndex($table->name, $indexDef);
                if ($error->getErrCode()) {
                    break;
                }
            }
        }

        if ($error->getErrCode() && $tableCreated) {
            // Something went wrong creating the primary key or the indexes. Drop the table partially created
            $this->dropTable($table->name);
        }

        return $error;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createPrimaryKey()
     */
    public function createPrimaryKey($tableName, $pkColumns) {
        // ALTER TABLE `table_name` ADD PRIMARY KEY (`col1`, `col2`);
        $pkDef = array_map(function ($colName) {
            return '`' . $colName . '`';
        }, $pkColumns);
        $sql = "ALTER TABLE `" . $tableName . "` ADD PRIMARY KEY (" . implode(',', $pkDef) . ")";
        $this->ExecuteBindQuery($sql);
        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createIndex()
     */
    public function createIndex($tableName, $indexDef) {
        // CREATE UNIQUE INDEX `index_name` ON `table_name` (`col1`, `col2`);
        $ixCols = array_map(
                function ($colName) {
                    $ixLength = '';
                    $matches = null;
                    if (preg_match('~(\w+)\((\d+)\)~', $colName, $matches)) {
                        $ixLength = '(' . $matches[2] . ')';
                        $colName = $matches[1];
                    }
                    return '`' . $colName . '`' . $ixLength;
                }, $indexDef->columns);
        $unique = $indexDef->unique ? ' UNIQUE ' : '';
        $sql = "CREATE $unique INDEX `$indexDef->name` ON `$tableName` (" . implode(',', $ixCols) . ")";
        $this->ExecuteBindQuery($sql);
        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createForeignKey()
     */
    public function createForeignKey($fkDef) {
        // ALTER TABLE `table` ADD CONSTRAINT `fk_name` FOREIGN KEY (`col1`, `col2`) REFERENCES `referenced_table` (`col1`, `col2`) ON DELETE CASCADE;
        $cols = array_map(function ($colName) {
            return '`' . $colName . '`';
        }, $fkDef->columnNames);
        $refCols = array_map(function ($colName) {
            return '`' . $colName . '`';
        }, $fkDef->referencedColumnNames);
        $sql = "ALTER TABLE `$fkDef->table` ADD CONSTRAINT `$fkDef->name` FOREIGN KEY (" . implode(',', $cols) .
                ") REFERENCES `$fkDef->referencedTable` (" . implode(',', $refCols) . ")";
        if ($fkDef->onDeleteCascade) {
            $sql .= ' ON DELETE CASCADE';
        }

        $this->ExecuteBindQuery($sql);
        return $this->getError();
    }

    /**
     * Sequences are not supported nor necessary in MySQL because it is possible to use auto-incremental fields for primary keys
     *
     * @see DbManager::createSequence()
     */
    public function createSequence($seq) {
        return new ErrorDescriptor();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createColumn()
     */
    public function createColumn($tableName, $colDef) {
        $sql = "ALTER TABLE $tableName ADD COLUMN ";

        $defaultValue = '';
        if ($colDef->defaultValue !== null) {
            $defaultValue = ' DEFAULT ' .
                    (is_string($colDef->defaultValue) || !is_numeric($colDef->defaultValue) ? "'" . DbHelper::escapeString($colDef->defaultValue) . "'" : $colDef->defaultValue);
        }
        $autoinc = $colDef->autoincrement ? ' AUTO_INCREMENT PRIMARY KEY' : ' ';
        $nullable = $colDef->nullable ? '' : ' NOT NULL';
        $sql .= "`$colDef->name` " . self::mapDataType($colDef) . $defaultValue . $autoinc . $nullable;

        $this->ExecuteQuery($sql);
        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::modifyColumn()
     */
    public function modifyColumn($tableName, $colDef) {
        $sql = "ALTER TABLE $tableName MODIFY ";
        $defaultValue = '';
        if ($colDef->defaultValue !== null) {
            $defaultValue = ' DEFAULT ' .
                    (is_string($colDef->defaultValue) || !is_numeric($colDef->defaultValue) ? "'" . DbHelper::escapeString($colDef->defaultValue) . "'" : $colDef->defaultValue);
        }
        $autoinc = $colDef->autoincrement ? ' AUTO_INCREMENT PRIMARY KEY' : ' ';
        $nullable = $colDef->nullable ? '' : ' NOT NULL';
        $sql .= "`$colDef->name` " . self::mapDataType($colDef) . $defaultValue . $autoinc . $nullable;

        $this->ExecuteQuery($sql);
        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createUser()
     */
    public function createUser($user, $password, $failIfExists = true) {
        if (!$failIfExists) {
            $sql = "SELECT user FROM mysql.user WHERE user = :id";
            $rst = $this->ExecuteBindQuery($sql, $user);
            if ($rst->Next()) {
                // The user already exists: no error
                return new ErrorDescriptor();
            }
        }
        $sql = "CREATE USER '$user'@'%' IDENTIFIED BY '$password'";
        $this->ExecuteBindQuery($sql);
        $error = $this->getError();

        return $error;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::grantDefaultPrivileges()
     */
    public function grantDefaultPrivileges($user, $schema, $table = null) {
        if (!$table) {
            $table = '*';
        }
        $sql = "GRANT ALL PRIVILEGES ON $schema.$table TO '$user'@'%'";
        $this->ExecuteBindQuery($sql);
        $error = $this->getError();
        if (!$error->getErrCode()) {
            $this->ExecuteBindQuery('FLUSH PRIVILEGES');
        }

        return $error;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::buildInsertOrUpdateQuery()
     */
    public function buildInsertOrUpdateQuery($tableName, $keyColumns, $updateColumns) {
        /*
         * INSERT INTO tableName (key1, key2, updateCol1, updateCol2) VALUES (valueKey1, valueKy2, updateValue1, updateValue2)
         * ON DUPLICATE KEY UPDATE updateCol1 = updateValue1, updateCol2 = updateValue2
         */
        $allColumns = [];
        $updates = [];
        foreach ($keyColumns as $colName => $value) {
            if ($value === null) {
                $value = 'NULL';
            }
            $allColumns[] = '`' . $colName . '`';
            $allValues[] = $value;
        }

        if (!empty($updateColumns)) {
            foreach ($updateColumns as $colName => $value) {
                if ($value === null) {
                    $value = 'NULL';
                }
                $allColumns[] = '`' . $colName . '`';
                $allValues[] = $value;
                $updates[] = '`' . $colName . '`=' . $value;
            }
        } else {
            // Nothing to update
            $first = array_key_first($keyColumns);
            $updates[] = "$first = $first";
        }

        $strUpdates = implode(',', $updates);
        $strAllColumns = implode(',', $allColumns);
        $strAllValues = implode(',', $allValues);

        return "INSERT INTO $tableName ($strAllColumns) VALUES ($strAllValues) ON DUPLICATE KEY UPDATE $strUpdates";
    }

    /**
     * Returns the equivalent MySQL data type of a table column
     *
     * @param DbColumnDefinition $column
     * @return string
     */
    static private function mapDataType($column) {
        switch ($column->dataType) {
            case DbDataTypes::BIGINT :
                return "BIGINT";
            case DbDataTypes::INT :
                return "INT";
            case DbDataTypes::TINYINT :
                return "TINYINT";
            case DbDataTypes::DECIMAL :
                $mySQLType = "DECIMAL";
                if ($column->length > 0) {
                    $length = intval($column->length);
                    if (intval($column->scale) > 0 && intval($column->scale) < intval($column->length)) {
                        $length .= ',' . intval($column->scale);
                    }
                    $mySQLType .= "($length)";
                }
                return $mySQLType;
            case DbDataTypes::VARCHAR :
                return 'VARCHAR(' . $column->length . ')';
            case DbDataTypes::CHAR :
                return 'CHAR(' . $column->length . ')';
            case DbDataTypes::DATETIME :
                return 'DATETIME';
            case DbDataTypes::TEXT :
                return 'TEXT';
            case DbDataTypes::LONGTEXT :
                return 'LONGTEXT';
            case DbDataTypes::BLOB :
                return 'LONGBLOB';
        }
        return 'VARCHAR(256)';
    }

    /**
     * Modifies the INSERT SQL $insertQuery adding a 'RETURNING' clause for the bind variables defined in $arrBoundVariables
     * This is necessary for inserting LOB values in a query with bound variables
     *
     * @param string $query
     * @param string[] $arrBoundVariables
     * @return string
     */
    private function buildLobInsert($insertQuery, $arrBoundVariables) {
        foreach ($arrBoundVariables as $varName => $fieldName) {
            if (startsWith(":blob_", $varName)) {
                $insertQuery = str_replace($varName, "EMPTY_BLOB()", $insertQuery);
            } else {
                $insertQuery = str_replace($varName, "EMPTY_CLOB()", $insertQuery);
            }
        }

        $fieldNames = implode(",", array_values($arrBoundVariables));
        $varNames = implode(",", array_keys($arrBoundVariables));

        // The RETURNING clause should look like: RETURNING field_lob_a,field_lob_b INTO :LOB_A,:LOB_B"
        $insertQuery = $insertQuery . " RETURNING $fieldNames INTO $varNames";
        return $insertQuery;
    }

    /**
     * For use when analyzing DB Queries.
     * Generates a file with traces about the queries executed
     *
     * @param string $sql
     * @param number $time Query execution time in seconds
     */
    private function writeLog($sql, $time) {
        $logLine = str_replace([chr(10), chr(13)], '', $sql);
        $hash = hash('sha256', $logLine);
        $filename = $GLOBALS['TEMP_DIR'] . 'sql.log';

        if (!file_exists($filename)) {
            file_put_contents($filename, "TIME·BASE·STACK·HASH·QUERY" . PHP_EOL, FILE_APPEND);
        }
        list($baseFunction, $stack) = $this->stackInfo();
        file_put_contents($filename, $time . '·' . $baseFunction . '·' . $stack . '·' . $hash . '·' . $logLine . PHP_EOL, FILE_APPEND);
    }

    private function stackInfo() {
        $omittedLevels = 1; // to not include Breakdown inner functions
        $stack = debug_backtrace();
        $last_level = count($stack) - 1;

        $str = "";
        $baseFunction = '';
        for ($i = $last_level; $i > $omittedLevels; $i--) { // avoid 0 an 1 indexes to not include Breakdown inner functions
            $level = $stack[$i];
            if ($level["function"] == 'handle') {
                continue;
            }
            if (!$baseFunction) {
                $baseFunction = $level["function"];
            }
            $str .= "/" . $level["function"];
        }

        return [$baseFunction, $str];
    }
}
