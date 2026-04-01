<?php

namespace App\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\StorageInterface;
use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\Storage\ModuleStorageDriver;
use ReflectionClass;

/**
 * 모듈 추상 클래스
 *
 * 모듈 개발자는 이 클래스를 상속받아 module.json만 작성하면 됩니다.
 * getIdentifier(), getVendor()는 디렉토리명에서 자동 추론됩니다.
 * getName(), getVersion(), getDescription()은 module.json에서 자동 파싱됩니다.
 */
abstract class AbstractModule implements ModuleInterface
{
    /**
     * 모듈 디렉토리 경로 (캐시)
     */
    private ?string $modulePath = null;

    /**
     * 모듈 식별자 (캐시)
     */
    private ?string $identifier = null;

    /**
     * 스토리지 드라이버 인스턴스 (캐시)
     */
    private ?StorageInterface $storage = null;

    /**
     * manifest JSON 캐시
     */
    private ?array $manifest = null;

    /**
     * module.json 매니페스트를 파싱하여 캐싱합니다.
     *
     * @return array 매니페스트 배열 (파일 미존재 시 빈 배열)
     */
    protected function loadManifest(): array
    {
        if ($this->manifest === null) {
            $manifestPath = $this->getModulePath().'/module.json';

            if (file_exists($manifestPath)) {
                $this->manifest = json_decode(file_get_contents($manifestPath), true) ?? [];
            } else {
                $this->manifest = [];
            }
        }

        return $this->manifest;
    }

    /**
     * 모듈명 반환 (다국어 지원)
     *
     * module.json의 name 필드에서 읽습니다. 오버라이드 가능합니다.
     *
     * @return string|array 문자열 또는 다국어 배열 ['ko' => '...', 'en' => '...']
     */
    public function getName(): string|array
    {
        return $this->loadManifest()['name'] ?? $this->getIdentifier();
    }

    /**
     * 모듈 버전 반환
     *
     * module.json의 version 필드에서 읽습니다. 오버라이드 가능합니다.
     *
     * @return string 모듈 버전
     */
    public function getVersion(): string
    {
        return $this->loadManifest()['version'] ?? '0.0.0';
    }

    /**
     * 모듈 설명 반환 (다국어 지원)
     *
     * module.json의 description 필드에서 읽습니다. 오버라이드 가능합니다.
     *
     * @return string|array 문자열 또는 다국어 배열 ['ko' => '...', 'en' => '...']
     */
    public function getDescription(): string|array
    {
        return $this->loadManifest()['description'] ?? '';
    }

    /**
     * 모듈 디렉토리 경로 반환
     */
    protected function getModulePath(): string
    {
        if ($this->modulePath === null) {
            $reflection = new ReflectionClass($this);
            $this->modulePath = dirname($reflection->getFileName());
        }

        return $this->modulePath;
    }

    /**
     * 모듈 식별자 반환 (디렉토리명에서 자동 추론)
     *
     * 디렉토리명이 'sirsoft-sample'이면 식별자도 'sirsoft-sample'
     */
    final public function getIdentifier(): string
    {
        if ($this->identifier === null) {
            $this->identifier = basename($this->getModulePath());
        }

        return $this->identifier;
    }

    /**
     * 벤더명 반환 (디렉토리명에서 자동 추론)
     *
     * 디렉토리명이 'sirsoft-sample'이면 벤더명은 'sirsoft'
     */
    final public function getVendor(): string
    {
        $identifier = $this->getIdentifier();
        $parts = explode('-', $identifier);

        return $parts[0];
    }

    /**
     * 모듈 설치
     *
     * 모듈 개발자가 설치 시 추가 작업이 필요한 경우 오버라이드
     */
    public function install(): bool
    {
        return true;
    }

    /**
     * 모듈 제거
     *
     * 모듈 개발자가 제거 시 추가 작업이 필요한 경우 오버라이드
     */
    public function uninstall(): bool
    {
        return true;
    }

    /**
     * 모듈이 런타임에 동적으로 생성한 테이블 목록을 반환합니다.
     *
     * 반환된 테이블들은 ModuleManager가 일괄 삭제합니다.
     * 마이그레이션 롤백 전에 호출되므로 메타 테이블이 아직 존재합니다.
     *
     * 모듈 개발자가 동적 테이블이 있는 경우 오버라이드하세요.
     *
     * @return array<string> 삭제할 테이블명 배열
     */
    public function getDynamicTables(): array
    {
        return [];
    }

