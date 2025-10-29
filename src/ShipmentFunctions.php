<?php

function shipment_locations($parameters) {
    $sql = "SELECT * FROM LOCATIONS WHERE IS_LAB=1";
    $rst = Database::getInstance()->executeBindQuery($sql);

    $locations = [];
    while ($rst->Next()) {
        $location = new stdClass();
        $location->id = $rst->GetField('ID_LOCATION');
        $location->code = $rst->GetField('CODE');
        $location->name = $rst->GetField('NAME');
        $locations[] = $location;
    }

    return new ServiceResponse($locations, null);
}

/**
 * Loads the list of all shipments that have been sent from or to the TEAM of the active user's session.
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function shipment_list($parameters) {
    $api = LinkcareSoapAPI::getInstance();

    $activeTeamId = loadParam($parameters, 'activeLocation');
    $page = loadParam($parameters, 'page');
    $pageSize = loadParam($parameters, 'pageSize');
    $filters = loadParam($parameters, 'filters');
    cleanFilters($filters);

    $arrVariables[':activeTeamId'] = $activeTeamId ? $activeTeamId : $api->getSession()->getTeamId();
    $arrVariables[':statusPreparing'] = ShipmentStatus::PREPARING;

    $filterConditions = [];
    if ($filters && property_exists($filters, 'ref') && $filters->ref) {
        $likeExpr = Database::getInstance()->fnConcat("'%'", ':shipmentRef', "'%'");
        $filterConditions[] = "SHIPMENT_REF LIKE $likeExpr";
        $arrVariables[':shipmentRef'] = $filters->ref;
    }
    if ($filters && property_exists($filters, 'sentFrom') && $filters->sentFrom) {
        $filterConditions[] = "ID_SENT_FROM=:sentFromId";
        $arrVariables[':sentFromId'] = $filters->sentFrom;
    }
    if ($filters && property_exists($filters, 'sentTo') && $filters->sentTo) {
        $filterConditions[] = "ID_SENT_TO=:sentToId";
        $arrVariables[':sentToId'] = $filters->sentTo;
    }

    $filterSql = empty($filterConditions) ? "" : " AND " . implode(' AND ', $filterConditions);

    $queryColumns = "s.*, l1.NAME as SENT_FROM, l2.NAME as SENT_TO";
    $queryFromClause = "FROM SHIPMENTS s
                LEFT JOIN LOCATIONS l1 ON s.ID_SENT_FROM = l1.ID_LOCATION
                LEFT JOIN LOCATIONS l2 ON s.ID_SENT_TO = l2.ID_LOCATION
            WHERE (s.ID_SENT_FROM=:activeTeamId OR (s.ID_SENT_TO=:activeTeamId AND s.ID_STATUS <> :statusPreparing)) $filterSql";
    list($rst, $totalRows) = fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize, $page);

    $shipmentList = [];
    while ($rst->Next()) {
        $shipmentList[] = Shipment::fromDBRecord($rst);
    }

    $data = new stdClass();
    $timezone = $api->getSession()->getTimezone();
    $data->rows = array_map(function ($shipment) use ($timezone) {
        /** @var Shipment $shipment */
        return $shipment->toJSON($timezone);
    }, $shipmentList);
    $data->total_count = $totalRows;

    return new ServiceResponse($data, null);
}

/**
 * Creates a new shipment
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function shipment_create($parameters) {
    $shipmentRef = loadParam($parameters, 'ref');
    $sentFromId = loadParam($parameters, 'sentFromId');
    $sentToId = loadParam($parameters, 'sentToId');
    $senderId = loadParam($parameters, 'senderId');
    $senderName = loadParam($parameters, 'sender');
    if (!$sentFromId) {
        return new ServiceResponse(null, "It is mandatory to provide the location from which the shipment is sent");
    }
    if ($sentFromId == $sentToId) {
        return new ServiceResponse(null, "Shipment cannot be sent to the same location");
    }

    $arrVariables[':shipmentRef'] = $shipmentRef;
    $arrVariables[':status'] = ShipmentStatus::PREPARING;
    $arrVariables[':sentFromId'] = $sentFromId;
    $arrVariables[':sentToId'] = $sentToId;
    $arrVariables[':senderId'] = $senderId;
    $arrVariables[':senderName'] = $senderName;

    $sql = "INSERT INTO SHIPMENTS (SHIPMENT_REF, ID_STATUS, ID_SENT_FROM, ID_SENT_TO, ID_SENDER, SENDER) VALUES (:shipmentRef, :status, :sentFromId, :sentToId, :senderId, :senderName)";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);

    $data = new stdClass();
    Database::getInstance()->getLastInsertedId($data->id);
    return new ServiceResponse($data, null);
}

/**
 * Updates the information of an existing shipment (that has not been shipped yet)
 * Only the properties relevant for the shipment are updated
 *
 * @param array $parameters
 * @return ServiceResponse
 */
