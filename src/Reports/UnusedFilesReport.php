<?php

namespace RobIngram\SilverStripe\UnusedFileReport\Reports;

use SilverStripe\Reports\Report;
use SilverStripe\Security\Member;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Assets\File;
use SilverStripe\Control\Controller;
use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Versioned\VersionedGridFieldState\VersionedGridFieldState;

/**
 * Create a report on the files that have been flagged as potentially unused
 *
 * @author Rob Ingram <robert.ingram@ccc.govt.nz>
 * @package Reports
 */
class UnusedFilesReport extends Report
{
    public function title()
    {
        return 'Unused Files Report';
    }

    public function description()
    {
        return DBField::create_field(
            'HTMLText',
            'This report contains <strong>potentially</strong> unused files. Please check with the owner before deleting any of these files.',
        );
    }

    public function columns()
    {
        $fields = [
            'ID' => 'File ID',
            'Created' => [
                'title' => 'Created',
                'casting' => 'Date->Nice',
            ],
            'LastEdited' => [
                'title' => 'Last Edited',
                'casting' => 'Date->Ago',
            ],
            'Name' => [
                'title' => 'File',
                'formatting' => function ($value, $item) {
                    return sprintf(
                        "<a href='%s' target=\"_blank\">%s</a>",
                        Controller::join_links(singleton(AssetAdmin::class)->Link('EditForm'), 'field/File/item', $item->ID, 'edit'),
                        $value,
                    );
                },
            ],
            'FileSize' => [
                'title' => 'File size',
                'formatting' => function ($value, $item) {
                    return $item->getSize();
                },
            ],
            'FileName' => 'Location',
            'ClassName' => 'Type',
            'OwnerID' => 'OwnerID',
            'OwnerID' => [
                'title' => 'OwnerID',
                'formatting' => function ($value, $item) {
                    if ($item->OwnerID > 0) {
                        $owner = Member::get()->byID($item->OwnerID);
                        return $owner = (isset($owner) ? $owner->getName() : 'Deleted');
                    }
                    return $value;
                },
            ],
            'Version' => 'Version',
        ];
        return $fields;
    }

    public function getColumns()
    {
        return $this->columns();
    }

    public function summaryFields()
    {
        return $this->columns();
    }

    public function sortColumns()
    {
        return array_keys($this->columns());
    }

    public function sourceRecords($params = [], $sort = null, $limit = null)
    {
        $where = false;

        if (isset($params['FileType']) && $params['FileType'] == 'File') {
            $where = "File.ClassName = 'SilverStripe\\\\Assets\\\\File'";
        } elseif (isset($params['FileType']) && $params['FileType'] == 'Image') {
            $where = "File.ClassName = 'SilverStripe\\\\Assets\\\\Image'";
        }


        $files = File::get()
            ->innerJoin('UnusedFileReportDB', '"File"."ID" = "UnusedFileReportDB"."FileID"');

        if (isset($params['Title'])) {
            $files = $files->filter('Title:PartialMatch:nocase', $params['Title']);
        }

        if ($where) {
            $files = $files->where($where);
        }

        $this->extend('updateSourceRecords', $files, $params);

        return $files;
    }

    public function parameterFields()
    {
        return new FieldList(
            TextField::create('Title', 'File name'),
            new DropdownField('FileType', 'File type', [
                'All' => 'All',
                'File' => 'Files Only',
                'Image' => 'Images Only',
            ]),
        );
    }

    public function getReportField()
    {
        $field = parent::getReportField();

        if ($config = $field->getConfig()) {
            $config->addComponent(new GridFieldDeleteAction());
            $config->addComponent(new VersionedGridFieldState());
        }

        return $field;
    }
}
