<?php

namespace IsobarNZ\VersionPrune\Tests\Tasks\ClearArchivedRecordsTest;

class ChildRecord extends BaseRecord
{
    private static $table_name = 'CART_ChildRecord';

    private static $db = [
        'Description' => 'Varchar',
    ];
}
