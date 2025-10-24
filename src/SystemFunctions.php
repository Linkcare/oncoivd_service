<?php

function deploy_service($parameters) {
    $dbModel = DbDataModels::shipmentsModel(Database::getInstance()->GetDatabase());
    $logs = [];

    // Create DB schema
    $error = Database::getInstance()->createSchema($dbModel, false);
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), "Error creating database schema: " . $error->getErrorMessage());
    }
    $logs[] = "Database schema created successfully.";

    $logs = array_merge($logs, populateLocations());

    return new ServiceResponse($logs, null);
}

function populateLocations() {
    $api = LinkcareSoapAPI::getInstance();
    $logs = [];

    foreach ($GLOBALS['LAB_TEAMS'] as $teamCode => $info) {
        try {
            $team = $api->team_get($teamCode);
        } catch (Exception $e) {
            $logs[] = "Team $teamCode could not be added to the locations table: " . $e->getMessage();
            continue;
        }

        $keyColumns = ['ID_LOCATION' => ':id'];
        $updateColumns = ['NAME' => ':name', 'CODE' => ':code', 'IS_LAB' => ':is_lab', 'IS_CLINICAL_SITE' => ':is_clinical_site'];
        $arrVariables = [':id' => $team->getId(), ':name' => $team->getName(), ':code' => $team->getCode(), ':is_lab' => $info['is_lab'] ? 1 : 0,
                ':is_clinical_site' => $info['is_clinical_site'] ? 1 : 0];
        $sql = Database::getInstance()->buildInsertOrUpdateQuery('LOCATIONS', $keyColumns, $updateColumns);
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        $error = Database::getInstance()->getError();
        if ($error->getErrCode()) {
            throw new ServiceException(ErrorCodes::DB_ERROR, "Error adding location '" . $team->getName() . "': " . $error->getErrorMessage());
        }
        $logs[] = "Team $teamCode added to the locations table";
    }
    return $logs;
}
