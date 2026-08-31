<?php

namespace Tests\Feature\Scheduling;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Scheduling\ScheduleRunState;
use App\Scheduling\WebCronTokenStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 관리자 실행 방식·예약 작업 API 의 권한·노출 계약 (W5 · D20).
 *
 * 가장 중요한 축은 **호출 번호가 일반 설정 통로로 새지 않는다**는 것이다. 그 번호는
 * 인증 없는 실행 자격증명이므로, 설정을 읽을 수만 있는 계정이 그것을 얻으면 등급
 * 구분이 사라진다. 그래서 두 방향을 함께 잰다 — 일반 설정 응답에 없다는 것과,
 * 전용 경로가 더 높은 권한을 요구한다는 것.
 *
 * @scenario caller=panel, token=valid, token=rotated
 */
class SystemExecutionApiTest extends TestCase
{
    use RefreshDatabase;

    /** 케이스 전용 토큰 파일 */
    private string $tokenPath;

    /** 케이스 전용 상태 파일 */
    private string $statePath;

    /**
     * 테스트 준비.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->tokenPath = storage_path('framework/testing/sysexec-token-'.$suffix.'.json');
        $this->statePath = storage_path('framework/testing/sysexec-state-'.$suffix.'.json');

        File::ensureDirectoryExists(dirname($this->tokenPath));

        $this->app->instance(WebCronTokenStore::class, new WebCronTokenStore($this->tokenPath));
        $this->app->instance(ScheduleRunState::class, new ScheduleRunState($this->statePath));

        app(ConsoleKernelContract::class)->bootstrap();
        $this->app->instance(Schedule::class, new Schedule('UTC'));
    }

    /**
     * 테스트 정리.
     */
    protected function tearDown(): void
    {
        foreach ([$this->tokenPath, $this->statePath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * 상태 조회는 판정 근거와 예약 상태를 돌려주되 **호출 번호는 싣지 않는다** (D20).
     *
     * @effects status_response_excludes_the_cron_token
     */
    public function test_status_does_not_carry_the_cron_token(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();

        $response = $this->asAdmin(['core.settings.read'])->getJson('/api/admin/settings/system/status');

        $response->assertOk()
            ->assertJsonPath('data.cron.token_issued', true)
            ->assertJsonPath('data.execution.configured_mode', 'auto');

        $this->assertStringNotContainsString($token, $response->getContent());
    }

    /**
     * 일반 설정 조회 응답에도 호출 번호가 실리지 않는다 (D20 — 통로 전수).
     *
     * 전용 경로만 잠그고 일반 통로를 재지 않으면, 카탈로그에 키가 하나 늘어나는 순간
     * 그 통로가 조용히 열린다.
     *
     * @effects settings_response_excludes_the_cron_token
     */
    public function test_general_settings_response_does_not_carry_the_cron_token(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();

        $response = $this->asAdmin(['core.settings.read'])->getJson('/api/admin/settings');

        $response->assertOk();
        $this->assertStringNotContainsString($token, $response->getContent());
    }

    /**
     * 설정 단건 조회로도 호출 번호에 닿을 수 없다 (D20 — 세 번째 통로).
     *
     * @effects single_setting_read_cannot_reach_the_cron_token
     */
    public function test_single_setting_read_cannot_reach_the_cron_token(): void
    {
        $token = app(WebCronTokenStore::class)->rotate();

        $response = $this->asAdmin(['core.settings.read'])
            ->getJson('/api/admin/settings/system.cron_token');

        $this->assertStringNotContainsString($token, $response->getContent());
    }

    /**
     * 호출 주소 조회는 설정 **수정** 권한을 요구한다.
     *
     * 읽기 권한만으로 얻을 수 있으면 등급 구분이 사라진다.
     *
     * @effects token_reveal_requires_update_permission
     */
    public function test_token_reveal_requires_update_permission(): void
    {
        $this->asAdmin(['core.settings.read'])
            ->getJson('/api/admin/settings/system/cron-token')
            ->assertStatus(403);
    }

    /**
     * 수정 권한이 있으면 호출 주소를 받는다 (없으면 그 시점에 발급).
     *
     * @effects token_reveal_returns_the_call_url
     */
    public function test_token_reveal_returns_the_call_url(): void
    {
        $response = $this->asAdmin(['core.settings.read', 'core.settings.update'])
            ->getJson('/api/admin/settings/system/cron-token');

        $response->assertOk();

        $url = $response->json('data.url');

        $this->assertIsString($url);
        $this->assertStringContainsString('/api/system/cron/run', $url);
        $this->assertStringContainsString('token=', $url);
        $this->assertTrue(app(WebCronTokenStore::class)->exists());
    }

    /**
     * 재발급은 새 주소를 돌려주고 이전 번호를 무효화한다.
     *
     * @effects token_rotation_replaces_the_call_url
     */
    public function test_token_rotation_replaces_the_call_url(): void
    {
        $store = app(WebCronTokenStore::class);
        $before = $store->rotate();

        $response = $this->asAdmin(['core.settings.read', 'core.settings.update'])
            ->postJson('/api/admin/settings/system/cron-token/rotate');

        $response->assertOk();

        $store->refresh();

        $this->assertNotSame($before, $store->token());
        $this->assertStringContainsString((string) $store->token(), (string) $response->json('data.url'));
    }

    /**
     * 재발급도 수정 권한을 요구한다.
     *
     * @effects token_rotation_requires_update_permission
     */
    public function test_token_rotation_requires_update_permission(): void
    {
        $this->asAdmin(['core.settings.read'])
            ->postJson('/api/admin/settings/system/cron-token/rotate')
            ->assertStatus(403);
    }

    /**
     * 재감지는 판정 근거와 유효 모드를 함께 돌려준다.
     *
     * @effects detect_returns_the_reason_and_effective_mode
     */
    public function test_detect_returns_reason_and_effective_mode(): void
    {
        $response = $this->asAdmin(['core.settings.read'])
            ->postJson('/api/admin/settings/system/detect');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'detection' => ['mode', 'blocked_functions', 'php_probe'],
                    'effective_mode',
                    'configured_mode',
                ],
            ]);
    }

