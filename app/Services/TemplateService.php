<?php

namespace App\Services;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Exceptions\TemplateNotFoundException;
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
        private PluginManagerInterface $pluginManager
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

            return $value[$locale] ?? $value['ko'] ?? $value['en'] ?? reset($value) ?: null;
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
     * 활성화된 템플릿 identifier 조회
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
                $this->templateManager->loadTemplates();
                $templateInfo = $this->templateManager->getTemplateInfo($template->identifier);

                HookManager::doAction('core.templates.after_deactivate', $templateInfo);

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
     * 템플릿 업데이트
     */
    public function updateTemplate(int $id, array $data): object
    {
        HookManager::doAction('core.templates.before_update', $id, $data);

        $template = $this->templateRepository->findById($id);

        if (! $template) {
            throw new \Exception(__('templates.not_found'));
        }

        $data = HookManager::applyFilters('core.templates.filter_update_data', $data, $template);

        $updatedTemplate = $this->templateRepository->update($id, $data);

        HookManager::doAction('core.templates.after_update', $updatedTemplate, $data);

        return $updatedTemplate;
    }

    /**
     * 템플릿 삭제
     */
    public function deleteTemplate(int $id): bool
    {
        HookManager::doAction('core.templates.before_delete', $id);

        $template = $this->templateRepository->findById($id);

        if (! $template) {
            throw new \Exception(__('templates.not_found'));
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

        // 4. 요청된 로케일이 locales에 있는지 검증
        $supportedLocales = $templateInfo['locales'] ?? [];
        if (! in_array($locale, $supportedLocales, true)) {
            return [
                'success' => false,
                'langPath' => null,
                'error' => 'locale_not_supported',
            ];
        }

        // 5. lang/{locale}.json 파일 존재 여부 확인
        $langPath = base_path("templates/{$identifier}/lang/{$locale}.json");

        // 6. Path Traversal 공격 방지
        $realPath = realpath($langPath);
        $basePath = realpath(base_path("templates/{$identifier}/lang"));

        if ($realPath === false || ! str_starts_with($realPath, $basePath)) {
            return [
                'success' => false,
                'langPath' => null,
                'error' => 'file_not_found',
            ];
        }

        if (! file_exists($langPath)) {
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
        $templateLangData = $this->loadLanguageFileWithFragments($result['langPath']);

        if ($templateLangData === null) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'invalid_json',
            ];
        }

        // 3. 활성화된 모듈들의 다국어 데이터 병합 (fragment 해석 포함)
        $moduleLangData = $this->loadActiveModulesLanguageData($locale);

        // 4. 활성화된 플러그인들의 다국어 데이터 병합 (fragment 해석 포함)
        $pluginLangData = $this->loadActivePluginsLanguageData($locale);

        // 5. 템플릿 데이터와 모듈/플러그인 데이터 병합
        $mergedData = array_merge($templateLangData, $moduleLangData, $pluginLangData);

        return [
            'success' => true,
            'data' => $mergedData,
            'error' => null,
        ];
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

        // 9. 템플릿 routes와 모듈/플러그인 routes 병합
        $mergedRoutes = array_merge(
            $templateRoutesData['routes'] ?? [],
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

        // 11. 최종 데이터 구성
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
                // 모듈 routes의 layout 필드에 moduleIdentifier 접두사 추가
                $moduleRoutes = array_map(function ($route) use ($moduleIdentifier) {
                    if (isset($route['layout'])) {
                        $route['layout'] = $moduleIdentifier.'.'.$route['layout'];
                    }

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
                    // 플러그인 routes의 layout 필드에 pluginIdentifier 접두사 추가
                    $pluginRoutes = array_map(function ($route) use ($pluginIdentifier) {
                        if (isset($route['layout'])) {
                            $route['layout'] = $pluginIdentifier.'.'.$route['layout'];
                        }

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
                throw new \RuntimeException(__('templates.errors.github_repo_not_found'));
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
            throw new \RuntimeException(__('templates.errors.already_installed'));
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
            throw new \RuntimeException(__('templates.errors.install_failed'));
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
     * @return array 업데이트 결과 (identifier, from_version, to_version 등)
     *
     * @throws ValidationException 업데이트 실패 시
     */
    public function performVersionUpdate(string $templateName, string $layoutStrategy = 'overwrite'): array
    {
        HookManager::doAction('core.templates.before_version_update', $templateName);

        try {
            $this->templateManager->loadTemplates();
            $result = $this->templateManager->updateTemplate($templateName, false, null, $layoutStrategy);

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
