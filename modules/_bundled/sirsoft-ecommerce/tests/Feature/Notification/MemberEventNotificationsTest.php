<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Notification;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\DTO\MileageAdminDeductDto;
use Modules\Sirsoft\Ecommerce\DTO\MileageAdminEarnDto;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원 사건 알림 4종 — 마일리지 적립·차감, 주문 부분 취소, 리뷰 답글
 */
class MemberEventNotificationsTest extends ModuleTestCase
{
    /**
     * 마일리지 KRW 규칙을 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ko');
        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);
        app(EcommerceSettingsService::class)->setSetting('mileage.currency_rules', [
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
        ]);
    }

    /**
     * 훅 호출 인수를 모으며 콜백을 실행합니다.
     *
     * @param  string  $hook  훅 이름
     * @param  callable  $callback  실행할 코드
     * @return array<int, array> 호출마다의 인수
     */
    private function capture(string $hook, callable $callback): array
    {
        $calls = [];
        $listener = function (...$args) use (&$calls) {
            $calls[] = $args;
        };
        HookManager::addAction($hook, $listener, 5);

        try {
            $callback();
        } finally {
            HookManager::removeAction($hook, $listener);
        }

        return $calls;
    }

    /**
     * 알림 데이터를 뽑습니다.
     *
     * @param  string  $type  알림 종류
     * @param  array  $args  훅 인수
     * @return array 추출 결과
     */
    private function extract(string $type, array $args): array
    {
        return app(EcommerceNotificationDataListener::class)->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            $type,
            $args,
        );
    }

    /**
     * 관리자 지급은 적립 알림 훅을 이번 금액으로 발화하고, 데이터에 사유·금액·잔액이 실린다.
     *
     * @scenario case=mileage_earned_admin
     *
     * @effects mileage_earned_notified
     *
     * @return void 반환값 없음
     */
    public function test_admin_earn_fires_mileage_earned(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.mileage.manage']);
        $member = $this->createUser();
        $this->actingAs($admin);

        $calls = $this->capture('sirsoft-ecommerce.mileage.after_earn', fn () => app(UserMileageService::class)->adminEarn(
            $member->id,
            new MileageAdminEarnDto(amount: 1500, currency: 'KRW', grantedBy: $admin->id, useDefaultExpiry: false),
        ));

        $this->assertCount(1, $calls);
        $this->assertSame(1500.0, (float) $calls[0][1]);
        $this->assertSame('admin', $calls[0][4]);

        $data = $this->extract('mileage_earned', $calls[0])['data'];
        $this->assertSame('1,500', $data['amount']);
        $this->assertSame('1,500', $data['balance']);
        $this->assertNotSame('', $data['reason_label']);
    }

    /**
     * 가입 적립도 적립 알림을 발화한다.
     *
     * @scenario case=mileage_earned_signup
     *
     * @effects mileage_earned_notified
     *
     * @return void 반환값 없음
     */
    public function test_signup_earn_fires_mileage_earned(): void
    {
        app(EcommerceSettingsService::class)->setSetting('mileage.signup_earn_enabled', true);
        app(EcommerceSettingsService::class)->setSetting('mileage.signup_earn_amount', 700);
        $member = $this->createUser();

        $calls = $this->capture('sirsoft-ecommerce.mileage.after_earn', fn () => app(UserMileageService::class)->grantSignupMileage($member->id));

        $this->assertCount(1, $calls);
        $this->assertSame(700.0, (float) $calls[0][1]);
        $this->assertSame('signup', $calls[0][4]);
    }

    /**
     * 구매확정 적립도 적립 알림을 발화하고(사유 purchase), 같은 옵션의 목표 적립액이 늘어 증액될 때는 늘어난 만큼만 알린다.
     *
     * @scenario case=mileage_earned_purchase
     *
     * @effects mileage_earned_notified
     *
     * @return void 반환값 없음
     */
    public function test_purchase_earn_fires_mileage_earned_with_increment_only(): void
    {
        $member = $this->createUser();
        $order = Order::factory()->create(['user_id' => $member->id, 'currency' => 'KRW']);
        $option = OrderOption::factory()->forOrder($order)->create(['quantity' => 1, 'subtotal_earned_points_amount' => 700]);
        $service = app(UserMileageService::class);

        $first = $this->capture('sirsoft-ecommerce.mileage.after_earn', fn () => $service->earnForOrderOption($order, $option, MileageTransactionTypeEnum::PURCHASE_EARN));
        $this->assertCount(1, $first);
        $this->assertSame(700.0, (float) $first[0][1]);
        $this->assertSame('purchase', $first[0][4]);

        $option->update(['subtotal_earned_points_amount' => 1000]);
        $second = $this->capture('sirsoft-ecommerce.mileage.after_earn', fn () => $service->earnForOrderOption($order, $option->fresh(), MileageTransactionTypeEnum::PURCHASE_EARN));
        $this->assertCount(1, $second);
        $this->assertSame(300.0, (float) $second[0][1], '증액 경로는 증액분만 알린다');
        $this->assertSame(1000.0, (float) $second[0][3], '잔액은 누적액');

        // 다시 확정해도(델타 0) 알리지 않는다
        $third = $this->capture('sirsoft-ecommerce.mileage.after_earn', fn () => $service->earnForOrderOption($order, $option->fresh(), MileageTransactionTypeEnum::PURCHASE_EARN));
        $this->assertCount(0, $third);
    }

    /**
     * 탈퇴 회원에게 쌓이는 적립(즉시 소멸 대상)은 적립 알림을 보내지 않는다.
     *
     * @scenario case=mileage_earned_withdrawn
     *
     * @effects mileage_earned_not_notified_for_forfeit
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_member_earn_is_not_notified(): void
    {
        app(EcommerceSettingsService::class)->setSetting('mileage.signup_earn_enabled', true);
        app(EcommerceSettingsService::class)->setSetting('mileage.signup_earn_amount', 700);
        $member = $this->createUser();
        $member->forceFill(['status' => UserStatus::Withdrawn->value])->save();

        $calls = $this->capture('sirsoft-ecommerce.mileage.after_earn', fn () => app(UserMileageService::class)->grantSignupMileage($member->id));

        $this->assertCount(0, $calls);
    }

    /**
     * 관리자 차감은 차감 알림 훅을 양수 금액·메모와 함께 발화한다.
     *
     * @scenario case=mileage_deducted
     *
     * @effects mileage_deducted_notified
     *
     * @return void 반환값 없음
     */
    public function test_admin_deduct_fires_mileage_deducted(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.mileage.manage']);
        $member = $this->createUser();
        $this->actingAs($admin);
        $service = app(UserMileageService::class);
        $service->adminEarn($member->id, new MileageAdminEarnDto(amount: 2000, currency: 'KRW', grantedBy: $admin->id, useDefaultExpiry: false));

        $calls = $this->capture('sirsoft-ecommerce.mileage.after_admin_deduct', fn () => $service->adminDeduct(
            $member->id,
            new MileageAdminDeductDto(amount: 500, currency: 'KRW', grantedBy: $admin->id, memo: '정정'),
        ));

        $this->assertCount(1, $calls);
        $data = $this->extract('mileage_deducted', $calls[0])['data'];
        $this->assertSame('500', $data['amount']);
        $this->assertSame('1,500', $data['balance']);
        $this->assertSame('정정', $data['memo']);
    }

    /**
     * 부분 취소 알림 — 회원 주문은 회원에게, 비회원 주문은 주문서 연락처(guest_recipient)로 간다.
     *
     * @scenario case=order_partially_cancelled
     *
     * @effects order_partially_cancelled_recipient
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_recipient_for_member_and_guest(): void
    {
        $member = $this->createUser();
        $memberOrder = Order::factory()->forUser($member)->create();
        $guestOrder = Order::factory()->forGuest()->create();

        $memberResult = $this->extract('order_partially_cancelled', [$memberOrder->fresh(), ['cancel_type' => 'partial'], [], null]);
        $guestResult = $this->extract('order_partially_cancelled', [$guestOrder->fresh(), ['cancel_type' => 'partial'], [], null]);

        $this->assertSame($member->id, $memberResult['context']['trigger_user_id']);
        $this->assertArrayNotHasKey('guest_recipient', $memberResult['context']);
        $this->assertTrue($guestResult['context']['is_guest_order']);
        $this->assertArrayHasKey('guest_recipient', $guestResult['context']);
        $this->assertSame($memberOrder->order_number, $memberResult['data']['order_number']);
    }

    /**
     * 리뷰 답글 알림은 첫 등록일 때만, 작성자가 회원일 때만 보낸다. 답글을 고치면 다시 보내지 않는다.
     *
     * @scenario case=review_replied
     *
     * @effects review_replied_first_only
     *
     * @return void 반환값 없음
     */
    public function test_review_replied_only_on_first_reply(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.reviews.update']);
        $this->actingAs($admin);
        $review = ProductReview::factory()->create();
        $service = app(ProductReviewService::class);

        $calls = $this->capture('sirsoft-ecommerce.product-review.after_reply', function () use ($service, $review, $admin) {
            $service->saveReply($review, $admin->id, ['reply_content' => '감사합니다']);
            $service->saveReply($review->fresh(), $admin->id, ['reply_content' => '고친 답글']);
        });

        $this->assertCount(2, $calls);
        $this->assertTrue($calls[0][1]);
        $this->assertFalse($calls[1][1]);

        $first = $this->extract('review_replied', $calls[0]);
        $this->assertSame((int) $review->user_id, (int) $first['context']['trigger_user_id']);
        $this->assertSame([], $this->extract('review_replied', $calls[1])['data'], '수정은 보내지 않는다');

        // 비회원(작성자 없음) 리뷰는 보내지 않는다
        $guestReview = ProductReview::factory()->create(['user_id' => null]);
        $this->assertSame([], $this->extract('review_replied', [$guestReview, true])['data']);
    }
}
