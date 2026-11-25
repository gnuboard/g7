<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Console;

use App\Extension\HookManager;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue\Concerns\CreatesAutoIssueCoupons;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 만료 임박 알림 명령 (#952 P8)
 */
class NotifyExpiringCouponsCommandTest extends ModuleTestCase
{
    use CreatesAutoIssueCoupons;

    /** 테스트 설정 파일 */
    private const SETTINGS_FILE = 'framework/testing/modules/sirsoft-ecommerce/settings/promotion.json';

    /**
     * 시각 고정을 풀고 설정 파일을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $file = storage_path(self::SETTINGS_FILE);
        if (file_exists($file)) {
            unlink($file);
        }
        parent::tearDown();
    }

    /**
     * 쿠폰 탭 설정을 씁니다.
     *
     * @param  array<string, mixed>  $values  설정 값
     * @return void 반환값 없음
     */
    private function writePromotionSettings(array $values): void
    {
        app(EcommerceSettingsService::class)->setSetting('promotion', array_merge([
            'birthday_field_key' => null,
            'coupon_expiry_notification_enabled' => true,
            'coupon_expiry_notification_days_before' => 7,
        ], $values));
    }

    /**
     * 만료 임박 알림 — N일 안에 만료되는 사용가능 발급 건만 회원별로 한 번 알리고 기록한다. 사용·취소·이미 알린 건은 빠진다. 설정을 끄면 보내지 않는다.
     *
     * @scenario case=notify_expiring_coupons
     *
     * @effects coupon_expiry_notified_once
     *
     * @return void 반환값 없음
     */
    public function test_notify_expiring_coupons_window_and_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-06-01 03:00:00', 'UTC'));
        $this->writePromotionSettings(['coupon_expiry_notification_days_before' => 7]);
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $user = $this->createUser();
        $make = fn (string $expires, string $status = 'available', ?string $notified = null) => CouponIssue::query()->create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id, 'coupon_code' => 'AU-'.uniqid(), 'status' => $status,
            'issued_at' => now(), 'expired_at' => $expires, 'expiry_notified_at' => $notified,
        ]);
        $inWindow = $make('2027-06-05 00:00:00');
        $outOfWindow = $make('2027-07-30 00:00:00');
        $used = $make('2027-06-04 00:00:00', CouponIssueRecordStatus::USED->value);
        $cancelled = $make('2027-06-04 00:00:00', CouponIssueRecordStatus::CANCELLED->value);
        $already = $make('2027-06-03 00:00:00', 'available', '2027-05-30 00:00:00');

        $calls = [];
        $listener = function (...$args) use (&$calls) {
            $calls[] = $args;
        };
        HookManager::addAction('sirsoft-ecommerce.coupon.notify_expiring', $listener, 5);

        try {
            $this->artisan('sirsoft-ecommerce:notify-expiring-coupons')->assertSuccessful();
            $this->artisan('sirsoft-ecommerce:notify-expiring-coupons')->assertSuccessful();
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.coupon.notify_expiring', $listener);
        }

        $this->assertCount(1, $calls, '같은 건을 두 번 알리지 않는다');
        $this->assertSame(1, $calls[0][1]);
        $this->assertNotNull($inWindow->fresh()->expiry_notified_at);
        $this->assertNull($outOfWindow->fresh()->expiry_notified_at);
        $this->assertNull($used->fresh()->expiry_notified_at);
        $this->assertNull($cancelled->fresh()->expiry_notified_at, '발급 취소된 건은 알리지 않는다');

        // 설정을 끄면 보내지 않는다
        $make('2027-06-06 00:00:00');
        $this->writePromotionSettings(['coupon_expiry_notification_enabled' => false]);
        $calls = [];
        HookManager::addAction('sirsoft-ecommerce.coupon.notify_expiring', $listener, 5);
        try {
            $this->artisan('sirsoft-ecommerce:notify-expiring-coupons')->assertSuccessful();
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.coupon.notify_expiring', $listener);
        }
        $this->assertCount(0, $calls);
        $this->assertNotNull($already->fresh()->expiry_notified_at);
    }

    /**
     * 달력에 없는 날짜(`--date=2027-02-30`)는 다음 달로 넘기지 않고 실패한다.
     *
     * @scenario case=notify_expiring_coupons_impossible_date
     *
     * @effects command_rejects_impossible_date
     *
     * @return void 반환값 없음
     */
    public function test_impossible_date_is_rejected(): void
    {
        $this->artisan('sirsoft-ecommerce:notify-expiring-coupons', ['--date' => '2027-02-30', '--dry-run' => true])->assertFailed();
    }
}
