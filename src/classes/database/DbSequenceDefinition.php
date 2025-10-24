<?php

class DbSequenceDefinition {
    /** @var string */
    public $name;

    /** @var number */
    public $minValue = 1;

    /** @var number */
    public $maxValue = PHP_INT_MAX;

    /** @var number */
    public $start = 1;

    /** @var number */
    public $increment = 1;

    /** @var number */
    public $cache = 20;

    public function __construct($name, $minValue = 1, $maxValue = PHP_INT_MAX, $increment = 1, $start = 1, $cache = 20) {
        $this->name = $name;
        $this->minValue = $minValue;
        $this->maxValue = $maxValue;
        $this->start = max($minValue, $start);
        $this->increment = $increment;
        $this->cache = $cache;
    }
}