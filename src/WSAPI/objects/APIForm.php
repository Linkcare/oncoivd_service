<?php

class APIForm {
    const STATUS_OPEN = 'OPEN';
    const STATUS_CLOSED = 'CLOSED';
    const STATUS_CANCELLED = 'CANCELLED';
    private $id;
    private $formCode;
    private $name;
    private $description;
    private $parentId;
    private $date;
    private $status;
    /** @var APIQuestion[] $questions */
    private $questions = null;
    /** @var LinkcareSoapAPI $api */
    private $api;

    public function __construct() {
        $this->api = LinkcareSoapAPI::getInstance();
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @param APIForm $form (optionl) If provided, the data will be loaded into this object instead of creating a new one
     * @return APIForm
     */
    static public function parseXML($xmlNode, $form = null) {
        if (!$xmlNode) {
            return null;
        }

        if (!$form) {
            $form = new APIForm();
        }

        $form->id = NullableString($xmlNode->ref);
        if ($xmlNode->code) {
            $form->formCode = NullableString((string) $xmlNode->code);
        } else {
            $form->formCode = NullableString((string) $xmlNode->form_code);
        }

        // form_get_summary retuns the information of the FORM into th subnode "data"
        $formInfoNode = $xmlNode->data ? $xmlNode->data : $xmlNode;
        if ($formInfoNode->short_name) {
            // form_get() returns the name in node "short_name")
            $form->name = NullableString($formInfoNode->short_name);
        } else {
            $form->name = NullableString($formInfoNode->name);
        }
        $form->description = NullableString($formInfoNode->description);
        $form->parentId = NullableInt($formInfoNode->parent_id);
        $form->date = NullableString($formInfoNode->date);
        $form->status = NullableString($formInfoNode->status);

        $questions = [];
        if ($formInfoNode->questions) {
            foreach ($formInfoNode->questions->question as $qNode) {
                $questions[] = APIQuestion::parseXML($qNode, $form->id);
            }
            $form->questions = array_filter($questions);
        }
        return $form;
    }

    /*
     * **********************************
     * GETTERS
     * **********************************
     */
    /**
     *
     * @return int
     */
    public function getId() {
        return $this->id;
    }

    /**
     *
     * @return string
     */
    public function getFormCode() {
        return $this->formCode;
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
    public function getDescription() {
        return $this->description;
    }

    /**
     *
     * @return int
     */
    public function getParentId() {
        return $this->parentId;
    }

    /**
     *
     * @return string
     */
    public function getDate() {
        return $this->date;
    }

    /**
     *
     * @return string
     */
    public function getStatus() {
        return $this->status;
    }

    /**
     *
     * @return APIQuestion[]
     */
    public function getQuestions() {
        if ($this->questions === null) {
            $this->questions = [];
            $f = $this->api->form_get_summary($this->id, true);
            $this->questions = $f->getQuestions();
        }

        return $this->questions ?? [];
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */
    public function refresh() {
        $requestQuestions = ($this->questions !== null);
        $this->api->form_get_summary($this->id, $requestQuestions, false, $this);
    }

    /**
     *
     * @return boolean
     */
    public function isClosed() {
        return $this->status == self::STATUS_CLOSED;
    }

    /**
     *
     * @return boolean
     */
    public function isOpen() {
        return $this->status == self::STATUS_OPEN;
    }

    /**
     *
     * @return boolean
     */
    public function isCancelled() {
        return $this->status == self::STATUS_CANCELLED;
    }

    /**
     * Opens a FORM.
     * The function will do nothing if the FORM is already open
     */
    public function open() {
        if ($this->isOpen()) {
            return;
        }
        $this->api->form_open($this->id);
    }

    /**
     * Closes a FORM.
     * The function will do nothing if the FORM is not open
     */
    public function close() {
        if (!$this->isOpen()) {
            return;
        }
        $this->api->form_close($this->id);
    }

    /**
     *
     * @param APIQuestion $q
     */
    public function addQuestion($q) {
        $this->questions[] = $q;
    }

    /**
     * Saves the answers of the FORM.
     * To improve performance, only the modified questions are saved.
     */
    public function updateAnswers() {
        if (empty($this->questions)) {
            return;
        }

        /** @var APIQuestion[] $questionsToUpdate */
        $questionsToUpdate = array_filter($this->questions, function ($q) {
            return $q->isModified();
        });

        $this->api->form_update_all_answers($this->id, $questionsToUpdate, false);
        foreach ($questionsToUpdate as $q) {
            $q->resetModified(false);
        }
    }

    /**
     * Searches the QUESTION with the $questionId indicated
     *
     * @param int $questionId
     * @return APIQuestion
     */
    public function findQuestion($questionId) {
        foreach ($this->getQuestions() as $q) {
            if ($q->getQuestionTemplateId() == $questionId || $q->getItemCode() == $questionId) {
                return $q;
            }
        }

        return null;
    }

    /**
     * Searches the array QUESTION at row $row with the $questionId indicated
     *
     * @param int $row
     * @param int $questionId
     * @param boolean $create Create the question in the array if it does not exist?
     * @return APIQuestion
     */
    public function findArrayQuestion($arrayRef, $row, $questionId, $create = false) {
        $referenceQuestion = null;
        $q = null;
        foreach ($this->getQuestions() as $q) {
            if ($arrayRef != $q->getArrayRef()) {
                continue;
            }
            if (!$create && $q->isEmptyRow()) {
                // The first question of and empty row always exists, but it is not a valid question (unless we are creating a new row)
                break;
            }
            if ($q->getQuestionTemplateId() == $questionId || $q->getItemCode() == $questionId) {
                if ($q->getRow() == $row) {
                    return $q;
                }
                if ($q->getRow() == 1) {
                    $referenceQuestion = $q;
                }
            }
        }
        if ($create) {
            /*
             * If we arrive here, it means that the specifued row does not exist yet. We have to add a new row andd add the question (using as
             * reference
             * the question with the same ID in the first row)
             */
            if ($referenceQuestion) {
                $q = clone $referenceQuestion;
            } else {
                $q = new APIQuestion();
                $q->setItemCode($questionId);
                $q->setArrayRef($arrayRef);
            }

            $q->setRow($row);
            return $q;
        }

        return null;
    }

    /**
     * Returns an array with the questions of the array QUESTION $arrayRef
     * If $arrayRef is not an ARRAY ITEM, the function will return null.<br>
     * The array returned is an associative bidimensional array where the keys of each dimension are the row number and the ITEM CODE of the question
     * respectively.
     *
     * @param int|string $arrayRef
     * @return APIQuestion[][]
     */
    public function getArrayQuestions($arrayRef) {
        // Fetch all the rows of the array
        // Find the array of aliquots
        $arrayQuestion = $this->findQuestion($arrayRef);
        if (!$arrayQuestion) {
            return null;
        }

        $array = [];
        foreach ($this->getQuestions() as $q) {
            if ($arrayQuestion->getId() != $q->getArrayRef()) {
                continue;
            }
            if ($q->isEmptyRow()) {
                // The first question of and empty row always exists, but it is not a valid question
                break;
            }

            $array[$q->getRow()][$q->getItemCode()] = $q;
        }

        return $array;
    }
}