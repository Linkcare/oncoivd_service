<?php

class DbTableDefinition {
    /** @var string */
    public $name;

    /** @var DbColumnDefinition[] */
    public $columns;

    /** @var string[] */
    public $primaryKey = null;
    /** @var boolean */
    public $autoIncrement = false;

    /** @var DbIndexDefinition[] */
    public $indexes = [];

    public function __construct($name, $columns, $primaryKey = null, $indexes = null, $autoIncrement = false) {
        $this->name = $name;
        if ($columns !== null) {
            $this->columns = is_array($columns) ? $columns : [$columns];
        }

        if ($primaryKey !== null) {
            $this->primaryKey = is_array($primaryKey) ? $primaryKey : [$primaryKey];
        }
        if ($indexes !== null) {
            $this->indexes = is_array($indexes) ? $indexes : [$indexes];
        }

        $this->autoIncrement = $autoIncrement;
    }

    /**
     * Returns the definition of a column or null if the column doesn't exist
     *
     * @param string $columnName
     * @return DbColumnDefinition
     */
    public function getColumn($columnName) {
        $columnName = strtoupper($columnName);
        foreach ($this->columns as $col) {
            if (strtoupper($col->name) == $columnName) {
                return $col;
            }
        }
        return null;
    }
}