    /**
     * 모듈 활성화
     *
     * 모듈 개발자가 활성화 시 추가 작업이 필요한 경우 오버라이드
     */
    public function activate(): bool
    {
        return true;
    }

    /**
     * 모듈 비활성화
     *
     * 모듈 개발자가 비활성화 시 추가 작업이 필요한 경우 오버라이드
     */
    public function deactivate(): bool
    {
        return true;
    }

    /**
     * 버전별 업그레이드 스텝 반환
     *
     * 기본 구현: upgrades/ 디렉토리를 자동 스캔하여 UpgradeStepInterface 구현체를 수집합니다.
     * 모듈 개발자가 인라인 클로저를 사용하려면 오버라이드하세요.
     *
     * @return array<string, callable|UpgradeStepInterface> 버전 => 스텝 매핑
     */
    public function upgrades(): array
    {
        return $this->discoverUpgradeSteps();
    }

    /**
     * upgrades/ 디렉토리에서 업그레이드 스텝을 자동 발견합니다.
     *
     * 파일명 규칙: Upgrade_1_1_0.php → 버전 '1.1.0'
     * 클래스는 UpgradeStepInterface를 구현해야 합니다.
     *
     * @return array<string, UpgradeStepInterface> 버전 => 스텝 매핑
     */
    protected function discoverUpgradeSteps(): array
    {
        $upgradesPath = $this->getModulePath().'/upgrades';

        if (! is_dir($upgradesPath)) {
            return [];
        }

        $steps = [];
        $files = glob($upgradesPath.'/Upgrade_*.php');

        if (! $files) {
            return [];
        }

        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);

            // Upgrade_1_1_0 → 1.1.0, Upgrade_1_0_0_beta_1 → 1.0.0-beta.1
            if (! preg_match('/^Upgrade_(\d+)_(\d+)_(\d+)(?:_([a-zA-Z]\w*(?:_\d+)*))?$/', $filename, $matches)) {
                continue;
            }

            $version = "{$matches[1]}.{$matches[2]}.{$matches[3]}";

            if (! empty($matches[4])) {
                $version .= '-'.str_replace('_', '.', $matches[4]);
            }

            require_once $file;

            // 네임스페이스 추론: 모듈 네임스페이스 + Upgrades\ClassName
            $namespacePart = ExtensionManager::directoryToNamespace($this->getIdentifier());
            $namespace = 'Modules\\'.$namespacePart.'\\Upgrades\\'.$filename;

