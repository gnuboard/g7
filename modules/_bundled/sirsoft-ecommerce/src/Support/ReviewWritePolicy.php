<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use Carbon\CarbonInterface;

/**
 * 리뷰 작성 기한 정책 헬퍼
 *
 * "구매확정 후 N일까지 리뷰 작성 가능" 판정을 한 곳으로 모은다.
 *
 * 기존에는 `ProductReviewService::canWrite` 와 `OrderOptionResource::isReviewDeadlinePassed`
 * 가 같은 규칙을 각자 구현했다. 조회 방식(주입 서비스 vs `module_setting` 헬퍼)과 기본값
 * 출처(config vs 리터럴)가 서로 달라, 한쪽만 바뀌면 화면 표시와 실제 저장 가능 여부가
 * 어긋난다. 게다가 서비스 쪽 config 폴백은 네임스페이스가 잘못돼(`ecommerce.*`) 항상
 * null 로 해석되고 있었다 — 모듈 config 는 `sirsoft-ecommerce.*` 로 등록된다.
 */
class ReviewWritePolicy
{
    /**
     * 기한 미설정 시 사용할 기본 일수.
     */
    public const DEFAULT_DEADLINE_DAYS = 90;

    /**
     * 리뷰 작성 가능 기간(일)을 반환합니다.
     *
     * 0 이하는 "무제한" 을 뜻하므로 그대로 돌려주고 판정 측에서 해석한다.
     *
     * @return int 작성 가능 일수 (0 이하면 무제한)
     */
    public static function deadlineDays(): int
    {
        $fallback = (int) config(
            'sirsoft-ecommerce.review.write_deadline_days',
            self::DEFAULT_DEADLINE_DAYS
        );

        $days = module_setting('sirsoft-ecommerce', 'review_settings.write_deadline_days', $fallback);

        return is_numeric($days) ? (int) $days : $fallback;
    }

    /**
     * 구매확정 시점 기준으로 작성 기한이 지났는지 판정합니다.
     *
     * @param  CarbonInterface|null  $confirmedAt  구매확정 일시 (미확정이면 null)
     * @return bool 기한이 지났으면 true (미확정·무제한이면 false)
     */
    public static function isDeadlinePassed(?CarbonInterface $confirmedAt): bool
    {
        $days = self::deadlineDays();

        if (! $confirmedAt || $days <= 0) {
            return false;
        }

        return now()->gt($confirmedAt->copy()->addDays($days));
    }
}
