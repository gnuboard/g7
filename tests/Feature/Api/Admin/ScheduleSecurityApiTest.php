<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Helpers\PermissionHelper;
use App\Jobs\GenerateSitemapJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Schedule\ScheduleExecutor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 스케줄 보안 정책 조회 API·상태 API 의 Shell 사용 여부·덮어쓰기의 저장/실행 반영 테스트
 *
 * 덮어쓰기 파일은 `storage/framework/testing/` 아래 임시 이름으로 만든다 — 화면에 보이는 경로가 프로젝트 기준
 * 상대경로인지(서버 절대경로 미노출)까지 함께 본다.
 */
class ScheduleSecurityApiTest extends TestCase
{
    use DatabaseTransactions;

    private const SECURITY_URL = '/api/admin/schedules/security';

    /** 이 테스트가 쓰는 임시 덮어쓰기 파일 경로 */
    private string $overridePath;

    /**
     * 임시 덮어쓰기 경로를 정하고 권한 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->overridePath = $dir.DIRECTORY_SEPARATOR.'schedule_security_'.Str::lower(Str::random(10)).'.json';
        config(['schedule_security.override_file' => $this->overridePath]);
        $this->clearPermissionCache();
    }

    /**
     * 임시 덮어쓰기 파일을 지우고 권한 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (is_file($this->overridePath)) {
            @unlink($this->overridePath);
        }
        $this->clearPermissionCache();

        parent::tearDown();
    }

    /**
     * PermissionHelper 정적 캐시를 초기화합니다.
     *
     * @return void 반환값 없음
     */
    private function clearPermissionCache(): void
    {
        $prop = (new \ReflectionClass(PermissionHelper::class))->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * 주어진 권한만 가진 관리자의 Bearer 토큰을 만듭니다.
     *
     * @param  list<string>  $permissions  부여할 권한 식별자
     * @return string Bearer 토큰
     */
    private function tokenFor(array $permissions): string
    {
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'schedule_security_test_'.Str::lower(Str::random(10)),
            'name' => ['ko' => '보안 테스트', 'en' => 'Security test'],
            'is_active' => true,
        ]);
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $ids = [];
        foreach ($permissions as $identifier) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => 'core',
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }
        $role->permissions()->sync($ids);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user->createToken('schedule-security-test')->plainTextToken;
    }

    /**
     * 스케줄 조회·등록 권한을 가진 관리자의 요청 빌더를 돌려줍니다.
     *
     * @return static 요청 빌더
     */
    private function asScheduleAdmin(): static
    {
        return $this->withToken($this->tokenFor(['core.schedules.read', 'core.schedules.create']))
            ->withHeader('Accept', 'application/json');
    }

    /**
     * 덮어쓰기 파일을 씁니다.
     *
     * @param  array<string, mixed>|string  $content  배열이면 JSON 으로, 문자열이면 그대로 쓴다
     * @return void 반환값 없음
     */
    private function writeOverride(array|string $content): void
    {
        file_put_contents($this->overridePath, is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_SLASHES));
    }

    /**
     * 보안 API 가 유효 정책을 항목별 출처(core/override)·Shell 정보·파일 상태와 함께 준다.
     *
     * @scenario override_state=valid, enforcement_point=api, permission=read
     *
     * @effects security_api_returns_effective_policy_with_sources, security_api_hides_absolute_paths
     *
     * @return void 반환값 없음
     */
    public function test_security_api_returns_effective_policy_with_sources(): void
    {
        config(['schedule_security.shell.enabled' => true, 'schedule_security.shell.allowed_binaries' => ['backup.sh']]);
        $this->writeOverride([
            'artisan' => [
                'allowlist' => ['about' => ['options' => ['only']], 'seo:prune-stats' => ['options' => ['chunk']]],
                'denylist' => ['inspire'],
                'denylist_prefixes' => ['seo:warm'],
            ],
            'job' => ['allowed_classes' => ['App\\Jobs\\OverrideOnlyJob']],
        ]);

        $response = $this->asScheduleAdmin()->getJson(self::SECURITY_URL)->assertOk();

        $response->assertJsonStructure(['data' => [
            'override_file' => ['path', 'exists', 'valid', 'error_code', 'error'],
            'shell' => ['enabled', 'gate_enabled', 'allowed_binaries', 'source'],
            'artisan' => [
                'allowlist' => [['command', 'options', 'max_arguments', 'source', 'override_options', 'denied']],
                'denylist' => [['command', 'source']],
                'denylist_prefixes' => [['prefix', 'source']],
                'allow_extension_commands',
            ],
            'job' => ['allowed_classes' => [['class', 'source']]],
            'url' => ['block_private_hosts', 'source'],
        ]]);

        $response->assertJsonPath('data.override_file.exists', true)
            ->assertJsonPath('data.override_file.valid', true)
            ->assertJsonPath('data.override_file.error', null)
            ->assertJsonPath('data.shell.enabled', true)
            ->assertJsonPath('data.shell.allowed_binaries', ['backup.sh'])
            ->assertJsonPath('data.shell.source', 'env');

        $allow = collect($response->json('data.artisan.allowlist'))->keyBy('command');
        $this->assertSame('core', $allow['cache:clear']['source']);
        $this->assertSame('override', $allow['about']['source']);
        $this->assertSame(['only'], $allow['about']['options']);
        $this->assertSame('core', $allow['seo:prune-stats']['source']);
        $this->assertSame(['chunk'], $allow['seo:prune-stats']['override_options']);
        $this->assertTrue($allow['inspire']['denied']);
        $this->assertFalse($allow['cache:clear']['denied']);

        $deny = collect($response->json('data.artisan.denylist'))->pluck('source', 'command');
        $this->assertSame('core', $deny['tinker']);
        $this->assertSame('override', $deny['inspire']);
        $prefixes = collect($response->json('data.artisan.denylist_prefixes'))->pluck('source', 'prefix');
        $this->assertSame('core', $prefixes['make:']);
        $this->assertSame('override', $prefixes['seo:warm']);

        $classes = collect($response->json('data.job.allowed_classes'))->pluck('source', 'class');
        $this->assertSame('core', $classes[GenerateSitemapJob::class]);
        $this->assertSame('override', $classes['App\\Jobs\\OverrideOnlyJob']);

        // 화면에는 프로젝트 기준 상대경로만 — 서버 절대경로를 내보내지 않는다
        $path = (string) $response->json('data.override_file.path');
        $this->assertStringStartsWith('storage/framework/testing/schedule_security_', $path);
        $this->assertStringNotContainsString(str_replace('\\', '/', base_path()), str_replace('\\', '/', $response->getContent()));
    }

    /**
     * 형식 오류 파일은 valid=false·오류 문구로 알리고, 정책은 코어 그대로다.
     *
     * @scenario override_state=invalid_json|locked_key, enforcement_point=api, permission=read
     *
     * @effects security_api_reports_invalid_override, invalid_override_is_ignored_entirely
     *
     * @return void 반환값 없음
     */
    public function test_security_api_reports_invalid_override(): void
    {
        $this->writeOverride('{"artisan": {"allowlist": {"about": {}}}, "shell": {"enabled": true}}');

        $response = $this->asScheduleAdmin()->getJson(self::SECURITY_URL)->assertOk();

        $response->assertJsonPath('data.override_file.exists', true)
            ->assertJsonPath('data.override_file.valid', false)
            ->assertJsonPath('data.override_file.error_code', 'not_overridable');
        $this->assertNotEmpty($response->json('data.override_file.error'));
        $this->assertNotContains('about', collect($response->json('data.artisan.allowlist'))->pluck('command')->all());
    }

    /**
     * 파일이 없으면 exists=false·valid=true 이고 모든 항목 출처는 core 다.
     *
     * @scenario override_state=missing, enforcement_point=api, permission=read
     *
     * @effects security_api_returns_effective_policy_with_sources
     *
     * @return void 반환값 없음
     */
    public function test_security_api_without_override_file(): void
    {
        $response = $this->asScheduleAdmin()->getJson(self::SECURITY_URL)->assertOk();

        $response->assertJsonPath('data.override_file.exists', false)
            ->assertJsonPath('data.override_file.valid', true);
        $sources = collect($response->json('data.artisan.allowlist'))
            ->merge($response->json('data.artisan.denylist'))
            ->pluck('source')->unique()->values()->all();
        $this->assertSame(['core'], $sources);
    }

    /**
     * 보안 API 는 비인증 401, core.schedules.read 가 없는 역할은 403 이다.
     *
     * @scenario enforcement_point=api, permission=none
     *
     * @effects security_api_requires_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_security_api_requires_read_permission(): void
    {
        $this->getJson(self::SECURITY_URL)->assertStatus(401);

        $this->withToken($this->tokenFor(['core.queue_jobs.read']))
            ->getJson(self::SECURITY_URL)
            ->assertStatus(403);
    }

    /**
     * 상태 API 가 shell_enabled 를 싣는다(게이트 on + 허용 실행 파일 1개 이상일 때만 true).
     *
     * @scenario target=shell, enforcement_point=api, permission=read
     *
     * @effects status_api_exposes_shell_enabled
     *
     * @return void 반환값 없음
     */
    public function test_status_api_exposes_shell_enabled(): void
    {
        config(['schedule_security.shell.enabled' => false, 'schedule_security.shell.allowed_binaries' => ['backup.sh']]);
        $api = $this->asScheduleAdmin();
        $api->getJson('/api/admin/schedules/status')->assertOk()->assertJsonPath('data.shell_enabled', false);
        $api->getJson(self::SECURITY_URL)->assertOk()->assertJsonPath('data.shell.enabled', false)
            ->assertJsonPath('data.shell.gate_enabled', false);

        config(['schedule_security.shell.enabled' => true]);
        $api->getJson('/api/admin/schedules/status')->assertOk()->assertJsonPath('data.shell_enabled', true);
    }

    /**
     * 덮어쓰기로 더한 Job 클래스는 저장 검증을 통과하고 실행기도 실행한다(같은 해석기).
     *
     * @scenario override_state=valid, target=job_classes, enforcement_point=store|run
     *
     * @effects override_adds_job_allowed_classes
     *
     * @return void 반환값 없음
     */
    public function test_override_job_class_passes_store_and_run(): void
    {
        config(['schedule_security.job.allowed_classes' => []]);
        Bus::fake([GenerateSitemapJob::class]);
        $payload = [
            'name' => 'sec'.Str::lower(Str::random(8)),
            'label' => ['ko' => '보안 덮어쓰기 Job', 'en' => 'Override job'],
            'type' => 'job',
            'command' => GenerateSitemapJob::class,
            'expression' => '0 0 * * *',
            'frequency' => 'daily',
        ];

        $api = $this->asScheduleAdmin();
        $api->postJson('/api/admin/schedules', $payload)->assertStatus(422)->assertJsonValidationErrors('command');

        $schedule = Schedule::factory()->create(['type' => 'job', 'command' => GenerateSitemapJob::class, 'dispatch_mode' => 'sync'])->fresh();
        $blocked = app(ScheduleExecutor::class)->execute($schedule, ScheduleTriggerType::Scheduled);
        $this->assertSame(ScheduleResultStatus::Failed, $blocked->status);

        $this->writeOverride(['job' => ['allowed_classes' => [GenerateSitemapJob::class]]]);

        $api->postJson('/api/admin/schedules', $payload)->assertCreated();
        $allowed = app(ScheduleExecutor::class)->execute($schedule->fresh(), ScheduleTriggerType::Scheduled);
        $this->assertSame(ScheduleResultStatus::Success, $allowed->status);
        Bus::assertDispatchedSync(GenerateSitemapJob::class);
    }
}
