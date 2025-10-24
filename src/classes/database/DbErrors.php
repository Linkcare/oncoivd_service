<?php

class DbErrors extends BasicEnum {
    const UNEXPECTED_ERROR = 'UNEXPECTED_ERROR';
    const NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';
    const DATABASE_EXECUTION_ERROR = 'DATABASE.EXECUTION_ERROR'; // An error occurred when saving an object
    const DATABASE_COLUMN_NOT_FOUND = 'DATABASE.COLUMN.NOT_FOUND'; // The column doesn't exist
}

