<?php

namespace App\Extension;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\ExtensionStatusGuard;
use App\Extension\Helpers\GithubHelper;
use App\Services\LayoutExtensionService;
use App\Services\LayoutService;
use App\Services\TemplateService;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
    use Traits\CachesTemplateStatus;
    use Traits\ClearsTemplateCaches;
    use Traits\InspectsUninstallData;
    use Traits\InvalidatesLayoutCache;
    use Traits\ValidatesLayoutFiles;

    /** @var int install 프로그레스바 단계 수 */
    public const INSTALL_STEPS = 4;

    /** @var int update 프로그레스바 단계 수 */
    public const UPDATE_STEPS = 8;

    /** @var int uninstall 프로그레스바 단계 수 */
    public const UNINSTALL_STEPS = 3;

    protected array $templates = [];

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

    protected string $templatesPath;

    protected string $pendingTemplatesPath;

    protected string $bundledTemplatesPath;

    public function __construct(
        protected ExtensionManager $extensionManager,
        protected TemplateRepositoryInterface $templateRepository,
        protected LayoutRepositoryInterface $layoutRepository,
        protected ModuleRepositoryInterface $moduleRepository,
        protected PluginRepositoryInterface $pluginRepository,
        protected LayoutExtensionService $layoutExtensionService
    ) {
        $this->templatesPath = base_path('templates');
        $this->pendingTemplatesPath = $this->templatesPath.DIRECTORY_SEPARATOR.'_pending';
        $this->bundledTemplatesPath = $this->templatesPath.DIRECTORY_SEPARATOR.'_bundled';
    }

    /**
     * 모든 템플릿을 로드하고 초기화합니다.
     */
    public function loadTemplates(): void
    {
        // 기존 템플릿 캐시 초기화 (테스트 환경에서 재로드 지원)
        $this->templates = [];

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
     * @return bool 설치 성공 여부
     *
     * @throws \Exception 템플릿을 찾을 수 없거나 의존성 문제 시
     */
    public function installTemplate(string $templateName, ?\Closure $onProgress = null): bool
    {
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

        // 1. _pending/_bundled에서 활성 디렉토리로 복사 (활성 디렉토리에 없는 경우)
        $onProgress?->__invoke('copy', '파일 복사 중...');
        if (! isset($this->templates[$templateName])) {
            $this->copyToActiveFromSource($templateName, $onProgress);
        }

        // 2. 검증
        $onProgress?->__invoke('validate', '검증 중...');

        return DB::transaction(function () use ($templateName, $onProgress) {
            $template = $this->getTemplate($templateName);
            if (! $template) {
                throw new \Exception(__('templates.errors.not_found', ['template' => $templateName]));
            }

            // 의존성 확인
            $this->checkDependencies($template);

            // SEO 설정 검증 (설치 전 seo-config.json 유효성 검사)
            $this->validateSeoConfig($templateName);

            // 레이아웃 검증 (설치 전 모든 레이아웃 파일 유효성 검사)
            $this->validateLayouts($templateName);

            // name과 description 다국어 변환 (역호환성)
            $name = $this->convertToMultilingual($template['name']);
            $description = $this->convertToMultilingual($template['description'] ?? '');

            // 3. DB 등록
            $onProgress?->__invoke('db', 'DB 등록 중...');

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
            $onProgress?->__invoke('layout', '레이아웃 등록 중...');

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
            HookManager::doAction('core.templates.installed', $templateName);

            return true;
        });
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
        $template = $this->getTemplate($templateName);
        if (! $template) {
            throw new \Exception(__('templates.errors.not_found', ['template' => $templateName]));
        }

        $templateRecord = $this->templateRepository->findByIdentifier($templateName);
        if (! $templateRecord) {
            throw new \Exception(__('templates.errors.not_installed', ['template' => $templateName]));
        }

        // 이미 활성화된 템플릿인지 확인
        if ($templateRecord->status === ExtensionStatus::Active->value) {
            throw new \Exception(__('templates.errors.already_active'));
        }

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
                'updated_at' => now(),
            ]);

            // 활성 모듈/플러그인의 레이아웃 확장을 새 템플릿에 등록
            $this->layoutExtensionService->registerAllActiveExtensionsToTemplate($templateRecord->id);

            // 활성 모듈/플러그인의 레이아웃을 새 템플릿에 등록
            // registerModuleLayouts/registerPluginLayouts는 모든 활성 템플릿을 조회하므로
            // 새로 활성화된 템플릿에도 자동으로 레이아웃이 등록됨 (updateOrCreate로 기존 중복 안전)
            app(ModuleManager::class)->registerLayoutsForAllActiveModules();
            app(PluginManager::class)->registerLayoutsForAllActivePlugins();

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
        HookManager::doAction('core.templates.activated', $templateName);

        return ['success' => true];
    }

    /**
     * 지정된 타입의 모든 템플릿을 비활성화합니다.
     *
     * @param  string  $type  템플릿 타입 ('admin' 또는 'user')
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
     * @return bool 비활성화 성공 여부
     */
    public function deactivateTemplate(string $templateName): bool
    {
        $template = $this->getTemplate($templateName);
        if (! $template) {
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
            'updated_at' => now(),
        ]);

        // 템플릿 상태 캐시 무효화
        self::invalidateTemplateStatusCache();

        // 확장 캐시 버전 증가 (프론트엔드가 새로운 캐시로 요청하도록)
        $this->incrementExtensionCacheVersion();

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
     * @return bool 제거 성공 여부
     *
     * @throws \Exception 템플릿을 찾을 수 없을 때
     */
    public function uninstallTemplate(string $templateName, ?\Closure $onProgress = null): bool
    {
        // 1. 캐시 삭제
        $onProgress?->__invoke('cache', '캐시 삭제 중...');

        $result = DB::transaction(function () use ($templateName, $onProgress) {
            $template = $this->getTemplate($templateName);
            if (! $template) {
                throw new \Exception(__('templates.errors.not_found', ['template' => $templateName]));
            }

            // 캐시 삭제
            $this->clearTemplateCache($templateName);

            // 2. DB 삭제
            $onProgress?->__invoke('db', 'DB 삭제 중...');

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
            $onProgress?->__invoke('files', '파일 삭제 중...');

            // 활성 템플릿 디렉토리 전체 삭제 (_pending/_bundled에 원본 보존되므로 재설치 가능)
            ExtensionPendingHelper::deleteExtensionDirectory($this->templatesPath, $templateName);

            // 메모리에서 템플릿 제거
            unset($this->templates[$templateName]);
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
                    'status' => 'uninstalled',
                    'source' => 'active',
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
                    'status' => 'uninstalled',
                    'source' => 'pending',
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
                    'status' => 'uninstalled',
                    'source' => 'bundled',
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

                $installedTemplates[$identifier] = [
                    'identifier' => $template['identifier'],
                    'vendor' => $template['vendor'],
                    'name' => $this->getLocalizedValue($template['name'], $locale),
                    'version' => $record->version,
                    'type' => $template['type'],
                    'description' => $this->getLocalizedValue($template['description'] ?? '', $locale),
                    'dependencies' => $template['dependencies'] ?? [],
                    'status' => $record->status,
                    'update_available' => $updateAvailable,
                    'latest_version' => $latestVersion,
                    'file_version' => $fileVersion,
                    'update_source' => $record->update_source ?? null,
                    'github_url' => $template['github_url'] ?? ($record->github_url ?? null),
                    'github_changelog_url' => $record->github_changelog_url ?? ($template['github_changelog_url'] ?? null),
                    'user_modified_at' => $record->user_modified_at,
                    'created_at' => $record->created_at,
                    'updated_at' => $record->updated_at,
                ];
            }
        }

        return $installedTemplates;
    }

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

        return [
            'identifier' => $template['identifier'],
            'vendor' => $template['vendor'],
            'name' => $this->getLocalizedValue($template['name'], $locale),
            'version' => $template['version'],
            'latest_version' => $template['latest_version'] ?? null,
            'update_available' => $template['update_available'] ?? false,
            'type' => $template['type'],
            'description' => $this->getLocalizedValue($template['description'] ?? '', $locale),
            'github_url' => $template['github_url'] ?? null,
            'github_changelog_url' => $template['github_changelog_url'] ?? null,
            'dependencies' => $template['dependencies'] ?? [],
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
            'dependencies' => $metadata['dependencies'] ?? [],
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
        ];
    }

    /**
     * 템플릿의 에러 레이아웃 설정을 검증합니다.
     *
     * error_config 섹션 존재 확인, 필수 에러 코드(404, 403, 500) 레이아웃 정의 확인,
     * 정의된 레이아웃 파일 실제 존재 확인을 수행합니다.
     *
     * @param  string  $templateId  템플릿 식별자
     * @param  array  $templateData  template.json 데이터
     * @return bool 검증 성공 여부
     *
     * @throws \Exception 검증 실패 시
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

            // 레이아웃 이름 (숫자 또는 문자열 키)
            $layoutName = $errorLayouts[$code] ?? $errorLayouts[(string) $code];

            // 3. 레이아웃 파일 실제 존재 확인 (errors/ 디렉토리 내)
            $layoutFilePath = $templatePath.'/layouts/errors/'.$layoutName.'.json';
            if (! File::exists($layoutFilePath)) {
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

        foreach ($validatedLayouts as $validatedLayout) {
            try {
                $layoutFile = $validatedLayout['file'];
                $layoutData = $validatedLayout['data'];
                $layoutName = $validatedLayout['layout_name'];

                // DB에 레이아웃 등록 (updateOrCreate로 멱등성 보장)
                $this->layoutRepository->updateOrCreate(
                    [
                        'template_id' => $templateId,
                        'name' => $layoutName,
                    ],
                    [
                        'content' => $layoutData,
                        'original_content_hash' => $this->computeContentHash($layoutData),
                        'original_content_size' => $this->computeContentSize($layoutData),
                    ]
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
     * @param  string  $templateName  템플릿명 (identifier)
     * @param  int  $templateId  템플릿 DB ID
     */
    protected function registerLayoutOverrides(string $templateName, int $templateId): void
    {
        $overridesPath = base_path("templates/{$templateName}/layouts/overrides");

        if (! File::exists($overridesPath)) {
            Log::info(__('templates.info.no_overrides_directory'), ['template' => $templateName]);

            // overrides 디렉토리 없음 → 기존 override 레이아웃 모두 삭제
            $this->cleanupOrphanOverrideLayouts($templateId, $templateName, []);

            return;
        }

        // overrides 디렉토리 내 모듈별 하위 디렉토리 스캔
        $moduleDirectories = File::directories($overridesPath);

        if (empty($moduleDirectories)) {
            Log::info(__('templates.info.no_override_modules'), ['template' => $templateName]);

            // 모듈 디렉토리 없음 → 기존 override 레이아웃 모두 삭제
            $this->cleanupOrphanOverrideLayouts($templateId, $templateName, []);

            return;
        }

        $registeredCount = 0;
        $registeredLayoutNames = [];

        foreach ($moduleDirectories as $moduleDirectory) {
            $moduleIdentifier = basename($moduleDirectory);

            // 공통 Trait 메서드 사용하여 오버라이드 레이아웃 검증
            try {
                $validatedLayouts = $this->validateLayoutFiles($moduleDirectory, $moduleIdentifier, 'template', true);
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

                    // content 필드 구성
                    $content = $this->extractLayoutContent($layoutData);

                    // DB에 오버라이드 레이아웃 등록
                    $this->layoutRepository->updateOrCreate(
                        [
                            'template_id' => $templateId,
                            'name' => $layoutName,
                        ],
                        [
                            'content' => $content,
                            'extends' => $layoutData['extends'] ?? null,
                            'source_type' => LayoutSourceType::Template,
                            'source_identifier' => $templateName,
                            'created_by' => Auth::id(),
                            'updated_by' => Auth::id(),
                        ]
                    );

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

        // 파일에 없는 고아 override 레이아웃 정리
        $this->cleanupOrphanOverrideLayouts($templateId, $templateName, $registeredLayoutNames);

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
     * @param  int  $templateId  템플릿 DB ID
     * @param  string  $templateName  템플릿명 (identifier)
     * @param  array  $registeredLayoutNames  현재 파일에서 등록된 레이아웃 이름 목록
     */
    protected function cleanupOrphanOverrideLayouts(int $templateId, string $templateName, array $registeredLayoutNames): void
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
                    // 캐시 삭제 (레코드 삭제 전에 수행)
                    $cacheKey = "template.{$templateId}.layout.{$override->name}";
                    Cache::forget($cacheKey);

                    $sourceHash = md5($override->source_type?->value.$override->source_identifier);
                    $cacheKeyWithHash = "template.{$templateId}.layout.{$override->name}.{$sourceHash}";
                    Cache::forget($cacheKeyWithHash);

                    $override->forceDelete();
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
                // 기본 캐시 키 패턴으로 삭제
                $cacheKey = "template.{$templateId}.layout.{$layout->name}";
                Cache::forget($cacheKey);

                // sourceHash를 포함한 캐시 키도 삭제 (LayoutService의 캐시 키 패턴)
                $sourceHash = md5($layout->source_type?->value.$layout->source_identifier);
                $cacheKeyWithHash = "template.{$templateId}.layout.{$layout->name}.{$sourceHash}";
                Cache::forget($cacheKeyWithHash);
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
     * @param  string  $templateIdentifier  템플릿 식별자
     */
    protected function clearTemplateCache(string $templateIdentifier): void
    {
        // DB 기반 조회 — 파일 시스템 상태와 무관하게 캐시 삭제 보장
        // (파일 교체 중이거나 reloadTemplate() 전에도 캐시를 확실히 삭제)
        $templateRecord = $this->templateRepository->findByIdentifier($templateIdentifier);
        if (! $templateRecord) {
            return;
        }

        // 레이아웃 캐시 삭제 (버전 없는 내부 캐시)
        $this->clearLayoutCaches($templateIdentifier);

        // Routes/다국어 캐시는 버전 포함 키이므로 incrementExtensionCacheVersion() + TTL로 무효화됨

        Log::info(__('templates.info.cache_cleared'), [
            'template' => $templateIdentifier,
        ]);
    }

    /**
     * 템플릿의 모든 레이아웃 캐시를 삭제합니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     */
    protected function clearLayoutCaches(string $templateIdentifier): void
    {
        // 템플릿의 모든 레이아웃 조회
        $template = $this->templateRepository->findByIdentifier($templateIdentifier);
        if (! $template) {
            return;
        }

        $this->invalidateTemplateLayoutCache($template->id, $templateIdentifier);
    }

    /**
     * 템플릿의 주요 레이아웃을 미리 캐싱합니다 (캐시 워밍).
     *
     * @param  string  $templateIdentifier  템플릿 식별자
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

                Cache::remember($cacheKey, $cacheTtl, function () use ($templateIdentifier, $layoutName, $layoutService) {
                    return $layoutService->getLayout($templateIdentifier, $layoutName);
                });

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
                Cache::put($cacheKey, ['success' => true, 'data' => $result['data']], $cacheTtl);
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
                    Cache::remember($cacheKey, $cacheTtl, function () use ($templateIdentifier, $locale, $templateService) {
                        // TemplateService를 통해 $partial 해석 + 모듈/플러그인 다국어 병합
                        $result = $templateService->getLanguageDataWithModules($templateIdentifier, $locale);

                        if (! $result['success']) {
                            return ['error' => $result['error']];
                        }

                        return ['success' => true, 'data' => $result['data']];
                    });
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
     * 활성화된 템플릿의 레이아웃 파일을 다시 스캔하여
     * DB에 저장된 레이아웃을 최신 파일 내용으로 갱신합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{success: bool, layouts_refreshed: int} 갱신 결과 및 갱신된 레이아웃 개수
     *
     * @throws \Exception 템플릿을 찾을 수 없거나 레이아웃 갱신 실패 시
     */
    public function refreshTemplateLayouts(string $identifier, bool $preserveModified = false): array
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

        $layoutsPath = base_path("templates/{$identifier}/layouts");

        if (! File::exists($layoutsPath)) {
            Log::info(__('templates.info.no_layouts_directory'), ['template' => $identifier]);

            return ['success' => true, 'layouts_refreshed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];
        }

        // 레이아웃 검증
        try {
            $validatedLayouts = $this->validateLayoutFiles($layoutsPath, $identifier, 'template', true);
        } catch (\Exception $e) {
            Log::error(__('templates.errors.layout_registration_failed'), [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // 파일에서 가져온 레이아웃 이름 목록
        $fileLayoutNames = collect($validatedLayouts)->pluck('layout_name')->toArray();

        // DB에 있는 템플릿 기본 레이아웃 조회 (source_type = template, source_identifier = null)
        $existingLayouts = $this->layoutRepository->getByTemplateIdWithFilter(
            $template->id,
            'template',
            null
        )->keyBy('name');

        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => 0];

        // 파일 기반 레이아웃 동기화
        foreach ($validatedLayouts as $validatedLayout) {
            try {
                $layoutName = $validatedLayout['layout_name'];
                $layoutData = $validatedLayout['data'];
                $existingLayout = $existingLayouts->get($layoutName);

                if ($existingLayout) {
                    // 기존 레이아웃이 있는 경우 - 내용 비교 후 업데이트
                    $existingContent = is_string($existingLayout->content)
                        ? json_decode($existingLayout->content, true)
                        : $existingLayout->content;

                    if ($existingContent !== $layoutData) {
                        // 사용자 수정 감지: preserveModified 시 original_content_hash와 현재 content hash 비교
                        if ($preserveModified) {
                            $currentHash = $this->computeContentHash($existingContent);
                            $originalHash = $existingLayout->original_content_hash;

                            // hash가 다르면 사용자가 수정한 것 → 보존
                            if ($originalHash && $currentHash !== $originalHash) {
                                $stats['skipped']++;
                                Log::info("템플릿 레이아웃 보존 (사용자 수정): {$layoutName}", ['template' => $identifier]);

                                continue;
                            }
                        }

                        // 업데이트 + 새 원본 hash/size 저장
                        $this->layoutRepository->updateOrCreate(
                            ['template_id' => $template->id, 'name' => $layoutName],
                            [
                                'content' => $layoutData,
                                'original_content_hash' => $this->computeContentHash($layoutData),
                                'original_content_size' => $this->computeContentSize($layoutData),
                            ]
                        );
                        $stats['updated']++;
                        Log::info("템플릿 레이아웃 업데이트: {$layoutName}", ['template' => $identifier]);
                    } else {
                        // 내용이 같으면 그대로
                        $stats['unchanged']++;
                    }
                } else {
                    // DB에 없으면 새로 생성
                    $this->layoutRepository->updateOrCreate(
                        ['template_id' => $template->id, 'name' => $layoutName],
                        [
                            'content' => $layoutData,
                            'source_type' => 'template',
                            'original_content_hash' => $this->computeContentHash($layoutData),
                            'original_content_size' => $this->computeContentSize($layoutData),
                        ]
                    );
                    $stats['created']++;
                    Log::info("템플릿 레이아웃 생성: {$layoutName}", ['template' => $identifier]);
                }
            } catch (\Exception $e) {
                Log::error(__('templates.errors.layout_registration_failed'), [
                    'file' => $validatedLayout['file'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // DB에만 있고 파일에 없는 레이아웃 삭제
        foreach ($existingLayouts as $layoutName => $layout) {
            if (! in_array($layoutName, $fileLayoutNames)) {
                // preserveModified: 사용자가 수정한 레이아웃은 삭제하지 않음
                if ($preserveModified && $layout->original_content_hash) {
                    $currentContent = is_string($layout->content)
                        ? json_decode($layout->content, true)
                        : $layout->content;
                    $currentHash = $this->computeContentHash($currentContent);

                    if ($currentHash !== $layout->original_content_hash) {
                        $stats['skipped']++;
                        Log::info("템플릿 레이아웃 보존 (삭제 대상, 사용자 수정): {$layoutName}", ['template' => $identifier]);

                        continue;
                    }
                }

                $layout->forceDelete();
                $stats['deleted']++;
                Log::info("템플릿 레이아웃 삭제: {$layoutName}", ['template' => $identifier]);
            }
        }

        // 오버라이드 레이아웃 재등록
        $this->registerLayoutOverrides($identifier, $template->id);

        // Extension 오버라이드 재등록 (레이아웃 확장)
        $extensionStats = $this->registerExtensionOverrides($identifier, $template->id);

        // 캐시 무효화
        $this->clearTemplateCache($identifier);

        $totalRefreshed = $stats['created'] + $stats['updated'];

        // 레이아웃 또는 Extension 오버라이드가 변경된 경우에만 캐시 버전 증가
        $extensionChanged = ($extensionStats['created'] ?? 0) > 0 || ($extensionStats['updated'] ?? 0) > 0;
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
     * @return array{registered: int, created: int, updated: int} 등록 통계
     */
    protected function registerExtensionOverrides(string $templateIdentifier, int $templateId): array
    {
        $stats = ['registered' => 0, 'created' => 0, 'updated' => 0];
        $extensionsPath = base_path("templates/{$templateIdentifier}/extensions");

        if (! is_dir($extensionsPath)) {
            Log::debug('Extension 오버라이드 디렉토리 없음', ['template' => $templateIdentifier]);

            return $stats;
        }

        // extensions/{module-identifier}/ 폴더 순회
        $moduleDirectories = glob($extensionsPath.'/*', GLOB_ONLYDIR);

        if (empty($moduleDirectories)) {
            Log::debug('Extension 오버라이드 모듈 폴더 없음', ['template' => $templateIdentifier]);

            return $stats;
        }

        foreach ($moduleDirectories as $moduleDir) {
            $moduleIdentifier = basename($moduleDir);
            $overrideFiles = glob($moduleDir.'/*.json');

            foreach ($overrideFiles as $file) {
                try {
                    $jsonContent = file_get_contents($file);
                    $content = json_decode($jsonContent, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::warning('Extension 오버라이드 파일 JSON 파싱 실패', [
                            'file' => $file,
                            'error' => json_last_error_msg(),
                        ]);

                        continue;
                    }

                    // 템플릿 오버라이드로 등록
                    $result = $this->layoutExtensionService->registerTemplateOverride(
                        $content,
                        $templateIdentifier,
                        $moduleIdentifier,  // override_target = 오버라이드 대상 모듈
                        $templateId
                    );

                    $stats['registered']++;
                    if ($result === 'created') {
                        $stats['created']++;
                    } elseif ($result === 'updated') {
                        $stats['updated']++;
                    }

                } catch (\Exception $e) {
                    Log::error('Extension 오버라이드 등록 실패', [
                        'file' => $file,
                        'template' => $templateIdentifier,
                        'module' => $moduleIdentifier,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

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
            if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
                return null;
            }

            $owner = $matches[1];
            $repo = $matches[2];

            $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => GithubHelper::buildHeaders(config('app.update.github_token') ?? ''),
                    'timeout' => 5,
                ],
            ]);

            $response = @file_get_contents($apiUrl, false, $context);

            if ($response === false) {
                Log::warning("GitHub API 호출 실패: {$apiUrl}");

                return null;
            }

            $data = json_decode($response, true);

            if (isset($data['tag_name'])) {
                return ltrim($data['tag_name'], 'v');
            }

            return null;

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
     *
     * @throws \RuntimeException 소스를 찾을 수 없을 때
     */
    protected function copyToActiveFromSource(string $templateName, ?\Closure $onProgress = null): void
    {
        $targetPath = $this->templatesPath.DIRECTORY_SEPARATOR.$templateName;

        // _pending에서 찾기
        if (isset($this->pendingTemplates[$templateName]) || ExtensionPendingHelper::isPending($this->templatesPath, $templateName)) {
            $sourcePath = ExtensionPendingHelper::getPendingPath($this->templatesPath, $templateName);
            ExtensionPendingHelper::copyToActive($sourcePath, $targetPath, $onProgress);

            // 메모리 재로드
            $this->reloadTemplate($templateName);

            return;
        }

        // _bundled에서 찾기
        if (isset($this->bundledTemplates[$templateName]) || ExtensionPendingHelper::isBundled($this->templatesPath, $templateName)) {
            $sourcePath = ExtensionPendingHelper::getBundledPath($this->templatesPath, $templateName);
            ExtensionPendingHelper::copyToActive($sourcePath, $targetPath, $onProgress);

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
     * 우선순위: GitHub → _pending → _bundled
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{update_available: bool, update_source: string|null, latest_version: string|null, current_version: string|null}
     */
    public function checkTemplateUpdate(string $identifier): array
    {
        $record = $this->templateRepository->findByIdentifier($identifier);
        if (! $record) {
            return [
                'update_available' => false,
                'update_source' => null,
                'latest_version' => null,
                'current_version' => null,
            ];
        }

        $currentVersion = $record->version;
        $template = $this->getTemplate($identifier);

        // 1. GitHub URL이 있으면 GitHub에서 최신 버전 확인
        $githubUrl = $template['github_url'] ?? ($record->github_url ?? null);
        if ($githubUrl) {
            $latestVersion = $this->fetchLatestVersion($githubUrl);
            if ($latestVersion && version_compare($latestVersion, $currentVersion, '>')) {
                return [
                    'update_available' => true,
                    'update_source' => 'github',
                    'latest_version' => $latestVersion,
                    'current_version' => $currentVersion,
                ];
            }
        }

        // 2. _bundled에서 업데이트 확인
        if (isset($this->bundledTemplates[$identifier])) {
            $bundledVersion = $this->bundledTemplates[$identifier]['version'] ?? null;
            if ($bundledVersion && version_compare($bundledVersion, $currentVersion, '>')) {
                return [
                    'update_available' => true,
                    'update_source' => 'bundled',
                    'latest_version' => $bundledVersion,
                    'current_version' => $currentVersion,
                ];
            }
        } else {
            $bundledMeta = ExtensionPendingHelper::loadBundledExtensions($this->templatesPath, 'template.json');
            if (isset($bundledMeta[$identifier])) {
                $bundledVersion = $bundledMeta[$identifier]['version'] ?? null;
                if ($bundledVersion && version_compare($bundledVersion, $currentVersion, '>')) {
                    return [
                        'update_available' => true,
                        'update_source' => 'bundled',
                        'latest_version' => $bundledVersion,
                        'current_version' => $currentVersion,
                    ];
                }
            }
        }

        return [
            'update_available' => false,
            'update_source' => null,
            'latest_version' => $currentVersion,
            'current_version' => $currentVersion,
        ];
    }

    /**
     * 모든 설치된 템플릿의 업데이트를 확인합니다.
     *
     * @return array{updated_count: int, details: array}
     */
    public function checkAllTemplatesForUpdates(): array
    {
        $templateRecords = $this->templateRepository->getAllKeyedByIdentifier();
        $details = [];
        $updatedCount = 0;

        foreach ($templateRecords as $identifier => $record) {
            $result = $this->checkTemplateUpdate($identifier);

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
                    'current_version' => $result['current_version'],
                    'latest_version' => $result['latest_version'],
                    'update_source' => $result['update_source'],
                ];
            }
        }

        return [
            'updated_count' => $updatedCount,
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
            File::ensureDirectoryExists($tempDir);

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
     * 레이아웃 콘텐츠의 정규화된 SHA-256 해시를 계산합니다.
     *
     * 배열/JSON 문자열 모두 동일한 결과를 반환합니다.
     *
     * @param  array|string  $content  레이아웃 콘텐츠 (배열 또는 JSON 문자열)
     * @return string SHA-256 해시
     */
    private function computeContentHash(array|string $content): string
    {
        return hash('sha256', $this->normalizeContent($content));
    }

    /**
     * 레이아웃 콘텐츠의 정규화된 바이트 크기를 계산합니다.
     *
     * @param  array|string  $content  레이아웃 콘텐츠 (배열 또는 JSON 문자열)
     * @return int 바이트 크기
     */
    private function computeContentSize(array|string $content): int
    {
        return strlen($this->normalizeContent($content));
    }

    /**
     * 콘텐츠를 정규화된 JSON 문자열로 변환합니다.
     *
     * @param  array|string  $content  레이아웃 콘텐츠 (배열 또는 JSON 문자열)
     * @return string 정규화된 JSON 문자열
     */
    private function normalizeContent(array|string $content): string
    {
        return is_string($content)
            ? json_encode(json_decode($content, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 사용자가 수정한 레이아웃이 있는지 확인합니다.
     *
     * original_content_hash와 현재 DB content의 hash를 비교하여
     * 사용자가 관리자 UI에서 레이아웃을 수정한 적이 있는지 감지합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{has_modified_layouts: bool, modified_count: int, modified_layouts: array}
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

        $allLayouts = $this->layoutRepository->getByTemplateId($record->id);
        $modifiedLayouts = $allLayouts->filter(function ($layout) {
            if (! $layout->original_content_hash) {
                return false; // hash 없으면 (레거시 데이터) 미수정 취급
            }

            $currentContent = is_string($layout->content)
                ? json_decode($layout->content, true)
                : $layout->content;
            $currentHash = $this->computeContentHash($currentContent);

            return $currentHash !== $layout->original_content_hash;
        });

        return [
            'has_modified_layouts' => $modifiedLayouts->isNotEmpty(),
            'modified_count' => $modifiedLayouts->count(),
            'modified_layouts' => $modifiedLayouts->map(function ($layout) {
                $currentContent = is_string($layout->content)
                    ? json_decode($layout->content, true)
                    : $layout->content;
                $currentSize = $this->computeContentSize($currentContent);
                $originalSize = $layout->original_content_size ?? $currentSize;

                return [
                    'id' => $layout->id,
                    'name' => $layout->name,
                    'updated_at' => $layout->updated_at?->format('Y-m-d H:i:s'),
                    'size_diff' => $currentSize - $originalSize,
                ];
            })->values()->toArray(),
        ];
    }

    /**
     * 템플릿을 업데이트합니다.
     *
     * 프로세스: 백업 → updating 상태 → 파일 교체 → DB 갱신 →
     * 레이아웃 갱신 → 상태 복원 → 백업 삭제
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $layoutStrategy  레이아웃 전략 ('overwrite' 또는 'keep')
     * @param  bool  $force  버전 비교 없이 강제 업데이트
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @return array{success: bool, from_version: string|null, to_version: string|null, message: string}
     *
     * @throws \RuntimeException 업데이트 실패 시
     */
    public function updateTemplate(string $identifier, string $layoutStrategy = 'overwrite', bool $force = false, ?\Closure $onProgress = null): array
    {
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

        if (! $updateInfo['update_available'] && ! $force) {
            return [
                'success' => false,
                'from_version' => $fromVersion,
                'to_version' => $fromVersion,
                'message' => __('templates.no_update_available'),
            ];
        }

        // force 시 버전/소스 결정
        if ($force && ! $updateInfo['update_available']) {
            $toVersion = $updateInfo['current_version'];
            $updateSource = $this->resolveForceUpdateSource($identifier);
        } else {
            $toVersion = $updateInfo['latest_version'];
            $updateSource = $updateInfo['update_source'];
        }
        $backupPath = null;

        try {
            // 1. 백업 생성
            $onProgress?->__invoke('backup', '백업 생성 중...');
            $backupPath = ExtensionBackupHelper::createBackup('templates', $identifier, $onProgress);

            // 2. 상태 → updating
            $onProgress?->__invoke('status', '상태 변경 중...');
            $this->templateRepository->updateByIdentifier($identifier, [
                'status' => ExtensionStatus::Updating->value,
                'updated_at' => now(),
            ]);

            // 3. 스테이징 (소스에 따라 분기) — 템플릿은 composer install 불필요
            $onProgress?->__invoke('staging', '스테이징 중...');
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
                }

                // 4. 원자적 적용 (스테이징 → 활성 디렉토리)
                $onProgress?->__invoke('files', '파일 교체 중...');
                if ($stagingPath) {
                    $targetPath = $this->templatesPath.DIRECTORY_SEPARATOR.$identifier;
                    ExtensionPendingHelper::copyToActive($stagingPath, $targetPath, $onProgress);
                }
            } finally {
                // 스테이징 정리
                if ($stagingPath) {
                    ExtensionPendingHelper::cleanupStaging($stagingPath);
                }
            }

            // 템플릿 재로드 (새 파일로)
            $onProgress?->__invoke('reload', '재로드 중...');
            $this->reloadTemplate($identifier);
            $template = $this->getTemplate($identifier);

            // SEO 설정 검증 (업데이트 후 seo-config.json 유효성 검사)
            $this->validateSeoConfig($identifier);

            // 5. 트랜잭션: DB 정보 갱신
            $onProgress?->__invoke('db', 'DB 갱신 중...');
            DB::beginTransaction();
            try {
                $name = $template ? $this->convertToMultilingual($template['name']) : $record->name;
                $description = $template ? $this->convertToMultilingual($template['description'] ?? '') : $record->description;

                $this->templateRepository->updateByIdentifier($identifier, [
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
                ]);

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            // 6. 상태 복원 (refreshTemplateLayouts()가 active 상태를 요구하므로 먼저 복원)
            $onProgress?->__invoke('restore_status', '상태 복원 중...');
            $this->templateRepository->updateByIdentifier($identifier, [
                'status' => $previousStatus,
                'updated_at' => now(),
            ]);

            // 7. 레이아웃 갱신 (이전 상태가 active이고 overwrite 전략이면)
            // refreshTemplateLayouts()는 캐시 초기화 + 캐시 버전 증가를 포함
            $onProgress?->__invoke('layout', '레이아웃 갱신 중...');
            if ($previousStatus === ExtensionStatus::Active->value) {
                $preserveModified = ($layoutStrategy === 'keep');
                $this->refreshTemplateLayouts($identifier, $preserveModified);
            }

            // 8. 백업 삭제 + 캐시 삭제
            $onProgress?->__invoke('cleanup', '정리 중...');
            ExtensionBackupHelper::deleteBackup($backupPath);

            $this->clearAllTemplateLanguageCaches();
            $this->clearAllTemplateRoutesCaches();
            // refreshTemplateLayouts() 내부에서 변경 시 incrementExtensionCacheVersion() 호출됨
            // 비활성 템플릿이라 refreshTemplateLayouts()를 건너뛴 경우에만 여기서 증가
            if ($previousStatus !== ExtensionStatus::Active->value) {
                $this->incrementExtensionCacheVersion();
            }
            self::invalidateTemplateStatusCache();

            // 훅 발행: 템플릿 업데이트 완료 (Artisan 직접 호출 시에도 리스너 트리거)
            HookManager::doAction('core.templates.updated', $identifier);

            Log::info('템플릿 업데이트 완료', [
                'template' => $identifier,
                'from' => $fromVersion,
                'to' => $toVersion,
                'source' => $updateSource,
                'layout_strategy' => $layoutStrategy,
            ]);

            return [
                'success' => true,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'message' => __('templates.update_success', [
                    'template' => $identifier,
                    'version' => $toVersion,
                ]),
            ];

        } catch (\Throwable $e) {
            Log::error('템플릿 업데이트 실패', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            if ($backupPath) {
                try {
                    ExtensionBackupHelper::restoreFromBackup('templates', $identifier, $backupPath);
                    ExtensionBackupHelper::deleteBackup($backupPath);

                    $this->reloadTemplate($identifier);
                } catch (\Throwable $restoreError) {
                    Log::error('템플릿 백업 복원 실패', [
                        'template' => $identifier,
                        'error' => $restoreError->getMessage(),
                    ]);
                }
            }

            // 상태 복원
            $this->templateRepository->updateByIdentifier($identifier, [
                'status' => $previousStatus,
                'updated_at' => now(),
            ]);

            throw new \RuntimeException(
                __('templates.errors.update_failed', [
                    'template' => $identifier,
                    'error' => $e->getMessage(),
                ]),
                0,
                $e
            );
        }
    }

    /**
     * --force 시 업데이트 소스를 결정합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return string|null 업데이트 소스 ('bundled' 또는 null)
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

        return null;
    }
}