function shipment_update($parameters) {
    $id = loadParam($parameters, 'id');
    $shipment = Shipment::exists($id);
    if (!$shipment) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID " . $id . " not found");
    }

    $api = LinkcareSoapAPI::getInstance();
    $timezone = $api->getSession()->getTimezone();

    if ($shipment->statusId == ShipmentStatus::PREPARING) {
        preserveProperties($parameters, ['id', 'ref', 'sentFromId', 'sentToId', 'sendDate']);
    } elseif ($shipment->statusId == ShipmentStatus::RECEIVING) {
        preserveProperties($parameters, ['id', 'receiverId', 'receptionDate', 'receptionStatusId', 'receptionComments']);
    } else {
        throw new ServiceException(ErrorCodes::INVALID_STATUS, "Shipment with ID $id cannot be updated because it is not in a status that allows updates");
    }
    // Copy the parameters received tracking the modified ones
    $shipment->trackedCopy($parameters, $timezone);
    $shipment->updateModified();

    return new ServiceResponse($shipment->id, null);
}

/**
 * Mark a shipment as "Sent"
 *
 * @param stdClass $parameters
 */
function shipment_send($parameters) {
    $api = LinkcareSoapAPI::getInstance();

    $shipmentId = loadParam($parameters, 'id');
    $shipmentDate = loadParam($parameters, 'sendDate', DateHelper::currentDate());
    $shipment = Shipment::exists($shipmentId);
    if (!$shipment) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment $shipmentId not found");
    }

    $sql = "SELECT COUNT(*) AS TOTAL_ALIQUOTS FROM ALIQUOTS WHERE ID_SHIPMENT=:id";
    $rst = Database::getInstance()->ExecuteBindQuery($sql, $shipmentId);
    if ($rst->Next()) {
        $numAliquots = $rst->GetField('TOTAL_ALIQUOTS');
    }
    if (!$numAliquots) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "A shipment can't be sent if it doesn't contain aliquots");
    }

    // Mark the shipment as "Shipped" and indicate the datetime
    $parameters->statusId = ShipmentStatus::SHIPPED;
    if (!DateHelper::isValidDate($shipmentDate)) {
        throw new ServiceException(ErrorCodes::INVALID_DATA_FORMAT, "Invalid shipment date: " . $parameters->sendDate);
    }

    if ($senderId = loadParam($parameters, 'id') ?? $shipment->senderId) {
        try {
            $user = $api->user_get($senderId);
            $parameters->sender = $user->getFullName();
        } catch (Exception $e) {}
    }

    $shipment->trackedCopy($parameters);
    if (!$shipment->ref) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "Shipment reference was not informed but is mandatory for sending a shipment");
    }
    if (!$shipment->senderId) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "Sender Id was not informed but is mandatory for sending a shipment");
    }
    if (!$shipment->sentToId) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "Destination was not informed but is mandatory for sending a shipment");
    }

    // Update the last modification date of the aliquots and generate a tracking record
    $aliquots = $shipment->getAliquots();
    foreach ($aliquots as $aliquot) {
        $aliquot->statusId = AliquotStatus::IN_TRANSIT;
        $aliquot->lastUpdate = $shipment->sendDate;
    }

    $arrVariables = [':shipmentId' => $shipmentId];
    $sql = "SELECT * FROM ALIQUOTS WHERE ID_SHIPMENT=:shipmentId";
    $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

    $aliquotList = [];
    while ($rst->Next()) {
        $colNames = $rst->getColumnNames();
        $aliquot = [];
        foreach ($colNames as $colName) {
            $aliquot[$colName] = $rst->GetField($colName);
        }
        $aliquot['UPDATED'] = $shipment->sendDate;
        $aliquotList[] = $aliquot;
    }

    $shipment->updateModified();
    trackAliquots($aliquotList, AliquotAuditActions::SHIPPED);

    return new ServiceResponse($shipment->d, null);
}

