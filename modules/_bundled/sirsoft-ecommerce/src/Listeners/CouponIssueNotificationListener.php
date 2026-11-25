<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueSource;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Services\CouponIssueNotifier;

/**
 * 쿠폰 발급 알림 리스너 (#952)
 *
 * 자동발급(가입·첫구매·생일)과 관리자 직접발급 1건마다 그 쿠폰의 발급 알림 훅을 발화한다. 큐로 돈다(기본) — 발급을 막지 않는다.
 */
class CouponIssueNotificationListener implements HookListenerInterface
{
    /**
     * @param  CouponIssueNotifier  $notifier  쿠폰 발급 알림 발화기
     */
    public function __construct(
        protected CouponIssueNotifier $notifier,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.coupon.after_auto_issue' => ['method' => 'handleAutoIssue', 'priority' => 30],
            'sirsoft-ecommerce.coupon.after_direct_issue' => ['method' => 'handleDirectIssue', 'priority' => 30],
        ];
    }

    /**
     * 기본 핸들러 (method 매핑 사용 — 직접 호출되지 않음)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 자동발급 후 알림 (coupon.after_auto_issue)
     *
     * @param  mixed  $issue  발급 건
     * @param  mixed  $source  발급 경로 값
     * @return void 반환값 없음
     */
    public function handleAutoIssue(mixed $issue = null, mixed $source = null): void
    {
        $resolved = CouponIssueSource::tryFrom((string) $source);
        if ($issue instanceof CouponIssue && $resolved !== null) {
            $this->send($issue, $resolved);
        }
    }

    /**
     * 직접발급 후 알림 (coupon.after_direct_issue — 인수: 쿠폰, 발급 건, 회원 ID)
     *
     * @param  mixed  $coupon  쿠폰
     * @param  mixed  $issue  발급 건
     * @return void 반환값 없음
     */
    public function handleDirectIssue(mixed $coupon = null, mixed $issue = null): void
    {
        if ($issue instanceof CouponIssue) {
            $this->send($issue, CouponIssueSource::DIRECT);
        }
    }

    /**
     * 알림 훅을 발화합니다. 실패해도 발급에 영향을 주지 않는다.
     *
     * @param  CouponIssue  $issue  발급 건
     * @param  CouponIssueSource  $source  발급 경로
     * @return void 반환값 없음
     */
    private function send(CouponIssue $issue, CouponIssueSource $source): void
    {
        try {
            $this->notifier->notify($issue, $source);
        } catch (\Throwable $e) {
            Log::warning('쿠폰 발급 알림 발화 실패', ['issue_id' => $issue->id, 'message' => $e->getMessage()]);
        }
    }
}
