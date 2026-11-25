<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueSource;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;

/**
 * 쿠폰 발급 알림 발화기 (#952)
 *
 * 발급 1건마다 그 쿠폰 전용 훅(`sirsoft-ecommerce.coupon.notify_issued.{쿠폰ID}`)을 한 번 발화한다. 그 훅은 쿠폰의 알림 종류
 * (`coupon_issued.{쿠폰ID}`)가 구독하므로, 발송 채널·문구·수신동의 판정은 알림 템플릿 시스템과 데이터 리스너가 맡는다.
 * 회원이 직접 받은 다운로드 쿠폰과 알림을 끈 쿠폰은 발화하지 않는다.
 */
class CouponIssueNotifier
{
    /**
     * 발급 건의 알림 훅을 발화합니다.
     *
     * @param  CouponIssue  $issue  발급 건
     * @param  CouponIssueSource  $source  발급 경로
     * @return int 발화한 수 (0 또는 1)
     */
    public function notify(CouponIssue $issue, CouponIssueSource $source): int
    {
        if ($source === CouponIssueSource::DOWNLOAD) {
            return 0;
        }

        $coupon = $issue->relationLoaded('coupon') ? $issue->coupon : $issue->coupon()->first();
        if (! $coupon instanceof Coupon || ! $coupon->notify_enabled || $coupon->issue_method === CouponIssueMethod::DOWNLOAD) {
            return 0;
        }

        $user = $issue->relationLoaded('user') ? $issue->user : $issue->user()->first();
        if (! $user instanceof User) {
            return 0;
        }

        HookManager::doAction($coupon->notificationHook(), $user, $issue, $source->value);

        return 1;
    }
}
