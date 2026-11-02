<?php

namespace Plugins\Sirsoft\Popup\Tests;

use App\Contracts\Extension\StorageInterface;
use App\Enums\PermissionType;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Popup\Plugin;
use Plugins\Sirsoft\Popup\Providers\PopupServiceProvider;
use Plugins\Sirsoft\Popup\Services\PopupImageService;
use Tests\TestCase;

/**
 * 팝업 플러그인 테스트 베이스 클래스
 *
 * 테스트 앱은 `plugins` 테이블의 활성 행으로 확장을 고르지 않으므로 오토로드·서비스 프로바이더·라우트·훅 리스너를
 * 여기서 직접 등록한다. 라우트 접두·이름은 PluginRouteServiceProvider 와 같게 맞춘다.
 * 데이터는 `DatabaseTransactions` 로 되돌린다 — 시험 DB 에 플러그인 마이그레이션을 미리 적용해 둔다
 * (`php artisan migrate --env=testing --path=plugins/_bundled/sirsoft-popup/database/migrations`).
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
        // 로컬 정리 대장도 가짜 이미지 디스크와 같은 테스트별 루트에 둔다.
        config(['filesystems.disks.plugins.root' => storage_path('framework/testing/disks/plugins')]);
        $this->app->register(PopupServiceProvider::class);
        // 설치본이 없어 코어 TranslationServiceProvider 가 plugins/<id>/lang 을 싣지 않으므로 _bundled 의 lang 을 직접 싣는다
        app('translator')->addNamespace('sirsoft-popup', $this->getPluginBasePath().'/lang');
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
     * 시험 트랜잭션을 열기 전에 테이블부터 맞춥니다.
     *
     * `DatabaseTransactions` 는 트레이트 준비 단계에서 트랜잭션을 연다. 그 뒤에 마이그레이션(DDL)을 돌리면 MySQL 이
     * 트랜잭션을 암묵 커밋해, 시험 DB 를 새로 만든 직후 첫 시험의 데이터가 되돌려지지 않고 남는다(2026-11-02 리베이스 뒤 실측:
     * 남은 팝업 4건이 다음 시험들의 목록·공개 응답을 흔들어 40건 실패).
     *
     * @return array<class-string, class-string> 준비한 트레이트
     */
    protected function setUpTraits()
    {
        $this->runMigrationsIfNeeded();

        return parent::setUpTraits();
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

        if (! Schema::hasTable('users')) {
            $this->artisan('migrate', ['--path' => ['database/migrations']]);
        }

        if (! Schema::hasTable('popups')) {
            $this->artisan('migrate', ['--path' => ['plugins/_bundled/sirsoft-popup/database/migrations']]);
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
            $prefix = 'Plugins\\Sirsoft\\Popup\\';
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

        Route::prefix('api/plugins/sirsoft-popup')
            ->name('api.plugins.sirsoft-popup.')
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
            'identifier' => 'sirsoft-popup',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '팝업', 'en' => 'Popup'],
            'version' => '1.0.0',
        ]);

        $memo = new \ReflectionProperty(HookListenerRegistrar::class, 'registered');
        $registered = $memo->getValue();
        foreach (array_keys($registered) as $key) {
            if (str_starts_with($key, 'sirsoft-popup::')) {
                unset($registered[$key]);
            }
        }
        $memo->setValue(null, $registered);

        foreach ($plugin->getHookListeners() as $listenerClass) {
            HookListenerRegistrar::register($listenerClass, 'sirsoft-popup');
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
            'identifier' => 'popup-admin-'.Str::lower(Str::random(8)),
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
            'sirsoft-popup.popups.read',
            'sirsoft-popup.popups.update',
            'sirsoft-popup.popups.delete',
        ]);
    }

    /**
     * 팝업 이미지 디스크를 가짜로 바꿉니다.
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
     * 이미지 서비스가 실제로 쓰는 디스크를 반환합니다(설치 여부와 무관하게 파일 존재를 확인하려고).
     *
     * @return Filesystem 이미지 디스크
     */
    protected function imageStorage(): Filesystem
    {
        $service = $this->app->make(PopupImageService::class);
        $storage = (new \ReflectionProperty($service, 'storage'))->getValue($service);

        return Storage::disk($storage->getDisk());
    }

    /**
     * 이미지 폴더의 파일 목록을 반환합니다 — 드라이버가 폴더를 만들 때 두는 표시 파일은 뺀다.
     *
     * @return array<int, string> 이미지 파일 경로
     */
    protected function imageFiles(): array
    {
        return $this->imageStorage()->allFiles('sirsoft-popup/images');
    }

    /**
     * 이미지 서비스가 쓰는 스토리지 드라이버를 반환합니다 — 저장소만 바꾼 서비스를 만들 때.
     *
     * @return StorageInterface 플러그인 스토리지 드라이버
     */
    protected function imageStorageDriver(): StorageInterface
    {
        $service = $this->app->make(PopupImageService::class);

        return (new \ReflectionProperty($service, 'storage'))->getValue($service);
    }

    /**
     * 플러그인 API 경로를 만듭니다.
     *
     * @param  string  $path  플러그인 접두 뒤 경로 (/로 시작)
     * @return string 전체 경로
     */
    protected function api(string $path): string
    {
        return '/api/plugins/sirsoft-popup'.$path;
    }

    /**
     * 등록·수정에 쓰는 유효한 이미지형 팝업 값을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 값
     * @return array<string, mixed> 팝업 값
     */
    protected function popupPayload(array $overrides = []): array
    {
        return array_replace([
            'title' => '가을 감사 이벤트',
            'is_active' => true,
            'content_type' => 'image',
            'display_period' => 'always',
            'page_scope' => 'all',
            'audience' => 'all',
            'device' => 'all',
            'image_alt' => ['ko' => '가을 감사 이벤트 안내', 'en' => 'Autumn thanks event'],
            'link_url' => '/event/autumn',
            'link_new_window' => false,
        ], $overrides);
    }

    /**
     * 등록·수정에 쓰는 유효한 직접 작성형 팝업 값을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 값
     * @return array<string, mixed> 팝업 값
     */
    protected function htmlPopupPayload(array $overrides = []): array
    {
        return array_replace([
            'title' => '10월 정기 시스템 점검 안내',
            'is_active' => true,
            'content_type' => 'html',
            'display_period' => 'always',
            'page_scope' => 'all',
            'audience' => 'all',
            'device' => 'all',
            'body_html' => ['ko' => '<h2>점검 안내</h2><p>점검 시간에는 주문이 잠시 중단됩니다.</p>'],
        ], $overrides);
    }

    /**
     * 관리자 API 로 이미지를 올립니다.
     *
     * @param  User  $admin  관리자
     * @param  string  $device  기기 (pc, mobile)
     * @param  string  $tempKey  임시 업로드 묶음 키
     * @param  UploadedFile|null  $file  올릴 파일 (null 이면 권장 크기 가짜 이미지)
     * @return TestResponse 응답
     */
    protected function uploadImage(User $admin, string $device, string $tempKey, ?UploadedFile $file = null): TestResponse
    {
        $file ??= $device === 'pc' ? UploadedFile::fake()->image('pc.jpg', 480, 520) : UploadedFile::fake()->image('m.jpg', 750, 900);

        return $this->actingAs($admin)->post($this->api('/admin/popup-images'), ['file' => $file, 'device' => $device, 'temp_key' => $tempKey], ['Accept' => 'application/json']);
    }

    /**
     * 관리자 API 로 PC 이미지(원하면 모바일도)를 올리고 이미지형 팝업을 등록합니다.
     *
     * @param  User  $admin  관리자
     * @param  array<string, mixed>  $overrides  바꿀 팝업 값
     * @param  bool  $mobile  모바일 이미지도 올릴지
     * @return TestResponse 등록 응답
     */
    protected function createPopupViaApi(User $admin, array $overrides = [], bool $mobile = false): TestResponse
    {
        $tempKey = (string) Str::uuid();
        $this->uploadImage($admin, 'pc', $tempKey)->assertCreated();
        if ($mobile) {
            $this->uploadImage($admin, 'mobile', $tempKey)->assertCreated();
        }

        return $this->actingAs($admin)->postJson($this->api('/admin/popups'), $this->popupPayload($overrides) + ['temp_key' => $tempKey]);
    }

    /**
     * 플러그인 설정을 이 시험 안에서만 바꿉니다 (설정 파일은 건드리지 않는다).
     *
     * @param  array<string, mixed>  $overrides  바꿀 설정
     * @return void 반환값 없음
     */
    protected function setPluginSettings(array $overrides): void
    {
        $plugin = new Plugin(['identifier' => 'sirsoft-popup', 'vendor' => 'sirsoft', 'version' => '1.0.0']);
        $service = app(PluginSettingsService::class);
        $ref = new \ReflectionProperty(PluginSettingsService::class, 'settingsCache');
        $cache = $ref->getValue($service);
        $cache['sirsoft-popup'] = array_merge($plugin->getConfigValues(), $cache['sirsoft-popup'] ?? [], $overrides);
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
