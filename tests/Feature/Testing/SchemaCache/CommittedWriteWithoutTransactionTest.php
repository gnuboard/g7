<?php

namespace Tests\Feature\Testing\SchemaCache;

use Illuminate\Support\Facades\DB;
use Tests\Support\TestingSchema\TestingSchemaCache;
use Tests\TestCase;

/**
 * DB 트레이트 없는 테스트가 테스트 DB 에 쓰면 다음 테스트가 스키마·데이터를 다시 판정한다.
 *
 * 트레이트가 없으면 쓰기가 곧바로 커밋되고 판정도 받지 않는다. 같은 프로세스에서 이미 판정을 마친 뒤라면 다음 DB 테스트가
 * 「확인됨」으로 넘어가 남은 행과 충돌했다(2026-11-03 실측 — 실제 업그레이드 단계를 돌리는 테스트 뒤 역할·알림 정의 중복 키).
 * 이 클래스는 트레이트를 쓰지 않으므로 그 감시를 받는다. 판정 상태는 끝에 다시 판정하도록 남긴다(안전한 쪽).
 */
class CommittedWriteWithoutTransactionTest extends TestCase
{
    /**
     * 읽기만 하면 판정을 다시 요구하지 않는다.
     *
     * @scenario case=read_only
     *
     * @effects committed_write_without_transaction_marks_dirty
     *
     * @return void 반환값 없음
     */
    public function test_read_only_query_keeps_verified_state(): void
    {
        $saved = TestingSchemaCache::stateForTesting();
        TestingSchemaCache::setStateForTesting(['verified' => true, 'dirty' => false] + $saved);

        try {
            DB::select('SELECT 1');

            $this->assertFalse(TestingSchemaCache::stateForTesting()['dirty']);
        } finally {
            TestingSchemaCache::markDirty();
        }
    }

    /**
     * 쓰기(DDL 포함)를 하면 다음 테스트 전에 다시 판정하게 표시한다.
     *
     * @scenario case=committed_write
     *
     * @effects committed_write_without_transaction_marks_dirty
     *
     * @return void 반환값 없음
     */
    public function test_committed_write_marks_dirty(): void
    {
        $saved = TestingSchemaCache::stateForTesting();
        TestingSchemaCache::setStateForTesting(['verified' => true, 'dirty' => false] + $saved);

        try {
            DB::statement('CREATE TEMPORARY TABLE zz_committed_write_probe (id INT)');

            $this->assertTrue(TestingSchemaCache::stateForTesting()['dirty']);
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS zz_committed_write_probe');
            TestingSchemaCache::markDirty();
        }
    }
}
