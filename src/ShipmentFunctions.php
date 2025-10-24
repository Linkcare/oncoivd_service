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

    $page = loadParam($parameters, 'page');
    $pageSize = loadParam($parameters, 'pageSize');
    $filters = loadParam($parameters, 'filters');
    cleanFilters($filters);

    $arrVariables[':currentTeamId'] = $api->getSession()->getTeamId();
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
            WHERE (s.ID_SENT_FROM=:currentTeamId OR (s.ID_SENT_TO=:currentTeamId AND s.ID_STATUS <> :statusPreparing)) $filterSql";
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
        preserveProperties($parameters, ['id', 'ref', 'sentFromId', 'sentToId']);
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
 * @param array $parameters
 */
function shipment_send($parameters) {
    $api = LinkcareSoapAPI::getInstance();

    $shipmentId = loadParam($parameters, 'id');
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
    $parameters->sendDate = DateHelper::currentDate();

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

    try {
        $user = $api->user_get($shipment->senderId);
        $parameters->sender = $user->getFullName();
    } catch (Exception $e) {}

    // Update the last modification date of the aliquots and generate a tracking record
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

    $shipment->updateModified($shipment);
    ServiceFunctions::trackAliquots($aliquotList);

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
            SET a.UPDATED=:updated, a.ID_STATUS=:rejectedStatus, a.ID_ALIQUOT_CONDITION=sa.ID_ALIQUOT_CONDITION,
                a.ID_LOCATION=:locationId, a.ID_SHIPMENT=NULL
            WHERE
                sa.ID_SHIPMENT=:shipmentId
            	AND a.ID_ALIQUOT = sa.ID_ALIQUOT
            	AND sa.ID_ALIQUOT_CONDITION IS NOT NULL AND sa.ID_ALIQUOT_CONDITION <> ''";
    $sqls[] = "UPDATE ALIQUOTS a, SHIPPED_ALIQUOTS sa
            SET a.UPDATED=:updated, a.ID_STATUS=:okStatus, a.ID_ALIQUOT_CONDITION=NULL,
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

    ServiceFunctions::trackAliquots($aliquotList);

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
