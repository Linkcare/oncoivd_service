<?php

class DbHelper {

    /**
     * Concatenates values in an array or scalar value and generates a SQL style string to be included in a query
     * Example (from array): ["A", "B", "C"] ==> "'A', 'B', 'C'"
     * Example (from scalar): "D" ==> "'D'"
     *
     * @param array $value : can be an array or scalar
     * @return string
     */
    static function array_to_quoted_string($value) {
        if (!isset($value))
            return '';

        if (!is_array($value)) {
            $value = self::escapeString($value);
            return "'$value'";
        }

        $sqlStr = "";
        foreach ($value as $str) {
            if ($sqlStr != "") {
                $sqlStr .= ",";
            }
            $str = self::escapeString($str);
            $sqlStr .= "'$str'";
        }

        return $sqlStr;
    }

    /**
     * Concatenates values in an array or scalar value and generates a SQL style string to be included in a query
     * Example (from array): [12, 15, 22] ==> "12, 15, 22"
     * Example (from scalar): 12 ==> "12"
     *
     * @param array $value : can be an array or scalar
     * @return string
     */
    static function array_to_string($value) {
        if (!isset($value))
            return '';

        if (!is_array($value))
            return "$value";

        $sqlStr = "";
        foreach ($value as $str) {
            if ($sqlStr != "")
                $sqlStr .= ",";
            $sqlStr .= "$str";
        }

        return $sqlStr;
    }

    /**
     * Escapes special characters to avoid SQL errors
     *
     * @param string $text
     * @return string
     */
    static function escapeString($text) {
        if (!$text) {
            return $text;
        }

        $escapedStr = str_replace("'", "''", trim($text));

        return $escapedStr;
    }

    /**
     * The OCI component always returns field values as strings or NULL.
     * This function forces the conversion to an integer value, except when
     * the value is NULL or "", in which case returns NULL
     *
     * @param string $value
     * @return NULL|number
     */
    static function intValue($value) {
        return ($value === null || $value === "" ? null : intval($value));
    }

    /**
     * Returns a string :id1,:id2,:id3 and also updates your $bindArray of bindings needed to pass to ExecuteBindQuery()
     *
     * @param string $prefix
     * @param mixed $values
     * @param Array $bindArray
     * @return string
     */
    static function bindParamArray($prefix, $values, &$bindArray) {
        $str = "";
        if (!is_array($values)) {
            $values = [$values];
        }
        $values = array_values($values); // To remove any possible index in associative arrays
        foreach ($values as $index => $value) {
            $str .= ":" . $prefix . $index . ",";
            $bindArray[":" . $prefix . $index] = $value;
        }
        return rtrim($str, ",");
    }

