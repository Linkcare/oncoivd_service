<?php

class XMLHelper {
    /* @var DomDocument $xmlDoc */
    public $xmlDoc;
    /* @var DomElement $rootNode */
    public $rootNode;

    public function __construct($rootNodeName) {
        $this->xmlDoc = new DomDocument('1.0', 'utf-8');
        $root = $this->xmlDoc->createElement($rootNodeName);
        $this->rootNode = $this->xmlDoc->appendChild($root);
    }

    /**
     * Creates a new child node into the specified parent node
     *
     * @param DOMElement $parentNode
     * @param string $childNodeName
     * @param string $value
     * @return DOMElement
     */
    public function createChildNode($parentNode, $childNodeName, $value = null) {
        if (!$parentNode) {
            $parentNode = $this->rootNode;
        }
        
        $childNode = $this->xmlDoc->createElement($childNodeName);
        $childNode = $parentNode->appendChild($childNode);
        if ($value || is_numeric($value)) {
            $textContent = $this->xmlDoc->createTextNode($value);
            $childNode->appendChild($textContent);
        }
        return $childNode;
    }

    /**
     * Appends a childNode to an existing parent node
     *
     * @param DOMElement $parentNode
     * @param DOMElement $childNode
     */
    public function appendNode($parentNode, $childNode) {
        if ($childNode) {
            $parentNode->appendChild($childNode);
        }
    }

    /**
     *
     * @return string
     */
    public function toString() {
        return $this->xmlDoc->saveXML();
    }
}