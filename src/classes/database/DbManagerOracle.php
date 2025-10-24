<?php
include_once ("DbManagerResultsOracle.php");

class DbManagerOracle extends DbManager {
    private $conn;
    private $nrows;
    private $pdo;
    private $res;
    private $error;
    /** @var DbManagerResultsOracle */
    private $results;
    private $transactionStarted = false;

    public function __construct() {
        $this->Port = 1521;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::connect()
     */
    public function connect() {
        $this->pdo = null;
        $MAX_intentos = 3;
        $intentos = 0;
        $lastException = null;
        $limitTime = microtime(true) + 5;
        $now = 0;
        if (!$this->Port) {
            $this->Port = 1521;
        }
        while ($this->conn == null && $intentos < $MAX_intentos && $now < $limitTime) {
            try {
                // by default use OCI8 connection
                if ($this->pdo) {
                    $this->conn = new PDO("oci:dbname=//" . $this->Host . "/" . $this->Database . ";charset=AL32UTF8", $this->User, $this->Passwd);
                } else {
                    $this->conn = oci_pconnect($this->User, $this->Passwd, $this->Host . ':' . $this->Port . '/' . $this->Database, 'AL32UTF8');
                }
                $intentos++;
            } catch (PDOException $e) {
                sleep(0.01);
                $intentos++;
                $lastException = $e;
            }
            $now = microtime(true);
        }
        if ($lastException) {
            throw new Exception('Error connecting to Oracle DB [user: ' . $this->User . ', DB: ' . $this->Database . ', Host: ' . $this->Host . ' ]: ' .
                    $lastException->getMessage(), $lastException->getCode());
        } elseif (!$this->conn) {
            throw new Exception('Error connecting to Oracle DB [user: ' . $this->User . ', DB: ' . $this->Database . ', Host: ' . $this->Host . ' ]');
        } else {
            // set format of date
            $sql = "ALTER SESSION SET NLS_DATE_FORMAT = 'yyyy-mm-dd hh24:mi:ss'";
            $this->ExecuteQuery($sql);

            return ($this->conn);
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::disconnect()
     */
    public function disconnect() {
        if ($this->conn) {
            oci_close($this->conn);
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::quoteIdentifier()
     */
    public function quoteIdentifier($name) {
        return '"' . $name . '"';
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::ExecuteSQL()
     */
    public function ExecuteSQL($query, $arrVariables = null, $limit = null, $offset = null) {
        $this->clearError();
        $this->nrows = 0;
        $this->results = null;

        $isReadQuery = $this->isReadQuery($query);

        $prefetch = 0;
        if (($limit !== null && $limit > 0) || ($offset !== null && $offset > 0)) {
            if ($offset === null) {
                $offset = 0;
            } else {
                $offset--;
            }
            $query = $query . " OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY";
            $prefetch = $limit + 1;
        }

        $lobs = null;
        $this->res = oci_parse($this->conn, $query);
        $error = oci_error($this->conn);
        if (!$error) {
            foreach ($arrVariables as $key => $val) {
                if (startsWith(':clob_', $key) || startsWith(':blob_', $key)) {
                    $bindType = (startsWith(':clob_', $key) ? OCI_B_CLOB : OCI_B_BLOB);
                    $lobs[$key] = oci_new_descriptor($this->conn, OCI_D_LOB);
                    oci_bind_by_name($this->res, $key, $lobs[$key], -1, $bindType);
                } else {
                    oci_bind_by_name($this->res, $key, $arrVariables[$key], -1);
                }
            }
            if ($prefetch) {
                oci_set_prefetch($this->res, $prefetch);
            }
            if (!$lobs) {
                // When there are no LOBS in the query, we can execute it indicating whether the query must be auto-commited or not
                oci_execute($this->res, (!$this->transactionStarted ? OCI_COMMIT_ON_SUCCESS : OCI_NO_AUTO_COMMIT));
                $error = oci_error($this->res);
            } else {
                /*
                 * When there are LOBS in the query passed as parameters, we can't ask Oracle to auto-commit the transaction because first we need to
                 * execute the query, and in a second step we must pass the value of the LOBS.
                 * If we didn't start a transaction, the execution would fail because the value of the parameters would not be set at that moment.
                 */
                oci_execute($this->res, OCI_DEFAULT);
                $error = oci_error($this->res);

                $ok = true;
                if (!$error) {
                    foreach ($lobs as $key => $lob) {
                        // then save clobs
                        if (!$lob->save($arrVariables[$key])) {
                            $ok = false;
                        }
                    }
                }
                if (!$this->transactionStarted) {
                    if ($ok && !$error) {
                        oci_commit($this->conn);
                    } else {
                        oci_rollback($this->conn);
                    }
                }
            }
            if (!$error) {
                $this->nrows = oci_num_rows($this->res);
                $error = oci_error($this->conn);
            }
        }

        if ($error) {
            throw new DbException($error['code'], $error['message']);
        }

        if ($isReadQuery) {
            $this->results = new DbManagerResultsOracle();
            $this->results->setResultSet($this->res, $this->pdo);
        }

        return ($this->results);
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
        $query = "BEGIN\n" . implode(";\n", $queryList) . ";\nEND;";
        $this->ExecuteSQL($query, $arrVariables);
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::LOBInsert()
     */
    public function LOBInsert($query, $arrVariables, $arrBlobNames) {
        $this->clearError();
        $this->nrows = 0;

        if (!is_array($arrVariables)) {
            $arrVariables = [':id' => $arrVariables];
        }
        if (empty($arrBlobNames)) {
            $arrBlobNames = [];
        }

        $lobs = null;

        if (!empty($arrBlobNames)) {
            $query = self::buildLobInsert($query, $arrBlobNames);
        }

        $this->res = oci_parse($this->conn, $query);
        $error = oci_error($this->conn);
        if (!$error) {
            foreach ($arrVariables as $key => $val) {
                if (!in_array($key, $arrBlobNames)) {
                    oci_bind_by_name($this->res, $key, $arrVariables[$key], -1);
                }
            }
            foreach ($arrBlobNames as $key => $fieldName) {
                $bindType = (startsWith(':clob_', $key) ? OCI_B_CLOB : OCI_B_BLOB);
                $lobs[$key] = oci_new_descriptor($this->conn, OCI_D_LOB);
                oci_bind_by_name($this->res, $key, $lobs[$key], -1, $bindType);
            }

            if (!$lobs) {
                // When there are no LOBS in the query, we can execute it indicating whether the query must be auto-commited or not
                oci_execute($this->res, (!$this->transactionStarted ? OCI_COMMIT_ON_SUCCESS : OCI_NO_AUTO_COMMIT));
                $error = oci_error($this->res);
            } else {
                /*
                 * When there are LOBS in the query passed as parameters, we can't ask Oracle to auto-commit the transaction because first we need to
                 * execute the query, and in a second step we must pass the value of the LOBS.
                 * If we didn't start a transaction, the execution would fail because the value of the parameters would not be set at that moment.
                 */
                oci_execute($this->res, OCI_DEFAULT);
                $error = oci_error($this->res);

                $ok = true;
                if (!$error) {
                    foreach ($lobs as $key => $lob) {
                        // then save clobs
                        if (!$lob->save($arrVariables[$key])) {
                            $ok = false;
                        }
                    }
                }
                if (!$this->transactionStarted) {
                    // If the query has not been executed in a transaction, we must commit or rollback it
                    if ($ok && !$error) {
                        oci_commit($this->conn);
                    } else {
                        oci_rollback($this->conn);
                    }
                }
                foreach ($lobs as $key => $lob) {
                    $lob->free();
                }
            }
            if (!$error) {
                $this->nrows = oci_num_rows($this->res);
                $error = oci_error($this->conn);
            }
        }

        if ($this->res) {
            oci_free_statement($this->res);
            $this->res = null;
        }

        if ($error) {
            throw new DbException($error['code'], $error['message']);
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::caseInsensitiveCollation()
     */
    public function caseInsensitiveCollation() {
        return 'COLLATE BINARY_AI'; // Case insensitive and accent insensitive search
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::caseSensitiveCollation()
     */
    public function caseSensitiveCollation() {
        return 'COLLATE BINARY'; // Case insensitive and accent insensitive search
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::regexp_likeFunction()
     */
    public function regexp_likeFunction($expression, $pattern, $caseSensitive = false) {
        if (!$caseSensitive) {
            $ciFlag = ", 'i'";
        }
        return "REGEXP_LIKE($expression, $pattern $ciFlag)";
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::fnDateFormat()
     */
    public function fnDateFormat($value, $format) {
        $format = preg_replace('~%Y~', 'rrrr', $format);
        $format = preg_replace('~%m~', 'mm', $format);
        $format = preg_replace('~%d~', 'dd', $format);
        $format = preg_replace('~%W~', 'fmDAY', $format);
        $format = preg_replace('~%H~', 'hh24', $format);
        $format = preg_replace('~%i~', 'mi', $format);
        $format = preg_replace('~%s~', 'ss', $format);
        return "TO_CHAR($value, '$format','NLS_DATE_LANGUAGE = english')";
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::fnDateAdd()
     */
    public function fnDateAdd($date, $interval, $unit) {
        return $date . " + INTERVAL'$interval'$unit";
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::nextSequenceValue()
     */
    public function getNextSequenceValue($sequenceName, &$nextValue) {
        $nextValue = null;
        $sql = "SELECT $sequenceName.NEXTVAL AS SEQ_VAL FROM DUAL";
        $rst = $this->ExecuteQuery($sql);
        if ($rst->Next()) {
            $nextValue = intval($rst->GetField('SEQ_VAL'));
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::getLastInsertedId()
     */
    public function getLastInsertedId(&$insertedId) {
        /*
         * It is not necessary to do anything because in Oracle there are no AUTO INCREMENT columns, and when inserting a new row it is necessary to
         * generate the ID beforehand using the function nextSequenceValue().
         * So, the ID must heve been generated before and is already assigned.
         */
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::beginTransaction()
     */
    public function beginTransaction() {
        /*
         * By default Oracle works in transactional mode (it is necessary to commit or rollback the transaction after executing any query), so we
         * don't need to perform any action.
         * When a SQL query is executed we will check whether an transaction has been specifically started using this function to override the global
         * autocommit mode
         */
        $this->transactionStarted = true;
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
        oci_commit($this->conn);
        $this->transactionStarted = false;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::rollback()
     */
    public function rollback() {
        oci_rollback($this->conn);
        $this->transactionStarted = false;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::getRowsAffected()
     */
    public function getRowsAffected() {
        return $this->nrows;
    }

    // /**
    // */
    // private function clearError() {
    // $this->error = null;
    // $this->errorDetails = null;
    // }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::sequenceExists()
     */
    public function sequenceExists($sequenceName) {
        $arrVariables[':seqName'] = $sequenceName;
        $sql = "SELECT SEQUENCE_NAME FROM USER_SEQUENCES WHERE SEQUENCE_NAME = :seqName";
        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return true;
        }

        return false;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::tableExists()
     */
    public function tableExists($tableName) {
        $arrVariables[':tableName'] = strtoupper($tableName);
        $sql = "SELECT TABLE_NAME FROM USER_TABLES WHERE TABLE_NAME=:tableName";
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
        $arrVariables[':tableName'] = $tableName;
        $sql = "SELECT CONSTRAINT_NAME FROM USER_CONSTRAINTS WHERE CONSTRAINT_TYPE='P' AND TABLE_NAME=:tableName";
        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return true;
        }

        return false;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::indexExists()
     */
    public function indexExists($tableName, $indexName) {
        $arrVariables[':indexName'] = $indexName;
        $sql = "SELECT INDEX_NAME FROM USER_INDEXES WHERE INDEX_NAME=:indexName";
        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return true;
        }

        return false;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::columnExists()
     */
    public function columnExists($tableName, $columnName) {
        $arrVariables[':tableName'] = $tableName;
        $arrVariables[':colName'] = $columnName;
        $sql = "SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE TABLE_NAME=:tableName AND COLUMN_NAME=:colName";
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
        $arrVariables[':tableName'] = $tableName;
        $arrVariables[':colName'] = $columnName;
        $sql = "SELECT DATA_TYPE, DATA_SCALE, DATA_PRECISION, NULLABLE, DATA_DEFAULT  FROM USER_TAB_COLUMNS WHERE TABLE_NAME=:tableName AND COLUMN_NAME=:colName";
        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if (!$rst->Next()) {
            return null;
        }

        $dataType = null;
        $length = NullableInt($rst->GetField('DATA_PRECISION'));
        $scale = NullableInt($rst->GetField('DATA_SCALE'));
        $nullable = strtoupper($rst->GetField('DATA_TYPE')) == 'Y';
        $defaultValue = NullableString('DATA_DEFAULT');
        switch (strtoupper($rst->GetField('DATA_TYPE'))) {
            case "NUMBER" :
                if ($scale > 0) {
                    $dataType = DbDataTypes::DECIMAL;
                } elseif ($length > 12) {
                    $dataType = DbDataTypes::BIGINT;
                } elseif ($length > 3) {
                    $dataType = DbDataTypes::INT;
                } else {
                    $dataType = DbDataTypes::TINYINT;
                }
                break;
            case 'VARCHAR2' :
                $dataType = $length < 4000 ? DbDataTypes::VARCHAR : DbDataTypes::TEXT;
                break;
            case 'CHAR' :
                $dataType = DbDataTypes::CHAR;
                break;
            case 'DATE' :
                $dataType = DbDataTypes::DATETIME;
                break;
            case 'CLOB' :
                $dataType = DbDataTypes::LONGTEXT;
                break;
            case 'BLOB' :
                $dataType = DbDataTypes::BLOB;
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
        $error = new ErrorDescriptor();
        $arrVariables[':tableName'] = $tableName;
        $arrVariables[':colName'] = $columnName;

        $sql = "SELECT NULLABLE FROM USER_TAB_COLUMNS WHERE TABLE_NAME=:tableName AND COLUMN_NAME=:colName";
        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            $newValue = $nullable ? 'Y' : 'N';
            if ($rst->GetField('NULLABLE') != $newValue) {
                // Change column nullability
                $nullability = $nullable ? 'NULL' : 'NOT NULL';
                $sql = "ALTER TABLE $tableName MODIFY $columnName $nullability";
                $this->ExecuteBindQuery($sql, null);
                $error = $this->getError();
            }
        } else {
            $error = new ErrorDescriptor(DbErrors::DATABASE_EXECUTION_ERROR);
        }

        return $error;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::constraintExists()
     */
    public function constraintExists($tableName, $constraintName) {
        $arrVariables[':tableName'] = $tableName;
        $arrVariables[':constraintName'] = $constraintName;
        $sql = "SELECT * FROM USER_CONSTRAINTS WHERE CONSTRAINT_NAME = :constraintName AND TABLE_NAME=:tableName";

        $rst = $this->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return true;
        }

        return false;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropColumn()
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
        $sql = "DROP TABLE $tableName CASCADE CONSTRAINTS";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropSchema()
     */
    public function dropSchema($schema) {
        $sql = "DROP USER $schema CASCADE";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropSequence()
     */
    public function dropSequence($sequenceName) {
        $sql = "DROP SEQUENCE $sequenceName";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropPrimaryKey()
     */
    public function dropPrimaryKey($tableName) {
        $sql = "ALTER TABLE $tableName DROP PRIMARY KEY";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropIndex()
     */
    public function dropIndex($tableName, $indexName) {
        $sql = 'DROP INDEX "' . $indexName . '"';
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::dropForeignKey()
     */
    public function dropForeignKey($tableName, $fkName) {
        $sql = "ALTER TABLE $tableName DROP CONSTRAINT $fkName";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::renameTable()
     */
    public function renameTable($tableName, $newTableName) {
        $sql = "ALTER TABLE $tableName RENAME TO $newTableName";
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::renameColumn()
     */
    public function renameColumn($tableName, $columnName, $newColumnName) {
        $sql = 'ALTER TABLE "' . $tableName . '" RENAME COLUMN "' . $columnName . '" TO "' . $newColumnName . '"';
        $this->ExecuteQuery($sql);

        return $this->getError();
    }

    /**
     * Note that in Oracle, a Schema is equivalent to a User, so this function assumes that the User already exists and only tries to create the
     * tables, indexes, sequences and other objects
     *
     * {@inheritdoc}
     * @see DbManager::createSchema()
     */
    public function createSchema($schema, $failIfExists = true) {
        $sql = "ALTER SESSION SET CURRENT_SCHEMA = $schema->name";
        $this->ExecuteQuery($sql);
        $error = $this->getError();
        if (!$error->getErrCode() && !empty($schema->tables)) {
            foreach ($schema->tables as $table) {
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

        if (!$error->getErrCode() && count($schema->sequences) > 0) {
            foreach ($schema->sequences as $seq) {
                $error = $this->createSequence($seq);
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
        $sql = 'CREATE TABLE "' . $table->name . '"';
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
            $line = '"' . $column->name . '" ' . self::mapDataType($column) . $defaultValue . $nullable;
            $colDef[] = $line;
        }

        $sql .= ' (' . implode(",\n", $colDef) . ')';

        $this->ExecuteQuery($sql);
        $error = $this->getError();

        if (!$error->getErrCode()) {
            $tableCreated = true;
        }

        if (!$error->getErrCode() && !empty($table->primaryKey)) {
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
        // ALTER TABLE "table_name" ADD CONSTRAINT "constraint_name" PRIMARY KEY ("col1", "col2");
        $pkDef = array_map(function ($colName) {
            return '"' . $colName . '"';
        }, $pkColumns);
        $pkName = $tableName . '_PK';
        $sql = 'ALTER TABLE "' . $tableName . '" ADD CONSTRAINT ' . $pkName . ' PRIMARY KEY (' . implode(',', $pkDef) . ')';
        $this->ExecuteQuery($sql);
        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createIndex()
     */
    public function createIndex($tableName, $indexDef) {
        // CREATE UNIQUE INDEX "index_name" ON "table_name" ("col1", "col2");
        $ixCols = array_map(
                function ($colName) {
                    $matches = null;
                    if (preg_match('~(\w+)\((\d+)\)~', $colName, $matches)) {
                        return "SUBSTR(" . $matches[1] . ",1," . $matches[2] . ")";
                    }
                    return '"' . $colName . '"';
                }, $indexDef->columns);
        $unique = $indexDef->unique ? 'UNIQUE' : '';
        $sql = "CREATE $unique INDEX \"" . $indexDef->name . '" ON "' . $tableName . '" (' . implode(',', $ixCols) . ")";
        $this->ExecuteQuery($sql);
        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createForeignKey()
     */
    public function createForeignKey($fkDef) {
        // ALTER TABLE "table" ADD CONSTRAINT "fk_name" FOREIGN KEY ("col1", "col2") REFERENCES "referenced_table" ("col1", "col2") ON DELETE CASCADE;
        $cols = array_map(function ($colName) {
            return '"' . $colName . '"';
        }, $fkDef->columnNames);
        $refCols = array_map(function ($colName) {
            return '"' . $colName . '"';
        }, $fkDef->referencedColumnNames);
        $sql = 'ALTER TABLE "' . $fkDef->table . '" ADD CONSTRAINT "' . $fkDef->name . '" FOREIGN KEY (' . implode(',', $cols) . ') REFERENCES "' .
                $fkDef->referencedTable . '" (' . implode(',', $refCols) . ")";
        if ($fkDef->onDeleteCascade) {
            $sql .= ' ON DELETE CASCADE';
        }

        $this->ExecuteQuery($sql);
        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createColumn()
     */
    public function createColumn($tableName, $colDef) {
        $sql = "ALTER TABLE $tableName ADD ";
        $defaultValue = '';
        if ($colDef->defaultValue !== null) {
            $defaultValue = ' DEFAULT ' .
                    (is_string($colDef->defaultValue) || !is_numeric($colDef->defaultValue) ? "'" . DbHelper::escapeString($colDef->defaultValue) . "'" : $colDef->defaultValue);
        }
        $nullable = $colDef->nullable ? '' : ' NOT NULL';
        $sql .= '"' . $colDef->name . '" ' . self::mapDataType($colDef) . $defaultValue . $nullable;

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
        $nullable = $colDef->nullable ? '' : ' NOT NULL';
        $sql .= '"' . $colDef->name . '" ' . self::mapDataType($colDef) . $defaultValue . $nullable;

        $this->ExecuteQuery($sql);
        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::createUser()
     */
    public function createUser($user, $password, $failIfExists = true) {
        $user = strtoupper($user);
        if (!$failIfExists) {
            $sql = "SELECT username FROM ALL_USERS WHERE username = :id";
            $rst = $this->ExecuteBindQuery($sql, $user);
            if ($rst->Next()) {
                // The user already exists: no error
                return new ErrorDescriptor();
            }
        }

        $sql = "CREATE USER $user IDENTIFIED BY $password";
        $this->ExecuteQuery($sql);
        $error = $this->getError();
        if (!$error->getErrCode()) {
            $error = $this->grantDefaultPrivileges($user, $user);
        }
        return $error;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::grantDefaultPrivileges()
     */
    public function grantDefaultPrivileges($user, $schema, $table = null) {
        $error = new ErrorDescriptor();
        if (!$table) {
            $privileges = ["CREATE TABLE", "UNLIMITED TABLESPACE", "CREATE SESSION"];
            foreach ($privileges as $priv) {
                $sql = "GRANT $priv TO $user";
                $this->ExecuteQuery($sql);
                $error = $this->getError();
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
     * @see DbManager::createSequence()
     */
    public function createSequence($seq) {
        // CREATE SEQUENCE "name" MINVALUE min_value MAXVALUE max_value INCREMENT BY increment START WITH start CACHE cache NOORDER NOCYCLE NOKEEP
        // NOSCALE GLOBAL
        $sql = 'CREATE SEQUENCE "' . $seq->name . '"  MINVALUE ' . $seq->minValue . ' MAXVALUE ' . $seq->maxValue . ' INCREMENT BY ' . $seq->increment .
                ' START WITH ' . $seq->start . ' CACHE ' . $seq->cache . ' NOORDER  NOCYCLE  NOKEEP  NOSCALE  GLOBAL';
        $this->ExecuteQuery($sql);
        return $this->getError();
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManager::buildInsertOrUpdateQuery()
     */
    public function buildInsertOrUpdateQuery($tableName, $keyColumns, $updateColumns) {
        /*
         * MERGE INTO tableName USING DUAL ON (key1=valueKey1 AND valueKey2=value2)
         * WHEN MATCHED THEN UPDATE SET updateCol1 = updateValue1, updateCol1 = updateValue1
         * WHEN NOT MATCHED THEN INSERT (key1, key2, updateCol1, updateCol2) VALUES (valueKey1, valueKy2, updateValue1, updateValue2)
         */
        $conditions = [];
        $allColumns = [];
        $updates = [];
        foreach ($keyColumns as $colName => $value) {
            if ($value === null) {
                $value = 'NULL';
            }
            $allColumns[] = '"' . $colName . '"';
            $allValues[] = $value;
            $conditions[] = '"' . $colName . '"=' . $value;
        }

        $whenMatched = '';
        if (!empty($updateColumns)) {
            foreach ($updateColumns as $colName => $value) {
                if ($value === null) {
                    $value = 'NULL';
                }
                $allColumns[] = '"' . $colName . '"';
                $allValues[] = $value;
                $updates[] = '"' . $colName . '"=' . $value;
                $strUpdates = implode(',', $updates);
            }
            $whenMatched = "WHEN MATCHED THEN UPDATE SET $strUpdates";
        }

        $strCondition = implode(' AND ', $conditions);
        $strAllColumns = implode(',', $allColumns);
        $strAllValues = implode(',', $allValues);

        return "MERGE INTO $tableName USING DUAL ON ($strCondition) $whenMatched WHEN NOT MATCHED THEN INSERT ($strAllColumns) VALUES ($strAllValues)";
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
                return "NUMBER(38)";
            case DbDataTypes::INT :
                return "NUMBER(12)";
            case DbDataTypes::TINYINT :
                return "NUMBER(3)";
            case DbDataTypes::DECIMAL :
                $mySQLType = "NUMBER";
                if ($column->length > 0) {
                    $length = intval($column->length);
                    if (intval($column->scale) > 0 && intval($column->scale) < intval($column->length)) {
                        $length .= ',' . intval($column->scale);
                    }
                    $mySQLType .= "($length)";
                }
                return $mySQLType;
            case DbDataTypes::VARCHAR :
                return 'VARCHAR2(' . $column->length . ')';
            case DbDataTypes::CHAR :
                return 'CHAR(' . $column->length . ')';
            case DbDataTypes::DATETIME :
                return 'DATE';
            case DbDataTypes::TEXT :
                return 'VARCHAR2(4000)';
            case DbDataTypes::LONGTEXT :
                return 'CLOB';
            case DbDataTypes::BLOB :
                return 'BLOB';
        }
        return 'VARCHAR2(256)';
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


