<?php

namespace Tests\Feature\Api\Admin;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateStep;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CoreUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 코어 업데이트 웹 단계 실행의 HTTP 표면 (#609 G6 · W6).
 *
 * 여기서 잠그는 것은 셋이다:
 *
 * 1. **권한 등급** — 적용 경로는 설정 수정과 다른 권한을 요구한다 (D4). 설정을 고칠 수
 *    있다는 것과 코어를 갈아 끼울 수 있다는 것은 다른 등급이고, 그 구분이 무너져도
 *    오류는 나지 않는다(그냥 통과한다).
 * 2. **착수 가드** — 진행 중 재착수, 없는 ZIP 지정처럼 「시작하면 안 되는 상태」에서
 *    시작하지 않는다.
 * 3. **응답 형태** — 화면이 W6 의 네 상태를 고르는 근거가 응답에 있다.
 *
 * 커버 축: core_update_web permission·start_guard·status_shape
 */
class CoreUpdateWebFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $stateDirectory;

    /**
     * 실행 상태 파일이 놓이는 디렉터리를 정하고, 앞선 테스트가 남긴 상태 파일을 지워 진행 중 실행이 없는 상태에서 시작하게 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDirectory = storage_path(
            str_replace('/', DIRECTORY_SEPARATOR, CoreUpdateRunState::DIRECTORY),
        );

        foreach ($this->stateFiles() as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * 테스트가 심은 실행 상태 파일과 적용 목록 파일을 지워 다음 테스트로 상태가 새지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->stateFiles() as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * 이 테스트가 만들거나 지우는 상태 파일 목록.
     *
     * @return list<string> 절대 경로
     */
    private function stateFiles(): array
    {
        return [
            $this->stateDirectory.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME,
            $this->stateDirectory.DIRECTORY_SEPARATOR.CoreUpdateRunState::APPLY_LIST_FILENAME,
        ];
    }

    /**
     * 지정한 권한을 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return array{0: User, 1: string} 사용자와 토큰
     */
    private function adminWith(array $permissions): array
    {
        $user = User::factory()->create();

        $permissionIds = [];

        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
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

        return [$user->fresh(), $user->createToken('test-token')->plainTextToken];
    }

    /**
     * 인증 헤더를 붙인 요청 헬퍼.
     *
     * @param  string  $token  Bearer 토큰
     * @return static 헤더가 붙은 테스트 인스턴스
     */
    private function asAdmin(string $token): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 설정 수정 권한만 있는 관리자는 적용 경로에 닿지 못하는지 확인합니다 (D4).
     *
     * @scenario actor=without_permission, run_state=idle
     *
     * @effects settings_update_permission_alone_cannot_reach_the_apply_endpoints
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_settings_update_permission_alone_cannot_reach_the_apply_endpoints(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.settings.update']);

        $this->asAdmin($token)->getJson('/api/admin/core-update/status')->assertForbidden();
        $this->asAdmin($token)->postJson('/api/admin/core-update/start')->assertForbidden();
        $this->asAdmin($token)->postJson('/api/admin/core-update/advance')->assertForbidden();
        $this->asAdmin($token)->postJson('/api/admin/core-update/dismiss')->assertForbidden();

        // 확인·변경사항은 종전 권한 그대로여야 한다 — 등급을 나눈다는 것이 기존 화면을
        // 막는다는 뜻은 아니다.
        $this->asAdmin($token)->getJson('/api/admin/core-update/changelog')->assertOk();
    }

    /**
     * 신규 권한이 있으면 상태를 볼 수 있고, 그 형태가 화면 계약과 맞는지 확인합니다.
     *
     * @scenario actor=with_permission, run_state=idle
     *
     * @effects status_returns_the_shape_the_screen_needs
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_status_returns_the_shape_the_screen_needs(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        $response = $this->asAdmin($token)->getJson('/api/admin/core-update/status')->assertOk();

        $data = $response->json('data');

        $this->assertNull($data['run'], '진행 중인 실행이 없으면 run 은 null 이어야 화면이 ①/② 를 그린다.');
        // 개수만 견주면 양변이 같은 `sequence()` 에서 나와 **어떻게 바꿔도 함께 움직인다**.
        // 화면이 실제로 기대는 것은 「같은 단계가 같은 순서로」 실려 오는 것이므로 그것을 잰다 —
        // 순서가 뒤집히거나 한 단계가 다른 키로 나가면 red 가 된다.
        $expectedKeys = array_map(
            static fn (CoreUpdateStep $step): string => $step->value,
            CoreUpdateStep::sequence(),
        );

        $this->assertNotEmpty($expectedKeys, '단계가 없으면 이 대조는 아무것도 재지 않는다.');
        $this->assertSame($expectedKeys, array_column($data['steps'], 'key'));
        $this->assertSame(count($expectedKeys), $data['total_steps']);
        $this->assertArrayHasKey('manual_sources', $data, '수동 ZIP 목록이 없으면 고급 접기가 비어 보인다 (㉯).');
        // 키 존재만 보면 집계가 어떻게 망가져도 통과한다 — 컨트롤러의 폴백이 실패 시에도
        // 그 키를 **항상** 채우기 때문이다. 화면이 실제로 읽는 세 필드의 형태까지 잰다.
        $this->assertArrayHasKey('modified_layouts', $data, '수정된 레이아웃 안내의 근거 (D29).');
        $this->assertIsBool($data['modified_layouts']['has_modified'] ?? null);
        $this->assertIsInt($data['modified_layouts']['count'] ?? null);
        $this->assertIsArray($data['modified_layouts']['extensions'] ?? null);

        // 진단 요약도 착수 화면이 「사전 조건 미충족」을 그리는 근거다 (D26 · W6).
        $this->assertArrayHasKey('preflight', $data, '진단 결과가 없으면 착수 화면이 그것을 그릴 수 없다.');

        foreach ($data['steps'] as $step) {
            $this->assertArrayHasKey('key', $step);
            $this->assertArrayHasKey('label', $step);
            $this->assertNotSame('', trim((string) $step['label']));
        }
    }

    /**
     * 진행 중인 실행이 있으면 다시 착수하지 못하는지 확인합니다.
     *
     * 두 실행이 겹치면 서로의 백업을 파괴한다 — 그 뒤 한쪽이 실패하면 되돌릴 것이 없다.
     *
     * @scenario actor=with_permission, run_state=running, concurrency=duplicate_request
     *
     * @effects a_second_start_is_refused_while_a_run_is_active
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_second_start_is_refused_while_a_run_is_active(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        // 진행 중 상태를 직접 심는다 — 실제 착수는 GitHub 조회를 타므로 이 검사의 축이 아니다.
        app(CoreUpdateRunState::class)->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 1);

        $this->asAdmin($token)
            ->postJson('/api/admin/core-update/start')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * 진행 중에는 기록을 지우지 못하는지 확인합니다.
     *
     * @scenario actor=with_permission, run_state=running
     *
     * @effects dismiss_is_refused_while_a_run_is_active
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_dismiss_is_refused_while_a_run_is_active(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        app(CoreUpdateRunState::class)->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 1);

        $this->asAdmin($token)
            ->postJson('/api/admin/core-update/dismiss')
            ->assertStatus(409);

        $this->assertTrue(app(CoreUpdateRunState::class)->exists(), '거부했는데 기록이 사라지면 안 된다.');
    }

    /**
     * 진행 중인 실행이 없으면 단계 수행이 거부되는지 확인합니다.
     *
     * @scenario actor=with_permission, run_state=idle
     *
     * @effects advance_without_a_run_is_refused
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_advance_without_a_run_is_refused(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        $this->asAdmin($token)
            ->postJson('/api/admin/core-update/advance')
            ->assertStatus(409);
    }

    /**
     * 끝난 실행은 기록을 지울 수 있는지 확인합니다.
     *
     * @scenario actor=with_permission, run_state=finished
     *
     * @effects a_finished_run_can_be_dismissed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_finished_run_can_be_dismissed(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        $state = app(CoreUpdateRunState::class);
        $state->start(new CoreUpdateOptions, '7.0.8', '7.1.0', 1);
        $state->patch(['status' => CoreUpdateRunState::STATUS_COMPLETED, 'next_step' => null]);

        $this->asAdmin($token)
            ->postJson('/api/admin/core-update/dismiss')
            ->assertOk()
            ->assertJsonPath('data.run', null);

        $this->assertFalse(app(CoreUpdateRunState::class)->exists());
    }

    /**
     * 존재하지 않는 ZIP 을 지정하면 착수하지 않는지 확인합니다 (㉯ — D18).
     *
     * 점검 모드를 켠 뒤에 알면 사이트를 세워 놓고 되돌리는 왕복이 된다.
     *
     * @scenario actor=with_permission, run_state=idle, source=manual_zip
     *
     * @effects starting_with_a_missing_zip_is_refused_before_anything_changes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_starting_with_a_missing_zip_is_refused_before_anything_changes(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        $this->asAdmin($token)
            ->postJson('/api/admin/core-update/start', ['zip_file' => 'does-not-exist.zip'])
            ->assertStatus(422);

        $this->assertFalse(app(CoreUpdateRunState::class)->exists(), '거부된 착수가 진행 상태를 남기면 안 된다.');
    }

    /**
     * 경로가 섞인 ZIP 이름은 검증에서 거부되는지 확인합니다.
     *
     * @scenario actor=with_permission, run_state=idle, source=manual_zip
     *
     * @effects a_zip_name_with_path_components_is_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_zip_name_with_path_components_is_rejected(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        $this->asAdmin($token)
            ->postJson('/api/admin/core-update/start', ['zip_file' => '../../.env'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['zip_file']);
    }

    /**
     * 운영자가 올려 둔 ZIP 이 목록에 나타나는지 확인합니다 (㉯ — D18).
     *
     * @scenario actor=with_permission, run_state=idle, source=manual_zip
     *
     * @effects uploaded_zip_files_appear_in_the_manual_source_list
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_uploaded_zip_files_appear_in_the_manual_source_list(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        $directory = (string) config('app.update.pending_path');
        File::ensureDirectoryExists($directory);
        $zip = $directory.DIRECTORY_SEPARATOR.'g7-test-source.zip';
        File::put($zip, 'not-a-real-zip');

        try {
            $names = array_column(
                (array) $this->asAdmin($token)->getJson('/api/admin/core-update/status')->json('data.manual_sources'),
                'name',
            );

            $this->assertContains('g7-test-source.zip', $names);
        } finally {
            @unlink($zip);
        }
    }

    /**
     * 설정 응답이 새 권한을 화면에 **참**으로 알려 주는지 확인합니다.
     *
     * 화면은 이 값으로 업데이트 영역을 표시할지 말지를 정한다 — 없으면 권한 없는
     * 관리자에게 누를 수 없는 탭이 남는다.
     *
     * 두 사용자를 한 테스트에서 번갈아 인증하지 않는 이유는 하네스 성질이다: 같은
     * 애플리케이션 인스턴스 안에서 가드가 첫 요청의 사용자를 캐시해, 두 번째 요청이
     * 앞 사용자의 권한으로 판정된다. 그 상태로 초록이 나면 판정을 잰 것이 아니다.
     *
     * @scenario actor=with_permission, run_state=idle
     *
     * @effects settings_abilities_expose_the_core_update_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_settings_abilities_expose_the_core_update_permission_when_granted(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        $abilities = (array) $this->asAdmin($token)->getJson('/api/admin/settings')->json('data.abilities');

        $this->assertTrue((bool) ($abilities['can_core_update'] ?? false));
    }

    /**
     * 설정 수정 권한만으로는 그 값이 거짓인지 확인합니다.
     *
     * @scenario actor=without_permission, run_state=idle
     *
     * @effects settings_abilities_expose_the_core_update_permission
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_settings_abilities_withhold_the_core_update_permission_when_not_granted(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.settings.update']);

        $abilities = (array) $this->asAdmin($token)->getJson('/api/admin/settings')->json('data.abilities');

        $this->assertTrue((bool) ($abilities['can_update'] ?? false), '설정 수정 권한은 그대로여야 한다.');
        $this->assertFalse((bool) ($abilities['can_core_update'] ?? false));
    }

    /**
     * 되돌리기까지 실패했을 때 수동 복구 안내의 근거가 화면에 도달하는지 확인합니다 (D10).
     *
     * 이 상태는 사이트가 점검 모드에 남는 유일한 갈래다. 화면이 「되돌리기도 실패했다」를
     * 그리려면 백업 위치와 점검 모드가 켜진 채라는 사실이 응답에 실려야 하는데, 그 셋 중
     * 하나만 빠져도 운영자는 무엇을 어디서 복구할지 알 수 없다 — 오류는 나지 않고 안내만
     * 비어 나간다.
     *
     * 종전에는 「백업을 파괴해야 재현되므로 검증 환경을 잃는다」는 이유로 미커버 원장에
     * 있었으나, 실측(§9.12.5 축 C)에서 측정 전용 패키지로 격리해 재현되는 것이 확인되어
     * 자동 검사로 옮겼다.
     *
     * @scenario actor=with_permission, failing_step=apply, rollback_outcome=restore_failed
     *
     * @effects a_failed_rollback_opens_the_manual_recovery_guidance
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_failed_rollback_opens_the_manual_recovery_guidance(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        $state = app(CoreUpdateRunState::class);
        $state->start(new CoreUpdateOptions, '7.1.0', '7.1.1', 1);
        $state->mergeContext(['maintenance_enabled' => true]);
        $state->patch([
            'status' => CoreUpdateRunState::STATUS_ROLLBACK_FAILED,
            'next_step' => null,
            'error' => ['message' => '마이그레이션 실패'],
            'rollback' => [
                'restored' => false,
                'files_were_applied' => true,
                'failed_targets' => ['app', 'config'],
                'backup_path' => 'storage/app/core_backups/20260902_190000',
                'maintenance_left_on' => true,
            ],
        ]);

        $run = (array) $this->asAdmin($token)
            ->getJson('/api/admin/core-update/status')
            ->assertOk()
            ->json('data.run');

        $this->assertSame(
            CoreUpdateRunState::STATUS_ROLLBACK_FAILED,
            $run['status'] ?? null,
            '화면은 이 상태값으로 수동 복구 영역을 연다.'
        );

        $rollback = (array) ($run['rollback'] ?? []);

        $this->assertFalse($rollback['restored'] ?? true, '복원되지 않았다는 사실이 실려야 한다.');
        $this->assertTrue($rollback['files_were_applied'] ?? false, '파일이 교체된 뒤의 실패라는 사실이 실려야 한다.');
        $this->assertTrue(
            $rollback['maintenance_left_on'] ?? false,
            '사이트가 점검 모드에 남았다는 사실이 빠지면 운영자는 사이트가 멀쩡한 줄 안다.'
        );
        $this->assertNotSame(
            '',
            trim((string) ($rollback['backup_path'] ?? '')),
            '백업 위치가 없으면 안내가 「어디서」 복구할지를 말하지 못한다.'
        );
        $this->assertNotEmpty($rollback['failed_targets'] ?? [], '되돌리지 못한 대상 목록이 실려야 한다.');
    }

    /**
     * 쓸 수 없는 경로가 있으면 착수 단계가 막고 아무것도 바꾸지 않는지 확인합니다 (D26).
     *
     * 이 게이트가 없으면 쓰기 권한이 없는 호스팅의 운영자가 사이트를 점검 모드로 보낸
     * 뒤에야 그 사실을 알게 되고, 그때는 되돌리기까지 부분 실패한다.
     *
     * 진단을 대역으로 갈아 끼운다 — 실제로 권한을 빼앗으면 검증 환경의 다른 축이
     * 오염되고 OS 마다 권한 동작이 달라 재현되지 않는다.
     *
     * @scenario actor=with_permission, failing_step=preflight, rollback_outcome=not_needed
     *
     * @effects preflight_blocks_the_run_when_target_paths_are_not_writable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_preflight_blocks_the_run_when_target_paths_are_not_writable(): void
    {
        [, $token] = $this->adminWith(['core.settings.read', 'core.system.update']);

        $blockedPath = 'app/Services';

        $this->app->instance(CoreUpdatePreflight::class, new class(app(CoreUpdateService::class), $blockedPath) extends CoreUpdatePreflight
        {
            /**
             * 진단 대역을 만들고, 쓰기 불가로 보고할 경로를 기억한다.
             *
             * @param  CoreUpdateService  $service  부모 진단이 요구하는 코어 업데이트 서비스
             * @param  string  $blocked  쓰기 불가로 보고할 상대 경로
             * @return void 반환값 없음
             */
            public function __construct(CoreUpdateService $service, private readonly string $blocked)
            {
                parent::__construct($service);
            }

            /**
             * 실제 점검 없이 지정 경로가 쓰기 불가라는 실패 진단 결과를 돌려준다.
             *
             * @param  bool  $requiresArchiveExtraction  압축 해제 수단 점검 여부(대역에서는 무시)
             * @param  bool  $deep  정밀 점검 여부(대역에서는 무시)
             * @return array<string, mixed> 쓰기 불가 경로를 담은 실패 진단 결과
             */
            public function run(bool $requiresArchiveExtraction = true, bool $deep = true): array
            {
                return [
                    'ok' => false,
                    'writable' => ['ok' => false, 'blocked' => [$this->blocked], 'checked' => 1],
                    'disk' => ['ok' => true],
                    'requirements' => ['available_methods' => []],
                    'errors' => ['쓰기 권한 없음: '.$this->blocked],
                ];
            }
        });

        $state = app(CoreUpdateRunState::class);
        $state->start(new CoreUpdateOptions, '7.1.0', '7.1.1', 1);

        // 되돌리기는 요청 경계를 넘어 이어진다(한 요청 = 한 단계). 화면이 스스로 이어가는 것과
        // 같은 방식으로 종착 상태까지 몰아 준다 — 상한을 두어 끝나지 않아도 무한 반복하지 않는다.
        $terminal = [CoreUpdateRunState::STATUS_ROLLED_BACK, CoreUpdateRunState::STATUS_ROLLBACK_FAILED];
        $run = [];

        for ($i = 0; $i < 6; $i++) {
            $this->asAdmin($token)->postJson('/api/admin/core-update/advance');

            $run = (array) $this->asAdmin($token)
                ->getJson('/api/admin/core-update/status')
                ->assertOk()
                ->json('data.run');

            if (in_array($run['status'] ?? null, $terminal, true)) {
                break;
            }
        }

        $this->assertContains(
            $run['status'] ?? null,
            $terminal,
            '진단이 막았는데 실행이 계속되거나 되돌리기가 끝나지 않으면 안 된다.'
        );

        // 핵심은 「막혔다」가 아니라 **아무것도 바꾸지 않은 채 막혔다**는 것이다.
        $this->assertFalse(
            ($run['rollback']['files_were_applied'] ?? true),
            '진단 단계는 파일 적용 앞이므로 교체된 파일이 있으면 안 된다.'
        );

        $this->assertStringContainsString(
            $blockedPath,
            (string) ($run['error']['message'] ?? ''),
            '막힌 경로가 실패 사유에 없으면 운영자는 무엇을 고쳐야 할지 알 수 없다.'
        );
    }
}
