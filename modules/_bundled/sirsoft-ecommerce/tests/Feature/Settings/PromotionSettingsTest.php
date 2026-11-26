<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Settings;

use App\Models\FieldDefinition;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쇼핑몰 설정 쿠폰 탭
 */
class PromotionSettingsTest extends ModuleTestCase
{
    private const URL = '/api/modules/sirsoft-ecommerce/admin/settings';

    /** 테스트 설정 파일 */
    private const SETTINGS_FILE = 'framework/testing/modules/sirsoft-ecommerce/settings/promotion.json';

    private User $admin;

    /**
     * 설정 권한 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ko');
        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update']);
    }

    /**
     * 설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $file = storage_path(self::SETTINGS_FILE);
        if (file_exists($file)) {
            unlink($file);
        }
        parent::tearDown();
    }

    /**
     * 회원 항목을 만듭니다.
     *
     * @param  string  $key  키
     * @param  string  $type  유형
     * @param  bool  $enabled  켬 여부
     * @return FieldDefinition 항목
     */
    private function field(string $key, string $type = 'date', bool $enabled = true): FieldDefinition
    {
        return FieldDefinition::query()->create([
            'field_set' => 'core.user',
            'key' => $key,
            'source_type' => 'custom',
            'source_identifier' => 'custom',
            'type' => $type,
            'storage' => 'value',
            'name' => ['ko' => $key, 'en' => $key],
            'enabled' => $enabled,
            'sort_order' => 900,
        ]);
    }

    /**
     * 설정 응답 `_meta` 에 생일 항목 후보가 실린다 — 날짜 유형만, 꺼진 항목은 「(꺼짐)」 표시와 함께.
     *
     * @scenario case=promotion_candidates
     *
     * @effects promotion_birthday_candidates
     *
     * @return void 반환값 없음
     */
    public function test_meta_lists_date_candidates_only(): void
    {
        $this->field('i952_birth');
        $this->field('i952_off', 'date', false);
        $this->field('i952_text', 'text');

        $candidates = collect($this->actingAs($this->admin)->getJson(self::URL)->assertOk()->json('data._meta.birthday_field_candidates'));
        $values = $candidates->pluck('value')->all();

        $this->assertContains('i952_birth', $values);
        $this->assertContains('i952_off', $values);
        $this->assertNotContains('i952_text', $values);
        $this->assertStringContainsString('(', (string) $candidates->firstWhere('value', 'i952_off')['label']);
    }

    /**
     * 후보 밖 키·범위 밖 일수는 422, 후보 키와 범위 안 일수는 저장된다.
     *
     * @scenario case=promotion_save
     *
     * @effects promotion_settings_validated
     *
     * @return void 반환값 없음
     */
    public function test_save_validates_candidate_and_days(): void
    {
        $this->field('i952_birth');
        $this->field('i952_text', 'text');
        $body = fn (array $promotion) => ['_tab' => 'promotion', 'promotion' => array_merge([
            'birthday_field_key' => null,
            'coupon_expiry_notification_enabled' => true,
            'coupon_expiry_notification_days_before' => 7,
        ], $promotion)];

        $this->actingAs($this->admin)->putJson(self::URL, $body(['birthday_field_key' => 'i952_text']))
            ->assertStatus(422)->assertJsonValidationErrors('promotion.birthday_field_key');
        $this->actingAs($this->admin)->putJson(self::URL, $body(['coupon_expiry_notification_days_before' => 31]))
            ->assertStatus(422)->assertJsonValidationErrors('promotion.coupon_expiry_notification_days_before');
        $this->actingAs($this->admin)->putJson(self::URL, $body(['coupon_expiry_notification_days_before' => 0]))
            ->assertStatus(422);

        $this->actingAs($this->admin)->putJson(self::URL, $body(['birthday_field_key' => 'i952_birth', 'coupon_expiry_notification_days_before' => 3]))
            ->assertOk();

        $data = $this->actingAs($this->admin)->getJson(self::URL)->json('data.promotion');
        $this->assertSame('i952_birth', $data['birthday_field_key']);
        $this->assertSame(3, (int) $data['coupon_expiry_notification_days_before']);
    }
}