/**
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function shipment_start_reception($parameters) {
    $shipmentId = loadParam($parameters, 'id');
    $shipment = Shipment::exists($shipmentId);
    if (!$shipment) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment $shipmentId not found");
    }

    // Mark the shipment as "Receiving"
    $modify = new stdClass();
    $modify->statusId = ShipmentStatus::RECEIVING;

    $shipment->trackedCopy($modify);

    $shipment->updateModified();

    return new ServiceResponse($shipment->d, null);
}

/**
 * Mark a shipment as "Received"
 *
 * @param array $parameters
 */
function shipment_finish_reception($parameters) {
    $api = LinkcareSoapAPI::getInstance();

    preserveProperties($parameters, ['id', 'receptionDate', 'receiverId', 'receptionStatusId', 'receptionComments']);
    $parameters->statusId = ShipmentStatus::RECEIVED;

    $shipmentId = loadParam($parameters, 'id');

    $shipment = Shipment::exists($shipmentId);
    if (!$shipment) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment $shipmentId not found");
    }

    try {
        $user = $api->user_get($parameters->receiverId);
        $parameters->receiver = $user->getFullName();
    } catch (Exception $e) {
        $parameters->receiver = $parameters->receiverId;
    }
    // Mark the shipment as "Received" and indicate the datetime
    $api = LinkcareSoapAPI::getInstance();
    $timezone = $api->getSession()->getTimezone();

    error_log("PARAMETERS: " . json_encode($parameters));
    $shipment->trackedCopy($parameters, $timezone);

    if (!$shipment->receptionDate) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "Reception datetime was not informed but is mandatory for receiving a shipment");
    }
    if (!$shipment->receiverId) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "Receiver Id was not informed but is mandatory for receiving a shipment");
    }
    if (!$shipment->receptionStatusId) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "Reception status was not informed but is mandatory for receiving a shipment");
    }

    $shipment->updateModified();

    // Update the new location, last modification date and the rejection reason (if any) of the aliquots
    $sqls = [];
    $arrVariables = [':shipmentId' => $shipmentId, ':updated' => $shipment->receptionDate, ':rejectedStatus' => AliquotStatus::REJECTED,
            ':okStatus' => AliquotStatus::AVAILABLE, ':locationId' => $shipment->sentToId];
    $sqls[] = "UPDATE ALIQUOTS a, SHIPPED_ALIQUOTS sa
            SET a.ALIQUOT_UPDATED=:updated, a.ID_STATUS=:rejectedStatus, a.ID_ALIQUOT_CONDITION=sa.ID_ALIQUOT_CONDITION,
                a.ID_LOCATION=:locationId, a.ID_SHIPMENT=NULL
            WHERE
                sa.ID_SHIPMENT=:shipmentId
            	AND a.ID_ALIQUOT = sa.ID_ALIQUOT
            	AND sa.ID_ALIQUOT_CONDITION IS NOT NULL AND sa.ID_ALIQUOT_CONDITION <> ''";
    $sqls[] = "UPDATE ALIQUOTS a, SHIPPED_ALIQUOTS sa
            SET a.ALIQUOT_UPDATED=:updated, a.ID_STATUS=:okStatus, a.ID_ALIQUOT_CONDITION=NULL,
                a.ID_LOCATION=:locationId, a.ID_SHIPMENT=NULL
            WHERE
                sa.ID_SHIPMENT=:shipmentId
            	AND a.ID_ALIQUOT = sa.ID_ALIQUOT
            	AND (sa.ID_ALIQUOT_CONDITION IS NULL OR sa.ID_ALIQUOT_CONDITION = '')";
    foreach ($sqls as $sql) {
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
    }

    // Generate a tracking record for each aliquot received
    $arrVariables = [':shipmentId' => $shipmentId];
    $sql = "SELECT * FROM ALIQUOTS WHERE ID_ALIQUOT IN (SELECT ID_ALIQUOT FROM SHIPPED_ALIQUOTS WHERE ID_SHIPMENT = :shipmentId)";
    $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

    $aliquotList = [];
    $currentDate = DateHelper::currentDate();
    while ($rst->Next()) {
        $colNames = $rst->getColumnNames();
        $aliquot = [];
        foreach ($colNames as $colName) {
            $aliquot[$colName] = $rst->GetField($colName);
        }
        $aliquot['UPDATED'] = $currentDate;
        $aliquotList[] = $aliquot;
    }

    trackAliquots($aliquotList, AliquotAuditActions::RECEIVED);

    return new ServiceResponse($shipment->d, null);
}

