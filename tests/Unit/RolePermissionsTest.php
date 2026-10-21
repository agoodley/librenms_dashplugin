<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\ScopedRepository;
use Agoodley\LibrenmsTopology\Sharing\SavedView;
use Agoodley\LibrenmsTopology\Support\RoleData;
use PHPUnit\Framework\TestCase;

class RolePermissionsTest extends TestCase
{
    /**
     * A repository whose user's role may not view the given models.
     *
     * @param  string[]  $denied  model classes
     */
    private static function repository(array $denied): ScopedRepository
    {
        return new class(null, $denied) extends ScopedRepository
        {
            public int $checks = 0;

            public function __construct(mixed $user, private readonly array $denied)
            {
                parent::__construct($user);
            }

            protected function policyAllows(string $modelClass): bool
            {
                $this->checks++;

                return ! in_array($modelClass, $this->denied, true);
            }

            public function read(string $table): array
            {
                return $this->rows($table, [1], ['device_id']);
            }
        };
    }

    public function testDataARolePermissionHidesReadsAsEmptyAndIsNamed(): void
    {
        $repo = self::repository(['App\Models\BgpPeer', 'App\Models\Link']);

        $this->assertFalse($repo->allowsTable('bgpPeers'));
        $this->assertFalse($repo->allowsTable('bgpPeers_cbgp'), 'the prefix counters are BGP data too');
        $this->assertTrue($repo->allowsTable('ospf_nbrs'));
        $this->assertTrue($repo->allowsTable('vendor_ouis'), 'a table without a role permission of its own');
        $this->assertSame(['LLDP/CDP links', 'BGP sessions'], $repo->hiddenKinds(['links', 'bgpPeers', 'bgpPeers_cbgp', 'ospf_nbrs']));
        $this->assertSame([], $repo->read('bgpPeers'), 'no query for a table the role may not read');
        $this->assertSame(3, $repo->checks, 'one check per model and repository');
    }

    public function testATableReadWithAnAliasIsGuardedToo(): void
    {
        $repo = self::repository(['App\Models\Ipv4Address', 'App\Models\Pseudowire']);

        $this->assertFalse($repo->allowsTable('ipv4_addresses as a'));
        $this->assertFalse($repo->allowsTable(' pseudowires as l'));
        $this->assertTrue($repo->allowsTable('ipv6_addresses as a'));
        $this->assertTrue($repo->allowsTable(''), 'no table, nothing to hide');
    }

    public function testEveryPageNamesTablesWithAModel(): void
    {
        foreach (RoleData::PAGES as $page => $tables) {
            foreach ($tables as $table) {
                $this->assertArrayHasKey($table, ScopedRepository::TABLE_MODELS, "$page reads $table");
            }
        }
        $this->assertSame([], self::repository([])->hiddenKinds(array_merge(...array_values(RoleData::PAGES))), 'the built-in user role sees everything');
    }

    public function testTheSavedViewsPageNamesWhatTheMapsOfItsViewsHide(): void
    {
        foreach (array_keys(SavedView::PAGES) as $page) {
            $this->assertSame([], array_diff(RoleData::PAGES[$page], RoleData::PAGES['views']), "a saved view opens the $page map");
        }
    }
}
