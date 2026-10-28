<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 웹 단건 설정 저장(`PUT /api/admin/settings/{key}`)도 `.env` 가 정하는 키를 거부한다 (7.1.4 F33).
 *
 * 회귀 성격: 탭 저장은 `.env` 고정 키를 버리지만 단건 저장은 그대로 써서, 저장은 성공으로 보이고
 * 실제 동작은 `.env` 값을 따르는 어긋남이 있었다.
 */
class UpdateSettingRequestEnvLockTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 설정 수정 권한을 가진 관리자.
     *
     * @return User 관리자
     */
    private function settingsAdmin(): User
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.settings.update'],
            [
                'name' => ['ko' => '설정 수정', 'en' => 'Update settings'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );
        $role = Role::create([
            'identifier' => 'admin_envlock_'.uniqid(),
            'name' => ['ko' => '설정 관리자', 'en' => 'Settings admin'],
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);
        $admin = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => ['ko' => '관리자', 'en' => 'Administrator'], 'extension_type' => ExtensionOwnerType::Core, 'extension_identifier' => 'core', 'is_active' => true]
        );
        $user->roles()->attach([$admin->id => ['assigned_at' => now()], $role->id => ['assigned_at' => now()]]);

        return $user->fresh();
    }

    /**
     * `.env` 로 고정된 키의 단건 저장은 422 이고 값이 바뀌지 않는다.
     *
     * @effects update_setting_rejects_env_locked_key
     *
     * @return void 반환값 없음
     */
    public function test_single_setting_save_rejects_env_locked_key(): void
    {
        config(['env-priority.enabled' => true, 'env-priority.explicit' => ['MAIL_HOST' => true]]);
        $before = app(ConfigRepositoryInterface::class)->get('mail.host');

        $this->actingAs($this->settingsAdmin(), 'sanctum')
            ->putJson('/api/admin/settings/mail.host', ['value' => 'smtp.changed.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);

        $this->assertSame($before, app(ConfigRepositoryInterface::class)->get('mail.host'));
    }

    /**
     * 고정되지 않은 키는 그대로 저장된다 (잠금 검사가 다른 키를 막지 않는다).
     *
     * @effects update_setting_allows_unlocked_key
     *
     * @return void 반환값 없음
     */
    public function test_single_setting_save_allows_unlocked_key(): void
    {
        config(['env-priority.enabled' => true, 'env-priority.explicit' => ['MAIL_HOST' => true]]);

        $this->actingAs($this->settingsAdmin(), 'sanctum')
            ->putJson('/api/admin/settings/mail.from_name', ['value' => '실측 보내는 이'])
            ->assertOk();

        $this->assertSame('실측 보내는 이', app(ConfigRepositoryInterface::class)->get('mail.from_name'));
    }
}
