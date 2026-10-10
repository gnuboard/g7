<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 코어 회원 저장 한 번으로 결제 통화·배송국가까지 저장되는지 검증한다.
 *
 * 회원 수정 화면은 코어 회원 저장 API(`PUT /api/admin/users/{user}`, `PUT /api/me`)만 호출하고,
 * 이커머스가 코어 훅(검증 규칙 필터·`filter_update_data`·`after_update`)으로 자기 두 칸을
 * 같은 요청 안에서 검증·저장한다. 검증 오류는 필드 이름 키로 돌아와 그 칸 아래에 뜬다.
 */
class UserPreferenceCoreSaveTest extends ModuleTestCase
{
    /**
     * 해외배송을 켜고 배송 가능 국가(KR·US 활성, JP 비활성)를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.international_shipping_enabled', true);
        $settings->setSetting('shipping.available_countries', [
            ['code' => 'KR', 'name' => ['ko' => '대한민국'], 'is_active' => true],
            ['code' => 'US', 'name' => ['ko' => '미국'], 'is_active' => true],
            ['code' => 'JP', 'name' => ['ko' => '일본'], 'is_active' => false],
        ]);

        $this->createDefaultRoles();
    }

    /**
     * 유저 프로필 Repository 를 반환합니다.
     *
     * @return EcommerceUserProfileRepositoryInterface 유저 프로필 Repository
     */
    private function profiles(): EcommerceUserProfileRepositoryInterface
    {
        return app(EcommerceUserProfileRepositoryInterface::class);
    }

    /**
     * 코어 회원 수정 권한과 지정한 이커머스 권한을 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $ecommercePermissions  추가할 이커머스 권한
     * @return User 관리자
     */
    private function adminWith(array $ecommercePermissions = []): User
    {
        return $this->createAdminUser(array_merge(['core.users.update'], $ecommercePermissions));
    }

    /**
     * 관리자 회원 수정 요청 본문을 만듭니다(대상의 기존 역할을 그대로 보냄).
     *
     * @param  User  $target  수정 대상 회원
     * @param  array<string, mixed>  $extra  덧붙일 필드
     * @return array<string, mixed> 요청 본문
     */
    private function adminBody(User $target, array $extra = []): array
    {
        return array_merge([
            'name' => '바뀐 이름',
            'email' => $target->email,
            'role_ids' => [Role::where('identifier', 'user')->value('id')],
        ], $extra);
    }

    /**
     * 관리자가 회원 수정 저장 한 번으로 결제 통화·배송국가까지 저장하고, 두 값이 users 에 쓰이지 않으며
     * 변경 훅이 발화하는지 확인한다.
     *
     * @scenario entry_point=admin_update, field=currency
     *
     * @effects core_save_persists_currency, core_save_persists_shipping_country, ecommerce_keys_not_written_to_users, admin_change_fires_changed_hooks, admin_can_change_user_currency, admin_currency_change_logged, admin_country_change_fires_activity_log
     *
     * @return void 반환값 없음
     */
    public function test_admin_core_update_saves_currency_and_shipping_country(): void
    {
        $admin = $this->adminWith([
            'sirsoft-ecommerce.user-currency.manage',
            'sirsoft-ecommerce.user-shipping-country.manage',
        ]);
        $target = $this->createUser();
        Sanctum::actingAs($admin);

        $fired = [];
        HookManager::addAction('sirsoft-ecommerce.admin.user_currency.changed', function () use (&$fired) {
            $fired[] = 'currency';
        });
        HookManager::addAction('sirsoft-ecommerce.admin.user_shipping_country.changed', function () use (&$fired) {
            $fired[] = 'shipping_country';
        });

        $response = $this->putJson("/api/admin/users/{$target->uuid}", $this->adminBody($target, [
            'ecommerce_preferred_currency' => 'USD',
            'ecommerce_preferred_shipping_country' => 'us',
        ]));

        $response->assertOk();
        $this->assertSame('USD', $this->profiles()->getPreferredCurrency($target->id));
        $this->assertSame('US', $this->profiles()->getPreferredShippingCountry($target->id));
        $this->assertSame('바뀐 이름', $target->fresh()->name);
        $this->assertArrayNotHasKey('ecommerce_preferred_currency', $target->fresh()->getAttributes());
        $this->assertSame(['currency', 'shipping_country'], $fired);
    }

