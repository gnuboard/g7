<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\ModuleSettingsInterface;
use App\Models\NotificationDefinition;
use App\Services\NotificationDefinitionService;
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

            // Toggle/체크박스 OFF 시 키 미전송 대응: boolean 기본값 필드가 누락되면 false로 채움
            foreach ($categoryDefaults as $key => $defaultValue) {
                if (is_bool($defaultValue) && ! array_key_exists($key, $categorySettings)) {
                    $categorySettings[$key] = false;
                }
            }

            $processedSettings = $this->normalizeCategoryData($categorySettings, $categoryDefaults);

            if (! $this->saveCategorySettings($category, $processedSettings)) {
                $success = false;
            }
        }

        // 캐시 초기화
        $this->settings = null;

        // report_policy 설정 변경 시 알림 정의 활성 상태 동기화
        // 저장된 최종 설정을 사용 (boolean 보정 후 값)
        if (isset($settings['report_policy'])) {
            $savedReportPolicy = $this->loadCategorySettings('report_policy');
            $this->syncNotificationDefinitionStatus($savedReportPolicy);
        }

        return $success;
    }

    /**
     * 신고 정책 알림 설정에 따라 notification_definitions 활성 상태를 동기화합니다.
     *
     * 설정 OFF 시 해당 알림 정의를 비활성화하여
     * NotificationHookListener가 훅을 구독하지 않도록 합니다.
     *
     * @param array $reportPolicy 신고 정책 설정
     * @return void
     */
    private function syncNotificationDefinitionStatus(array $reportPolicy): void
    {
        $syncMap = [
            'notify_admin_on_report' => 'report_received_admin',
            'notify_author_on_report_action' => 'report_action',
        ];

        $changed = false;

        foreach ($syncMap as $settingKey => $definitionType) {
            if (! array_key_exists($settingKey, $reportPolicy)) {
                continue;
            }

            $updated = NotificationDefinition::where('type', $definitionType)
                ->where('extension_identifier', 'sirsoft-board')
                ->update(['is_active' => (bool) $reportPolicy[$settingKey]]);

            if ($updated > 0) {
                $changed = true;
            }
        }

        // 알림 정의 캐시 무효화 (NotificationHookListener가 새 상태를 읽도록)
        if ($changed) {
            app(NotificationDefinitionService::class)->invalidateAllCache();
        }
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
