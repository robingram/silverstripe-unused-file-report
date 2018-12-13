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
        return DBField::create_field('HTMLText',
            'This report contains <strong>potentially</strong> unused files. Please check with the owner before deleting any of these files.'
        );
    }

    public function columns()
    {
        $fields = array(
            'ID' => 'File ID',
            'Created' => array(
                'title' => 'Created',
                'casting' => 'Date->Nice'
            ),
            'LastEdited' => array(
                'title' => 'Last Edited',
                'casting' => 'Date->Ago'
            ),
            'Name' => array(
                'title' => 'File',
                'formatting' => function ($value, $item) {
                    return sprintf(
                        "<a href='%s'>%s</a>",
                        Controller::join_links(singleton(AssetAdmin::class)->Link('EditForm'), 'field/File/item', $item->ID, 'edit'),
                        $value
                    );
                },
            ),
            'FileSize' => array(
                'title' => 'File size',
                'formatting' => function ($value, $item) {
                    return $this->toHumanReadableFileSize($value);
                }
            ),
            'FileName' => 'Location',
            'ClassName' => 'Type',
            'OwnerID' => 'OwnerID',
            'OwnerID' => array(
                'title' => 'OwnerID',
                'formatting' => function ($value, $item) {
                    if ($item->OwnerID > 0) {
                        $owner = Member::get()->byID($item->OwnerID);
                        return $owner = (isset($owner) ? $owner->getName() : 'Deleted');
                    }
                    return $value;
                }
            ),
            'VersionNumber' => 'Version',
            'CurrentVersionID' => 'CurrentVersionID',
        );
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
                'Image' => 'Images Only'
            ])
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

    /**
     * Display the value as a human friendly file size
     * http://stackoverflow.com/questions/2510434/format-bytes-to-kilobytes-megabytes-gigabytes
     *
     * @param  int $value
     * @return string        Human friendly version of $value as file size
     */
    protected function toHumanReadableFileSize($value, $precision = 1)
    {
        if (!empty($value)) {
            $units = array('B', 'KB', 'MB', 'GB', 'TB');

            $bytes = max($value, 0);
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);
            $bytes /= pow(1024, $pow);

            return round($bytes, $precision) . ' ' . $units[$pow];
        } else {
            return 0;
        }
    }
}
