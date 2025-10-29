<?php

abstract class AliquotAuditActions extends BasicEnum {
    const CREATED = 'CREATED';
    const SHIPPED = 'SHIPPED';
    const RECEIVED = 'RECEIVED';
    const SHIPMENT_TRACKED = 'ECRF_SHIPMENT_TRACKED';
    const RECEPTION_TRACKED = 'ECRF_RECEPTION_TRACKED';
}