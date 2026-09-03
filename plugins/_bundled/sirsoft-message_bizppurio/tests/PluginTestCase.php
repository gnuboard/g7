<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests;

use App\Enums\ExtensionStatus;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 비즈뿌리오 메시징 플러그인 테스트 베이스 클래스
 *
 * 모든 플러그인 테스트는 이 클래스를 상속받아야 합니다.
 * 코어 + 번들 확장 마이그레이션을 자동으로 처리합니다.
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * HookManager static state 스냅샷 — tearDown 에서 복원하여 테스트 간 훅 격리 보장.
     *
     * @var array{hooks: array, filters: array, dispatching: array}|null
     */
    private ?array $hookSnapshot = null;

    /**
     * HookListenerRegistrar::$registered 스냅샷 — tearDown 에서 복원.
     *
     * HookManager 의 hooks/filters 는 스냅샷 복원되지만, HookListenerRegistrar 는
     * "source::listenerClass" 키로 별도의 static 등록 여부 캐시를 갖는다(동일 PHP
     * process 내 중복 등록 방지용, idempotent 보장). 이 캐시를 복원하지 않으면,
     * 어떤 테스트에서 activate() 로 리스너를 한 번 등록한 뒤 HookManager 상태만
     * 복원되고 이 캐시는 "등록됨"으로 남아, 다음 테스트에서 같은 리스너를 다시
     * register() 해도 이미 등록된 것으로 판단해 스킵된다 — 실제로는 훅이 비어있는데
     * 등록 로직만 건너뛰어 필터가 전혀 발동하지 않는 조용한 실패로 이어진다.
     *
     * @var array<string, bool>|null
     */
    private ?array $hookListenerRegistrarSnapshot = null;

    /** 테스트 격리용 임시 plugins 스토리지 루트 (setUp 에서 생성, tearDown 에서 제거). */
    private ?string $isolatedPluginsRoot = null;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatePluginStorage();
        $this->snapshotHookManager();
        $this->snapshotHookListenerRegistrar();
        $this->activateSelfForMiddlewareGate();
    }

    /**
     * 자기 플러그인을 미들웨어 게이트 인덱스에 활성 등록한다.
     *
     * getMiddleware() self-gate 선언(webhook IP 화이트리스트)은 코어 게이트가
     * 활성 플러그인 registry 를 대조해 실행하므로, 테스트 환경에서도 plugins 테이블
     * 활성 행 + PluginManager 인스턴스 등록 + 인덱스 무효화가 있어야 매칭된다.
     * 누락 시 게이트가 미들웨어를 부착하지 못해 차단 IP 도 통과한다.
     */
    protected function activateSelfForMiddlewareGate(): void
    {
        Plugin::query()->updateOrCreate(
            ['identifier' => 'sirsoft-message_bizppurio'],
            [
                'vendor' => 'sirsoft',
                'name' => json_encode(['ko' => 'sirsoft-message_bizppurio', 'en' => 'sirsoft-message_bizppurio']),
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
            ]
        );
        PluginManager::invalidatePluginStatusCache();

        $pluginManager = $this->app->make(PluginManager::class);
        $property = new \ReflectionProperty($pluginManager, 'plugins');
        $property->setAccessible(true);
        $plugins = $property->getValue($pluginManager);
        $plugins['sirsoft-message_bizppurio'] = new \Plugins\Sirsoft\MessageBizppurio\Plugin;
        $property->setValue($pluginManager, $plugins);

        ExtensionMiddlewareRegistry::flush();
    }

    /**
     * tearDown 에 HookManager 상태 + plugins 스토리지 복원.
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();
        $this->restoreHookListenerRegistrar();
        $this->restorePluginStorage();

        parent::tearDown();
    }

    /**
     * 플러그인 스토리지('plugins' 디스크)를 테스트 전용 임시 디렉토리로 격리한다.
     *
     * 설정 저장 테스트(PluginSettingsService::save)는 코어 'plugins' 디스크
     * (root = `plugins` 디스크, config/filesystems.php)에 setting.json 을 쓴다. 격리하지 않으면
     * 테스트가 실제 로컬 런타임 설정 파일을 덮어써 검수 모드/자격증명이 오염된다
     * (RefreshDatabase 는 DB 만 롤백하고 파일시스템은 되돌리지 않음). 디스크 root 를
     * 임시 경로로 바꾸고 resolved 인스턴스를 purge 하여 실제 파일을 원천적으로 못
     * 건드리게 한다. (회귀 배경: #458)
     */
    private function isolatePluginStorage(): void
    {
        // Laravel 이 테스트용 쓰기 공간으로 보장하는 storage/framework/testing 하위를 사용한다.
        // sys_get_temp_dir() 은 CI/컨테이너/open_basedir 제약 환경에서 위치가 다르거나
        // 쓰기 불가일 수 있어 프로젝트 내부의 격리 경로를 쓴다 (Laravel 규약 준수).
        $this->isolatedPluginsRoot = storage_path(
            'framework/testing/plugin-storage-'.uniqid('', true)
        );

        File::ensureDirectoryExists($this->isolatedPluginsRoot);

        config(['filesystems.disks.plugins.root' => $this->isolatedPluginsRoot]);

        // 이미 resolve 된 'plugins' 디스크 인스턴스를 폐기해 새 root 로 재생성되게 한다.
        Storage::forgetDisk('plugins');
    }

    /**
     * 격리 임시 디렉토리를 제거한다 (테스트 간 잔여 파일 격리).
     */
    private function restorePluginStorage(): void
    {
        if ($this->isolatedPluginsRoot !== null && is_dir($this->isolatedPluginsRoot)) {
            File::deleteDirectory($this->isolatedPluginsRoot);
        }

        $this->isolatedPluginsRoot = null;

        Storage::forgetDisk('plugins');
    }

    /**
     * HookManager static $hooks / $filters / $dispatching 를 스냅샷.
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
     * 스냅샷 시점으로 HookManager 복원.
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

    /**
     * HookListenerRegistrar static $registered 를 스냅샷.
     */
    private function snapshotHookListenerRegistrar(): void
    {
        $ref = new \ReflectionClass(HookListenerRegistrar::class);
        $prop = $ref->getProperty('registered');
        $this->hookListenerRegistrarSnapshot = $prop->getValue();
    }

    /**
     * 스냅샷 시점으로 HookListenerRegistrar::$registered 를 복원.
     */
    private function restoreHookListenerRegistrar(): void
    {
        if ($this->hookListenerRegistrarSnapshot === null) {
            return;
        }

        $ref = new \ReflectionClass(HookListenerRegistrar::class);
        $ref->getProperty('registered')->setValue(null, $this->hookListenerRegistrarSnapshot);

        $this->hookListenerRegistrarSnapshot = null;
    }

    /**
     * 마이그레이션 경로를 반환합니다.
     *
     * RefreshDatabase 의 migrate:fresh 명령에 코어 + 번들 확장 마이그레이션 경로를 전달합니다.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        $paths = ['database/migrations'];
        foreach (glob(base_path('modules/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
            $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
        }
        foreach (glob(base_path('plugins/_bundled/*/database/migrations'), GLOB_ONLYDIR) as $p) {
            $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $p);
        }

        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => false,
            '--path' => $paths,
        ];
    }
}
