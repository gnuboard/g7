<?php

namespace App\Extension;

use App\Exceptions\ExtensionUpdateFailedException;
use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\DeactivationReason;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Enums\LayoutSyncMode;
use App\Exceptions\ExtensionOperationInProgressException;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\Concerns\ResolvesExtensionIcons;
use App\Extension\Concerns\ResolvesUpdateCoreCompatibility;
use App\Extension\Helpers\DependencyVersionWarnings;
use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\ExtensionInstallRollbackHelper;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\ExtensionStatusGuard;
use App\Extension\Helpers\GithubHelper;
use App\Extension\Layout\DTO\LayoutSyncEntry;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\Layout\LayoutSyncReport;
use App\Providers\CoreServiceProvider;
use App\Services\LayoutExtensionService;
use App\Services\LayoutService;
use App\Services\TemplateService;
use App\Support\ExtensionIntegrations;
use App\Support\StateContracts;
use Composer\Semver\Semver;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿 관리자 클래스
 *
 * 템플릿의 로딩, 설치, 활성화, 비활성화, 제거 등을 담당합니다.
 */
class TemplateManager implements TemplateManagerInterface
{
    use ResolvesExtensionIcons;
    use ResolvesUpdateCoreCompatibility;
    use Traits\CachesTemplateStatus;
    use Traits\ClearsTemplateCaches;
    use Traits\CollectsFragmentSources;
    use Traits\ComputesLayoutContentHash;
    use Traits\InspectsUninstallData;
    use Traits\InvalidatesLayoutCache;
    use Traits\SynchronizesLayoutRows;
    use Traits\ValidatesLayoutFiles;
    use Traits\ValidatesStateContracts;

    /**
     * 아이콘 해석기에 넘길 확장 유형을 반환합니다.
     *
     * @return string 확장 유형 ('template')
     */
    protected function extensionIconType(): string
    {
        return 'template';
    }

    /** @var int install 프로그레스바 단계 수 */
    public const INSTALL_STEPS = 4;

    /** @var int update 프로그레스바 단계 수 */
    public const UPDATE_STEPS = 8;

    /** @var int uninstall 프로그레스바 단계 수 */
    public const UNINSTALL_STEPS = 3;

    protected array $templates = [];

    /**
     * 템플릿 디렉토리 스캔이 1회 이상 수행되었는지 여부
     *
     * `ensureLoaded()` 의 멱등 판정에만 쓴다 — 명시적 `loadTemplates()` 는 이 값과 무관하게
     * 항상 재스캔한다(설치/삭제 직후 갱신 계약).
     */
    protected bool $templatesLoaded = false;

    /**
     * _pending 디렉토리의 템플릿 메타데이터 배열
     *
     * @var array<string, array>
     */
    protected array $pendingTemplates = [];

    /**
     * _bundled 디렉토리의 템플릿 메타데이터 배열
     *
     * @var array<string, array>
     */
    protected array $bundledTemplates = [];

    /**
     * 마지막 설치·갱신이 빈 자리로 등록한 조각 참조 경고 (#712 — {@see takeLastLayoutWarnings()})
     *
     * @var array<int, array{file: string, partial: string, reason: string, message: string}>
     */
    protected array $lastLayoutWarnings = [];

    protected string $templatesPath;

    protected string $pendingTemplatesPath;

    protected string $bundledTemplatesPath;

    /**
     * @param  ExtensionManager  $extensionManager  설치·활성 상태 등 확장 공통 관리
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 행 조회·저장
     * @param  LayoutRepositoryInterface  $layoutRepository  레이아웃 행 조회·저장
     * @param  ModuleRepositoryInterface  $moduleRepository  모듈 행 조회·저장
     * @param  PluginRepositoryInterface  $pluginRepository  플러그인 행 조회·저장
     * @param  LayoutExtensionService  $layoutExtensionService  확장 조각 등록·해제 (설치·제거에 동반)
     * @param  LayoutRowSynchronizer  $layoutRowSynchronizer  레이아웃·확장 조각 행 동기화기 (3자 병합·기준 보관)
     * @param  LayoutExtensionRepositoryInterface  $layoutExtensionRepository  확장 조각 행 조회 (수정한 레이아웃 목록)
     */
    public function __construct(
        protected ExtensionManager $extensionManager,
        protected TemplateRepositoryInterface $templateRepository,
        protected LayoutRepositoryInterface $layoutRepository,
        protected ModuleRepositoryInterface $moduleRepository,
        protected PluginRepositoryInterface $pluginRepository,
        protected LayoutExtensionService $layoutExtensionService,
        protected LayoutRowSynchronizer $layoutRowSynchronizer,
        protected LayoutExtensionRepositoryInterface $layoutExtensionRepository
    ) {
        $this->templatesPath = base_path('templates');
        $this->pendingTemplatesPath = $this->templatesPath.DIRECTORY_SEPARATOR.'_pending';
        $this->bundledTemplatesPath = $this->templatesPath.DIRECTORY_SEPARATOR.'_bundled';
    }

    /**
     * 코어 캐시 드라이버를 반환합니다.
     *
     * 이 메서드가 다루는 키(`template.*`, `layout.*`)는 모두 코어 소유이므로
     * 항상 `g7:core:` 네임스페이스를 써야 한다. 컨테이너의 `CacheInterface`
     * 바인딩(모듈/플러그인 테스트가 일시적으로 `PluginCacheDriver` 등으로
     * 재바인딩할 수 있음)에 의존하면 누수된 바인딩 때문에 forget/remember 가
     * `g7:plugin.*` 네임스페이스로 빗나가므로, 항상 CoreCacheDriver 를 직접 생성한다.
     *
     * @return CacheInterface 코어 캐시 드라이버
     */
    private function cache(): CacheInterface
    {
        return new CoreCacheDriver(config('cache.default', 'array'));
    }

    /**
     * 템플릿이 아직 로드되지 않았을 때만 로드합니다. (멱등)
     *
     * 소비자가 "템플릿 맵이 채워져 있음" 만 필요로 할 때 쓴다. `loadTemplates()` 는 맵을
     * 리셋하고 디렉토리를 통째로 재스캔하므로, 그것을 무조건 호출하면 공유 인스턴스의
     * 상태를 매번 갈아엎으면서 풀스캔 비용까지 반복된다.
     *
     * @return void 반환값 없음
     */
    public function ensureLoaded(): void
    {
        if ($this->templatesLoaded) {
            return;
        }

        $this->loadTemplates();
    }

    /**
     * 모든 템플릿을 로드하고 초기화합니다.
     *
     * 항상 재스캔한다 — 설치/삭제/업데이트 직후 갱신을 보장하는 계약이다.
     * 단순히 "채워져 있으면 됨" 인 호출자는 `ensureLoaded()` 를 쓴다.
     *
     * @return void 반환값 없음
     */
    public function loadTemplates(): void
    {
        // 기존 템플릿 캐시 초기화 (테스트 환경에서 재로드 지원)
        $this->templates = [];
        $this->templatesLoaded = true;

        if (! File::exists($this->templatesPath)) {
            return;
        }

        $directories = File::directories($this->templatesPath);

        foreach ($directories as $directory) {
            $templateName = basename($directory);

            // _bundled, _pending 등 내부 디렉토리 건너뛰기
            if (str_starts_with($templateName, '_')) {
                continue;
            }

            $templateFile = $directory.'/template.json';

            // vendor-name 형식 검증
            if (! preg_match('/^[a-z0-9]+-[a-z0-9_]+$/i', $templateName)) {
                Log::warning("Invalid template directory name: {$templateName}. Expected format: vendor-name");

                continue;
            }

            // 무결성 검사: 활성 디렉토리는 있으나 template.json 누락 감지
            if (! File::exists($templateFile)) {
                Log::warning('템플릿 활성 디렉토리가 불완전합니다 (template.json 누락)', [
                    'template' => $templateName,
                    'directory' => $directory,
                    'hint' => "복구: php artisan template:install {$templateName} --force",
                ]);
            }

            if (File::exists($templateFile)) {
                try {
                    $jsonContent = File::get($templateFile);
                    $templateData = json_decode($jsonContent, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::error("Failed to parse template.json in {$templateName}: ".json_last_error_msg());

                        continue;
                    }

                    // 필수 필드 검증
                    if (! $this->validateTemplateData($templateData, $templateName)) {
                        continue;
                    }

                    // 다국어 구조 확인 및 변환 (역호환성)
                    if (isset($templateData['name'])) {
                        $templateData['name'] = $this->convertToMultilingual($templateData['name']);
                    }
                    if (isset($templateData['description'])) {
                        $templateData['description'] = $this->convertToMultilingual($templateData['description']);
                    }

                    // 경로 정보 추가
                    $templateData['_paths'] = [
                        'root' => $directory,
                        'components_manifest' => $directory.'/components.json',
                        'routes' => $directory.'/routes.json',
                        'components_bundle' => $directory.'/dist/components.iife.js',
                        'assets' => $directory.'/assets',
                        'lang' => $directory.'/lang',
                        'layouts' => $directory.'/layouts',
                    ];

                    $this->templates[$templateName] = $templateData;
                } catch (\Exception $e) {
                    Log::error("Failed to load template {$templateName}: ".$e->getMessage());
                }
            }
        }

        // _pending 디렉토리 로드
        $this->loadPendingTemplates();

        // _bundled 디렉토리 로드
        $this->loadBundledTemplates();
    }

    /**
     * _pending 디렉토리의 템플릿 메타데이터를 로드합니다.
     *
     * 클래스 로드 없이 template.json 메타데이터만 읽어 저장합니다.
     * 이미 활성 디렉토리에 로드된 템플릿은 제외합니다.
     *
     * @return void 반환값 없음
     */
    protected function loadPendingTemplates(): void
    {
        $pending = ExtensionPendingHelper::loadPendingExtensions($this->templatesPath, 'template.json');

        foreach ($pending as $identifier => $metadata) {
            // 이미 활성 디렉토리에 로드된 템플릿은 제외
            if (isset($this->templates[$identifier])) {
                continue;
            }

            $this->pendingTemplates[$identifier] = $metadata;
        }
    }

    /**
     * _bundled 디렉토리의 템플릿 메타데이터를 로드합니다.
     *
     * 클래스 로드 없이 template.json 메타데이터만 읽어 저장합니다.
     * 이미 활성 디렉토리 또는 _pending에 로드된 템플릿은 제외합니다.
     *
     * @return void 반환값 없음
     */
    protected function loadBundledTemplates(): void
    {
        $bundled = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');

        foreach ($bundled as $identifier => $metadata) {
            // 이미 활성 디렉토리 또는 pending에 로드된 템플릿은 제외
            if (isset($this->templates[$identifier]) || isset($this->pendingTemplates[$identifier])) {
                continue;
            }

            $this->bundledTemplates[$identifier] = $metadata;
        }
    }

    /**
     * _pending 디렉토리의 템플릿 메타데이터를 반환합니다.
     *
     * @return array _pending 템플릿 메타데이터 배열
     */
    public function getPendingTemplates(): array
    {
        return $this->pendingTemplates;
    }

    /**
     * _bundled 디렉토리의 템플릿 메타데이터를 반환합니다.
     *
     * @return array _bundled 템플릿 메타데이터 배열
     */
    public function getBundledTemplates(): array
    {
        return $this->bundledTemplates;
    }

    /**
     * 문자열을 다국어 배열로 변환 (역호환성)
     *
     * @param  mixed  $value  변환할 값 (문자열 또는 배열)
     * @return array 다국어 배열
     */
    protected function convertToMultilingual($value): array
    {
        // 이미 배열인 경우 그대로 반환
        if (is_array($value)) {
            return $value;
        }

        // 문자열인 경우 모든 지원 언어로 변환
        if (is_string($value)) {
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $result = [];
            foreach ($locales as $locale) {
                $result[$locale] = $value;
            }

            return $result;
        }

        // 그 외의 경우 빈 배열
        $locales = config('app.translatable_locales', ['ko', 'en']);
        $result = [];
        foreach ($locales as $locale) {
            $result[$locale] = '';
        }

        return $result;
    }

    /**
     * 다국어 값 추출 헬퍼 메서드
     *
     * @param  mixed  $value  추출할 값 (문자열 또는 배열)
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return string 추출된 값
     */
    protected function getLocalizedValue($value, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        // 문자열인 경우 그대로 반환
        if (is_string($value)) {
            return $value;
        }

        // 배열인 경우 로케일에 맞는 값 반환
        if (is_array($value)) {
            // 요청 언어 → fallback 언어 → 첫 번째 값 → 빈 문자열
            return $value[$locale]
                ?? $value[config('app.fallback_locale')]
                ?? (! empty($value) ? array_values($value)[0] : '')
                ?? '';
        }

        return '';
    }

    /**
     * 템플릿 데이터 유효성 검증
     *
     * @param  array  $data  템플릿 데이터
     * @param  string  $templateName  템플릿 디렉토리명
     * @return bool 유효성 검증 결과
     */
    protected function validateTemplateData(array $data, string $templateName): bool
    {
        $requiredFields = ['identifier', 'vendor', 'name', 'version', 'type'];

        foreach ($requiredFields as $field) {
            if (! isset($data[$field])) {
                Log::error("Missing required field '{$field}' in template.json for {$templateName}");

                return false;
            }
        }

        // type 값 검증
        if (! in_array($data['type'], ['admin', 'user'])) {
            Log::error("Invalid type '{$data['type']}' in template.json for {$templateName}. Must be 'admin' or 'user'");

            return false;
        }

        return true;
    }

    /**
     * 디렉토리명(vendor-name)을 네임스페이스(Vendor\Name)로 변환합니다.
     *
     * 하이픈(-)은 네임스페이스 구분자(\)로, 언더스코어(_)는 PascalCase로 변환됩니다.
     * 예: sirsoft-admin_basic -> Sirsoft\AdminBasic
     *
     * @param  string  $directoryName  디렉토리명 (예: sirsoft-admin_basic, sirsoft-user_theme)
     * @return string 네임스페이스 (예: Sirsoft\AdminBasic, Sirsoft\UserTheme)
     */
    protected function convertDirectoryToNamespace(string $directoryName): string
    {
        return ExtensionManager::directoryToNamespace($directoryName);
    }

    /**
     * 활성화된 템플릿을 반환합니다.
     *
     * @param  string  $type  템플릿 타입 ('admin' 또는 'user')
     * @return array|null 활성화된 템플릿 데이터 또는 null
     */
    public function getActiveTemplate(string $type): ?array
    {
        // 캐시된 활성화 템플릿 identifier 목록 활용
        $activeIdentifiers = self::getActiveTemplateIdentifiersByType($type);

        if (empty($activeIdentifiers)) {
            return null;
        }

        // 타입별 활성 템플릿은 하나만 존재
        $identifier = $activeIdentifiers[0];

        return $this->templates[$identifier] ?? null;
    }

    /**
     * 지정된 템플릿을 시스템에 설치합니다.
     *
     * @param  string  $templateName  설치할 템플릿명 (identifier)
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @param  bool  $force  Updating/Failed 등 진행 중 상태도 무시하고 강제 설치 여부
     * @return bool 설치 성공 여부
     *
     * @throws \Exception 템플릿을 찾을 수 없거나 의존성 문제 시
     */
    public function installTemplate(string $templateName, ?\Closure $onProgress = null, bool $force = false): bool
    {
        // 직전 훅 — CLI·어드민 UI 공통으로 한 번 발화한다(예전에는 Service 에서만 발화해 CLI 가 빠졌다, #138 후속)
        HookManager::doAction('core.templates.before_install', $templateName);

        // identifier 형식 검증 (내부 호출 방어)
        ExtensionManager::validateIdentifierFormat($templateName);

        // 상태 가드: 진행 중인 작업이 있으면 차단
        $existingRecord = $this->templateRepository->findByIdentifier($templateName);
        if ($existingRecord) {
            ExtensionStatusGuard::assertNotInProgress(
                ExtensionStatus::from($existingRecord->status),
                $templateName
            );
        }

        // 검증(2단계)은 복사된 활성 디렉토리를 읽어야 해서 복사보다 뒤에 온다. 그래서 검증이
        // 실패하면 방금 만든 활성 디렉토리가 고아로 남는다 — DB 행이 없어 목록에도 뜨지 않고
        // 오류도 남지 않은 채 디스크만 점유한다. 이번 호출이 만든 것이면 되돌린다.
        $rollbackActivePath = $this->templatesPath.DIRECTORY_SEPARATOR.$templateName;
        $rollbackDirExisted = File::isDirectory($rollbackActivePath);

        // 1. _pending/_bundled에서 활성 디렉토리로 복사 (활성 디렉토리에 없는 경우)
        // force=true 시 활성 디렉토리가 있어도 원본으로 덮어씀 (불완전 설치 복구)
        $onProgress?->__invoke('copy', __('extensions.progress.copy_files'));
        if ($force || ! isset($this->templates[$templateName])) {
            $this->copyToActiveFromSource($templateName, $onProgress, $force);
        }

        // 2. 검증
        $onProgress?->__invoke('validate', __('extensions.progress.validate'));

        try {
            DB::transaction(function () use ($templateName, $onProgress) {
                $template = $this->getTemplate($templateName);
                if (! $template) {
                    throw new \Exception(__('templates.errors.not_found', ['template' => $templateName]));
                }

                // 의존성 확인
                $this->checkDependencies($template);

                // SEO 설정 검증 (설치 전 seo-config.json 유효성 검사)
                $this->validateSeoConfig($templateName);

                // 레이아웃 검증 (설치 전 모든 레이아웃 파일 유효성 검사) — 7.0 JSON 의 깨진 조각 참조는 경고로 모은다 (#712)
                $this->lastLayoutWarnings = [];
                $this->validateLayouts($templateName);
                $this->rememberLayoutWarnings();

                // name과 description 다국어 변환 (역호환성)
                $name = $this->convertToMultilingual($template['name']);
                $description = $this->convertToMultilingual($template['description'] ?? '');

                // 활성 언어팩의 manifest seed(ja 등)를 name/description 다국어 필드에 주입
                $manifest = HookManager::applyFilters(
                    "template.{$templateName}.manifest.translations",
                    ['name' => $name, 'description' => $description]
                );
                $name = $manifest['name'] ?? $name;
                $description = $manifest['description'] ?? $description;

                // 3. DB 등록
                $onProgress?->__invoke('db', __('extensions.progress.db_register'));

                // 템플릿 레코드 생성 또는 업데이트
                $templateRecord = $this->templateRepository->updateOrCreate(
                    ['identifier' => $templateName],
                    [
                        'vendor' => $template['vendor'],
                        'name' => $name,
                        'version' => $template['version'],
                        'type' => $template['type'],
                        'description' => $description,
                        'github_url' => $template['github_url'] ?? null,
                        'metadata' => $template['metadata'] ?? null,
                        'status' => ExtensionStatus::Inactive->value,
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                    ]
                );

                // 4. 레이아웃 등록
                $onProgress?->__invoke('layout', __('extensions.progress.layout_register'));

                // 레이아웃 JSON 파일 일괄 등록
                $this->registerLayouts($templateName, $templateRecord->id);

                // 모듈 레이아웃 오버라이드 등록
                $this->registerLayoutOverrides($templateName, $templateRecord->id);

                // Extension 오버라이드 등록 (모듈/플러그인 Extension 커스터마이징)
                $this->registerExtensionOverrides($templateName, $templateRecord->id);

                // 에러 레이아웃 검증 (레이아웃 등록 후 수행)
                $this->validateErrorLayouts($templateName, $template);

                // 템플릿 상태 캐시 무효화
                self::invalidateTemplateStatusCache();

                // 확장 캐시 버전 증가 (프론트엔드가 새로운 캐시로 요청하도록)
                $this->incrementExtensionCacheVersion();

                // 훅 발행: 템플릿 설치 완료
                // @deprecated 'core.templates.installed' — identifier 1개만 전달하는 구훅. 정규 훅은
                //   트랜잭션 밖의 after_install 이다. 구독자가 있어 다음 메이저까지 유지(위치도 그대로).
                HookManager::doAction('core.templates.installed', $templateName);
            });
        } catch (\Throwable $e) {
            ExtensionInstallRollbackHelper::removeIfCreatedByThisInstall(
                $rollbackActivePath,
                $rollbackDirExisted,
                $templateName,
                'template',
            );

            throw $e;
        }

        // 정규 훅 — CLI·어드민 UI 공통 단일 발화(#138). **DB 트랜잭션 밖**에서 발화한다: 언어팩 동기화
        // 구독은 동기 실행이라, 트랜잭션 안이면 커밋 실패 시 언어팩 레코드만 롤백되고 파일 I/O 는 남는다.
        // 설치 롤백 try 밖이기도 해서, 리스너 예외가 방금 끝난 설치를 되돌리지 않는다.
        HookManager::doAction('core.templates.after_install', $templateName, $this->getTemplateInfo($templateName) ?? []);

        return true;
    }

