<?php

class DbColumnDefinition {
    /** @var string */
    public $name;

    /** @var string Any of the DbDataTypes constants */
    public $dataType;

    /** @var int */
    public $length;

    /** @var int */
    public $scale = 0;

    /** @var boolean */
    public $nullable = true;

    /** @var string */
    public $defaultValue = null;

    /** @var boolean */
    public $autoincrement = null;

    /**
     *
     * @param string $name
     * @param string $dataType Any of the DbDataTypes constants
     * @param int $length
     * @param int $precision
     * @param boolean $nullable (default = true)
     * @param string $defaultValue
     * @param boolean $autoincrement (default = false). If true, the column will become the PRIMARY KEY of the table
     */
    public function __construct($name, $dataType, $length = null, $scale = null, $nullable = true, $defaultValue = null, $autoincrement = false) {
        $this->name = $name;
        $this->dataType = $dataType;
        $this->length = $length;
        $this->scale = $scale;
        $this->nullable = $nullable;
        $this->defaultValue = $defaultValue;
        $this->autoincrement = $autoincrement;
    }
}