<?php

namespace IsobarNZ\VersionPrune\Tasks;

use Generator;
use InvalidArgumentException;
use ReflectionException;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\HTML;

class ClearArchivedRecords extends BuildTask
{
    const DEFAULT_KEEP_VERSIONS = 5;

    /**
     * @var string
     */
    private static $segment = 'ClearArchivedRecords';

    /**
     * @var string
     */
    protected $title = "Prunes pages and objects version archive";

    /**
     * @var string
     */
    protected $description = <<<DESCRIPTION
Prunes backlog of version history to a fixed number per record, as well as
any versions for archived or orphaned records. Note that this module will make
deleted objects unrecoverable.
Run with ?run=yes to acknowledge that deleted pages cannot be recovered,
and that you have made a backup manually, or run with ?run=dry to dry-run.
Set keep=<num> to specify number of versions to keep.
DESCRIPTION;

    /**
     * Number of versions to keep
     *
     * @var int
     */
    protected $keepVersions = self::DEFAULT_KEEP_VERSIONS;

    /**
     * @var bool
     */
    protected $dry = false;

    /**
     * True to skip slow tasks and just do orphan cleanup
     *
     * @var bool
     */
    protected $fast = false;

    /**
     * @param HTTPRequest $request
     * @throws ReflectionException
     */
    public function run($request)
    {
        $run = $request->getVar('run');
        if (!in_array($run, ['dry', 'yes', 'fast'])) {
            throw new InvalidArgumentException("Please provide the 'run' argument with either 'yes', 'dry', or 'fast'");
        }
        $this->setDry($run === 'dry');
        $this->setFast($run === 'fast');

        // With slow requests, need to increase time limit to 1 hour
        if (!$this->isFast() && !$this->isDry()) {
            Environment::increaseTimeLimitTo(3600);
            Environment::increaseMemoryLimitTo();
        }

        // Set keep versions
        $this->setKeepVersions($request->getVar('keep') ?: self::DEFAULT_KEEP_VERSIONS);

        // Loop over all versioned classes
        foreach ($this->getBaseClasses() as $class) {
            $this->flushClass($class);
        }

        $this->message("Flush complete!");
    }

    /**
     * Get all base classess with versioned
     *
     * @return Generator|string []
     * @throws ReflectionException
     */
    protected function getBaseClasses()
    {
        foreach ($this->directSubclasses(DataObject::class) as $class) {
            if (DataObject::has_extension($class, Versioned::class)) {
                yield $class;
            }
        }
    }

    /**
     * Get direct subclasses only
     *
     * @param string $class
     * @return Generator|string[]
     * @throws ReflectionException
     */
    protected function directSubclasses($class)
    {
        foreach (ClassInfo::subclassesFor($class) as $subclass) {
            if (get_parent_class($subclass) === $class) {
                yield $subclass;
            }
        }
    }

    /**
     * Flush this given class, and any base clasess, of orphaned versioned records
     *
     * @param string $class
     */
    public function flushClass(string $class)
    {
        $this->message("Beginning flush for {$class}");

        // Delete old versions for non-deleted records (note: Can be slow on large recordsets)
        if (!$this->isFast()) {
            $this->deleteOldVersions($class);
        }

        // Clear all obsolete versions for deleted records
        $this->deleteArchivedVersions($class);

        // Flush all subclass tables
        $this->deleteOrphanedVersions($class);

        // Yay
        $this->message("Done flushing {$class}");
    }

    /**
     * Output message
     *
     * @param string $string
     */
    protected function message(string $string)
    {
        if (Director::is_cli()) {
            echo "{$string}\n";
        } else {
            echo HTML::createTag('p', [], Convert::raw2xml($string));
        }
    }

    /**
     * Delete all old versions of a record
     *
     * @param string $class
     */
    public function deleteOldVersions(string $class): void
    {
        // E.g. `SiteTree`
        $baseTable = DataObject::getSchema()->tableName($class);
        $baseVersionedTable = "{$baseTable}_Versions";

        // Clear all except keepVersions num of max versions
        $clearedVersionCounts = 0;
        foreach ($this->getDraftObjects($class) as $object) {
            // Get version to keep
            $versionBound = DB::prepared_query(<<<SQL
SELECT "Version" FROM "{$baseVersionedTable}"
WHERE "RecordID" = ?
ORDER BY "Version" DESC
LIMIT {$this->getKeepVersions()}, 1
SQL
                ,
                [$object->ID]
            )->value();

            // Record has fewer than keepVersions versions
            if (!$versionBound) {
                continue;
            }

            $query = SQLSelect::create()
                ->setFrom("\"{$baseVersionedTable}\"")
                ->addWhere([
                    "\"{$baseVersionedTable}\".\"RecordID\" = ?" => $object->ID,
                    "\"{$baseVersionedTable}\".\"Version\" <= ?" => $versionBound,
                ]);

            // Delete or count
            if ($this->isDry()) {
                $count = $query->setSelect('COUNT(*)')->execute()->value();
            } else {
                $delete = $query->toDelete();
                $delete->execute();
                $count = DB::affected_rows();
            }

            $clearedVersionCounts += $count;
        }

        // Log output
        if ($clearedVersionCounts) {
            $prefix = $this->isDry() ? '(dry): ' : '';
            $this->message(
                <<<MESSAGE
{$prefix}Cleared {$clearedVersionCounts} old versions (before last {$this->getKeepVersions()}) from table {$baseVersionedTable}
MESSAGE
            );
        }
    }

