<?php

namespace IsobarNZ\VersionPrune\Tests\Tasks\ClearArchivedRecordsTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

class BaseRecord extends DataObject implements TestOnly
{
    private static $table_name = 'CART_BaseRecord';

    private static $db = [
        'Title' => 'Varchar',
    ];

    private static $extensions = [
        'versioned' => Versioned::class,
    ];
}
