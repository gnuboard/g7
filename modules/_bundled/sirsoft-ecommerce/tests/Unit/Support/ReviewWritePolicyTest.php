<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Modules\Sirsoft\Ecommerce\Support\ReviewWritePolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 리뷰 작성 기한 정책 SSoT 검증
 *
 * 같은 규칙이 서비스(저장 가능 여부)와 리소스(화면 표시)에 각각 구현돼 있어
 * 조회 방식과 기본값 출처가 서로 달랐다. 특히 서비스 쪽 config 폴백은 모듈 config
 * 네임스페이스를 잘못 참조해(`ecommerce.*`) 항상 null 로 해석되고 있었다.
 *
 * @effects review_deadline_uses_single_policy_source
 */
class ReviewWritePolicyTest extends ModuleTestCase
{
    /**
     * 모듈 config 네임스페이스가 실제로 해석된다. (기존 폴백은 null 이었다)
     */
    public function test_module_config_namespace_resolves(): void
    {
        $this->assertNull(config('ecommerce.review.write_deadline_days'));
        $this->assertNotNull(config('sirsoft-ecommerce.review.write_deadline_days'));
    }

    /**
     * 설정 미지정 시 config 기본값을 사용한다.
     */
    public function test_deadline_days_falls_back_to_module_config(): void
    {
        $this->assertSame(
            (int) config('sirsoft-ecommerce.review.write_deadline_days'),
            ReviewWritePolicy::deadlineDays()
        );
    }

    /**
     * 기한 내 구매확정은 통과한다.
     */
    public function test_deadline_not_passed_within_period(): void
    {
        $this->assertFalse(ReviewWritePolicy::isDeadlinePassed(now()->subDay()));
    }

    /**
     * 기한을 넘긴 구매확정은 차단된다.
     */
    public function test_deadline_passed_after_period(): void
    {
        $days = ReviewWritePolicy::deadlineDays();

        $this->assertTrue(ReviewWritePolicy::isDeadlinePassed(now()->subDays($days + 1)));
    }

    /**
     * 미확정(null)은 기한 판정 대상이 아니다.
     */
    public function test_null_confirmed_at_is_not_deadline_passed(): void
    {
        $this->assertFalse(ReviewWritePolicy::isDeadlinePassed(null));
    }

    /**
     * 판정이 인자를 변형하지 않는다. (copy 누락 시 호출자의 시각이 밀린다)
     */
    public function test_confirmed_at_is_not_mutated(): void
    {
        $confirmedAt = now()->subDay();
        $snapshot = $confirmedAt->copy();

        ReviewWritePolicy::isDeadlinePassed($confirmedAt);

        $this->assertTrue($snapshot->equalTo($confirmedAt));
    }
}
