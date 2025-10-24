<?php

class DbFKDefinition {
    /** @var string */
    public $name;

    /** @var string */
    public $table;

    /** @var string[] */
    public $columnNames;

    /** @var string */
    public $referencedTable;

    /** @var string[] */
    public $referencedColumnNames;

    /** @var boolean */
    public $onDeleteCascade = false;

    /**
     *
     * @param string $name Name assigned to the foreign key
     * @param string $table Name of the table with the columns that reference another table
     * @param string|string[] $columnNames Name of the columns that reference the columns of another table
     * @param string $referencedTable Name of the referenced table
     * @param string|string[] $referencedColumnNames Name of the referenced columns
     * @param boolean $onDeleteCascade
     */
    public function __construct($name, $table, $columnNames, $referencedTable, $referencedColumnNames, $onDeleteCascade = false) {
        $this->name = $name;
        $this->table = $table;
        $this->columnNames = is_array($columnNames) ? $columnNames : [$columnNames];
        $this->referencedTable = $referencedTable;
        $this->referencedColumnNames = is_array($referencedColumnNames) ? $referencedColumnNames : [$referencedColumnNames];
        $this->onDeleteCascade = $onDeleteCascade;
    }
}