    /**
     * Delete any version that isn't in draft anymore
     *
     * @param string $class
     */
    public function deleteArchivedVersions(string $class): void
    {
        // E.g. `SiteTree`
        $baseTable = DataObject::getSchema()->tableName($class);
        $baseVersionedTable = "{$baseTable}_Versions";

        $query = SQLSelect::create()
            ->setFrom("\"{$baseVersionedTable}\"")
            ->addLeftJoin(
                $baseTable,
                "\"{$baseVersionedTable}\".\"RecordID\" = \"{$baseTable}\".\"ID\""
            )
            ->addWhere("\"{$baseTable}\".\"ID\" IS NULL");

        // If dry-run, output result
        if ($this->isDry()) {
            $count = $query->setSelect('COUNT(*)')->execute()->value();
        } else {
            // Only delete versioned table, not base
            $delete = $query->toDelete();
            $delete->setDelete("\"{$baseVersionedTable}\"");
            $delete->execute();
            $count = DB::affected_rows();
        }

        // Log output
        if ($count) {
            $prefix = $this->isDry() ? '(dry): ' : '';
            $this->message("{$prefix}Cleared {$count} rows from {$baseVersionedTable} for deleted records");
        }
    }

    /**
     * @param string $class
     */
    public function deleteOrphanedVersions(string $class): void
    {
        // E.g. `SiteTree`
        $baseTable = DataObject::getSchema()->tableName($class);
        $baseVersionedTable = "{$baseTable}_Versions";

        foreach (ClassInfo::dataClassesFor($class) as $subclass) {
            // Skip base record
            $subTable = DataObject::getSchema()->tableName($subclass);
            if ($subTable === $baseTable) {
                continue;
            }
            $versionedTable = "{$subTable}_Versions";

            $query = SQLSelect::create()
                ->setFrom("\"{$versionedTable}\"")
                ->addLeftJoin(
                    $baseVersionedTable,
                    <<<JOIN
"{$versionedTable}"."RecordID" = "{$baseVersionedTable}"."RecordID" AND
"{$versionedTable}"."Version" = "{$baseVersionedTable}"."Version"
JOIN

                )
                ->addWhere("\"{$baseVersionedTable}\".\"ID\" IS NULL");

            // If dry-run, output result
            if ($this->isDry()) {
                $count = $query->setSelect('COUNT(*)')->execute()->value();
            } else {
                // Only delete versioned table, not base
                $delete = $query->toDelete();
                $delete->setDelete("\"{$versionedTable}\"");
                $delete->execute();
                $count = DB::affected_rows();
            }

            // Log output
            if ($count) {
                $prefix = $this->isDry() ? '(dry): ' : '';
                $this->message("{$prefix}Cleared {$count} rows from {$versionedTable}");
            }
        }
    }

    /**
     * @return int
     */
    public function getKeepVersions(): int
    {
        return (int)$this->keepVersions ?: self::DEFAULT_KEEP_VERSIONS;
    }

    /**
     * @param int $keepVersions
     * @return $this
     */
    public function setKeepVersions(int $keepVersions): self
    {
        $this->keepVersions = $keepVersions;
        return $this;
    }

    /**
     * @return bool
     */
    public function isDry(): bool
    {
        return $this->dry;
    }

    /**
     * @param bool $dry
     * @return $this
     */
    public function setDry(bool $dry): self
    {
        $this->dry = $dry;
        return $this;
    }

    /**
     * @return bool
     */
    public function isFast(): bool
    {
        return $this->fast;
    }

    /**
     * @param bool $fast
     * @return $this
     */
    public function setFast(bool $fast): self
    {
        $this->fast = $fast;
        return $this;
    }

    /**
     * Get draft records in list
     *
     * @param string $class
     * @return Generator|DataObject[]
     */
    protected function getDraftObjects(string $class)
    {
        // Batch all queries into groups of 100 records
        $allRecords = Versioned::get_by_stage($class, Versioned::DRAFT);
        $count = $allRecords->count();
        $batches = (int)($count / 100) + 1;
        for ($batch = 0; $batch < $batches; $batch++) {
            $batchRecords = $allRecords->where([
                'MOD("ID", ?) = ?' => [
                    $batches,
                    $batch,
                ],
            ]);
            foreach ($batchRecords as $record) {
                yield $record;
            }

            // Flush records
            gc_collect_cycles();
        }
    }
}
