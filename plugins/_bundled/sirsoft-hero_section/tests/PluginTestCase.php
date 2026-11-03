<?php

namespace Plugins\Sirsoft\HeroSection\Tests;

use App\Enums\PermissionType;
use App\Extension\HookListenerRegistrar;
use App\Extension\PluginManager;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\HeroSection\Plugin;
use Plugins\Sirsoft\HeroSection\Providers\HeroSectionServiceProvider;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Tests\TestCase;

/**
 * 히어로섹션 플러그인 테스트 베이스 클래스
 *
 * 테스트 앱은 `plugins` 테이블의 활성 행으로 확장을 고르지 않으므로 오토로드·서비스 프로바이더·라우트·훅 리스너를
 * 여기서 직접 등록한다. 라우트 접두·이름은 PluginRouteServiceProvider 와 같게 맞춘다.
 * 데이터는 `DatabaseTransactions` 로 되돌린다 — 시험 DB 에 플러그인 마이그레이션을 미리 적용해 둔다
 * (`php artisan migrate --env=testing --path=plugins/_bundled/sirsoft-hero_section/database/migrations`).
 */
abstract class PluginTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * 마이그레이션 확인 플래그 (프로세스당 한 번만)
     */
    protected static bool $migrated = false;

    /**
     * HookManager static state 스냅샷 — tearDown 에서 복원해 테스트 간 훅 누수를 막는다.
     *
     * @var array{hooks: array, filters: array, dispatching: array}|null
     */
    private ?array $hookSnapshot = null;

    /**
     * 플러그인 루트 경로를 반환합니다.
     *
     * @return string 플러그인 루트 절대 경로
     */
    protected function getPluginBasePath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerPluginAutoload();
        $this->app->register(HeroSectionServiceProvider::class);
        // 설치본이 없어 코어 TranslationServiceProvider 가 plugins/<id>/lang 을 싣지 않으므로 _bundled 의 lang 을 직접 싣는다
        app('translator')->addNamespace('sirsoft-hero_section', $this->getPluginBasePath().'/lang');
        $this->runMigrationsIfNeeded();
        $this->registerPluginRoutes();

        $this->snapshotHookManager();
        $this->registerPluginHookListeners();
    }

    /**
     * 테스트를 정리합니다 — 시험이 더한 훅을 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

    /**
     * 시험 DB 에 코어·플러그인 테이블이 없으면 마이그레이션합니다.
     *
     * @return void 반환값 없음
     */
    protected function runMigrationsIfNeeded(): void
    {
        if (static::$migrated) {
            return;
        }

        // MySQL 은 DDL 이 열린 트랜잭션을 암묵 커밋한다 — 시험 트랜잭션을 잠시 닫고 돌린 뒤 같은 깊이로 다시 연다.
        // 닫지 않으면 마이그레이션이 실제로 돈 첫 시험의 데이터가 커밋돼 시험 DB 에 남는다
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        if (! Schema::hasTable('users')) {
            $this->artisan('migrate', ['--path' => ['database/migrations']]);
        }

        // 테이블이 있어도 뒤에 더한 마이그레이션(컬럼 추가)은 돌린다 — 이미 돈 것은 migrate 가 건너뛴다
        $this->artisan('migrate', ['--path' => ['plugins/_bundled/sirsoft-hero_section/database/migrations']]);

        for ($i = 0; $i < $level; $i++) {
            $connection->beginTransaction();
        }

        static::$migrated = true;
    }

    /**
     * 플러그인 오토로드를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginAutoload(): void
    {
        $pluginBasePath = $this->getPluginBasePath();

        spl_autoload_register(function ($class) use ($pluginBasePath) {
            $prefix = 'Plugins\\Sirsoft\\HeroSection\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            // Database\Factories·Database\Seeders 는 소문자 database/ 아래에 있다(설치본 오토로드 생성기와 같은 규칙)
            if (str_starts_with($relativeClass, 'Database\\Factories\\')) {
                $file = $pluginBasePath.'/database/factories/'.substr($relativeClass, strlen('Database\\Factories\\')).'.php';
            } elseif (str_starts_with($relativeClass, 'Database\\Seeders\\')) {
                $file = $pluginBasePath.'/database/seeders/'.substr($relativeClass, strlen('Database\\Seeders\\')).'.php';
            } elseif ($relativeClass === 'Plugin') {
                $file = $pluginBasePath.'/plugin.php';
            } else {
                $file = $pluginBasePath.'/src/'.str_replace('\\', '/', $relativeClass).'.php';
            }

            if (file_exists($file)
                && ! class_exists($class, false) && ! interface_exists($class, false)
                && ! trait_exists($class, false) && ! enum_exists($class, false)) {
                // 활성 디렉토리 사본이 이미 로드된 심볼을 다시 선언하면 fatal 이 된다 — 선언 여부를 확인하고 require_once
                require_once $file;
            }
        });
    }

    /**
     * 플러그인 웹·API 라우트를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginRoutes(): void
    {
        $base = $this->getPluginBasePath().'/src/routes';

        Route::prefix('api/plugins/sirsoft-hero_section')
            ->name('api.plugins.sirsoft-hero_section.')
            ->middleware('api')
            ->group($base.'/api.php');

        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /**
     * plugin.php 의 리스너를 실제 등록 경로(HookListenerRegistrar)로 등록합니다.
     *
     * 손으로 addAction 하면 큐 래핑·sync 판정을 건너뛰어 운영과 다른 경로를 시험하게 된다(backend-misc
     * 「Listener 데이터 접근」). 등록기는 프로세스 안 중복 등록을 정적으로 기억하므로, 테스트마다 훅을 되돌리는
     * 이 베이스에서는 등록 직전에 이 플러그인 출처의 기억만 지운다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginHookListeners(): void
    {
        $plugin = new Plugin([
            'identifier' => 'sirsoft-hero_section',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '히어로섹션', 'en' => 'Hero Section'],
            'version' => '1.0.0',
        ]);

        $memo = new \ReflectionProperty(HookListenerRegistrar::class, 'registered');
        $registered = $memo->getValue();
        foreach (array_keys($registered) as $key) {
            if (str_starts_with($key, 'sirsoft-hero_section::')) {
                unset($registered[$key]);
            }
        }
        $memo->setValue(null, $registered);

        foreach ($plugin->getHookListeners() as $listenerClass) {
            HookListenerRegistrar::register($listenerClass, 'sirsoft-hero_section');
        }
    }

    /**
     * 주어진 권한만 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return User 관리자 회원
     */
    protected function adminWith(array $permissions): User
    {
        $role = Role::query()->create([
            'identifier' => 'hero-admin-'.Str::lower(Str::random(8)),
            'name' => ['ko' => '시험 관리자', 'en' => 'Test Admin'],
            'description' => ['ko' => '시험', 'en' => 'Test'],
        ]);

        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        $user->flushPermissionCaches();

        return $user;
    }

    /**
     * 조회·등록수정·삭제 권한을 모두 가진 관리자를 만듭니다.
     *
     * @return User 관리자 회원
     */
    protected function fullAdmin(): User
    {
        return $this->adminWith([
            'sirsoft-hero_section.banners.read',
            'sirsoft-hero_section.banners.update',
            'sirsoft-hero_section.banners.delete',
        ]);
    }

    /**
     * 배너 이미지 디스크를 가짜로 바꿉니다.
     *
     * 이미지 서비스가 쓰는 디스크는 플러그인 설치 여부에 따라 다르다 — 설치돼 있으면 플러그인 디스크(`plugins`),
     * 아니면 기본 디스크(`local`). 테스트 결과가 개발 환경의 설치 상태에 갈리지 않게 둘 다 가짜로 둔다.
     *
     * @return void 반환값 없음
     */
    protected function fakeImageDisks(): void
    {
        Storage::fake('local');
        Storage::fake('plugins');
    }

    /**
     * 플러그인 설정 저장(`PluginSettingsService::save()`)이 개발 환경의 설치 상태와 무관하게 돌게 합니다.
     *
     * 저장은 `PluginManager::getPlugin()` 인스턴스가 있어야 하고 그 플러그인 디스크(`plugins`)에 설정 파일을 쓴다 —
     * 설치하지 않은 클론에서는 인스턴스가 없어 저장이 실패하고, 설치한 클론에서는 실제 설정 파일을 덮는다.
     * 인스턴스를 직접 등록하고 디스크를 가짜로 둔다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginInstance(): void
    {
        Storage::fake('plugins');

        $manager = app(PluginManager::class);
        $prop = new \ReflectionProperty($manager, 'plugins');
        $plugins = $prop->getValue($manager);
        $plugins['sirsoft-hero_section'] = new Plugin;
        $prop->setValue($manager, $plugins);
    }

    /**
     * 이미지 서비스가 실제로 쓰는 디스크를 반환합니다(설치 여부와 무관하게 파일 존재를 확인하려고).
     *
     * @return Filesystem 이미지 디스크
     */
    protected function imageStorage(): Filesystem
    {
        $service = $this->app->make(BannerImageService::class);
        $storage = (new \ReflectionProperty($service, 'storage'))->getValue($service);

        return Storage::disk($storage->getDisk());
    }

    /**
     * 플러그인 API 경로를 만듭니다.
     *
     * @param  string  $path  플러그인 접두 뒤 경로 (/로 시작)
     * @return string 전체 경로
     */
    protected function api(string $path): string
    {
        return '/api/plugins/sirsoft-hero_section'.$path;
    }

    /**
     * 등록·수정에 쓰는 유효한 배너 값을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 값
     * @return array<string, mixed> 배너 값
     */
    protected function bannerPayload(array $overrides = []): array
    {
        return array_replace([
            'position' => 'home_hero',
            'is_active' => true,
            'display_period' => 'always',
            'title' => ['ko' => '가을 신상품', 'en' => 'Autumn arrivals'],
            'description' => ['ko' => '최대 30% 할인'],
            'button_text' => ['ko' => '보러 가기'],
            'image_alt' => ['ko' => '가을 신상품 배너'],
            'title_visible' => true,
            'title_color' => '#FFFFFF',
            'title_align' => 'left',
            'description_visible' => true,
            'description_color' => '#E5E7EB',
            'description_align' => 'left',
            'link_url' => '/shop',
            'link_new_window' => false,
        ], $overrides);
    }

    /**
     * 관리자 API 로 이미지를 올립니다.
     *
     * @param  User  $admin  관리자
     * @param  string  $device  기기 (pc, mobile)
     * @param  array<string, mixed>  $owner  ['temp_key' => …] (업로드는 임시 묶음에만 들어간다)
     * @param  UploadedFile|null  $file  올릴 파일 (null 이면 권장 크기 가짜 이미지)
     * @return TestResponse 응답
     */
    protected function uploadImage(User $admin, string $device, array $owner, ?UploadedFile $file = null): TestResponse
    {
        $file ??= $device === 'pc' ? UploadedFile::fake()->image('pc.jpg', 1920, 640) : UploadedFile::fake()->image('m.jpg', 750, 1000);

        return $this->actingAs($admin)->post($this->api('/admin/banner-images'), ['file' => $file, 'device' => $device] + $owner, ['Accept' => 'application/json']);
    }

    /**
     * 관리자 API 로 PC 이미지(원하면 모바일도)를 올리고 배너를 등록합니다.
     *
     * @param  User  $admin  관리자
     * @param  array<string, mixed>  $overrides  바꿀 배너 값
     * @param  bool  $mobile  모바일 이미지도 올릴지
     * @return TestResponse 등록 응답
     */
    protected function createBannerViaApi(User $admin, array $overrides = [], bool $mobile = false): TestResponse
    {
        $tempKey = (string) Str::uuid();
        $this->uploadImage($admin, 'pc', ['temp_key' => $tempKey])->assertCreated();
        if ($mobile) {
            $this->uploadImage($admin, 'mobile', ['temp_key' => $tempKey])->assertCreated();
        }

        return $this->actingAs($admin)->postJson($this->api('/admin/banners'), $this->bannerPayload($overrides) + ['temp_key' => $tempKey]);
    }

    /**
     * 플러그인 설정을 이 시험 안에서만 바꿉니다 (설정 파일은 건드리지 않는다).
     *
     * @param  array<string, mixed>  $overrides  바꿀 설정
     * @return void 반환값 없음
     */
    protected function setPluginSettings(array $overrides): void
    {
        $plugin = new Plugin(['identifier' => 'sirsoft-hero_section', 'vendor' => 'sirsoft', 'version' => '1.0.0']);
        $service = app(PluginSettingsService::class);
        $ref = new \ReflectionProperty(PluginSettingsService::class, 'settingsCache');
        $cache = $ref->getValue($service);
        $cache['sirsoft-hero_section'] = array_merge($plugin->getConfigValues(), $cache['sirsoft-hero_section'] ?? [], $overrides);
        $ref->setValue($service, $cache);
        $this->app->instance(PluginSettingsService::class, $service);
    }

    /**
     * HookManager 상태를 저장합니다.
     *
     * @return void 반환값 없음
     */
    private function snapshotHookManager(): void
    {
        $ref = new \ReflectionClass(HookManager::class);
        $this->hookSnapshot = [
            'hooks' => $ref->getProperty('hooks')->getValue(),
            'filters' => $ref->getProperty('filters')->getValue(),
            'dispatching' => $ref->getProperty('dispatching')->getValue(),
        ];
    }

    /**
     * 저장한 시점으로 HookManager 를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    private function restoreHookManager(): void
    {
        if ($this->hookSnapshot === null) {
            return;
        }

        $ref = new \ReflectionClass(HookManager::class);
        $ref->getProperty('hooks')->setValue(null, $this->hookSnapshot['hooks']);
        $ref->getProperty('filters')->setValue(null, $this->hookSnapshot['filters']);
        $ref->getProperty('dispatching')->setValue(null, $this->hookSnapshot['dispatching']);
        $this->hookSnapshot = null;
    }
}
