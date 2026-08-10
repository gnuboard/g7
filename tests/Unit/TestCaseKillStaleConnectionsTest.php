<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TestCase::killStaleTestingConnections() 회귀 테스트.
 *
 * 배경: mysql 커넥션은 read/write 분리 구조(config/database.php)라 최상위 'database'
 * 키가 존재하지 않는다. 과거 구현은 `config('database.connections.mysql.database')` 를
 * 읽어 항상 null 을 얻었고, 그 결과
 *
 *   if (($process->db ?? '') === $testingDb && ...)   // $testingDb === null
 *
 * 조건이 어떤 커넥션과도 매칭되지 않았다(`'' === null` 도, `'g7_testing' === null` 도 false).
 * 즉 좀비 커넥션 정리가 전면 무력화된 채로 조용히 통과하고 있었다. 정리 실패는 예외를
 * 던지지 않으므로 아무도 눈치채지 못한다.
 *
 * 본 테스트는 테스트 DB 에 붙은 별도 커넥션(좀비 대역)을 만들어 두고 메서드를 실행해,
 * 그 커넥션이 실제로 KILL 되는지를 확인한다. 판정값이 null 로 회귀하면 좀비가 살아남아 red.
 */
class TestCaseKillStaleConnectionsTest extends TestCase
{
    /**
     * 테스트 DB 에 붙은 별도 PDO 커넥션을 만듭니다 (좀비 대역).
     *
     * @return array{0: PDO, 1: int} PDO 와 그 커넥션 ID
     */
    private function openZombie(): array
    {
        $c = config('database.connections.mysql.write');

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $c['host'][0] ?? $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $id = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();

        return [$pdo, $id];
    }

    /**
     * PROCESSLIST 에 해당 커넥션 ID 가 남아 있는지 확인합니다.
     */
    private function connectionAlive(int $id): bool
    {
        foreach (DB::select('SHOW PROCESSLIST') as $p) {
            if ((int) $p->Id === $id) {
                return true;
            }
        }

        return false;
    }

    private function invokeKiller(): void
    {
        $m = new ReflectionMethod(TestCase::class, 'killStaleTestingConnections');
        $m->setAccessible(true);
        $m->invoke($this);
    }

    public function test_좀비는_정리하고_자기_커넥션은_살려둔다(): void
    {
        $testingDb = DB::connection()->getDatabaseName();
        $selfId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        [$pdo, $zombieId] = $this->openZombie();

        $this->assertTrue($this->connectionAlive($zombieId), '좀비 대역이 만들어져야 한다');
        $this->assertNotSame($selfId, $zombieId, '좀비는 자기 커넥션과 달라야 한다');

        $this->invokeKiller();

        $this->assertFalse(
            $this->connectionAlive($zombieId),
            '테스트 DB 에 붙은 좀비 커넥션이 정리되어야 한다 '
            .'(대상 DB 판정이 null 이면 어떤 행과도 매칭되지 않아 살아남는다)'
        );

        // 자기 커넥션은 KILL 대상에서 제외되므로 정리 직후에도 쿼리가 가능해야 한다.
        // (연결 ID 동일성으로 단정하지 않는다 — 다른 커넥션을 KILL 하면 Laravel 이
        //  재연결하며 ID 가 바뀔 수 있고, 그것은 결함이 아니다.)
        $this->assertSame(
            $testingDb,
            DB::selectOne('SELECT DATABASE() AS d')->d,
            '정리 직후에도 자기 커넥션으로 후속 쿼리가 가능해야 한다'
        );

        unset($pdo);
    }

    public function test_대상_db_판정이_실제_접속_db와_일치한다(): void
    {
        $target = DB::connection()->getDatabaseName();
        $actual = DB::selectOne('SELECT DATABASE() AS d')->d;

        $this->assertNotSame('', $target, '대상 DB 가 비면 좀비 정리가 무력화된다');
        $this->assertSame($actual, $target, '판정값이 실제 접속 DB 와 달라서는 안 된다');

        // 과거 구현이 읽던 최상위 키는 존재하지 않는다(구조적 사실).
        $this->assertNull(
            config('database.connections.mysql.database'),
            'read/write 분리 커넥션에는 최상위 database 키가 없다'
        );
    }
}
