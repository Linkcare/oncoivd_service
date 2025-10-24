<?php

abstract class ShipmentStatus extends BasicEnum {
    const PREPARING = "PREPARING";
    const SHIPPED = "SHIPPED";
    const RECEIVING = "RECEIVING";
    const RECEIVED = "RECEIVED";
}