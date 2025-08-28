<?php

class APIQuestion {
    const OPTIONS_TYPES = [APIQuestionTypes::BOOLEAN, APIQuestionTypes::SELECT, APIQuestionTypes::HORIZONTAL_CHECK, APIQuestionTypes::VERTICAL_CHECK,
            APIQuestionTypes::VERTICAL_RADIO, APIQuestionTypes::HORIZONTAL_RADIO];

    // Private members
    private $id;
    private $formId;
    private $itemCode;
    private $questionTemplateId;
    private $name;
    private $unit;
    private $order;
    private $arrayRef;
    /** @var boolean */
    private $emptyRow = false;
    private $row;
    private $column;
    private $decimals;
    private $mandatory;
    private $description;
    private $descriptionOnEdit;
    private $constraint;
    private $dataCode;
    private $type;
    private $value;
    private $optionId; // For multianswer questions (OPTIONS / CHECKS)
    private $valueDescription;
    /** @var APIQuestionOption[] */
    private $options;
    private $modified = true;
    private $api;

    public function __construct($itemCode = null, $value = null, $optionId = null, $type = null) {
        $this->api = LinkcareSoapAPI::getInstance();

        $this->itemCode = $itemCode;
        $this->value = $value;
        $this->optionId = $optionId;
        $this->type = $type;
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APIQuestion
     */
    static public function parseXML($xmlNode, $formId) {
        if (!$xmlNode) {
            return null;
        }
        $question = new APIQuestion();
        $question->formId = $formId;
        $question->id = NullableString($xmlNode->question_id);
        $question->itemCode = NullableString($xmlNode->item_code);
        $question->questionTemplateId = NullableString($xmlNode->question_template_id);

        $question->order = intval($xmlNode->order);
        $question->arrayRef = NullableString($xmlNode->array_ref);
        $question->row = NullableInt($xmlNode->row);
        $question->column = NullableInt($xmlNode->column);
        if ($question->row) {
            $question->emptyRow = textToBool($xmlNode->empty_row);
        }
        $question->decimals = NullableInt($xmlNode->num_dec);
        $question->mandatory = textToBool($xmlNode->mandatory);
        $question->description = NullableString($xmlNode->description);
        $question->descriptionOnEdit = NullableString($xmlNode->description_onedit);
        $question->constraint = NullableString($xmlNode->constraint);
        $question->dataCode = NullableString($xmlNode->data_code);
        $question->type = NullableString($xmlNode->type);
        if (in_array($question->type, self::OPTIONS_TYPES)) {
            $question->optionId = NullableString($xmlNode->value);
        } else {
            $question->value = NullableString($xmlNode->value);
        }
        $question->valueDescription = NullableString($xmlNode->value_description);
        if ($xmlNode->options) {
            $question->options = [];
            foreach ($xmlNode->options->option as $opt) {
                $question->options[] = APIQuestionOption::parseXML($opt);
            }
        }

        $question->modified = false;
        return $question;
    }

    /**
     * Creates a clone of the question.
     * The cloned question will have a NULL ID and value
     */
    public function __clone() {
        $this->id = null;
        $this->value = null;
        $this->optionId = null;
        $this->valueDescription = null;
        $this->modified = true;

        if (!empty($this->options)) {
            $this->options = array_map(function ($o) {
                /* @var APIQuestionOption $o */
                return (clone $o);
            }, $this->options);
        }
    }

    /*
     * **********************************
     * GETTERS
     * **********************************
     */

    /**
     *
     * @return string
     */
    public function getId() {
        return $this->id;
    }

    /**
     *
     * @return string
     */
    public function getItemCode() {
        return $this->itemCode;
    }

    /**
     *
     * @return string
     */
    public function getQuestionTemplateId() {
        return $this->questionTemplateId;
    }

    /**
     *
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    /**
     *
     * @return string
     */
    public function getUnit() {
        return $this->unit;
    }

    /**
     *
     * @return int
     */
    public function getOrder() {
        return $this->order;
    }

    /**
     * If the Question belongs to an array, this function returns the reference of the array.
     * Otherwise returns null
     *
     * @return int
     */
    public function getArrayRef() {
        return $this->arrayRef;
    }

    /**
     *
     * @return int
     */
    public function getRow() {
        return $this->row;
    }

    /**
     *
     * @return int
     */
    public function getColumn() {
        return $this->column;
    }

    /**
     *
     * @return int
     */
    public function getDecimals() {
        return $this->decimals;
    }

    /**
     *
     * @return boolean
     */
    public function getMandatory() {
        return $this->mandatory;
    }

    /**
     *
     * @return string
     */
    public function getDescription() {
        return $this->description;
    }

    /**
     *
     * @return string
     */
    public function getDescriptionOnEdit() {
        return $this->descriptionOnEdit;
    }

    /**
     *
     * @return string
     */
    public function getConstraint() {
        return $this->constraint;
    }

    /**
     *
     * @return string
     */
    public function getDataCode() {
        return $this->dataCode;
    }

    /**
     *
     * @return string
     */
    public function getType() {
        return $this->type;
    }

    /**
     * Gets the answer of the question.
     * When it is a multiptions question, the value returned is the ID of the selected option.
     * Alternatively you can use the 2 following functions to request specifically whether you want to get the value or the optionId:
     * <ul>
     * <li>getValue()</li>
     * <li>getOptionId()</li>
     * </ul>
     *
     * @return string
     */
    public function getAnswer() {
        if (in_array($this->getType(), self::OPTIONS_TYPES)) {
            return $this->optionId;
        } else {
            return $this->value;
        }
    }

    /**
     * Returns the value assigned to the question.
     * If the question is a multianswer question (e.g. RADIO), the value returned is the ID of the selected option.<br>
     * In multianswer questions it is possible also to use the alternative functions to request specifically whether you want to get the value or the
     * optionId:
     * <ul>
     * <li>getValue()</li>
     * <li>getOptionId()</li>
     * </ul>
     *
     * @return string
     */
    public function getValue() {
        if (in_array($this->getType(), self::OPTIONS_TYPES)) {
            return $this->optionId;
        }
        return $this->value;
    }

    /**
     *
     * @return string
     */
    public function getOptionId() {
        return $this->optionId;
    }

    /**
     *
     * @return string
     */
    public function getOptionValue() {
        return $this->value;
    }

    /**
     *
     * @return string
     */
    public function getValueDescription() {
        return $this->valueDescription;
    }

    /**
     *
     * @return APIQuestionOption[]
     */
    public function getOptions() {
        return $this->options ?? [];
    }

    /*
     * **********************************
     * SETTERS
     * **********************************
     */

    /**
     * Sets the ITEM CODE of the question
     *
     * @param string $value
     */
    public function setItemCode($value) {
        $this->itemCode = $value;
    }

    /**
     * Sets the Reference of the array to which this question belongs
     *
     * @param string $value
     */
    public function setArrayRef($value) {
        $this->arrayRef = $value;
    }

    /**
     * Sets the answer of the question.
     * When it is a multiptions question, the value expected should be the ID of the selected option.
     * Alternatively you can use the 2 following functions to indicate specifically whether you want to set the value or the optionId:
     * <ul>
     * <li>setValue()</li>
     * <li>setOptionId()</li>
     * </ul>
     *
     * @param string $value
     */
    public function setAnswer($value) {
        if ($value !== null && !is_scalar($value)) {
            throw new Exception('The answer of a question must be a scalar value');
        }
        if (in_array($this->getType(), self::OPTIONS_TYPES)) {
            $this->modified = ($this->optionId !== $value);
            $this->optionId = $value;
        } else {
            $this->modified = ($this->value !== $value);
            $this->value = $value;
        }
    }

    /**
     * Sets the answer of a Multioptions question (checkbox, radios).
     * The answer can be set by either:
     * <ul>
     * <li>The ID of the selected option</li>
     * <li>The value of the selected option</li>
     * </ul>
     *
     * Note that when $optionId (The ID of the option), is not null, $optionValue will be ignored by the API,
     *
     * @param string $optionId
     * @param string $optionValue
     */
    public function setOptionAnswer($optionId, $optionValue = null) {
        if ($optionId !== null && !is_scalar($optionId)) {
            throw new Exception('The answer of a question must be a scalar value');
        }

        if (in_array($this->getType(), self::OPTIONS_TYPES)) {
            $this->modified = ($this->optionId !== $optionId);
            $this->optionId = $optionId;
        }

        if ($optionValue !== null && !is_scalar($optionValue)) {
            throw new APIException('The answer of a question must be a scalar value');
        }

        $this->modified = $this->modified || ($this->value !== $optionValue);
        $this->value = $optionValue;
    }

    /**
     * Sets the row of the question (Used in questions that belong to an array)
     *
     * @param string $value
     */
    public function setRow($value) {
        $this->row = $value;
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */

    /**
     * Returns whether the answer of the question has been modified.
     *
     * @return boolean
     */
    public function isModified() {
        return $this->modified;
    }

    /**
     * Marks the question as not modified.
     *
     * @return boolean
     */
    public function resetModified() {
        $this->modified = false;
    }

    public function save() {
        if (!$this->modified) {
            return;
        }
        $this->api->form_set_answer($this->formId, $this->id, $this->value, $this->optionId);
        $this->modified = false;
    }

    /**
     * Returns whether the question belongs to an empty row in an array.
     *
     * @return boolean
     */
    public function isEmptyRow() {
        return $this->emptyRow;
    }

    /**
     *
     * @param XMLHelper $xml
     * @param SimpleXMLElement $parentNode
     * @return SimpleXMLElement
     */
    public function toXML($xml, $parentNode, $plain = false) {
        if ($parentNode === null) {
            $parentNode = $xml->rootNode;
        }

        $id = $this->getId() ?? $this->getItemCode();

        if ($plain) {
            $xml->createChildNode($parentNode, "question_id", $id);
        } else {
            if ($this->getRow()) {
                // Is a question in an array
                $arrayColumnId = $this->getItemCode() ? $this->getItemCode() : $this->getQuestionTemplateId();

                $xml->createChildNode($parentNode, "question_id", $arrayColumnId);
                $xml->createChildNode($parentNode, "column", $this->getColumn());
            } else {
                $xml->createChildNode($parentNode, "question_id", $id);
            }
        }

        if (in_array($this->getType(), self::OPTIONS_TYPES)) {
            $xml->createChildNode($parentNode, "value", $this->getOptionValue());
            $xml->createChildNode($parentNode, "option_id", $this->getOptionId());
        } elseif ($this->getType() == APIQuestionTypes::CODE) {
            $valObj = new stdClass();
            $valObj->code = $this->getValue();
            $xml->createChildNode($parentNode, "value", json_encode($valObj));
            $xml->createChildNode($parentNode, "option_id", '');
        } else {
            $xml->createChildNode($parentNode, "value", $this->getValue());
            $xml->createChildNode($parentNode, "option_id", '');
        }

        return $parentNode;
    }
}