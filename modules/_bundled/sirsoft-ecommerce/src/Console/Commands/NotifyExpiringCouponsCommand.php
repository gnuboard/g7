<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;

/**
 * 쿠폰 만료 임박 알림 커맨드 (#952)
 *
 * 사용가능 상태이고 만료일이 사이트 오늘 ~ N일 안이며 아직 알리지 않은 발급 건을 회원별로 묶어 알림 훅
 * (`sirsoft-ecommerce.coupon.notify_expiring`)을 1회 발화하고, 발화한 건에 `expiry_notified_at` 을 기록한다(발급 건당 1회).
 *
 * @example php artisan sirsoft-ecommerce:notify-expiring-coupons
 * @example php artisan sirsoft-ecommerce:notify-expiring-coupons --date=2026-12-01 --dry-run
 */
class NotifyExpiringCouponsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:notify-expiring-coupons
                            {--date= : 기준 날짜 (사이트 시간대, YYYY-MM-DD — 없으면 오늘)}
                            {--dry-run : 실제 발송 없이 대상만 확인}
                            {--limit=5000 : 한 번에 처리할 최대 발급 건수}';

    /**
     * @var string
     */
    protected $description = '곧 만료되는 쿠폰을 회원에게 알립니다.';

    /**
     * @param  CouponIssueRepositoryInterface  $issueRepository  쿠폰 발급 내역 저장소
     */
    public function __construct(
        protected CouponIssueRepositoryInterface $issueRepository,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드 실행
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        if (! module_setting('sirsoft-ecommerce', 'promotion.coupon_expiry_notification_enabled', true)) {
            $this->info(__('sirsoft-ecommerce::messages.console.notify_expiring_coupons.notification_disabled'));

            return Command::SUCCESS;
        }

        $daysBefore = max((int) config('sirsoft-ecommerce.limits.coupon_expiry_notification_days_before_min', 1), min((int) config('sirsoft-ecommerce.limits.coupon_expiry_notification_days_before_max', 30), (int) module_setting('sirsoft-ecommerce', 'promotion.coupon_expiry_notification_days_before', 7)));
        $timezone = TimezoneHelper::getSiteTimezone();
        $dateOption = $this->option('date');

        try {
            $today = is_string($dateOption) && $dateOption !== ''
                ? CarbonImmutable::createFromFormat('Y-m-d', $dateOption, $timezone)->startOfDay()
                : CarbonImmutable::now($timezone)->startOfDay();
        } catch (\Throwable) {
            $this->error(__('sirsoft-ecommerce::messages.console.issue_birthday_coupons.invalid_date'));

            return Command::FAILURE;
        }

        // 저장값은 UTC — 사이트 하루 경계를 UTC 로 바꿔 비교한다. 이미 지난 순간은 대상이 아니다.
        $from = max($today->utc(), CarbonImmutable::now('UTC'));
        $to = $today->addDays($daysBefore)->endOfDay()->utc();

        $issues = $this->issueRepository->getExpiringUnnotifiedIssues($from, $to, max(1, (int) $this->option('limit')));
        $groups = $issues->filter(fn ($issue) => $issue->user !== null)->groupBy('user_id');

        if ($groups->isEmpty()) {
            $this->info(__('sirsoft-ecommerce::messages.console.notify_expiring_coupons.no_targets'));

            return Command::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $this->info(__('sirsoft-ecommerce::messages.console.notify_expiring_coupons.targets', ['count' => $groups->count()]).($isDryRun ? ' [DRY-RUN]' : ''));

        if ($isDryRun) {
            return Command::SUCCESS;
        }

        $sent = 0;
        foreach ($groups as $userIssues) {
            $user = $userIssues->first()->user;
            $earliest = $userIssues->min('expired_at');
            $names = $userIssues
                ->map(fn ($issue) => $issue->coupon?->getLocalizedName())
                ->filter()
                ->unique()
                ->values()
                ->all();

            HookManager::doAction(
                'sirsoft-ecommerce.coupon.notify_expiring',
                $user,
                $userIssues->count(),
                // 알림 변수 날짜는 사이트 날짜 — UTC 날짜면 저녁 만료분이 하루 당겨져 안내된다
                TimezoneHelper::toSiteDateString($earliest) ?? '',
                $names,
            );

            $this->issueRepository->markExpiryNotified($userIssues->pluck('id')->map(fn ($id) => (int) $id)->all());
            $sent++;
        }

        $this->info(__('sirsoft-ecommerce::messages.console.notify_expiring_coupons.done', ['count' => $sent]));

        return Command::SUCCESS;
    }
}
