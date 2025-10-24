<?php

class DbIndexDefinition {
    /** @var string */
    public $name;

    /** @var string[] */
    public $columns = [];

    /** @var boolean */
    public $unique = false;

    /**
     *
     * @param string $name
     * @param string|string[] $columns
     * @param boolean $unique
     */
    public function __construct($name, $columns, $unique = false) {
        $this->name = $name;
        if (!is_array($columns)) {
            $columns = [$columns];
        }
        $this->columns = $columns;
        $this->unique = $unique;
    }
}