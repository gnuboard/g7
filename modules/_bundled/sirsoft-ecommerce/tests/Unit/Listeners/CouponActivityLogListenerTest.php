<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Enums\ActivityLogType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Listeners\CouponActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CouponActivityLogListener 테스트
 *
 * 쿠폰 활동 로그 리스너의 모든 훅 메서드를 검증합니다.
 * - 로그 기록 (4개): handleAfterCreate, handleAfterUpdate, handleAfterDelete, handleAfterBulkStatus
 */
class CouponActivityLogListenerTest extends ModuleTestCase
{
    private CouponActivityLogListener $listener;

    private $logChannel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('request', Request::create('/api/admin/sirsoft-ecommerce/test'));
        $this->listener = app(CouponActivityLogListener::class);
        $this->logChannel = Mockery::mock(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
        Log::shouldReceive('error')->byDefault();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks
    // ═══════════════════════════════════════════

    public function test_getSubscribedHooks_returns_all_hooks(): void
    {
        $hooks = CouponActivityLogListener::getSubscribedHooks();

        $this->assertCount(4, $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.coupon.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.coupon.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.coupon.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.coupon.after_bulk_status', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.coupon.before_update', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.coupon.before_bulk_status', $hooks);
    }

    // ═══════════════════════════════════════════
    // 이벤트 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleAfterCreate_logs_activity(): void
    {
        $coupon = $this->createCouponMock(10, 'Summer Sale');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'coupon.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.coupon_create'
                    && $context['description_params']['coupon_id'] === 10
                    && isset($context['loggable'])
                    // Coupon.name 은 AsUnicodeJson cast — 다국어 배열
                    && $context['properties']['name'] === ['ko' => 'Summer Sale', 'en' => 'Summer Sale'];
            });

        $this->listener->handleAfterCreate($coupon, ['name' => 'Summer Sale']);
    }

    public function test_handleAfterUpdate_logs_activity_with_changes(): void
    {
        $coupon = $this->createCouponMock(10, 'Summer Sale Updated');

        // 스냅샷을 인수로 직접 전달 (Service가 before 훅 이후 캡처하여 after 훅에 전달)
        $snapshot = ['id' => 10, 'name' => 'Summer Sale'];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'coupon.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.coupon_update'
                    && $context['description_params']['coupon_id'] === 10
                    && isset($context['loggable'])
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleAfterUpdate($coupon, ['name' => 'Summer Sale Updated'], $snapshot);
    }

    public function test_handleAfterUpdate_without_snapshot(): void
    {
        $coupon = $this->createCouponMock(99, 'No Snapshot');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'coupon.update'
                    && $context['changes'] === null;
            });

        // 스냅샷 없이 호출 (기본값 null)
        $this->listener->handleAfterUpdate($coupon, ['name' => 'No Snapshot']);
    }

    public function test_handleAfterDelete_logs_activity(): void
    {
        $couponId = 15;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($couponId) {
                return $action === 'coupon.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.coupon_delete'
                    && $context['description_params']['coupon_id'] === $couponId
                    && $context['properties']['coupon_id'] === $couponId
                    && ! isset($context['loggable']);
            });

        $this->listener->handleAfterDelete($couponId);
    }

    public function test_handleAfterBulkStatus_logs_activity_with_backed_enum(): void
    {
        $coupons = collect([
            Coupon::create(['name' => ['ko' => '쿠폰A', 'en' => 'CouponA'], 'discount_type' => CouponDiscountType::FIXED, 'discount_value' => 1000, 'target_type' => CouponTargetType::PRODUCT_AMOUNT]),
            Coupon::create(['name' => ['ko' => '쿠폰B', 'en' => 'CouponB'], 'discount_type' => CouponDiscountType::FIXED, 'discount_value' => 2000, 'target_type' => CouponTargetType::PRODUCT_AMOUNT]),
            Coupon::create(['name' => ['ko' => '쿠폰C', 'en' => 'CouponC'], 'discount_type' => CouponDiscountType::FIXED, 'discount_value' => 3000, 'target_type' => CouponTargetType::PRODUCT_AMOUNT]),
        ]);
        $ids = $coupons->pluck('id')->toArray();

        $loggedActions = [];
        $this->logChannel->shouldReceive('info')
            ->times(3)
            ->withArgs(function ($action, $context) use (&$loggedActions, $ids) {
                if ($action !== 'coupon.bulk_status') {
                    return false;
                }
                $loggedActions[] = $context;

                return $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.coupon_bulk_status'
                    && isset($context['loggable'])
                    && in_array($context['properties']['coupon_id'], $ids)
                    && $context['properties']['issue_status'] === 'issuing';
            });

        $this->listener->handleAfterBulkStatus($ids, CouponIssueStatus::ISSUING, 3);

        $this->assertCount(3, $loggedActions);
    }

    public function test_handleAfterBulkStatus_logs_activity_with_non_backed_enum(): void
    {
        $coupons = collect([
            Coupon::create(['name' => ['ko' => '쿠폰D', 'en' => 'CouponD'], 'discount_type' => CouponDiscountType::FIXED, 'discount_value' => 1000, 'target_type' => CouponTargetType::PRODUCT_AMOUNT]),
            Coupon::create(['name' => ['ko' => '쿠폰E', 'en' => 'CouponE'], 'discount_type' => CouponDiscountType::FIXED, 'discount_value' => 2000, 'target_type' => CouponTargetType::PRODUCT_AMOUNT]),
        ]);
        $ids = $coupons->pluck('id')->toArray();

        $loggedActions = [];
        $this->logChannel->shouldReceive('info')
            ->times(2)
            ->withArgs(function ($action, $context) use (&$loggedActions, $ids) {
                if ($action !== 'coupon.bulk_status') {
                    return false;
                }
                $loggedActions[] = $context;

                return isset($context['loggable'])
                    && in_array($context['properties']['coupon_id'], $ids)
                    && $context['properties']['issue_status'] === 'suspended';
            });

        $this->listener->handleAfterBulkStatus($ids, 'suspended', 2);

        $this->assertCount(2, $loggedActions);
    }

    // ═══════════════════════════════════════════
    // 에러 핸들링 테스트
    // ═══════════════════════════════════════════

    public function test_logActivity_catches_exception_and_logs_error(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->andThrow(new \Exception('Channel unavailable'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Failed to record activity log'
                    && $context['action'] === 'coupon.delete'
                    && $context['error'] === 'Channel unavailable';
            });

        $this->listener->handleAfterDelete(1);
    }

    // ═══════════════════════════════════════════
    // handle 기본 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handle_does_nothing(): void
    {
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════
    // 헬퍼 메서드
    // ═══════════════════════════════════════════

    private function createCouponMock(int $id, ?string $name = null): Coupon
    {
        $coupon = Mockery::mock(Coupon::class)->makePartial();
        // Coupon.name 은 AsUnicodeJson cast (다국어 JSON) — raw JSON 문자열로 세팅
        $nameArray = $name !== null ? ['ko' => $name, 'en' => $name] : null;
        $coupon->setRawAttributes([
            'id' => $id,
            'name' => $nameArray !== null ? json_encode($nameArray, JSON_UNESCAPED_UNICODE) : null,
        ], false);
        $coupon->shouldReceive('getKey')->andReturn($id);
        $coupon->shouldReceive('getMorphClass')->andReturn('coupon');

        return $coupon;
    }
}
