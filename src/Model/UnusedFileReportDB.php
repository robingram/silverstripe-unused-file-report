<?php

namespace RobIngram\SilverStripe\UnusedFileReport\Model;

use SilverStripe\ORM\DataObject;

class UnusedFileReportDB extends DataObject
{
    /**
     * {@inheritDoc}
     * @var array
     */
    private static $db = [
        'FileID' => 'Int'
    ];

    private static $table_name = 'UnusedFileReportDB';
}