    /**
     * 판정 사유가 번역된 문구로 함께 내려온다.
     *
     * 화면은 동적 키 + 치환을 조합할 수단이 없으므로, 번역이 서버에서 끝나지 않으면
     * 그 자리는 영원히 비어 있다.
     *
     * @effects detect_reason_is_localized_server_side
     */
    public function test_detect_reason_is_localized(): void
    {
        $probe = $this->asAdmin(['core.settings.read'])
            ->postJson('/api/admin/settings/system/detect')
            ->json('data.detection.php_probe');

        $this->assertArrayHasKey('message_key', $probe);

        if (($probe['message_key'] ?? '') !== '') {
            $this->assertArrayHasKey('message_localized', $probe);
            $this->assertNotSame(
                'settings.'.$probe['message_key'],
                $probe['message_localized'],
                '번역 키가 해석되지 않고 그대로 내려왔습니다.',
            );
        }
    }

    /**
     * "지금 실행" 은 읽기 권한만으로는 거부된다.
     *
     * 권한 스코프 캐시가 프로세스 정적이라 한 케이스 안에서 두 행위자를 쓰면 앞
     * 행위자의 판정이 뒤로 샌다 — 행위자마다 케이스를 나눈다.
     *
     * @effects run_now_requires_update_permission
     */
    public function test_run_now_is_denied_without_update_permission(): void
    {
        $this->asAdmin(['core.settings.read'])
            ->postJson('/api/admin/settings/system/cron/run')
            ->assertStatus(403);
    }

    /**
     * "지금 실행" 은 수정 권한이 있으면 실행 요약을 돌려준다.
     *
     * @effects run_now_returns_a_summary
     */
    public function test_run_now_returns_a_summary(): void
    {
        $this->asAdmin(['core.settings.read', 'core.settings.update'])
            ->postJson('/api/admin/settings/system/cron/run')
            ->assertOk()
            ->assertJsonStructure(['data' => ['due', 'executed', 'failed', 'skipped', 'truncated']]);
    }

