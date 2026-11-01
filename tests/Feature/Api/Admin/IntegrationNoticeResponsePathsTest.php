<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 관리자 업데이트·파일 설치·GitHub 설치 응답에도 선택 연동 안내가 실린다 (#822 D10·§3.3)
 *
 * 설치 창(일반 설치)·활성화 응답만 `integration_notices` 를 싣고, 업데이트 응답과 ZIP·GitHub 설치 응답은
 * 빠뜨려 관리자 화면에서 안내가 사라졌다(매니저 결과·Artisan 출력에는 있었음). 세 확장 유형 × 세 경로를 모두 잰다.
 */
class IntegrationNoticeResponsePathsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, class-string> 유형별 서비스 */
    private const SERVICES = [
        'modules' => ModuleService::class,
        'plugins' => PluginService::class,
        'templates' => TemplateService::class,
    ];

    /** @var array<string, string> 유형별 업데이트 서비스 메서드 */
    private const UPDATE_METHODS = [
        'modules' => 'updateModule',
        'plugins' => 'updatePlugin',
        'templates' => 'performVersionUpdate',
    ];

    /** @var array<string, string> 유형별 업데이트 결과의 확장 정보 키 */
    private const INFO_KEYS = [
        'modules' => 'module_info',
        'plugins' => 'plugin_info',
        'templates' => 'template_info',
    ];

    private string $token;

    /**
     * 세 유형의 설치 권한을 가진 관리자와 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->createAdmin()->createToken('test-token')->plainTextToken;
    }

    /**
     * 세 확장 유형을 돌려줍니다.
     *
     * @return array<string, array{0: string}> 유형 세그먼트
     */
    public static function types(): array
    {
        return [
            '모듈' => ['modules'],
            '플러그인' => ['plugins'],
            '템플릿' => ['templates'],
        ];
    }

    /**
     * 업데이트 응답(확장 정보가 있는 일반 경로)이 매니저가 낸 연동 안내를 `data.integration_notices` 와 완료 문구 한 줄로 싣는다.
     *
     * @scenario case=update_with_info
     *
     * @effects update_response_carries_integration_notices
     *
     * @param  string  $segment  확장 유형 세그먼트
     * @return void 반환값 없음
     */
    #[DataProvider('types')]
    public function test_update_response_carries_integration_notices(string $segment): void
    {
        $notice = $this->notice();
        $service = Mockery::mock(self::SERVICES[$segment]);
        $service->shouldReceive(self::UPDATE_METHODS[$segment])->once()->andReturn([
            'success' => true,
            'from_version' => '1.0.0',
            'to_version' => '2.0.0',
            self::INFO_KEYS[$segment] => $this->info($segment),
            'integration_notices' => [$notice],
        ]);
        $this->app->instance(self::SERVICES[$segment], $service);

        $response = $this->authRequest()->postJson("/api/admin/{$segment}/test-ext/update");

        $response->assertStatus(200)
            ->assertJsonPath('data.integration_notices.0.identifier', 'sirsoft-zz-target')
            ->assertJsonPath('data.integration_notices.0.state', 'not_installed');
        $this->assertStringContainsString($notice['message'], (string) $response->json('message'));
    }

    /**
     * 확장 정보가 없는 업데이트 응답도 안내 문구를 완료 문구 뒤에 잇는다.
     *
     * @scenario case=update_without_info
     *
     * @effects update_response_carries_integration_notices
     *
     * @param  string  $segment  확장 유형 세그먼트
     * @return void 반환값 없음
     */
    #[DataProvider('types')]
    public function test_update_response_without_info_appends_notice_line(string $segment): void
    {
        $notice = $this->notice();
        $service = Mockery::mock(self::SERVICES[$segment]);
        $service->shouldReceive(self::UPDATE_METHODS[$segment])->once()->andReturn([
            'success' => true,
            'from_version' => '1.0.0',
            'to_version' => '2.0.0',
            'integration_notices' => [$notice],
        ]);
        $this->app->instance(self::SERVICES[$segment], $service);

        $response = $this->authRequest()->postJson("/api/admin/{$segment}/test-ext/update");

        $response->assertStatus(200)->assertJsonPath('data.integration_notices.0.identifier', 'sirsoft-zz-target');
        $this->assertStringContainsString($notice['message'], (string) $response->json('message'));
    }

    /**
     * ZIP 파일 설치 응답이 `data.integration_notices` 를 싣는다(일반 설치 창과 같은 계약).
     *
     * @scenario case=install_from_file
     *
     * @effects install_paths_carry_integration_notices
     *
     * @param  string  $segment  확장 유형 세그먼트
     * @return void 반환값 없음
     */
    #[DataProvider('types')]
    public function test_install_from_file_response_carries_integration_notices(string $segment): void
    {
        $service = Mockery::mock(self::SERVICES[$segment]);
        $service->shouldReceive('installFromZipFile')->once()->andReturn($this->info($segment));
        $this->app->instance(self::SERVICES[$segment], $service);

        $response = $this->authRequest()->post("/api/admin/{$segment}/install-from-file", [
            'file' => UploadedFile::fake()->create('ext.zip', 10, 'application/zip'),
        ]);

        $response->assertStatus(201);
        $this->assertIsArray($response->json('data.integration_notices'));
    }

    /**
     * GitHub 설치 응답이 `data.integration_notices` 를 싣는다.
     *
     * @scenario case=install_from_github
     *
     * @effects install_paths_carry_integration_notices
     *
     * @param  string  $segment  확장 유형 세그먼트
     * @return void 반환값 없음
     */
    #[DataProvider('types')]
    public function test_install_from_github_response_carries_integration_notices(string $segment): void
    {
        $service = Mockery::mock(self::SERVICES[$segment]);
        $service->shouldReceive('installFromGithub')->once()->andReturn($this->info($segment));
        $this->app->instance(self::SERVICES[$segment], $service);

        $response = $this->authRequest()->postJson("/api/admin/{$segment}/install-from-github", [
            'github_url' => 'https://github.com/example/test-ext',
        ]);

        $response->assertStatus(201);
        $this->assertIsArray($response->json('data.integration_notices'));
    }

    /**
     * 미설치 연동 대상 안내 한 건을 만듭니다.
     *
     * @return array<string, string|null> 안내 항목
     */
    private function notice(): array
    {
        return [
            'type' => 'module',
            'identifier' => 'sirsoft-zz-target',
            'name' => 'ZZ 대상',
            'state' => 'not_installed',
            'required_version' => '>=1.0.0',
            'installed_version' => null,
            'message' => 'ZZ 대상 연동 안내 822',
        ];
    }

    /**
     * 리소스가 읽는 최소 확장 정보를 만듭니다.
     *
     * @param  string  $segment  확장 유형 세그먼트
     * @return array<string, mixed> 확장 정보
     */
    private function info(string $segment): array
    {
        $info = [
            'identifier' => 'test-ext',
            'vendor' => 'test',
            'name' => ['ko' => '시험 확장', 'en' => 'Test Extension'],
            'version' => '2.0.0',
            'description' => ['ko' => '시험용', 'en' => 'For testing'],
            'dependencies' => [],
            'status' => 'active',
            'assets' => null,
            'update_available' => false,
            'update_source' => null,
            'latest_version' => null,
            'file_version' => null,
            'github_url' => null,
            'github_changelog_url' => null,
            'is_pending' => false,
            'is_bundled' => false,
        ];

        if ($segment === 'templates') {
            $info['type'] = 'user';
        }

        return $info;
    }

    /**
     * 세 유형의 읽기·설치 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function createAdmin(): User
    {
        $user = User::factory()->create();
        $permissionIds = [];
        foreach (['modules', 'plugins', 'templates'] as $type) {
            foreach (['read', 'install'] as $action) {
                $identifier = "core.{$type}.{$action}";
                $permissionIds[] = Permission::firstOrCreate(['identifier' => $identifier], [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ])->id;
            }
        }

        $role = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => json_encode(['ko' => '시험 관리자', 'en' => 'Test Admin']),
            'description' => json_encode(['ko' => '시험 관리자', 'en' => 'Test Admin']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
            'description' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * Bearer 토큰과 JSON Accept 헤더를 단 요청을 돌려줍니다.
     *
     * @return static 요청 빌더
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }
}
