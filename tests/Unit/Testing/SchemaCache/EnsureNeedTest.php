<?php

namespace Tests\Unit\Testing\SchemaCache;

use RuntimeException;
use Tests\PureTestCase;
use Tests\Support\TestingSchema\TestingSchemaCache;
use Tests\TestCase;

/**
 * 테스트마다 스키마 판정이 필요한지 — 이미 맞춘 상태면 아무것도 하지 않고(빠른 길), 필요할 때만 다시 판정한다.
 *
 * 다시 판정하는 경우: 처음, 판정 뒤 DDL 암묵 커밋 감지(dirty), 프레임워크가 migrated 를 내림, 다른 프로세스가 데이터 상태를
 * 바꿈(상태 토큰), 요구 프로필(시드·시드 없음)이 현재와 다름. 전체 재생에 실패한 프로세스는 즉시 예외로 멈춘다.
 */
class EnsureNeedTest extends PureTestCase
{
    /** 시험 전 정적 상태 */
    private array $saved;

    /**
     * 정적 상태를 보관합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = TestingSchemaCache::stateForTesting();
    }

    /**
     * 정적 상태를 되돌립니다(같은 프로세스의 다른 DB 테스트가 영향받지 않게).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TestingSchemaCache::setStateForTesting($this->saved);
        parent::tearDown();
    }

    /**
     * 판정을 마친 상태를 만듭니다.
     *
     * @param  string  $profile  현재 데이터 프로필
     * @param  bool  $dirty  dirty 여부
     * @return void 반환값 없음
     */
    private function verified(string $profile = 'none', bool $dirty = false): void
    {
        TestingSchemaCache::setStateForTesting(['verified' => true, 'dirty' => $dirty, 'profile' => $profile, 'stateToken' => 'tok', 'database' => 'zz_testing']);
    }

    /**
     * 판정을 마쳤고 요구가 any 면 다시 판정하지 않는다(시드 프로필이어도).
     *
     * @scenario case=verified_any
     *
     * @effects verified_schema_skips_decision
     *
     * @return void 반환값 없음
     */
    public function test_verified_any_is_noop(): void
    {
        $this->verified('none');
        $this->assertFalse(TestingSchemaCache::needsDecision('any', true, 'tok'));

        $this->verified('seed:X');
        $this->assertFalse(TestingSchemaCache::needsDecision('any', true, 'tok'));
    }

    /**
     * 처음·dirty·migrated 해제·상태 토큰 변경이면 다시 판정한다.
     *
     * @scenario case=state_changed
     *
     * @effects changed_state_triggers_decision
     *
     * @return void 반환값 없음
     */
    public function test_first_dirty_unmigrated_or_foreign_state_redecides(): void
    {
        TestingSchemaCache::setStateForTesting([]);
        $this->assertTrue(TestingSchemaCache::needsDecision('any', true, null), '처음');

        $this->verified('none', dirty: true);
        $this->assertTrue(TestingSchemaCache::needsDecision('any', true, 'tok'), 'DDL 암묵 커밋 감지');

        $this->verified();
        $this->assertTrue(TestingSchemaCache::needsDecision('any', false, 'tok'), '프레임워크가 migrated 를 내림');
        $this->assertTrue(TestingSchemaCache::needsDecision('any', true, 'other'), '자식 프로세스가 데이터 상태를 바꿈');

        TestingSchemaCache::markDirty();
        $this->assertTrue(TestingSchemaCache::stateForTesting()['dirty']);
    }

    /**
     * 시드 요구는 같은 시드 프로필일 때만 건너뛰고, none-exact 는 시드가 남아 있으면 다시 판정한다.
     *
     * @scenario case=profile_mismatch
     *
     * @effects profile_requirement_enforced
     *
     * @return void 반환값 없음
     */
    public function test_profile_requirements(): void
    {
        $this->verified('none');
        $this->assertTrue(TestingSchemaCache::needsDecision('seed:X', true, 'tok'), '시드 요구 + 현재 시드 없음');
        $this->assertFalse(TestingSchemaCache::needsDecision('none-exact', true, 'tok'));

        $this->verified('seed:X');
        $this->assertFalse(TestingSchemaCache::needsDecision('seed:X', true, 'tok'));
        $this->assertTrue(TestingSchemaCache::needsDecision('seed:Y', true, 'tok'), '다른 시더');
        $this->assertTrue(TestingSchemaCache::needsDecision('none-exact', true, 'tok'), 'none-exact + 현재 시드');
    }

    /**
     * 전체 재생에 실패한 프로세스는 판정 없이 첫 실패를 원인으로 담아 바로 멈춘다.
     *
     * @scenario case=fatal
     *
     * @effects failed_fresh_stops_process_immediately
     *
     * @return void 반환값 없음
     */
    public function test_fatal_rethrows_immediately(): void
    {
        $cause = new RuntimeException('migrate:fresh 실패');
        TestingSchemaCache::setStateForTesting(['fatal' => $cause]);

        try {
            TestingSchemaCache::ensure($this->createStub(TestCase::class), 'any');
            $this->fail('예외가 나야 한다');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e->getPrevious());
        }
    }
}
