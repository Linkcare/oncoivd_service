<?php

class DbManagerResults {

    /**
     * Moves the cursor of the recordset returned by the execution of a SQL query to the next row.
     * Returns false if there are no more rows
     *
     * @return boolean
     */
    public function Next() {
        return false;
    }

    /**
     * returns the value of a column retrieved from a SQL query
     *
     * @return mixed
     */
    public function GetField($fieldName) {
        return ("");
    }

    /**
     * Returns the names of the columns retrieved by the query.
     * Note that this function will only return a value after having called the function Next() at least once.
     *
     * @return string[]
     */
    public function getColumnNames() {
        return null;
    }
}
