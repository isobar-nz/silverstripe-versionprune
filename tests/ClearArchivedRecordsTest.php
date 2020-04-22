<?php

namespace IsobarNZ\VersionPrune\Tests\Tasks;

use IsobarNZ\VersionPrune\Tasks\ClearArchivedRecords;
use IsobarNZ\VersionPrune\Tests\Tasks\ClearArchivedRecordsTest\BaseRecord;
use IsobarNZ\VersionPrune\Tests\Tasks\ClearArchivedRecordsTest\ChildRecord;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;

class ClearArchivedRecordsTest extends SapphireTest
{
    protected static $fixture_file = 'ClearArchivedRecordsTest.yml';

    protected static $extra_dataobjects = [
        BaseRecord::class,
        ChildRecord::class,
    ];

    public function testDeleteOldVersions()
    {
        // Page A has 3 versions
        $this->assertPageHasCountVersions(10, 3);
        $this->assertPageHasVersion(10, 1);

        ob_start();
        $task = ClearArchivedRecords::create();
        $task->setKeepVersions(2);
        $task->deleteOldVersions(BaseRecord::class);
        ob_end_clean();

        $this->assertPageHasCountVersions(10, 2);
        $this->assertPageHasVersion(10, 2);
        $this->assertPageHasVersion(10, 3);
    }

    public function testDeleteArchivedVersions()
    {
        // page Z does not have a record
        $this->assertPageHasVersion(111, 1);

        ob_start();
        $task = ClearArchivedRecords::create();
        $task->deleteArchivedVersions(BaseRecord::class);
        ob_end_clean();

        $this->assertPageHasCountVersions(111, 0);
    }

    /**
     * Test that subclass _Versions delete if orphaned in base _Versions table
     */
    public function testDeleteOrphanedVersions()
    {
        $this->assertPageHasCountVersions(10, 4, 'CART_ChildRecord_Versions');
        $this->assertPageHasCountVersions(111, 1, 'CART_ChildRecord_Versions');
        $this->assertPageHasCountVersions(112, 1, 'CART_ChildRecord_Versions');

        // Note: We are only comparing to parent version table; not cleaning from base record
        ob_start();
        $task = ClearArchivedRecords::create();
        $task->deleteOrphanedVersions(BaseRecord::class);
        ob_end_clean();

        $this->assertPageHasCountVersions(10, 3, 'CART_ChildRecord_Versions');
        $this->assertPageHasCountVersions(111, 1, 'CART_ChildRecord_Versions'); // pagez_orphan not targetted for deletion
        $this->assertPageHasCountVersions(112, 0, 'CART_ChildRecord_Versions');
    }

    /**
     * Test full end to end process
     */
    public function testDeleteEverything()
    {
        ob_start();
        $task = ClearArchivedRecords::create();
        $task->setKeepVersions(2);
        $task->flushClass(BaseRecord::class);
        ob_end_clean();

        // Base records
        $this->assertPageHasCountVersions(10, 2, 'CART_BaseRecord_Versions');
        $this->assertPageHasCountVersions(111, 0, 'CART_BaseRecord_Versions');
        $this->assertPageHasCountVersions(112, 0, 'CART_BaseRecord_Versions');

        // Subclass records
        $this->assertPageHasCountVersions(10, 2, 'CART_ChildRecord_Versions');
        $this->assertPageHasCountVersions(111, 0, 'CART_ChildRecord_Versions');
        $this->assertPageHasCountVersions(112, 0, 'CART_ChildRecord_Versions');
    }

    /**
     * @param int    $pageID
     * @param int    $count
     * @param string $table
     */
    protected function assertPageHasCountVersions(int $pageID, int $count, $table = "CART_BaseRecord_Versions"): void
    {
        $this->assertEquals(
            $count,
            DB::prepared_query(
                <<<SQL
SELECT COUNT(*) FROM "{$table}"
WHERE "RecordID" = ?
SQL
                ,
                [$pageID]
            )->value()
        );
    }

    /**
     * Page has a specific version
     *
     * @param        $pageID
     * @param        $versionID
     * @param string $table
     */
    protected function assertPageHasVersion($pageID, $versionID, $table = 'CART_BaseRecord_Versions'): void
    {
        $this->assertEquals(
            1,
            DB::prepared_query(
                <<<SQL
SELECT COUNT(*) FROM "{$table}"
WHERE "RecordID" = ?
AND "Version" = ?
SQL
                ,
                [$pageID, $versionID]
            )->value()
        );
    }
}
