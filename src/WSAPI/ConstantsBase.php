<?php

abstract class ConstantsBase {
    private static $constCacheArray = NULL;

    /**
     *
     * @return array
     */
    static private function getConstants() {
        if (self::$constCacheArray == NULL) {
            self::$constCacheArray = [];
        }
        $calledClass = get_called_class();
        if (!array_key_exists($calledClass, self::$constCacheArray)) {
            $reflect = new ReflectionClass($calledClass);
            self::$constCacheArray[$calledClass] = $reflect->getConstants();
        }
        return self::$constCacheArray[$calledClass];
    }

    /**
     *
     * @param string $name
     * @param boolean $strict
     * @return boolean
     */
    static public function isValidName($name, $strict = false) {
        $name = trim($name);
        $constants = self::getConstants();

        if ($strict) {
            return array_key_exists($name, $constants);
        }

        $keys = array_map('strtolower', array_keys($constants));
        return in_array(strtolower($name), $keys);
    }

    /**
     *
     * @param mixed $value
     * @param boolean $strict
     * @return boolean
     */
    static public function isValidValue($value, $strict = true) {
        $values = array_values(self::getConstants());
        return in_array($value, $values, $strict);
    }

    /**
     *
     * @return array
     */
    static public function valueList() {
        return array_values(self::getConstants());
    }

    /**
     *
     * @param string $name
     * @return mixed
     */
    static public function fromName($name) {
        $constants = array_map(function ($constantName) {
            return strtoupper($constantName);
        }, self::getConstants());

        $name = strtoupper(trim($name));
        if (array_key_exists($name, $constants)) {
            return $constants[$name];
        }

        return null;
    }

    /**
     *
     * @param mixed $value
     * @return string
     */
    static public function getName($value) {
        return array_search($value, self::getConstants());
    }
}