    /**
     * `system` 탭 저장이 카탈로그에 반영된다 (신규 카테고리의 저장 경로).
     *
     * @effects system_tab_save_persists_to_the_catalog
     */
    public function test_system_tab_save_persists(): void
    {
        $this->asAdmin(['core.settings.read', 'core.settings.update'])
            ->postJson('/api/admin/settings', [
                '_tab' => 'system',
                'system' => [
                    'execution_mode' => 'inprocess',
                    'cron_mode' => 'web',
                    'cron_allowed_ips' => "203.0.113.7\n2001:db8::/32",
                    'cron_min_interval' => 15,
                    'cron_visit_fallback' => true,
                ],
            ])
            ->assertOk();

        $saved = app(ConfigRepositoryInterface::class)->getCategory('system');

        $this->assertSame('inprocess', $saved['execution_mode']);
        $this->assertSame('web', $saved['cron_mode']);
        $this->assertSame(15, $saved['cron_min_interval']);
        $this->assertTrue((bool) $saved['cron_visit_fallback']);
    }

    /**
     * 허용값 밖의 실행 방식은 벌크 저장에서 거부된다.
     *
     * @effects invalid_cron_mode_is_rejected_on_bulk_save
     */
    public function test_invalid_cron_mode_is_rejected_on_bulk_save(): void
    {
        $this->asAdmin(['core.settings.read', 'core.settings.update'])
            ->postJson('/api/admin/settings', [
                '_tab' => 'system',
                'system' => ['cron_mode' => 'whenever-i-feel-like-it'],
            ])
            ->assertStatus(422);
    }

    /**
     * 허용값 밖의 실행 방식은 **단건** 저장에서도 거부된다 (우회로 차단).
     *
     * @effects invalid_cron_mode_is_rejected_on_single_save
     */
    public function test_invalid_cron_mode_is_rejected_on_single_save(): void
    {
        $this->asAdmin(['core.settings.read', 'core.settings.update'])
            ->putJson('/api/admin/settings/system.cron_mode', ['value' => 'whenever'])
            ->assertStatus(422);
    }

    /**
     * 잘못된 IP 표기는 **양쪽 저장 경로**에서 거부된다.
     *
     * 형태 규칙이 한쪽에만 있으면 다른 쪽이 우회로가 되고, 판정기는 그 항목을
     * 조용히 매칭 실패로 처리해 운영자가 원인을 알 수 없다.
     *
     * @effects invalid_ip_allow_list_is_rejected_on_both_paths
     */
    public function test_invalid_ip_allow_list_is_rejected_on_both_paths(): void
    {
        $admin = $this->asAdmin(['core.settings.read', 'core.settings.update']);

        $admin->postJson('/api/admin/settings', [
            '_tab' => 'system',
            'system' => ['cron_allowed_ips' => 'not-an-ip'],
        ])->assertStatus(422);

        $admin->putJson('/api/admin/settings/system.cron_allowed_ips', ['value' => 'not-an-ip'])
            ->assertStatus(422);
    }

    /**
     * 최소 간격의 경계값은 설정 한계표에서 도출된다 (상한 초과는 거부).
     *
     * @effects min_interval_bounds_come_from_the_limits_table
     */
    public function test_min_interval_upper_bound_is_enforced(): void
    {
        $max = (int) config('core.settings_limits.system_cron_min_interval_max');

        $this->asAdmin(['core.settings.read', 'core.settings.update'])
            ->postJson('/api/admin/settings', [
                '_tab' => 'system',
                'system' => ['cron_min_interval' => $max + 1],
            ])
            ->assertStatus(422);
    }

    /**
     * 지정한 권한을 가진 관리자로 인증된 요청 빌더를 만듭니다.
     *
     * @param  list<string>  $permissions  부여할 권한 식별자
     * @return static 인증 헤더가 붙은 테스트 인스턴스
     */
    private function asAdmin(array $permissions): static
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ],
            );
            $permissionIds[] = $permission->id;
        }

        $testRole = Role::create([
            'identifier' => 'sysexec_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);
        $testRole->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ],
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $token = $user->fresh()->createToken('test-token')->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }
}
