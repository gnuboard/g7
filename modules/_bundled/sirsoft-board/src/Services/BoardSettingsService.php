<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\ModuleSettingsInterface;
use App\Traits\NormalizesSettingsData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * 게시판 모듈 환경설정 서비스
 *
 * ModuleSettingsInterface를 구현하여 모듈별 설정을 관리합니다.
 */
class BoardSettingsService implements ModuleSettingsInterface
{
    use NormalizesSettingsData;

    /**
     * 모듈 식별자
     */
    private const MODULE_IDENTIFIER = 'sirsoft-board';

    /**
     * 설정 기본값 (캐시)
     */
    private ?array $defaults = null;

    /**
     * 현재 설정값 (캐시)
     */
    private ?array $settings = null;

    /**
     * 생성자
     *
     * @param  BoardPermissionService  $permissionService  게시판 권한 서비스
     */
    public function __construct(
        private readonly BoardPermissionService $permissionService,
    ) {
        //
    }

    /**
     * 모듈 설정 기본값 파일 경로 반환
     *
     * @return string|null defaults.json 파일의 절대 경로, 없으면 null
     */
    public function getSettingsDefaultsPath(): ?string
    {
        $path = $this->getModulePath().'/config/settings/defaults.json';

        return file_exists($path) ? $path : null;
    }

    /**
     * 설정값 조회
     *
     * @param  string  $key  설정 키 (예: 'basic_defaults.per_page')
     * @param  mixed  $default  기본값
     * @return mixed 설정값
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->getAllSettings();

        return Arr::get($settings, $key, $default);
    }

    /**
     * 설정값 저장
     *
     * @param  string  $key  설정 키
     * @param  mixed  $value  저장할 값
     * @return bool 성공 여부
     */
    public function setSetting(string $key, mixed $value): bool
    {
        $settings = $this->getAllSettings();
        Arr::set($settings, $key, $value);

        // 카테고리 추출
        $parts = explode('.', $key);
        $category = $parts[0];

        return $this->saveCategorySettings($category, $settings[$category] ?? []);
    }

    /**
     * 전체 설정 조회
     *
     * @return array 모든 카테고리의 설정값
     */
    public function getAllSettings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $defaults = $this->getDefaults();
        $categories = $defaults['_meta']['categories'] ?? [];
        $defaultValues = $defaults['defaults'] ?? [];

        $settings = [];
        foreach ($categories as $category) {
            $categoryDefaults = $defaultValues[$category] ?? [];
            $savedSettings = $this->loadCategorySettings($category);
            $settings[$category] = array_merge($categoryDefaults, $savedSettings);
        }

        // 저장된 데이터를 defaults 스키마에 맞게 정규화 (하위호환성)
        $settings = $this->normalizeSettingsData($settings, $defaultValues);

        $this->settings = $settings;

