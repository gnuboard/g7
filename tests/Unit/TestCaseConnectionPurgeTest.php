<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TestCase 유휴 DB 연결 정리 테스트
 *
 * 트랜잭션 트레이트 없는 순수 TestCase 테스트의 DB 연결이 tearDown 에서 닫히는지
 * 검증합니다. 이 정리가 회귀하면 프로세스 내 테스트 수만큼 연결이 누적되어
 * DB 를 쓰는 테스트가 max_connections 를 넘는 시점부터 전부
 * 1040 Too many connections 로 실패합니다.
 */
class TestCaseConnectionPurgeTest extends TestCase
{
    #[Test]
    public function idle_connection_is_purged(): void
    {
        DB::select('select 1');
        $this->assertNotEmpty($this->app['db']->getConnections(), '쿼리 후 연결이 열려 있어야 합니다');

        $this->purgeIdleDatabaseConnections();

        $this->assertSame([], $this->app['db']->getConnections(), '유휴 연결은 모두 닫혀야 합니다');
    }

    #[Test]
    public function transacting_connection_survives_purge(): void
    {
        DB::beginTransaction();

        try {
            $this->purgeIdleDatabaseConnections();

            // 트랜잭션 진행 중 연결은 보존 — 트레이트의 롤백 콜백이 담당한다
            $this->assertArrayHasKey(
                DB::getDefaultConnection(),
                $this->app['db']->getConnections(),
                '트랜잭션 진행 중인 연결은 purge 대상이 아니어야 합니다',
            );
            $this->assertSame(1, DB::connection()->transactionLevel());
        } finally {
            DB::rollBack();
        }
    }
}