    /**
     * 지정된 템플릿을 활성화합니다.
     *
     * @param  string  $templateName  활성화할 템플릿명 (identifier)
     * @param  bool  $force  필요 의존성이 충족되지 않아도 강제 활성화 여부
     * @return array{success: bool, warning?: bool, missing_modules?: array, missing_plugins?: array, message?: string} 활성화 결과
     *
     * @throws \Exception 활성화 실패 시
     */
    public function activateTemplate(string $templateName, bool $force = false): array
    {
        // 직전 훅 — CLI·어드민 UI 공통으로 한 번 발화한다(예전에는 Service 에서만 발화해 CLI 가 빠졌다, #138 후속)
        HookManager::doAction('core.templates.before_activate', $templateName);

        $template = $this->getTemplate($templateName);
        if (! $template) {
            throw new \Exception(__('templates.errors.not_found', ['template' => $templateName]));
        }

        $templateRecord = $this->templateRepository->findByIdentifier($templateName);
        if (! $templateRecord) {
            throw new \Exception(__('templates.errors.not_installed', ['template' => $templateName]));
        }

        // 상태 가드: 설치·삭제·업데이트가 진행 중이면 차단 (모듈·플러그인 활성화와 같은 가드)
        ExtensionStatusGuard::assertNotInProgress(
            ExtensionStatus::from($templateRecord->status),
            $templateName
        );

        // 필터 훅 — 직전 훅 뒤에 돈다(before → filter 순서). 직전 훅과 함께 Manager 로 옮겨 CLI·어드민 UI 공통으로
        // 한 번 발화한다(#138 후속 — 설계 결정). 결과 값은 종전처럼 쓰지 않는다(활성화 상태는 아래에서 정한다).
        HookManager::applyFilters('core.templates.filter_activate_data', ['status' => ExtensionStatus::Active->value], $templateRecord);

        // 이미 활성화된 템플릿인지 확인
        if ($templateRecord->status === ExtensionStatus::Active->value) {
            throw new \Exception(__('templates.errors.already_active'));
        }

        // 코어 버전 호환성 사전 검증
        if (! $force && ! CoreServiceProvider::isCoreUpdateInProgress()) {
            CoreVersionChecker::validateExtension(
                $template['g7_version'] ?? null,
                $templateName,
                'template'
            );
        }

        // 전역 상태 공개 계약 이름 충돌 검증 (#639) — 같은 유형의 활성 템플릿은 이번 활성화로 교체되므로
        // 그 선언은 빼고 본다. 같은 계약을 선언한 새 사용자 템플릿이 옛 템플릿 때문에 막히면 안 된다.
        $replaced = $this->getActiveTemplate((string) ($template['type'] ?? ''));
        $this->validateStateContracts(
            'template:'.$templateName,
            StateContracts::normalize($template['state_contracts'] ?? []),
            is_array($replaced) && isset($replaced['identifier']) ? ['template:'.$replaced['identifier']] : [],
        );

        // 의존성 검증: 필요한 모듈/플러그인이 활성화되어 있는지 확인
        $missingModules = [];
        $missingPlugins = [];

        // 모듈 의존성 확인
        // dependencies.modules는 연관 배열 형식 {"identifier": ">=version"} 또는 빈 객체 {}
        $requiredModules = $template['dependencies']['modules'] ?? [];
        foreach ($requiredModules as $requiredModuleIdentifier => $_versionConstraint) {
            $requiredModule = $this->moduleRepository->findByIdentifier($requiredModuleIdentifier);
            if (! $requiredModule) {
                $missingModules[] = [
                    'identifier' => $requiredModuleIdentifier,
                    'name' => $requiredModuleIdentifier,
                    'status' => 'not_installed',
                ];
            } elseif ($requiredModule->status !== ExtensionStatus::Active->value) {
                $missingModules[] = [
                    'identifier' => $requiredModule->identifier,
                    'name' => $requiredModule->getLocalizedName(),
                    'status' => 'inactive',
                ];
            }
        }

        // 플러그인 의존성 확인
        // dependencies.plugins는 연관 배열 형식 {"identifier": ">=version"} 또는 빈 객체 {}
        $requiredPlugins = $template['dependencies']['plugins'] ?? [];
        foreach ($requiredPlugins as $requiredPluginIdentifier => $_versionConstraint) {
            $requiredPlugin = $this->pluginRepository->findByIdentifier($requiredPluginIdentifier);
            if (! $requiredPlugin) {
                $missingPlugins[] = [
                    'identifier' => $requiredPluginIdentifier,
                    'name' => $requiredPluginIdentifier,
                    'status' => 'not_installed',
                ];
            } elseif ($requiredPlugin->status !== ExtensionStatus::Active->value) {
                $missingPlugins[] = [
                    'identifier' => $requiredPlugin->identifier,
                    'name' => $requiredPlugin->getLocalizedName(),
                    'status' => 'inactive',
                ];
            }
        }

        $hasMissingDependencies = ! empty($missingModules) || ! empty($missingPlugins);

        if ($hasMissingDependencies && ! $force) {
            // 필요한 의존성이 충족되지 않고 강제 활성화가 아닌 경우 경고 반환
            return [
                'success' => false,
                'warning' => true,
                'missing_modules' => $missingModules,
                'missing_plugins' => $missingPlugins,
                'message' => __('templates.warnings.missing_dependencies'),
            ];
        }

        // 실제 활성화 처리
        DB::transaction(function () use ($templateName, $template, $templateRecord) {
            // 같은 타입의 기존 활성 템플릿 비활성화
            $this->deactivateTemplatesByType($template['type']);

            // 데이터베이스 상태 업데이트
            $this->templateRepository->updateByIdentifier($templateName, [
                'status' => ExtensionStatus::Active->value,
                'deactivated_reason' => null,
                'deactivated_at' => null,
                'incompatible_required_version' => null,
                'updated_at' => now(),
            ]);

            // 템플릿 자기 레이아웃을 keep 규칙으로 갱신한다(D11) — 꺼 둔 채 업데이트한 템플릿은 켤 때 새 판이 들어와야
            // 하고(종전엔 옛 화면에 고착), 운영자가 고친 부분은 남아야 한다. 파일이 원본과 같으면 아무것도 바꾸지 않는다.
            try {
                $this->refreshTemplateLayouts($templateName, true);
            } catch (\Throwable $e) {
                Log::error('템플릿 활성화 중 레이아웃 갱신 실패', ['template' => $templateName, 'error' => $e->getMessage()]);
            }

            // 활성 모듈/플러그인의 레이아웃을 새 템플릿에 **먼저** 등록한다 — 아래 조각 등록의 적용 필터(C10)가 호스트
            // 레이아웃·확장점이 이 템플릿에 실재하는지 보므로, 호스트가 없는 채로 조각을 먼저 등록하면 모듈 화면에 붙는
            // 조각이 통째로 빠진다(#712 — 새 관리자 템플릿 활성화 시 38 → 27행).
            // registerModuleLayouts/registerPluginLayouts는 모든 활성 템플릿을 조회하므로
            // 새로 활성화된 템플릿에도 자동으로 레이아웃이 등록됨 (keep 규칙 — 템플릿을 바꿨다 돌아와도 운영자 수정 보존, D3)
            app(ModuleManager::class)->registerLayoutsForAllActiveModules();
            app(PluginManager::class)->registerLayoutsForAllActivePlugins();

            // 그 뒤에 활성 모듈/플러그인의 레이아웃 확장(조각)을 새 템플릿에 등록 (keep — 운영자가 고친 조각은 보존)
            $this->layoutExtensionService->registerAllActiveExtensionsToTemplate($templateRecord->id);

            // 확장 캐시 버전 증가 (프론트엔드가 새로운 캐시로 요청하도록)
            $this->incrementExtensionCacheVersion();

            // 캐시 워밍 (활성화된 템플릿만) - 확장/레이아웃 등록 후 실행하여 캐시에 반영
            $this->warmTemplateCache($templateName);

            // 템플릿 상태 캐시 무효화
            self::invalidateTemplateStatusCache();

            Log::info(__('templates.messages.template_activated'), [
                'template' => $templateName,
                'type' => $template['type'],
            ]);
        });

        // 훅 발행: 템플릿 활성화 완료
        // @deprecated 'core.templates.activated' — identifier 1개만 전달하는 구훅. 정규 훅은 after_activate.
        HookManager::doAction('core.templates.activated', $templateName);

        // 정규 훅 — CLI·어드민 UI 공통 단일 발화(#138). 템플릿만 계약이 다르다: 식별자 없이
        // 정보 배열 1개를 넘긴다(구독자 handleTemplateAfterActivate(array)). 정보가 없어도
        // 활동 로그에 템플릿 이름이 남도록 빈 배열 대신 identifier 를 담아 넘긴다.
        HookManager::doAction('core.templates.after_activate', $this->getTemplateInfo($templateName) ?? ['identifier' => $templateName]);

        return ['success' => true];
    }

