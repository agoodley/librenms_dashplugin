<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Data\SchemaCheck;
use PHPUnit\Framework\TestCase;

/**
 * The checks of the plugin's tables ask the database's catalogue once per table and process (for a minute), and only
 * remember what they found.
 */
class SchemaCheckTest extends TestCase
{
    /** @var string[] the catalogue's answers asked for */
    private array $asked = [];

    /** @var array<string, string[]> tables and their columns */
    private array $schema = ['ansible_runs' => ['id', 'status', 'Change_Id'], 'ansible_config' => ['name', 'value']];

    protected function setUp(): void
    {
        SchemaCheck::useCatalogue(function (string $what, string $table) {
            $this->asked[] = "$what $table";

            return $what === 'table' ? isset($this->schema[$table]) : ($this->schema[$table] ?? []);
        });
    }

    protected function tearDown(): void
    {
        SchemaCheck::useCatalogue(null);
    }

    public function testEachTableIsAskedOnceForAllItsChecks(): void
    {
        $this->assertTrue(SchemaCheck::hasColumns('ansible_runs', ['id', 'change_id']));
        $this->assertFalse(SchemaCheck::hasColumns('ansible_runs', ['id', 'proof']), 'a missing column');
        $this->assertTrue(SchemaCheck::hasTable('ansible_runs'), 'its columns tell it exists');
        $this->assertTrue(SchemaCheck::hasTable('ansible_config'));
        $this->assertTrue(SchemaCheck::hasTable('ansible_config'));
        $this->assertTrue(SchemaCheck::hasColumns('ansible_config', ['value']));
        $this->assertSame(['columns ansible_runs', 'table ansible_config', 'columns ansible_config'], $this->asked);
    }

    public function testWhatIsMissingIsAskedAgain(): void
    {
        $this->assertFalse(SchemaCheck::hasTable('ansible_changes'));
        $this->assertFalse(SchemaCheck::hasColumns('ansible_changes', ['id']));
        $this->schema['ansible_changes'] = ['id']; // ./lnms migrate ran meanwhile
        $this->assertTrue(SchemaCheck::hasTable('ansible_changes'));
        $this->assertTrue(SchemaCheck::hasColumns('ansible_changes', ['id']));
        $this->assertSame(['table ansible_changes', 'columns ansible_changes', 'table ansible_changes', 'columns ansible_changes'], $this->asked);
    }
}
