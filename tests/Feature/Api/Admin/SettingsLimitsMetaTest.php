<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Http\Requests\Settings\SaveSettingsRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 환경설정 입력 한계값 계약 테스트 (#493 C4)
 *
 * 설정 화면의 숫자 입력 경계값과 저장 검증 규칙이 각자 리터럴을 들면 조용히 갈라져,
 * "화면이 허용한 값인데 저장에서 422" 또는 그 반대가 됩니다. 양쪽이 같은 출처
 * (`config('core.settings_limits')`)를 읽는지, 그 값이 화면에 실제로 전달되는지 검증합니다.
 */
class SettingsLimitsMetaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createSettingsAdmin();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 설정 조회 권한을 가진 관리자를 생성합니다.
     *
     * @return User 생성된 관리자
     */
    private function createSettingsAdmin(): User
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.settings.read'],
            [
                'name' => json_encode(['ko' => 'core.settings.read', 'en' => 'core.settings.read']),
                'description' => json_encode(['ko' => 'core.settings.read', 'en' => 'core.settings.read']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );

        $role = Role::create([
            'identifier' => 'settings_limits_admin_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);

        $adminBaseRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminBaseRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 설정 조회 응답이 화면이 바인딩할 한계값을 함께 내려주는지 검증합니다.
     */
    #[Test]
    public function index_response_includes_settings_limits_meta(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/settings');

        $response->assertStatus(200);

        $limits = $response->json('data._meta.limits');

        $this->assertIsArray($limits, '설정 응답에 _meta.limits 가 없습니다 — 화면이 경계값을 받을 수 없습니다.');
        $this->assertSame(config('core.settings_limits'), $limits);
    }

    /**
     * 저장 규칙이 리터럴이 아니라 설정 한계값을 읽는지 검증합니다.
     */
    #[Test]
    public function save_rules_read_password_length_bounds_from_config(): void
    {
        config([
            'core.settings_limits.security_password_min_length_min' => 9,
            'core.settings_limits.security_password_min_length_max' => 33,
        ]);

        $rules = (new SaveSettingsRequest)->rules()['security.password_min_length'];

        $this->assertContains('min:9', $rules);
        $this->assertContains('max:33', $rules);
    }
}