    /**
     * Dumps the contents of a table to a file.
     * The exported file can later be imported using the function populateTable()<br>
     * Returns the number of rows extracted
     *
     * @param DbManager $db
     * @param string $tableName
     * @param string $fileName
     * @param string $where Optional clause to filter the rows dump. Must be a valid SQL expression that will be placed in the WHERE statement
     * @throws DbException
     * @return int
     */
    static public function dumpTable($db, $tableName, $fileName, $where = null, $orderBy = null, $progressCallback = null) {
        $row = 0;
        $sql = "SELECT * FROM $tableName" . (trim($where) != '' ? " WHERE $where" : '') . ($orderBy ? " ORDER BY $orderBy" : '');

        $countSql = "SELECT COUNT(*) AS TOTAL FROM ($sql) tbl";
        $rst = $db->ExecuteBindQuery($countSql);
        if ($rst->Next()) {
            $total = $rst->GetField('TOTAL');
        }

        $f = fopen($fileName, 'w');
        if (!$f) {
            throw new DbException(new ErrorDescriptor(DbErrors::UNEXPECTED_ERROR, "Can't open file $fileName"));
        }

        try {
            $offset = 1;
            $limit = 100;
            $parcial = 0;
            $columnNames = null;
            while ($parcial < $limit) {
                $rst = $db->ExecuteBindQuery($sql, null, $limit, $offset);
                while ($rst->Next()) {
                    $parcial++;
                    if ($row == 0) {
                        $columnNames = $rst->getColumnNames();
                        fwrite($f, json_encode($columnNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                    }
                    $rowData = null;
                    foreach ($columnNames as $col) {
                        $rowData[] = $rst->GetField($col);
                    }
                    fwrite($f, json_encode($rowData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                    $row++;
                }
                if ($parcial < $limit) {
                    break;
                }
                $parcial = 0;
                $offset += $limit;

                if (is_callable($progressCallback)) {
                    $progressCallback($row, $total);
                }
            }
            if (is_callable($progressCallback)) {
                $progressCallback($row, $total);
            }
        } catch (DbException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new DbException(new ErrorDescriptor(DbErrors::UNEXPECTED_ERROR, $e->getMessage()));
        } finally {
            fclose($f);
        }

        return $row;
    }

    /**
     * Imports data from a file (exported with the function dumpTable() ) into a table.
     * Returns the number of rows imported
     *
     * @param DbManager $db
     * @param DbTableDefinition $tableDef
     * @param string $filename
     * @param int $offset Start importing from this line (base 0)
     * @throws DbException
     * @return int
     */
    static public function populateTable($db, $tableDef, $filename, $offset = 0, $progressCallback = null) {
        $row = 0;
        $totalLines = self::countFileLines($filename);
        if (!$totalLines) {
            return 0;
        }
        $totalLines--; // Note that the first line is the header

        $f = fopen($filename, 'r');
        if (!$f) {
            throw new DbException(new ErrorDescriptor(DbErrors::UNEXPECTED_ERROR, "Can't open file $filename"));
        }

        $failed = false;
        try {
            $db->beginTransaction();
            $fieldList = '';
            $headerProcessed = false;
            while (($line = fgets($f)) !== false) {
                $data = json_decode($line);
                if ($headerProcessed == 0) {
                    $columnNames = $data;
                    if (!is_array($columnNames)) {
                        break;
                    }

                    $columns = [];
                    $normNames = [];
                    foreach ($columnNames as $colName) {
                        if ($colDef = $tableDef->getColumn($colName)) {
                            $columns[] = $colDef;
                            $normNames[] = $colName;
                        } else {
                            $columns[] = null;
                        }
                    }
                    $fieldList = implode(',', $normNames);
                    // This was the header column. Go to the first data column
                    $headerProcessed = true;
                    continue;
                }

                if ($row++ < $offset) {
                    continue;
                }

                $rowValues = [];
                $ix = 0;
                foreach ($columns as $colDef) {
                    $value = $data[$ix++];
                    if ($colDef == null) {
                        continue;
                    }
                    $rowValues[':v' . $ix] = $value;
                }
                $paramList = implode(',', array_keys($rowValues));
                $sql = "INSERT INTO $tableDef->name ($fieldList) VALUES ($paramList)";
                $db->ExecuteBindQuery($sql, $rowValues);
                $error = $db->getError();
                if ($error->getErrCode()) {
                    throw new DbException($error);
                }

                if ($row % 10 == 0 && is_callable($progressCallback)) {
                    $db->commit();
                    $db->beginTransaction();
                    $progressCallback($row, $totalLines);
                }
            }
            $progressCallback($row, $totalLines);
            $db->commit();
        } catch (DbException $e) {
            $failed = true;
            throw $e;
        } catch (Exception $e) {
            $failed = true;
            throw new DbException(new ErrorDescriptor(DbErrors::UNEXPECTED_ERROR, $e->getMessage()));
        } finally {
            if ($failed) {
                $db->rollback();
            }
            fclose($f);
        }

        return $row;
    }

    /**
     * Count the number of lines of a file
     *
     * @param string $filename
     * @return number
     */
    static private function countFileLines($filename) {
        $linecount = 0;
        if (!file_exists($filename)) {
            return null;
        }

        $handle = fopen($filename, "r");
        if (!$handle) {
            return null;
        }
        while (!feof($handle)) {
            $buffer = fgets($handle, 4096);
            $linecount += substr_count($buffer, "\n");
        }
        if (strlen($buffer) > 0 && $buffer[-1] != "\n") {
            $linecount++;
        }

        fclose($handle);
        return $linecount;
    }
}

?>