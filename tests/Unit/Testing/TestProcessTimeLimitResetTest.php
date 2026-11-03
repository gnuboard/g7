<?php

namespace Tests\Unit\Testing;

use Tests\TestCase;

/**
 * 테스트가 걸어 둔 실행 시간 상한은 다음 테스트로 넘어가지 않는다.
 *
 * 시험 대상 코드의 `set_time_limit(300)` 이 PHPUnit 프로세스에 남으면, Windows PHP 는 그때부터 실제 경과 시간을 재서
 * 같은 프로세스의 뒤 테스트가 300초를 넘기는 순간 실행 전체가 치명 오류로 끝났다(2026-11-03 실측 — 정적 게시
 * 서비스 시험 뒤 코어 묶음 중단). 두 메서드는 선언 순서대로 돈다.
 */
class TestProcessTimeLimitResetTest extends TestCase
{
    /**
     * 시험 대상 코드처럼 실행 시간 상한을 건다.
     *
     * @scenario case=limit_set_by_code_under_test
     *
     * @effects time_limit_not_inherited_by_next_test
     *
     * @return void 반환값 없음
     */
    public function test_code_under_test_sets_time_limit(): void
    {
        set_time_limit(300);

        $this->assertSame('300', ini_get('max_execution_time'));
    }

    /**
     * 다음 테스트는 CLI 기본값(무제한)에서 시작한다.
     *
     * @scenario case=limit_set_by_code_under_test
     *
     * @effects time_limit_not_inherited_by_next_test
     *
     * @return void 반환값 없음
     */
    public function test_next_test_starts_without_time_limit(): void
    {
        $this->assertSame('0', ini_get('max_execution_time'));
    }
}
