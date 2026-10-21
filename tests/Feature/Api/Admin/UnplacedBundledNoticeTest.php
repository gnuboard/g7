<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Core\BundledSourcePlanner;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 미배치 공식 번들 안내 API 테스트 (공개 #146)
 *
 * GET  /api/admin/extensions/unplaced-bundled
 * POST /api/admin/extensions/unplaced-bundled/{type}/dismiss
 *
 * 스키마 재생성을 피하려고 DatabaseTransactions 로 격리하고(로컬 MySQL DDL OOM), 기록 파일은 격리 storage,
 * 설치·디스크 상태는 판정 서비스 대역으로 흉내 냅니다.
 */
class UnplacedBundledNoticeTest extends TestCase
{
    use DatabaseTransactions;

    private string $root;

    private string $originalStoragePath;

    /** @var array<string, bool> 디스크에 있다고 칠 `{type}:{id}` */
    private array $onDisk = [];

    /** @var array<string, array<int, string>> 유형별 설치 식별자 */
    private array $installed = ['module' => [], 'plugin' => [], 'template' => []];

    /**
     * 격리 storage 와 판정 서비스 대역, 기본 기록 파일을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = storage_path('test_unplaced_notice_'.uniqid());
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'logs');
        $this->originalStoragePath = storage_path();
        app()->useStoragePath($this->root);
        config(['app.version' => '7.1.1']);

        $this->app->instance(BundledSourcePlanner::class, $this->planner());
        $this->writeRecord('7.1.1');
    }

    /**
     * storage 경로를 되돌리고 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);
        if (File::isDirectory($this->root)) {
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    /**
     * 네 조회 권한을 가진 관리자는 유형별 안내·해시·코어 버전·복사용 명령을 받고, 숨김 샘플은 빠집니다.
     *
     * @scenario hidden=true
     *
     * @effects notice_lists_unplaced_by_type_without_hidden
     *
     * @return void 반환값 없음
     */
    public function test_index_returns_grouped_items_without_hidden(): void
    {
        $response = $this->actingAsAdmin($this->allReadPermissions())->getJson('/api/admin/extensions/unplaced-bundled');

        $response->assertOk()
            ->assertJsonPath('data.core_version', '7.1.1')
            ->assertJsonPath('data.items.plugins.0.identifier', 'g7test-unplaced_probe')
            ->assertJsonPath('data.items.plugins.0.name', '프로브')
            ->assertJsonPath('data.items.plugins.0.version', '1.0.0')
            ->assertJsonPath('data.items.modules.0.identifier', 'g7test-unplaced_mod')
            ->assertJsonPath('data.items.templates.0.identifier', 'g7test-unplaced_tpl')
            ->assertJsonPath('data.items.language_packs.0.identifier', 'g7-plugin-g7test-unplaced_probe-ja')
            ->assertJsonPath('data.commands.plugins', 'php artisan core:bundled-sources place g7test-unplaced_probe')
            ->assertJsonCount(1, 'data.items.plugins');
        $this->assertNotNull($response->json('data.hash'));
    }

    /**
     * 플러그인 조회 권한만 있으면 플러그인 안내만 채우고 나머지 유형은 비웁니다.
     *
     * @scenario domain=plugin
     *
     * @effects notice_hides_types_without_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_index_empties_types_without_permission(): void
    {
        $response = $this->actingAsAdmin(['core.plugins.read'])->getJson('/api/admin/extensions/unplaced-bundled');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items.plugins')
            ->assertJsonCount(0, 'data.items.modules')
            ->assertJsonCount(0, 'data.items.templates')
            ->assertJsonCount(0, 'data.items.language_packs');
    }

    /**
     * 조회 권한이 하나도 없으면 403, 로그인하지 않으면 401 입니다.
     *
     * @effects notice_requires_auth_and_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_index_requires_auth_and_permission(): void
    {
        $this->getJson('/api/admin/extensions/unplaced-bundled')->assertStatus(401);
        $this->actingAsAdmin(['core.users.read'])->getJson('/api/admin/extensions/unplaced-bundled')->assertStatus(403);
    }

    /**
     * 기록의 코어 버전이 다르거나 파일이 없거나 손상됐으면 빈 결과 200 입니다(손상은 경고 로그).
     *
     * @scenario source_kind=version_mismatch
     *
     * @effects notice_empty_for_mismatch_missing_or_corrupt_record
     *
     * @param  string  $state  기록 상태 (mismatch|missing|corrupt)
     * @return void 반환값 없음
     */
    #[DataProvider('recordStateProvider')]
    public function test_index_empty_for_unusable_record(string $state): void
    {
        $path = $this->planner()->recordPath();
        match ($state) {
            'mismatch' => $this->writeRecord('7.0.9'),
            'missing' => File::delete($path),
            'corrupt' => File::put($path, '{not json'),
        };

        $response = $this->actingAsAdmin($this->allReadPermissions())->getJson('/api/admin/extensions/unplaced-bundled');

        $response->assertOk()
            ->assertJsonCount(0, 'data.items.plugins')
            ->assertJsonCount(0, 'data.items.modules')
            ->assertJsonPath('data.hash', null);
    }

    /**
     * 기록 상태 변종을 돌려줍니다.
     *
     * @return array<string, array{0: string}> 데이터셋
     */
    public static function recordStateProvider(): array
    {
        return [
            'mismatch' => ['mismatch'],
            'missing' => ['missing'],
            'corrupt' => ['corrupt'],
        ];
    }

    /**
     * 기록 뒤에 디스크에 생겼거나(place) 설치된 번들은 안내에서 빠집니다.
     *
     * @scenario disk_state=present
     *
     * @effects notice_excludes_now_on_disk_or_installed
     *
     * @return void 반환값 없음
     */
    public function test_index_excludes_now_on_disk_or_installed(): void
    {
        $this->onDisk['plugin:g7test-unplaced_probe'] = true;
        $this->installed['module'] = ['g7test-unplaced_mod'];
        $this->app->instance(BundledSourcePlanner::class, $this->planner());

        $response = $this->actingAsAdmin($this->allReadPermissions())->getJson('/api/admin/extensions/unplaced-bundled');

        $response->assertOk()
            ->assertJsonCount(0, 'data.items.plugins')
            ->assertJsonCount(0, 'data.items.modules')
            ->assertJsonCount(1, 'data.items.templates');
    }

    /**
     * 닫기는 그 관리자·그 유형에만 적용되고, 다른 관리자와 다른 유형은 그대로이며, 새 기록(해시 변경)이면 다시 보입니다.
     *
     * @effects dismiss_is_per_admin_per_type_until_hash_changes
     *
     * @return void 반환값 없음
     */
    public function test_dismiss_is_per_admin_and_type_until_hash_changes(): void
    {
        $a = $this->makeAdmin($this->allReadPermissions());
        $b = $this->makeAdmin(['core.plugins.read']);

        $this->actingAs($a)->postJson('/api/admin/extensions/unplaced-bundled/plugin/dismiss')
            ->assertOk()
            ->assertJsonPath('data.type', 'plugin');

        $this->actingAs($a)->getJson('/api/admin/extensions/unplaced-bundled')
            ->assertJsonCount(0, 'data.items.plugins')
            ->assertJsonCount(1, 'data.items.modules');
        $this->actingAs($b)->getJson('/api/admin/extensions/unplaced-bundled')
            ->assertJsonCount(1, 'data.items.plugins');

        // 새 기록(항목 추가) → 해시가 바뀌어 다시 보인다
        $this->writeRecord('7.1.1', extraPlugin: true);
        $this->actingAs($a)->getJson('/api/admin/extensions/unplaced-bundled')
            ->assertJsonCount(2, 'data.items.plugins');
    }

    /**
     * 닫기는 그 유형의 조회 권한이 필요하고(없으면 403), 언어팩 유형도 닫을 수 있으며, 알 수 없는 유형은 404 입니다.
     *
     * @effects dismiss_requires_type_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_dismiss_permission_and_types(): void
    {
        $b = $this->makeAdmin(['core.plugins.read']);
        $this->actingAs($b)->postJson('/api/admin/extensions/unplaced-bundled/module/dismiss')->assertStatus(403);

        $a = $this->makeAdmin($this->allReadPermissions());
        $this->actingAs($a)->postJson('/api/admin/extensions/unplaced-bundled/language_pack/dismiss')->assertOk();
        $this->actingAs($a)->getJson('/api/admin/extensions/unplaced-bundled')->assertJsonCount(0, 'data.items.language_packs');
        $this->actingAs($a)->postJson('/api/admin/extensions/unplaced-bundled/widget/dismiss')->assertStatus(404);
    }

    /**
     * 기록 파일을 씁니다.
     *
     * @param  string  $version  기록의 코어 버전
     * @param  bool  $extraPlugin  플러그인 항목을 하나 더 넣을지 (해시 변경용)
     * @return void 반환값 없음
     */
    private function writeRecord(string $version, bool $extraPlugin = false): void
    {
        $unplaced = [
            ['type' => 'plugin', 'identifier' => 'g7test-unplaced_probe', 'name' => ['ko' => '프로브', 'en' => 'Probe'], 'version' => '1.0.0', 'hidden' => false],
            ['type' => 'plugin', 'identifier' => 'g7test-unplaced_hidden', 'name' => ['ko' => '샘플'], 'version' => '1.0.0', 'hidden' => true],
            ['type' => 'module', 'identifier' => 'g7test-unplaced_mod', 'name' => ['ko' => '모듈'], 'version' => '1.0.0', 'hidden' => false],
            ['type' => 'template', 'identifier' => 'g7test-unplaced_tpl', 'name' => ['ko' => '템플릿'], 'version' => '1.0.0', 'hidden' => false],
            ['type' => 'language_pack', 'identifier' => 'g7-plugin-g7test-unplaced_probe-ja', 'name' => ['ko' => '언어팩'], 'version' => '1.0.0', 'hidden' => false],
        ];
        if ($extraPlugin) {
            $unplaced[] = ['type' => 'plugin', 'identifier' => 'g7test-unplaced_probe2', 'name' => ['ko' => '프로브2'], 'version' => '1.0.0', 'hidden' => false];
        }

        $path = $this->planner()->recordPath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode([
            'schema' => 1, 'core_version' => $version, 'recorded_at' => now()->toIso8601String(), 'source' => 'core:update',
            'unplaced' => $unplaced, 'placed_by_dependency' => [], 'placed_by_selection' => [],
            'hash' => BundledSourcePlanner::hashUnplaced($unplaced),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 설치·디스크 상태를 흉내 내는 판정 서비스 대역을 만듭니다.
     *
     * @return BundledSourcePlanner 판정 서비스 대역
     */
    private function planner(): BundledSourcePlanner
    {
        $repo = function (string $class, array $ids) {
            $mock = Mockery::mock($class);
            $mock->shouldReceive('getInstalledIdentifiers')->andReturn($ids);

            return $mock;
        };
        $lp = Mockery::mock(LanguagePackRepositoryInterface::class);
        $lp->shouldReceive('allForUpdateCheck')->andReturn(new EloquentCollection);
        $onDisk = $this->onDisk;

        return new class($repo(ModuleRepositoryInterface::class, $this->installed['module']), $repo(PluginRepositoryInterface::class, $this->installed['plugin']), $repo(TemplateRepositoryInterface::class, $this->installed['template']), $lp, $onDisk) extends BundledSourcePlanner
        {
            /**
             * 디스크 상태 대역을 받아 둡니다.
             *
             * @param  ModuleRepositoryInterface  $modules  모듈 저장소
             * @param  PluginRepositoryInterface  $plugins  플러그인 저장소
             * @param  TemplateRepositoryInterface  $templates  템플릿 저장소
             * @param  LanguagePackRepositoryInterface  $languagePacks  언어팩 저장소
             * @param  array<string, bool>  $onDisk  디스크에 있다고 칠 키
             */
            public function __construct($modules, $plugins, $templates, $languagePacks, private readonly array $onDisk)
            {
                parent::__construct($modules, $plugins, $templates, $languagePacks);
            }

            /**
             * 디스크 상태 대역으로 판정합니다.
             *
             * @param  string  $type  유형
             * @param  string  $identifier  식별자
             * @return bool 있으면 true
             */
            public function isOnDisk(string $type, string $identifier): bool
            {
                return isset($this->onDisk[$type.':'.$identifier]);
            }
        };
    }

    /**
     * 네 유형의 조회 권한 목록을 돌려줍니다.
     *
     * @return array<int, string> 권한 식별자
     */
    private function allReadPermissions(): array
    {
        return ['core.modules.read', 'core.plugins.read', 'core.templates.read', 'core.language_packs.read'];
    }

    /**
     * 권한을 가진 관리자로 요청하도록 설정합니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자
     * @return static 테스트 인스턴스
     */
    private function actingAsAdmin(array $permissions): static
    {
        return $this->actingAs($this->makeAdmin($permissions));
    }

    /**
     * 관리자 역할과 지정 권한을 가진 사용자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자
     * @return User 사용자
     */
    private function makeAdmin(array $permissions): User
    {
        $user = User::factory()->create();

        $ids = [];
        foreach ($permissions as $identifier) {
            $ids[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'description' => ['ko' => $identifier, 'en' => $identifier],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => PermissionType::Admin,
            ])->id;
        }

        $role = Role::create([
            'identifier' => 'unplaced_notice_'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'is_active' => true,
        ]);
        $role->permissions()->sync($ids);

        $adminRole = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'description' => ['ko' => '관리자', 'en' => 'Administrator'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
