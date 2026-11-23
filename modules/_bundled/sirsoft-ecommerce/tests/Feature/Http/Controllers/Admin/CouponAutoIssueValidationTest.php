<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 발급방법·발급조건·첫구매 시점·발급 알림 검증 (#952 §4.5)
 */
class CouponAutoIssueValidationTest extends ModuleTestCase
{
    /** 쿠폰 API */
    private const URL = '/api/modules/sirsoft-ecommerce/admin/promotion-coupons';

    private User $admin;

    /**
     * 쿠폰 권한 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ko');
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.create',
            'sirsoft-ecommerce.promotion-coupon.update',
        ]);
    }

    /**
     * 유효한 쿠폰 입력을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮을 값
     * @return array<string, mixed> 입력
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => ['ko' => 'i952 검증 쿠폰', 'en' => 'i952 validation'],
            'target_type' => CouponTargetType::ORDER_AMOUNT->value,
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_method' => CouponIssueMethod::AUTO->value,
            'issue_condition' => CouponIssueCondition::SIGNUP->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'per_user_limit' => 0,
            'valid_type' => 'days_from_issue',
            'valid_days' => 30,
            'is_combinable' => false,
            'target_scope' => CouponTargetScope::ALL->value,
        ], $overrides);
    }

    /**
     * 자동발급 + 수동 조건, 직접/다운로드 + 자동 조건은 422 다.
     *
     * @scenario case=method_condition_pair
     *
     * @effects coupon_issue_rule_rejected
     *
     * @return void 반환값 없음
     */
    public function test_method_condition_mismatch_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['issue_condition' => 'manual']))
            ->assertStatus(422)->assertJsonValidationErrors('issue_condition');
        $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['issue_method' => 'direct', 'issue_condition' => 'signup']))
            ->assertStatus(422)->assertJsonValidationErrors('issue_condition');
        $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['issue_method' => 'download', 'issue_condition' => 'birthday']))
            ->assertStatus(422)->assertJsonValidationErrors('issue_condition');
    }

    /**
     * 첫구매 쿠폰은 시점이 없으면 구매확정으로 채우고, 목록 밖 시점은 422 다. 다른 조건이면 시점을 비운다.
     *
     * @scenario case=first_purchase_trigger
     *
     * @effects coupon_first_purchase_trigger_defaulted
     *
     * @return void 반환값 없음
     */
    public function test_first_purchase_trigger_default_and_enum(): void
    {
        $created = $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['issue_condition' => 'first_purchase']))->assertStatus(201);
        $this->assertSame('confirmed', Coupon::find($created->json('data.id'))->getRawOriginal('first_purchase_trigger_status'));

        $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['issue_condition' => 'first_purchase', 'first_purchase_trigger_status' => 'bogus']))
            ->assertStatus(422)->assertJsonValidationErrors('first_purchase_trigger_status');

        $signup = $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['first_purchase_trigger_status' => 'shipping']))->assertStatus(201);
        $this->assertNull(Coupon::find($signup->json('data.id'))->getRawOriginal('first_purchase_trigger_status'));
    }

    /**
     * 수정은 부분 갱신 — 조건만 첫구매로 바꾸고 시점을 비워 보내면 422, 시점만 보내면 저장된 조건을 승계해 통과한다.
     *
     * @scenario case=partial_update_inherits
     *
     * @effects coupon_issue_rule_inherits_stored
     *
     * @return void 반환값 없음
     */
    public function test_partial_update_inherits_stored_values(): void
    {
        $id = $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['issue_condition' => 'first_purchase']))->assertStatus(201)->json('data.id');

        $this->actingAs($this->admin)->putJson(self::URL."/{$id}", ['first_purchase_trigger_status' => null, 'per_user_limit' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('first_purchase_trigger_status');
        $this->actingAs($this->admin)->putJson(self::URL."/{$id}", ['first_purchase_trigger_status' => 'payment_complete', 'per_user_limit' => 0])
            ->assertOk();
        $this->assertSame('payment_complete', Coupon::find($id)->getRawOriginal('first_purchase_trigger_status'));
    }

    /**
     * 자동발급은 회원당 한도를 0 으로 고정하고, 다운로드 쿠폰은 발급 알림을 끈다.
     *
     * @scenario case=normalize
     *
     * @effects coupon_issue_rule_normalized
     *
     * @return void 반환값 없음
     */
    public function test_auto_limit_forced_zero_and_download_notify_off(): void
    {
        $auto = $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['per_user_limit' => 3]))->assertStatus(201)->json('data.id');
        $this->assertSame(0, (int) Coupon::find($auto)->per_user_limit);

        $download = $this->actingAs($this->admin)->postJson(self::URL, $this->payload([
            'issue_method' => 'download',
            'issue_condition' => 'manual',
            'per_user_limit' => 1,
            'notify_enabled' => true,
        ]))->assertStatus(201)->json('data.id');
        $this->assertFalse((bool) Coupon::find($download)->getRawOriginal('notify_enabled'));
    }

    /**
     * 발급 알림 칸은 불리언 표기를 받고, 켠 쿠폰은 응답에 알림 종류가 실린다.
     *
     * @scenario case=notify_fields
     *
     * @effects coupon_notify_fields_saved
     *
     * @return void 반환값 없음
     */
    public function test_notify_fields_are_saved_and_exposed_to_admin(): void
    {
        $response = $this->actingAs($this->admin)->postJson(self::URL, $this->payload([
            'notify_enabled' => '1',
            'notify_marketing_only' => 'true',
        ]))->assertStatus(201);

        $id = $response->json('data.id');
        $coupon = Coupon::find($id);
        $this->assertTrue((bool) $coupon->getRawOriginal('notify_enabled'));
        $this->assertTrue((bool) $coupon->getRawOriginal('notify_marketing_only'));

        $show = $this->actingAs($this->admin)->getJson(self::URL."/{$id}")->assertOk();
        $this->assertTrue($show->json('data.notify_enabled'));
        $this->assertSame('coupon_issued.'.$id, $show->json('data.notification_type'));
        $this->assertArrayHasKey('birthday_field_configured', $show->json('data'));

        $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['notify_enabled' => 'maybe']))
            ->assertStatus(422)->assertJsonValidationErrors('notify_enabled');
    }

    /**
     * §4.5 5~7 — 쿠폰 요청에 채널·문구를 실어 보내도 받지 않고(알림 문구는 알림 템플릿 API 로만 바뀐다),
     * 광고성 칸은 불리언만 받으며, 생일 항목이 지정되지 않아도 생일 쿠폰은 저장되고 응답이 그 사실을 알린다.
     *
     * @scenario case=notify_text_not_from_coupon_request
     *
     * @effects coupon_issue_rule_normalized
     *
     * @return void 반환값 없음
     */
    public function test_channel_text_ignored_marketing_boolean_and_birthday_without_field(): void
    {
        $id = $this->actingAs($this->admin)->postJson(self::URL, $this->payload([
            'notify_enabled' => true,
            'notify_channels' => ['mail', 'sms'],
            'notify_subject' => ['ko' => 'i952 요청에 실은 제목'],
            'notify_body' => ['ko' => 'i952 요청에 실은 본문'],
        ]))->assertStatus(201)->json('data.id');

        $definition = \App\Models\NotificationDefinition::query()->where('type', 'coupon_issued.'.$id)->first();
        $this->assertNotNull($definition);
        $templates = \App\Models\NotificationTemplate::query()->where('definition_id', $definition->id)->get()->keyBy('channel');
        $this->assertFalse((bool) $templates['mail']->is_active, '요청의 채널 목록으로 메일이 켜지지 않는다');
        $this->assertNotSame('i952 요청에 실은 제목', $templates['mail']->subject['ko'] ?? null);
        $this->assertArrayNotHasKey('notify_channels', Coupon::find($id)->getAttributes());

        $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['notify_marketing_only' => 'maybe']))
            ->assertStatus(422)->assertJsonValidationErrors('notify_marketing_only');

        $birthday = $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['issue_condition' => 'birthday']))->assertStatus(201)->json('data.id');
        $this->assertFalse($this->actingAs($this->admin)->getJson(self::URL."/{$birthday}")->assertOk()->json('data.birthday_field_configured'));
    }

    /**
     * 쿠폰 수정 활동 로그는 응답에서 숨긴 칸(발급 시점·발급 알림)도 이전 값을 그대로 남긴다 — 이전 값이 null 로 기록되던 결함(#952 실측).
     *
     * @scenario case=activity_log_old_values
     *
     * @effects coupon_activity_log_keeps_hidden_old_values
     *
     * @return void 반환값 없음
     */
    public function test_update_activity_log_keeps_old_values_of_hidden_fields(): void
    {
        $id = $this->actingAs($this->admin)->postJson(self::URL, $this->payload(['issue_condition' => 'first_purchase']))->assertStatus(201)->json('data.id');

        $this->actingAs($this->admin)->putJson(self::URL."/{$id}", ['first_purchase_trigger_status' => 'delivered', 'notify_enabled' => true, 'per_user_limit' => 0])->assertOk();

        $log = \App\Models\ActivityLog::query()->where('loggable_id', $id)->where('action', 'like', '%update%')->latest('id')->first();
        $this->assertNotNull($log);
        $changes = collect($log->changes)->keyBy('field');
        $this->assertSame('confirmed', $changes['first_purchase_trigger_status']['old']);
        $this->assertSame('delivered', $changes['first_purchase_trigger_status']['new']);
        $this->assertFalse($changes['notify_enabled']['old']);
        $this->assertTrue($changes['notify_enabled']['new']);
    }
}
