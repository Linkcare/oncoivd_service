<?php

class DbManagerResultsMySQL extends DbManagerResults {
    /** @var PDOStatement */
    private $rst;
    var $rs;
    private $fieldNames;
    var $pdo;

    /**
     *
     * @param PDOStatement $pRst
     */
    function setResultSet($pRst) {
        $this->rst = $pRst;
        $this->rs = null;
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManagerResults::Next()
     */
    public function Next() {
        if ($this->rst) {
            $this->rs = $this->rst->fetch(PDO::FETCH_ASSOC);
            if (empty($this->fieldNames) && $this->rs) {
                // Normalize the field names to uppercase
                foreach (array_keys($this->rs) as $fieldName) {
                    if (is_numeric($fieldName)) {
                        continue;
                    }
                    $this->fieldNames[strtoupper($fieldName)] = $fieldName;
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
        // Normalize the field name to uppercase so that the search is case-insensitive
        $fieldName = strtoupper($fieldName);
        if (!array_key_exists($fieldName, $this->fieldNames)) {
            return null;
        }
        $key = $this->fieldNames[$fieldName];
        if (is_object($this->rs[$key])) {
            return $this->rs[$key]->load();
        } // clob oci
        else {
            return ($this->rs[$key]);
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see DbManagerResults::getColumnNames()
     */
    public function getColumnNames() {
        return $this->fieldNames != null ? array_keys($this->fieldNames) : null;
    }
}
