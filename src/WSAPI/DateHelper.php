<?php

class DateHelper {

    /**
     * Calculates the current date in the specified timezone
     *
     * @param string|number $timezone
     * @return string
     */
    static public function currentDate($timezone = null) {
        $tz_object = new DateTimeZone('UTC');
        $datetime = new DateTime();
        $datetime->setTimezone($tz_object);
        $dateUTC = $datetime->format('Y\-m\-d\ H:i:s');

        return self::UTCToLocal($dateUTC, $timezone);
    }

    /**
     * Builds a date string with format yyyy-mm-dd hh:mm:ss from the date parts
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param int $second
     * @return string
     */
    static public function buildDatetime($year, $month = 1, $day = 1, $hour = 0, $minute = 0, $second = 0) {
        $year = max(intval($year), 2000);
        $month = max(intval($month), 1);
        $day = max(intval($day), 1);
        $hour = max(intval($hour), 0);
        $minute = max(intval($minute), 0);
        $second = max(intval($second), 0);
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    /**
     * Extract the date part of a full datetime expression.
     * The returned value is a date expression with format YYYY-MM-DD
     *
     * @param string $dateTime
     */
    static public function datePart($datetime) {
        $normalized = null;
        if (!self::isValidDate($datetime, $normalized)) {
            return null;
        }
        return explode(' ', $normalized)[0];
    }

    /**
     * Extract the time part of a full datetime expression
     * If the time is not included in the datetime expression, the function returns '00:00:00'<br>
     * The returned value is a time expression with format hh:mm:ss
     *
     * @param string $dateTime
     */
    static public function timePart($datetime) {
        $normalized = null;
        if (self::isValidDate($datetime, $normalized)) {
            return explode(' ', $normalized)[1];
        }

        if (self::isValidTime($datetime, $normalized)) {
            return $normalized;
        }
        return null;
    }

    /**
     * Verifies if a string has a valid datetime format and corresponds to a real date
     * A date is valid if:
     * <ul>
     * <li>has the format YYYY-MM-DD[ hh:mm:ss] (month, day, hour, etc can be expressed with a single digit)</li>
     * <li>Is a valid date (for example, 2024-13-01 is an invalid date because 13 is not a real month)</li>
     * </ul>
     *
     * @param string $datetime
     * @param string $normalized If $normalized is provided, then it is filled with a normalized version of the date with format "YYYY-MM-DD hh:mm:ss"
     * @return boolean
     */
    static public function isValidDate($datetime, &$normalized = null) {
        $normalized = null;
        $datetime = trim($datetime);
        $regexp = '/^(\d{4})-(\d{1,2})-(\d{1,2})\s?(\d{1,2})?(:\d{1,2})?(:\d{1,2})?$/';

        $matches = null;
        if (!preg_match($regexp, $datetime, $matches)) {
            return null;
        }

        $year = intval($matches[1]);
        $month = intval($matches[2]);
        $day = intval($matches[3]);

        $hour = count($matches) > 4 ? intval($matches[4]) : 0;
        $minute = count($matches) > 5 ? intval(ltrim($matches[5], ':')) : 0;
        $second = count($matches) > 6 ? intval(ltrim($matches[6], ':')) : 0;
        if ($hour >= 24 || $minute >= 60 || $second >= 60) {
            return false;
        }

        if (checkdate($month, $day, $year)) {
            $normalized = sprintf("%04d-%02d-%02d %02d:%02d:%02d", $year, $month, $day, $hour, $minute, $second);
            return true;
        }

        return false;
    }

    /**
     * Verifies if a string has a valid time format and corresponds to a real time
     * A time is valid if:
     * <ul>
     * <li>has the format hh:mm:ss (hour, minute and second can be expressed with a single digit)</li>
     * <li>Is a valid time(for example, 12:62:03 is an invalid time because 62 is not a valid minute value)</li>
     * </ul>
     * The time expression is considered valid if at least the "hour" part is present (minutes and seconds can be omitted).
     *
     * @param string $time
     * @param string $normalized If $normalized is provided, then it is filled with a normalized version of the time with format "hh:mm:ss"
     * @return boolean
     */
    static public function isValidTime($time, &$normalized = null) {
        $normalized = null;
        $time = trim($time);
        $regexp = '/^(\d{1,2})(:\d{1,2})?(:\d{1,2})$/';

        $matches = null;
        if (!preg_match($regexp, $time, $matches)) {
            return null;
        }

        $hour = intval($matches[1]);
        $minute = count($matches) > 2 ? intval(ltrim($matches[2], ':')) : 0;
        $second = count($matches) > 3 ? intval(ltrim($matches[3], ':')) : 0;

        if ($hour >= 24 || $minute >= 60 || $second >= 60) {
            return false;
        }

        $normalized = sprintf("%02d:%02d:%02d", $hour, $minute, $second);

        return true;
    }

    /**
     * Generates a full datetime expression from date and time provided.
     * The returned values is a full datetime expression with format 'YYYY-MM-DD hh:mm:ss'<br>
     * The values provided in $date and $time can be full datetimes, but only the date and time part will be used respectively
     *
     * @param string $date
     * @param string $time
     */
    static public function compose($date, $time) {
        $fullDate = trim(self::datePart($date) . ' ' . self::timePart($time));

        return $fullDate;
    }

    /**
     * Converts a local time of the timezone provided to the corresponding UTC time
     *
     * @param string $localTime full date in format YYYY-MM-DD hh:mm:ss
     * @param string $timezone
     */
    static public function localToUTC($localTime, $timezone) {
        if (!$localTime) {
            return null;
        }

        if (startsWith('UTC+', $timezone)) {
            $timezone = explode('UTC+', $timezone)[1];
        } elseif (startsWith('UTC-', $timezone)) {
            $timezone = -explode('UTC-', $timezone)[1];
        }

        if (is_numeric($timezone)) {
            return self::applyTimeShift($localTime, -$timezone); // Convert to UTC time
        }

        try {
            $dt = new DateTime($localTime, new DateTimeZone($timezone));
            $dt->setTimezone(new DateTimeZone("UTC"));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            // Invalid date or timezone
            return $localTime;
        }
    }

    /**
     * Converts an UTC time to the corresponding local time of the timezone provided
     *
     * @param string $localTime full date in format YYYY-MM-DD hh:mm:ss
     * @param string $timezone
     */
    static public function UTCToLocal($UTCTime, $timezone) {
        if (!$UTCTime) {
            return null;
        }

        if (startsWith('UTC+', $timezone)) {
            $timezone = explode('UTC+', $timezone)[1];
        } elseif (startsWith('UTC-', $timezone)) {
            $timezone = -explode('UTC-', $timezone)[1];
        }

        if (is_numeric($timezone)) {
            return self::applyTimeShift($UTCTime, $timezone); // Convert to UTC time
        }

        try {
            $dt = new DateTime($UTCTime, new DateTimeZone("UTC"));
            $dt->setTimezone(new DateTimeZone($timezone));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            // Invalid date or timezone
            return $UTCTime;
        }
    }

    /**
     * Returns the offset in hours that corresponds to the provided timezone with respect to UTC
     * The return value is expressed in hours, rounding to half hours.
     *
     * @param string $timezone
     * @return number
     */
    static function timezoneOffset($timezone) {
        if (strpos($timezone, 'UTC+') === 0) {
            $timezone = explode('UTC+', $timezone)[1];
        } elseif (strpos($timezone, 'UTC-') === 0) {
            $timezone = -explode('UTC-', $timezone)[1];
        }

        if (is_numeric($timezone)) {
            return $timezone;
        }
        // difference in hours (rounding to half hours):
        $interval = strtotime(todayInTimezone($timezone)) - strtotime(todayUTC());
        $interval = intval(($interval + 900) / 1800) / 2;
        return $interval;
    }

    /**
     * Returns the offset in hours that corresponds to the provided date with respect to UTC
     * The return value is expressed in hours, rounding to half hours.
     * If the date provided does not fall in a range of 12h around the UTC time, it is considered invalid and null will be returned
     *
     * @param string $date
     * @return number
     */
    static public function localDateOffset($date) {
        // difference in hours (rounding to half hours):
        $interval = strtotime($date) - strtotime(todayUTC());
        $interval = intval(($interval + 900) / 1800) / 2;
        if ($interval > 12 || $interval < -11) {
            return null;
        }
        return $interval;
    }

    /**
     * Adds a period (seconds, minutes...) to a date.
     * Valid period units:
     * <ul>
     * <li>seconds</li>
     * <li>minutes</li>
     * <li>hours</li>
     * <li>days</li>
     * <li>weeks</li>
     * <li>months</li>
     * <li>years</li>
     * </ul>
     *
     * @param string $date
     * @param int $period
     * @param string $units
     * @return string
     */
    static public function addPeriod($date, $period, $units) {
        if ($period < 0) {
            $period = abs($period);
            return date('Y-m-d H:i:s', strtotime($date . "- $period $units"));
        } else {
            return date('Y-m-d H:i:s', strtotime($date . "+ $period $units"));
        }
    }

    /**
     * Returns true if a date string has time part (at least hour and minutes)<br>
     * If the string does not have a valid date format, the function returns false
     *
     * @param string $datetime
     * @return boolean
     */
    static function hasTimePart($datetime) {
        $regexp = '/^\d{4}-\d\d?-\d\d?\s(\d\d?)(:\d\d?)?(:\d\d?)$/';

        if (!preg_match($regexp, $datetime)) {
            return false;
        }

        return true;
    }

    /**
     * Returns a Unix timestamp (seconds since 1/1/1970 UTC) from a date expressed in a timezone
     *
     * @param string $localDate Local date in format 'yyyy-mm-dd hh:mm:ss'
     * @param string $timezone Timezone of the date
     * @return number
     */
    static public function localDateToUnixTimestamp($localDate, $timezone) {
        // The dates must be a timestamp of a 13-digit integer, in milliseconds.
        $curTimeZone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $timezoneOffset = self::timezoneOffset($timezone) * 3600;

        $UTCTimestamp = strtotime($localDate) - $timezoneOffset;

        date_default_timezone_set($curTimeZone);

        return $UTCTimestamp;
    }

    /**
     * Converts a Unix timestamp (seconds since 1/1/1970 UTC) to a local date of a selected timezone
     *
     * @param number $timestamp
     * @param int|string $timezone
     * @param string $format (default = 'Y-m-d H:i:s')
     * @return string
     */
    static public function UnixTimestampToLocalDate($timestamp, $timezone, $format = 'Y-m-d H:i:s') {
        $timezoneOffset = self::timezoneOffset($timezone) * 3600;

        $curTimeZone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $localDate = date($format, $timestamp + $timezoneOffset);
        date_default_timezone_set($curTimeZone);

        return $localDate;
    }

    /**
     * Returns true if a date string has date part (year, month and date)<br>
     * If the string does not have a valid date format, the function returns false
     *
     * @param string $datetime
     * @return boolean
     */
    static function hasDatePart($datetime) {
        $regexp = '/^\d{4}-\d\d?-\d\d?(\s(\d\d?)?(:\d\d?)?(:\d\d?)?)?$/';

        if (!preg_match($regexp, $datetime)) {
            return false;
        }

        return true;
    }

    /**
     * Applies a time shift (in hours) to a date
     *
     * @param string $date
     * @param number $shift A time shift expressed in hours
     * @return string
     */
    static private function applyTimeShift($date, $shift) {
        if (!$date) {
            return null;
        }
        if (!is_numeric($shift)) {
            return $date;
        }

        // The offset in some timezones is not an integer number of hours
        $shift = intval($shift * 60);
        $d = strtotime($date);
        if (!$d) {
            $d = strtotime(todayUTC());
        }
        return date('Y-m-d H:i:s', strtotime("$shift minutes", $d));
    }
}