/**
 *
 * @param array $parameters
 */
function shipment_delete($parameters) {
    $shipmentId = loadParam($parameters, 'id');

    $sql = "SELECT * FROM SHIPMENTS s WHERE s.ID_SHIPMENT = :id";
    $rst = Database::getInstance()->ExecuteBindQuery($sql, $shipmentId);
    if (!$rst->Next()) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID: $shipmentId was not found");
    }
    $shipment = Shipment::fromDBRecord($rst);

    if ($shipment->statusId != ShipmentStatus::PREPARING) {
        throw new ServiceException(ErrorCodes::INVALID_STATUS, "Shipment with ID: $shipmentId can't be deleted because it is not in 'Preparing' status");
    }

    $arrVariables = [':id' => $shipmentId, ':aliquotStatus' => AliquotStatus::AVAILABLE];
    $sqls = [];
    $sqls[] = "DELETE FROM SHIPPED_ALIQUOTS WHERE ID_SHIPMENT=:id";
    $sqls[] = "UPDATE ALIQUOTS SET ID_SHIPMENT = NULL, ID_STATUS = :aliquotStatus WHERE ID_SHIPMENT = :id";
    $sqls[] = "DELETE FROM SHIPMENTS WHERE ID_SHIPMENT=:id";
    foreach ($sqls as $sql) {
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
    }

    return new ServiceResponse($shipmentId, null);
}

/**
 * Loads the details of a specific shipment, including its aliquots.
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function shipment_details($parameters) {
    $api = LinkcareSoapAPI::getInstance();
    $shipmentId = loadParam($parameters, 'id');

    $arrVariables = [':shipmentId' => $shipmentId];
    $sql = "SELECT s.*, l1.NAME as SENT_FROM, l2.NAME as SENT_TO
            FROM SHIPMENTS s
                LEFT JOIN LOCATIONS l1 ON s.ID_SENT_FROM = l1.ID_LOCATION
                LEFT JOIN LOCATIONS l2 ON s.ID_SENT_TO = l2.ID_LOCATION
            WHERE s.ID_SHIPMENT = :shipmentId";
    $rst = Database::getInstance()->executeBindQuery($sql, $arrVariables);

    if (!$rst->Next()) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment not found");
    }

    $shipment = Shipment::fromDBRecord($rst);
    $shipment->getAliquots(null, $api->getSession()->getTimezone()); // Force loading the aliquots of the shipment

    return new ServiceResponse($shipment->toJSON($api->getSession()->getTimezone()), null);
}

/**
 * Loads the details of a specific shipment, including its aliquots.
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function shippable_aliquots($parameters) {
    $api = LinkcareSoapAPI::getInstance();
    $locationId = loadParam($parameters, 'locationId');
    $page = loadParam($parameters, 'page');
    $pageSize = loadParam($parameters, 'pageSize');
    $filters = loadParam($parameters, 'filters');
    cleanFilters($filters);

    $arrVariables = [':locationId' => $locationId, ':statusId' => AliquotStatus::AVAILABLE];

    $filterConditions = [];
    if ($filters && property_exists($filters, 'patientRef') && $filters->patientRef) {
        $likeExpr = Database::getInstance()->fnConcat("'%'", ':patientRef', "'%'");
        $filterConditions[] = "a.PATIENT_REF LIKE $likeExpr";
        $arrVariables[':patientRef'] = $filters->patientRef;
    }
    if ($filters && property_exists($filters, 'type') && $filters->type) {
        $likeExpr = Database::getInstance()->fnConcat("'%'", ':sampleType', "'%'");
        $filterConditions[] = "a.SAMPLE_TYPE LIKE $likeExpr";
        $arrVariables[':sampleType'] = $filters->type;
    }

    if ($excludeIds = loadParam($parameters, 'excludeIds')) {
        $excludeIds = explode(',', $excludeIds);
        if (count($excludeIds) > 0) {
            $exclude = DbHelper::bindParamArray('exId', $parameters, $arrVariables);
            $filterConditions[] = "a.ID_ALIQUOT NOT IN ($exclude)";
        }
    }

    $filterSql = empty($filterConditions) ? "" : " AND " . implode(' AND ', $filterConditions);

    $queryColumns = "a.* , l.NAME AS LOCATION_NAME";
    $queryFromClause = "FROM ALIQUOTS a LEFT JOIN LOCATIONS l ON a.ID_LOCATION = l.ID_LOCATION 
                        WHERE a.ID_LOCATION = :locationId AND a.ID_STATUS = :statusId $filterSql";
    list($rst, $totalRows) = fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize, $page);

    $available = [];
    while ($rst->Next()) {
        $available[] = Aliquot::fromDBRecord($rst);
    }

    $data = new stdClass();
    $timezone = $api->getSession()->getTimezone();
    $data->rows = array_map(function ($aliquot) use ($timezone) {
        /** @var Aliquot $aliquot */
        return $aliquot->toJSON($timezone);
    }, $available);
    $data->total_count = $totalRows;

    return new ServiceResponse($data, null);
}