    /**
     * 지정된 타입의 모든 템플릿을 비활성화합니다.
     *
     * @param  string  $type  템플릿 타입 ('admin' 또는 'user')
     * @return void 반환값 없음
     */
    protected function deactivateTemplatesByType(string $type): void
    {
        $activeTemplates = $this->templateRepository->getActiveByType($type);

        foreach ($activeTemplates as $templateRecord) {
            // 캐시 삭제
            $this->clearTemplateCache($templateRecord->identifier);

            $this->templateRepository->updateByIdentifier($templateRecord->identifier, [
                'status' => ExtensionStatus::Inactive->value,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * 지정된 템플릿을 비활성화합니다.
     *
     * @param  string  $templateName  비활성화할 템플릿명 (identifier)
     * @param  string  $reason  비활성화 사유 (DeactivationReason enum value: manual|incompatible_core)
     * @param  string|null  $incompatibleRequiredVersion  incompatible_core 사유 시 요구된 코어 버전 제약
     * @param  string|null  $failureReason  실패 시 사유가 담기는 out 파라미터 (성공 시 null)
     * @return bool 비활성화 성공 여부
     */
    public function deactivateTemplate(
        string $templateName,
        string $reason = DeactivationReason::Manual->value,
        ?string $incompatibleRequiredVersion = null,
        ?string &$failureReason = null,
    ): bool {
        // 직전 훅 — CLI·어드민 UI 공통으로 한 번 발화한다(예전에는 Service 에서만 발화해 CLI 가 빠졌다, #138 후속)
        HookManager::doAction('core.templates.before_deactivate', $templateName);

        $failureReason = null;

        $template = $this->getTemplate($templateName);
        if (! $template) {
            $failureReason = __('templates.errors.not_found', ['template' => $templateName]);

            return false;
        }

        // 상태 가드: 진행 중 상태 체크
        $existingRecord = $this->templateRepository->findByIdentifier($templateName);
        if ($existingRecord) {
            ExtensionStatusGuard::assertNotInProgress(
                ExtensionStatus::from($existingRecord->status),
                $templateName
            );
        }

        // 캐시 삭제
        $this->clearTemplateCache($templateName);

        $this->templateRepository->updateByIdentifier($templateName, [
            'status' => ExtensionStatus::Inactive->value,
            'deactivated_reason' => $reason,
            'deactivated_at' => now(),
            'incompatible_required_version' => $incompatibleRequiredVersion,
            'updated_at' => now(),
        ]);

        // 템플릿 상태 캐시 무효화
        self::invalidateTemplateStatusCache();

        // 확장 캐시 버전 증가 (프론트엔드가 새로운 캐시로 요청하도록)
        $this->incrementExtensionCacheVersion();

        // 요구사항 #6: 비활성화 후 훅 발행 — 언어팩 cascade 등 후속 처리
        HookManager::doAction('core.templates.after_deactivate', $templateName);

        Log::info(__('templates.messages.template_deactivated'), [
            'template' => $templateName,
        ]);

        return true;
    }

    /**
     * 지정된 템플릿을 시스템에서 제거합니다.
     *
     * @param  string  $templateName  제거할 템플릿명 (identifier)
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @param  array<int, array{directory: string, archive: string}>|null  $preservedBackups  삭제 전에 보관한 운영자 소유 디렉토리(`custom/`)의 사본 경로가 담기는 out 파라미터.
     *                                                                                        운영자에게 "지웠지만 사본은 여기 있다" 를 알리기 위한 것이므로 호출부가 노출해야 한다.
     * @param  bool  $deleteData  운영자가 "데이터도 삭제" 를 골랐는지 — 제거 동작은 바꾸지 않고
     *                            after_uninstall 훅 인자로만 전달한다(활동 로그 기록용)
     * @return bool 제거 성공 여부
     *
     * @throws \Exception 템플릿을 찾을 수 없을 때
     */
    public function uninstallTemplate(
        string $templateName,
        ?\Closure $onProgress = null,
        ?array &$preservedBackups = null,
        bool $deleteData = false,
    ): bool {
        // 직전 훅 — CLI·어드민 UI 공통으로 한 번 발화한다(예전에는 Service 에서만 발화해 CLI 가 빠졌다, #138 후속)
        HookManager::doAction('core.templates.before_uninstall', $templateName, $deleteData);

        $preservedBackups = [];

        // 상태 가드: 설치·삭제·업데이트가 진행 중이면 차단 (모듈·플러그인 삭제와 같은 가드)
        $existingRecord = $this->templateRepository->findByIdentifier($templateName);
        if ($existingRecord) {
            ExtensionStatusGuard::assertNotInProgress(
                ExtensionStatus::from($existingRecord->status),
                $templateName
            );
        }

        // after_uninstall 훅에 실을 정보는 파괴 단계(DB 행 삭제·디렉토리 삭제) 이전에 캡처한다.
        $templateInfo = $this->getTemplateInfo($templateName) ?? [];

        // 1. 캐시 삭제
        $onProgress?->__invoke('cache', __('extensions.progress.cache_delete'));

        $result = DB::transaction(function () use ($templateName, $onProgress) {
            $template = $this->getTemplate($templateName);
            if (! $template) {
                throw new \Exception(__('templates.errors.not_found', ['template' => $templateName]));
            }

            // 캐시 삭제
            $this->clearTemplateCache($templateName);

            // 2. DB 삭제
            $onProgress?->__invoke('db', __('extensions.progress.db_delete'));

            // 레이아웃 삭제 (트랜잭션 내)
            $templateRecord = $this->templateRepository->findByIdentifier($templateName);
            if ($templateRecord) {
                $this->unregisterLayouts($templateRecord->id);
            }

            // Extension 오버라이드 제거
            $this->unregisterExtensionOverrides($templateName);

            // 데이터베이스에서 템플릿 정보 제거
            $this->templateRepository->deleteByIdentifier($templateName);

            // 템플릿 상태 캐시 무효화
            self::invalidateTemplateStatusCache();

            // 확장 캐시 버전 증가 (프론트엔드가 새로운 캐시로 요청하도록)
            $this->incrementExtensionCacheVersion();

            Log::info(__('templates.messages.template_uninstalled'), [
                'template' => $templateName,
            ]);

            return true;
        });

        if ($result) {
            // 3. 파일 삭제
            $onProgress?->__invoke('files', __('extensions.progress.delete_files'));

            // 활성 템플릿 디렉토리 전체 삭제 (_pending/_bundled에 원본 보존되므로 재설치 가능)
            $preservedBackups = ExtensionPendingHelper::deleteExtensionDirectory(
                $this->templatesPath,
                $templateName
            );

            // 메모리에서 템플릿 제거
            unset($this->templates[$templateName]);

            // 정규 훅 — CLI·어드민 UI 공통 단일 발화(#138)
            HookManager::doAction('core.templates.after_uninstall', $templateName, $templateInfo, $deleteData);
        }

        return $result;
    }

    /**
     * 템플릿 삭제 시 삭제될 데이터 정보를 조회합니다.
     *
     * 확장 디렉토리 경로와 용량을 반환합니다.
     *
     * @param  string  $templateName  템플릿명
     * @return array|null 삭제 정보 배열 또는 null (템플릿 없음)
     */
    public function getTemplateUninstallInfo(string $templateName): ?array
    {
        $template = $this->getTemplate($templateName);
        if (! $template) {
            return null;
        }

        // 확장 설치 디렉토리 정보 조회
        $extensionDirInfo = $this->getExtensionDirectoryInfo('templates', $templateName);

        return [
            'extension_directory' => $extensionDirInfo,
        ];
    }

    /**
     * 지정된 이름의 템플릿 데이터를 반환합니다.
     *
     * @param  string  $templateName  템플릿명 (identifier)
     * @return array|null 템플릿 데이터 또는 null
     */
    public function getTemplate(string $templateName): ?array
    {
        return $this->templates[$templateName] ?? null;
    }

    /**
     * 로드된 모든 템플릿 인스턴스들을 반환합니다.
     *
     * @return array 모든 템플릿 배열
     */
    public function getAllTemplates(): array
    {
        return $this->templates;
    }

    /**
     * 설치되지 않은 템플릿들을 반환합니다.
     *
     * @return array 미설치 템플릿 배열
     */
    public function getUninstalledTemplates(): array
    {
        $uninstalledTemplates = [];
        // 캐시된 설치된 템플릿 identifier 목록 활용
        $installedTemplateIdentifiers = self::getInstalledTemplateIdentifiers();
        $locale = app()->getLocale();

        // 활성 디렉토리 템플릿 중 미설치
        foreach ($this->templates as $identifier => $template) {
            if (! in_array($identifier, $installedTemplateIdentifiers)) {
                $uninstalledTemplates[$identifier] = [
                    'identifier' => $template['identifier'],
                    'vendor' => $template['vendor'],
                    'name' => $this->getLocalizedValue($template['name'], $locale),
                    'version' => $template['version'],
                    'type' => $template['type'],
                    'description' => $this->getLocalizedValue($template['description'] ?? '', $locale),
                    'dependencies' => $template['dependencies'] ?? [],
                    'integrations' => ExtensionIntegrations::normalize($template['integrations'] ?? []),
                    'requires_core' => $template['g7_version'] ?? null,
                    'status' => 'uninstalled',
                    'source' => 'active',
                    'hidden' => (bool) ($template['hidden'] ?? false),
                    ...$this->resolveIconFields($template['identifier'], $template['version']),
                ];
            }
        }

        // _pending 디렉토리 템플릿 중 미설치
        foreach ($this->pendingTemplates as $identifier => $metadata) {
            if (! in_array($identifier, $installedTemplateIdentifiers) && ! isset($uninstalledTemplates[$identifier])) {
                $name = $this->convertToMultilingual($metadata['name'] ?? $identifier);
                $description = $this->convertToMultilingual($metadata['description'] ?? '');
                $uninstalledTemplates[$identifier] = [
                    'identifier' => $identifier,
                    'vendor' => $metadata['vendor'] ?? '',
                    'name' => $this->getLocalizedValue($name, $locale),
                    'version' => $metadata['version'] ?? '0.0.0',
                    'type' => $metadata['type'] ?? 'admin',
                    'description' => $this->getLocalizedValue($description, $locale),
                    'dependencies' => $metadata['dependencies'] ?? [],
                    'integrations' => ExtensionIntegrations::normalize($metadata['integrations'] ?? []),
                    'requires_core' => $metadata['g7_version'] ?? null,
                    'status' => 'uninstalled',
                    'source' => 'pending',
                    'hidden' => (bool) ($metadata['hidden'] ?? false),
                    ...$this->resolveIconFields($identifier, $metadata['version'] ?? '0.0.0'),
                ];
            }
        }

        // _bundled 디렉토리 템플릿 중 미설치
        foreach ($this->bundledTemplates as $identifier => $metadata) {
            if (! in_array($identifier, $installedTemplateIdentifiers) && ! isset($uninstalledTemplates[$identifier])) {
                $name = $this->convertToMultilingual($metadata['name'] ?? $identifier);
                $description = $this->convertToMultilingual($metadata['description'] ?? '');
                $uninstalledTemplates[$identifier] = [
                    'identifier' => $identifier,
                    'vendor' => $metadata['vendor'] ?? '',
                    'name' => $this->getLocalizedValue($name, $locale),
                    'version' => $metadata['version'] ?? '0.0.0',
                    'type' => $metadata['type'] ?? 'admin',
                    'description' => $this->getLocalizedValue($description, $locale),
                    'dependencies' => $metadata['dependencies'] ?? [],
                    'integrations' => ExtensionIntegrations::normalize($metadata['integrations'] ?? []),
                    'requires_core' => $metadata['g7_version'] ?? null,
                    'status' => 'uninstalled',
                    'source' => 'bundled',
                    'hidden' => (bool) ($metadata['hidden'] ?? false),
                    ...$this->resolveIconFields($identifier, $metadata['version'] ?? '0.0.0'),
                ];
            }
        }

        return $uninstalledTemplates;
    }

    /**
     * 설치된 템플릿 정보를 데이터베이스 레코드와 함께 반환합니다 (목록용 간소화된 정보).
     *
     * 업데이트 관련 필드(update_available, latest_version, file_version, github_url)를 포함합니다.
     *
     * @return array 설치된 템플릿 배열
     */
    public function getInstalledTemplatesWithDetails(): array
    {
        $installedTemplates = [];
        $templateRecords = $this->templateRepository->getAllKeyedByIdentifier();
        $locale = app()->getLocale();

        foreach ($this->templates as $identifier => $template) {
            if ($templateRecords->has($identifier)) {
                $record = $templateRecords->get($identifier);

                // 업데이트 감지: GitHub URL이 있으면 DB latest_version 비교, 없으면 파일 버전 비교
                $fileVersion = $template['version'];
                $updateAvailable = $record->update_available ?? false;
                $latestVersion = $record->latest_version ?? null;

                // update_available이 true인데 latest_version이 null이면 _bundled 버전으로 보완
                if ($updateAvailable && $latestVersion === null) {
                    $bundledVersion = $this->bundledTemplates[$identifier]['version'] ?? null;
                    if ($bundledVersion === null) {
                        $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');
                        $bundledVersion = $bundledMeta[$identifier]['version'] ?? null;
                    }
                    $latestVersion = $bundledVersion ?? $fileVersion;
                }

                // 다국어 필드는 DB row(applyExtensionManifests 가 활성 언어팩 ja 등을 주입) 우선,
                // 미설치 시 template.json 폴백 — 상세 조회(getTemplateDetails)·모듈·플러그인과 같은 규약이다.
                // 종전에는 이 목록 경로만 디스크 매니페스트를 바로 읽어, 언어팩이 DB 에 넣어 둔 ja 가
                // 버려졌다(실측 2026-09-12: 같은 응답에서 모듈 description 은 활성 언어팩의 번역으로
                // 내려가는데 템플릿 description 만 ko 원문이 내려갔다).
                $nameJson = $record->name ?: $template['name'];
                $descriptionJson = $record->description ?: ($template['description'] ?? '');

                $installedTemplates[$identifier] = [
                    'identifier' => $template['identifier'],
                    'vendor' => $template['vendor'],
                    'name' => $this->getLocalizedValue($nameJson, $locale),
                    'version' => $record->version,
                    'type' => $template['type'],
                    'description' => $this->getLocalizedValue($descriptionJson, $locale),
                    'dependencies' => $template['dependencies'] ?? [],
                    'integrations' => ExtensionIntegrations::normalize($template['integrations'] ?? []),
                    'requires_core' => $template['g7_version'] ?? null,
                    'status' => $record->status,
                    'update_available' => $updateAvailable,
                    'latest_version' => $latestVersion,
                    'file_version' => $fileVersion,
                    'update_source' => $record->update_source ?? null,
                    // 업데이트 대상 버전의 코어 호환 — 업데이트 창의 경고·강제 업데이트 체크가 읽는다(업데이트가 있을 때만).
                    ...($updateAvailable ? $this->buildUpdateCompatibilityFields($this->resolveUpdateTargetRequiredCoreVersion(
                        $record->update_source ?? null,
                        $this->findBundledTemplateManifest($identifier),
                        $template['g7_version'] ?? null
                    )) : []),
                    'github_url' => $template['github_url'] ?? ($record->github_url ?? null),
                    'github_changelog_url' => $record->github_changelog_url ?? ($template['github_changelog_url'] ?? null),
                    'hidden' => (bool) ($template['hidden'] ?? false),
                    ...$this->resolveIconFields($template['identifier'], $record->version),
                    'user_modified_at' => $record->user_modified_at,
                    'created_at' => $record->created_at,
                    'updated_at' => $record->updated_at,
                ];
            }
        }

        return $installedTemplates;
    }

    /**
     * 템플릿 식별자에 대한 메타데이터(다국어 치환 + 활성 상태 포함)를 조회합니다.
     *
     * 활성 디렉토리에 존재하지 않으면 pending/bundled 메타데이터로 폴백합니다.
     *
     * @param  string  $templateName  템플릿 식별자
     * @return array<string, mixed>|null 템플릿 메타데이터 (이름·설명·버전·상태 등) 또는 부재 시 null
     */
    public function getTemplateInfo(string $templateName): ?array
    {
        $template = $this->getTemplate($templateName);

        // 활성 디렉토리에 없으면 pending/bundled 메타데이터에서 폴백
        if (! $template) {
            return $this->getTemplateInfoFromMetadata($templateName);
        }

        $templateRecord = $this->templateRepository->findByIdentifier($templateName);
        $locale = app()->getLocale();

        // 레이아웃 개수 조회
        $layoutsCount = 0;
        if ($templateRecord) {
            $layoutsCount = $this->layoutRepository->countByTemplateId($templateRecord->id);
        }

        // 컴포넌트 정보 조회
        $components = $this->getTemplateComponents($templateName);

        // 다국어 필드는 DB row(applyExtensionManifests 가 활성 언어팩 ja 등을 주입) 우선,
        // 미설치 시 template.json 폴백.
        $nameJson = $templateRecord?->name ?: $template['name'];
        $descriptionJson = $templateRecord?->description ?: ($template['description'] ?? '');

        return [
            'identifier' => $template['identifier'],
            'vendor' => $template['vendor'],
            'name' => $this->getLocalizedValue($nameJson, $locale),
            'version' => $template['version'],
            'latest_version' => $template['latest_version'] ?? null,
            'update_available' => $template['update_available'] ?? false,
            'type' => $template['type'],
            'description' => $this->getLocalizedValue($descriptionJson, $locale),
            'github_url' => $template['github_url'] ?? null,
            'github_changelog_url' => $template['github_changelog_url'] ?? null,
            'requires_core' => $template['g7_version'] ?? null,
            'dependencies' => $template['dependencies'] ?? [],
            'integrations' => ExtensionIntegrations::normalize($template['integrations'] ?? []),
            'externals' => $template['externals'] ?? [],
            'locales' => $template['locales'] ?? [],
            'layouts_count' => $layoutsCount,
            'components' => $components,
            'license' => $template['license'] ?? null,
            'metadata' => $template['metadata'] ?? [],
            'status' => $templateRecord ? $templateRecord->status : 'not_installed',
            'is_installed' => (bool) $templateRecord,
            'user_modified_at' => $templateRecord?->user_modified_at,
            'created_at' => $templateRecord?->created_at,
            'updated_at' => $templateRecord?->updated_at,
            ...$this->resolveIconFields($template['identifier'], $template['version']),
        ];
    }

    /**
     * pending/bundled 메타데이터에서 템플릿 정보를 반환합니다.
     *
     * 활성 디렉토리에 템플릿 데이터가 없는 경우 (미설치 상태)
     * JSON 메타데이터 기반으로 동일 구조의 정보를 반환합니다.
     *
     * @param  string  $templateName  템플릿명 (identifier)
     * @return array|null 템플릿 정보 배열 또는 null
     */
    protected function getTemplateInfoFromMetadata(string $templateName): ?array
    {
        $metadata = $this->pendingTemplates[$templateName] ?? $this->bundledTemplates[$templateName] ?? null;

        if (! $metadata) {
            return null;
        }

        $locale = app()->getLocale();
        $name = $this->convertToMultilingual($metadata['name'] ?? $templateName);
        $description = $this->convertToMultilingual($metadata['description'] ?? '');

        return [
            'identifier' => $metadata['identifier'] ?? $templateName,
            'vendor' => $metadata['vendor'] ?? '',
            'name' => $this->getLocalizedValue($name, $locale),
            'version' => $metadata['version'] ?? '0.0.0',
            'latest_version' => null,
            'update_available' => false,
            'type' => $metadata['type'] ?? 'admin',
            'description' => $this->getLocalizedValue($description, $locale),
            'github_url' => $metadata['github_url'] ?? null,
            'github_changelog_url' => $metadata['github_changelog_url'] ?? null,
            'requires_core' => $metadata['g7_version'] ?? null,
            'dependencies' => $metadata['dependencies'] ?? [],
            'integrations' => ExtensionIntegrations::normalize($metadata['integrations'] ?? []),
            'externals' => $metadata['externals'] ?? [],
            'locales' => $metadata['locales'] ?? [],
            'layouts_count' => 0,
            'components' => $metadata['components'] ?? [],
            'license' => $metadata['license'] ?? null,
            'metadata' => $metadata,
            'status' => 'not_installed',
            'is_installed' => false,
            'user_modified_at' => null,
            'created_at' => null,
            'updated_at' => null,
            ...$this->resolveIconFields($metadata['identifier'] ?? $templateName, $metadata['version'] ?? '0.0.0'),
        ];
    }

    /**
     * 템플릿의 `seo-config.json` 을 검증합니다.
     *
     * 파일은 선택이라 없으면 통과시키고(SEO 렌더가 div 폴백으로 돈다), 있으면 JSON 파싱 ·
     * `component_map` 의 `tag` 필수 · `render` 가 가리키는 모드의 실재 · `render_modes` 의
     * 타입 허용값을 본다.
     *
     * @param  string  $templateId  템플릿 식별자
     * @return bool 검증 성공 여부 (파일이 없어도 true)
     *
     * @throws \Exception 파싱 실패·구조 위반 시
     */
    protected function validateSeoConfig(string $templateId): bool
    {
        $path = base_path("templates/{$templateId}/seo-config.json");

        // 1. 파일 미존재 → 경고만 (선택 파일이므로 설치 차단 안함)
        if (! File::exists($path)) {
            Log::info("[Template] seo-config.json not found for {$templateId} — SEO rendering will use div fallback");

            return true;
        }

        // 2. JSON 파싱 검증
        $content = File::get($path);
        $config = json_decode($content, true);
        if (! is_array($config)) {
            throw new \Exception(
                "seo-config.json is invalid JSON for template '{$templateId}'"
            );
        }

        // 3. component_map 구조 검증
        if (isset($config['component_map'])) {
            foreach ($config['component_map'] as $name => $entry) {
                if (! is_array($entry)) {
                    throw new \Exception(
                        "seo-config.json: component_map.{$name} must be an object"
                    );
                }

                // tag 필드 검증 (필수, 빈 문자열 허용 — Fragment 등)
                if (! array_key_exists('tag', $entry) || ! is_string($entry['tag'])) {
                    throw new \Exception(
                        "seo-config.json: component_map.{$name}.tag is required and must be a string"
                    );
                }

                // render 필드 → render_modes에 정의 존재 확인
                if (isset($entry['render'])) {
                    $renderModeName = $entry['render'];
                    if (! isset($config['render_modes'][$renderModeName])) {
                        throw new \Exception(
                            "seo-config.json: component_map.{$name}.render references undefined mode '{$renderModeName}'"
                        );
                    }
                }
            }
        }

        // 4. render_modes 구조 검증
        if (isset($config['render_modes'])) {
            $validTypes = ['iterate', 'format', 'raw', 'fields', 'pagination'];
            foreach ($config['render_modes'] as $modeName => $modeConfig) {
                if (! is_array($modeConfig)) {
                    throw new \Exception(
                        "seo-config.json: render_modes.{$modeName} must be an object"
                    );
                }

                $type = $modeConfig['type'] ?? null;
                if (! $type || ! in_array($type, $validTypes, true)) {
                    throw new \Exception(
                        "seo-config.json: render_modes.{$modeName}.type must be one of: ".implode(', ', $validTypes)
                    );
                }
            }
        }

        // 5. stylesheets 검증 (배열인지만 확인)
        if (isset($config['stylesheets']) && ! is_array($config['stylesheets'])) {
            throw new \Exception(
                'seo-config.json: stylesheets must be an array'
            );
        }

        // 6. self_closing 검증 (배열인지만 확인)
        if (isset($config['self_closing']) && ! is_array($config['self_closing'])) {
            throw new \Exception(
                'seo-config.json: self_closing must be an array'
            );
        }

        // 7. seo_overrides 검증 (객체이고, _local/_global 키만 허용)
        if (isset($config['seo_overrides'])) {
            if (! is_array($config['seo_overrides'])) {
                throw new \Exception(
                    'seo-config.json: seo_overrides must be an object'
                );
            }
            $allowedScopes = ['_local', '_global'];
            foreach (array_keys($config['seo_overrides']) as $scope) {
                if (! in_array($scope, $allowedScopes, true)) {
                    throw new \Exception(
                        "seo-config.json: seo_overrides.{$scope} is not allowed (use _local or _global)"
                    );
                }
                if (! is_array($config['seo_overrides'][$scope])) {
                    throw new \Exception(
                        "seo-config.json: seo_overrides.{$scope} must be an object"
                    );
                }
            }
        }

        return true;
    }

    /**
     * 에러 레이아웃 파일의 존재 여부를 검증합니다.
     *
     * @param  string  $templateId  템플릿 식별자
     * @param  array  $templateData  template.json 데이터
     * @return bool 검증 성공 여부
     *
     * @throws \Exception 검증 실패 시
     */
    protected function validateErrorLayouts(string $templateId, array $templateData): bool
    {
        $templatePath = base_path("templates/{$templateId}");

        // 템플릿 디렉토리가 존재하지 않는 경우 검증 건너뛰기 (테스트 환경 등)
        if (! File::exists($templatePath)) {
            Log::debug('에러 레이아웃 검증 건너뛰기: 템플릿 디렉토리가 존재하지 않음', [
                'template' => $templateId,
            ]);

            return true;
        }

        // 1. error_config 섹션 존재 확인
        if (! isset($templateData['error_config']['layouts'])) {
            throw new \Exception(
                __('templates.errors.missing_error_config')
            );
        }

        $errorLayouts = $templateData['error_config']['layouts'];
        $requiredErrorCodes = [404, 403, 500];

        // 2. 필수 에러 코드별 레이아웃 정의 확인
        foreach ($requiredErrorCodes as $code) {
            // 숫자 또는 문자열 키 모두 허용
            if (! isset($errorLayouts[$code]) && ! isset($errorLayouts[(string) $code])) {
                throw new \Exception(
                    __('templates.errors.missing_error_layout', ['code' => $code])
                );
            }

            // 레이아웃 이름 (숫자 또는 문자열 키) — 다른 모든 카테고리(auth/, board/) 와
            // 동일하게 디렉토리 접두사 포함된 식별자를 그대로 사용한다 (예: "errors/404").
            $layoutName = $errorLayouts[$code] ?? $errorLayouts[(string) $code];

            // 3. 레이아웃 파일 실제 존재 확인 — layout_name 그대로 layouts/ 하위 파일 경로로 사용.
            //    소스 선택은 설치 경로와 **같은 우선순위 규약**을 쓴다. 종전에는 여기만 `.json` 을
            //    하드코딩해, 소스(`.g7.html`)으로만 작성된 에러 레이아웃이 registerLayouts() 로
            //    정상 등록된 직후 이 검증에서 "없다" 로 판정돼 설치가 세워졌다 (34차 ❌2).
            $found = false;

            foreach ($this->layoutSourceCandidates($templatePath.'/layouts/'.$layoutName.'.json') as $candidate) {
                if (File::exists($candidate['path'])) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                throw new \Exception(
                    __('templates.errors.error_layout_not_found', [
                        'code' => $code,
                        'path' => $layoutName,
                    ])
                );
            }
        }

        return true;
    }

    /**
     * 템플릿의 코어 버전 호환성(`template.json` 의 `g7_version`)을 확인하고, 아니면 예외로 세웁니다.
     *
     * 모듈·플러그인의 동명 메서드와 판정 대상이 다르다 — 저쪽은 다른 확장에 대한 의존이고,
     * 템플릿은 코어 버전 범위만 선언한다.
     *
     * @param  array<string, mixed>  $template  템플릿 메타데이터 (`template.json`)
     * @return void 반환값 없음
     *
     * @throws \Exception 코어 버전이 선언 범위를 벗어날 때
     */
    protected function checkDependencies(array $template): void
    {
        // 그누보드7 코어 버전 호환성 검증 (template.json의 g7_version 필드 활용)
        $g7Version = $template['g7_version'] ?? null;
        CoreVersionChecker::validateExtension(
            $g7Version,
            $template['identifier'],
            'template'
        );

        $dependencies = $template['dependencies'] ?? [];
        $unmetDependencies = [];

        // 모듈 의존성 확인
        if (isset($dependencies['modules']) && ! empty($dependencies['modules'])) {
            foreach ($dependencies['modules'] as $moduleName => $versionConstraint) {
                // identifier 컬럼으로 활성화된 모듈 조회
                $module = $this->moduleRepository->findActiveByIdentifier($moduleName);
                if (! $module) {
                    $unmetDependencies[] = __('templates.errors.dependency_not_met', [
                        'dependency' => $moduleName,
                        'type' => 'module',
                    ]);

                    continue;
                }

                // 버전 체크
                if (! $this->checkVersionConstraint($module->version, $versionConstraint)) {
                    $unmetDependencies[] = __('templates.errors.version_mismatch', [
                        'dependency' => $moduleName,
                        'required' => $versionConstraint,
                        'installed' => $module->version,
                    ]);
                }
            }
        }

        // 플러그인 의존성 확인
        if (isset($dependencies['plugins']) && ! empty($dependencies['plugins'])) {
            foreach ($dependencies['plugins'] as $pluginName => $versionConstraint) {
                // identifier 컬럼으로 활성화된 플러그인 조회
                $plugin = $this->pluginRepository->findActiveByIdentifier($pluginName);
                if (! $plugin) {
                    $unmetDependencies[] = __('templates.errors.dependency_not_met', [
                        'dependency' => $pluginName,
                        'type' => 'plugin',
                    ]);

                    continue;
                }

                // 버전 체크
                if (! $this->checkVersionConstraint($plugin->version, $versionConstraint)) {
                    $unmetDependencies[] = __('templates.errors.version_mismatch', [
                        'dependency' => $pluginName,
                        'required' => $versionConstraint,
                        'installed' => $plugin->version,
                    ]);
                }
            }
        }

        if (! empty($unmetDependencies)) {
            throw new \Exception(implode("\n", $unmetDependencies));
        }
    }

    /**
     * 버전 제약조건을 검증합니다.
     *
     * @param  string  $installedVersion  설치된 버전
     * @param  string  $versionConstraint  요구 버전 제약조건 (예: '>=1.0.0', '^2.0', '~1.2.3')
     * @return bool 제약조건 충족 여부
     */
    protected function checkVersionConstraint(string $installedVersion, string $versionConstraint): bool
    {
        try {
            return Semver::satisfies($installedVersion, $versionConstraint);
        } catch (\Exception $e) {
            Log::warning('Version constraint check failed', [
                'installed_version' => $installedVersion,
                'constraint' => $versionConstraint,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 템플릿 디렉토리를 스캔하고 유효한 템플릿을 찾습니다.
     *
     * @return array 스캔된 템플릿 정보 배열
     */
    public function scanTemplates(): array
    {
        if (! File::exists($this->templatesPath)) {
            return [];
        }

        $scannedTemplates = [];
        $directories = File::directories($this->templatesPath);

        foreach ($directories as $directory) {
            $templateName = basename($directory);

            // _bundled, _pending 등 내부 디렉토리 건너뛰기
            if (str_starts_with($templateName, '_')) {
                continue;
            }

            // vendor-name 형식 검증
            if (! preg_match('/^[a-z0-9]+-[a-z0-9_]+$/i', $templateName)) {
                Log::warning("Invalid template directory name: {$templateName}. Expected format: vendor-name");

                continue;
            }

            $templateFile = $directory.'/template.json';
            if (! File::exists($templateFile)) {
                Log::warning("template.json not found in: {$directory}");

                continue;
            }

            $scannedTemplates[$templateName] = [
                'path' => $directory,
                'identifier' => $templateName,
            ];
        }

        return $scannedTemplates;
    }

    /**
     * 템플릿의 의존성을 검증합니다.
     *
     * @param  string  $identifier  검증할 템플릿 식별자
     * @return bool 의존성 충족 여부
     *
     * @throws \Exception 의존성이 충족되지 않을 때
     */
    public function validateTemplate(string $identifier): bool
    {
        $template = $this->getTemplate($identifier);

        if (! $template) {
            throw new \Exception(__('templates.errors.not_found', ['template' => $identifier]));
        }

        // 의존성 검증 수행
        $this->checkDependencies($template);

        return true;
    }

    /**
     * 타입별 템플릿 목록을 반환합니다.
     *
     * @param  string  $type  템플릿 타입 (admin 또는 user)
     * @return array 해당 타입의 템플릿 배열
     */
    public function getTemplatesByType(string $type): array
    {
        $templatesByType = [];

        foreach ($this->templates as $identifier => $template) {
            // template.json에서 타입 정보 확인 (템플릿 인스턴스를 통해)
            $templateRecord = $this->templateRepository->findByIdentifier($identifier);

            if ($templateRecord && $templateRecord->type === $type) {
                $templatesByType[$identifier] = $template;
            }
        }

        return $templatesByType;
    }

    /**
     * 마지막 설치·갱신이 빈 자리로 등록한 조각 참조 경고를 꺼내고 비웁니다 (#712).
     *
     * 템플릿 설치는 레이아웃 검증을 여러 번 돈다(사전 검증·등록·오버라이드 디렉토리마다). 검증 한 번이 끝날 때마다
     * {@see rememberLayoutWarnings()} 가 경고를 이 보관소에 모으고, 설치를 부른 서비스가 결과를 만들 때 꺼낸다.
     *
     * @return array<int, array{file: string, partial: string, reason: string, message: string}> 경고 목록
     */
    public function takeLastLayoutWarnings(): array
    {
        $warnings = $this->lastLayoutWarnings;
        $this->lastLayoutWarnings = [];

        return $warnings;
    }

    /**
     * 방금 끝난 레이아웃 검증의 경고를 보관소에 합칩니다 (같은 경고는 한 번만).
     *
     * @return void 반환값 없음
     */
    private function rememberLayoutWarnings(): void
    {
        foreach ($this->takeLayoutIncludeWarnings() as $warning) {
            if (! in_array($warning, $this->lastLayoutWarnings, true)) {
                $this->lastLayoutWarnings[] = $warning;
            }
        }
    }

    /**
     * 템플릿 설치 전 모든 레이아웃 JSON 파일을 검증합니다.
     *
     * 설치 전에 모든 레이아웃 파일의 유효성을 검사하여
     * 하나라도 오류가 있으면 설치를 중단합니다.
     *
     * @param  string  $templateName  템플릿명 (identifier)
     * @return array 검증된 레이아웃 데이터 배열
     *
     * @throws \Exception 레이아웃 검증 실패 시
     */
    protected function validateLayouts(string $templateName): array
    {
        $layoutsPath = base_path("templates/{$templateName}/layouts");

        // 공통 Trait 메서드 사용 (recursive=true: 하위 디렉토리 포함 전체 스캔)
        return $this->validateLayoutFiles($layoutsPath, $templateName, 'template', true);
    }

    /**
     * 템플릿 레이아웃 JSON 파일을 읽어 DB에 일괄 등록합니다.
     *
     * validateLayouts()에서 이미 검증된 레이아웃 데이터를 사용하여
     * DB에 등록합니다.
     *
     * @param  string  $templateName  템플릿명 (identifier)
     * @param  int  $templateId  템플릿 DB ID
     * @return void 반환값 없음
     */
    protected function registerLayouts(string $templateName, int $templateId): void
    {
        $layoutsPath = base_path("templates/{$templateName}/layouts");

        if (! File::exists($layoutsPath)) {
            Log::info(__('templates.info.no_layouts_directory'), ['template' => $templateName]);

            return;
        }

        // 검증된 레이아웃 데이터 조회 (validateLayouts에서 이미 수행됨)
        // 여기서 다시 검증하여 데이터를 가져옴
        try {
            $validatedLayouts = $this->validateLayoutFiles($layoutsPath, $templateName, 'template', true);
            $this->rememberLayoutWarnings();
        } catch (\Exception $e) {
            Log::error(__('templates.errors.layout_registration_failed'), [
                'template' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (empty($validatedLayouts)) {
            Log::info(__('templates.info.no_layout_files'), ['template' => $templateName]);

            return;
        }

        $synchronizer = $this->layoutSynchronizer();

        foreach ($validatedLayouts as $validatedLayout) {
            try {
                $layoutFile = $validatedLayout['file'];
                $layoutName = $validatedLayout['layout_name'];

                // 오버라이드는 전용 등록(registerLayoutOverrides)이 맡는다.
                if ($this->isOverrideLayoutFile($layoutFile)) {
                    continue;
                }

                // DB에 레이아웃 등록 — 원본 본문(병합 기준)까지 남긴다. 재설치로 행이 남아 있으면 운영자 수정은 보존한다.
                $synchronizer->syncLayout(
                    [
                        'template_id' => $templateId,
                        'template' => $templateName,
                        'name' => $layoutName,
                        'source_type' => LayoutSourceType::Template,
                        'source_identifier' => null,
                    ],
                    $validatedLayout['data'],
                    $validatedLayout['original'],
                    LayoutSyncMode::Keep
                );

                Log::info(__('templates.info.layout_registered'), [
                    'layout' => $layoutName,
                    'template' => $templateName,
                    'file' => basename($layoutFile),
                ]);

            } catch (\Exception $e) {
                Log::error(__('templates.errors.layout_registration_failed'), [
                    'file' => $validatedLayout['file'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 특정 템플릿의 모든 레이아웃을 삭제합니다.
     *
     * @param  int  $templateId  템플릿 DB ID
     * @return void 반환값 없음
     */
    protected function unregisterLayouts(int $templateId): void
    {
        $deletedCount = $this->layoutRepository->deleteByTemplateId($templateId);

        if ($deletedCount > 0) {
            Log::info(__('templates.info.layouts_deleted'), [
                'count' => $deletedCount,
                'template_id' => $templateId,
            ]);
        }
    }

    /**
     * 템플릿의 모듈 레이아웃 오버라이드를 등록합니다.
     *
     * overrides/ 디렉토리에서 모듈별 오버라이드 레이아웃을 스캔하여
     * DB에 등록합니다. source_type='template'으로 저장되어
     * 레이아웃 해석 시 모듈 기본 레이아웃보다 높은 우선순위를 가집니다.
     * 파일에 없는 기존 오버라이드 레코드(고아)는 자동으로 정리됩니다.
     *
     * 원본 해시·원본 본문을 남겨 운영자 수정 여부를 판정할 수 있게 하고, keep 이면 병합한다(W6 — 종전엔 해시 없이
     * 덮어써 keep 이 무효였고 「수정한 레이아웃」 목록에도 뜨지 않았다).
     *
     * @param  string  $templateName  템플릿명 (identifier)
     * @param  int  $templateId  템플릿 DB ID
     * @param  LayoutSyncMode  $mode  동기화 모드 (설치·활성화는 Keep, 갱신은 그 갱신의 모드)
     * @param  array<string, array<string, mixed>>  $backupOriginals  업데이트 백업을 컴파일한 이전 판 원본 (이름 => 원본)
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return void 반환값 없음
     */
    protected function registerLayoutOverrides(string $templateName, int $templateId, LayoutSyncMode $mode = LayoutSyncMode::Keep, array $backupOriginals = [], ?LayoutSyncReport $report = null): void
    {
        $overridesPath = base_path("templates/{$templateName}/layouts/overrides");

        if (! File::exists($overridesPath)) {
            Log::info(__('templates.info.no_overrides_directory'), ['template' => $templateName]);

            // overrides 디렉토리 없음 → 기존 override 레이아웃 모두 정리 (keep 이면 운영자 수정본은 남긴다)
            $this->cleanupOrphanOverrideLayouts($templateId, $templateName, [], $mode, $report);

            return;
        }

        // overrides 디렉토리 내 모듈별 하위 디렉토리 스캔
        $moduleDirectories = File::directories($overridesPath);

        if (empty($moduleDirectories)) {
            Log::info(__('templates.info.no_override_modules'), ['template' => $templateName]);

            // 모듈 디렉토리 없음 → 기존 override 레이아웃 모두 정리 (keep 이면 운영자 수정본은 남긴다)
            $this->cleanupOrphanOverrideLayouts($templateId, $templateName, [], $mode, $report);

            return;
        }

        $registeredCount = 0;
        $registeredLayoutNames = [];

        foreach ($moduleDirectories as $moduleDirectory) {
            $moduleIdentifier = basename($moduleDirectory);

            // 공통 Trait 메서드 사용하여 오버라이드 레이아웃 검증
            try {
                $validatedLayouts = $this->validateLayoutFiles($moduleDirectory, $moduleIdentifier, 'template', true);
                $this->rememberLayoutWarnings();
            } catch (\Exception $e) {
                Log::error(__('templates.errors.override_layout_registration_failed'), [
                    'template' => $templateName,
                    'module' => $moduleIdentifier,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (empty($validatedLayouts)) {
                Log::info(__('templates.info.no_override_layouts_for_module'), [
                    'template' => $templateName,
                    'module' => $moduleIdentifier,
                ]);

                continue;
            }

            foreach ($validatedLayouts as $validatedLayout) {
                try {
                    $layoutFile = $validatedLayout['file'];
                    $layoutData = $validatedLayout['data'];
                    $layoutName = $validatedLayout['layout_name'];

                    // content 필드 구성 — 원본도 같은 규칙으로 잘라야 병합 기준과 본문의 키 집합이 맞는다.
                    $content = $this->extractLayoutContent($layoutData);
                    $original = $this->extractLayoutContent($validatedLayout['original']);
                    $backupOriginal = isset($backupOriginals[$layoutName]) ? $this->extractLayoutContent($backupOriginals[$layoutName]) : null;

                    // DB에 오버라이드 레이아웃 등록 (동기화기 경유 — 원본 해시·원본 본문 기록, keep 이면 병합).
                    // extends 컬럼은 본문이 아니라 파일 선언에서 온다(오버라이드 본문에는 extends 키가 없다).
                    $entry = $this->layoutSynchronizer()->syncLayout(
                        [
                            'template_id' => $templateId,
                            'template' => $templateName,
                            'name' => $layoutName,
                            'source_type' => LayoutSourceType::Template,
                            'source_identifier' => $templateName,
                            'extends' => $layoutData['extends'] ?? null,
                        ],
                        $content,
                        $original,
                        $mode,
                        $backupOriginal
                    );
                    $report?->add($entry);

                    $registeredCount++;
                    $registeredLayoutNames[] = $layoutName;

                    Log::info(__('templates.info.override_layout_registered'), [
                        'layout' => $layoutName,
                        'template' => $templateName,
                        'module' => $moduleIdentifier,
                        'file' => basename($layoutFile),
                    ]);

                } catch (\Exception $e) {
                    Log::error(__('templates.errors.override_layout_registration_failed'), [
                        'file' => $validatedLayout['file'],
                        'template' => $templateName,
                        'module' => $moduleIdentifier,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 파일에 없는 고아 override 레이아웃 정리 (keep 이면 운영자 수정본은 남긴다)
        $this->cleanupOrphanOverrideLayouts($templateId, $templateName, $registeredLayoutNames, $mode, $report);

        if ($registeredCount > 0) {
            // 오버라이드 레이아웃 캐시 무효화
            $this->invalidateOverrideLayoutCaches($templateId);

            Log::info(__('templates.info.override_layouts_registered'), [
                'template' => $templateName,
                'count' => $registeredCount,
            ]);
        }
    }

    /**
     * 파일에 없는 고아 오버라이드 레이아웃을 정리합니다.
     *
     * DB에 등록된 오버라이드 레이아웃 중 현재 파일에서 등록되지 않은
     * 레이아웃을 삭제합니다. 이는 레이아웃 이름 변경이나 오버라이드 파일
     * 삭제 시 발생하는 고아 레코드를 방지합니다.
     *
     * 편집기 「새 파일」 로 만든 레이아웃은 오버라이드와 같은 source_type·source_identifier 를 쓴다. 파일에서
     * 등록된 행만 원본 정보(원본 해시·원본 본문)를 가지므로, 둘 다 없는 행은 운영자가 만든 것으로 보고
     * 지우지 않는다(LayoutRowSynchronizer::hasFileOrigin) — 종전에는 그 행을 판본 이력째 영구 삭제했다 (#703).
     * 7.0.x 에서 등록된 오버라이드 행은 7.1.0 업그레이드 스텝이 원본 정보를 채운다.
     *
     * @param  int  $templateId  템플릿 DB ID
     * @param  string  $templateName  템플릿명 (identifier)
     * @param  array  $registeredLayoutNames  현재 파일에서 등록된 레이아웃 이름 목록
     * @param  LayoutSyncMode  $mode  동기화 모드 — keep 이면 운영자가 고친 오버라이드는 남긴다(D4)
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return void 반환값 없음
     */
    protected function cleanupOrphanOverrideLayouts(int $templateId, string $templateName, array $registeredLayoutNames, LayoutSyncMode $mode = LayoutSyncMode::Overwrite, ?LayoutSyncReport $report = null): void
    {
        try {
            $existingOverrides = $this->layoutRepository->getOverridesByTemplateId($templateId);

            if ($existingOverrides->isEmpty()) {
                return;
            }

            $deletedCount = 0;

            foreach ($existingOverrides as $override) {
                // source_identifier가 현재 템플릿과 일치하는 오버라이드만 정리 대상
                if ($override->source_identifier !== $templateName) {
                    continue;
                }

                if (! in_array($override->name, $registeredLayoutNames)) {
                    // 파일에서 온 적이 없는 행(원본 본문·원본 해시가 둘 다 없다)은 오버라이드가 아니라 운영자가 편집기
                    // 「새 레이아웃」 으로 만든 화면이다 — `source_identifier` 가 템플릿 식별자라 모양만 같을 뿐이므로
                    // 고아 정리 대상이 아니다(지우면 템플릿을 켜기만 해도 운영자 화면이 영구 삭제된다).
                    if (! $this->layoutSynchronizer()->hasFileOrigin($override)) {
                        continue;
                    }

                    // 새 버전에서 빠졌지만 운영자 수정본이면 남긴다 (D4 — 템플릿 자기 레이아웃과 같은 규칙)
                    if ($mode === LayoutSyncMode::Keep && $this->layoutSynchronizer()->isOperatorModified($override)) {
                        $report?->add(new LayoutSyncEntry('layout', $templateName, $override->name, 'kept_removed'));

                        continue;
                    }

                    // 캐시 삭제 (레코드 삭제 전에 수행) — 키 조립은 트레이트 단일 지점에 위임
                    // (.with_source_meta 변종 포함, 수작업 키 목록의 규약 드리프트 방지)
                    $this->forgetLayoutCacheKeys($override, $templateName);

                    $this->layoutRepository->forceDelete($override);
                    $deletedCount++;

                    Log::info('고아 오버라이드 레이아웃 삭제', [
                        'layout' => $override->name,
                        'template' => $templateName,
                        'template_id' => $templateId,
                    ]);
                }
            }

            if ($deletedCount > 0) {
                Log::info('고아 오버라이드 레이아웃 정리 완료', [
                    'template' => $templateName,
                    'deleted_count' => $deletedCount,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('고아 오버라이드 레이아웃 정리 중 오류', [
                'template' => $templateName,
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 오버라이드 레이아웃 관련 캐시를 무효화합니다.
     *
     * 템플릿의 오버라이드 레이아웃이 등록/변경되면 해당 레이아웃의
     * 캐시를 무효화하여 다음 요청 시 새로운 데이터가 로드되도록 합니다.
     *
     * @param  int  $templateId  템플릿 DB ID
     * @return void 반환값 없음
     */
    protected function invalidateOverrideLayoutCaches(int $templateId): void
    {
        try {
            // 템플릿의 오버라이드 레이아웃 조회
            $overrideLayouts = $this->layoutRepository->getOverridesByTemplateId($templateId);

            if ($overrideLayouts->isEmpty()) {
                return;
            }

            foreach ($overrideLayouts as $layout) {
                // 키 조립은 트레이트 단일 지점에 위임 (.with_source_meta 변종 포함).
                // 이 경로는 identifier 를 보유하지 않으므로 '' 전달 — 버전 포함 공개
                // 서빙 키는 종전과 동일하게 버전 bump 로 무효화된다.
                $this->forgetLayoutCacheKeys($layout, '');
            }

            Log::info(__('templates.info.override_layouts_cache_invalidated'), [
                'template_id' => $templateId,
                'invalidated_count' => $overrideLayouts->count(),
            ]);
        } catch (\Exception $e) {
            Log::warning(__('templates.info.override_layouts_cache_invalidation_error'), [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 레이아웃 데이터에서 content 필드를 추출합니다.
     *
     * @param  array  $layoutData  레이아웃 JSON 데이터
     * @return array content 배열
     */
    protected function extractLayoutContent(array $layoutData): array
    {
        $content = [];

        if (isset($layoutData['slots'])) {
            $content['slots'] = $layoutData['slots'];
        }

        if (isset($layoutData['meta'])) {
            $content['meta'] = $layoutData['meta'];
        }

        if (isset($layoutData['data_sources'])) {
            $content['data_sources'] = $layoutData['data_sources'];
        }

        if (isset($layoutData['version'])) {
            $content['version'] = $layoutData['version'];
        }

        return $content;
    }

    /**
     * 템플릿 관련 모든 캐시를 삭제합니다.
     *
     * 템플릿 라이프사이클(update/deactivate/uninstall/cache-clear)이 공유하는
     * 무효화 단일 지점이다. 버전 접미사 없는 고정 키(`template.config.{identifier}`,
     * `template.{id|identifier}.components_manifest`)는 캐시 버전 bump 로 무효화되지
     * 않으므로 여기서 능동 forget 한다 — 누락 시 공개 config.json 이 TTL(1시간) 동안
     * 이전 manifest 로 응답된다 (#588, 공개 #119).
     *
     * template:cache-clear 커맨드가 호출할 수 있도록 public 이다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return int 능동 삭제를 시도한 캐시 키 수
     */
    public function clearTemplateCache(string $templateIdentifier): int
    {
        $clearedCount = 0;

        // identifier 만으로 가능한 고정 키 forget — uninstall 등 DB 레코드가 이미
        // 없는 상황에서도 반드시 수행되어야 하므로 레코드 조회보다 먼저 둔다.
        $this->cache()->forget("template.config.{$templateIdentifier}");
        $clearedCount++;

        // components_manifest identifier 변종 (ComponentExists 는 int|string 수용)
        $this->cache()->forget("template.{$templateIdentifier}.components_manifest");
        $clearedCount++;

        // 활성 템플릿 + 레이아웃 무변경 업데이트 경로는 캐시 버전 bump 가 없어
        // 현재 버전 routes/language 키가 stale 로 남는다 — warmTemplateCache() 와
        // 대칭으로 현재 버전 키를 능동 삭제한다 (이전 버전 키는 TTL 자연 만료).
        $cacheVersion = self::getExtensionCacheVersion();
        $this->cache()->forget("template.routes.{$templateIdentifier}.v{$cacheVersion}");
        $clearedCount++;
        foreach (config('app.supported_locales', ['ko', 'en']) as $locale) {
            $this->cache()->forget("template.language.{$templateIdentifier}.{$locale}.v{$cacheVersion}");
            $clearedCount++;
        }

        // DB 기반 조회 — 파일 시스템 상태와 무관하게 캐시 삭제 보장
        // (파일 교체 중이거나 reloadTemplate() 전에도 캐시를 확실히 삭제)
        $templateRecord = $this->templateRepository->findByIdentifier($templateIdentifier);
        if (! $templateRecord) {
            return $clearedCount;
        }

        // components_manifest 숫자 id 변종 (StoreLayoutRequest 가 integer 검증 — 실운영 키)
        $this->cache()->forget("template.{$templateRecord->id}.components_manifest");
        $clearedCount++;

        // 레이아웃 캐시 삭제 (버전 없는 내부 캐시)
        $clearedCount += $this->clearLayoutCaches($templateIdentifier);

        Log::info(__('templates.info.cache_cleared'), [
            'template' => $templateIdentifier,
        ]);

        return $clearedCount;
    }

    /**
     * 템플릿의 모든 레이아웃 캐시를 삭제합니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return int 캐시를 무효화한 레이아웃 수
     */
    protected function clearLayoutCaches(string $templateIdentifier): int
    {
        // 템플릿의 모든 레이아웃 조회
        $template = $this->templateRepository->findByIdentifier($templateIdentifier);
        if (! $template) {
            return 0;
        }

        $this->invalidateTemplateLayoutCache($template->id, $templateIdentifier);

        return $this->layoutRepository->getByTemplateId($template->id)->count();
    }

    /**
     * 템플릿의 주요 레이아웃을 미리 캐싱합니다 (캐시 워밍).
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return void 반환값 없음
     */
    protected function warmTemplateCache(string $templateIdentifier): void
    {
        $template = $this->templateRepository->findByIdentifier($templateIdentifier);
        if (! $template || $template->status !== ExtensionStatus::Active->value) {
            return;
        }

        // 템플릿에 등록된 모든 레이아웃 자동 식별
        $layouts = $this->layoutRepository->getLayoutNamesByTemplateId($template->id)->toArray();

        if (empty($layouts)) {
            Log::debug('캐시 워밍할 레이아웃 없음', [
                'template' => $templateIdentifier,
            ]);

            return;
        }

        // 현재 캐시 버전 — 프론트엔드 ?v= 파라미터와 일치하는 키로 워밍해야 서빙 시 히트됨
        $cacheVersion = self::getExtensionCacheVersion();
        $layoutService = app(LayoutService::class);
        $cacheTtl = config('template.layout.cache_ttl', 3600);

        foreach ($layouts as $layoutName) {
            try {
                // PublicLayoutController::serve()와 동일한 캐시 키 패턴 사용
                $cacheKey = "layout.{$templateIdentifier}.{$layoutName}.v{$cacheVersion}";

                $this->cache()->remember($cacheKey, function () use ($templateIdentifier, $layoutName, $layoutService) {
                    return $layoutService->getLayout($templateIdentifier, $layoutName);
                }, $cacheTtl);

                Log::debug('레이아웃 캐시 워밍 완료', [
                    'template' => $templateIdentifier,
                    'layout' => $layoutName,
                ]);
            } catch (\Exception $e) {
                // 캐시 워밍 실패는 무시 (레이아웃이 없거나 순환 참조 등)
                Log::debug('레이아웃 캐시 워밍 실패', [
                    'template' => $templateIdentifier,
                    'layout' => $layoutName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Routes 캐시 워밍
        // PublicTemplateController::getRoutes()와 동일한 캐시 키 패턴 사용
        try {
            $templateService = app(TemplateService::class);
            $result = $templateService->getRoutesDataWithModules($templateIdentifier);

            if ($result['success']) {
                $cacheKey = "template.routes.{$templateIdentifier}.v{$cacheVersion}";
                $this->cache()->put($cacheKey, ['success' => true, 'data' => $result['data']], $cacheTtl);
            }
        } catch (\Exception $e) {
            Log::debug('Routes 캐시 워밍 실패', [
                'template' => $templateIdentifier,
                'error' => $e->getMessage(),
            ]);
        }

        // 다국어 파일 캐시 워밍 ($partial 해석 + 모듈/플러그인 다국어 병합)
        // serveLanguage()와 동일한 결과가 캐시에 저장되어야 함
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);
        foreach ($supportedLocales as $locale) {
            try {
                $langFilePath = base_path("templates/{$templateIdentifier}/lang/{$locale}.json");
                if (file_exists($langFilePath)) {
                    $cacheKey = "template.language.{$templateIdentifier}.{$locale}.v{$cacheVersion}";
                    $this->cache()->remember($cacheKey, function () use ($templateIdentifier, $locale, $templateService) {
                        // TemplateService를 통해 $partial 해석 + 모듈/플러그인 다국어 병합
                        $result = $templateService->getLanguageDataWithModules($templateIdentifier, $locale);

                        if (! $result['success']) {
                            return ['error' => $result['error']];
                        }

                        return ['success' => true, 'data' => $result['data']];
                    }, $cacheTtl);
                }
            } catch (\Exception $e) {
                Log::debug('다국어 파일 캐시 워밍 실패', [
                    'template' => $templateIdentifier,
                    'locale' => $locale,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info(__('templates.info.cache_warmed'), [
            'template' => $templateIdentifier,
        ]);
    }

    /**
     * 템플릿의 컴포넌트 목록을 조회합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{basic: array, composite: array, layout: array} 컴포넌트 목록
     */
    public function getTemplateComponents(string $identifier): array
    {
        $componentsPath = base_path("templates/{$identifier}/components.json");

        if (! File::exists($componentsPath)) {
            return ['basic' => [], 'composite' => [], 'layout' => []];
        }

        $content = File::get($componentsPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['basic' => [], 'composite' => [], 'layout' => []];
        }

        $components = $data['components'] ?? [];

        // 각 타입별로 컴포넌트 이름 추출
        $basic = [];
        $composite = [];
        $layout = [];

        // basic 컴포넌트
        foreach ($components['basic'] ?? [] as $component) {
            if (isset($component['name']) && ! empty($component['name'])) {
                $basic[] = $component['name'];
            }
        }

        // composite 컴포넌트
        foreach ($components['composite'] ?? [] as $component) {
            if (isset($component['name']) && ! empty($component['name'])) {
                $composite[] = $component['name'];
            }
        }

        // layout 컴포넌트
        foreach ($components['layout'] ?? [] as $component) {
            if (isset($component['name']) && ! empty($component['name'])) {
                $layout[] = $component['name'];
            }
        }

        return [
            'basic' => $basic,
            'composite' => $composite,
            'layout' => $layout,
        ];
    }

    /**
     * 템플릿의 레이아웃을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * 모든 행 쓰기는 {@see LayoutRowSynchronizer} 를 지난다. keep(`$preserveModified`)이면 운영자가 고친 화면은
     * 조각 단위로 병합하고(고친 조각은 운영자 판, 손대지 않은 조각은 새 판), 운영자가 지운·만든·이름 바꾼 화면은
     * 되살리거나 덮거나 지우지 않는다. overwrite 면 새 판으로 덮는다(수동 「레이아웃 갱신」).
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  bool  $preserveModified  운영자가 고친 화면을 보존(병합)할지 여부 (keep)
     * @param  string|null  $backupPath  업데이트 백업 디렉토리 — 원본 본문이 없는 행의 비교 기준을 만든다
     * @return array{success: bool, layouts_refreshed: int, created: int, updated: int, deleted: int, unchanged: int, skipped: int, extensions_refreshed: int, layout_sync: array<string, mixed>, layout_warnings: array<int, array<string, string>>} 갱신 결과 (빈 자리로 등록한 조각 참조 경고 포함)
     *
     * @throws \Exception 템플릿을 찾을 수 없거나 레이아웃 갱신 실패 시
     */
    public function refreshTemplateLayouts(string $identifier, bool $preserveModified = false, ?string $backupPath = null): array
    {
        // 템플릿 DB 조회
        $template = $this->templateRepository->findByIdentifier($identifier);

        if (! $template) {
            throw new \Exception(__('templates.errors.template_not_found', ['identifier' => $identifier]));
        }

        // 템플릿이 활성화 상태인지 확인
        if ($template->status !== 'active') {
            throw new \Exception(__('templates.errors.template_not_active', ['identifier' => $identifier]));
        }

        $mode = LayoutSyncMode::fromPreserve($preserveModified);
        $report = new LayoutSyncReport($mode);
        $layoutsPath = base_path("templates/{$identifier}/layouts");
        $this->lastLayoutWarnings = [];

        if (! File::exists($layoutsPath)) {
            Log::info(__('templates.info.no_layouts_directory'), ['template' => $identifier]);

            return ['success' => true, 'layouts_refreshed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => 0, 'extensions_refreshed' => 0, 'layout_sync' => $report->toArray(), 'layout_warnings' => []];
        }

        // 레이아웃 검증 — 7.0 JSON 의 깨진 조각 참조는 빈 자리로 등록하고 경고로 모은다 (#712)
        try {
            $validatedLayouts = $this->validateLayoutFiles($layoutsPath, $identifier, 'template', true);
            $this->rememberLayoutWarnings();
        } catch (\Exception $e) {
            Log::error(__('templates.errors.layout_registration_failed'), [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // 오버라이드는 전용 등록이 맡는다 — 자기 레이아웃 동기화에서 뺀다.
        $validatedLayouts = array_values(array_filter($validatedLayouts, fn ($layout) => ! $this->isOverrideLayoutFile($layout['file'])));
        $fileLayoutNames = array_column($validatedLayouts, 'layout_name');

        $synchronizer = $this->layoutSynchronizer();
        $backupOriginals = $synchronizer->compileBackupOriginals($backupPath, 'templates', $identifier);
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => 0];

        // 파일 기반 레이아웃 동기화
        foreach ($validatedLayouts as $validatedLayout) {
            $layoutName = $validatedLayout['layout_name'];

            try {
                $entry = $synchronizer->syncLayout(
                    [
                        'template_id' => $template->id,
                        'template' => $identifier,
                        'name' => $layoutName,
                        'source_type' => LayoutSourceType::Template,
                        'source_identifier' => null,
                    ],
                    $validatedLayout['data'],
                    $validatedLayout['original'],
                    $mode,
                    $backupOriginals[$layoutName] ?? null
                );
                $this->tallyLayoutSync($stats, $entry);
                $report->add($entry);
            } catch (\Exception $e) {
                Log::error(__('templates.errors.layout_registration_failed'), [
                    'file' => $validatedLayout['file'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // DB에만 있고 파일에 없는 레이아웃 — 이전 판 파일 목록에 있던 이름만 삭제 후보(운영자가 만든·이름 바꾼 화면은 남긴다).
        $previousNames = $backupOriginals !== []
            ? array_values(array_filter(array_keys($backupOriginals), fn ($name) => ! str_contains((string) $name, '.')))
            : null;
        $existingLayouts = $this->layoutRepository->getByTemplateIdWithFilter($template->id, 'template', null);

        foreach ($existingLayouts as $layout) {
            if (in_array($layout->name, $fileLayoutNames, true)) {
                continue;
            }

            $entry = $synchronizer->removeMissingLayout($layout, $identifier, $mode, $synchronizer->isRemovalCandidate($layout, $previousNames));
            $this->tallyLayoutSync($stats, $entry);
            $report->add($entry);
        }

        // 오버라이드 레이아웃 재등록
        $this->registerLayoutOverrides($identifier, $template->id, $mode, $backupOriginals, $report);

        // Extension 오버라이드 재등록 (레이아웃 확장)
        $extensionStats = $this->registerExtensionOverrides($identifier, $template->id, $mode, $report);

        // 캐시 무효화
        $this->clearTemplateCache($identifier);

        $totalRefreshed = $stats['created'] + $stats['updated'];

        // 레이아웃 또는 Extension 오버라이드가 변경된 경우에만 캐시 버전 증가
        $extensionChanged = ($extensionStats['created'] ?? 0) > 0 || ($extensionStats['updated'] ?? 0) > 0 || ($extensionStats['deleted'] ?? 0) > 0;
        if ($totalRefreshed > 0 || $stats['deleted'] > 0 || $extensionChanged) {
            $this->incrementExtensionCacheVersion();
        }

        Log::info('템플릿 레이아웃 동기화 완료', [
            'template' => $identifier,
            'created' => $stats['created'],
            'updated' => $stats['updated'],
            'deleted' => $stats['deleted'],
            'unchanged' => $stats['unchanged'],
            'skipped' => $stats['skipped'],
            'extensions_refreshed' => $extensionStats['registered'] ?? 0,
        ]);

        return [
            'success' => true,
            'layouts_refreshed' => $totalRefreshed,
            'created' => $stats['created'],
            'updated' => $stats['updated'],
            'deleted' => $stats['deleted'],
            'unchanged' => $stats['unchanged'],
            'skipped' => $stats['skipped'],
            'extensions_refreshed' => $extensionStats['registered'] ?? 0,
            'layout_sync' => $report->toArray(),
            'layout_warnings' => $this->takeLastLayoutWarnings(),
        ];
    }

    /**
     * 템플릿 Extension 오버라이드 등록
     *
     * 템플릿 설치 시 extensions/ 폴더의 오버라이드 파일을 등록합니다.
     * 모듈/플러그인의 Extension을 템플릿이 커스터마이징할 수 있습니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @param  int  $templateId  템플릿 DB ID
     * @param  LayoutSyncMode  $mode  동기화 모드 (설치는 Keep, 갱신은 그 갱신의 모드)
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return array{registered: int, created: int, updated: int, deleted: int} 등록 통계 (deleted = 정리한 옛 행)
     */
    protected function registerExtensionOverrides(string $templateIdentifier, int $templateId, LayoutSyncMode $mode = LayoutSyncMode::Keep, ?LayoutSyncReport $report = null): array
    {
        $stats = ['registered' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0];
        $extensionsPath = base_path("templates/{$templateIdentifier}/extensions");

        // 대상별로 소스에 실재하는 조각 키 — 등록 뒤 이 집합에 없는 옛 행을 정리한다(removeOrphanTemplateOverrides).
        $liveKeysByTarget = [];

        if (! is_dir($extensionsPath)) {
            Log::debug('Extension 오버라이드 디렉토리 없음', ['template' => $templateIdentifier]);
            $stats['deleted'] = $this->layoutExtensionService->removeOrphanTemplateOverrides($templateIdentifier, $templateId, $liveKeysByTarget);

            return $stats;
        }

        // extensions/{module-identifier}/ 폴더 순회
        $moduleDirectories = glob($extensionsPath.'/*', GLOB_ONLYDIR);

        if (empty($moduleDirectories)) {
            Log::debug('Extension 오버라이드 모듈 폴더 없음', ['template' => $templateIdentifier]);
            $stats['deleted'] = $this->layoutExtensionService->removeOrphanTemplateOverrides($templateIdentifier, $templateId, $liveKeysByTarget);

            return $stats;
        }

        foreach ($moduleDirectories as $moduleDir) {
            $moduleIdentifier = basename($moduleDir);
            // 소스 디렉토리에 실재하는 조각 키 — 컴파일 성공 여부와 무관하다(모듈·플러그인 갱신과 같은 기준).
            // 컴파일에 한 번 실패했다고 오버라이드 행을 지우면 모듈 원본 조각이 대신 그려진다.
            $liveKeysByTarget[$moduleIdentifier] = $this->fragmentSourceKeys($moduleDir, []);

            // 소스 선택 + 읽기 — 둘 다 규약 SSoT 가 끝낸다 (CollectsFragmentSources).
            // 읽기를 여기서 다시 구현하면 그것이 사본이 되고, 사본은 트레이트보다 반드시 약해진다
            // (실제로 그랬다 — `is_array` 미검사로 스칼라 JSON 이 통과해 등록에 닿았다).
            $definitions = $this->loadFragmentDefinitions($moduleDir, [], [
                'template' => $templateIdentifier,
                'module' => $moduleIdentifier,
            ], $templateIdentifier);

            foreach ($definitions as $definition) {
                $file = $definition['path'];

                try {
                    // 템플릿 오버라이드로 등록
                    $result = $this->layoutExtensionService->registerTemplateOverride(
                        $definition['data'],
                        $templateIdentifier,
                        $moduleIdentifier,  // override_target = 오버라이드 대상 모듈
                        $templateId,
                        $definition['key'],
                        $mode,
                        $report
                    );

                    $stats['registered']++;
                    if ($result === 'created') {
                        $stats['created']++;
                    } elseif ($result === 'updated') {
                        $stats['updated']++;
                    }

                    // `\Exception` 이 아니라 `\Throwable` 이다 — 등록 한 건이 던지는 `Error`(타입 오류 등)에
                    // 나머지 확장점이 끌려 사라지면 안 된다 (트레이트 읽기 계약과 같은 이유).
                } catch (\Throwable $e) {
                    Log::error('Extension 오버라이드 등록 실패', [
                        'file' => $file,
                        'template' => $templateIdentifier,
                        'module' => $moduleIdentifier,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 소스에 더는 없는 조각의 행 정리 — 백필된 옛 키 행(대상 이름 키)도 여기서 파일 키로 정정된다.
        $stats['deleted'] = $this->layoutExtensionService->removeOrphanTemplateOverrides($templateIdentifier, $templateId, $liveKeysByTarget);

        if ($stats['registered'] > 0) {
            Log::info('템플릿 Extension 오버라이드 등록 완료', [
                'template' => $templateIdentifier,
                'count' => $stats['registered'],
            ]);
        }

        return $stats;
    }

    /**
     * 템플릿 Extension 오버라이드 제거
     *
     * 템플릿 삭제 시 오버라이드를 제거합니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return void 반환값 없음
     */
    protected function unregisterExtensionOverrides(string $templateIdentifier): void
    {
        $deletedCount = $this->layoutExtensionService->unregisterBySource(
            LayoutSourceType::Template,
            $templateIdentifier
        );

        if ($deletedCount > 0) {
            Log::info('템플릿 Extension 오버라이드 제거 완료', [
                'template' => $templateIdentifier,
                'count' => $deletedCount,
            ]);
        }
    }

    /**
     * 템플릿의 의존성 충족 상태를 확인합니다.
     *
     * template.json의 dependencies를 기반으로 모든 모듈/플러그인의
     * 활성화 상태 및 버전 요구사항 충족 여부를 확인합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{met: bool, modules: array, plugins: array} 의존성 상태
     */
    public function checkDependenciesStatus(string $identifier): array
    {
        $template = $this->getTemplate($identifier);

        if (! $template) {
            return [
                'met' => false,
                'modules' => [],
                'plugins' => [],
                'error' => __('templates.errors.not_found', ['template' => $identifier]),
            ];
        }

        $dependencies = $template['dependencies'] ?? [];
        $moduleDependencies = $dependencies['modules'] ?? [];
        $pluginDependencies = $dependencies['plugins'] ?? [];

        $moduleStatuses = [];
        $pluginStatuses = [];
        $allMet = true;

        // 모듈 의존성 확인
        foreach ($moduleDependencies as $moduleName => $versionConstraint) {
            // 먼저 설치된 모듈 조회 (활성화 여부와 무관)
            $module = $this->moduleRepository->findByIdentifier($moduleName);
            $activeModule = $module && $module->status === ExtensionStatus::Active->value;

            $status = [
                'identifier' => $moduleName,
                'name' => $module ? $module->getLocalizedName() : $moduleName,
                'required_version' => $versionConstraint,
                'installed_version' => $module?->version,
                'is_active' => $activeModule,
                'version_met' => false,
                'met' => false,
            ];

            if ($activeModule) {
                $status['version_met'] = $this->checkVersionConstraint($module->version, $versionConstraint);
                $status['met'] = $status['version_met'];
            }

            if (! $status['met']) {
                $allMet = false;
            }

            $moduleStatuses[] = $status;
        }

        // 플러그인 의존성 확인
        foreach ($pluginDependencies as $pluginName => $versionConstraint) {
            // 먼저 설치된 플러그인 조회 (활성화 여부와 무관)
            $plugin = $this->pluginRepository->findByIdentifier($pluginName);
            $activePlugin = $plugin && $plugin->status === ExtensionStatus::Active->value;

            $status = [
                'identifier' => $pluginName,
                'name' => $plugin ? $plugin->getLocalizedName() : $pluginName,
                'required_version' => $versionConstraint,
                'installed_version' => $plugin?->version,
                'is_active' => $activePlugin,
                'version_met' => false,
                'met' => false,
            ];

            if ($activePlugin) {
                $status['version_met'] = $this->checkVersionConstraint($plugin->version, $versionConstraint);
                $status['met'] = $status['version_met'];
            }

            if (! $status['met']) {
                $allMet = false;
            }

            $pluginStatuses[] = $status;
        }

        return [
            'met' => $allMet,
            'modules' => $moduleStatuses,
            'plugins' => $pluginStatuses,
        ];
    }

    /**
     * 템플릿의 미충족 의존성 목록을 반환합니다.
     *
     * checkDependenciesStatus()를 활용하여 충족되지 않은 의존성만 필터링합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{modules: array, plugins: array} 미충족 의존성 목록
     */
    public function getUnmetDependencies(string $identifier): array
    {
        $status = $this->checkDependenciesStatus($identifier);

        // 에러가 있는 경우 빈 배열 반환
        if (isset($status['error'])) {
            return [
                'modules' => [],
                'plugins' => [],
            ];
        }

        // 충족되지 않은 의존성만 필터링
        $unmetModules = array_filter($status['modules'], function ($module) {
            return ! $module['met'];
        });

        $unmetPlugins = array_filter($status['plugins'], function ($plugin) {
            return ! $plugin['met'];
        });

        return [
            'modules' => array_values($unmetModules),
            'plugins' => array_values($unmetPlugins),
        ];
    }

    /**
     * 특정 모듈에 의존하는 활성 템플릿 목록을 반환합니다.
     *
     * 모든 활성화된 템플릿을 조회하여 해당 모듈을 dependencies.modules에
     * 포함하고 있는 템플릿의 identifier 목록을 반환합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return array 의존하는 템플릿 identifier 배열
     */
    public function getTemplatesDependingOnModule(string $moduleIdentifier): array
    {
        $dependentTemplates = $this->templateRepository->findActiveByModuleDependency($moduleIdentifier);

        return $dependentTemplates->pluck('identifier')->toArray();
    }

    /**
     * 특정 플러그인에 의존하는 활성 템플릿 목록을 반환합니다.
     *
     * 모든 활성화된 템플릿을 조회하여 해당 플러그인을 dependencies.plugins에
     * 포함하고 있는 템플릿의 identifier 목록을 반환합니다.
     *
     * @param  string  $pluginIdentifier  플러그인 식별자
     * @return array 의존하는 템플릿 identifier 배열
     */
    public function getTemplatesDependingOnPlugin(string $pluginIdentifier): array
    {
        $dependentTemplates = $this->templateRepository->findActiveByPluginDependency($pluginIdentifier);

        return $dependentTemplates->pluck('identifier')->toArray();
    }

    // ==========================================
    // 업데이트 관련 메서드
    // ==========================================

    /**
     * GitHub에서 최신 버전을 조회합니다.
     *
     * @param  string  $githubUrl  GitHub 저장소 URL
     * @return string|null 최신 버전 또는 null
     */
    protected function fetchLatestVersion(string $githubUrl): ?string
    {
        if (! $githubUrl) {
            return null;
        }

        try {
            [$owner, $repo] = GithubHelper::parseUrl($githubUrl);
        } catch (\RuntimeException $e) {
            return null;
        }

        try {
            $token = (string) (config('app.update.github_token') ?? '');
            $result = GithubHelper::fetchLatestRelease($owner, $repo, $token);

            return $result['version'];
        } catch (\Exception $e) {
            Log::error('최신 버전 확인 중 오류 발생', [
                'github_url' => $githubUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * GitHub URL로부터 변경 내역 URL을 생성합니다.
     *
     * @param  string|null  $githubUrl  GitHub 저장소 URL
     * @return string|null 변경 내역 URL 또는 null
     */
    protected function buildChangelogUrl(?string $githubUrl): ?string
    {
        if (! $githubUrl) {
            return null;
        }

        $githubUrl = rtrim($githubUrl, '/');
        $githubUrl = preg_replace('/\.git$/', '', $githubUrl);

        return $githubUrl.'/releases';
    }

    /**
     * _pending 또는 _bundled에서 활성 디렉토리로 템플릿을 복사합니다.
     *
     * @param  string  $templateName  템플릿 식별자
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @param  bool  $force  이미 활성 디렉터리에 있어도 덮어쓸지 여부
     * @return void 반환값 없음
     *
     * @throws \RuntimeException 소스를 찾을 수 없을 때
     */
    protected function copyToActiveFromSource(string $templateName, ?\Closure $onProgress = null, bool $force = false): void
    {
        $targetPath = $this->templatesPath.DIRECTORY_SEPARATOR.$templateName;

        // _pending에서 찾기
        if (isset($this->pendingTemplates[$templateName]) || ExtensionPendingHelper::isPending($this->templatesPath, $templateName)) {
            $sourcePath = ExtensionPendingHelper::getPendingPath($this->templatesPath, $templateName);
            ExtensionPendingHelper::copyToActive($sourcePath, $targetPath, $onProgress);
            Log::info('템플릿을 _pending에서 활성 디렉토리로 복사', ['template' => $templateName, 'force' => $force]);

            // 메모리 재로드
            $this->reloadTemplate($templateName);

            return;
        }

        // _bundled에서 찾기
        if (isset($this->bundledTemplates[$templateName]) || ExtensionPendingHelper::isBundled($this->templatesPath, $templateName)) {
            $sourcePath = ExtensionPendingHelper::getBundledPath($this->templatesPath, $templateName);
            ExtensionPendingHelper::copyToActive($sourcePath, $targetPath, $onProgress);
            Log::info('템플릿을 _bundled에서 활성 디렉토리로 복사', ['template' => $templateName, 'force' => $force]);

            // 메모리 재로드
            $this->reloadTemplate($templateName);

            return;
        }

        throw new \RuntimeException(__('templates.pending_not_found', ['template' => $templateName]));
    }

    /**
     * 활성 디렉토리의 template.json을 다시 읽어 메모리에 로드합니다.
     *
     * @param  string  $templateName  템플릿 식별자
     * @return void 반환값 없음
     */
    protected function reloadTemplate(string $templateName): void
    {
        $directory = $this->templatesPath.DIRECTORY_SEPARATOR.$templateName;
        $templateFile = $directory.DIRECTORY_SEPARATOR.'template.json';

        if (! File::exists($templateFile)) {
            return;
        }

        try {
            $jsonContent = File::get($templateFile);
            $templateData = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Failed to parse template.json in {$templateName}: ".json_last_error_msg());

                return;
            }

            if (! $this->validateTemplateData($templateData, $templateName)) {
                return;
            }

            if (isset($templateData['name'])) {
                $templateData['name'] = $this->convertToMultilingual($templateData['name']);
            }
            if (isset($templateData['description'])) {
                $templateData['description'] = $this->convertToMultilingual($templateData['description']);
            }

            $templateData['_paths'] = [
                'root' => $directory,
                'components_manifest' => $directory.'/components.json',
                'routes' => $directory.'/routes.json',
                'components_bundle' => $directory.'/dist/components.iife.js',
                'assets' => $directory.'/assets',
                'lang' => $directory.'/lang',
                'layouts' => $directory.'/layouts',
            ];

            $this->templates[$templateName] = $templateData;

            // pending/bundled 목록에서 제거
            unset($this->pendingTemplates[$templateName]);
            unset($this->bundledTemplates[$templateName]);

        } catch (\Exception $e) {
            Log::error("Failed to reload template {$templateName}: ".$e->getMessage());
        }
    }

    /**
     * 단일 템플릿의 업데이트 가능 여부를 확인합니다.
     *
     * 일반 업데이트 우선순위:
     *   1. GitHub URL 존재 + API 조회 성공
     *      a. 소스 미지정이고 번들 판이 GitHub 최신·현재보다 높음 → 'bundled' 소스 반환 (코어와 함께 배포된 번들이 호환 기준 —
     *         확장 공개 릴리스가 코어 출시보다 늦을 때 「업데이트 없음」으로 끝나던 문제)
     *      b. GitHub 버전 > 현재 → 'github' 소스 반환
     *      c. 그 밖 → "업데이트 없음"
     *   2. GitHub URL 없음 OR API 조회 실패 → _bundled 폴백 (안전망, `github` 지정이면 폴백하지 않음)
     *   `bundled` 지정이면 GitHub 를 보지 않고 번들만 비교한다 (CLI `--source`).
     *
     * --force 업데이트 우선순위는 resolveForceUpdateSource() 참조 (번들 우선).
     *
     * 참고: _pending 디렉토리는 install 경로에서만 사용되며 update 에서는 참조하지 않음.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $source  소스 지정 (`bundled`·`github`, null 이면 자동 — GitHub·번들 중 높은 버전)
     * @return array{update_available: bool, update_source: string|null, latest_version: string|null, current_version: string|null} 업데이트 확인 결과
     */
    public function checkTemplateUpdate(string $identifier, ?string $source = null): array
    {
        $record = $this->templateRepository->findByIdentifier($identifier);
        if (! $record) {
            return $this->buildTemplateUpdateResponse(false, null, null, null, null);
        }

        $currentVersion = $record->version;
        $template = $this->getTemplate($identifier);
        $activeRequiredCoreVersion = $template['g7_version'] ?? null;

        // 1. GitHub URL이 있으면 GitHub에서 최신 버전 확인 (번들 지정이면 건너뜀)
        $githubUrl = $template['github_url'] ?? ($record->github_url ?? null);
        if ($source !== 'bundled' && $githubUrl) {
            try {
                $latestVersion = $this->fetchLatestVersion($githubUrl);
            } catch (\Throwable $e) {
                Log::warning('템플릿 GitHub 버전 조회 실패', [
                    'template' => $identifier,
                    'url' => $githubUrl,
                    'error' => $e->getMessage(),
                ]);
                $latestVersion = null;
            }

            if ($latestVersion !== null) {
                // 소스를 지정하지 않았으면 코어와 함께 배포된 번들이 공개 최신보다 새 판일 때 번들을 후보로 본다 — 확장 공개
                // 릴리스가 코어 출시보다 늦으면 GitHub 결과만 믿어 「업데이트 없음」으로 끝났다(W3 실측: 번들 1.1.0, 공개 1.0.4).
                $bundled = $source === null ? $this->bundledTemplateUpdateCandidate($identifier, $activeRequiredCoreVersion) : null;
                if ($bundled !== null
                    && version_compare($bundled['version'], $latestVersion, '>')
                    && version_compare($bundled['version'], $currentVersion, '>')) {
                    return $this->buildTemplateUpdateResponse(true, 'bundled', $bundled['version'], $currentVersion, $bundled['required']);
                }

                if (version_compare($latestVersion, $currentVersion, '>')) {
                    return $this->buildTemplateUpdateResponse(
                        true, 'github', $latestVersion, $currentVersion, $activeRequiredCoreVersion
                    );
                }

                return $this->buildTemplateUpdateResponse(
                    false, null, $currentVersion, $currentVersion, $activeRequiredCoreVersion
                );
            }

            Log::info('템플릿 업데이트 확인: GitHub 조회 실패로 bundled 폴백', [
                'template' => $identifier,
            ]);
        }

        // github 지정 — 번들로 폴백하지 않는다(문서: GitHub 단독).
        if ($source === 'github') {
            return $this->buildTemplateUpdateResponse(false, null, $currentVersion, $currentVersion, $activeRequiredCoreVersion);
        }

        // 2. _bundled에서 업데이트 확인
        if (isset($this->bundledTemplates[$identifier])) {
            $bundledVersion = $this->bundledTemplates[$identifier]['version'] ?? null;
            $bundledRequired = $this->resolveUpdateTargetRequiredCoreVersion('bundled', $this->bundledTemplates[$identifier], $activeRequiredCoreVersion);
            if ($bundledVersion && version_compare($bundledVersion, $currentVersion, '>')) {
                return $this->buildTemplateUpdateResponse(
                    true, 'bundled', $bundledVersion, $currentVersion, $bundledRequired
                );
            }
        } else {
            $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');
            if (isset($bundledMeta[$identifier])) {
                $bundledVersion = $bundledMeta[$identifier]['version'] ?? null;
                $bundledRequired = $this->resolveUpdateTargetRequiredCoreVersion('bundled', $bundledMeta[$identifier], $activeRequiredCoreVersion);
                if ($bundledVersion && version_compare($bundledVersion, $currentVersion, '>')) {
                    return $this->buildTemplateUpdateResponse(
                        true, 'bundled', $bundledVersion, $currentVersion, $bundledRequired
                    );
                }
            }
        }

        return $this->buildTemplateUpdateResponse(
            false, null, $currentVersion, $currentVersion, $activeRequiredCoreVersion
        );
    }

    /**
     * 번들(`_bundled`) 판 템플릿의 버전과 코어 요구 버전을 돌려줍니다 — 번들에 없으면 null.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $activeRequiredCoreVersion  설치본의 코어 요구 버전 (번들 manifest 에 없을 때의 폴백)
     * @return array{version: string, required: string|null}|null 번들 후보
     */
    private function bundledTemplateUpdateCandidate(string $identifier, ?string $activeRequiredCoreVersion): ?array
    {
        $meta = $this->bundledTemplates[$identifier]
            ?? (ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json')[$identifier] ?? null);
        $version = is_array($meta) ? ($meta['version'] ?? null) : null;

        if (! is_string($version) || $version === '') {
            return null;
        }

        return ['version' => $version, 'required' => $this->resolveUpdateTargetRequiredCoreVersion('bundled', $meta, $activeRequiredCoreVersion)];
    }

    /**
     * checkTemplateUpdate 응답 페이로드 빌더 (호환성 메타 부착).
     *
     * @param  bool  $updateAvailable  업데이트 가용 여부
     * @param  string|null  $updateSource  업데이트 소스 (github|bundled|null)
     * @param  string|null  $latestVersion  최신 버전
     * @param  string|null  $currentVersion  현재 설치된 버전
     * @param  string|null  $requiredCoreVersion  요구 코어 버전 제약
     * @return array{update_available: bool, update_source: ?string, latest_version: ?string, current_version: ?string, required_core_version: ?string, is_compatible: bool, current_core_version: string} 업데이트 확인 응답(코어 호환 필드 포함)
     */
    protected function buildTemplateUpdateResponse(
        bool $updateAvailable,
        ?string $updateSource,
        ?string $latestVersion,
        ?string $currentVersion,
        ?string $requiredCoreVersion,
    ): array {
        return [
            'update_available' => $updateAvailable,
            'update_source' => $updateSource,
            'latest_version' => $latestVersion,
            'current_version' => $currentVersion,
            ...$this->buildUpdateCompatibilityFields($requiredCoreVersion),
        ];
    }

    /**
     * 모든 설치된 템플릿의 업데이트를 확인합니다.
     *
     * @return array{updated_count: int, details: array} 갱신 건수와 템플릿별 결과
     */
    public function checkAllTemplatesForUpdates(): array
    {
        $templateRecords = $this->templateRepository->getAllKeyedByIdentifier();
        $details = [];
        $updatedCount = 0;
        $checkedCount = 0;

        foreach ($templateRecords as $identifier => $record) {
            $result = $this->checkTemplateUpdate($identifier);
            $checkedCount++;

            $updateData = [
                'update_available' => $result['update_available'],
                'latest_version' => $result['latest_version'],
                'update_source' => $result['update_source'],
                'updated_at' => now(),
            ];

            // GitHub 출처인 경우 changelog URL 갱신
            if ($result['update_source'] === 'github') {
                $template = $this->getTemplate($identifier);
                $githubUrl = $template['github_url'] ?? ($record->github_url ?? null);
                if ($githubUrl) {
                    $updateData['github_changelog_url'] = $this->buildChangelogUrl($githubUrl);
                }
            }

            $this->templateRepository->updateByIdentifier($identifier, $updateData);

            if ($result['update_available']) {
                $updatedCount++;
                $details[] = [
                    'identifier' => $identifier,
                    'update_available' => true,
                    'current_version' => $result['current_version'],
                    'latest_version' => $result['latest_version'],
                    'update_source' => $result['update_source'],
                ];
            }
        }

        return [
            'updated_count' => $updatedCount,
            'checked_count' => $checkedCount,
            'details' => $details,
        ];
    }

    /**
     * GitHub에서 템플릿 업데이트를 다운로드하여 _pending 스테이징에 배치합니다.
     *
     * ExtensionManager의 공용 GitHub 다운로드 유틸리티를 사용하여
     * 코어 업데이트와 동일한 폴백 체인(ZipArchive → unzip)을 적용합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $githubUrl  GitHub 저장소 URL
     * @param  string  $version  다운로드할 버전
     * @return string 스테이징 경로
     *
     * @throws \RuntimeException 다운로드 실패 시
     */
    protected function downloadTemplateUpdate(string $identifier, string $githubUrl, string $version): string
    {
        if (! $githubUrl) {
            throw new \RuntimeException(__('templates.errors.invalid_github_url'));
        }

        if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
            throw new \RuntimeException(__('templates.errors.invalid_github_url'));
        }

        $owner = $matches[1];
        $repo = $matches[2];

        // _pending 스테이징 경로 생성
        $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->templatesPath, $identifier);

        // 임시 디렉토리 (다운로드/추출용)
        $tempDir = storage_path('app/temp/template_update_'.uniqid());

        try {
            ExtensionPendingHelper::ensureUpdateTempDirectory($tempDir);

            // GitHub에서 다운로드 및 추출 (코어와 동일한 폴백 체인)
            $extractedDir = $this->extensionManager->downloadAndExtractFromGitHub(
                $owner, $repo, $version, $tempDir, config('app.update.github_token') ?? ''
            );

            // 추출된 파일을 _pending 스테이징으로 복사
            ExtensionPendingHelper::stageForUpdate($extractedDir, $stagingPath);

            Log::info('템플릿 업데이트 다운로드 및 스테이징 완료', [
                'template' => $identifier,
                'version' => $version,
                'staging_path' => $stagingPath,
            ]);

            return $stagingPath;

        } catch (\Exception $e) {
            ExtensionPendingHelper::cleanupStaging($stagingPath);
            throw $e;
        } finally {
            // 임시 파일 정리
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    /**
     * 사용자가 수정한 레이아웃이 있는지 확인합니다.
     *
     * original_content_hash와 현재 DB content의 hash를 비교하여
     * 사용자가 관리자 UI에서 레이아웃을 수정한 적이 있는지 감지합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{has_modified_layouts: bool, modified_count: int, modified_layouts: array} 수정한 레이아웃 존재 여부·개수·목록
     */
    public function hasModifiedLayouts(string $identifier): array
    {
        $record = $this->templateRepository->findByIdentifier($identifier);
        if (! $record) {
            return [
                'has_modified_layouts' => false,
                'modified_count' => 0,
                'modified_layouts' => [],
            ];
        }

        // 템플릿 자신이 소유한 레이아웃만 센다 — getByTemplateId() 는 같은 template_id 에
        // 등록된 모듈/플러그인 소유 레이아웃까지 반환해서, 남의 확장 수정본이 템플릿
        // 업데이트 모달에 자기 것으로 집계된다. 그래서 source_type='template' 만 남긴다.
        // 오버라이드 레이아웃(source_identifier = 템플릿)도 이 템플릿 소유라 함께 센다(W6) — keep 갱신이 병합하는 범위와 같다.
        $ownLayouts = $this->layoutRepository->getByTemplateIdWithFilter($record->id, 'template', null)
            ->concat($this->layoutRepository->getByTemplateIdWithFilter($record->id, 'template', $identifier));

        return $this->buildModifiedLayoutsReport(
            $ownLayouts,
            $this->layoutExtensionRepository->getBySource(LayoutSourceType::Template, $identifier)
        );
    }

    /**
     * 템플릿을 업데이트합니다.
     *
     * 프로세스: 백업 → updating 상태 → 파일 교체 → DB 갱신 →
     * 상태 복원 → 레이아웃 갱신 → 활성 확장 조각 재등록(활성 템플릿만) → 백업 삭제
     *
     * 파라미터 순서는 updateModule / updatePlugin 과 일치시켜 공통 prefix
     * (id, force, onProgress, ...) 를 공유한다. 템플릿은 upgrade step 이 없어
     * vendorMode / onUpgradeStep 은 없음.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  bool  $force  버전 비교 없이 강제 업데이트
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @param  string  $layoutStrategy  레이아웃 전략 ('overwrite' 또는 'keep')
     * @param  string|null  $sourceOverride  소스 강제 지정 ('bundled' | 'github'). null 이면 자동 감지
     * @param  string|null  $zipPath  사용자가 업로드한 ZIP 경로 (sourceOverride 가 'zip' 일 때 필수)
     * @return array{success: bool, from_version: string|null, to_version: string|null, message: string} 업데이트 결과(성공 여부·이전/이후 버전·메시지)
     *
     * @throws \RuntimeException 업데이트 실패 시
     * @throws ExtensionOperationInProgressException 같은 확장의 업데이트가 이미 진행 중일 때 (409)
     */
    public function updateTemplate(string $identifier, bool $force = false, ?\Closure $onProgress = null, string $layoutStrategy = 'overwrite', ?string $sourceOverride = null, ?string $zipPath = null): array
    {
        // 같은 템플릿의 업데이트는 한 번에 하나만 — 상태 가드(status=updating)가 서기 전 구간에 들어온 두 번째 요청이
        // 함께 통과해 업데이트가 두 번 수행됐다(#138 실측 ② 버튼 더블클릭). 직전 훅보다 먼저 잠그고, 이 지역 변수가
        // 메서드를 빠져나갈 때(정상 반환·예외 모두) 잠금이 풀린다.
        $operationLock = ExtensionOperationLock::acquire('template', $identifier);

        // 직전 훅 — CLI·어드민 UI 공통으로 한 번 발화한다(예전에는 Service 에서만 발화해 CLI 가 빠졌다, #138 후속)
        HookManager::doAction('core.templates.before_version_update', $identifier);

        $record = $this->templateRepository->findByIdentifier($identifier);
        if (! $record) {
            throw new \RuntimeException(__('templates.not_installed', ['template' => $identifier]));
        }

        // 상태 가드
        ExtensionStatusGuard::assertNotInProgress(
            ExtensionStatus::from($record->status),
            $identifier
        );

        $previousStatus = $record->status;
        $fromVersion = $record->version;
        $updateInfo = $this->checkTemplateUpdate($identifier);

        // ZIP 강제 경로: 외부 ZIP 파일을 직접 추출하여 사용. checkTemplateUpdate 결과는 무시.
        // zipTempDir / zipExtractedDir 는 staging 단계에서 사용 후 finally 에서 정리.
        $zipTempDir = null;
        $zipExtractedDir = null;
        if ($zipPath !== null) {
            $prepared = $this->extensionManager->prepareZipSource($zipPath, $identifier, 'template.json');
            $zipTempDir = $prepared['temp_dir'];
            $zipExtractedDir = $prepared['extracted_dir'];
            $updateSource = 'zip';
            $toVersion = $prepared['to_version'];
        }
        // 번들 강제 경로: 코어 업그레이드 / 일괄 업데이트 컨텍스트에서 GitHub 상태와 무관하게
        // _bundled manifest 버전을 강제 사용.
        elseif ($sourceOverride === 'bundled') {
            $bundled = $this->getBundledVersion($identifier);
            if ($bundled === null) {
                throw new \RuntimeException(
                    __('templates.errors.force_update_no_source', ['template' => $identifier])
                );
            }
            $updateSource = 'bundled';
            $toVersion = $bundled;
        } elseif ($sourceOverride === 'github') {
            // GitHub 강제 경로: _bundled 폴백 없이 GitHub 만 시도.
            $template = $this->getTemplate($identifier);
            $githubUrl = $template['github_url'] ?? ($record->github_url ?? null);
            if (empty($githubUrl)) {
                throw new \RuntimeException(
                    __('templates.errors.force_update_no_source', ['template' => $identifier])
                );
            }
            $updateSource = 'github';
            $toVersion = ($updateInfo['update_source'] === 'github' ? $updateInfo['latest_version'] : null)
                ?? $updateInfo['current_version'];
        } elseif (! $updateInfo['update_available'] && ! $force) {
            $result = [
                'success' => false,
                'from_version' => $fromVersion,
                'to_version' => $fromVersion,
                'message' => __('templates.no_update_available'),
            ];

            // "업데이트할 것 없음" 도 정규 훅을 발화한다 — 어드민 UI 는 종전부터 이 결과를 활동 로그에
            // 남겨 왔다(Service 발화 시절 동작 보존). 구훅(updated)은 발화하지 않는다.
            HookManager::doAction('core.templates.after_version_update', $identifier, $result, $this->getTemplateInfo($identifier) ?? []);

            return $result;
        } elseif ($force && ! $updateInfo['update_available']) {
            $updateSource = $this->resolveForceUpdateSource($identifier);

            if ($updateSource === null) {
                throw new \RuntimeException(
                    __('templates.errors.force_update_no_source', ['template' => $identifier])
                );
            }

            // 번들 재설치는 번들 manifest 버전 기준, github 재설치는 현재 버전 기준
            if ($updateSource === 'bundled') {
                $toVersion = $this->getBundledVersion($identifier) ?? $updateInfo['current_version'];
            } else {
                $toVersion = $updateInfo['current_version'];
            }
        } else {
            $toVersion = $updateInfo['latest_version'];
            $updateSource = $updateInfo['update_source'];
        }

        // 다운그레이드 차단 — fromVersion > toVersion 인 경우 force=false 면 차단.
        // force=true 는 의도적 다운그레이드 (장애 롤백 등) 허용. lang pack/모듈/플러그인과 일관.
        if ($fromVersion && version_compare($toVersion, $fromVersion, '<') && ! $force) {
            throw new \RuntimeException(__('templates.errors.downgrade_blocked', [
                'from' => $fromVersion,
                'to' => $toVersion,
            ]));
        }

        $backupPath = null;
        $layoutSync = null;
        $layoutWarnings = [];
        $recordedColumns = [];
        $filesRestored = false;

        try {
            // 1. 백업 생성
            $onProgress?->__invoke('backup', __('extensions.progress.backup'));
            $backupPath = ExtensionBackupHelper::createBackup('templates', $identifier, $onProgress);

            // 2. 상태 → updating
            $onProgress?->__invoke('status', __('extensions.progress.status_change'));
            $this->templateRepository->updateByIdentifier($identifier, [
                'status' => ExtensionStatus::Updating->value,
                'updated_at' => now(),
            ]);

            // 3. 스테이징 (소스에 따라 분기) — 템플릿은 composer install 불필요
            $onProgress?->__invoke('staging', __('extensions.progress.staging'));
            $stagingPath = null;

            try {
                if ($updateSource === 'github') {
                    $template = $this->getTemplate($identifier);
                    $githubUrl = $template['github_url'] ?? ($record->github_url ?? null);
                    $stagingPath = $this->downloadTemplateUpdate($identifier, $githubUrl, $toVersion);
                } elseif ($updateSource === 'bundled') {
                    $sourcePath = ExtensionPendingHelper::getBundledPath($this->templatesPath, $identifier);
                    $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->templatesPath, $identifier);
                    ExtensionPendingHelper::stageForUpdate($sourcePath, $stagingPath, $onProgress);
                } elseif ($updateSource === 'zip') {
                    $stagingPath = ExtensionPendingHelper::createUpdateStagingPath($this->templatesPath, $identifier);
                    ExtensionPendingHelper::stageForUpdate($zipExtractedDir, $stagingPath, $onProgress);
                }

                // 3.5. 코어 버전 호환성 사전 검증 (staging manifest 기준)
                if ($stagingPath && ! $force && ! CoreServiceProvider::isCoreUpdateInProgress()) {
                    $stagedManifest = (new Vendor\VendorIntegrityChecker)->readManifest($stagingPath);
                    CoreVersionChecker::validateExtension(
                        $stagedManifest['g7_version'] ?? null,
                        $identifier,
                        'template'
                    );
                }

                // 4. 원자적 적용 (스테이징 → 활성 디렉토리)
                $onProgress?->__invoke('files', __('extensions.progress.replace_files'));
                if ($stagingPath) {
                    $targetPath = $this->templatesPath.DIRECTORY_SEPARATOR.$identifier;
                    ExtensionPendingHelper::copyToActive($stagingPath, $targetPath, $onProgress);
                }
            } finally {
                // 스테이징 정리
                if ($stagingPath) {
                    ExtensionPendingHelper::cleanupStaging($stagingPath);
                }
                // ZIP 임시 추출 디렉토리 정리
                if ($zipTempDir && File::isDirectory($zipTempDir)) {
                    File::deleteDirectory($zipTempDir);
                }
            }

            // 템플릿 재로드 (새 파일로)
            $onProgress?->__invoke('reload', __('extensions.progress.reload'));
            $this->reloadTemplate($identifier);
            $template = $this->getTemplate($identifier);

            // SEO 설정 검증 (업데이트 후 seo-config.json 유효성 검사)
            $this->validateSeoConfig($identifier);

            // 5. 트랜잭션: DB 정보 갱신
            $onProgress?->__invoke('db', __('extensions.progress.db_update'));
            DB::beginTransaction();
            try {
                $name = $template ? $this->convertToMultilingual($template['name']) : $record->name;
                $description = $template ? $this->convertToMultilingual($template['description'] ?? '') : $record->description;

                // 활성 언어팩의 manifest seed(ja 등)를 name/description 다국어 필드에 주입 (install 경로와 동일)
                if ($template) {
                    $manifest = HookManager::applyFilters(
                        "template.{$identifier}.manifest.translations",
                        ['name' => $name, 'description' => $description]
                    );
                    $name = $manifest['name'] ?? $name;
                    $description = $manifest['description'] ?? $description;
                }

                $recorded = [
                    'version' => $toVersion,
                    'latest_version' => $toVersion,
                    'name' => $name,
                    'description' => $description,
                    'update_available' => false,
                    'update_source' => null,
                    'github_url' => $template['github_url'] ?? $record->github_url,
                    'github_changelog_url' => $this->buildChangelogUrl($template['github_url'] ?? $record->github_url),
                    'metadata' => $template['metadata'] ?? $record->metadata,
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ];
                $this->templateRepository->updateByIdentifier($identifier, $recorded);

                DB::commit();
                // 실패 경로가 되돌릴 칸 — 이 배열 하나가 기록·복원의 SSoT 다(갱신 시각은 복원이 새로 찍는다).
                $recordedColumns = array_keys(Arr::except($recorded, ['updated_at']));
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            // 6. 상태 복원 (refreshTemplateLayouts()가 active 상태를 요구하므로 먼저 복원)
            $onProgress?->__invoke('restore_status', __('extensions.progress.restore_status'));
            $this->templateRepository->updateByIdentifier($identifier, [
                'status' => $previousStatus,
                'updated_at' => now(),
            ]);

            // 7. 레이아웃 갱신 (이전 상태가 active이고 overwrite 전략이면)
            // refreshTemplateLayouts()는 캐시 초기화 + 캐시 버전 증가를 포함
            $onProgress?->__invoke('layout', __('extensions.progress.layout_update'));
            if ($previousStatus === ExtensionStatus::Active->value) {
                $preserveModified = ($layoutStrategy === 'keep');
                $refreshed = $this->refreshTemplateLayouts($identifier, $preserveModified, $backupPath);
                $layoutSync = $refreshed['layout_sync'] ?? null;
                $layoutWarnings = $refreshed['layout_warnings'] ?? [];

                // 활성 모듈·플러그인의 조각을 새 판 템플릿에 다시 등록한다(keep — 운영자가 고친 조각은 보존).
                // 번들 일괄 업데이트는 모듈 → 플러그인 → 템플릿 순서라, 플러그인이 조각을 등록할 때는 템플릿이 아직 옛 판이어서
                // 새 확장점(예: 결제 플러그인의 주문 상세 슬롯)에 붙는 조각이 붙을 자리가 없어 건너뛰어졌다. 템플릿을 갱신한
                // 지금 다시 등록해야 그 조각이 들어온다. 실패해도 파일·버전은 이미 새 판이라 업데이트는 되돌리지 않는다 —
                // 다른 확장을 켜거나 갱신할 때 같은 재등록이 다시 돈다.
                try {
                    $this->layoutExtensionService->registerAllActiveExtensionsToTemplate($record->id);
                } catch (\Throwable $e) {
                    Log::error('템플릿 업데이트 뒤 확장 조각 재등록 실패', ['template' => $identifier, 'error' => $e->getMessage()]);
                }
            } elseif ($backupPath !== null) {
                // 꺼 둔 채 업데이트(D11) — 켤 때(activateTemplate) 병합할 수 있게 이전 판 원본을 미리 저장한다.
                $this->layoutSynchronizer()->stashBaseForInactiveUpdate($backupPath, 'templates', $identifier);
            }

            // 8. 백업 삭제 + 캐시 삭제
            $onProgress?->__invoke('cleanup', __('extensions.progress.cleanup'));
            ExtensionBackupHelper::deleteBackup($backupPath);
            // 백업을 지운 뒤의 실패(완료 훅·캐시 재생성)는 파일을 되돌릴 수 없다 — 파일이 새 버전이므로 DB 버전 칸도 두어야
            // 둘이 맞는다. catch 가 지워진 백업으로 복원을 시도하지 않게 비운다.
            $backupPath = null;

            // 고정 키(config/components_manifest) + 현재 버전 routes/language 키 능동 삭제.
            // 비활성 업데이트·레이아웃 무변경 업데이트 경로는 버전 bump 가 없어 이 호출이
            // 없으면 공개 config.json 이 TTL 동안 이전 manifest 로 응답된다 (#588, 공개 #119).
            // 활성 경로의 refreshTemplateLayouts() 경유 중복 삭제는 forget 멱등이라 무해.
            $this->clearTemplateCache($identifier);

            $this->clearAllTemplateLanguageCaches();
            $this->clearAllTemplateRoutesCaches();
            // 모듈(`updateModule`)·플러그인(`updatePlugin`)과 동형으로 **무조건** 올린다 —
            // lang/routes/components/dist 만 바뀐 릴리스도 정적 게시본(#122)을 갱신해야 한다.
            // 종전에는 활성 템플릿이면 `refreshTemplateLayouts()` 의 조건부 bump(레이아웃
            // 변경 건수 > 0)에 위임했는데, 그 조건은 게시 입력 중 레이아웃 축만 보므로
            // 레이아웃이 그대로인 릴리스는 게시본이 immutable 로 stale 하게 남았다(#651 F1).
            // `refreshTemplateLayouts()` 내부의 조건부 bump 는 단독 호출처(refresh-layout)가
            // 있어 그대로 두며, 이중 bump 는 put 1회 비용이고 terminating 게시는 1회로 병합된다.
            $this->incrementExtensionCacheVersion();
            self::invalidateTemplateStatusCache();

            // 훅 발행: 템플릿 업데이트 완료 (Artisan 직접 호출 시에도 리스너 트리거)
            // @deprecated 'core.templates.updated' — identifier 1개만 전달하는 구훅. 정규 훅은 after_version_update.
            HookManager::doAction('core.templates.updated', $identifier);

            Log::info('템플릿 업데이트 완료', [
                'template' => $identifier,
                'from' => $fromVersion,
                'to' => $toVersion,
                'source' => $updateSource,
                'layout_strategy' => $layoutStrategy,
            ]);

            // 새 판이 요구하는 의존 확장 버전 검사 — 모자라면 경고만 하고 갱신은 그대로 둔다(일괄 업데이트처럼 의존 확장이
            // 곧 따라 올라오는 흐름을 막지 않는다). 결과·완료 문구·로그로 알린다.
            $dependencyWarnings = DependencyVersionWarnings::afterUpdate(
                'template',
                $identifier,
                $this->templatesPath.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.'template.json'
            );

            $result = [
                'success' => true,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'layout_strategy' => $layoutStrategy,
                'layout_sync' => $layoutSync,
                'layout_warnings' => $layoutWarnings,
                'dependency_warnings' => $dependencyWarnings,
                'message' => DependencyVersionWarnings::appendToMessage(__('templates.update_success', [
                    'template' => $identifier,
                    'version' => $toVersion,
                ]), $dependencyWarnings),
            ];

        } catch (\Throwable $e) {
            Log::error('템플릿 업데이트 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            // 실패 시점에 되돌릴 백업이 있었는지 — 백업을 지운 뒤(마무리 단계) 실패면 새 판이 남는다. 실패 안내가 실제 결과를 말하게 한다.
            $hadBackup = $backupPath !== null;

            if ($backupPath) {
                try {
                    ExtensionBackupHelper::restoreFromBackup('templates', $identifier, $backupPath);
                    $filesRestored = true;
                    ExtensionBackupHelper::deleteBackup($backupPath);

                    $this->reloadTemplate($identifier);
                } catch (\Throwable $restoreError) {
                    Log::error('템플릿 백업 복원 실패', [
                        'template' => $identifier,
                        'error' => $restoreError->getMessage(),
                    ]);
                }
            }

            // 상태 복원 — 새 버전 기록이 이미 저장된 뒤 실패했고 파일을 백업으로 되돌렸으면 버전 칸도 업데이트 전 값으로
            // 되돌린다. 파일은 옛 버전인데 DB 만 새 버전이면 관리자 화면이 설치되지 않은 버전을 보여 주고, "업데이트 없음" 으로
            // 판정돼 같은 업데이트를 다시 시도할 수도 없다. 파일을 되돌리지 못했으면(백업 삭제 뒤 실패·복원 실패) 새 버전
            // 파일에 맞춰 버전 칸을 그대로 둔다 — 반대 방향 불일치를 만들지 않는다. 업그레이드 스텝은 멱등이 규정이라
            // (upgrade-step-guide 원칙 4) 다시 시도할 때 처음부터 돌아도 안전하다.
            $this->templateRepository->updateByIdentifier($identifier, [
                'status' => $previousStatus,
                'updated_at' => now(),
                ...($filesRestored && $recordedColumns !== [] ? Arr::only($record->getRawOriginal(), $recordedColumns) : []),
            ]);

            // 실패 경로도 bump 한다 — 백업 복원이 실패했거나 부분 반영된 디스크가 남을 수
            // 있어, 게시본을 새 버전으로 다시 굽는 쪽이 옛 게시본을 그대로 두는 쪽보다
            // 안전하다(#651 F2). 원래 예외를 가리지 않도록 bump 실패는 삼킨다.
            try {
                $this->incrementExtensionCacheVersion();
            } catch (\Throwable) {
                // 원래 예외(아래 throw)가 진짜 원인이다 — bump 실패는 로그(트레이트)로 충분
            }

            throw ExtensionUpdateFailedException::fromFailure(
                __('templates.errors.update_failed', [
                    'template' => $identifier,
                    'error' => $e->getMessage(),
                ]),
                $filesRestored,
                $hadBackup,
                $recordedColumns !== [],
                $e
            );
        }

        // 정규 훅 — CLI·어드민 UI 공통 단일 발화(#138). 업데이트 롤백(백업 복원) try 밖에서 발화해,
        // 리스너 예외가 이미 끝난 업데이트를 되돌리는 경로로 들어가지 않게 한다.
        // 이름이 after_version_update 인 이유: after_update 는 템플릿 레코드 수정 훅이다(동명이인 유지).
        HookManager::doAction('core.templates.after_version_update', $identifier, $result, $this->getTemplateInfo($identifier) ?? []);

        return $result;
    }

    /**
     * --force 시 업데이트 소스를 결정합니다.
     *
     * 운영 정책: --force 시에는 번들이 우선, 번들이 없는 경우에만 GitHub 사용.
     * (일반 업데이트의 GitHub 우선과 반대 — 개발자가 로컬 번들로 되돌리려는 의도 존중)
     *
     * 우선순위:
     *   1. _bundled (메모리 캐시) → 'bundled'
     *   2. _bundled (디스크 재조회) → 'bundled'
     *   3. GitHub URL 존재 → 'github'
     *   4. 둘 다 없음 → null (업데이트 불가)
     *
     * @param  string  $identifier  템플릿 식별자
     * @return string|null 'bundled' | 'github' | null
     */
    private function resolveForceUpdateSource(string $identifier): ?string
    {
        if (isset($this->bundledTemplates[$identifier])) {
            return 'bundled';
        }

        $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');
        if (isset($bundledMeta[$identifier])) {
            return 'bundled';
        }

        // 번들 없음 → GitHub URL 확인
        $template = $this->getTemplate($identifier);
        $record = $this->templateRepository->findByIdentifier($identifier);
        $githubUrl = ($template['github_url'] ?? null) ?: ($record->github_url ?? null);
        if ($githubUrl) {
            return 'github';
        }

        return null;
    }

    /**
     * _bundled 에 등록된 템플릿의 버전을 반환합니다 (force 업데이트용).
     *
     * @param  string  $identifier  템플릿 식별자
     * @return string|null 버전 문자열 또는 null
     */
    private function getBundledVersion(string $identifier): ?string
    {
        if (isset($this->bundledTemplates[$identifier]['version'])) {
            return $this->bundledTemplates[$identifier]['version'];
        }

        $meta = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');

        return $meta[$identifier]['version'] ?? null;
    }

    /**
     * 같은 식별자의 _bundled template 매니페스트를 반환합니다 (업데이트 대상 코어 호환 판정용).
     *
     * @param  string  $identifier  template 식별자
     * @return array<string, mixed>|null _bundled 매니페스트 또는 null
     */
    private function findBundledTemplateManifest(string $identifier): ?array
    {
        if (isset($this->bundledTemplates[$identifier])) {
            return $this->bundledTemplates[$identifier];
        }

        $meta = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');

        return $meta[$identifier] ?? null;
    }
}
