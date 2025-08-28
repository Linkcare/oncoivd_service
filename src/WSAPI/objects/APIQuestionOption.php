<?php

class APIQuestionOption {
    private $id;
    private $description;
    private $value;
    private $image;

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APITeam
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $team = new APIQuestionOption();
        $team->id = NullableString($xmlNode->option_id);
        $team->description = NullableString($xmlNode->description);
        $team->value = NullableString($xmlNode->value);
        $team->image = NullableString($xmlNode->image_info);
        return $team;
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
    public function getDescription() {
        return $this->description;
    }

    /**
     *
     * @return string
     */
    public function getValue() {
        return $this->value;
    }

    /**
     *
     * @return string
     */
    public function getImage() {
        return $this->image;
    }
}