<?php

namespace Tests\Feature\Extension;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Exceptions\ExtensionUpdateFailedException;
use App\Exceptions\ExtensionUpdateRequestFailedException;
use App\Exceptions\LanguagePackOperationException;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Extension\Helpers\StuckUpdateRecovery;
use App\Extension\Support\ExtensionUpdateReport;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\Vendor\VendorMode;
use App\Http\Resources\ModuleResource;
use App\Models\LanguagePack;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\LanguagePackService;
use App\Services\ModuleService;
use App\Services\TemplateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * 웹(관리자 화면) 확장 업데이트가 터미널과 같은 결과·실패 안내와 입력 선택지를 갖는다 (7.2.1 출시 전).
 *
 * 터미널 `{type}:update` 는 판 변화·의존 경고·멈춘 업데이트 정리·실패 뒤 상태·재실행 명령을 보여 주고 `--source`·`--zip`·`--force` 를
 * 받지만, 웹 응답에는 그 값이 빠졌고(실패는 422 로 감싸 outcome 이 사라졌다) 요청 본문은 소스·ZIP 을 받지 않았다. 언어팩 터미널
 * `--source=github` 은 서비스에 닿지 않아 효과가 없었다.
 *
 * @scenario-manifest tests/scenarios/extension-update-failure-rollback.yaml
 */
class ExtensionWebUpdateReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 업데이트 권한을 가진 관리자.
     */
    private User $manager;

    /**
     * 수동 ZIP 대기 폴더(테스트 전용).
     */
    private string $pendingDir;

    /**
     * 권한 시드·관리자·수동 ZIP 대기 폴더를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->manager = $this->makeUserWithPermissions([
            'core.modules.read', 'core.modules.install',
            'core.templates.read', 'core.templates.install',
            'core.language_packs.read', 'core.language_packs.update',
        ]);

        $this->pendingDir = storage_path('framework/testing/extension_pending_'.uniqid());
        File::ensureDirectoryExists($this->pendingDir);
        config(['app.update.extension_pending_path' => $this->pendingDir]);

        StuckUpdateRecovery::takeRecovered();
        ExtensionUpdateJournal::discard('module', 'web-report-module');
    }

    /**
     * 대기 폴더·진행 기록을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->pendingDir);
        ExtensionUpdateJournal::discard('module', 'web-report-module');
        StuckUpdateRecovery::takeRecovered();

        parent::tearDown();
    }

    /**
     * 지정된 권한을 가진 사용자를 만듭니다.
     *
     * @param  array<int, string>  $permissionIdentifiers  권한 식별자 목록
     * @return User 사용자
     */
    private function makeUserWithPermissions(array $permissionIdentifiers): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'identifier' => 'web_update_report_'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '', 'en' => ''],
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('identifier', $permissionIdentifiers)->pluck('id')->all());
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * 모듈 서비스 결과에 쓰는 모듈 정보.
     *
     * @param  string  $version  판
     * @return array<string, mixed> 모듈 정보
     */
    private function moduleInfo(string $version): array
    {
        return [
            'identifier' => 'web-report-module',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 모듈', 'en' => 'Test Module'],
            'version' => $version,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'dependencies' => [],
            'status' => 'active',
            'update_available' => false,
        ];
    }

    /**
     * 성공 응답에 터미널과 같은 판 변화·의존 경고·멈춘 업데이트 정리가 실리고, no_update 는 false 다.
     *
     * @scenario source=module, path=web_update_success, unit=web_response
     *
     * @effects web_update_success_reports_versions_and_warnings
     *
     * @return void 반환값 없음
     */
    public function test_success_response_carries_versions_dependency_warnings_and_recovered(): void
    {
        $this->mock(ModuleService::class, function ($mock): void {
            $mock->shouldReceive('updateModule')->once()->andReturnUsing(function () {
                // 매니저가 이번 요청에서 멈춘 업데이트를 정리했다 — 터미널 「정리했습니다」와 같은 정보
                $reflection = new \ReflectionProperty(StuckUpdateRecovery::class, 'recovered');
                $reflection->setValue(null, [[
                    'type' => 'module', 'identifier' => 'web-report-module', 'status' => 'updating',
                    'unfinished' => ['from' => '1.0.0', 'to' => '1.1.0'],
                ]]);

                return [
                    'success' => true,
                    'from_version' => '1.0.0',
                    'to_version' => '1.1.0',
                    'dependency_warnings' => [['identifier' => 'other-module', 'required' => '>=2.0.0', 'installed' => '1.0.0']],
                    'module_info' => $this->moduleInfo('1.1.0'),
                ];
            });
        });
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/modules/web-report-module/update');

        $response->assertOk()
            ->assertJsonPath('data.from_version', '1.0.0')
            ->assertJsonPath('data.to_version', '1.1.0')
            ->assertJsonPath('data.dependency_warnings.0.identifier', 'other-module')
            ->assertJsonPath('data.recovered.0.identifier', 'web-report-module')
            ->assertJsonPath('data.recovered.0.activate_command', 'php artisan module:activate web-report-module')
            ->assertJsonPath('data.recovered.0.unfinished.to', '1.1.0')
            ->assertJsonPath('data.no_update', false)
            ->assertJsonPath('data.layout_warnings', []);
    }

    /**
     * 업데이트할 것이 없었으면 data.no_update 가 true 다 — 화면은 성공이 아니라 안내로 보인다.
     *
     * @scenario source=module, path=web_update_success, unit=web_response
     *
     * @effects web_update_no_update_is_flagged
     *
     * @return void 반환값 없음
     */
    public function test_no_update_is_flagged(): void
    {
        $this->mock(ModuleService::class, function ($mock): void {
            $mock->shouldReceive('updateModule')->once()->andReturn([
                'success' => false,
                'from_version' => '1.0.0',
                'to_version' => '1.0.0',
                'module_info' => $this->moduleInfo('1.0.0'),
            ]);
        });
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/modules/web-report-module/update');

        $response->assertOk()
            ->assertJsonPath('data.no_update', true)
            ->assertJsonPath('data.recovered', null)
            ->assertJsonPath('message', __('modules.no_update_available'));
    }

    /**
     * 실패 응답은 422 모양을 유지하면서 data 에 실패 뒤 상태·경고·재실행 명령을 싣는다.
     *
     * @scenario source=module, path=web_update_failure, unit=web_response
     *
     * @effects web_update_failure_reports_outcome_and_retry
     *
     * @return void 반환값 없음
     */
    public function test_failure_response_carries_outcome_warnings_and_retry_command(): void
    {
        $this->mock(ModuleService::class, function ($mock): void {
            $mock->shouldReceive('updateModule')->once()->andThrow(ExtensionUpdateRequestFailedException::make(
                'module_name',
                '모듈 업데이트 실패',
                ['outcome' => 'kept_new', 'warnings' => [['step' => 'finalize', 'message' => 'x']], 'retry_command' => 'php artisan module:update web-report-module --force'],
            ));
        });
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/admin/modules/web-report-module/update');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.module_name.0', '모듈 업데이트 실패')
            ->assertJsonPath('data.outcome', 'kept_new')
            ->assertJsonPath('data.warnings.0.step', 'finalize')
            ->assertJsonPath('data.retry_command', 'php artisan module:update web-report-module --force');
    }

    /**
     * 실패 값은 매니저 예외의 outcome·이번 요청 경고로 만들고, 새 판이 남았으면 재실행 명령에 --force·고른 소스를 붙인다.
     * 시작 전 거절(outcome 없음)은 재실행 명령을 만들지 않는다.
     *
     * @scenario source=module, path=web_update_failure, unit=web_response
     *
     * @effects web_update_failure_reports_outcome_and_retry
     *
     * @return void 반환값 없음
     */
    public function test_failure_fields_follow_manager_outcome(): void
    {
        LifecycleWarnings::current()->add('upgrade_step', 'web-report-module', '스텝 경고');

        $fields = ExtensionUpdateReport::failureFields(
            new ExtensionUpdateFailedException('실패', 'kept_new'),
            'module',
            'web-report-module',
            ['source' => 'github', 'vendor_mode' => 'auto'],
        );

        $this->assertSame('kept_new', $fields['outcome']);
        $this->assertNotEmpty($fields['warnings']);
        $this->assertSame('php artisan module:update web-report-module --force --source=github', $fields['retry_command']);

        $refused = ExtensionUpdateReport::failureFields(new \RuntimeException('거절'), 'module', 'web-report-module');
        $this->assertNull($refused['outcome']);
        $this->assertNull($refused['retry_command']);

        $this->assertSame(
            'php artisan module:update web-report-module',
            ExtensionUpdateReport::retryCommand('module', 'web-report-module', 'restored'),
        );
    }

    /**
     * 목록 행은 이 서버의 진행 기록이 남아 있으면 unfinished_update 를, 없으면 null 을 싣는다.
     *
     * @scenario source=module, path=web_update_list, unit=web_response
     *
     * @effects web_update_list_shows_unfinished_update
     *
     * @return void 반환값 없음
     */
    public function test_list_row_shows_unfinished_update(): void
    {
        $request = Request::create('/api/admin/modules');

        $clean = (new ModuleResource($this->moduleInfo('1.0.0')))->resolve($request);
        $this->assertArrayHasKey('unfinished_update', $clean);
        $this->assertNull($clean['unfinished_update']);

        ExtensionUpdateJournal::begin('module', 'web-report-module', '1.0.0', '1.1.0', 'bundled');

        $row = (new ModuleResource($this->moduleInfo('1.1.0')))->resolve($request);
        $this->assertSame('1.0.0', $row['unfinished_update']['from_version']);
        $this->assertSame('1.1.0', $row['unfinished_update']['to_version']);
        $this->assertNotEmpty($row['unfinished_update']['started_at']);
    }

    /**
     * 요청 본문의 vendor_mode·source·zip_file·force 가 서비스에 그대로 닿는다 — ZIP 은 대기 폴더 안 절대 경로로 바뀐다.
     *
     * @scenario source=module, path=web_update_input, unit=web_response
     *
     * @effects web_update_passes_source_zip_force
     *
     * @return void 반환값 없음
     */
    public function test_module_update_passes_vendor_mode_source_zip_and_force(): void
    {
        File::put($this->pendingDir.DIRECTORY_SEPARATOR.'web-report-module-1.1.0.zip', 'zip');
        $expectedZip = realpath($this->pendingDir.DIRECTORY_SEPARATOR.'web-report-module-1.1.0.zip');

        $this->mock(ModuleService::class, function ($mock) use ($expectedZip): void {
            $mock->shouldReceive('updateModule')
                ->once()
                ->with(
                    'web-report-module',
                    Mockery::on(fn ($mode) => $mode === VendorMode::Bundled),
                    'overwrite',
                    true,
                    Mockery::any(),
                    'github',
                    $expectedZip,
                )
                ->andReturn(['success' => true, 'from_version' => '1.0.0', 'to_version' => '1.1.0', 'module_info' => $this->moduleInfo('1.1.0')]);
        });
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/admin/modules/web-report-module/update', [
            'vendor_mode' => 'bundled',
            'source' => 'github',
            'zip_file' => 'web-report-module-1.1.0.zip',
            'force' => true,
        ])->assertOk();

        // 대기 폴더 목록 — 화면의 ZIP 선택지(서버 경로는 싣지 않는다)
        $this->getJson('/api/admin/modules/update-sources/manual-zip')
            ->assertOk()
            ->assertJsonPath('data.sources.0.name', 'web-report-module-1.1.0.zip');
    }

    /**
     * 대기 폴더에 없는 ZIP·경로가 든 이름·잘못된 소스는 서비스를 부르지 않고 422 로 거절한다.
     *
     * @scenario source=module, path=web_update_input, unit=web_response
     *
     * @effects web_update_rejects_unknown_zip
     *
     * @return void 반환값 없음
     */
    public function test_module_update_rejects_zip_outside_pending_folder(): void
    {
        $this->mock(ModuleService::class, function ($mock): void {
            $mock->shouldNotReceive('updateModule');
        });
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/admin/modules/web-report-module/update', ['zip_file' => 'missing.zip'])
            ->assertStatus(422)
            ->assertJsonPath('errors.zip_file.0', __('extensions.errors.manual_zip_not_found', ['name' => 'missing.zip']));

        $this->postJson('/api/admin/modules/web-report-module/update', ['zip_file' => '../escape.zip'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('zip_file');

        $this->postJson('/api/admin/modules/web-report-module/update', ['source' => 'ftp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');
    }

    /**
     * 템플릿 업데이트도 source 를 서비스에 넘긴다 — 템플릿은 vendor 방식이 없어 인자 자리가 다르다.
     *
     * @scenario source=template, path=web_update_input, unit=web_response
     *
     * @effects web_update_passes_source_zip_force
     *
     * @return void 반환값 없음
     */
    public function test_template_update_passes_source(): void
    {
        $this->mock(TemplateService::class, function ($mock): void {
            $mock->shouldReceive('performVersionUpdate')
                ->once()
                ->with('web-report-template', 'overwrite', false, Mockery::any(), 'bundled')
                ->andReturn(['success' => true, 'from_version' => '1.0.0', 'to_version' => '1.0.1']);
        });
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/admin/templates/web-report-template/update', ['source' => 'bundled'])
            ->assertOk()
            ->assertJsonPath('data.from_version', '1.0.0')
            ->assertJsonPath('data.to_version', '1.0.1')
            ->assertJsonPath('data.no_update', false);
    }

    /**
     * 활성 코어 ja 언어팩을 만듭니다.
     *
     * @return LanguagePack 언어팩
     */
    private function makePack(): LanguagePack
    {
        return LanguagePack::query()->create([
            'identifier' => 'sirsoft-core-ja',
            'vendor' => 'sirsoft',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
    }

    /**
     * 언어팩 업데이트는 force·source 를 서비스에 넘기고, 성공 응답에 판 변화를 싣는다.
     *
     * @scenario source=language_pack, path=web_update_input, unit=web_response
     *
     * @effects language_pack_update_passes_source
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_update_passes_force_and_source(): void
    {
        $pack = $this->makePack();
        $updated = (clone $pack)->forceFill(['version' => '1.0.1']);

        $this->mock(LanguagePackService::class, function ($mock) use ($pack, $updated): void {
            $mock->shouldReceive('find')->andReturn($pack);
            $mock->shouldReceive('performUpdate')
                ->once()
                ->with(Mockery::type(LanguagePack::class), true, Mockery::any(), 'github')
                ->andReturn($updated);
        });
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/admin/language-packs/'.$pack->id.'/update', ['force' => true, 'source' => 'github'])
            ->assertOk()
            ->assertJsonPath('data.from_version', '1.0.0')
            ->assertJsonPath('data.to_version', '1.0.1')
            ->assertJsonPath('data.no_update', false);
    }

    /**
     * 「이미 최신」 언어팩 업데이트는 422 를 유지하면서 data.no_update=true 로 안내임을 알린다.
     *
     * @scenario source=language_pack, path=web_update_failure, unit=web_response
     *
     * @effects web_update_no_update_is_flagged
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_already_latest_is_flagged_as_no_update(): void
    {
        $pack = $this->makePack();
        $this->mock(LanguagePackService::class, function ($mock) use ($pack): void {
            $mock->shouldReceive('find')->andReturn($pack);
            $mock->shouldReceive('performUpdate')->andThrow(new LanguagePackOperationException('language_packs.errors.update_already_latest'));
        });
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/admin/language-packs/'.$pack->id.'/update')
            ->assertStatus(422)
            ->assertJsonPath('message', __('language_packs.errors.update_already_latest'))
            ->assertJsonPath('data.no_update', true);
    }

    /**
     * 터미널 `language-pack:update --source=github` 이 서비스에 소스를 넘긴다 — 종전에는 넘기지 않아 효과가 없었다.
     *
     * @scenario source=language_pack, path=web_update_input, unit=web_response
     *
     * @effects language_pack_update_passes_source
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_command_passes_source_to_service(): void
    {
        $pack = $this->makePack();
        $updated = (clone $pack)->forceFill(['version' => '1.0.1']);

        $service = Mockery::mock(LanguagePackService::class);
        $service->shouldReceive('findByIdentifier')->with('sirsoft-core-ja')->andReturn($pack);
        $service->shouldReceive('performUpdate')
            ->once()
            ->with(Mockery::type(LanguagePack::class), false, Mockery::any(), 'github')
            ->andReturn($updated);

        // 콘솔 커널은 명령 객체를 부팅 때 만들어 둔다 — 컨테이너 바인딩만 바꾸면 옛 서비스가 그대로 쓰인다.
        // 가짜 서비스를 받은 명령을 같은 이름으로 다시 등록한다.
        $command = new \App\Console\Commands\LanguagePack\UpdateLanguagePackCommand($service);
        $command->setLaravel($this->app);
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($command);

        $this->artisan('language-pack:update', ['identifier' => 'sirsoft-core-ja', '--source' => 'github'])
            ->assertExitCode(0);
    }
}
