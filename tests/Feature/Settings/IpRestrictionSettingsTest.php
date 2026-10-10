<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * IP 접근 제한 설정의 저장·검증·자기 잠금 방지를 실제 저장 API 로 고정합니다.
 *
 * 이 설정은 저장이 끝나는 순간 다음 요청부터 차단이 걸린다. 그래서 두 가지가 결정적이다 —
 * ① 중첩 규칙이 한 필드도 잃지 않고 저장되는가(컨트롤러는 validated() 만 저장하므로 규칙이 없는
 * 하위 키는 성공 응답 속에 조용히 버려진다), ② 저장하는 관리자 자신을 잠그는 조합을 저장 **전에**
 * 막는가(되돌릴 화면까지 함께 잃으면 구제 수단은 셸뿐이다).
 *
 * DB 는 관리자 계정·역할만 쓰므로 스키마를 새로 만들지 않고 트랜잭션으로 격리한다.
 */
class IpRestrictionSettingsTest extends TestCase
{
    use DatabaseTransactions;

    /** 허용 목록 밖의 방문자 */
    private const OUTSIDE_IP = '198.51.100.7';

    /** 허용 목록 안의 사무실 IP */
    private const OFFICE_IP = '203.0.113.9';

    /** 사무실 대역 */
    private const OFFICE_RANGE = '203.0.113.0/24';

    /** 관리자 토큰 */
    private string $token;

