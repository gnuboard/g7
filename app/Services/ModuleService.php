<?php

namespace App\Services;

use App\Exceptions\ModuleOperationException;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Helpers\ChangelogParser;
use App\Extension\Helpers\GithubHelper;
use App\Extension\Helpers\ZipInstallHelper;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Models\Module;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class ModuleService
{
    /**
     * 검색 가능한 필드 목록
     */
    private const SEARCHABLE_FIELDS = ['name', 'identifier', 'description', 'vendor'];

    public function __construct(
        private ModuleRepositoryInterface $moduleRepository,
        private ModuleManagerInterface $moduleManager
    ) {}

    /**
     * 모든 모듈 목록을 조회합니다 (설치된 모듈과 미설치 모듈 포함).
     *
     * @return array 모든 모듈 목록
     */
    public function getAllModules(): array
    {
        // ModuleManager 초기화
        $this->moduleManager->loadModules();

        // 설치된 모듈과 미설치 모듈을 분리하여 반환
        $installedModules = $this->moduleManager->getInstalledModulesWithDetails();
        $uninstalledModules = $this->moduleManager->getUninstalledModules();

        return [
            'installed' => array_values($installedModules),
            'uninstalled' => array_values($uninstalledModules),
            'total' => count($installedModules) + count($uninstalledModules),
        ];
    }

    /**
     * 설치된 모듈만 조회합니다.
     *
     * @return array 설치된 모듈 목록
     */
    public function getInstalledModulesOnly(): array
    {
        $this->moduleManager->loadModules();

        return array_values($this->moduleManager->getInstalledModulesWithDetails());
    }

    /**
     * 미설치 모듈만 조회합니다.
     *
     * @return array 미설치 모듈 목록
     */
    public function getUninstalledModulesOnly(): array
    {
        $this->moduleManager->loadModules();

        return array_values($this->moduleManager->getUninstalledModules());
    }

    /**
     * 특정 모듈의 상세 정보를 조회합니다.
     *
     * @param  string  $moduleName  모듈명
     * @return array|null 모듈 정보 또는 null
     */
    public function getModuleInfo(string $moduleName): ?array
    {
        $this->moduleManager->loadModules();

        return $this->moduleManager->getModuleInfo($moduleName);
    }

    /**
     * 모듈 삭제 시 삭제될 데이터 정보를 조회합니다.
     *
     * @param  string  $moduleName  모듈명
     * @return array|null 삭제 정보 (테이블 목록, 스토리지 디렉토리 목록, 용량) 또는 null
     */
    public function getModuleUninstallInfo(string $moduleName): ?array
    {
        $this->moduleManager->loadModules();

        return $this->moduleManager->getModuleUninstallInfo($moduleName);
    }

    /**
     * 설치된 모듈 목록을 조회합니다.
     *
     * @return \Illuminate\Database\Eloquent\Collection 설치된 모듈 목록
     */
    public function getInstalledModules()
    {
        return $this->moduleRepository->getInstalled();
    }

    /**
     * 마켓플레이스에서 이용 가능한 모듈 목록을 조회합니다.
     *
     * @return \Illuminate\Database\Eloquent\Collection 마켓플레이스 모듈 목록
     */
    public function getMarketplaceModules()
    {
        return $this->moduleRepository->getForMarketplace();
    }

    /**
     * 의존성 정보가 포함된 모든 모듈을 조회합니다.
     *
     * @return \Illuminate\Database\Eloquent\Collection 의존성 정보가 포함된 모듈 목록
     */
    public function getAllModulesWithDependencies()
    {
        return $this->moduleRepository->getAllWithDependencies();
    }

    /**
     * 슬러그를 사용하여 모듈을 조회합니다.
     *
     * @param  string  $slug  모듈 슬러그
     * @return Module|null 조회된 모듈 또는 null
     */
    public function getModuleBySlug(string $slug)
    {
        return $this->moduleRepository->findBySlug($slug);
    }

    /**
     * 설치된 모듈들의 업데이트 가능 여부를 확인합니다.
     *
     * @return array 업데이트 확인 결과 (updated_count, details)
     *
     * @throws ValidationException 업데이트 확인 실패 시
     */
    public function checkForUpdates(): array
    {
        HookManager::doAction('core.modules.before_check_updates');

        try {
            $this->moduleManager->loadModules();
            $result = $this->moduleManager->checkAllModulesForUpdates();

            HookManager::doAction('core.modules.after_check_updates', $result);

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'modules' => [__('modules.check_updates_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 지정된 모듈을 업데이트합니다.
     *
     * @param  string  $moduleName  업데이트할 모듈 identifier
     * @param  \App\Extension\Vendor\VendorMode  $vendorMode  Vendor 설치 모드
     * @param  string  $layoutStrategy  레이아웃 전략 (overwrite|keep)
     * @return array 업데이트 결과 (identifier, from_version, to_version 등)
     *
     * @param  bool  $force  코어 버전 비호환 강제 우회 (위험 — 사용자 명시 필요)
     *
     * @throws ValidationException 업데이트 실패 시
     */
    public function updateModule(
        string $moduleName,
        \App\Extension\Vendor\VendorMode $vendorMode = \App\Extension\Vendor\VendorMode::Auto,
        string $layoutStrategy = 'overwrite',
        bool $force = false,
    ): array {
        HookManager::doAction('core.modules.before_update', $moduleName);

        try {
            $this->moduleManager->loadModules();
            $result = $this->moduleManager->updateModule(
                $moduleName,
                $force,
                null,
                $vendorMode,
                $layoutStrategy,
            );

            $moduleInfo = $this->moduleManager->getModuleInfo($moduleName);

            HookManager::doAction('core.modules.after_update', $moduleName, $result, $moduleInfo);

            return array_merge($result, [
                'module_info' => $moduleInfo,
            ]);
        } catch (\Exception $e) {
            // Manager의 RuntimeException은 이미 번역된 메시지를 포함하므로
            // getPrevious()로 원본 에러를 추출하여 이중 래핑 방지
            $rawError = $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage();

            throw ValidationException::withMessages([
                'module_name' => [__('modules.errors.update_failed', ['module' => $moduleName, 'error' => $rawError])],
            ]);
        }
    }

    /**
     * 지정된 모듈의 수정된 레이아웃을 확인합니다.
     *
     * @param  string  $moduleName  확인할 모듈 identifier
     * @return array{has_modified_layouts: bool, modified_count: int, modified_layouts: array}
     *
     * @throws ValidationException 확인 실패 시
     */
    public function checkModifiedLayouts(string $moduleName): array
    {
        try {
            $this->moduleManager->loadModules();

            return $this->moduleManager->hasModifiedLayouts($moduleName);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'module_name' => [__('modules.check_modified_layouts_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 모듈을 시스템에 설치합니다.
     *
     * @param  string  $moduleName  설치할 모듈명
     * @param  \App\Extension\Vendor\VendorMode  $vendorMode  Vendor 설치 모드
     * @param  bool  $force  Updating/Failed 등 진행 중 상태도 무시하고 강제 설치 여부
     * @return array|null 설치된 모듈 정보 또는 null
     *
     * @throws ValidationException 모듈 설치 실패 시
     */
    public function installModule(
        string $moduleName,
        \App\Extension\Vendor\VendorMode $vendorMode = \App\Extension\Vendor\VendorMode::Auto,
        bool $force = false,
    ): ?array {
        HookManager::doAction('core.modules.before_install', $moduleName);

        try {
            $this->moduleManager->loadModules();
            $result = $this->moduleManager->installModule($moduleName, null, $vendorMode, $force);

            if ($result) {
                // 설치 후 모듈 정보 반환
                $moduleInfo = $this->moduleManager->getModuleInfo($moduleName);

                HookManager::doAction('core.modules.after_install', $moduleName, $moduleInfo);

                return $moduleInfo;
            }

            return null;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'module_name' => [__('modules.installation_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 모듈을 활성화합니다.
     *
     * @param  string  $moduleName  활성화할 모듈명
     * @param  bool  $force  필요 의존성이 충족되지 않아도 강제 활성화 여부
     * @return array 활성화 결과 (경고 포함 가능)
     *
     * @throws ValidationException 모듈 활성화 실패 시
     */
    public function activateModule(string $moduleName, bool $force = false): array
    {
        HookManager::doAction('core.modules.before_activate', $moduleName, $force);

        try {
            $this->moduleManager->loadModules();
            $result = $this->moduleManager->activateModule($moduleName, $force);

            // 경고 응답인 경우 그대로 반환
            if (isset($result['warning']) && $result['warning'] === true) {
                return $result;
            }

            if ($result['success']) {
                $moduleInfo = $this->moduleManager->getModuleInfo($moduleName);

                HookManager::doAction('core.modules.after_activate', $moduleName, $moduleInfo);

                return [
                    'success' => true,
                    'module_info' => $moduleInfo,
                ];
            }

            return ['success' => false];
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'module_name' => [__('modules.activation_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 모듈을 비활성화합니다.
     *
     * @param  string  $moduleName  비활성화할 모듈명
     * @param  bool  $force  의존 템플릿이 있어도 강제 비활성화 여부
     * @return array 비활성화 결과 (경고 포함 가능)
     *
     * @throws ValidationException 모듈 비활성화 실패 시
     */
    public function deactivateModule(string $moduleName, bool $force = false): array
    {
        HookManager::doAction('core.modules.before_deactivate', $moduleName, $force);

        try {
            $this->moduleManager->loadModules();
            $result = $this->moduleManager->deactivateModule($moduleName, $force);

            // 경고 응답인 경우 그대로 반환
            if (isset($result['warning']) && $result['warning'] === true) {
                return $result;
            }

            if ($result['success']) {
                $moduleInfo = $this->moduleManager->getModuleInfo($moduleName);

                // after_deactivate 훅은 ModuleManager 가 string identifier 시그니처로 이미 발화
                // (Service 중복 발화 제거 — listener 2회 실행으로 인한 activity log 중복 차단)

                return array_merge($result, ['module_info' => $moduleInfo]);
            }

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'module_name' => [__('modules.deactivation_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 모듈 제거
     *
     * @param  string  $moduleName  제거할 모듈명
     * @param  bool  $deleteData  모듈 데이터(테이블) 삭제 여부
     * @return bool 제거 성공 여부
     *
     * @throws ValidationException 모듈 제거 실패 시
     */
    public function uninstallModule(string $moduleName, bool $deleteData = false): bool
    {
        HookManager::doAction('core.modules.before_uninstall', $moduleName, $deleteData);

        try {
            // 제거 전 모듈 정보 보존
            $this->moduleManager->loadModules();
            $moduleInfo = $this->moduleManager->getModuleInfo($moduleName);

            $result = $this->moduleManager->uninstallModule($moduleName, $deleteData);

            if ($result) {
                $module = $this->moduleRepository->findByName($moduleName);
                if ($module) {
                    $this->moduleRepository->delete($module);
                }

                HookManager::doAction('core.modules.after_uninstall', $moduleName, $moduleInfo, $deleteData);
            }

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'module_name' => [__('modules.uninstallation_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 활성화된 모듈들의 ID 목록을 반환합니다.
     *
     * @return array 활성화된 모듈 ID 배열
     */
    public function getActiveModuleIds(): array
    {
        return $this->moduleRepository->getActiveModuleIds();
    }

    /**
     * 활성화된 모듈들의 identifier 목록을 반환합니다.
     *
     * @return array 활성화된 모듈 identifier 배열
     */
    public function getActiveModuleIdentifiers(): array
    {
        return $this->moduleRepository->getActiveModuleIdentifiers();
    }

    /**
     * 활성화된 모든 모듈의 커스텀 메뉴를 수집하여 반환합니다.
     *
     * @return array 모듈별 커스텀 메뉴 배열
     */
    public function getCustomMenusFromModules(): array
    {
        $this->moduleManager->loadModules();

        return $this->moduleManager->getCustomMenusFromModules();
    }

    /**
     * 권한 수준에 따라 모듈 인덱스 데이터를 반환합니다.
     *
     * core.modules.read 권한 보유 시 전체 모듈 목록을 반환하고,
     * 미보유 시(예: core.menus.read만 보유) 커스텀 메뉴 데이터만 반환합니다.
     *
     * @param  array  $validated  검증된 요청 데이터
     * @param  bool  $includeCustomMenus  커스텀 메뉴 포함 여부
     * @return array 권한에 따른 모듈 인덱스 데이터
     */
    public function getIndexData(array $validated, bool $includeCustomMenus): array
    {
        // core.modules.read 권한 미보유 시 최소 데이터만 반환
        if (! PermissionHelper::check('core.modules.read')) {
            $responseData = [
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 12,
                ],
            ];

            if ($includeCustomMenus) {
                $responseData['custom_menus'] = $this->getCustomMenusFromModules();
            }

            return $responseData;
        }

        // 전체 모듈 목록 반환
        $filters = [
            'search' => $validated['search'] ?? null,
            'filters' => $validated['filters'] ?? [],
            'status' => $validated['status'] ?? null,
            'include_hidden' => (bool) ($validated['include_hidden'] ?? false),
        ];
        $perPage = (int) ($validated['per_page'] ?? 12);
        $page = (int) ($validated['page'] ?? 1);

        $result = $this->getPaginatedModules($filters, $perPage, $page);

        $responseData = [
            'data' => $result['data'],
            'pagination' => [
                'total' => $result['total'],
                'current_page' => $result['current_page'],
                'last_page' => $result['last_page'],
                'per_page' => $result['per_page'],
            ],
        ];

        if ($includeCustomMenus) {
            $responseData['custom_menus'] = $this->getCustomMenusFromModules();
        }

        return $responseData;
    }

    /**
     * 페이지네이션 및 검색 필터가 적용된 모듈 목록을 조회합니다.
     *
     * @param  array  $filters  검색 필터 (search, filters, status)
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  현재 페이지
     * @return array 페이지네이션된 모듈 목록
     */
    public function getPaginatedModules(array $filters, int $perPage = 12, int $page = 1): array
    {
        $this->moduleManager->loadModules();

        // 모든 모듈 가져오기
        $installedModules = $this->moduleManager->getInstalledModulesWithDetails();
        $uninstalledModules = $this->moduleManager->getUninstalledModules();

        // 모든 모듈 합치기
        $allModules = array_merge(
            array_values($installedModules),
            array_values($uninstalledModules)
        );

        // 숨김 필터 적용 (기본: 숨김 항목 제외)
        $allModules = $this->applyHiddenFilter($allModules, (bool) ($filters['include_hidden'] ?? false));

        // 상태 필터 적용
        if (! empty($filters['status'])) {
            $allModules = $this->applyStatusFilter($allModules, $filters['status']);
        }

        // 다중 검색 필터 적용 (우선)
        if (! empty($filters['filters']) && is_array($filters['filters'])) {
            $allModules = $this->applyMultipleSearchFilters($allModules, $filters['filters']);
        }
        // 단일 검색어 필터 (하위 호환성)
        elseif (! empty($filters['search'])) {
            $allModules = $this->applyOrSearchAcrossFields($allModules, $filters['search']);
        }

        // 총 개수
        $total = count($allModules);

        // 페이지네이션 적용
        $offset = ($page - 1) * $perPage;
        $paginatedModules = array_slice($allModules, $offset, $perPage);

        return [
            'data' => array_values($paginatedModules),
            'total' => $total,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
            'per_page' => $perPage,
        ];
    }

    /**
     * 숨김 필터를 적용합니다.
     *
     * manifest 의 hidden=true 로 마킹된 모듈은 기본 제외되며,
     * $includeHidden=true 인 경우 포함됩니다.
     *
     * @param  array  $modules  모듈 목록
     * @param  bool  $includeHidden  숨김 항목 포함 여부
     * @return array 필터링된 모듈 목록
     */
    private function applyHiddenFilter(array $modules, bool $includeHidden): array
    {
        if ($includeHidden) {
            return $modules;
        }

        return array_filter($modules, function ($module) {
            return empty($module['hidden']);
        });
    }

    /**
     * 상태 필터를 적용합니다.
     *
     * @param  array  $modules  모듈 목록
     * @param  string  $status  상태 (installed, not_installed, active, inactive)
     * @return array 필터링된 모듈 목록
     */
    private function applyStatusFilter(array $modules, string $status): array
    {
        return array_filter($modules, function ($module) use ($status) {
            return match ($status) {
                'installed' => $module['status'] !== 'not_installed',
                'not_installed' => $module['status'] === 'not_installed',
                'active' => $module['status'] === 'active',
                'inactive' => $module['status'] === 'inactive',
                default => true,
            };
        });
    }

    /**
     * 다중 검색 조건을 적용합니다 (AND 조건).
     *
     * @param  array  $modules  모듈 목록
     * @param  array  $searchFilters  검색 필터 배열
     * @return array 필터링된 모듈 목록
     */
    private function applyMultipleSearchFilters(array $modules, array $searchFilters): array
    {
        if (empty($searchFilters)) {
            return $modules;
        }

        return array_filter($modules, function ($module) use ($searchFilters) {
            foreach ($searchFilters as $filter) {
                if (! $this->matchesFilter($module, $filter)) {
                    return false; // AND 조건: 하나라도 실패하면 제외
                }
            }

            return true;
        });
    }

    /**
     * 단일 필터 조건 매칭 여부를 확인합니다.
     *
     * @param  array  $module  모듈 정보
     * @param  array  $filter  필터 조건
     * @return bool 매칭 여부
     */
    private function matchesFilter(array $module, array $filter): bool
    {
        $field = $filter['field'] ?? null;
        $value = $filter['value'] ?? null;
        $operator = $filter['operator'] ?? 'like';

        if (! $field || ! $value || ! in_array($field, self::SEARCHABLE_FIELDS)) {
            return true; // 유효하지 않은 필터는 통과
        }

        // 필드 값 가져오기 (다국어 필드 처리)
        $fieldValue = $this->getFieldValue($module, $field);

        if ($fieldValue === null) {
            return false;
        }

        return match ($operator) {
            'eq' => mb_strtolower($fieldValue) === mb_strtolower($value),
            'starts_with' => str_starts_with(mb_strtolower($fieldValue), mb_strtolower($value)),
            'ends_with' => str_ends_with(mb_strtolower($fieldValue), mb_strtolower($value)),
            default => str_contains(mb_strtolower($fieldValue), mb_strtolower($value)), // like
        };
    }

    /**
     * 단일 검색어로 여러 필드를 OR 조건으로 검색합니다.
     *
     * @param  array  $modules  모듈 목록
     * @param  string  $searchTerm  검색어
     * @return array 필터링된 모듈 목록
     */
    private function applyOrSearchAcrossFields(array $modules, string $searchTerm): array
    {
        $searchTerm = mb_strtolower($searchTerm);

        return array_filter($modules, function ($module) use ($searchTerm) {
            foreach (self::SEARCHABLE_FIELDS as $field) {
                $fieldValue = $this->getFieldValue($module, $field);
                if ($fieldValue !== null && str_contains(mb_strtolower($fieldValue), $searchTerm)) {
                    return true; // OR 조건: 하나라도 매칭되면 포함
                }
            }

            return false;
        });
    }

    /**
     * 모듈에서 필드 값을 가져옵니다 (다국어 필드 처리 포함).
     *
     * @param  array  $module  모듈 정보
     * @param  string  $field  필드명
     * @return string|null 필드 값
     */
    private function getFieldValue(array $module, string $field): ?string
    {
        $value = $module[$field] ?? null;

        if ($value === null) {
            return null;
        }

        // 다국어 필드인 경우 (name, description)
        if (is_array($value)) {
            // 현재 로케일 우선, 없으면 ko, 그 다음 en
            $locale = app()->getLocale();

            return $value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? reset($value) ?: null;
        }

        return (string) $value;
    }

    /**
     * ZIP 파일에서 모듈을 설치합니다.
     *
     * @param  UploadedFile  $file  업로드된 ZIP 파일
     * @return array 설치된 모듈 정보
     *
     * @throws ValidationException 설치 실패 시
     */
    /**
     * 업로드된 ZIP 의 manifest 와 검증 결과만 추출합니다 (실제 설치 X).
     *
     * 사용자가 모듈 설치 전 module.json 검증 실패 사유를 미리 확인할 수 있게 합니다.
     * 언어팩의 `LanguagePackService::previewManifest` 와 동일 패턴.
     *
     * @param  UploadedFile  $file  업로드된 ZIP 파일
     * @return array{manifest: ?array<string, mixed>, validation: array<string, mixed>} 미리보기 결과
     */
    public function previewManifest(UploadedFile $file): array
    {
        $tempPath = storage_path('app/temp/modules');
        $extractPath = $tempPath.'/preview-'.uniqid('module_');
        $manifest = null;
        $errors = [];

        try {
            File::ensureDirectoryExists($tempPath);

            $result = ZipInstallHelper::extractAndValidate(
                $file->getRealPath(), $extractPath, 'module.json', 'modules'
            );

            $manifest = $result['config'];
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        } finally {
            if (File::exists($extractPath)) {
                File::deleteDirectory($extractPath);
            }
        }

        $existing = $manifest && ! empty($manifest['identifier'])
            ? $this->moduleRepository->findByIdentifier($manifest['identifier'])
            : null;

        return [
            'manifest' => $manifest,
            'validation' => [
                'errors' => $errors,
                'is_valid' => $errors === [] && $manifest !== null,
                'already_installed' => $existing !== null,
                'existing_version' => $existing?->version,
            ],
        ];
    }
    /**
     * 업로드된 ZIP 파일로부터 모듈을 추출/검증하고 _pending 으로 이동 후 설치합니다.
     *
     * `module.json` 검증 → identifier 충돌 검사 → _pending 이동 → 설치 파이프라인 진입.
     *
     * @param  UploadedFile  $file  사용자가 업로드한 모듈 ZIP 파일
     * @return array 설치 결과 (identifier/version/installed_at 포함)
     */
    public function installFromZipFile(UploadedFile $file): array
    {
        $tempPath = storage_path('app/temp/modules');
        $extractPath = $tempPath.'/'.uniqid('module_');

        try {
            File::ensureDirectoryExists($tempPath);

            $result = ZipInstallHelper::extractAndValidate(
                $file->getRealPath(), $extractPath, 'module.json', 'modules'
            );

            $this->ensureModuleNotInstalled($result['identifier']);

            ZipInstallHelper::moveToPending(
                $result['sourcePath'], base_path('modules/_pending'), $result['identifier']
            );

            try {
                return $this->executeModuleInstall($result['identifier']);
            } catch (\Throwable $e) {
                $pendingPath = base_path('modules/_pending/'.$result['identifier']);
                if (File::exists($pendingPath)) {
                    File::deleteDirectory($pendingPath);
                }
                throw $e;
            }
        } finally {
            if (File::exists($extractPath)) {
                File::deleteDirectory($extractPath);
            }
        }
    }

    /**
     * GitHub 저장소에서 모듈을 설치합니다.
     *
     * @param  string  $githubUrl  GitHub 저장소 URL
     * @return array 설치된 모듈 정보
     *
     * @throws \RuntimeException 설치 실패 시
     */
    public function installFromGithub(string $githubUrl): array
    {
        $tempPath = storage_path('app/temp/modules');
        $extractPath = $tempPath.'/'.uniqid('module_');
        $zipPath = null;

        try {
            File::ensureDirectoryExists($tempPath);

            [$owner, $repo] = GithubHelper::parseUrl($githubUrl);

            $token = config('app.update.github_token') ?? '';

            if (! GithubHelper::checkRepoExists($owner, $repo, $token)) {
                throw new ModuleOperationException('modules.errors.github_repo_not_found');
            }

            $zipPath = GithubHelper::downloadZip($owner, $repo, $tempPath, $token);

            $result = ZipInstallHelper::extractAndValidate(
                $zipPath, $extractPath, 'module.json', 'modules'
            );

            $this->ensureModuleNotInstalled($result['identifier']);

            ZipInstallHelper::moveToPending(
                $result['sourcePath'], base_path('modules/_pending'), $result['identifier']
            );

            try {
                return $this->executeModuleInstall($result['identifier']);
            } catch (\Throwable $e) {
                $pendingPath = base_path('modules/_pending/'.$result['identifier']);
                if (File::exists($pendingPath)) {
                    File::deleteDirectory($pendingPath);
                }
                throw $e;
            }
        } finally {
            if (File::exists($extractPath)) {
                File::deleteDirectory($extractPath);
            }
            if ($zipPath && File::exists($zipPath)) {
                File::delete($zipPath);
            }
        }
    }

    /**
     * 모듈이 이미 설치되어 있는지 확인합니다.
     *
     * _bundled/_pending에만 존재하는 경우(is_installed=false)는 설치 허용합니다.
     *
     * @param  string  $identifier  모듈 식별자
     *
     * @throws \RuntimeException 이미 설치된 경우
     */
    private function ensureModuleNotInstalled(string $identifier): void
    {
        $this->moduleManager->loadModules();
        $existingModule = $this->moduleManager->getModuleInfo($identifier);

        if ($existingModule && $existingModule['is_installed']) {
            throw new ModuleOperationException('modules.errors.already_installed');
        }
    }

    /**
     * _pending에서 모듈을 설치합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return array 설치된 모듈 정보
     *
     * @throws \RuntimeException 설치 실패 시
     */
    private function executeModuleInstall(string $identifier): array
    {
        $this->moduleManager->loadModules();
        $result = $this->moduleManager->installModule($identifier);

        if (! $result) {
            throw new ModuleOperationException('modules.errors.install_failed');
        }

        return $this->moduleManager->getModuleInfo($identifier);
    }

    /**
     * 모듈의 레이아웃을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * @param  string  $moduleName  모듈명
     * @return array|null 갱신 결과 또는 null
     *
     * @throws ValidationException 레이아웃 갱신 실패 시
     */
    public function refreshModuleLayouts(string $moduleName): ?array
    {
        HookManager::doAction('core.modules.before_refresh_layouts', $moduleName);

        try {
            $this->moduleManager->loadModules();
            $result = $this->moduleManager->refreshModuleLayouts($moduleName);

            if ($result['success']) {
                $moduleInfo = $this->moduleManager->getModuleInfo($moduleName);

                HookManager::doAction('core.modules.after_refresh_layouts', $moduleName, $result);

                return $moduleInfo;
            }

            return null;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'module_name' => [__('modules.refresh_layouts_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 모듈 에셋 파일 경로를 반환합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string  $path  파일 경로
     * @return array{success: bool, filePath: string|null, mimeType: string|null, error: string|null}
     */
    public function getAssetFilePath(string $identifier, string $path): array
    {
        // 1. 활성화된 모듈 확인
        $module = $this->moduleRepository->findByIdentifier($identifier);

        if (! $module || $module->status !== ExtensionStatus::Active->value) {
            return [
                'success' => false,
                'filePath' => null,
                'mimeType' => null,
                'error' => 'module_not_found',
            ];
        }

        // 2. 파일 경로 구성 (모듈 루트 기준)
        $filePath = base_path("modules/{$identifier}/{$path}");

        // 3. 파일 존재 확인
        if (! file_exists($filePath) || ! is_file($filePath)) {
            return [
                'success' => false,
                'filePath' => null,
                'mimeType' => null,
                'error' => 'file_not_found',
            ];
        }

        // 4. MIME 타입 감지
        $mimeType = $this->getMimeType($filePath);

        return [
            'success' => true,
            'filePath' => $filePath,
            'mimeType' => $mimeType,
            'error' => null,
        ];
    }

    /**
     * MIME 타입 감지
     *
     * @param  string  $filePath  파일 경로
     * @return string MIME 타입
     */
    private function getMimeType(string $filePath): string
    {
        $mimeTypes = [
            'js' => 'application/javascript',
            'mjs' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'map' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
        ];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * 모듈의 변경 내역(changelog)을 조회합니다.
     *
     * source가 'github'이면 GitHub에서 원격 CHANGELOG.md를 가져와 파싱합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string|null  $source  소스 ('active', 'bundled', 'github')
     * @param  string|null  $fromVersion  시작 버전 (초과)
     * @param  string|null  $toVersion  끝 버전 (이하)
     * @return array 변경 내역 배열
     */
    public function getModuleChangelog(string $identifier, ?string $source = null, ?string $fromVersion = null, ?string $toVersion = null): array
    {
        // GitHub 소스: 원격에서 CHANGELOG.md를 가져옴
        if ($source === 'github') {
            return $this->fetchRemoteChangelog($identifier, $fromVersion, $toVersion);
        }

        $basePath = base_path('modules');
        $filePath = ChangelogParser::resolveChangelogPath($basePath, $identifier, $source);

        if ($filePath === null) {
            return [];
        }

        if ($fromVersion !== null && $toVersion !== null) {
            return ChangelogParser::getVersionRange($filePath, $fromVersion, $toVersion);
        }

        return ChangelogParser::parse($filePath);
    }

    /**
     * GitHub에서 원격 CHANGELOG.md를 가져와 파싱합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string|null  $fromVersion  시작 버전 (초과)
     * @param  string|null  $toVersion  끝 버전 (이하)
     * @return array 변경 내역 배열
     */
    private function fetchRemoteChangelog(string $identifier, ?string $fromVersion = null, ?string $toVersion = null): array
    {
        $module = $this->moduleManager->getModule($identifier);

        if (! $module) {
            return [];
        }

        $githubUrl = $module->getGithubUrl();

        if (empty($githubUrl)) {
            // GitHub URL이 없으면 bundled 폴백
            return $this->getModuleChangelog($identifier, 'bundled', $fromVersion, $toVersion);
        }

        try {
            [$owner, $repo] = GithubHelper::parseUrl($githubUrl);
        } catch (\RuntimeException $e) {
            return $this->getModuleChangelog($identifier, 'bundled', $fromVersion, $toVersion);
        }

        // 최신 버전 태그로 CHANGELOG.md 가져오기
        $ref = $toVersion ?? 'main';
        $content = GithubHelper::fetchRawFile($owner, $repo, $ref, 'CHANGELOG.md');

        if ($content === null) {
            // 원격 실패 시 bundled 폴백
            return $this->getModuleChangelog($identifier, 'bundled', $fromVersion, $toVersion);
        }

        if ($fromVersion !== null && $toVersion !== null) {
            return ChangelogParser::getVersionRangeFromString($content, $fromVersion, $toVersion);
        }

        return ChangelogParser::parseFromString($content);
    }
}