/**
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function find_aliquot($parameters) {
    $aliquotId = loadParam($parameters, 'aliquotId');
    $arrVariables = [':aliquotId' => $aliquotId];
    $conditions = [];
    if ($locationId = loadParam($parameters, 'locationId')) {
        $conditions[] = "a.ID_LOCATION = :locationId";
        $arrVariables[':locationId'] = $locationId;
    }
    if ($statusId = loadParam($parameters, 'statusId')) {
        $conditions[] = "a.ID_STATUS = :statusId";
        $arrVariables[':statusId'] = $statusId;
    }
    if ($excludeIds = loadParam($parameters, 'excludeIds')) {
        $excludeIds = explode(',', $excludeIds);
        if (count($excludeIds) > 0) {
            $exclude = DbHelper::bindParamArray('exId', $parameters, $arrVariables);
            $conditions[] = "a.ID_ALIQUOT NOT IN ($exclude)";
        }
    }

    $filter = "";
    if (!empty($conditions)) {
        $filter = 'AND ' . implode(' AND ', $conditions);
    }

    $sql = "SELECT a.* , l.NAME AS LOCATION_NAME FROM ALIQUOTS a LEFT JOIN LOCATIONS l ON a.ID_LOCATION = l.ID_LOCATION WHERE a.ID_ALIQUOT = :aliquotId $filter";
    $rst = Database::getInstance()->executeBindQuery($sql, $arrVariables);
    if (!$rst->Next()) {
        return new ServiceResponse(null, "Aliquot not found");
    }

    $aliquot = new stdClass();
    $aliquot->id = $rst->GetField('ID_ALIQUOT');
    $aliquot->patientId = $rst->GetField('PATIENT_REF');
    $aliquot->type = $rst->GetField('SAMPLE_TYPE');
    $aliquot->locationId = $rst->GetField('ID_LOCATION');
    $aliquot->location = $rst->GetField('LOCATION_NAME');
    $aliquot->statusId = $rst->GetField('ID_STATUS');
    $aliquot->status = AliquotStatus::getName($rst->GetField('ID_STATUS'));
    $aliquot->created = $rst->GetField('ALIQUOT_CREATED');
    $aliquot->lastUpdate = $rst->GetField('ALIQUOT_UPDATED');

    return new ServiceResponse($aliquot, null);
}

/**
 * Adds an aliquot to a shipment.
 *
 * @param stdClass $params
 */
function shipment_add_aliquot($params) {
    $shipmentId = loadParam($params, 'shipmentId');
    $aliquotId = loadParam($params, 'aliquotId');

    if (!Shipment::exists($shipmentId)) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found");
    }

    $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId];
    $sql = "INSERT INTO SHIPPED_ALIQUOTS (ID_SHIPMENT, ID_ALIQUOT) VALUES (:shipmentId, :aliquotId)";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);

    // Update also the current status of the aliquot
    $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId, ':statusId' => AliquotStatus::IN_TRANSIT];
    $sql = "UPDATE ALIQUOTS SET ID_STATUS = :statusId, ID_SHIPMENT=:shipmentId WHERE ID_ALIQUOT = :aliquotId";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);

    return new ServiceResponse(1, null);
}

