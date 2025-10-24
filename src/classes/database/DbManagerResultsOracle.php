<?php

class DbManagerResultsOracle extends DbManagerResults {
    var $rst;
    var $rs;
    var $pdo;
    private $fieldNames;

    public function setResultSet($pRst, $pdo = true) {
        $this->pdo = $pdo;
        $this->rst = $pRst;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManagerResults::Next()
     */
    public function Next() {
        if ($this->rst) {
            if ($this->pdo) {
                $this->rst->fetch(PDO::FETCH_ASSOC);
            } else {
                $this->rs = oci_fetch_array($this->rst);
                if (empty($this->fieldNames) && $this->rs) {
                    // Normalize the field names to uppercase
                    foreach (array_keys($this->rs) as $fieldName) {
                        if (is_numeric($fieldName) || $fieldName == '$_RN') {
                            continue;
                        }
                        $this->fieldNames[strtoupper($fieldName)] = $fieldName;
                    }
                }
            }
        } else {
            return false;
        }
        return ($this->rs);
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManagerResults::GetField()
     */
    public function GetField($fieldName) {
        if (!isset($this->rs[$fieldName])) {
            return null;
        } else {
            if (is_object($this->rs[$fieldName])) {
                return $this->rs[$fieldName]->load();
            } // clob oci
            else {
                return ($this->rs[$fieldName]);
            }
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManagerResults::GetLOBChunk()
     */
    public function GetLOBChunk($fieldName, $startPos, $length) {
        if ($startPos < 0 || !isset($this->rs[$fieldName]) || !is_object($this->rs[$fieldName])) {
            return null;
        }

        $size = $this->rs[$fieldName]->size();
        if ($startPos >= $size) {
            return null;
        }
        if ($startPos + $length >= $size) {
            $length = $size - $startPos;
        }

        $this->rs[$fieldName]->seek($startPos);
        return $this->rs[$fieldName]->read($length);
    }

    /**
     * Returns the names of the columns retrieved by the query.
     * Note that this function will only return a value after having called the function Next() at least once.
     *
     * @return string[]
     */
    function getColumnNames() {
        return $this->fieldNames != null ? array_keys($this->fieldNames) : null;
    }
}
