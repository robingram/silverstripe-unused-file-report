<?php
/**
 * Create a report on the files that have been flagged as potentially unused
 *
 * @author Rob Ingram <robert.ingram@ccc.govt.nz>
 * @package Reports
 */
class UnusedFilesReport extends SS_Report {
  public function title() {
    return 'Unused Files Report';
  }

  public function description() {
    return 'This report contains <strong>potentially</strong> unused files. Please check with the owner before deleting any of these files.';
  }

  public function columns() {
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
        'title'      => 'File',
        'formatting' => function($value, $item) {
          return sprintf(
            "<a href='%s'>%s</a>",
            Controller::join_links(singleton('AssetAdmin')->Link('EditForm'), 'field/File/item', $item->ID, 'edit'),
            $value
          );
        },
      ),
      'FileSize' => array(
        'title' => 'File size',
        'formatting' => function($value, $item) {
          return $this->toHumanReadableFileSize($value);
        }
      ),
      'FileName' => 'Location',
      'ClassName' => 'Type',
      'OwnerID' => 'OwnerID',
      'OwnerID' => array(
        'title' => 'OwnerID',
        'formatting' => function($value, $item) {
          if($item->OwnerID > 0) {
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

  public function getColumns() {
    return $this->columns();
  }

  public function summaryFields() {
    return $this->columns();
  }

  public function sortColumns() {
    return array_keys($this->columns());
  }

  public function sourceRecords($params, $sort, $limit) {
    if (isset($params['FileType']) && $params['FileType'] == 'File') {
      $where = "File.ClassName = 'File'";
    } elseif (isset($params['FileType']) && $params['FileType'] == 'Image') {
      $where = "File.ClassName = 'Image'";
    } else {
      $where = "File.ClassName = 'File' OR File.ClassName = 'Image'";
    }

    $files = File::get()
      ->innerJoin('UnusedFileReportDB', '"File"."ID" = "UnusedFileReportDB"."FileID"')
      ->where($where);

    return $files;
  }

  public function parameterFields() {
    return new FieldList(
      new DropdownField('FileType', 'File type', array(
        'All' => 'All',
        'File' => 'Files Only',
        'Image' => 'Images Only'
        )
      )
    );
  }

  public function getReportField() {
    $gridField = parent::getReportField();
    $gridField->setModelClass('FilesReport');
    $gridConfig = $gridField->getConfig();
    $gridConfig->removeComponentsByType('GridFieldPrintButton');
    $gridConfig->removeComponentsByType('GridFieldExportButton');
    $gridConfig->addComponents(
      new GridFieldPrintReportButton('buttons-after-left'),
      new GridFieldExportReportButton('buttons-after-left')
    );

    return $gridField;
  }

  /**
   * Display the value as a human friendly file size
   * http://stackoverflow.com/questions/2510434/format-bytes-to-kilobytes-megabytes-gigabytes
   *
   * @param  int    $value
   * @return string        Human friendly version of $value as file size
   */
  protected function toHumanReadableFileSize($value, $precision = 1) {
    if(!empty($value)) {
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