/**
 * Marks an individual aliquot of a shipment as received.
 *
 * @param stdClass $params
 */
function shipment_set_aliquot_condition($params) {
    $shipmentId = loadParam($params, 'shipmentId');
    $aliquotId = loadParam($params, 'aliquotId');
    $conditionId = loadParam($params, 'conditionId');
    $conditionId = $conditionId ? $conditionId : null;

    if (!Shipment::exists($shipmentId)) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found");
    }

    $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId, ':conditionId' => $conditionId];
    $sql = "UPDATE SHIPPED_ALIQUOTS SET ID_ALIQUOT_CONDITION=:conditionId WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT = :aliquotId";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);

    return new ServiceResponse(1, null);
}

/**
 * Removes an aliquot from a shipment.
 *
 * @param stdClass $params
 */
function shipment_remove_aliquot($params) {
    $shipmentId = loadParam($params, 'shipmentId');
    $aliquotId = loadParam($params, 'aliquotId');

    if (!Shipment::exists($shipmentId)) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found");
    }

    $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId];
    $sql = "DELETE FROM SHIPPED_ALIQUOTS WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT=:aliquotId";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);

    // Update also the current status of the aliquot
    $arrVariables = [':shipmentId' => null, ':aliquotId' => $aliquotId, ':statusId' => AliquotStatus::AVAILABLE];
    $sql = "UPDATE ALIQUOTS SET ID_STATUS = :statusId, ID_SHIPMENT=:shipmentId WHERE ID_ALIQUOT = :aliquotId";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);

    return new ServiceResponse(1, null);
}

/**
 * Creates or updates a tracking of aliquots in the database.
 * <ul>
 * <li>If the aliquot does not exist in the ALIQUTOS table, it is created with the provided values.</li>
 * <li>A record is created in the ALIQUOTS_HISTORY table to maintain an audit log of the changes</li>
 * </ul>
 *
 * @param array $dbRows
 */
function trackAliquots($dbRows, $action = AliquotAuditActions::CREATED) {
    $arrVariables = [];

    $dbColumnNames = ['ID_ALIQUOT', 'ID_PATIENT', 'PATIENT_REF', 'SAMPLE_TYPE', 'ID_LOCATION', 'ID_STATUS', 'ID_ALIQUOT_CONDITION', 'ID_TASK',
            'ALIQUOT_CREATED', 'ALIQUOT_UPDATED', 'ID_SHIPMENT', 'RECORD_TIMESTAMP'];

    $now = DateHelper::currentDate();
    foreach ($dbRows as $row) {
        $arrVariables[':action'] = $action;
        $row['RECORD_TIMESTAMP'] = $now; // Add the current timestamp to track the real time when the DB record was created/modified

        // Read the last known values of the aliquot to be updated
        $sqlPrev = "SELECT * FROM ALIQUOTS WHERE ID_ALIQUOT=:id";
        $rst = Database::getInstance()->ExecuteBindQuery($sqlPrev, $row['ID_ALIQUOT']);
        $prevValues = [];
        while ($rst->Next()) {
            foreach ($rst->getColumnNames() as $colName) {
                $prevValues[$colName] = $rst->GetField($colName);
            }
        }

        $keyColumns = ['ID_ALIQUOT' => ':id_aliquot'];

        $updateColumns = [];
        foreach ($dbColumnNames as $colName) {
            $parameterName = ':' . strtolower($colName);
            if (array_key_exists($colName, $row)) {
                // New value provided for the column
                $arrVariables[$parameterName] = $row[$colName];
            } elseif (array_key_exists($colName, $prevValues)) {
                // If the column is not present in the row, we must keep the previous value
                $arrVariables[$parameterName] = $prevValues[$colName];
            } else {
                $arrVariables[$parameterName] = null;
            }
            if (!array_key_exists($colName, $keyColumns)) {
                $updateColumns[$colName] = $parameterName;
            }
        }

        $sql = Database::getInstance()->buildInsertOrUpdateQuery('ALIQUOTS', $keyColumns, $updateColumns);
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

        /*
         * Add the tracking of the aliquots in the ALIQUOTS_HISTORY table
         */
        $sql = "INSERT INTO ALIQUOTS_HISTORY (ID_ALIQUOT, ID_TASK, ACTION, ID_LOCATION, ID_STATUS, ID_ALIQUOT_CONDITION, ALIQUOT_UPDATED, ID_SHIPMENT, RECORD_TIMESTAMP)
                        VALUES (:id_aliquot, :id_task, :action, :id_location, :id_status, :id_aliquot_condition, :aliquot_updated, :id_shipment, :record_timestamp)";
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
    }
}