            if (class_exists($namespace) && is_subclass_of($namespace, UpgradeStepInterface::class)) {
                $steps[$version] = new $namespace;
            }
        }

        ksort($steps, SORT_NATURAL);

        return $steps;
    }

    /**
     * 모듈 라우트 파일 경로 목록 반환
     *
     * 기본적으로 src/routes/api.php, src/routes/web.php를 반환
     * 파일이 존재하는 경우에만 포함
     */
    public function getRoutes(): array
    {
        $routes = [];
        $basePath = $this->getModulePath();

        $apiRoute = $basePath.'/src/routes/api.php';
        $webRoute = $basePath.'/src/routes/web.php';

        if (file_exists($apiRoute)) {
            $routes['api'] = $apiRoute;
        }

        if (file_exists($webRoute)) {
            $routes['web'] = $webRoute;
        }

        return $routes;
    }

    /**
     * 모듈 마이그레이션 경로 반환
     *
     * 기본적으로 database/migrations 디렉토리를 반환
     * 디렉토리가 존재하는 경우에만 포함
     */
    public function getMigrations(): array
    {
        $migrationsPath = $this->getModulePath().'/database/migrations';

        if (is_dir($migrationsPath)) {
            return [$migrationsPath];
        }

        return [];
    }

    /**
     * 모듈 뷰 파일 목록 반환
     *
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 뷰가 필요한 경우 오버라이드
     */
    public function getViews(): array
    {
        return [];
    }

    /**
     * 모듈 역할 목록 반환
     *
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 역할이 필요한 경우 오버라이드
     *
     * @return array 역할 정의 배열
     *               [
     *               [
     *               'identifier' => 'vendor-module.role-name',
     *               'name' => ['ko' => '...', 'en' => '...'],
     *               'description' => ['ko' => '...', 'en' => '...'],
     *               ],
     *               ]
     */
    public function getRoles(): array
    {
        return [];
    }

    /**
     * 모듈 권한 목록 반환 (계층형 구조, 다국어 지원)
     *
     * 구조: 모듈(1레벨) → 카테고리(2레벨) → 개별 권한(3레벨)
     * identifier는 자동 생성됨: {module}.{category}.{action}
     *
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 권한이 필요한 경우 오버라이드
     *
     * @return array 권한 정의 배열
     *               [
     *               'name' => ['ko' => '...', 'en' => '...'],
     *               'description' => ['ko' => '...', 'en' => '...'],
     *               'categories' => [
     *               [
     *               'identifier' => 'products',
     *               'name' => ['ko' => '...', 'en' => '...'],
     *               'permissions' => [
     *               [
     *               'action' => 'read',
     *               'name' => ['ko' => '...', 'en' => '...'],
     *               'type' => 'admin',  // admin 또는 user (기본값: admin)
     *               'roles' => ['admin', 'manager'],
     *               ],
     *               ],
     *               ],
     *               ],
     *               ]
     */
    public function getPermissions(): array
    {
        return [];
    }

    /**
     * 모듈 설정 정보 반환
     *
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 설정이 필요한 경우 오버라이드
     */
    public function getConfig(): array
    {
        return [];
    }

    /**
     * 관리자 메뉴 목록 반환
     *
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 관리자 메뉴가 필요한 경우 오버라이드
     */
    public function getAdminMenus(): array
    {
        return [];
    }

    /**
     * 모듈 커스텀 메뉴 목록 반환
     *
     * 모듈이 제공하는 관리자 사이드바 메뉴 정의
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 커스텀 메뉴가 필요한 경우 오버라이드
     *
     * @return array 메뉴 정의 배열
     *               [
     *               [
     *               'code' => 'menu_code',           // 메뉴 고유 코드
     *               'name' => ['ko' => '...', 'en' => '...'],  // 다국어 메뉴명
     *               'url' => '/admin/path',          // 메뉴 URL
     *               'icon' => 'icon-name',           // 아이콘 이름
     *               'sort_order' => 10,              // 정렬 순서 (낮을수록 상위)
     *               'permission' => 'vendor-module.permission',  // 필요 권한 (선택)
     *               'children' => [...],             // 하위 메뉴 배열 (선택)
     *               ],
     *               ]
     */
    public function getCustomMenus(): array
    {
        return [];
    }

    /**
     * 훅 리스너 목록 반환
     *
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 훅 리스너가 필요한 경우 오버라이드
     */
    public function getHookListeners(): array
    {
        return [];
    }

    /**
     * 스케줄 작업 목록 반환
     *
     * 모듈에서 등록하는 스케줄 작업 목록입니다.
     * 코어에서 이 메서드를 호출하여 모듈 스케줄러를 등록합니다.
     *
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 스케줄 작업이 필요한 경우 오버라이드
     *
     * @return array 스케줄 작업 배열
     *               [
     *               [
     *               'command' => 'artisan:command',
     *               'schedule' => 'daily' | 'hourly' | 'everyMinute' | 'weekly' | cron expression,
     *               'description' => '작업 설명 (선택)',
     *               'enabled_config' => 'setting.key' (선택, module_setting()으로 조회하여 활성화 여부 결정),
     *               ],
     *               ]
     *
     * enabled_config 형식:
     *   - 'order_settings.auto_cancel_expired' → module_setting($identifier, 'order_settings.auto_cancel_expired')
     *   - 'sirsoft-ecommerce.order_settings.auto_cancel_expired' → identifier 접두사 자동 제거 후 동일하게 조회
     */
    public function getSchedules(): array
    {
        return [];
    }

    /**
     * 모듈 설치 시 실행할 시더 클래스 목록 반환
     *
     * 빈 배열 반환 시 database/seeders/ 디렉토리의 모든 시더를 자동 검색합니다. (역호환)
     * 오버라이드하여 실행할 시더와 순서를 명시적으로 정의하세요.
     *
     * @return array<class-string<\Illuminate\Database\Seeder>> 시더 클래스명 배열 (FQCN)
     */
    public function getSeeders(): array
    {
        return [];
    }

    /**
     * 모듈 의존성 반환
     *
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 의존성이 필요한 경우 오버라이드
     */
    public function getDependencies(): array
    {
        return [];
    }

    /**
     * 모듈 설정 기본값 파일 경로 반환
     *
     * 기본적으로 config/settings/defaults.json 파일 경로 반환
     * 모듈 개발자가 다른 경로를 사용하는 경우 오버라이드
     *
     * @return string|null defaults.json 파일의 절대 경로, 없으면 null
     */
    public function getSettingsDefaultsPath(): ?string
    {
        $path = $this->getModulePath().'/config/settings/defaults.json';

        return file_exists($path) ? $path : null;
    }

    /**
     * 모듈에 환경설정이 있는지 확인
     *
     * @return bool 환경설정 존재 여부
     */
    public function hasSettings(): bool
    {
        return $this->getSettingsDefaultsPath() !== null;
    }

    /**
     * 모듈 설정 저장 경로 반환
     *
     * @deprecated 향후 제거 예정. getStorage()->getBasePath('settings') 사용 권장
     *
     * @return string 설정 파일 저장 디렉토리 경로
     */
    public function getSettingsStoragePath(): string
    {
        return $this->getStorage()->getBasePath('settings');
    }

    /**
     * 모듈 설정 기본값 반환
     *
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 기본 설정값이 필요한 경우 오버라이드
     *
     * @return array 설정 기본값 배열
     */
    public function getConfigValues(): array
    {
        return [];
    }

    /**
     * 모듈 설정 스키마 반환
     *
     * 민감한 필드(sensitive: true) 정보 등을 포함합니다.
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 민감한 필드가 있는 경우 오버라이드
     *
     * @return array 설정 스키마 배열
     */
    public function getSettingsSchema(): array
    {
        return [];
    }

    /**
     * GitHub URL 반환
     *
     * module.json의 github_url 필드에서 읽습니다. 오버라이드 가능합니다.
     *
     * @return string|null GitHub URL 또는 null
     */
    public function getGithubUrl(): ?string
    {
        return $this->loadManifest()['github_url'] ?? null;
    }

    /**
     * 라이선스 반환
     *
     * module.json의 license 필드에서 읽습니다. 오버라이드 가능합니다.
     *
     * @return string|null 라이선스 또는 null
     */
    public function getLicense(): ?string
    {
        return $this->loadManifest()['license'] ?? null;
    }

    /**
     * 모듈 메타데이터 반환
     *
     * 기본적으로 빈 배열 반환
     * 모듈 개발자가 메타데이터가 필요한 경우 오버라이드
     */
    public function getMetadata(): array
    {
        return [];
    }

    /**
     * 레이아웃 확장 파일 경로 반환
     *
     * @return string extensions 디렉토리 경로
     */
    public function getExtensionsPath(): string
    {
        return $this->getModulePath().'/resources/extensions';
    }

    /**
     * 레이아웃 확장 파일 목록 반환
     *
     * @return array<string> JSON 파일 경로 목록
     */
    public function getLayoutExtensions(): array
    {
        $path = $this->getExtensionsPath();

        if (! is_dir($path)) {
            return [];
        }

        return glob($path.'/*.json') ?: [];
    }

    /**
     * SEO config 파일 경로를 반환합니다.
     *
     * @return string seo-config.json 파일 경로
     */
    public function getSeoConfigPath(): string
    {
        return $this->getModulePath().'/resources/seo-config.json';
    }

    /**
     * SEO config를 로드하여 반환합니다.
     *
     * @return array SEO 설정 배열 (파일 미존재 시 빈 배열)
     */
    public function getSeoConfig(): array
    {
        $path = $this->getSeoConfigPath();

        if (! file_exists($path)) {
            return [];
        }

        $config = json_decode(file_get_contents($path), true);

        return is_array($config) ? $config : [];
    }

    /**
     * 그누보드7 코어 요구 버전 제약 반환
     *
     * module.json의 g7_version 필드에서 읽습니다. 오버라이드 가능합니다.
     * null 반환 시 버전 검증 건너뜀 (역호환성)
     *
     * @return string|null 버전 제약 문자열 또는 null
     */
    public function getRequiredCoreVersion(): ?string
    {
        return $this->loadManifest()['g7_version'] ?? null;
    }

    /**
     * 모듈 프론트엔드 에셋 정보 반환
     *
     * module.json의 assets 섹션에서 정보를 읽어 반환합니다.
     * 빌드된 JS/CSS 파일 경로와 외부 스크립트 정보를 포함합니다.
     *
     * @return array 에셋 정보 배열
     *               [
     *               'js' => ['entry' => 'resources/js/index.ts', 'output' => 'dist/js/module.iife.js'],
     *               'css' => ['entry' => 'resources/css/main.css', 'output' => 'dist/css/module.css'],
     *               'handlers' => true,
     *               'static' => 'resources/assets/',
     *               'external' => [...],
     *               ]
     */
    public function getAssets(): array
    {
        return $this->loadManifest()['assets'] ?? [];
    }

    /**
     * 모듈 에셋 로딩 설정 반환
     *
     * module.json의 loading 섹션에서 정보를 읽어 반환합니다.
     *
     * @return array 로딩 설정 배열
     *               [
     *               'strategy' => 'global' | 'layout' | 'lazy',
     *               'priority' => 100,
     *               'dependencies' => [],
     *               ]
     */
    public function getAssetLoadingConfig(): array
    {
        $loading = $this->loadManifest()['loading'] ?? [];

        return [
            'strategy' => $loading['strategy'] ?? 'global',
            'priority' => $loading['priority'] ?? 100,
            'dependencies' => $loading['dependencies'] ?? [],
        ];
    }

    /**
     * 프론트엔드 에셋 빌드가 가능한지 확인합니다.
     *
     * hasAssets()와 달리 빌드 결과물이 아닌 소스 엔트리포인트 정의 여부로 판단합니다.
     * 빌드 커맨드에서 빌드 대상 필터링에 사용됩니다.
     *
     * @return bool 빌드 가능 여부
     */
    public function canBuild(): bool
    {
        $assets = $this->getAssets();

        return ! empty($assets['js']['entry']) || ! empty($assets['css']['entry']);
    }

    /**
     * 모듈에 프론트엔드 에셋이 있는지 확인
     *
     * @return bool 에셋 존재 여부
     */
    public function hasAssets(): bool
    {
        $assets = $this->getAssets();

        // js 또는 css output이 정의되어 있고 파일이 존재하는지 확인
        if (! empty($assets['js']['output'])) {
            $jsPath = $this->getModulePath().'/'.$assets['js']['output'];
            if (file_exists($jsPath)) {
                return true;
            }
        }

        if (! empty($assets['css']['output'])) {
            $cssPath = $this->getModulePath().'/'.$assets['css']['output'];
            if (file_exists($cssPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 빌드된 에셋 파일 경로 반환
     *
     * @return array 빌드된 에셋 경로 배열 ['js' => '...', 'css' => '...']
     */
    public function getBuiltAssetPaths(): array
    {
        $assets = $this->getAssets();
        $result = [];

        if (! empty($assets['js']['output'])) {
            $jsPath = $this->getModulePath().'/'.$assets['js']['output'];
            if (file_exists($jsPath)) {
                $result['js'] = $assets['js']['output'];
            }
        }

        if (! empty($assets['css']['output'])) {
            $cssPath = $this->getModulePath().'/'.$assets['css']['output'];
            if (file_exists($cssPath)) {
                $result['css'] = $assets['css']['output'];
            }
        }

        return $result;
    }

    /**
     * 모듈 스토리지 드라이버 인스턴스 반환
     *
     * 모듈별로 격리된 파일 저장소를 제공합니다.
     * 카테고리별로 파일을 분리하여 저장합니다 (settings, attachments, images, cache, temp).
     *
     * @return StorageInterface 스토리지 드라이버 인스턴스
     */
    public function getStorage(): StorageInterface
    {
        if ($this->storage === null) {
            $this->storage = new ModuleStorageDriver(
                $this->getIdentifier(),
                $this->getStorageDisk()
            );
        }

        return $this->storage;
    }

    /**
     * 모듈에서 사용할 스토리지 디스크 이름 반환
     *
     * 기본값은 'modules'이며, 모듈 개발자가 다른 디스크를 사용하려면 오버라이드합니다.
     * 예: config('module-name.disk', 'modules')
     *
     * @return string 디스크 이름 (modules, public, s3 등)
     */
    public function getStorageDisk(): string
    {
        return 'modules';
    }

    /**
     * 카테고리별 스토리지 기본 경로 반환
     *
     * @param  string  $category  카테고리 (settings, attachments, images, cache, temp)
     * @return string 전체 파일 시스템 경로
     */
    public function getStorageBasePath(string $category): string
    {
        return $this->getStorage()->getBasePath($category);
    }

    /**
     * 파일의 공개 URL 반환
     *
     * public disk인 경우 직접 URL을 반환하고,
     * private disk인 경우 null을 반환합니다 (별도 API 엔드포인트 사용).
     *
     * @param  string  $category  카테고리
     * @param  string  $path  파일 경로
     * @return string|null 파일 URL (private disk인 경우 null)
     */
    public function getStorageUrl(string $category, string $path): ?string
    {
        return $this->getStorage()->url($category, $path);
    }
}