        return $settings;
    }

    /**
     * 카테고리별 설정 조회
     *
     * @param  string  $category  카테고리명
     * @return array 카테고리의 설정값
     */
    public function getSettings(string $category): array
    {
        $allSettings = $this->getAllSettings();

        return $allSettings[$category] ?? [];
    }

    /**
     * 설정 저장
     *
     * @param  array  $settings  저장할 설정 배열
     * @return bool 성공 여부
     */
    public function saveSettings(array $settings): bool
    {
        $success = true;
        $defaults = $this->getDefaults();
        $defaultValues = $defaults['defaults'] ?? [];

        foreach ($settings as $category => $categorySettings) {
            if (str_starts_with($category, '_')) {
                continue; // _meta, _tab 등 메타 정보 무시
            }

            // 카테고리 값이 배열이 아닌 경우 무시 (최상위 레벨 오염 데이터 방어)
            if (! is_array($categorySettings)) {
                continue;
            }

            // defaults 스키마에 맞게 정규화
            $categoryDefaults = $defaultValues[$category] ?? [];
            $processedSettings = $this->normalizeCategoryData($categorySettings, $categoryDefaults);

            if (! $this->saveCategorySettings($category, $processedSettings)) {
                $success = false;
            }
        }

        // 캐시 초기화
        $this->settings = null;

        return $success;
    }

    /**
     * 프론트엔드용 설정 조회 (민감정보 제외)
     *
     * frontend_schema에 따라 민감하지 않은 설정만 반환합니다.
     *
     * @return array 프론트엔드에 노출 가능한 설정값
     */
    public function getFrontendSettings(): array
    {
        $defaults = $this->getDefaults();
        $frontendSchema = $defaults['frontend_schema'] ?? [];
        $allSettings = $this->getAllSettings();

        $frontendSettings = [];

        foreach ($frontendSchema as $category => $schema) {
            if (! ($schema['expose'] ?? false)) {
                continue;
            }

            $categorySettings = $allSettings[$category] ?? [];
            $fields = $schema['fields'] ?? [];

            if (empty($fields)) {
                // fields가 없으면 전체 카테고리 노출
                $frontendSettings[$category] = $categorySettings;

                continue;
            }

            $exposedFields = [];
            foreach ($fields as $fieldName => $fieldSchema) {
                if ($fieldSchema['expose'] ?? false) {
                    $exposedFields[$fieldName] = $categorySettings[$fieldName] ?? null;
                }
            }

            if (! empty($exposedFields)) {
                $frontendSettings[$category] = $exposedFields;
            }
        }

        return $frontendSettings;
    }

    /**
     * 신고 관리 권한에 역할을 재할당합니다.
     *
     * @param  array  $reportPermissions  { view_roles: [...], manage_roles: [...] }
     * @return void
     */
    public function syncReportPermissionRoles(array $reportPermissions): void
    {
        $this->permissionService->syncModulePermissionRoles([
            'sirsoft-board.reports.view'   => $reportPermissions['view_roles'] ?? [],
            'sirsoft-board.reports.manage' => $reportPermissions['manage_roles'] ?? [],
        ]);
    }

    /**
     * 신고 관리 권한에 현재 할당된 역할 목록을 반환합니다.
     *
     * @return array { view_roles: [...], manage_roles: [...] }
     */
    public function getReportPermissionRoles(): array
    {
        return $this->permissionService->getModulePermissionRoles([
            'sirsoft-board.reports.view',
            'sirsoft-board.reports.manage',
        ]);
    }

    /**
     * 캐시 초기화
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->defaults = null;
        $this->settings = null;
    }

    /**
     * 기본값 조회
     *
     * @return array defaults.json 내용
     */
    private function getDefaults(): array
    {
        if ($this->defaults !== null) {
            return $this->defaults;
        }

        $path = $this->getSettingsDefaultsPath();
        if ($path === null) {
            return [];
        }

        $content = File::get($path);
        $this->defaults = json_decode($content, true) ?? [];

        return $this->defaults;
    }

    /**
     * 카테고리 설정 파일 경로 반환
     *
     * @param  string  $category  카테고리명
     * @return string 설정 파일 경로
     */
    private function getCategoryFilePath(string $category): string
    {
        return $this->getStoragePath().'/'.$category.'.json';
    }

    /**
     * 카테고리 설정 로드
     *
     * @param  string  $category  카테고리명
     * @return array 설정값
     */
    private function loadCategorySettings(string $category): array
    {
        $path = $this->getCategoryFilePath($category);

        if (! File::exists($path)) {
            return [];
        }

        $content = File::get($path);

        return json_decode($content, true) ?? [];
    }

    /**
     * 카테고리 설정 저장
     *
     * @param  string  $category  카테고리명
     * @param  array  $settings  설정값
     * @return bool 성공 여부
     */
    private function saveCategorySettings(string $category, array $settings): bool
    {
        $storagePath = $this->getStoragePath();

        // 디렉토리 생성
        if (! File::isDirectory($storagePath)) {
            File::makeDirectory($storagePath, 0755, true);
        }

        $path = $this->getCategoryFilePath($category);
        $content = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return File::put($path, $content) !== false;
    }

    /**
     * 모듈 경로 반환
     *
     * @return string 모듈 디렉토리 경로
     */
    private function getModulePath(): string
    {
        return base_path('modules/'.self::MODULE_IDENTIFIER);
    }

    /**
     * 설정 저장 경로 반환
     *
     * @return string 설정 파일 저장 디렉토리 경로
     */
    private function getStoragePath(): string
    {
        return storage_path('app/modules/'.self::MODULE_IDENTIFIER.'/settings');
    }
}