    /**
     * 미등록 통화를 보내면 422 와 함께 통화 필드 키로 오류가 돌아오고 코어 필드도 저장되지 않는지 확인한다.
     *
     * @scenario entry_point=admin_update, field=currency
     *
     * @effects invalid_value_error_on_field_key, core_fields_untouched_on_rejection, only_registered_currency_allowed
     *
     * @return void 반환값 없음
     */
    public function test_admin_core_update_rejects_unregistered_currency_on_field_key(): void
    {
        $admin = $this->adminWith(['sirsoft-ecommerce.user-currency.manage']);
        $target = $this->createUser();
        $originalName = $target->name;
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/users/{$target->uuid}", $this->adminBody($target, [
            'ecommerce_preferred_currency' => 'XYZ',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['ecommerce_preferred_currency']);
        $this->assertSame($originalName, $target->fresh()->name);
        $this->assertNull($this->profiles()->getPreferredCurrency($target->id));
    }

    /**
     * 비활성 배송국가를 보내면 422 와 함께 배송국가 필드 키로 오류가 돌아오는지 확인한다.
     *
     * @scenario entry_point=admin_update, field=shipping_country
     *
     * @effects invalid_value_error_on_field_key, core_fields_untouched_on_rejection, inactive_country_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_admin_core_update_rejects_inactive_country_on_field_key(): void
    {
        $admin = $this->adminWith(['sirsoft-ecommerce.user-shipping-country.manage']);
        $target = $this->createUser();
        $originalName = $target->name;
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/users/{$target->uuid}", $this->adminBody($target, [
            'ecommerce_preferred_shipping_country' => 'JP',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['ecommerce_preferred_shipping_country']);
        $this->assertSame($originalName, $target->fresh()->name);
    }

    /**
     * 이커머스 관리 권한이 없는 관리자가 값을 바꾸려 하면 두 필드 키로 오류가 돌아오고 아무것도 저장되지 않는지 확인한다.
     *
     * @scenario entry_point=admin_update, field=shipping_country
     *
     * @effects change_without_permission_rejected_on_field_key, core_fields_untouched_on_rejection
     *
     * @return void 반환값 없음
     */
    public function test_admin_without_ecommerce_permission_cannot_change_values(): void
    {
        $admin = $this->adminWith();
        $target = $this->createUser();
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/users/{$target->uuid}", $this->adminBody($target, [
            'ecommerce_preferred_currency' => 'USD',
            'ecommerce_preferred_shipping_country' => 'US',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors([
            'ecommerce_preferred_currency',
            'ecommerce_preferred_shipping_country',
        ]);
        $this->assertNull($this->profiles()->getPreferredCurrency($target->id));
        $this->assertNull($this->profiles()->getPreferredShippingCountry($target->id));
    }

    /**
     * 저장된 값을 그대로 보내면(지금은 고를 수 없는 값·권한 없음이어도) 코어 저장을 막지 않고 변경 훅도 발화하지 않는지 확인한다.
     *
     * @scenario entry_point=admin_update, field=currency
     *
     * @effects unchanged_value_does_not_block_core_save, unchanged_value_fires_no_changed_hook
     *
     * @return void 반환값 없음
     */
    public function test_unchanged_values_pass_even_when_no_longer_selectable_or_permitted(): void
    {
        // 저장된 값이 지금은 고를 수 없는 값(미등록 통화·비활성 국가)이고 관리자에게 이커머스 권한도 없다.
        // 폼은 조회 응답을 그대로 시드해 보내므로, 값이 그대로면 다른 칸의 저장을 막지 않아야 한다.
        $admin = $this->adminWith();
        $target = $this->createUser();
        $this->profiles()->setPreferredCurrency($target->id, 'XYZ');
        $this->profiles()->setPreferredShippingCountry($target->id, 'JP');
        Sanctum::actingAs($admin);

        $fired = false;
        HookManager::addAction('sirsoft-ecommerce.admin.user_currency.changed', function () use (&$fired) {
            $fired = true;
        });

        $response = $this->putJson("/api/admin/users/{$target->uuid}", $this->adminBody($target, [
            'ecommerce_preferred_currency' => 'XYZ',
            'ecommerce_preferred_shipping_country' => 'JP',
        ]));

        $response->assertOk();
        $this->assertSame('바뀐 이름', $target->fresh()->name);
        $this->assertSame('XYZ', $this->profiles()->getPreferredCurrency($target->id));
        $this->assertSame('JP', $this->profiles()->getPreferredShippingCountry($target->id));
        $this->assertFalse($fired, '값이 그대로인데 변경 훅이 발화했습니다.');
    }

    /**
     * 본인 프로필 저장 한 번으로 결제 통화·배송국가가 저장되고 관리자 변경 훅은 발화하지 않는지 확인한다.
     *
     * @scenario entry_point=profile_update, field=currency
     *
     * @effects core_save_persists_currency, core_save_persists_shipping_country, self_change_fires_no_admin_hook
     *
     * @return void 반환값 없음
     */
    public function test_profile_core_update_saves_currency_and_shipping_country(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $fired = false;
        HookManager::addAction('sirsoft-ecommerce.admin.user_currency.changed', function () use (&$fired) {
            $fired = true;
        });

        $response = $this->putJson('/api/me', [
            'name' => '내 새 이름',
            'email' => $user->email,
            'ecommerce_preferred_currency' => 'JPY',
            'ecommerce_preferred_shipping_country' => 'US',
        ]);

        $response->assertOk();
        $this->assertSame('JPY', $this->profiles()->getPreferredCurrency($user->id));
        $this->assertSame('US', $this->profiles()->getPreferredShippingCountry($user->id));
        $this->assertSame('내 새 이름', $user->fresh()->name);
        $this->assertFalse($fired, '본인 저장인데 관리자 변경 훅이 발화했습니다.');
    }

    /**
     * 본인 프로필 저장에서 잘못된 두 값이 각 필드 키로 422 를 받고 코어 필드도 저장되지 않는지 확인한다.
     *
     * @scenario entry_point=profile_update, field=shipping_country
     *
     * @effects invalid_value_error_on_field_key, core_fields_untouched_on_rejection
     *
     * @return void 반환값 없음
     */
    public function test_profile_core_update_rejects_invalid_values_on_field_keys(): void
    {
        $user = $this->createUser();
        $originalName = $user->name;
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/me', [
            'name' => '내 새 이름',
            'email' => $user->email,
            'ecommerce_preferred_currency' => 'XYZ',
            'ecommerce_preferred_shipping_country' => 'JP',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'ecommerce_preferred_currency',
            'ecommerce_preferred_shipping_country',
        ]);
        $this->assertSame($originalName, $user->fresh()->name);
    }
}
