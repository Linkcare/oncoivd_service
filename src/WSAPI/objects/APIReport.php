<?php

class APIReport {
    private $id;
    private $code;
    private $name;
    private $description;
    private $parentId;
    private $status;
    private $url;
    private $urlHtml;
    /** @var LinkcareSoapAPI $api */
    private $api;

    public function __construct() {
        $this->api = LinkcareSoapAPI::getInstance();
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APIReport
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $report = new APIReport();
        $report->id = NullableString($xmlNode->ref);
        if ($xmlNode->code) {
            $report->code = NullableString($xmlNode->code);
        } else {
            $report->code = NullableString($xmlNode->report_code);
        }

        $report->name = NullableString($xmlNode->name);
        $report->description = NullableString($xmlNode->description);
        $report->parentId = NullableInt($xmlNode->parent_id);
        $report->status = NullableString($xmlNode->status);
        $report->url = NullableString($xmlNode->url);
        $report->urlHtml = NullableString($xmlNode->url_html);

        return $report;
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
    public function getReportCode() {
        return $this->code;
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
    public function getUrl() {
        return $this->url;
    }

    /**
     *
     * @return string
     */
    public function getUrlHtml() {
        return $this->urlHtml;
    }

    /**
     *
     * @return string
     */
    public function getStatus() {
        return $this->status;
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */
}