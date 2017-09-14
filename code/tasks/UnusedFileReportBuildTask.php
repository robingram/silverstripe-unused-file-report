<?php
/**
 * A task that collects data on unused files
 */
class UnusedFileReportBuildTask extends BuildTask
{
    /**
     * {@inheritDoc}
     * @var string
     */
    protected $title = 'Build table for Unused File Reports';

    /**
     * {@inheritDoc}
     * @var string
     */
    protected $description = 'A task that collects data on unused files';

    /**
     * {@inheritDoc}
     * @var boolean
     */
    protected $enabled = true;

    /**
    * Reference to the SS class manifest
    * @var SS_ClassManifest
    */
    protected $manifest;

    /**
    * Classes that are descended from DataObject
    * @var array
    */
    protected $dataClasses;

    /**
    * Classes that are descended from SiteTree
    * @var array
    */
    protected $siteTreeClasses;

    // Isolation levels so that we can run large queries without locking the DB
    const ISOLATION_ON = 'SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;';
    const ISOLATION_OFF = 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;';

    const HAS_ONE_QUERY_TEMPLATE = 'SELECT %sID AS file_id FROM %s WHERE %sID > 0';
    const HAS_MANY_QUERY_TEMPLATE = 'SELECT %sID AS file_id FROM %s WHERE %sID > 0';
    const MANY_MANY_QUERY_TEMPLATE = 'SELECT %sID AS file_id FROM %s_%s WHERE %sID > 0';
    const CONTENT_QUERY_TEMPLATE = "SELECT %s AS content FROM %s WHERE %s IS NOT NULL AND %s != '' AND (%s LIKE '%%img%%' OR %s LIKE '%%file_link%%')";

    const LARGE_QUERY_BATCH_SIZE = 500;

    /**
    * Classes that we should NOT be checking for unused file references
    * @var array
    */
    protected $excludeClasses = [
        'File',
        'FileVersion'
    ];

    /**
     * {@inheritDoc}
     * @param  SS_HTTPRequest $request [description]
     */
    public function run($request)
    {
        echo '<p>Start: ' . date('Y-m-d H:i:s') . '</p>';
        ini_set('max_execution_time', 1200);
        $this->buildReportTable();
        echo '<p>Memory: ' . memory_get_peak_usage() . '</p>';
        echo '<p>End: ' . date('Y-m-d H:i:s') . '</p>';
    }

    /**
     * Get the IDs of all files that are in use and populate the table with
     * files that atent
     */
    public function buildReportTable()
    {
        $used = $this->getUsedFiles();
        $query =  sprintf(
            "SELECT ID FROM File WHERE ID NOT IN (%s) AND ClassName != 'Folder'",
            implode(',', $used)
        );

        $files = DB::query($query);

        if(isset($files)) {
            DB::query('TRUNCATE "UnusedFileReportDB"');
            $insert = SQLInsert::create('"UnusedFileReportDB"');

            $count = 0;
            foreach ($files as $file) {
                $insert->addRow(
                    [
                        'FileID' => $file['ID']
                    ]
                );
                $count++;
            }

            $insert->execute();

            echo "<h3>Report table generation completed. Added {$count} files.</h3>";
        }
    }

    /**
    * Get the IDs of all files that are in use on the site
    * @return array
    */
    protected function getUsedFiles()
    {
        $classesToCheck = $this->getClassesToCheck();
        $classesWithFiles = $this->getFileClasses($classesToCheck);
        $relationshipQuery = $this->getRelationshipFileQuery($classesWithFiles);
        unset($classesWithFiles);
        $relatedIds = $this->getRelatedFileIDs($relationshipQuery);
        unset($relationshipQuery);

        $classesWithContent = $this->getContentClasses($classesToCheck);
        unset($classesToCheck);
        $contentQuery = $this->getContentQuery($classesWithContent);
        unset($classesWithContent);
        $contentIds = $this->getContentIds($contentQuery);
        unset($contentQuery);

        $usedIds = array_unique(array_merge($relatedIds, $contentIds));
        unset($relatedIds);
        unset($contentIds);
        return $usedIds;
    }

    /**
    * Get all the candidate classes to check for File or Image references
    * @return array
    */
    protected function getClassesToCheck()
    {
        $classes = array_diff(
            array_unique(
                array_merge(
                    $this->getSiteTreeClasses(),
                    $this->getDataClasses()
                )
            ),
            $this->excludeClasses
        );
        sort($classes);

        return $classes;
    }

