<?php

namespace Tests\Feature\User;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 회원 수정 요청에 실린 확장 소유 필드의 코어 계약을 검증합니다.
 *
 * 회원 수정 화면은 코어 회원 저장 API 하나만 호출하고, 확장은 코어 훅으로 자기 필드를 검증·저장한다.
 * - 구독 확장이 없으면 확장 키는 검증된 데이터에 들어오지 않아 무시되고, 코어 저장은 그대로 성공한다.
 * - 구독 확장이 있으면 검증 규칙 필터로 키가 들어오고, `core.user.filter_update_data` 로 걷어낸 키는
 *   users 에 쓰이지 않으며, `core.user.after_update` 의 원본 데이터로는 그대로 전달된다.
 * - 관리자 수정(`core.user.update_validation_rules`)과 본인 프로필 수정
 *   (`core.user.update_profile_validation_rules`) 두 경로가 같은 계약을 따른다.
 */
class UserUpdateExtensionFieldContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 코어 회원 수정 권한을 가진 관리자와 그 역할을 만듭니다.
     *
     * @return array{0: User, 1: Role} 관리자와 관리자 역할
     */
    private function createAdmin(): array
    {
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.update'],
            [
                'name' => ['ko' => 'core.users.update', 'en' => 'core.users.update'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => PermissionType::Admin,
            ]
        );

        $role = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $admin = User::factory()->create();
        $admin->roles()->attach($role->id, ['assigned_at' => now()]);

        return [$admin->fresh(), $role];
    }

    /**
     * 확장 소유 필드(`ext_field`)를 받아들이는 가짜 확장 구독을 등록합니다.
     *
     * @param  array<string, mixed>  $received  after_update 가 받은 원본 데이터를 담을 참조
     * @param  array<string, mixed>  $coreData  filter_update_data 를 지난 코어 데이터를 담을 참조
     * @return void 반환값 없음
     */
    private function registerFakeExtension(array &$received, array &$coreData): void
    {
        $addRule = fn (array $rules) => $rules + ['ext_field' => ['nullable', 'string', 'in:alpha,beta']];
        HookManager::addFilter('core.user.update_validation_rules', $addRule);
        HookManager::addFilter('core.user.update_profile_validation_rules', $addRule);

        HookManager::addFilter('core.user.filter_update_data', function (array $data) use (&$coreData) {
            unset($data['ext_field']);
            $coreData = $data;

            return $data;
        }, 999);

        HookManager::addAction('core.user.after_update', function (User $user, array $originalData) use (&$received) {
            $received = $originalData;
        });
    }

    /**
     * 구독 확장이 없을 때 관리자 회원 수정에 실린 확장 키가 무시되고 코어 저장은 그대로 성공하는지 확인한다.
     *
     * @scenario entry_point=admin_update, extension_state=absent
     *
     * @effects unknown_extension_keys_ignored_without_subscriber, core_save_unaffected
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_ignores_extension_keys_without_subscriber(): void
    {
        [$admin, $role] = $this->createAdmin();
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/users/{$target->uuid}", [
            'name' => '바뀐 이름',
            'email' => $target->email,
            'role_ids' => [$role->id],
            'ecommerce_preferred_currency' => 'USD',
            'ecommerce_preferred_shipping_country' => 'US',
        ]);

        $response->assertOk();
        $this->assertSame('바뀐 이름', $target->fresh()->name);
        $this->assertArrayNotHasKey('ecommerce_preferred_currency', $target->fresh()->getAttributes());
    }

    /**
     * 구독 확장이 없을 때 본인 프로필 수정에 실린 확장 키가 무시되고 코어 저장은 그대로 성공하는지 확인한다.
     *
     * @scenario entry_point=profile_update, extension_state=absent
     *
     * @effects unknown_extension_keys_ignored_without_subscriber, core_save_unaffected
     *
     * @return void 반환값 없음
     */
    public function test_profile_update_ignores_extension_keys_without_subscriber(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/me', [
            'name' => '내 새 이름',
            'email' => $user->email,
            'ecommerce_preferred_currency' => 'USD',
        ]);

        $response->assertOk();
        $this->assertSame('내 새 이름', $user->fresh()->name);
    }

    /**
     * 구독 확장이 있을 때 관리자 수정의 확장 값이 코어 쓰기에서 빠지고 after_update 원본 데이터로만 전달되는지 확인한다.
     *
     * @scenario entry_point=admin_update, extension_state=subscribed
     *
     * @effects stripped_key_not_passed_to_core_write, after_update_receives_extension_value, core_save_unaffected
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_passes_extension_value_to_after_update_only(): void
    {
        [$admin, $role] = $this->createAdmin();
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $received = [];
        $coreData = [];
        $this->registerFakeExtension($received, $coreData);

        $response = $this->putJson("/api/admin/users/{$target->uuid}", [
            'name' => '바뀐 이름',
            'email' => $target->email,
            'role_ids' => [$role->id],
            'ext_field' => 'alpha',
        ]);

        $response->assertOk();
        $this->assertSame('바뀐 이름', $target->fresh()->name);
        $this->assertArrayNotHasKey('ext_field', $coreData);
        $this->assertSame('alpha', $received['ext_field'] ?? null);
    }

    /**
     * 본인 프로필 수정도 같은 계약(필드 키 오류, 코어 쓰기 분리, after_update 전달)을 따르는지 확인한다.
     *
     * @scenario entry_point=profile_update, extension_state=subscribed
     *
     * @effects stripped_key_not_passed_to_core_write, after_update_receives_extension_value, extension_rule_error_on_field_key
     *
     * @return void 반환값 없음
     */
    public function test_profile_update_follows_same_contract(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $received = [];
        $coreData = [];
        $this->registerFakeExtension($received, $coreData);

        $this->putJson('/api/me', [
            'name' => '내 이름',
            'email' => $user->email,
            'ext_field' => 'gamma',
        ])->assertStatus(422)->assertJsonValidationErrors(['ext_field']);

        $this->putJson('/api/me', [
            'name' => '내 이름',
            'email' => $user->email,
            'ext_field' => 'beta',
        ])->assertOk();

        $this->assertArrayNotHasKey('ext_field', $coreData);
        $this->assertSame('beta', $received['ext_field'] ?? null);
    }
}
