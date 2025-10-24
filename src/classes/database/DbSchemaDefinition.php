<?php

class DbSchemaDefinition {
    /** @var string */
    public $name;

    /** @var DbTableDefinition[] */
    public $tables = [];

    /** @var DbFKDefinition[] */
    public $foreignKeys = [];

    /** @var DbSequenceDefinition[] */
    public $sequences = [];

    /**
     *
     * @param string $name
     * @param DbTableDefinition|DbTableDefinition[] $tables
     * @param DbFKDefinition|DbFKDefinition[] $foreignKeys
     */
    public function __construct($name, $tables, $foreignKeys = null, $sequences = []) {
        $this->name = $name;
        if ($tables !== null) {
            $this->tables = is_array($tables) ? $tables : [$tables];
        }

        if ($foreignKeys !== null) {
            $this->foreignKeys = is_array($foreignKeys) ? $foreignKeys : [$foreignKeys];
        }
        if ($sequences !== null) {
            $this->sequences = is_array($sequences) ? $sequences : [$sequences];
        }
    }

    /**
     * Returns the definition of a table or null if the table doesn't exist
     *
     * @param string $tableName
     * @return DbTableDefinition
     */
    public function getTable($tableName) {
        $tableName = strtoupper($tableName);
        foreach ($this->tables as $tbl) {
            if (strtoupper($tbl->name) == $tableName) {
                return $tbl;
            }
        }
        return null;
    }
}