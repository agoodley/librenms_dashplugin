<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Support\ErrorText;
use PHPUnit\Framework\TestCase;

class ErrorTextTest extends TestCase
{
    public function testADatabaseErrorIsLoggedWithoutItsSqlAndBindings(): void
    {
        if (! class_exists(\Illuminate\Database\QueryException::class)) {
            // Laravel's own class in LibreNMS: its message holds the SQL with the bindings (a run's whole output)
            eval('namespace Illuminate\Database; class QueryException extends \PDOException {
                public function __construct(string $sql, array $bindings, \PDOException $previous) {
                    parent::__construct($previous->getMessage() . " (SQL: $sql " . implode(",", $bindings) . ")", 0, $previous);
                    $this->errorInfo = $previous->errorInfo;
                }
            }');
        }
        $pdo = new \PDOException('SQLSTATE[08S01]: Communication link failure: 1153 Got a packet bigger than \'max_allowed_packet\' bytes');
        $pdo->errorInfo = ['08S01', 1153, "Got a packet bigger than 'max_allowed_packet' bytes"];
        $query = new \Illuminate\Database\QueryException('update ansible_runs set output = ?', ['DEVICE OUTPUT running-config secret 5 abc'], $pdo);

        $text = ErrorText::forLog($query);
        $this->assertSame("database error 08S01/1153: Got a packet bigger than 'max_allowed_packet' bytes", $text);
        $this->assertStringNotContainsString('DEVICE OUTPUT', $text);
        $this->assertStringNotContainsString('update ansible_runs', $text);
    }

    public function testOtherErrors(): void
    {
        $this->assertSame('RuntimeException: the runner is down', ErrorText::forLog(new \RuntimeException("the runner\nis down")));
        $this->assertSame('database error 42', ErrorText::forLog(new \PDOException('SQLSTATE: value secret-thing', 42)), 'a bare PDO error: its code only');
    }
}
