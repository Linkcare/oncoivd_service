<?php

abstract class AliquotStatus extends BasicEnum {
    const ALL = 'ALL';
    const AVAILABLE = 'IN_PLACE';
    const IN_TRANSIT = 'IN_TRANSIT';
    const REJECTED = 'REJECTED';
    const USED = 'USED';
}