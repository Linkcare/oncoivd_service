<?php

/**
 * Definition of the Database data models
 */
class DbDataModels {

    /** @var string */
    /**
     * Generates the structure of the data schema
     *
     * @param string $name Name assigned to the new DB schema
     * @return DbSchemaDefinition
     */
    static public function shipmentsModel($name) {
        $tables = [];
        $columns = [];
        $indexes = [];
        $fks = [];

        // Locations table (Teams)
        $columns = [];
        $indexes = null;
        $columns[] = new DbColumnDefinition('ID_LOCATION', DbDataTypes::VARCHAR, 64, null, false);
        $columns[] = new DbColumnDefinition('CODE', DbDataTypes::VARCHAR, 64, null, false);
        $columns[] = new DbColumnDefinition('NAME', DbDataTypes::VARCHAR, 128, null, false);
        $columns[] = new DbColumnDefinition('IS_LAB', DbDataTypes::TINYINT, null, null, false);
        $columns[] = new DbColumnDefinition('IS_CLINICAL_SITE', DbDataTypes::TINYINT, null, null, false);
        $tables[] = new DbTableDefinition('LOCATIONS', $columns, 'ID_LOCATION', $indexes);

        // Shipments table
        $columns = [];
        $columns[] = new DbColumnDefinition('ID_SHIPMENT', DbDataTypes::BIGINT, null, null, false, null, true);
        $columns[] = new DbColumnDefinition('SHIPMENT_REF', DbDataTypes::VARCHAR, 64, null, false);
        $columns[] = new DbColumnDefinition('ID_STATUS', DbDataTypes::VARCHAR, 32, null, false);
        $columns[] = new DbColumnDefinition('ID_SENT_FROM', DbDataTypes::VARCHAR, 32, null, false);
        $columns[] = new DbColumnDefinition('ID_SENT_TO', DbDataTypes::VARCHAR, 32);
        $columns[] = new DbColumnDefinition('SHIPMENT_DATE', DbDataTypes::DATETIME);
        $columns[] = new DbColumnDefinition('ID_SENDER', DbDataTypes::VARCHAR, 32);
        $columns[] = new DbColumnDefinition('SENDER', DbDataTypes::VARCHAR, 128);
        $columns[] = new DbColumnDefinition('RECEPTION_DATE', DbDataTypes::DATETIME);
        $columns[] = new DbColumnDefinition('ID_RECEIVER', DbDataTypes::VARCHAR, 32);
        $columns[] = new DbColumnDefinition('RECEIVER', DbDataTypes::VARCHAR, 128);
        $columns[] = new DbColumnDefinition('ID_RECEPTION_STATUS', DbDataTypes::VARCHAR, 32);
        $columns[] = new DbColumnDefinition('RECEPTION_COMMENTS', DbDataTypes::TEXT);
        $indexes[] = new DbIndexDefinition('SHIPMENT_REF_IDX', ['SHIPMENT_REF'], true);
        $tables[] = new DbTableDefinition('SHIPMENTS', $columns, 'ID_SHIPMENT', $indexes, true);

        // Aliquots table
        $columns = [];
        $indexes = null;
        $columns[] = new DbColumnDefinition('ID_ALIQUOT', DbDataTypes::VARCHAR, 64, null, false);
        $columns[] = new DbColumnDefinition('ID_PATIENT', DbDataTypes::BIGINT, null, null, false);
        $columns[] = new DbColumnDefinition('PATIENT_REF', DbDataTypes::VARCHAR, 32, null, false);
        $columns[] = new DbColumnDefinition('SAMPLE_TYPE', DbDataTypes::VARCHAR, 32, null, false);
        $columns[] = new DbColumnDefinition('ID_LOCATION', DbDataTypes::VARCHAR, 32, null, false);
        $columns[] = new DbColumnDefinition('ID_STATUS', DbDataTypes::VARCHAR, 32, null, false);
        $columns[] = new DbColumnDefinition('ID_ALIQUOT_CONDITION', DbDataTypes::VARCHAR, 64);
        $columns[] = new DbColumnDefinition('ID_TASK', DbDataTypes::BIGINT, 32, null, false);
        $columns[] = new DbColumnDefinition('ID_SHIPMENT', DbDataTypes::BIGINT);
        $columns[] = new DbColumnDefinition('ALIQUOT_CREATED', DbDataTypes::DATETIME, null, null, false);
        $columns[] = new DbColumnDefinition('ALIQUOT_UPDATED', DbDataTypes::DATETIME, null, null, false);
        $columns[] = new DbColumnDefinition('RECORD_TIMESTAMP', DbDataTypes::DATETIME, null, null, false);
        $indexes[] = new DbIndexDefinition('PATIENT_ID_IDX', ['ID_PATIENT']);
        $indexes[] = new DbIndexDefinition('SAMPLE_TYPE_IDX', ['SAMPLE_TYPE']);
        $indexes[] = new DbIndexDefinition('LOCATION_ID_IDX', ['ID_LOCATION']);
        $indexes[] = new DbIndexDefinition('STATUS_ID_IDX', ['ID_STATUS']);
        $tables[] = new DbTableDefinition('ALIQUOTS', $columns, 'ID_ALIQUOT', $indexes);

        // Aliquots history tracking table
        $columns = [];
        $indexes = null;
        $columns[] = new DbColumnDefinition('ID_HISTORY', DbDataTypes::BIGINT, null, null, false, null, true);
        $columns[] = new DbColumnDefinition('ID_ALIQUOT', DbDataTypes::VARCHAR, 64, null, false);
        $columns[] = new DbColumnDefinition('ID_TASK', DbDataTypes::BIGINT, null, null, false);
        $columns[] = new DbColumnDefinition('ID_LOCATION', DbDataTypes::VARCHAR, 32, null, false);
        $columns[] = new DbColumnDefinition('ID_STATUS', DbDataTypes::VARCHAR, 32, null, false);
        $columns[] = new DbColumnDefinition('ID_ALIQUOT_CONDITION', DbDataTypes::VARCHAR, 64);
        $columns[] = new DbColumnDefinition('ALIQUOT_UPDATED', DbDataTypes::DATETIME, null, null, false);
        $columns[] = new DbColumnDefinition('ID_SHIPMENT', DbDataTypes::BIGINT);
        $columns[] = new DbColumnDefinition('RECORD_TIMESTAMP', DbDataTypes::DATETIME, null, null, false);
        $indexes[] = new DbIndexDefinition('ALIQUOT_ID_HISTORY_IDX', ['ID_ALIQUOT']);
        $tables[] = new DbTableDefinition('ALIQUOTS_HISTORY', $columns, 'ID_HISTORY', $indexes, true);

        // Aliquots included in shipments
        $columns = [];
        $indexes = null;
        $columns[] = new DbColumnDefinition('ID_SHIPMENT', DbDataTypes::BIGINT, null, null, false);
        $columns[] = new DbColumnDefinition('ID_ALIQUOT', DbDataTypes::VARCHAR, 64, null, false);
        $columns[] = new DbColumnDefinition('ID_ALIQUOT_CONDITION', DbDataTypes::VARCHAR, 64);
        $columns[] = new DbColumnDefinition('ID_SHIPMENT_TASK', DbDataTypes::BIGINT);
        $columns[] = new DbColumnDefinition('ID_RECEPTION_TASK', DbDataTypes::BIGINT);
        $indexes[] = new DbIndexDefinition('ID_ALIQUOT_IDX', ['ID_ALIQUOT']);
        $tables[] = new DbTableDefinition('SHIPPED_ALIQUOTS', $columns, ['ID_SHIPMENT', 'ID_ALIQUOT'], $indexes);
        $fks[] = new DbFKDefinition('SHIPMENT_ALIQUOTS_FK1', 'SHIPPED_ALIQUOTS', ['ID_SHIPMENT'], 'SHIPMENTS', ['ID_SHIPMENT']);
        $fks[] = new DbFKDefinition('SHIPMENT_ALIQUOTS_FK2', 'SHIPPED_ALIQUOTS', ['ID_ALIQUOT'], 'ALIQUOTS', ['ID_ALIQUOT']);

        $db = new DbSchemaDefinition($name, $tables, $fks);
        return $db;
    }
}