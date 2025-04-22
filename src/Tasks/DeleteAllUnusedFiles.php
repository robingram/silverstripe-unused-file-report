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
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
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

    private static bool $skip_deleting_folders = false;
    private static bool $skip_deleting_images = false;
    private static bool $skip_deleting_non_images = false;

    private static bool $skip_deleting_folders_physical_only = false;
    private static bool $skip_deleting_images_physical_only = false;
    private static bool $skip_deleting_non_images_physical_only = false;
    private static bool $skip_deleting_all_files_physical_only = false;

    protected bool $skipDeletingFolders;
    protected bool $skipDeletingImages;
    protected bool $skipDeletingNonImages;

    protected bool $skipDeletingFoldersPhysicalOnly;
    protected bool $skipDeletingImagesPhysicalOnly;
    protected bool $skipDeletingNonImagesPhysicalOnly;
    protected bool $skipDeletingAllFilesPhysicalOnly;
    protected int $countOfFiles = 0;


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
        $this->skipDeletingFolders = Config::inst()->get(self::class, 'skip_deleting_folders');
        $this->skipDeletingImages = Config::inst()->get(self::class, 'skip_deleting_images');
        $this->skipDeletingNonImages = Config::inst()->get(self::class, 'skip_deleting_non_images');
        $this->skipDeletingFoldersPhysicalOnly = Config::inst()->get(self::class, 'skip_deleting_folders_physical_only');
        $this->skipDeletingImagesPhysicalOnly = Config::inst()->get(self::class, 'skip_deleting_images_physical_only');
        $this->skipDeletingNonImagesPhysicalOnly = Config::inst()->get(self::class, 'skip_deleting_non_images_physical_only');
        $this->skipDeletingAllFilesPhysicalOnly = Config::inst()->get(self::class, 'skip_deleting_all_files_physical_only');


        $list = UnusedFileReportDB::get()->columnUnique('FileID');
        $this->countOfFiles = count($list);
        if ($list) {
            $myCount = 0;
            foreach ($list as $id) {
                $myCount++;
                $this->deleteFile($id, $myCount);
            }
        } else {
            echo 'No files to delete.' . PHP_EOL;
        }
        echo '======================' . PHP_EOL . PHP_EOL . PHP_EOL;
    }


    protected function deleteFile(int $id, int $myCount): bool
    {
        $file = File::get()->byID($id);

        if ($file) {
            echo 'Looking at file: ' . $myCount . ' / ' . $this->countOfFiles . ': '  . $file->getFilename();
            if ($this->skipDeletingFolders) {
                if ($file instanceof Folder) {
                    echo '... Skipping folder: ' . $file->getFilename() . PHP_EOL;
                    return true;
                }
            }
            if ($this->skipDeletingImages) {
                if ($file instanceof Image) {
                    echo '... Skipping image: ' . $file->getFilename() . PHP_EOL;
                    return true;
                }
            }
            if ($this->skipDeletingNonImages) {
                if (!($file instanceof Image) && !($file instanceof Folder)) {
                    echo '... Skipping non-image file: ' . $file->getFilename() . PHP_EOL;
                    return true;
                }
            }
            $file->deleteFromStage(Versioned::DRAFT);
            $file->deleteFromStage(Versioned::LIVE);
            DB::query('DELETE FROM "File" WHERE "ID" = ' . $id . ' LIMIT 1');
            DB::query('DELETE FROM "File_Live" WHERE "ID" = ' . $id . ' LIMIT 1');
            echo '... Deleted' . PHP_EOL;
            if ($this->deletePhysicalFile($file)) {
                DB::query('DELETE FROM "UnusedFileReportDB" WHERE "FileID" = ' . $id . ' LIMIT 1');
                return true;
            } else {
                return false;
            }
        } else {
            DB::query('DELETE FROM "UnusedFileReportDB" WHERE "FileID" = ' . $id . ' LIMIT 1');
            echo 'ERROR: Could not find DB file to delete, ID is: ' . $id . PHP_EOL;
        }
        return false;
    }

    protected function deletePhysicalFile($file): bool
    {
        if ($this->skipDeletingAllFilesPhysicalOnly) {
            return true;
        }
        $fileName = $file->getFilename();
        if ($fileName) {
            $path = Controller::join_links(ASSETS_PATH, $fileName);
            if (file_exists($path)) {
                echo 'ERROR: Also having to delete physical file: ' . $path;
                if ($this->skipDeletingFoldersPhysicalOnly && $file instanceof Folder) {
                    return true;
                }
                if ($this->skipDeletingImagesPhysicalOnly && $file instanceof Image) {
                    return true;
                }
                if ($this->skipDeletingNonImagesPhysicalOnly && !($file instanceof Image) && !($file instanceof Folder)) {
                    return true;
                }
                if ($this->deleteDirectoryOrFile($path)) {
                    echo '... Deleted physical file: ' . $path . PHP_EOL;
                } else {
                    echo '... Deletion did not work successfully: ' . $path . PHP_EOL;
                }
                if (file_exists($path)) {
                    echo 'ERROR: Could not delete file: ' . $path . PHP_EOL;
                    return false;
                }
            } else {
                return true;
            }
        } else {
            echo 'ERROR: Could not find filename for File with ID: ' . $file->ID . PHP_EOL;
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
