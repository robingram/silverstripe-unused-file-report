<?php

namespace RobIngram\SilverStripe\UnusedFileReport\Tasks;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Core\Config\Config;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Manifest\ClassLoader;
use SilverStripe\Control\Director;
use RobIngram\SilverStripe\UnusedFileReport\Model\UnusedFileReportDB;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Versioned\Versioned;

/**
 * A task that collects data on unused files
 */
class DeleteAllUnusedFiles extends BuildTask
{
    /**
     * {@inheritDoc}
     * @var string
     */
    private static $segment = 'delete-all-unused-files';

    /**
     * {@inheritDoc}
     * @var string
     */
    protected $title = 'Delete all unused files';

    /**
     * {@inheritDoc}
     * @var string
     */
    protected $description = 'All the files that currently listed in the unused file report will be deleted.';


    /**
     * {@inheritDoc}
     * @param  HTTPRequest $request
     */
    public function run($request)
    {
        Environment::increaseMemoryLimitTo(-1);
        Environment::increaseTimeLimitTo(-1);
        echo PHP_EOL . PHP_EOL;
        echo '======================' . PHP_EOL;
        echo 'Delete all unused files' . PHP_EOL;
        echo '======================' . PHP_EOL;
        if (! Director::is_cli()) {
            echo 'ERROR: This task can only be run from the command line.' . PHP_EOL;
            return;
        }
        $list = UnusedFileReportDB::get()->columnUnique('FileID');
        if ($list) {
            foreach ($list as $id) {
                if ($this->deleteFile($id)) {
                    DB::query('DELETE FROM "UnusedFileReportDB" WHERE "FileID" = ' . $id . ' LIMIT 1');
                }
            }
        } else {
            echo 'OK: No files to delete.' . PHP_EOL;
        }
        echo PHP_EOL . PHP_EOL;
    }


    protected function deleteFile(int $id): bool
    {
        $file = File::get()->byID($id);
        if ($file) {
            echo 'Deleting file: ' . $file->getFilename() . PHP_EOL;
            $fileName = $file->getFilename();

            try {
                //$file->deleteFile();
            } catch (Exception $exception) {
                echo 'ERROR: Caught exception: ' . $exception->getMessage();
            }
            $file->deleteFromStage(Versioned::DRAFT);
            $file->deleteFromStage(Versioned::LIVE);
            DB::query('DELETE FROM "File" WHERE "ID" = ' . $id . ' LIMIT 1');
            DB::query('DELETE FROM "File_Live" WHERE "ID" = ' . $id . ' LIMIT 1');
            $path = Controller::join_links(ASSETS_PATH, $fileName);
            if (file_exists($path)) {
                echo 'ERROR: Also having to delete physical file: ' . $path . PHP_EOL;
                if (! $this->deleteDirectoryOrFile($path)) {
                    echo 'ERROR: Deletion did not work successfully: ' . $path . PHP_EOL;
                }
                if (file_exists($path)) {
                    echo 'ERROR: Could not delete file: ' . $path . PHP_EOL;
                }
            } else {
                return true;
            }
        } else {
            DB::query('DELETE FROM "UnusedFileReportDB" WHERE "FileID" = ' . $id . ' LIMIT 1');
            echo 'ERROR: could not find DB file to delete ' . PHP_EOL;
        }
        return false;
    }

    protected function deleteDirectoryOrFile(string $path): bool
    {
        if (! is_dir($path)) {
            return unlink($path);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        return rmdir($path);
    }
}