    /**
    * Get all the classes that have references to images or files
    *
    * @param array $candidates Candidate class names
    * @return array
    */
    protected function getFileClasses($candidates)
    {
        $hasOneRels   = [];
        $hasManyRels  = [];
        $manyManyRels = [];

        foreach ($candidates as $className) {
            $hasOneRels[$className]   = $this->getRelationshipFields($className, 'has_one');
            $hasManyRels[$className]  = $this->getRelationshipFields($className, 'has_many');
            $manyManyRels[$className] = $this->getRelationshipFields($className, 'many_many');
        }

        return [
            'has_one'   => array_filter($hasOneRels),
            'has_many'  => array_filter($hasManyRels),
            'many_many' => array_filter($manyManyRels)
        ];
    }

    /**
    * Get all the File or Image relationships on a class for the given type of relationship
    *
    * @param string $class        Name of class
    * @param string $relationship Type of relationship (e.g. 'has_one')
    * @return array               Array of relationships to files or images
    */
    protected function getRelationshipFields($class, $relationship)
    {
        $relationships = @(array)Config::inst()->get($class, $relationship, Config::UNINHERITED);
        return array_intersect($relationships, ['File', 'Image']);
    }

    /**
    * Build a query string to get the file IDs for all relationship types
    *
    * @param array $relationships All relationships of type 'has_one', 'has_many' and 'many_many'
    * @return string
    */
    protected function getRelationshipFileQuery($relationships)
    {
        $hasOneSql = $this->getHasOneQuery($relationships['has_one']);
        $hasManySql = $this->getHasManyQuery($relationships['has_many']);
        $manyManySql = $this->getManyManyQuery($relationships['many_many']);

        return implode("\nUNION\n", array_filter([$hasOneSql, $hasManySql, $manyManySql]));
    }

    /**
    * Build a query to get file IDs for has_one relationships
    *
    * @param array $hasOneClasses Classes and their relationships of type 'has_one'
    * @return string
    */
    protected function getHasOneQuery($hasOneClasses)
    {
        return implode(
            "\nUNION\n",
            array_map(
                function($k, $v) {
                    return implode(
                        "\nUNION\n",
                        array_map(
                            function($field) use ($k) {
                                return sprintf(
                                    self::HAS_ONE_QUERY_TEMPLATE,
                                    $field,
                                    $this->getVersionedTableName($k),
                                    $field
                                );
                            },
                            array_keys($v)
                        )
                    );
                },
                array_keys($hasOneClasses),
                $hasOneClasses
            )
        );
    }

    /**
    * Build a query to get file IDs for has_many relationships
    *
    * @param array $hasOneClasses Classes and their relationships of type 'has_many'
    * @return string
    */
    protected function getHasManyQuery($hasManyClasses)
    {
        if (count($hasManyClasses) == 0) {
            return '';
        }

        $fields = call_user_func_array('array_merge', $hasManyClasses);
        return implode(
            "\nUNION\n",
            array_map(
                function($field) {
                    return sprintf(
                        self::HAS_MANY_QUERY_TEMPLATE,
                        $field,
                        $this->getVersionedTableName($field),
                        $field
                    );
                },
                $fields
            )
        );
    }

    /**
    * Build a query to get file IDs for many_many relationships
    *
    * @param array $hasOneClasses Classes and their relationships of type 'many_many'
    * @return string
    */
    protected function getManyManyQuery($manyManyClasses)
    {
        return implode(
            "\nUNION\n",
            array_map(
                function($k, $v) {
                    return implode(
                        "\nUNION\n",
                        array_map(
                            function($joinTable, $field) use ($k) {
                                return sprintf(
                                    self::MANY_MANY_QUERY_TEMPLATE,
                                    $field,
                                    $k,
                                    $joinTable,
                                    $field
                                );
                            },
                            array_keys($v),
                            $v
                        )
                    );
                },
                array_keys($manyManyClasses),
                $manyManyClasses
            )
        );
    }

    /**
    * Run the query to get the file IDs referenced by all relationships
    *
    * @param string $query SQL query to run
    * @return array
    */
    protected function getRelatedFileIDs($query)
    {
        DB::query(self::ISOLATION_ON);
        $result = DB::query($query)->column();
        DB::query(self::ISOLATION_OFF);

        return $result;
    }

    /**
    * Get all the classes that have references to HTMLText content
    *
    * @param array $candidates Candidate class names
    * @return array
    */
    protected function getContentClasses($candidates)
    {
        $contentClasses = [];

        foreach ($candidates as $className) {
            $contentClasses[$className] = array_intersect(
                DataObject::custom_database_fields($className),
                ['HTMLText']
            );
        }
        $contentClasses = array_filter($contentClasses);

        return $contentClasses;
    }