/**
 * Returns the list of shipments that have shipped aliquots that have not been tracked yet in the eCRF.
 * The conditions to consider that a Shipment is pending to be tracked are:
 * <ul>
 * <li>The shipment must be in "Shipped" or "Received" status</li>
 * <li>At least one of the aliquots in the shipment has not been tracked yet in the eCRF, what means that they do not have an associated eCRF
 * Task to track the shipment (which contains the information about the shipment)</li>
 * </ul>
 * The returned value is an array where each item is an associative array with the following structure:
 * <ul>
 * <li>shipment: Shipment</li>
 * <li>patients: array of ['patientId' => ..., 'patientRef' => ...]: The list of patients in the shipment with untracked aliquots</li>
 * </ul>
 *
 * @return array
 */
function untrackedShipments() {
    // Find the shipped aliquots that have not been tracked yet in the eCRF
    $sql = "SELECT DISTINCT sa.ID_SHIPMENT, a.ID_PATIENT, a.PATIENT_REF 
            FROM SHIPPED_ALIQUOTS sa, SHIPMENTS s, ALIQUOTS a
            WHERE s.ID_SHIPMENT=sa.ID_SHIPMENT AND s.ID_STATUS IN (:statusShipped, :statusReceived)
                AND (sa.ID_SHIPMENT_TASK IS NULL OR sa.ID_SHIPMENT_TASK=0) AND sa.ID_ALIQUOT = a.ID_ALIQUOT
            ORDER BY s.SHIPMENT_DATE, a.ID_PATIENT";
    $rst = Database::getInstance()->executeBindQuery($sql,
            [':statusShipped' => ShipmentStatus::SHIPPED, ':statusReceived' => ShipmentStatus::RECEIVED]);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
    }

    $pendingShipmentIds = [];
    while ($rst->Next()) {
        $pendingShipmentIds[$rst->GetField('ID_SHIPMENT')][] = ['patientId' => $rst->GetField('ID_PATIENT'),
                'patientRef' => $rst->GetField('PATIENT_REF')];
    }

    $untrackedShipments = [];
    foreach ($pendingShipmentIds as $shipmentId => $patientIdsInShipment) {
        if ($shipment = Shipment::exists($shipmentId)) {
            $untrackedShipments[] = ['shipment' => $shipment, 'patients' => $patientIdsInShipment];
        }
    }

    return $untrackedShipments;
}

/**
 * Returns the list of shipments that have aliquots received at the destination of a shipment that have not been tracked yet in the eCRF.
 * The conditions to consider that the reception of a Shipment is pending to be tracked are:
 * <ul>
 * <li>The shipment must be in "Received" status</li>
 * <li>At least one of the aliquots in the shipment has not been tracked yet in the eCRF, what means that they do not have an associated eCRF
 * Task to track the reception (which contains the information about the reception)</li>
 * </ul>
 * The returned value is an array where each item is an associative array with the following structure:
 * <ul>
 * <li>shipment: Shipment</li>
 * <li>patients: array of ['patientId' => ..., 'patientRef' => ..., 'trackingTaskId' => ...]: The list of patients in the shipment with untracked
 * aliquots</li>
 * </ul>
 *
 * @return array
 */
