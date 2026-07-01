<?php

namespace App\Services;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Exceptions\TemplateNotFoundException;
use App\Exceptions\TemplateOperationException;
use App\Extension\Helpers\ChangelogParser;
use App\Extension\Helpers\GithubHelper;
use App\Extension\Helpers\ZipInstallHelper;
use App\Extension\HookManager;
use App\Extension\Traits\ResolvesLanguageFragments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TemplateService
{
    use ResolvesLanguageFragments;

    public function __construct(
        private TemplateRepositoryInterface $templateRepository,
        private TemplateManagerInterface $templateManager,
        private ModuleManagerInterface $moduleManager,
        private PluginManagerInterface $pluginManager,
        private LayoutVersionRepositoryInterface $layoutVersionRepository
    ) {
        // TemplateManager 초기화 (템플릿 스캔)
        $this->templateManager->loadTemplates();
    }

    /**
     * 검색 가능한 필드 목록
     */
    private const SEARCHABLE_FIELDS = ['name', 'identifier', 'description', 'vendor'];

    /**
     * 모든 템플릿 목록을 조회합니다 (설치된 템플릿과 미설치 템플릿 포함).
     *
     * @param  string|null  $type  템플릿 타입 (admin 또는 user)
     * @return array 모든 템플릿 목록
     */
    public function getAllTemplates(?string $type = null): array
    {
        HookManager::doAction('core.templates.before_list', $type);

        // 설치된 템플릿과 미설치 템플릿을 분리하여 반환
        $installedTemplates = $this->templateManager->getInstalledTemplatesWithDetails();
        $uninstalledTemplates = $this->templateManager->getUninstalledTemplates();

        // 타입 필터링
        if ($type) {
            $installedTemplates = array_filter($installedTemplates, fn ($t) => $t['type'] === $type);
            $uninstalledTemplates = array_filter($uninstalledTemplates, fn ($t) => $t['type'] === $type);
        }

        $result = [
            'installed' => array_values($installedTemplates),
            'uninstalled' => array_values($uninstalledTemplates),
            'total' => count($installedTemplates) + count($uninstalledTemplates),
        ];

        HookManager::doAction('core.templates.after_list', $result, $type);

        return $result;
    }

    /**
     * 페이지네이션 및 검색 필터가 적용된 템플릿 목록을 조회합니다.
     *
     * @param  array  $filters  검색 필터 (search, filters, status, type)
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  현재 페이지
     * @return array 페이지네이션된 템플릿 목록
     */
    public function getPaginatedTemplates(array $filters, int $perPage = 12, int $page = 1): array
    {
        HookManager::doAction('core.templates.before_list', $filters);

        // 템플릿 매니저 초기화
        $this->templateManager->loadTemplates();

        // 모든 템플릿 가져오기
        $installedTemplates = $this->templateManager->getInstalledTemplatesWithDetails();
        $uninstalledTemplates = $this->templateManager->getUninstalledTemplates();

        // 모든 템플릿 합치기
        $allTemplates = array_merge(
            array_values($installedTemplates),
            array_values($uninstalledTemplates)
        );

        // 타입 필터 적용
        if (! empty($filters['type'])) {
            $allTemplates = array_filter($allTemplates, fn ($t) => $t['type'] === $filters['type']);
            $allTemplates = array_values($allTemplates);
        }

        // 숨김 필터 적용 (기본: 숨김 항목 제외)
        $allTemplates = $this->applyHiddenFilter($allTemplates, (bool) ($filters['include_hidden'] ?? false));

        // 상태 필터 적용
        if (! empty($filters['status'])) {
            $allTemplates = $this->applyStatusFilter($allTemplates, $filters['status']);
        }

        // 다중 검색 필터 적용 (우선)
        if (! empty($filters['filters']) && is_array($filters['filters'])) {
            $allTemplates = $this->applyMultipleSearchFilters($allTemplates, $filters['filters']);
        }
        // 단일 검색어 필터 (하위 호환성)
        elseif (! empty($filters['search'])) {
            $allTemplates = $this->applyOrSearchAcrossFields($allTemplates, $filters['search']);
        }

        // 총 개수
        $total = count($allTemplates);

        // 페이지네이션 적용
        $offset = ($page - 1) * $perPage;
        $paginatedTemplates = array_slice($allTemplates, $offset, $perPage);

        $result = [
            'data' => array_values($paginatedTemplates),
            'total' => $total,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
            'per_page' => $perPage,
        ];

        HookManager::doAction('core.templates.after_list', $result, $filters);

        return $result;
    }

    /**
     * 숨김 필터를 적용합니다.
     *
     * manifest 의 hidden=true 로 마킹된 템플릿은 기본 제외되며,
     * $includeHidden=true 인 경우 포함됩니다.
     *
     * @param  array  $templates  템플릿 목록
     * @param  bool  $includeHidden  숨김 항목 포함 여부
     * @return array 필터링된 템플릿 목록
     */
    private function applyHiddenFilter(array $templates, bool $includeHidden): array
    {
        if ($includeHidden) {
            return $templates;
        }

        return array_filter($templates, function ($template) {
            return empty($template['hidden']);
        });
    }

    /**
     * 상태 필터를 적용합니다.
     *
     * @param  array  $templates  템플릿 목록
     * @param  string  $status  상태 (installed, not_installed, active, inactive)
     * @return array 필터링된 템플릿 목록
     */
    private function applyStatusFilter(array $templates, string $status): array
    {
        return array_filter($templates, function ($template) use ($status) {
            return match ($status) {
                'installed' => $template['status'] !== 'not_installed',
                'not_installed' => $template['status'] === 'not_installed',
                'active' => $template['status'] === 'active',
                'inactive' => $template['status'] === 'inactive',
                default => true,
            };
        });
    }

    /**
     * 다중 검색 조건을 적용합니다 (AND 조건).
     *
     * @param  array  $templates  템플릿 목록
     * @param  array  $searchFilters  검색 필터 배열
     * @return array 필터링된 템플릿 목록
     */
    private function applyMultipleSearchFilters(array $templates, array $searchFilters): array
    {
        if (empty($searchFilters)) {
            return $templates;
        }

        return array_filter($templates, function ($template) use ($searchFilters) {
            foreach ($searchFilters as $filter) {
                if (! $this->matchesFilter($template, $filter)) {
                    return false; // AND 조건: 하나라도 실패하면 제외
                }
            }

            return true;
        });
    }

    /**
     * 단일 필터 조건 매칭 여부를 확인합니다.
     *
     * @param  array  $template  템플릿 정보
     * @param  array  $filter  필터 조건
     * @return bool 매칭 여부
     */
    private function matchesFilter(array $template, array $filter): bool
    {
        $field = $filter['field'] ?? null;
        $value = $filter['value'] ?? null;
        $operator = $filter['operator'] ?? 'like';

        if (! $field || ! $value || ! in_array($field, self::SEARCHABLE_FIELDS)) {
            return true; // 유효하지 않은 필터는 통과
        }

        // 필드 값 가져오기 (다국어 필드 처리)
        $fieldValue = $this->getFieldValue($template, $field);

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
     * @param  array  $templates  템플릿 목록
     * @param  string  $searchTerm  검색어
     * @return array 필터링된 템플릿 목록
     */
    private function applyOrSearchAcrossFields(array $templates, string $searchTerm): array
    {
        $searchTerm = mb_strtolower($searchTerm);

        return array_filter($templates, function ($template) use ($searchTerm) {
            foreach (self::SEARCHABLE_FIELDS as $field) {
                $fieldValue = $this->getFieldValue($template, $field);
                if ($fieldValue !== null && str_contains(mb_strtolower($fieldValue), $searchTerm)) {
                    return true; // OR 조건: 하나라도 매칭되면 포함
                }
            }

            return false;
        });
    }

    /**
     * 템플릿에서 필드 값을 가져옵니다 (다국어 필드 처리 포함).
     *
     * @param  array  $template  템플릿 정보
     * @param  string  $field  필드명
     * @return string|null 필드 값
     */
    private function getFieldValue(array $template, string $field): ?string
    {
        $value = $template[$field] ?? null;

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
     * 설치된 템플릿만 조회합니다.
     *
     * @param  string|null  $type  템플릿 타입 (admin 또는 user)
     * @return array 설치된 템플릿 목록
     */
    public function getInstalledTemplatesOnly(?string $type = null): array
    {
        $templates = array_values($this->templateManager->getInstalledTemplatesWithDetails());

        if ($type) {
            $templates = array_filter($templates, fn ($t) => $t['type'] === $type);
            $templates = array_values($templates);
        }

        return $templates;
    }

    /**
     * 미설치 템플릿만 조회합니다.
     *
     * @param  string|null  $type  템플릿 타입 (admin 또는 user)
     * @return array 미설치 템플릿 목록
     */
    public function getUninstalledTemplatesOnly(?string $type = null): array
    {
        $templates = array_values($this->templateManager->getUninstalledTemplates());

        if ($type) {
            $templates = array_filter($templates, fn ($t) => $t['type'] === $type);
            $templates = array_values($templates);
        }

        return $templates;
    }

    /**
     * 특정 템플릿의 상세 정보를 조회합니다.
     *
     * @param  string  $identifier  템플릿 식별자 (vendor-name 형식)
     * @return array|null 템플릿 정보 또는 null
     */
    public function getTemplateInfo(string $identifier): ?array
    {
        return $this->templateManager->getTemplateInfo($identifier);
    }

    /**
     * ID로 템플릿 조회
     *
     * @param  int  $id  템플릿 ID
     * @return object|null 템플릿 모델 또는 null
     */
    public function getTemplateById(int $id): ?object
    {
        HookManager::doAction('core.templates.before_show', $id);

        $template = $this->templateRepository->findById($id);

        HookManager::doAction('core.templates.after_show', $template, $id);

        return $template;
    }

    /**
     * 식별자로 템플릿 조회
     *
     * @param  string  $identifier  템플릿 식별자 (vendor-name 형식)
     * @return object|null 템플릿 모델 또는 null
     */
    public function findByIdentifier(string $identifier): ?object
    {
        HookManager::doAction('core.templates.before_find_by_identifier', $identifier);

        $template = $this->templateRepository->findByIdentifier($identifier);

        HookManager::doAction('core.templates.after_find_by_identifier', $template, $identifier);

        return $template;
    }

    /**
     * 활성화된 템플릿 identifier 조회.
     *
     * @param  string  $type  템플릿 타입 ('admin' 또는 'user')
     * @return string 활성 템플릿 identifier
     *
     * @throws TemplateNotFoundException 활성화된 템플릿이 없을 때
     */
    public function getActiveTemplateIdentifier(string $type): string
    {
        $template = $this->templateRepository->findActiveByType($type);

        if (! $template) {
            throw new TemplateNotFoundException($type);
        }

        return $template->identifier;
    }

    /**
     * 템플릿을 설치합니다.
     *
     * @param  string  $identifier  설치할 템플릿 식별자
     * @param  bool  $force  활성 디렉토리가 있어도 원본으로 덮어쓰고 재설치
     * @return array|null 설치된 템플릿 정보 또는 null
     *
     * @throws ValidationException 설치 실패 시
     */
    public function installTemplate(string $identifier, bool $force = false): ?array
    {
        HookManager::doAction('core.templates.before_install', $identifier);

        try {
            $result = $this->templateManager->installTemplate($identifier, null, $force);

            if ($result) {
                $templateInfo = $this->templateManager->getTemplateInfo($identifier);

                HookManager::doAction('core.templates.after_install', $identifier, $templateInfo);

                return $templateInfo;
            }

            return null;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'identifier' => [__('templates.errors.installation_failed').': '.$e->getMessage()],
            ]);
        }
    }

    /**
     * 템플릿을 제거합니다.
     *
     * @param  string  $identifier  제거할 템플릿 식별자
     * @param  bool  $deleteData  템플릿 관련 데이터 삭제 여부
     * @return array|null 제거된 템플릿 정보 또는 null
     *
     * @throws ValidationException 제거 실패 시
     */
    public function uninstallTemplate(string $identifier, bool $deleteData = false): ?array
    {
        HookManager::doAction('core.templates.before_uninstall', $identifier, $deleteData);

        try {
            // 제거 전 템플릿 정보 보존
            $templateInfo = $this->templateManager->getTemplateInfo($identifier);

            $result = $this->templateManager->uninstallTemplate($identifier);

            if ($result) {
                HookManager::doAction('core.templates.after_uninstall', $identifier, $templateInfo, $deleteData);

                return $templateInfo;
            }

            return null;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'identifier' => [__('templates.errors.uninstallation_failed').': '.$e->getMessage()],
            ]);
        }
    }

    /**
     * 템플릿 삭제 시 삭제될 데이터 정보를 조회합니다.
     *
     * @param  string  $templateName  템플릿명
     * @return array|null 삭제 정보 배열 또는 null (템플릿 없음)
     */
    public function getTemplateUninstallInfo(string $templateName): ?array
    {
        $this->templateManager->loadTemplates();

        return $this->templateManager->getTemplateUninstallInfo($templateName);
    }

    /**
     * 템플릿을 비활성화합니다.
     *
     * @param  int|string  $idOrIdentifier  템플릿 ID 또는 식별자
     * @return array|null 비활성화된 템플릿 정보 또는 null
     *
     * @throws ValidationException 비활성화 실패 시
     */
    public function deactivateTemplate(int|string $idOrIdentifier): ?array
    {
        // ID 또는 identifier로 템플릿 조회
        $template = is_int($idOrIdentifier)
            ? $this->templateRepository->findById($idOrIdentifier)
            : $this->templateRepository->findByIdentifier($idOrIdentifier);

        if (! $template) {
            throw ValidationException::withMessages([
                'template' => [__('templates.errors.template_not_found', ['identifier' => $idOrIdentifier])],
            ]);
        }

        HookManager::doAction('core.templates.before_deactivate', $template->identifier);

        try {
            $result = $this->templateManager->deactivateTemplate($template->identifier);

            if ($result) {
                // 템플릿 매니저에서 업데이트된 정보 조회
                // (after_deactivate 훅은 TemplateManager 가 string identifier 시그니처로 이미 발화)
                $this->templateManager->loadTemplates();
                $templateInfo = $this->templateManager->getTemplateInfo($template->identifier);

                return $templateInfo;
            }

            return null;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'identifier' => [__('templates.errors.deactivation_failed').': '.$e->getMessage()],
            ]);
        }
    }

    /**
     * 템플릿을 활성화합니다.
     *
     * force 파라미터가 없고 필요한 의존성이 충족되지 않은 경우 경고를 반환합니다.
     *
     * @param  int|string  $idOrIdentifier  템플릿 ID 또는 식별자
     * @param  bool  $force  의존성 미충족 시에도 강제 활성화
     * @return array 활성화 결과 (성공 시 템플릿 정보, 경고 시 warning 배열)
     *
     * @throws ValidationException 활성화 실패 시
     */
    public function activateTemplate(int|string $idOrIdentifier, bool $force = false): array
    {
        // ID 또는 identifier로 템플릿 조회
        $template = is_int($idOrIdentifier)
            ? $this->templateRepository->findById($idOrIdentifier)
            : $this->templateRepository->findByIdentifier($idOrIdentifier);

        if (! $template) {
            throw ValidationException::withMessages([
                'template' => [__('templates.errors.template_not_found', ['identifier' => $idOrIdentifier])],
            ]);
        }

        HookManager::doAction('core.templates.before_activate', $template->identifier);

        try {
            // 필터 훅 - 활성화 데이터 변형
            $data = ['status' => ExtensionStatus::Active->value];
            $data = HookManager::applyFilters('core.templates.filter_activate_data', $data, $template);

            // TemplateManager에 활성화 로직 위임 (force 파라미터 전달)
            $result = $this->templateManager->activateTemplate($template->identifier, $force);

            // 경고 응답인 경우 그대로 반환
            if (isset($result['warning']) && $result['warning'] === true) {
                return $result;
            }

            // 템플릿 매니저에서 업데이트된 정보 조회
            $this->templateManager->loadTemplates();
            $templateInfo = $this->templateManager->getTemplateInfo($template->identifier);

            HookManager::doAction('core.templates.after_activate', $templateInfo);

            return [
                'success' => true,
                'template_info' => $templateInfo,
            ];
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'identifier' => [__('templates.errors.activation_failed').': '.$e->getMessage()],
            ]);
        }
    }

    /**
     * 템플릿 DB 레코드를 업데이트합니다 (before/after_update 훅 + filter_update_data 발화).
     *
     * @param  int  $id  템플릿 DB 레코드 ID
     * @param  array<string, mixed>  $data  업데이트할 필드 (display_name/description/settings 등)
     * @return object 갱신된 템플릿 레코드
     *
     * @throws TemplateOperationException 템플릿을 찾을 수 없을 때
     */
    public function updateTemplate(int $id, array $data): object
    {
        HookManager::doAction('core.templates.before_update', $id, $data);

        $template = $this->templateRepository->findById($id);

        if (! $template) {
            throw new TemplateOperationException('templates.not_found');
        }

        $data = HookManager::applyFilters('core.templates.filter_update_data', $data, $template);

        $updatedTemplate = $this->templateRepository->update($id, $data);

        HookManager::doAction('core.templates.after_update', $updatedTemplate, $data);

        return $updatedTemplate;
    }

    /**
     * 템플릿 DB 레코드를 삭제합니다 (before/after_delete 훅 발화).
     *
     * @param  int  $id  템플릿 DB 레코드 ID
     * @return bool 삭제 성공 여부
     *
     * @throws TemplateOperationException 템플릿을 찾을 수 없을 때
     */
    public function deleteTemplate(int $id): bool
    {
        HookManager::doAction('core.templates.before_delete', $id);

        $template = $this->templateRepository->findById($id);

        if (! $template) {
            throw new TemplateOperationException('templates.not_found');
        }

        $result = $this->templateRepository->delete($id);

        HookManager::doAction('core.templates.after_delete', $template, $result);

        return $result;
    }

    /**
     * 템플릿 정적 파일 경로 조회 및 검증
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $path  파일 경로
     * @return array{success: bool, filePath: string|null, mimeType: string|null, error: string|null}
     */
    public function getAssetFilePath(string $identifier, string $path): array
    {
        // 1. 활성화된 템플릿 확인
        $template = $this->templateRepository->findByIdentifier($identifier);

        if (! $template || $template->status !== ExtensionStatus::Active->value) {
            return [
                'success' => false,
                'filePath' => null,
                'mimeType' => null,
                'error' => 'template_not_found',
            ];
        }

        // 2. Path Traversal 방지
        $safePath = $this->sanitizePath($path);

        // 3. 파일 경로 구성
        $filePath = base_path("templates/{$identifier}/dist/{$safePath}");

        // 4. 파일 존재 확인
        if (! file_exists($filePath) || ! is_file($filePath)) {
            return [
                'success' => false,
                'filePath' => null,
                'mimeType' => null,
                'error' => 'file_not_found',
            ];
        }

        // 5. 보안 검증 (허용된 확장자만)
        if (! $this->isAllowedExtension($filePath)) {
            return [
                'success' => false,
                'filePath' => null,
                'mimeType' => null,
                'error' => 'file_type_not_allowed',
            ];
        }

        // 6. MIME 타입 감지
        $mimeType = $this->getMimeType($filePath);

        return [
            'success' => true,
            'filePath' => $filePath,
            'mimeType' => $mimeType,
            'error' => null,
        ];
    }

    /**
     * 컴포넌트 정의 파일 경로 조회 및 검증
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{success: bool, componentsPath: string|null, error: string|null}
     */
    public function getComponentsFilePath(string $identifier): array
    {
        // 1. 활성화된 템플릿 확인
        $template = $this->templateRepository->findByIdentifier($identifier);

        if (! $template || $template->status !== ExtensionStatus::Active->value) {
            return [
                'success' => false,
                'componentsPath' => null,
                'error' => 'template_not_found',
            ];
        }

        // 2. components.json 경로
        $componentsPath = base_path("templates/{$identifier}/components.json");

        // 3. 파일 존재 확인
        if (! file_exists($componentsPath)) {
            return [
                'success' => false,
                'componentsPath' => null,
                'error' => 'components_not_found',
            ];
        }

        return [
            'success' => true,
            'componentsPath' => $componentsPath,
            'error' => null,
        ];
    }

    /**
     * 템플릿의 다국어 파일 경로를 조회하고 검증합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $locale  로케일 (ko, en 등)
     * @return array{success: bool, langPath: string|null, error: string|null}
     */
    public function getLanguageFilePath(string $identifier, string $locale): array
    {
        // 1. 로케일 형식 검증 (ISO 639-1: 2자리 소문자)
        if (! preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)) {
            return [
                'success' => false,
                'langPath' => null,
                'error' => 'invalid_locale',
            ];
        }

        // 2. 템플릿 DB 조회 및 활성화 여부 확인
        $template = $this->templateRepository->findByIdentifier($identifier);
        if (! $template || $template->status !== ExtensionStatus::Active->value) {
            return [
                'success' => false,
                'langPath' => null,
                'error' => 'template_not_found',
            ];
        }

        // 3. template.json에서 locales 목록 확인
        $templateInfo = $this->getTemplateInfo($identifier);
        if (! $templateInfo) {
            return [
                'success' => false,
                'langPath' => null,
                'error' => 'template_not_found',
            ];
        }

        // 4. 요청된 로케일 검증 — 시스템 활성 로케일(언어팩 반영) 기준
        //    템플릿 자체 번역(`template.json` `locales`)에 없더라도, 활성 언어팩이
        //    번역을 제공할 수 있으므로 시스템 supported_locales 로 통과시킨다.
        $systemLocales = config('app.supported_locales', ['ko', 'en']);
        if (! in_array($locale, $systemLocales, true)) {
            return [
                'success' => false,
                'langPath' => null,
                'error' => 'locale_not_supported',
            ];
        }

        // 5. lang/{locale}.json 파일 존재 여부 확인
        $langPath = base_path("templates/{$identifier}/lang/{$locale}.json");

        // 6. Path Traversal 공격 방지
        $basePath = realpath(base_path("templates/{$identifier}/lang"));
        $realPath = realpath($langPath);

        if ($realPath !== false && ($basePath === false || ! str_starts_with($realPath, $basePath))) {
            return [
                'success' => false,
                'langPath' => null,
                'error' => 'file_not_found',
            ];
        }

        // 7. 템플릿이 자체 번역하지 않는 로케일(언어팩 전담)도 허용 — null 반환 시
        //    호출자가 빈 베이스에 언어팩 데이터를 병합하도록 한다.
        if (! file_exists($langPath)) {
            $templateLocales = $templateInfo['locales'] ?? [];
            if (! in_array($locale, $templateLocales, true)) {
                return [
                    'success' => true,
                    'langPath' => null,
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'langPath' => null,
                'error' => 'file_not_found',
            ];
        }

        // 7. 성공 시 파일 경로 반환
        return [
            'success' => true,
            'langPath' => $langPath,
            'error' => null,
        ];
    }

    /**
     * 템플릿 다국어 데이터를 활성화된 모듈의 다국어와 병합하여 반환합니다.
     *
     * $partial 디렉티브를 사용하여 분할된 다국어 파일들을 자동으로 병합합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $locale  로케일 (ko, en 등)
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function getLanguageDataWithModules(string $identifier, string $locale): array
    {
        // 1. 템플릿 다국어 파일 경로 조회
        $result = $this->getLanguageFilePath($identifier, $locale);

        if (! $result['success']) {
            return [
                'success' => false,
                'data' => null,
                'error' => $result['error'],
            ];
        }

        // 2. 템플릿 다국어 데이터 로드 (fragment 해석 포함)
        //    langPath 가 null 인 경우 템플릿이 해당 로케일을 자체 번역하지 않음 →
        //    빈 베이스에서 시작해 활성 언어팩 데이터로만 채운다.
        if ($result['langPath'] === null) {
            $templateLangData = [];
        } else {
            $templateLangData = $this->loadLanguageFileWithFragments($result['langPath']);

            if ($templateLangData === null) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'invalid_json',
                ];
            }
        }

        // 3. 활성화된 모듈들의 다국어 데이터 병합 (fragment 해석 포함)
        $moduleLangData = $this->loadActiveModulesLanguageData($locale);

        // 4. 활성화된 플러그인들의 다국어 데이터 병합 (fragment 해석 포함)
        $pluginLangData = $this->loadActivePluginsLanguageData($locale);

        // 5. 코어 자체의 프론트엔드 다국어 자원 로드 (베이스 레이어)
        //    어떤 템플릿이 부팅되든 코어 키(`core.*`) 가 자동 노출되도록
        //    가장 낮은 우선순위로 합류시킨다.
        $coreLangData = $this->loadCoreFrontendLanguageData($locale);

        // 6. 병합 순서: 코어 → 템플릿 → 모듈 → 플러그인 (코어가 가장 낮은 우선순위)
        //    모듈/플러그인은 식별자 wrap 으로 충돌 없음. 코어 키는 `core.*` prefix 컨벤션.
        //
        //  deep merge 사용: 동일 top-level 키(예: `layout_editor`) 의
        //    하위 트리를 재귀 병합한다. 과거 array_merge 는 shallow 라서 템플릿이
        //    `layout_editor.palette` 만 정의해도 코어의 `layout_editor.chrome` 등
        //    전체 트리가 통째로 교체되어 chrome/device/zoom 키가 미해석되었다.
        //    leaf 충돌 시 우선순위는 기존과 동일(코어 < 템플릿 < 모듈 < 플러그인).
        $mergedData = $this->deepMergeLanguageData(
            $coreLangData,
            $templateLangData,
            $moduleLangData,
            $pluginLangData
        );

        // 7. 활성 언어팩의 frontend/*.json 병합 (가장 높은 우선순위 — 코어/모듈/플러그인 모두 덮어쓸 수 있음)
        $mergedData = HookManager::applyFilters(
            'template.language.merge',
            $mergedData,
            $identifier,
            $locale
        );

        return [
            'success' => true,
            'data' => $mergedData,
            'error' => null,
        ];
    }

    /**
     * 코어/템플릿/모듈/플러그인 다국어 데이터를 재귀 병합합니다.
     *
     * PHP `array_merge` 는 shallow merge 라서 동일 top-level 키(예: `layout_editor`) 가
     * 양쪽에 있으면 뒤에 오는 쪽이 트리 전체를 통째로 교체한다. 본 helper 는 연관
     * 배열(assoc) 끼리는 재귀적으로 leaf 까지 내려가며 병합하고, 시퀀셜 배열(list)
     * 이나 scalar leaf 는 뒤에 오는 값으로 덮어쓴다.
     *
     * 우선순위(낮음 → 높음): 코어 < 템플릿 < 모듈 < 플러그인. 같은 키에 leaf 충돌
     * 시 뒤에 오는 입력 우선. 이 정책은 기존 `array_merge` 호출 순서와 동일.
     *
     * 시퀀셜 배열 판정: 키가 `0, 1, 2, ...` 연속 정수 → list. 그 외 → assoc.
     * lang JSON 은 본질적으로 assoc 트리(`{ "layout_editor": { "chrome": { ... } } }`)
     * 이고 leaf 가 string 인 게 일반적이라 list 케이스는 드물지만, 어떤 키가 검증
     * 룰 배열(`["required", "string"]`) 같은 list 를 leaf 로 둘 수 있어 명시적으로
     * 분기한다.
     *
     * @param  array  ...$layers  병합할 lang 배열들 (낮은 우선순위 → 높은 우선순위)
     * @return array 병합된 lang 데이터
     */
    private function deepMergeLanguageData(array ...$layers): array
    {
        $result = [];
        foreach ($layers as $layer) {
            $result = $this->deepMergeTwo($result, $layer);
        }

        return $result;
    }

    /**
     * 두 배열을 재귀 병합합니다 (deepMergeLanguageData 의 내부 helper).
     *
     * @param  array  $base  기존 누적 결과 (낮은 우선순위)
     * @param  array  $override  새로 합칠 입력 (높은 우선순위)
     * @return array 병합 결과
     */
    private function deepMergeTwo(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                array_key_exists($key, $base)
                && is_array($base[$key])
                && is_array($value)
                && $this->isAssocArray($base[$key])
                && $this->isAssocArray($value)
            ) {
                // 양쪽이 assoc 트리 → 재귀
                $base[$key] = $this->deepMergeTwo($base[$key], $value);
            } else {
                // list, scalar, 일방만 array → 덮어쓰기
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * 배열이 연관 배열(assoc) 인지 판정합니다.
     *
     * 빈 배열은 assoc 으로 취급해 양쪽이 빈 경우에도 재귀 호출이 안전하게 종료
     * 되도록 한다. 키가 `0, 1, 2, ...` 연속 정수 sequence 면 list 로 판정.
     *
     * @param  array  $arr  검사할 배열
     * @return bool assoc 여부
     */
    private function isAssocArray(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return ! array_is_list($arr);
    }

    /**
     * 코어 자체의 프론트엔드 다국어 자원을 로드합니다.
     *
     * 어떤 템플릿이 부팅되든 코어 키(`core.*`) 가 자동 노출되도록
     * 1단계(템플릿 lang) 앞에서 베이스 데이터로 로드됩니다.
     *
     * 모듈/플러그인/템플릿과 동일한 디렉토리 구조(`lang/{locale}.json` +
     * `lang/partial/`) 를 사용하며, $partial 디렉티브 해석도 동일하게 처리됩니다.
     *
     * @param  string  $locale  로케일 (ko, en 등)
     * @return array 코어 프론트엔드 다국어 데이터
     */
    private function loadCoreFrontendLanguageData(string $locale): array
    {
        $coreLangPath = base_path("lang/{$locale}.json");

        if (! file_exists($coreLangPath)) {
            return [];
        }

        $data = $this->loadLanguageFileWithFragments($coreLangPath);

        return is_array($data) ? $data : [];
    }

    /**
     * 다국어 파일을 로드하고 $partial 디렉티브를 해석합니다.
     *
     * @param  string  $langPath  다국어 파일 경로
     * @return array|null 해석된 다국어 데이터, 실패 시 null
     */
    private function loadLanguageFileWithFragments(string $langPath): ?array
    {
        if (! file_exists($langPath)) {
            return [];
        }

        $content = file_get_contents($langPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Fragment 해석 (basePath는 lang 디렉토리 루트, $partial 값에 fragments/ko/... 전체 경로 포함)
        $this->resetFragmentStack();
        $basePath = dirname($langPath);

        return $this->resolveLanguageFragments($data, $basePath);
    }

    /**
     * 활성화된 모든 모듈의 다국어 데이터를 로드합니다.
     *
     * $partial 디렉티브를 사용하여 분할된 다국어 파일들을 자동으로 병합합니다.
     *
     * @param  string  $locale  로케일 (ko, en 등)
     * @return array 모듈별로 식별자가 키인 다국어 데이터
     */
    private function loadActiveModulesLanguageData(string $locale): array
    {
        $langData = [];
        $activeModules = $this->moduleManager->getActiveModules();

        foreach ($activeModules as $module) {
            $moduleIdentifier = $module->getIdentifier();
            $langFilePath = base_path("modules/{$moduleIdentifier}/resources/lang/{$locale}.json");

            // 다국어 파일이 존재하는 경우에만 로드 (fragment 해석 포함)
            $data = $this->loadLanguageFileWithFragments($langFilePath);

            if ($data !== null && is_array($data) && ! empty($data)) {
                $langData[$moduleIdentifier] = $data;
            }
        }

        return $langData;
    }

    /**
     * 활성화된 모든 플러그인의 다국어 데이터를 로드합니다.
     *
     * $partial 디렉티브를 사용하여 분할된 다국어 파일들을 자동으로 병합합니다.
     *
     * @param  string  $locale  로케일 (ko, en 등)
     * @return array 플러그인별로 식별자가 키인 다국어 데이터
     */
    private function loadActivePluginsLanguageData(string $locale): array
    {
        $langData = [];
        $activePlugins = $this->pluginManager->getActivePlugins();

        foreach ($activePlugins as $plugin) {
            $pluginIdentifier = $plugin->getIdentifier();
            $langFilePath = base_path("plugins/{$pluginIdentifier}/resources/lang/{$locale}.json");

            // 다국어 파일이 존재하는 경우에만 로드 (fragment 해석 포함)
            $data = $this->loadLanguageFileWithFragments($langFilePath);

            if ($data !== null && is_array($data) && ! empty($data)) {
                $langData[$pluginIdentifier] = $data;
            }
        }

        return $langData;
    }

    /**
     * 템플릿 routes.json 데이터를 활성화된 모듈의 routes와 병합하여 반환합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function getRoutesDataWithModules(string $identifier): array
    {
        // 1. 템플릿 DB 조회 및 활성화 여부 확인
        $template = $this->templateRepository->findByIdentifier($identifier);
        if (! $template || $template->status !== ExtensionStatus::Active->value) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'template_not_found',
            ];
        }

        // 2. 템플릿 정보 조회
        $templateInfo = $this->getTemplateInfo($identifier);
        if (! $templateInfo) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'template_not_found',
            ];
        }

        // 3. 템플릿 routes.json 파일 경로
        $routesFilePath = base_path("templates/{$identifier}/routes.json");

        // 4. routes.json 파일이 없는 경우
        if (! file_exists($routesFilePath)) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'routes_not_found',
            ];
        }

        // 5. 템플릿 routes.json 데이터 로드
        $templateRoutesContent = file_get_contents($routesFilePath);
        $templateRoutesData = json_decode($templateRoutesContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'invalid_json',
            ];
        }

        // 6. 템플릿 타입 추출 (admin 또는 user)
        $templateType = $template->type;

        // 7. 템플릿 타입에 맞는 모듈 routes 데이터만 병합
        $moduleRoutes = $this->loadActiveModulesRoutesData($templateType);

        // 8. 플러그인 routes는 admin 템플릿에만 포함 (플러그인은 설정 페이지 등 admin 전용)
        $pluginRoutes = ($templateType === 'admin')
            ? $this->loadActivePluginsRoutesData()
            : [];

        // 9. 템플릿 자체 라우트에 source 태깅
        // 모듈/플러그인 라우트는 각 로더(loadActive*RoutesData)에서 이미 source 부여됨
        $templateRoutes = array_map(function ($route) {
            $route['source'] = ['kind' => 'template', 'identifier' => null];

            return $route;
        }, $templateRoutesData['routes'] ?? []);

        $mergedRoutes = array_merge(
            $templateRoutes,
            $moduleRoutes,
            $pluginRoutes
        );

        // 10. 시스템 라우트 주입 필터 — 코어/모듈/플러그인이 전역 라우트를 주입할 수 있는 확장점
        $mergedRoutes = HookManager::applyFilters(
            'core.routes.filter_merged',
            $mergedRoutes,
            $templateType,
            $identifier
        );

        // 11. 필터 단계에서 주입된 시스템 라우트(preview 등)에 source 메타 fallback 부여
        // (라우트 트리 그룹핑은 template/module/plugin 3종만 표시,
        // source.kind='core' 인 라우트는 트리에서 제외)
        $mergedRoutes = array_map(function ($route) {
            if (! isset($route['source'])) {
                $route['source'] = ['kind' => 'core', 'identifier' => null];
            }

            return $route;
        }, $mergedRoutes);

        // 12. 최종 데이터 구성
        $resultData = [
            'version' => $templateRoutesData['version'] ?? '1.0.0',
            'routes' => $mergedRoutes,
        ];

        return [
            'success' => true,
            'data' => $resultData,
            'error' => null,
        ];
    }

    /**
     * 레이아웃 편집기용 routes 데이터를 source 태깅과 함께 반환합니다.
     *
     * `getRoutesDataWithModules` 와 동일하게 템플릿/모듈/플러그인 라우트에 `source`
     * (`{kind, identifier}`)를 태깅하지만, 편집기 요구사항에 맞춰 두 가지가 다르다:
     *  - 활성/비활성 무관: 설치돼 있으나 비활성인 템플릿도 편집 가능해야 하므로
     *  활성 상태 가드를 적용하지 않는다.
     *  - `_bundled` 폴백: 활성 디렉토리에 routes.json 이 없으면 `_bundled` 원본을 읽는다.
     *
     * source 태깅이 없으면 편집기 `buildRouteTree`(useRouteTree)가 `route.source.kind`
     * 접근에서 throw 하여 라우트 트리 전체가 network 에러로 무너진다 — 본 메서드가
     * 편집기 진입 routes 응답의 SSoT 다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function getEditorRoutesDataWithModules(string $identifier): array
    {
        // 1. routes.json 경로 — 활성 디렉토리 우선, _bundled 폴백 (활성/비활성 무관).
        $candidates = [
            base_path("templates/{$identifier}/routes.json"),
            base_path("templates/_bundled/{$identifier}/routes.json"),
        ];
        $routesFilePath = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $routesFilePath = $candidate;
                break;
            }
        }
        if ($routesFilePath === null) {
            return ['success' => false, 'data' => null, 'error' => 'routes_not_found'];
        }

        // 2. routes.json 디코드
        $templateRoutesData = json_decode((string) file_get_contents($routesFilePath), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($templateRoutesData)) {
            return ['success' => false, 'data' => null, 'error' => 'invalid_json'];
        }

        // 3. 템플릿 타입(admin/user) — 모듈/플러그인 라우트 병합 분기에 사용.
        //    DB 모델(비활성 포함) → 매니페스트 info → 'user' 순으로 폴백.
        $template = $this->templateRepository->findByIdentifier($identifier);
        $templateType = $template->type
            ?? ($this->getTemplateInfo($identifier)['type'] ?? 'user');

        // 4. 템플릿 라우트에 source 태깅 (모듈/플러그인 로더는 자체 source 부여).
        $templateRoutes = array_map(function ($route) {
            $route['source'] = ['kind' => 'template', 'identifier' => null];

            return $route;
        }, $templateRoutesData['routes'] ?? []);

        $moduleRoutes = $this->loadActiveModulesRoutesData($templateType);
        $pluginRoutes = ($templateType === 'admin')
            ? $this->loadActivePluginsRoutesData()
            : [];

        $mergedRoutes = array_merge($templateRoutes, $moduleRoutes, $pluginRoutes);

        // 5. 시스템 라우트 주입 필터 (getRoutesDataWithModules 와 동일 확장점).
        $mergedRoutes = HookManager::applyFilters(
            'core.routes.filter_merged',
            $mergedRoutes,
            $templateType,
            $identifier
        );

        // 6. source 미부여 라우트(필터 주입 시스템 라우트 등)에 core fallback.
        $mergedRoutes = array_map(function ($route) {
            if (! isset($route['source'])) {
                $route['source'] = ['kind' => 'core', 'identifier' => null];
            }

            return $route;
        }, $mergedRoutes);

        // 7. base 레이아웃 + 인라인 모달 수집 — 위지윅
        //    편집기 라우트 트리의 `[공통 레이아웃]` · `[모달]` 그룹 SSoT. routes.json 에는
        //    base/modal 정보가 없으므로 레이아웃 파일에서 직접 수집한다. 모듈/플러그인
        //    수집은 라우트 로더와 동일하게 템플릿 타입(admin/user)으로 필터링한다.
        $baseAndModals = $this->collectEditorBaseAndModals($identifier, $templateType);

        // 8. 레이아웃별 현재(최신) 저장 버전 맵 — 라우트 트리 버전 배지.
        //    버전 이력이 있는 레이아웃만 포함(미저장 = 원본 → 배지 미표시). 템플릿이
        //    DB 에 없으면(파일만 존재) 버전 이력도 없으므로 빈 맵.
        $layoutVersions = $template
            ? $this->layoutVersionRepository->getCurrentVersionsByTemplateId($template->id)
            : [];

        return [
            'success' => true,
            'data' => [
                'version' => $templateRoutesData['version'] ?? '1.0.0',
                'routes' => $mergedRoutes,
                'base_layouts' => $baseAndModals['base_layouts'],
                'modals' => $baseAndModals['modals'],
                'layout_versions' => $layoutVersions,
            ],
            'error' => null,
        ];
    }

    /**
     * 편집기 라우트 트리의 `[공통 레이아웃]` · `[모달]` 그룹용 base 레이아웃 + 모달
     * 목록을 템플릿 + 활성 모듈/플러그인 레이아웃 파일에서 수집합니다.
     *
     * 다음 세 출처의 `layouts/` 를 재귀 순회해 수집한다(계획서 8.4.5 — "대상 템플릿의
     * 모든 레이아웃의 modals 섹션을 스캔"):
     *  - 템플릿: `templates/{id}/layouts/` (활성 → `_bundled` 폴백). host_layout 접두사 없음.
     *  - 활성 모듈: `modules/{id}/resources/layouts/`. host_layout 에 `{moduleId}.` 접두사.
     *  - 활성 플러그인: `plugins/{id}/resources/layouts/`. host_layout 에 `{pluginId}.` 접두사.
     *
     * 각 레이아웃에서:
     *  - `meta.is_base === true` → base 레이아웃 항목(`{layout_name, label}`).
     *  - `modals[]` 의 모달(인라인 정의 + `{"partial": "..."}` 참조 모두) → 모달 항목
     *    (`{modal_id, host_layout, label}`). partial 참조는 참조 파일을 읽어 id/title 추출.
     *
     * host_layout 접두사 규약은 라우트 트리 layout 규약(`loadActiveModulesRoutesData`
     * 의 `{moduleId}.{layout}`)과 일치시켜 트리에서 호스트 화면 노드와 매칭되게 한다.
     *
     * 모듈/플러그인 레이아웃은 라우트 로더(`routes/{admin|user}.json` 분기)와 동일하게
     * 템플릿 타입(admin/user)으로 필터링한다 — `layouts/{admin|user}/` 서브디렉토리만
     * 순회한다. 이를 누락하면 user 템플릿 편집기에 admin 레이아웃의 모달이(또는 그 반대로)
     * 새어 들어와 트리에 노출된다. 타입 서브디렉토리가 없는 출처는 라우트 로더와 동형으로
     * 건너뛴다(타입 미일치 레이아웃 비노출). 템플릿 자체 레이아웃은 그 템플릿 전용이므로
     * 타입 필터 없이 전부 수집한다.
     *
     * 라벨은 base 는 `meta.editor_label`/`layout_name`, 모달은 `modal.meta.editor_label`/
     * `modal.title`/`modal.id` 우선순위로 해석한다(편집기 클라이언트 라벨 규칙과 동형).
     * 활성/비활성 무관(편집은 비활성 템플릿도 허용), 파일 부재/파싱 실패는
     * 조용히 건너뛴다(라우트 그룹은 정상 표시).
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $templateType  템플릿 타입(`admin`/`user`) — 모듈/플러그인 레이아웃 타입 필터
     * @return array{base_layouts: array<int, array{layout_name: string, label: string|null}>, modals: array<int, array{modal_id: string, host_layout: string, label: string|null}>}
     */
    private function collectEditorBaseAndModals(string $identifier, string $templateType): array
    {
        $baseLayouts = [];
        $modals = [];

        // 1. 템플릿 레이아웃 (활성 → _bundled 폴백). host_layout 접두사 없음.
        //    템플릿 레이아웃은 해당 템플릿 전용이므로 타입 필터 없이 전부 수집한다.
        $templateDir = null;
        foreach ([
            base_path("templates/{$identifier}/layouts"),
            base_path("templates/_bundled/{$identifier}/layouts"),
        ] as $candidate) {
            if (File::isDirectory($candidate)) {
                $templateDir = $candidate;
                break;
            }
        }
        if ($templateDir !== null) {
            $collected = $this->collectBaseAndModalsFromLayoutDir($templateDir, '');
            $baseLayouts = array_merge($baseLayouts, $collected['base_layouts']);
            $modals = array_merge($modals, $collected['modals']);
        }

        // 2. 활성 모듈 레이아웃. host_layout 에 `{moduleId}.` 접두사(라우트 규약 동형).
        //    라우트 로더와 동일하게 `layouts/{templateType}/` 서브디렉토리만 순회한다.
        foreach ($this->moduleManager->getActiveModules() as $module) {
            $moduleId = $module->getIdentifier();
            $moduleDir = base_path("modules/{$moduleId}/resources/layouts/{$templateType}");
            if (! File::isDirectory($moduleDir)) {
                continue;
            }
            $collected = $this->collectBaseAndModalsFromLayoutDir($moduleDir, $moduleId.'.');
            $baseLayouts = array_merge($baseLayouts, $collected['base_layouts']);
            $modals = array_merge($modals, $collected['modals']);
        }

        // 3. 활성 플러그인 레이아웃. host_layout 에 `{pluginId}.` 접두사.
        //    플러그인 라우트는 admin 템플릿에만 포함되므로(라우트 로더 규약), 모달도
        //    admin 템플릿에서만 `layouts/{templateType}/` 서브디렉토리를 순회한다.
        if ($templateType === 'admin') {
            foreach ($this->pluginManager->getActivePlugins() as $plugin) {
                $pluginId = $plugin->getIdentifier();
                $pluginDir = base_path("plugins/{$pluginId}/resources/layouts/{$templateType}");
                if (! File::isDirectory($pluginDir)) {
                    continue;
                }
                $collected = $this->collectBaseAndModalsFromLayoutDir($pluginDir, $pluginId.'.');
                $baseLayouts = array_merge($baseLayouts, $collected['base_layouts']);
                $modals = array_merge($modals, $collected['modals']);
            }
        }

        return ['base_layouts' => $baseLayouts, 'modals' => $modals];
    }

    /**
     * 한 `layouts/` 디렉토리를 재귀 순회해 base 레이아웃 + 모달을 수집합니다.
     *
     * `collectEditorBaseAndModals` 의 출처별(템플릿/모듈/플러그인) 공통 수집 본체.
     * `partials/` 디렉토리는 모달 본체(partial)일 뿐 호스트 레이아웃이 아니므로 제외한다
     * — partial 모달은 각 호스트의 `modals[]` 참조로 수집된다.
     *
     * @param  string  $layoutDir  스캔할 layouts 디렉토리 절대 경로
     * @param  string  $hostPrefix  host_layout/layout_name 에 붙일 접두사(예 `sirsoft-ecommerce.`). 템플릿은 빈 문자열.
     * @return array{base_layouts: array<int, array{layout_name: string, label: string|null}>, modals: array<int, array{modal_id: string, host_layout: string, label: string|null}>}
     */
    private function collectBaseAndModalsFromLayoutDir(string $layoutDir, string $hostPrefix): array
    {
        $baseLayouts = [];
        $modals = [];

        // 라우트 레이아웃은 하위 디렉토리(`auth/`, `shop/`, `admin/` 등)에 있으므로 재귀 순회한다
        // (File::files 는 최상위만 읽어 하위 레이아웃의 모달을 누락한다).
        $partialsDirPrefix = $layoutDir.DIRECTORY_SEPARATOR.'partials';
        foreach (File::allFiles($layoutDir) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            if (str_starts_with($file->getPathname(), $partialsDirPrefix)) {
                continue;
            }

            $decoded = json_decode((string) File::get($file->getPathname()), true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                continue;
            }

            // layout_name 미선언 시 layouts 디렉토리 기준 상대 경로(확장자 제외)로 폴백한다
            // — 라우트 layoutName 규약(`auth/register`)과 일치시켜 host_layout 매칭을 보장.
            $relativeName = str_replace(
                ['\\', '.json'],
                ['/', ''],
                $file->getRelativePathname()
            );
            $layoutBaseName = is_string($decoded['layout_name'] ?? null) && $decoded['layout_name'] !== ''
                ? $decoded['layout_name']
                : $relativeName;
            // 모듈/플러그인은 라우트 트리 layout 규약(`{id}.{layout}`)과 동일하게 접두사를 붙인다.
            $layoutName = $hostPrefix.$layoutBaseName;

            // base 레이아웃
            if (($decoded['meta']['is_base'] ?? false) === true) {
                $baseLayouts[] = [
                    'layout_name' => $layoutName,
                    'label' => $this->resolveEditorTreeLabel($decoded['meta'] ?? [], null),
                ];
            }

            // 모달 수집 — 인라인 정의 + partial 참조 모두.
            //
            // 호스트 레이아웃의 `modals[]` 에 선언된 모달은 인라인/partial 여부와 무관하게
            // 동일한 모달이다(런타임/DB 에서는 partial 이 펼쳐져 호스트 content 의 완전한
            // 모달 노드로 저장된다). 편집기 트리에 노출돼야 호스트 하위 "이 화면의 모달"
            // 그룹·전체 `[모달]` 그룹에서 편집 가능하다. partial 참조는 그 파일을 읽어
            // id/title 을 추출한다.
            $layoutModals = $decoded['modals'] ?? null;
            if (is_array($layoutModals)) {
                foreach ($layoutModals as $modal) {
                    if (! is_array($modal)) {
                        continue;
                    }

                    // partial 참조 모달 → 참조 파일을 읽어 모달 노드를 끌어온다.
                    // partial 경로는 호스트 파일 디렉토리 기준(1순위) → layouts 루트(폴백)로 해석한다.
                    if (isset($modal['partial']) && is_string($modal['partial'])) {
                        $resolved = $this->resolveModalPartial($modal['partial'], $layoutDir, $file->getPath());
                        if ($resolved === null) {
                            continue;
                        }
                        $modal = $resolved;
                    }

                    $modalId = $modal['id'] ?? $modal['modal_id'] ?? null;
                    if (! is_string($modalId) || $modalId === '') {
                        continue;
                    }
                    // 라벨 fallback: 모달 노드 최상위 `title` → Modal 컴포넌트 `props.title`.
                    // G7 모달은 제목을 Modal 컴포넌트의 `props.title` 로 두는 것이 일반적이므로
                    // (최상위 `title` 키는 거의 쓰이지 않음), props.title 까지 fallback 으로 읽어야
                    // 편집기 트리에 modal_id 원문 대신 친화 제목(대개 `$t:` 키)이 표시된다.
                    $modalTitle = $modal['title']
                        ?? (is_array($modal['props'] ?? null) ? ($modal['props']['title'] ?? null) : null);
                    $modals[] = [
                        'modal_id' => $modalId,
                        'host_layout' => $layoutName,
                        'label' => $this->resolveEditorTreeLabel($modal['meta'] ?? [], is_string($modalTitle) ? $modalTitle : null),
                    ];
                }
            }
        }

        return ['base_layouts' => $baseLayouts, 'modals' => $modals];
    }

    /**
     * 호스트 레이아웃 `modals[]` 의 partial 참조를 실제 모달 노드로 해석합니다.
     *
     * 편집기 트리 모달 수집은 레이아웃 파일 raw 를 읽으므로, partial 참조 모달은 그 자체엔
     * `id`/`title` 이 없다(`{"partial": "..."}` 뿐). 참조 파일을 읽어 모달 노드를 끌어와
     * 인라인 모달과 동일하게 다룬다. 경로 규약은 레이아웃 partial 해석과 동형: 호스트 파일
     * 디렉토리 기준 상대 경로, `partials/` 시작 시 layouts 루트 폴백. layouts 디렉토리 밖
     * 참조는 거부(경로 traversal 방지). 파일 부재/파싱 실패는 null(조용히 건너뜀).
     *
     * @param  string  $partialPath  modals[].partial 값 (예: `partials/auth/_modal_terms.json`)
     * @param  string  $layoutDir  layouts 디렉토리 절대 경로(보안 경계 + 루트 폴백 기준)
     * @param  string  $hostFileDir  호스트 레이아웃 파일이 위치한 디렉토리 절대 경로(1순위 기준)
     * @return array<string, mixed>|null 해석된 모달 노드 배열 또는 null
     */
    private function resolveModalPartial(string $partialPath, string $layoutDir, string $hostFileDir): ?array
    {
        $layoutDirReal = realpath($layoutDir);
        if ($layoutDirReal === false) {
            return null;
        }
        $normalize = static fn (string $p): string => str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);

        // 경로 해석 규약(레이아웃 partial 과 동형): ① 호스트 파일 디렉토리 기준 →
        // ② layouts 루트 기준 폴백. 두 결합을 순서대로 시도해 먼저 존재하는 파일을 채택한다.
        //  - 호스트 기준: 예) layouts/admin/admin_ecommerce_order_detail.json 의
        //    `partials/.../x.json` → layouts/admin/partials/.../x.json
        //  - 루트 폴백: 예) layouts/auth/register.json 의 `partials/auth/x.json`
        //    → layouts/partials/auth/x.json
        $resolved = false;
        foreach ([$hostFileDir, $layoutDirReal] as $baseDir) {
            $candidate = realpath($baseDir.DIRECTORY_SEPARATOR.$partialPath);
            if ($candidate === false) {
                continue;
            }
            // 보안 — layouts 디렉토리 밖 참조 거부(경로 traversal 방지).
            if (! str_starts_with($normalize($candidate), $normalize($layoutDirReal))) {
                continue;
            }
            $resolved = $candidate;
            break;
        }

        if ($resolved === false) {
            return null;
        }

        $decoded = json_decode((string) File::get($resolved), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * 편집기 트리 노드 라벨을 해석합니다 (base/modal 공용).
     *
     * `meta.editor_label`(다국어 `$t:` 키 허용) → 전달된 fallback(모달 title 등) →
     * null 순으로 해석한다. 클라이언트(useRouteTree.resolveLabel)와 동일하게 `$t:` 키는
     * 그대로 전달해 프론트가 해석하도록 한다.
     *
     * @param  array  $meta  레이아웃/모달 meta 배열
     * @param  string|null  $fallback  meta.editor_label 부재 시 사용할 라벨(모달 title 등)
     * @return string|null 해석된 라벨 또는 null
     */
    private function resolveEditorTreeLabel(array $meta, ?string $fallback): ?string
    {
        $editorLabel = $meta['editor_label'] ?? null;
        if (is_string($editorLabel) && $editorLabel !== '') {
            return $editorLabel;
        }

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * 템플릿의 레이아웃 이름 → 라우트 path 매핑을 반환합니다.
     *
     * 코드 편집기가 파일 선택 시 `?route=` URL 동기화 / 위지윅 편집기에서 넘어온
     * `?route=` 로 해당 레이아웃을 복원하는 데 사용한다. path 내 `{{...}}` 표현식은
     * 위지윅 편집기(클라이언트 useEditorRoutes)와 동일하게 모듈 설정 기반으로 해석해
     * 양쪽 path 가 일치하도록 한다. redirect / layout 미지정 / 해석 불가 라우트는
     * 매핑에서 제외하며, 동일 레이아웃을 여러 라우트가 공유하면 먼저 선언된 path 를 채택한다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array<string, string> 레이아웃 이름 → 라우트 path
     */
    public function getLayoutRoutePathMap(string $identifier): array
    {
        $routesData = $this->getRoutesDataWithModules($identifier);
        $routes = $routesData['data']['routes'] ?? [];

        $map = [];
        foreach ($routes as $route) {
            $layout = $route['layout'] ?? null;
            $path = $route['path'] ?? null;
            if (! is_string($layout) || $layout === '' || ! is_string($path) || $path === '') {
                continue;
            }
            $resolvedPath = $this->resolveRoutePathExpressions($path);
            if ($resolvedPath === '') {
                continue;
            }
            if (! isset($map[$layout])) {
                $map[$layout] = $resolvedPath;
            }
        }

        return $map;
    }

    /**
     * 라우트 path 내 `{{...}}` 표현식을 위지윅 편집기와 동일하게 해석합니다.
     *
     * 위지윅 편집기는 클라이언트에서 `_global.modules` 컨텍스트로 path 표현식을 평가해
     * selectedRoute.path 를 만든다(useEditorRoutes.resolveEditorRouteExpressions).
     * 코드 편집기의 `?route=` 가 그 값과 매칭되려면 서버 맵도 동일하게 해석해야 한다.
     * 모듈 route_path 토글 패턴을 g7_module_settings 로 치환하고, 평가 불가한 표현식은
     * 클라이언트 fallback 과 동일하게 비운다.
     *
     * @param  string  $path  원본 라우트 path
     * @return string 해석된 path (선행 `*` 제거, 중복 슬래시 정규화)
     */
    private function resolveRoutePathExpressions(string $path): string
    {
        $path = ltrim($path, '*');

        $resolved = (string) preg_replace_callback('/\{\{(.+?)\}\}/', function (array $m): string {
            $expr = trim($m[1]);
            if (preg_match("/modules\?\.\['([^']+)'\]\?\.([\\w?.]+)\s*\?\?\s*'([^']+)'/", $expr, $mm)) {
                $moduleId = $mm[1];
                $settingKey = str_replace('?.', '.', $mm[2]);
                $default = $mm[3];
                if (preg_match('/\.no_route\s*\?/', $expr) && g7_module_settings($moduleId, 'basic_info.no_route')) {
                    return '';
                }

                return (string) (g7_module_settings($moduleId, $settingKey) ?? $default);
            }

            return '';
        }, $path);

        $resolved = (string) preg_replace('#/+#', '/', $resolved);
        if ($resolved !== '/') {
            $resolved = rtrim($resolved, '/');
        }

        return $resolved;
    }

    /**
     * 활성화된 모든 모듈의 routes 데이터를 템플릿 타입에 맞게 로드합니다.
     *
     * 새 구조(routes/admin.json, routes/user.json)를 우선 탐색하고,
     * 레거시 구조(routes.json)는 admin 타입에만 폴백으로 적용합니다.
     *
     * @param  string  $templateType  템플릿 타입 ('admin' 또는 'user')
     * @return array 모든 모듈의 routes 배열
     */
    private function loadActiveModulesRoutesData(string $templateType = 'admin'): array
    {
        $routes = [];
        $activeModules = $this->moduleManager->getActiveModules();

        foreach ($activeModules as $module) {
            $moduleIdentifier = $module->getIdentifier();

            // 새 구조: routes/{type}.json 우선
            $typedRoutesPath = base_path("modules/{$moduleIdentifier}/resources/routes/{$templateType}.json");

            // 레거시 구조: routes.json 폴백 (admin 타입에만 적용)
            $legacyRoutesPath = base_path("modules/{$moduleIdentifier}/resources/routes.json");

            $routesFilePath = null;

            if (file_exists($typedRoutesPath)) {
                $routesFilePath = $typedRoutesPath;
            } elseif ($templateType === 'admin' && file_exists($legacyRoutesPath)) {
                $routesFilePath = $legacyRoutesPath;
                Log::warning('모듈 routes.json이 레거시 위치에 있습니다. routes/admin.json으로 이동하세요.', [
                    'module' => $moduleIdentifier,
                    'path' => $legacyRoutesPath,
                ]);
            }

            if ($routesFilePath === null) {
                continue;
            }

            $content = file_get_contents($routesFilePath);
            $data = json_decode($content, true);

            // JSON 파싱 성공 시 routes 배열 병합
            if (json_last_error() === JSON_ERROR_NONE && isset($data['routes']) && is_array($data['routes'])) {
                // 모듈 routes의 layout 필드에 moduleIdentifier 접두사 추가 + source 태깅
                $moduleRoutes = array_map(function ($route) use ($moduleIdentifier) {
                    if (isset($route['layout'])) {
                        $route['layout'] = $moduleIdentifier.'.'.$route['layout'];
                    }
                    $route['source'] = ['kind' => 'module', 'identifier' => $moduleIdentifier];

                    return $route;
                }, $data['routes']);

                $routes = array_merge($routes, $moduleRoutes);
            }
        }

        return $routes;
    }

    /**
     * 활성화된 모든 플러그인의 routes 데이터를 로드합니다.
     *
     * @return array 모든 플러그인의 routes 배열
     */
    private function loadActivePluginsRoutesData(): array
    {
        $routes = [];
        $activePlugins = $this->pluginManager->getActivePlugins();

        foreach ($activePlugins as $plugin) {
            $pluginIdentifier = $plugin->getIdentifier();
            $routesFilePath = base_path("plugins/{$pluginIdentifier}/resources/routes.json");

            // routes.json 파일이 존재하는 경우에만 로드
            if (file_exists($routesFilePath)) {
                $content = file_get_contents($routesFilePath);
                $data = json_decode($content, true);

                // JSON 파싱 성공 시 routes 배열 병합
                if (json_last_error() === JSON_ERROR_NONE && isset($data['routes']) && is_array($data['routes'])) {
                    // 플러그인 routes의 layout 필드에 pluginIdentifier 접두사 추가 + source 태깅
                    $pluginRoutes = array_map(function ($route) use ($pluginIdentifier) {
                        if (isset($route['layout'])) {
                            $route['layout'] = $pluginIdentifier.'.'.$route['layout'];
                        }
                        $route['source'] = ['kind' => 'plugin', 'identifier' => $pluginIdentifier];

                        return $route;
                    }, $data['routes']);

                    $routes = array_merge($routes, $pluginRoutes);
                }
            }

            // 설정 페이지가 있는 플러그인은 자동으로 설정 라우트 생성
            if ($plugin->hasSettings()) {
                $routes[] = [
                    'path' => '*/admin/plugins/'.$pluginIdentifier.'/settings',
                    'layout' => $pluginIdentifier.'.plugin_settings',
                    'auth_required' => true,
                    'params' => [
                        'identifier' => $pluginIdentifier,
                    ],
                    'meta' => [
                        'title' => '$t:'.$pluginIdentifier.'.settings.title',
                        'permission' => 'core.plugins.read',
                    ],
                    'source' => ['kind' => 'plugin', 'identifier' => $pluginIdentifier],
                ];
            }
        }

        return $routes;
    }

    /**
     * Path Traversal 방지를 위한 경로 정제
     */
    private function sanitizePath(string $path): string
    {
        // ../ 및 ..\ 패턴 제거
        $path = str_replace(['../', '..\\'], '', $path);

        // 절대 경로 방지
        $path = ltrim($path, '/\\');

        return $path;
    }

    /**
     * 허용된 파일 확장자 확인
     */
    private function isAllowedExtension(string $filePath): bool
    {
        $allowedExtensions = [
            'js', 'mjs', 'css', 'json',
            'png', 'jpg', 'jpeg', 'svg', 'webp', 'gif',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
        ];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return in_array($extension, $allowedExtensions);
    }

    /**
     * MIME 타입 감지
     */
    private function getMimeType(string $filePath): string
    {
        $mimeTypes = [
            'js' => 'application/javascript',
            'mjs' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
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
     * ZIP 파일에서 템플릿을 설치합니다.
     *
     * @param  UploadedFile  $file  업로드된 ZIP 파일
     * @return array 설치된 템플릿 정보
     *
     * @throws \RuntimeException 설치 실패 시
     */
    /**
     * 업로드된 ZIP 의 manifest 와 검증 결과만 추출합니다 (실제 설치 X).
     *
     * 사용자가 템플릿 설치 전 template.json 검증 실패 사유를 미리 확인할 수 있게 합니다.
     *
     * @param  UploadedFile  $file  업로드된 ZIP 파일
     * @return array{manifest: ?array<string, mixed>, validation: array<string, mixed>} 미리보기 결과
     */
    public function previewManifest(UploadedFile $file): array
    {
        $tempPath = storage_path('app/temp/templates');
        $extractPath = $tempPath.'/preview-'.uniqid('template_');
        $manifest = null;
        $errors = [];

        try {
            File::ensureDirectoryExists($tempPath);

            $result = ZipInstallHelper::extractAndValidate(
                $file->getRealPath(), $extractPath, 'template.json', 'templates'
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
            ? $this->templateRepository->findByIdentifier($manifest['identifier'])
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
     * 업로드된 ZIP 파일로부터 템플릿을 추출/검증하고 _pending 으로 이동 후 설치합니다.
     *
     * `template.json` 검증 → identifier 충돌 검사 → _pending 이동 → 설치 파이프라인 진입.
     *
     * @param  UploadedFile  $file  사용자가 업로드한 템플릿 ZIP 파일
     * @return array 설치 결과 (identifier/version/installed_at 포함)
     */
    public function installFromZipFile(UploadedFile $file): array
    {
        $tempPath = storage_path('app/temp/templates');
        $extractPath = $tempPath.'/'.uniqid('template_');

        try {
            File::ensureDirectoryExists($tempPath);

            $result = ZipInstallHelper::extractAndValidate(
                $file->getRealPath(), $extractPath, 'template.json', 'templates'
            );

            $this->ensureTemplateNotInstalled($result['identifier']);

            ZipInstallHelper::moveToPending(
                $result['sourcePath'], base_path('templates/_pending'), $result['identifier']
            );

            try {
                return $this->executeTemplateInstall($result['identifier']);
            } catch (\Throwable $e) {
                $pendingPath = base_path('templates/_pending/'.$result['identifier']);
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
     * GitHub 저장소에서 템플릿을 설치합니다.
     *
     * @param  string  $githubUrl  GitHub 저장소 URL
     * @return array 설치된 템플릿 정보
     *
     * @throws \RuntimeException 설치 실패 시
     */
    public function installFromGithub(string $githubUrl): array
    {
        $tempPath = storage_path('app/temp/templates');
        $extractPath = $tempPath.'/'.uniqid('template_');
        $zipPath = null;

        try {
            File::ensureDirectoryExists($tempPath);

            [$owner, $repo] = GithubHelper::parseUrl($githubUrl);

            $token = config('app.update.github_token') ?? '';

            if (! GithubHelper::checkRepoExists($owner, $repo, $token)) {
                throw new TemplateOperationException('templates.errors.github_repo_not_found');
            }

            $zipPath = GithubHelper::downloadZip($owner, $repo, $tempPath, $token);

            $result = ZipInstallHelper::extractAndValidate(
                $zipPath, $extractPath, 'template.json', 'templates'
            );

            $this->ensureTemplateNotInstalled($result['identifier']);

            ZipInstallHelper::moveToPending(
                $result['sourcePath'], base_path('templates/_pending'), $result['identifier']
            );

            try {
                return $this->executeTemplateInstall($result['identifier']);
            } catch (\Throwable $e) {
                $pendingPath = base_path('templates/_pending/'.$result['identifier']);
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
     * 템플릿이 이미 설치되어 있는지 확인합니다.
     *
     * _bundled/_pending에만 존재하는 경우(is_installed=false)는 설치 허용합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     *
     * @throws \RuntimeException 이미 설치된 경우
     */
    private function ensureTemplateNotInstalled(string $identifier): void
    {
        $this->templateManager->loadTemplates();
        $existingTemplate = $this->templateManager->getTemplateInfo($identifier);

        if ($existingTemplate && $existingTemplate['is_installed']) {
            throw new TemplateOperationException('templates.errors.already_installed');
        }
    }

    /**
     * _pending에서 템플릿을 설치합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array 설치된 템플릿 정보
     *
     * @throws \RuntimeException 설치 실패 시
     */
    private function executeTemplateInstall(string $identifier): array
    {
        $this->templateManager->loadTemplates();
        $result = $this->templateManager->installTemplate($identifier);

        if (! $result) {
            throw new TemplateOperationException('templates.errors.install_failed');
        }

        return $this->templateManager->getTemplateInfo($identifier);
    }

    /**
     * 템플릿의 컴포넌트 목록을 조회합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{basic: array, composite: array} 컴포넌트 목록
     */
    public function getTemplateComponents(string $identifier): array
    {
        $componentsPath = base_path("templates/{$identifier}/components.json");

        if (! File::exists($componentsPath)) {
            return ['basic' => [], 'composite' => []];
        }

        $content = File::get($componentsPath);
        $components = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['basic' => [], 'composite' => []];
        }

        $basic = [];
        $composite = [];

        foreach ($components['components'] ?? [] as $component) {
            $name = $component['name'] ?? '';
            $type = $component['type'] ?? 'basic';

            if ($type === 'basic') {
                $basic[] = $name;
            } elseif ($type === 'composite') {
                $composite[] = $name;
            }
        }

        return [
            'basic' => $basic,
            'composite' => $composite,
        ];
    }

    /**
     * 템플릿의 레이아웃을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array|null 갱신 결과 또는 null
     *
     * @throws ValidationException 레이아웃 갱신 실패 시
     */
    public function refreshTemplateLayouts(string $identifier): ?array
    {
        HookManager::doAction('core.templates.before_refresh_layouts', $identifier);

        try {
            $this->templateManager->loadTemplates();
            $result = $this->templateManager->refreshTemplateLayouts($identifier);

            if ($result['success']) {
                $templateInfo = $this->templateManager->getTemplateInfo($identifier);

                HookManager::doAction('core.templates.after_refresh_layouts', $identifier, $result);

                return $templateInfo;
            }

            return null;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'identifier' => [__('templates.errors.refresh_layouts_failed').': '.$e->getMessage()],
            ]);
        }
    }

    /**
     * 특정 모듈에 의존하는 템플릿 목록을 조회합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return array 의존하는 템플릿 목록
     */
    public function getTemplatesDependingOnModule(string $moduleIdentifier): array
    {
        $this->templateManager->loadTemplates();

        $dependentTemplates = [];

        // 설치된 모든 템플릿 조회
        $installedTemplates = $this->templateManager->getInstalledTemplatesWithDetails();

        foreach ($installedTemplates as $template) {
            $dependencies = $template['dependencies'] ?? [];

            // 모듈 의존성 확인
            if (isset($dependencies['modules']) && is_array($dependencies['modules'])) {
                if (array_key_exists($moduleIdentifier, $dependencies['modules'])) {
                    $dependentTemplates[] = [
                        'identifier' => $template['identifier'],
                        'name' => $template['name'],
                        'version' => $template['version'],
                        'type' => $template['type'],
                        'status' => $template['status'],
                        'required_version' => $dependencies['modules'][$moduleIdentifier],
                    ];
                }
            }
        }

        return $dependentTemplates;
    }

    /**
     * 특정 플러그인에 의존하는 템플릿 목록을 조회합니다.
     *
     * @param  string  $pluginIdentifier  플러그인 식별자
     * @return array 의존하는 템플릿 목록
     */
    public function getTemplatesDependingOnPlugin(string $pluginIdentifier): array
    {
        $this->templateManager->loadTemplates();

        $dependentTemplates = [];

        // 설치된 모든 템플릿 조회
        $installedTemplates = $this->templateManager->getInstalledTemplatesWithDetails();

        foreach ($installedTemplates as $template) {
            $dependencies = $template['dependencies'] ?? [];

            // 플러그인 의존성 확인
            if (isset($dependencies['plugins']) && is_array($dependencies['plugins'])) {
                if (array_key_exists($pluginIdentifier, $dependencies['plugins'])) {
                    $dependentTemplates[] = [
                        'identifier' => $template['identifier'],
                        'name' => $template['name'],
                        'version' => $template['version'],
                        'type' => $template['type'],
                        'status' => $template['status'],
                        'required_version' => $dependencies['plugins'][$pluginIdentifier],
                    ];
                }
            }
        }

        return $dependentTemplates;
    }

    /**
     * 모든 설치된 템플릿의 업데이트를 확인합니다.
     *
     * @return array 업데이트 확인 결과 (updated_count, details)
     *
     * @throws ValidationException 확인 실패 시
     */
    public function checkForUpdates(): array
    {
        HookManager::doAction('core.templates.before_check_updates');

        try {
            $this->templateManager->loadTemplates();
            $result = $this->templateManager->checkAllTemplatesForUpdates();

            HookManager::doAction('core.templates.after_check_updates', $result);

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'templates' => [__('templates.check_updates_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 지정된 템플릿의 버전을 업데이트합니다.
     *
     * @param  string  $templateName  업데이트할 템플릿 identifier
     * @param  string  $layoutStrategy  레이아웃 전략 ('overwrite' 또는 'keep')
     * @param  bool  $force  코어 버전 비호환 강제 우회 (위험 — 사용자 명시 필요)
     * @return array 업데이트 결과 (identifier, from_version, to_version 등)
     *
     * @throws ValidationException 업데이트 실패 시
     */
    public function performVersionUpdate(string $templateName, string $layoutStrategy = 'overwrite', bool $force = false): array
    {
        HookManager::doAction('core.templates.before_version_update', $templateName);

        try {
            $this->templateManager->loadTemplates();
            $result = $this->templateManager->updateTemplate($templateName, $force, null, $layoutStrategy);

            $templateInfo = $this->templateManager->getTemplateInfo($templateName);

            HookManager::doAction('core.templates.after_version_update', $templateName, $result, $templateInfo);

            return array_merge($result, [
                'template_info' => $templateInfo,
            ]);
        } catch (\Exception $e) {
            // Manager의 RuntimeException은 이미 번역된 메시지를 포함하므로
            // getPrevious()로 원본 에러를 추출하여 이중 래핑 방지
            $rawError = $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage();

            throw ValidationException::withMessages([
                'template_name' => [__('templates.errors.update_failed', ['template' => $templateName, 'error' => $rawError])],
            ]);
        }
    }

    /**
     * 지정된 템플릿의 수정된 레이아웃을 확인합니다.
     *
     * @param  string  $templateName  확인할 템플릿 identifier
     * @return array{has_modified_layouts: bool, modified_count: int, modified_layouts: array}
     *
     * @throws ValidationException 확인 실패 시
     */
    public function checkModifiedLayouts(string $templateName): array
    {
        try {
            $this->templateManager->loadTemplates();

            return $this->templateManager->hasModifiedLayouts($templateName);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'template_name' => [__('templates.check_modified_layouts_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 템플릿의 변경 내역(changelog)을 조회합니다.
     *
     * source가 'github'이면 GitHub에서 원격 CHANGELOG.md를 가져와 파싱합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $source  소스 ('active', 'bundled', 'github')
     * @param  string|null  $fromVersion  시작 버전 (초과)
     * @param  string|null  $toVersion  끝 버전 (이하)
     * @return array 변경 내역 배열
     */
    public function getTemplateChangelog(string $identifier, ?string $source = null, ?string $fromVersion = null, ?string $toVersion = null): array
    {
        // GitHub 소스: 원격에서 CHANGELOG.md를 가져옴
        if ($source === 'github') {
            return $this->fetchRemoteChangelog($identifier, $fromVersion, $toVersion);
        }

        $basePath = base_path('templates');
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
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $fromVersion  시작 버전 (초과)
     * @param  string|null  $toVersion  끝 버전 (이하)
     * @return array 변경 내역 배열
     */
    private function fetchRemoteChangelog(string $identifier, ?string $fromVersion = null, ?string $toVersion = null): array
    {
        $template = $this->templateManager->getTemplate($identifier);

        if (! $template) {
            return [];
        }

        $githubUrl = $template['github_url'] ?? null;

        if (empty($githubUrl)) {
            return $this->getTemplateChangelog($identifier, 'bundled', $fromVersion, $toVersion);
        }

        try {
            [$owner, $repo] = GithubHelper::parseUrl($githubUrl);
        } catch (\RuntimeException $e) {
            return $this->getTemplateChangelog($identifier, 'bundled', $fromVersion, $toVersion);
        }

        $ref = $toVersion ?? 'main';
        $content = GithubHelper::fetchRawFile($owner, $repo, $ref, 'CHANGELOG.md');

        if ($content === null) {
            return $this->getTemplateChangelog($identifier, 'bundled', $fromVersion, $toVersion);
        }

        if ($fromVersion !== null && $toVersion !== null) {
            return ChangelogParser::getVersionRangeFromString($content, $fromVersion, $toVersion);
        }

        return ChangelogParser::parseFromString($content);
    }
}