    /**
    * Build a set of queries to get content of all HTMLText fields
    *
    * @param array $contentClasses Classes and their HTMLText fields
    * @return string
    */
    protected function getContentQuery($contentClasses)
    {
        $queries = array_map(
            function($k, $v) {
                return implode(
                    "\nUNION\n",
                    array_map(
                        function($field) use ($k) {
                            return sprintf(
                                self::CONTENT_QUERY_TEMPLATE,
                                $field,
                                $this->getVersionedTableName($k),
                                $field,
                                $field,
                                $field,
                                $field
                            );
                        },
                        array_keys($v)
                    )
                );
            },
            array_keys($contentClasses),
            $contentClasses
        );
        return $queries;
    }

    /**
    * Get the IDs of all image and file references in HTML content
    * @param array $queries Array of SQL queries to get the content fields
    * @return array
    */
    protected function getContentIds($queries)
    {
        $allIds = [];
        $batchSize = self::LARGE_QUERY_BATCH_SIZE;
        foreach ($queries as $query) {
            $offset = 0;
            do {
                $limitQuery = " LIMIT {$offset},{$batchSize}";
                DB::query(self::ISOLATION_ON);
                $rawContent = DB::query($query . $limitQuery)->column();
                DB::query(self::ISOLATION_OFF);
                $ids = array_filter(
                    array_unique(
                        array_merge(
                            $this->extractImageReferences($rawContent),
                            $this->extractFileReferences($rawContent)
                        )
                    )
                );
                $allIds = array_unique(array_merge($allIds, $ids));
                $offset += $batchSize;
            } while (count($rawContent) > 0 && count($rawContent) == $batchSize);
        }
        return $allIds;
    }

    /**
    * Extract the IDs of all files from image references in HTML content
    * @param string $content
    * @return array
    */
    protected function extractImageReferences($content)
    {
        $allImages = (object) array('images' => []);
        array_walk(
            $content,
            function($value, $_, &$accumulator) {
                $images = [];
                preg_match_all('/<img[^>]+src="([^">]+)"/', $value, $images);
                if (!empty($images[1])) {
                    $accumulator->images = array_merge($accumulator->images, $images[1]);
                }
            },
            $allImages
        );
        $allImages = array_unique($allImages->images);
        return $this->findFiles($allImages);
    }

    /**
    * Extract the IDs of all files from file references in HTML content
    * @param string $content
    * @return array
    */
    protected function extractFileReferences($content)
    {
        $allFiles = (object) array('files' => []);
        array_walk(
            $content,
            function($value, $_, &$accumulator) {
                $files = [];
                preg_match_all('/\[file_link,id=([0-9]*)\]/', $value, $files);
                if (!empty($files[1])) {
                    $accumulator->files = array_merge($accumulator->files, $files[1]);
                }
            },
            $allFiles
        );
        $fileIds = array_unique($allFiles->files);

        return $fileIds;
    }

    /**
    * Get the IDs of files given a list of paths
    * @param array $files
    * @return array
    */
    protected function findFiles($files)
    {
        return array_filter(
            array_map(
                function($fileName) {
                    $file = File::find($fileName);
                    if ($file) {
                        return $file->ID;
                    }
                },
                $files
            )
        );
    }

    /**
    * Get the SS manifest
    * @return SS_ClassManifest
    */
    protected function getManifest()
    {
        if (is_null($this->manifest)) {
            $classLoader = SS_ClassLoader::instance();
            $this->manifest = $classLoader->getManifest();
        }
        return $this->manifest;
    }

    /**
    * Get classes that are descendents of SiteTree
    * @return array
    */
    protected function getSiteTreeClasses()
    {
        if (is_null($this->siteTreeClasses)) {
            $this->siteTreeClasses = $this->getManifest()->getDescendantsOf('SiteTree');
        }
        return $this->siteTreeClasses;
    }

    /**
    * Get classes that are descendents of DataObject
    * @return array
    */
    protected function getDataClasses()
    {
        if (is_null($this->dataClasses)) {
            $this->dataClasses = $this->getManifest()->getDescendantsOf('DataObject');
        }
        return $this->dataClasses;
    }

    /**
    * Transform a table name to it's versioned equivalent if necessary
    * @param  string $className Name of class (corresponds to table name)
    * @return string            Class name with versioned extension if applicable
    */
    protected function getVersionedtableName($className)
    {
        return ($className::has_extension('Versioned'))
            ? "{$className}_versions"
            :$className;
    }
}
