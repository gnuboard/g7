<?php

namespace Modules\Sirsoft\Page\Services;

use App\Contracts\Extension\ModuleSettingsInterface;
use App\Traits\NormalizesSettingsData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * 페이지 모듈 환경설정 서비스
 *
 * 이 서비스가 없던 동안 `g7_settings.modules.sirsoft-page` 미러는 **영구 미존재**였고,
 * 소비자(`PageAttachmentService`, `UploadPageAttachmentRequest`)는 항상 인자 기본값으로
 * 폴백했습니다 — 운영자가 설정을 바꿔도 첨부 상한이 반영되지 않았습니다 (공개이슈 #109).
 *
 * defaults.json 은 카테고리 맵(`{"attachment": {...}}`) 한 단계 구조입니다.
 */
class PageSettingsService implements ModuleSettingsInterface
{
    use NormalizesSettingsData;

    /**
     * 모듈 식별자
     */
    private const MODULE_IDENTIFIER = 'sirsoft-page';

    /**
     * 설정 기본값 (캐시)
     */
    private ?array $defaults = null;

    /**
     * 현재 설정값 (캐시)
     */
    private ?array $settings = null;

    /**
     * 모듈 설정 기본값 파일 경로 반환
     *
     * @return string|null defaults.json 파일의 절대 경로, 없으면 null
     */
    public function getSettingsDefaultsPath(): ?string
    {
        $path = base_path('modules/'.self::MODULE_IDENTIFIER).'/config/settings/defaults.json';

        return file_exists($path) ? $path : null;
    }

    /**
     * 설정값 조회
     *
     * @param  string  $key  설정 키 (예: 'attachment.max_count')
     * @param  mixed  $default  기본값
     * @return mixed 설정값
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->getAllSettings(), $key, $default);
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

        $category = explode('.', $key)[0];

        return $this->saveSettings([$category => $settings[$category] ?? []]);
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

        $defaultValues = $this->getDefaults();
        $settings = [];

        foreach ($defaultValues as $category => $categoryDefaults) {
            if (! is_array($categoryDefaults)) {
                continue;
            }

            $settings[$category] = array_merge($categoryDefaults, $this->loadCategorySettings($category));
        }

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
        return $this->getAllSettings()[$category] ?? [];
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
        $defaultValues = $this->getDefaults();

        foreach ($settings as $category => $categorySettings) {
            if (str_starts_with((string) $category, '_') || ! is_array($categorySettings)) {
                continue;
            }

            $categoryDefaults = $defaultValues[$category] ?? [];

            // 토글이 꺼진 채 전송되지 않는 boolean 필드는 false 로 채운다
            foreach ($categoryDefaults as $key => $defaultValue) {
                if (is_bool($defaultValue) && ! array_key_exists($key, $categorySettings)) {
                    $categorySettings[$key] = false;
                }
            }

            $processed = $this->normalizeCategoryData($categorySettings, $categoryDefaults);

            if (! $this->saveCategorySettings($category, $processed)) {
                $success = false;
            }
        }

        $this->clearCache();

        return $success;
    }

    /**
     * 프론트엔드용 설정 조회 (민감정보 제외)
     *
     * defaults.json 에 frontend_schema 가 없으므로 노출 대상이 없습니다.
     *
     * @return array 프론트엔드에 노출 가능한 설정값
     */
    public function getFrontendSettings(): array
    {
        return [];
    }

    /**
     * 캐시 초기화
     */
    public function clearCache(): void
    {
        $this->defaults = null;
        $this->settings = null;

        // 상주 프로세스의 config 미러도 함께 갱신한다 (공개이슈 #109)
        g7_refresh_module_settings_config(self::MODULE_IDENTIFIER);
    }

    /**
     * 기본값 조회
     *
     * @return array defaults.json 의 카테고리 맵
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

        $decoded = json_decode(File::get($path), true) ?? [];

        // `_meta`/`defaults` 래퍼 형식(다른 모듈 관례)도 함께 받아 준다
        $this->defaults = is_array($decoded['defaults'] ?? null) ? $decoded['defaults'] : $decoded;

        return $this->defaults;
    }

    /**
     * 카테고리 설정 로드
     *
     * @param  string  $category  카테고리명
     * @return array 저장된 설정값
     */
    private function loadCategorySettings(string $category): array
    {
        $path = $this->getCategoryFilePath($category);

        if (! File::exists($path)) {
            return [];
        }

        return json_decode(File::get($path), true) ?? [];
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

        if (! File::isDirectory($storagePath)) {
            File::makeDirectory($storagePath, 0755, true);
        }

        $content = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return File::put($this->getCategoryFilePath($category), $content) !== false;
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
     * 설정 저장 경로 반환
     *
     * testing 환경에서는 운영 설정(storage/app/modules/.../settings)을 보호하기 위해
     * 별도 경로를 사용합니다 — 테스트가 개발/운영 설정을 덮어쓰지 않게 합니다.
     *
     * @return string 설정 파일 저장 디렉토리 경로
     */
    private function getStoragePath(): string
    {
        if (app()->runningUnitTests()) {
            return storage_path('framework/testing/modules/'.self::MODULE_IDENTIFIER.'/settings');
        }

        return storage_path('app/modules/'.self::MODULE_IDENTIFIER.'/settings');
    }
}
