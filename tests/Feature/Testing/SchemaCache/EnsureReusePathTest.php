<?php

namespace Tests\Feature\Testing\SchemaCache;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestingSchema\RecordStore;
use Tests\Support\TestingSchema\TestingSchemaCache;
use Tests\TestCase;

/**
 * 기록이 있을 때의 재사용 경로 — 전체 재생 없이 3초 안에 판정을 끝내고 RefreshDatabase 의 migrated 를 세운다.
 *
 * 트랜잭션을 쓰지 않고 스키마 판정만 받는다(needsTestingSchema). 직접 다시 판정을 부르므로 끝나면 다음 테스트가 다시 판정하게 한다.
 */
class EnsureReusePathTest extends TestCase
{
    /**
     * 트랜잭션 트레이트 없이도 테스트 스키마 판정을 받습니다.
     *
     * @return bool 늘 true
     */
    protected function needsTestingSchema(): bool
    {
        return true;
    }

    /**
     * 다음 테스트가 다시 판정하게 표시합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TestingSchemaCache::markDirty();
        parent::tearDown();
    }

    /**
     * 같은 프로세스에서 상태를 처음으로 돌려 다시 판정해도(기록·스키마 일치) 3초 안에 RESET 으로 끝난다.
     *
     * @scenario case=reuse
     *
     * @effects reuse_path_completes_quickly
     *
     * @return void 반환값 없음
     */
    public function test_reuse_path_is_fast_and_sets_migrated(): void
    {
        $db = DB::connection()->getDatabaseName();
        $this->assertNotNull(RecordStore::read($db), 'setUp 판정 뒤 기록이 있어야 한다');
        $fresh = getenv(TestingSchemaCache::FORCE_ENV);
        putenv(TestingSchemaCache::FORCE_ENV);

        try {
            TestingSchemaCache::setStateForTesting([]);
            RefreshDatabaseState::$migrated = false;

            $started = microtime(true);
            TestingSchemaCache::ensure($this, 'any');
            $elapsed = microtime(true) - $started;
        } finally {
            if ($fresh !== false) {
                putenv(TestingSchemaCache::FORCE_ENV.'='.$fresh);
            }
        }

        $this->assertLessThan(3.0, $elapsed, "재사용 판정이 {$elapsed}초 걸렸다");
        $this->assertTrue(RefreshDatabaseState::$migrated);
        $this->assertTrue(TestingSchemaCache::stateForTesting()['verified']);
        $this->assertSame('none', TestingSchemaCache::stateForTesting()['profile']);
    }
}
