<?php

/**
 * Returns true if the string $needle is found exactly at the begining of $haystack
 *
 * @param string $needle
 * @param string $haystack
 * @return boolean
 */
function startsWith($needle, $haystack) {
    return (strpos($haystack, $needle) === 0);
}

/**
 * Returns true if the $value passed is strictly equal to null or an empty string or a string composed only by spaces
 *
 * @param string $value
 */
function isNullOrEmpty($value) {
    return is_null($value) || trim($value) === "";
}

/**
 * Converts a string variable to boole
 * - return true if $text = ['y', 'yes', 'true', '1']
 * - return false otherwise
 *
 * @param string $value
 * @return bool
 */
function textToBool($text) {
    $text = trim(strtolower($text));
    $valNum = 0;
    if (is_numeric($text)) {
        $valNum = intval($text);
    }

    if (in_array($text, ['s', 'y', 'yes', 'true', '1']) || $valNum) {
        $boolValue = true;
    } else {
        $boolValue = false;
    }

    return $boolValue;
}

/**
 * Converts a value to a equivalent boolean string ('true' or 'false')
 *
 * @param string $value
 * @return string
 */
function boolToText($value) {
    return $value ? 'true' : 'false';
}

/**
 * Converts a expression to an integer if it is not null nor empty string.
 * Otherwise returns null
 *
 * @param mixed $value
 * @return NULL|number
 */
function NullableInt($value) {
    if (isNullOrEmpty($value)) {
        return null;
    }
    return intval($value);
}

/**
 * Converts a expression to an string if it is not null.
 * Otherwise returns null.
 * An zero-length string is considered NULL
 *
 * @param mixed $value
 * @return NULL|string
 */
function NullableString($value) {
    if ($value !== null) {
        $value = "" . $value;
    }
    if ($value === "") {
        $value = null;
    }
    return $value;
}

/**
 * Sets the time zone based on the Operative System configuration
 */
function setSystemTimeZone() {
    $timezone = $GLOBALS["DEFAULT_TIMEZONE"];
    if (is_link('/etc/localtime')) {
        // Mac OS X (and older Linuxes)
        // /etc/localtime is a symlink to the
        // timezone in /usr/share/zoneinfo.
        $filename = readlink('/etc/localtime');
        if (strpos($filename, '/usr/share/zoneinfo/') === 0) {
            $timezone = substr($filename, 20);
        }
    } elseif (file_exists('/etc/timezone')) {
        // Ubuntu / Debian.
        $data = file_get_contents('/etc/timezone');
        if ($data) {
            $timezone = $data;
        }
    } elseif (file_exists('/etc/sysconfig/clock')) {
        // RHEL / CentOS
        $data = parse_ini_file('/etc/sysconfig/clock');
        if (!empty($data['ZONE'])) {
            $timezone = $data['ZONE'];
        }
    }
    date_default_timezone_set($timezone);
}

/**
 * Implementation of mb_str_split() for version of PHP < 7.4.0
 *
 * @param string $string
 * @param number $split_length
 * @param string $encoding
 * @return string
 */
function str_split_multibyte($string, $split_length = 1, $encoding = null) {
    if (null !== $string && !\is_scalar($string) && !(\is_object($string) && \method_exists($string, '__toString'))) {
        trigger_error('mb_str_split(): expects parameter 1 to be string, ' . \gettype($string) . ' given', E_USER_WARNING);
        return null;
    }
    if (null !== $split_length && !\is_bool($split_length) && !\is_numeric($split_length)) {
        trigger_error('mb_str_split(): expects parameter 2 to be int, ' . \gettype($split_length) . ' given', E_USER_WARNING);
        return null;
    }
    $split_length = (int) $split_length;
    if (1 > $split_length) {
        trigger_error('mb_str_split(): The length of each segment must be greater than zero', E_USER_WARNING);
        return false;
    }
    if (null === $encoding) {
        $encoding = mb_internal_encoding();
    } else {
        $encoding = (string) $encoding;
    }

    if (!in_array($encoding, mb_list_encodings(), true)) {
        static $aliases;
        if ($aliases === null) {
            $aliases = [];
            foreach (mb_list_encodings() as $encoding) {
                $encoding_aliases = mb_encoding_aliases($encoding);
                if ($encoding_aliases) {
                    foreach ($encoding_aliases as $alias) {
                        $aliases[] = $alias;
                    }
                }
            }
        }
        if (!in_array($encoding, $aliases, true)) {
            trigger_error('mb_str_split(): Unknown encoding "' . $encoding . '"', E_USER_WARNING);
            return null;
        }
    }

    $result = [];
    $length = mb_strlen($string, $encoding);
    for ($i = 0; $i < $length; $i += $split_length) {
        $result[] = mb_substr($string, $i, $split_length, $encoding);
    }
    return $result;
}

function loadParam($parameters, $propertyName, $defaultValue = null) {
    return $parameters && property_exists($parameters, $propertyName) ? $parameters->$propertyName : $defaultValue;
}

/**
 * Modifies an object to preserve only the properties defined in the $properties array.
 *
 * @param stdClass $object
 * @param string[] $properties
 */
function preserveProperties($object, $properties) {
    if (!is_object($object) || !is_array($properties)) {
        return;
    }

    $keys = array_keys(get_object_vars($object));
    foreach ($keys as $key) {
        if (!in_array($key, $properties)) {
            unset($object->$key);
        }
    }
}

/**
 * Removes the properties of the $filters object that are null, empty or a string of spaces.
 *
 * @param stdClass $filters
 */
function cleanFilters($filters) {
    if (is_null($filters) || !is_object($filters)) {
        return;
    }

    foreach ($filters as $property => $value) {
        if (is_null($value) || (is_string($value) && trim($value) === '')) {
            unset($filters->$property);
        }
    }
}

function removeBomUtf8($s) {
    if (substr($s, 0, 3) == chr(hexdec('EF')) . chr(hexdec('BB')) . chr(hexdec('BF'))) {
        return substr($s, 3);
    } else {
        return $s;
    }
}