    /**
     * 설정 권한을 가진 관리자 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->createAdminUser()->createToken('ip-restriction-test')->plainTextToken;
    }

    /**
     * 설정 읽기·저장 권한을 가진 관리자를 만듭니다.
     *
     * 트랜잭션 격리라 기존 데이터와 공존한다 — 공용 행은 firstOrCreate 로 받고, 새로 만드는
     * 식별자에는 실행 고유 접미어를 붙인다.
     *
     * @return User 생성된 관리자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'email' => 'ip-restriction-'.Str::lower(Str::random(10)).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $permissionIds = [];

        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier.' 권한', 'en' => $identifier.' permission']),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'ip_restriction_test_'.Str::lower(Str::random(8)),
            'name' => json_encode(['ko' => 'IP 제한 테스트 관리자', 'en' => 'IP restriction test admin']),
            'description' => json_encode(['ko' => '테스트 전용', 'en' => 'Test only']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 보안 탭 저장 요청을 보냅니다 (필수 필드는 기본값으로 채운다).
     *
     * @param  array<string, mixed>  $security  제출할 security 키
     * @param  string  $ip  요청 IP
     * @return TestResponse 응답
     */
    private function saveSecurity(array $security, string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'security',
                'security' => array_merge([
                    'force_https' => false,
                    'login_attempt_enabled' => true,
                ], $security),
            ]);
    }

    /**
     * 저장된 security 카테고리를 저장소에서 다시 읽습니다.
     *
     * @return array<string, mixed> 저장된 설정
     */
    private function storedSecurity(): array
    {
        return app(ConfigRepositoryInterface::class)->getCategory('security');
    }

    /**
     * 중첩 규칙의 모든 필드가 한 개도 빠짐없이 저장됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=contains_client, rule=allows_client
     *
     * @effects settings_persist_nested_rules_without_loss
     */
    public function test_security_tab_persists_every_ip_restriction_field(): void
    {
        $rules = [
            ['pattern' => '/api/admin/users*', 'ips' => [self::OFFICE_RANGE, '2001:db8::/32'], 'memo' => '회원 관리는 본사만', 'enabled' => true],
            ['pattern' => '/shop/*', 'ips' => ['192.0.2.10'], 'memo' => '', 'enabled' => false],
        ];

        $this->saveSecurity([
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => [self::OFFICE_RANGE, '10.0.0.0/8'],
            'ip_restriction_rules' => $rules,
        ], self::OFFICE_IP)->assertOk();

        $stored = $this->storedSecurity();

        $this->assertTrue($stored['ip_restriction_enabled']);
        $this->assertSame([self::OFFICE_RANGE, '10.0.0.0/8'], $stored['admin_allowed_ips']);
        $this->assertSame('/api/admin/users*', $stored['ip_restriction_rules'][0]['pattern'] ?? null);
        $this->assertSame([self::OFFICE_RANGE, '2001:db8::/32'], $stored['ip_restriction_rules'][0]['ips'] ?? null);
        $this->assertSame('회원 관리는 본사만', $stored['ip_restriction_rules'][0]['memo'] ?? null);
        $this->assertTrue($stored['ip_restriction_rules'][0]['enabled'] ?? null, '규칙 사용 여부가 저장되지 않았습니다 — 중첩 규칙이 없으면 조용히 버려집니다.');
        $this->assertFalse($stored['ip_restriction_rules'][1]['enabled'] ?? null);
    }

    /**
     * 목록 항목의 앞뒤 공백·빈 항목·중복은 저장 전에 정리됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=contains_client
     *
     * @effects list_entries_are_normalized_before_save
     */
    public function test_entries_are_trimmed_and_deduplicated_before_saving(): void
    {
        $this->saveSecurity([
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => [' '.self::OFFICE_IP.' ', self::OFFICE_IP, ''],
            'ip_restriction_rules' => [
                ['pattern' => '  /api/__probe*  ', 'ips' => ['192.0.2.1', ' 192.0.2.1'], 'enabled' => true],
            ],
        ], self::OFFICE_IP)->assertOk();

        $stored = $this->storedSecurity();

        $this->assertSame([self::OFFICE_IP], $stored['admin_allowed_ips']);
        $this->assertSame('/api/__probe*', $stored['ip_restriction_rules'][0]['pattern']);
        $this->assertSame(['192.0.2.1'], $stored['ip_restriction_rules'][0]['ips']);
    }

    /**
     * 형식이 잘못된 항목·패턴·빈 규칙은 필드별 오류로 거부됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api
     *
     * @effects invalid_entries_are_rejected
     */
    public function test_invalid_entries_are_rejected_with_field_errors(): void
    {
        $response = $this->saveSecurity([
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => [self::OFFICE_IP, 'admin.example.com'],
            'ip_restriction_rules' => [
                ['pattern' => '/api/admin users', 'ips' => ['192.168.*']],
                ['pattern' => '/api/ok*', 'ips' => []],
            ],
        ], self::OFFICE_IP);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'security.admin_allowed_ips.1',
            'security.ip_restriction_rules.0.pattern',
            'security.ip_restriction_rules.0.ips.0',
            'security.ip_restriction_rules.1.ips',
        ]);
        $this->assertStringContainsString('admin.example.com', $response->json('errors')['security.admin_allowed_ips.1'][0] ?? '');
        $this->assertFalse($this->storedSecurity()['ip_restriction_enabled'], '거부된 요청이 저장을 남겼습니다.');
    }

    /**
     * 현재 IP 가 빠진 관리자 허용 목록은 자기 잠금으로 거부합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=excludes_client
     *
     * @effects self_lockout_by_admin_list_is_rejected
     */
    public function test_saving_a_list_without_the_current_ip_is_rejected_as_self_lockout(): void
    {
        $response = $this->saveSecurity([
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => [self::OFFICE_RANGE],
            'ip_restriction_rules' => [],
        ], self::OUTSIDE_IP);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['security.admin_allowed_ips']);
        $this->assertStringContainsString(self::OUTSIDE_IP, $response->json('errors')['security.admin_allowed_ips'][0] ?? '');
        $this->assertFalse($this->storedSecurity()['ip_restriction_enabled'], '자기 잠금 조합이 저장되었습니다.');
    }

    /**
     * 설정 화면 경로를 막는 규칙도 자기 잠금으로 거부합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=empty, rule=blocks_client
     *
     * @effects self_lockout_by_rule_is_rejected
     */
    public function test_rule_that_blocks_the_settings_screen_is_rejected_as_self_lockout(): void
    {
        $response = $this->saveSecurity([
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => [],
            'ip_restriction_rules' => [
                ['pattern' => '/admin/settings*', 'ips' => [self::OFFICE_IP], 'enabled' => true],
            ],
        ], self::OUTSIDE_IP);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['security.ip_restriction_rules']);
        $this->assertStringContainsString('/admin/settings*', $response->json('errors')['security.ip_restriction_rules'][0] ?? '');
    }

    /**
     * 다시 들어올 관리자 로그인 경로를 막는 규칙도 자기 잠금으로 거부합니다.
     *
     * 설정 화면만 보면 이 조합을 놓친다 — 저장은 통과하고, 세션이 끊긴 다음에야 "로그인 자체가
     * 403" 이라는 형태로 드러난다. 그때는 화면으로 되돌릴 방법이 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=admin_login_api, admin_list=empty, rule=blocks_client
     *
     * @effects self_lockout_by_rule_is_rejected
     */
    public function test_rule_that_blocks_the_admin_login_is_rejected_as_self_lockout(): void
    {
        $response = $this->saveSecurity([
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => [],
            'ip_restriction_rules' => [
                ['pattern' => '/api/auth/admin/*', 'ips' => [self::OFFICE_IP], 'enabled' => true],
            ],
        ], self::OUTSIDE_IP);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['security.ip_restriction_rules']);
        $this->assertStringContainsString('/api/auth/admin/*', $response->json('errors')['security.ip_restriction_rules'][0] ?? '');
    }

    /**
     * 마스터 토글을 끈 채로는 어떤 목록도 저장할 수 있습니다 (적용되지 않으므로).
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=off, surface=core_admin_api, admin_list=excludes_client
     *
     * @effects disabled_toggle_skips_self_lockout_check
     */
    public function test_disabled_restriction_can_be_saved_with_any_list(): void
    {
        $this->saveSecurity([
            'ip_restriction_enabled' => false,
            'admin_allowed_ips' => [self::OFFICE_RANGE],
            'ip_restriction_rules' => [],
        ], self::OUTSIDE_IP)->assertOk();

        $this->assertSame([self::OFFICE_RANGE], $this->storedSecurity()['admin_allowed_ips']);
    }

    /**
     * 자기 잠금 판정은 저장 후의 설정(저장값 위에 제출분)으로 합니다.
     *
     * 토글만 켜는 부분 저장이라도, 이미 저장된 목록이 현재 IP 를 빼고 있으면 잠금이다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=excludes_client
     *
     * @effects self_lockout_uses_post_save_settings
     */
    public function test_self_lockout_check_uses_merged_stored_values(): void
    {
        $repository = app(ConfigRepositoryInterface::class);
        $repository->saveCategory('security', array_merge($repository->getCategory('security'), [
            'ip_restriction_enabled' => false,
            'admin_allowed_ips' => [self::OFFICE_RANGE],
        ]));

        $this->saveSecurity(['ip_restriction_enabled' => true], self::OUTSIDE_IP)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['security.admin_allowed_ips']);
    }

    /**
     * 저장한 제한은 같은 프로세스의 다음 요청부터 곧바로 적용됩니다.
     *
     * 저장 경로가 설정 미러를 다시 채우지 않으면 상주 프로세스는 저장 후에도 옛 값을 읽는다 —
     * 화면은 "켜짐" 인데 아무것도 막지 않는 상태가 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=contains_client
     *
     * @effects saved_restriction_applies_to_next_request
     */
    public function test_saved_restriction_takes_effect_on_the_next_request(): void
    {
        $this->saveSecurity([
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => [self::OFFICE_IP],
            'ip_restriction_rules' => [],
        ], self::OFFICE_IP)->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => self::OUTSIDE_IP])
            ->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/admin/settings')
            ->assertStatus(403);

        $this->withServerVariables(['REMOTE_ADDR' => self::OFFICE_IP])
            ->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/admin/settings')
            ->assertOk();
    }
}