function untrackedReceptions() {
    // Find the shipped aliquots that have not been tracked yet in the eCRF
    $sql = "SELECT DISTINCT sa.ID_SHIPMENT, a.ID_PATIENT, a.PATIENT_REF, sa.ID_SHIPMENT_TASK FROM SHIPPED_ALIQUOTS sa, SHIPMENTS s, ALIQUOTS a
            WHERE s.ID_SHIPMENT=sa.ID_SHIPMENT AND s.ID_STATUS=:statusReceived
                AND sa.ID_SHIPMENT_TASK > 0
                AND (sa.ID_RECEPTION_TASK IS NULL OR sa.ID_RECEPTION_TASK=0)
                AND sa.ID_ALIQUOT = a.ID_ALIQUOT
            ORDER BY s.SHIPMENT_DATE, a.ID_PATIENT";

    $rst = Database::getInstance()->executeBindQuery($sql, [':statusReceived' => ShipmentStatus::RECEIVED]);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
    }

    $pendingShipmentIds = [];
    while ($rst->Next()) {
        $pendingShipmentIds[$rst->GetField('ID_SHIPMENT')][] = ['patientId' => $rst->GetField('ID_PATIENT'),
                'patientRef' => $rst->GetField('PATIENT_REF'), 'trackingTaskId' => $rst->GetField('ID_SHIPMENT_TASK')];
    }

    $untrackedReceptions = [];
    foreach ($pendingShipmentIds as $shipmentId => $patientIdsInShipment) {
        if ($shipment = Shipment::exists($shipmentId)) {
            $untrackedReceptions[] = ['shipment' => $shipment, 'patients' => $patientIdsInShipment];
        }
    }

    return $untrackedReceptions;
}

/**
 * Updates the aliquots that have been successfully tracked in the eCRF to indicate the associated tracking task.
 *
 * @param string $trackedAction 'SHIPMENT' or 'RECEPTION'
 * @param number $shipmentId
 * @param string $taskId
 * @param string[] $aliquotIds
 */
function markTrackedAliquots($trackedAction, $shipmentId, $taskId, $aliquotIds) {
    $shipment = Shipment::exists($shipmentId);
    if (!$shipment) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found while marking tracked receptions");
    }

    $aliquots = array_filter($shipment->getAliquots(),
            function ($aliquot) use ($aliquotIds) {
                /** @var Aliquot $aliquot */
                return in_array($aliquot->id, $aliquotIds);
            });

    if (count($aliquots) != count($aliquotIds)) {
        $foundIds = array_map(function ($aliquot) {
            return $aliquot->id;
        }, $aliquots);
        $missingIds = array_diff($aliquotIds, $foundIds);
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Some aliquots of the shipment with ID $shipmentId were not found while marking tracked receptions: " .
                implode(', ', $missingIds));
    }

    $taskColumn = null;
    $action = null;
    switch ($trackedAction) {
        case 'SHIPMENT' :
            $taskColumn = 'ID_SHIPMENT_TASK';
            $action = AliquotAuditActions::SHIPMENT_TRACKED;
            break;
        case 'RECEPTION' :
            $taskColumn = 'ID_RECEPTION_TASK';
            $action = AliquotAuditActions::RECEPTION_TRACKED;
            break;
        default :
            throw new ServiceException(ErrorCodes::INVALID_DATA_FORMAT, "Invalid tracked action: $trackedAction");
    }
    $arrVariables = [':shipmentId' => $shipmentId, ':taskId' => $taskId];
    $inCondition = DbHelper::bindParamArray('aliquotId', $aliquotIds, $arrVariables);
    $sql = "UPDATE SHIPPED_ALIQUOTS SET $taskColumn = :taskId WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT IN ($inCondition)";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);

    foreach ($aliquots as $aliquot) {
        $aliquot->taskId = $taskId;
        $aliquot->save($action);
    }
}

/* ************************************************************************************* */
/* ************************************************************************************* */
/* ************************************************************************************* */

/**
 *
 * @param string $queryColumns
 * @param string $queryFromClause
 * @param array $arrVariables
 * @param number $pageSize
 * @param number $page
 * @return [DbManagerResults, int]
 */
function fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize = null, $page = null) {
    if ($pageSize > 0) {
        $offset = ($page > 0) ? 1 + ($page - 1) * $pageSize : null;
    } else {
        $pageSize = null;
    }

    $sqlFecth = "SELECT $queryColumns " . $queryFromClause;
    $sqlCount = "SELECT COUNT(*) AS TOTAL_ROWS " . $queryFromClause;

    $rstCount = Database::getInstance()->executeBindQuery($sqlCount, $arrVariables);
    $rstCount->Next();

    $totalRows = $rstCount->GetField('TOTAL_ROWS');
    $rst = Database::getInstance()->executeBindQuery($sqlFecth, $arrVariables, $pageSize, $offset);

    return [$rst, $totalRows];
}
