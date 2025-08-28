<?php

abstract class APIQuestionTypes extends ConstantsBase {
    const NUMERICAL = 'NUMERICAL';
    const BOOLEAN = 'BOOLEAN';
    const TEXT = 'TEXT';
    const TEXT_AREA = 'TEXT_AREA';
    const STATIC_TEXT = 'STATIC_TEXT';
    const SELECT = 'SELECT';
    const DATE = 'DATE';
    const TIME = 'TIME';
    const HORIZONTAL_CHECK = 'HORIZONTAL_CHECK';
    const VERTICAL_CHECK = 'VERTICAL_CHECK';
    const VERTICAL_RADIO = 'VERTICAL_RADIO';
    const HORIZONTAL_RADIO = 'HORIZONTAL_RADIO';
    const FORM = 'FORM';
    const CODE = 'CODE';
    const GRAPH = 'GRAPH';
    const FILE = 'FILE';
    const ACTION = 'ACTION';
    const LINK = 'LINK';
    const EDITABLE_STATIC_TEXT = 'TEXT_AREA';
    const HTML = 'HTML';
    const JSON = 'JSON';
    const DEVICE = 'DEVICE';
    const AGE = 'AGE';
    const SLIDER = 'VAS';
    const MULTIMEDIA = 'MULTIMEDIA';
    const GEOLOCATION = 'GEOLOCATION';
    const CASE_DATA = 'CASE_DATA';
    const COUNTDOWN = 'COUNTDOWN';
    const QR = 'QR';
    const EVALUATION = 'EVALUATION';
    const TRAINER = 'TRAINER';
    const PHONE = 'PHONE';
    const EMAIL = 'EMAIL';
    const ASSOCIATE = 'ASSOCIATE';
    const ARRAY = 'ARRAY';
    const SIGNATURE = 'SIGNATURE';

    /**
     * Returns true if the values of the requested question type can be multiple options selected from a predefined list.
     * The particularity of this type of question is that the values can be assigned as an array of ID (the reference of the option) or an array of
     * values (the value assigned to the option)
     *
     * @param string $type
     * @return boolean
     */
    static public function isMultiOptions($type) {
        return $type == self::HORIZONTAL_CHECK || $type == self::VERTICAL_CHECK;
    }

    /**
     * Returns true if the values of the requested question type are a single option selected from a predefined list.
     * The particularity of this type of question is that the value can be assigned as a ID (the reference of the option) or by value (the value
     * assigned to the option)
     *
     * @param string $type
     * @return boolean
     */
    static public function isSingleOption($type) {
        return $type == self::HORIZONTAL_RADIO || $type == self::VERTICAL_RADIO || $type == self::SELECT;
    }

    /**
     * Returns true if the values of the requested question type are scalar.
     *
     * @param string $type
     * @return boolean
     */
    static public function isScalar($type) {
        $scalarTypes = [self::NUMERICAL, self::BOOLEAN, self::TEXT, self::TEXT_AREA, self::DATE, self::TIME, self::HTML, self::JSON, self::AGE,
                self::SLIDER, self::PHONE, self::EMAIL];
        return in_array($type, $scalarTypes);
    }
}