<?php

namespace App\Repositories;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * JSON 파일 기반 설정 저장소
 *
 * 설정을 카테고리별 JSON 파일로 관리합니다.
 */
class JsonConfigRepository implements ConfigRepositoryInterface
{
    /**
     * Storage disk 이름
     */
    private const STORAGE_DISK = 'settings';

    /**
     * 백업 파일 저장 디렉토리 (disk 기준)
     */
    private const BACKUP_DIR = 'backups';

    /**
     * 기본 설정 파일 경로
     */
    private const DEFAULTS_FILE = 'settings/defaults.json';

    /**
     * 기본 설정 캐시
     *
     * @var array<string, mixed>|null
     */
    private ?array $defaultsCache = null;

    /**
     * 메모리 캐시
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $cache = null;

    /**
     * 모든 카테고리의 설정을 조회합니다.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $settings = [];
        foreach ($this->getCategories() as $category) {
            $settings[$category] = $this->getCategory($category);
        }

        $this->cache = $settings;

        return $settings;
    }

    /**
     * 특정 카테고리의 설정을 조회합니다.
     *
     * @return array<string, mixed>
     */
    public function getCategory(string $category): array
    {
        if (! $this->categoryExists($category)) {
            return $this->getDefaultsForCategory($category);
        }

        $path = $this->getCategoryPath($category);

        if (! Storage::disk(self::STORAGE_DISK)->exists($path)) {
            return $this->getDefaultsForCategory($category);
        }

        try {
            $content = Storage::disk(self::STORAGE_DISK)->get($path);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning("설정 파일 JSON 파싱 실패: {$category}", [
                    'error' => json_last_error_msg(),
                ]);

                return $this->getDefaultsForCategory($category);
            }

            // _meta 키 제거하고 반환
            unset($data['_meta']);

            // 기본값과 병합 (새 설정 키 자동 추가)
            $defaults = $this->getDefaultsForCategory($category);

            return array_merge($defaults, $data);
        } catch (\Exception $e) {
            Log::error("설정 파일 읽기 실패: {$category}", [
                'error' => $e->getMessage(),
            ]);

            return $this->getDefaultsForCategory($category);
        }
    }

    /**
     * 도트 노테이션으로 특정 설정값을 조회합니다.
     *
     * @param  string  $key  예: 'mail.host', 'general.site_name'
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key, 2);

        if (count($parts) === 1) {
            // 카테고리만 지정된 경우
            return $this->getCategory($key);
        }

        [$category, $settingKey] = $parts;
        $categoryData = $this->getCategory($category);

        return Arr::get($categoryData, $settingKey, $default);
    }

    /**
     * 도트 노테이션으로 특정 설정값을 저장합니다.
     */
    public function set(string $key, mixed $value): bool
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$category, $settingKey] = $parts;
        $categoryData = $this->getCategory($category);
        Arr::set($categoryData, $settingKey, $value);

        return $this->saveCategory($category, $categoryData);
    }

    /**
     * 여러 설정을 일괄 저장합니다.
     *
     * @param  array<string, mixed>  $settings
     */
    public function setMany(array $settings): bool
    {
        $grouped = $this->groupByCategory($settings);

        foreach ($grouped as $category => $categorySettings) {
            $existing = $this->getCategory($category);
            $merged = array_merge($existing, $categorySettings);

            if (! $this->saveCategory($category, $merged)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 특정 카테고리의 설정을 저장합니다.
     *
     * @param  array<string, mixed>  $settings
     */
    public function saveCategory(string $category, array $settings): bool
    {
        if (! $this->categoryExists($category)) {
            return false;
        }

        $this->ensureDirectoryExists();

        // 메타 정보 추가
        $data = [
            '_meta' => [
                'version' => '1.0.0',
                'updated_at' => now()->toIso8601String(),
            ],
            ...$settings,
        ];

        $path = $this->getCategoryPath($category);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        try {
            // 파일 잠금으로 동시 쓰기 방지
            $fullPath = Storage::disk(self::STORAGE_DISK)->path($path);
            $handle = fopen($fullPath, 'c');

            if ($handle === false) {
                return false;
            }

            if (flock($handle, LOCK_EX)) {
                ftruncate($handle, 0);
                fwrite($handle, $json);
                fflush($handle);
                flock($handle, LOCK_UN);
            }

            fclose($handle);

            // 캐시 무효화
            $this->cache = null;

            return true;
        } catch (\Exception $e) {
            Log::error("설정 파일 저장 실패: {$category}", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 설정 키 존재 여부를 확인합니다.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 특정 설정을 삭제합니다.
     */
    public function delete(string $key): bool
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$category, $settingKey] = $parts;
        $categoryData = $this->getCategory($category);

        Arr::forget($categoryData, $settingKey);

        return $this->saveCategory($category, $categoryData);
    }

    /**
     * 사용 가능한 카테고리 목록을 반환합니다.
     *
     * config/settings/defaults.json의 _meta.categories에서 조회합니다.
     *
     * @return array<string>
     */
    public function getCategories(): array
    {
        if ($this->defaultsCache === null) {
            $this->loadDefaultsFile();
        }

        return $this->defaultsCache['_meta']['categories'] ?? [];
    }

    /**
     * 카테고리 존재 여부를 확인합니다.
     */
    public function categoryExists(string $category): bool
    {
        return in_array($category, $this->getCategories(), true);
    }

    /**
     * 설정 파일을 초기화합니다.
     *
     * @param  array<string, array<string, mixed>>  $settings
     */
    public function initialize(array $settings = []): bool
    {
        $this->ensureDirectoryExists();

        $defaults = $this->getDefaults();
        $merged = array_replace_recursive($defaults, $settings);

        foreach ($merged as $category => $categorySettings) {
            if (! $this->saveCategory($category, $categorySettings)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 설정을 백업합니다.
     *
     * @return string 백업 파일 경로
     */
    public function backup(): string
    {
        $this->ensureBackupDirectoryExists();

        $timestamp = now()->format('Y-m-d_His');
        $backupName = "backup_{$timestamp}.zip";
        $backupPath = self::BACKUP_DIR.'/'.$backupName;

        $zip = new \ZipArchive;
        $fullBackupPath = Storage::disk(self::STORAGE_DISK)->path($backupPath);

        if ($zip->open($fullBackupPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(__('exceptions.settings.backup_creation_failed'));
        }

        foreach ($this->getCategories() as $category) {
            $categoryPath = $this->getCategoryPath($category);
            $fullCategoryPath = Storage::disk(self::STORAGE_DISK)->path($categoryPath);

            if (file_exists($fullCategoryPath)) {
                $zip->addFile($fullCategoryPath, "{$category}.json");
            }
        }

        $zip->close();

        return $backupPath;
    }

    /**
     * 백업에서 설정을 복원합니다.
     */
    public function restore(string $backupPath): bool
    {
        $fullBackupPath = Storage::disk(self::STORAGE_DISK)->path($backupPath);

        if (! file_exists($fullBackupPath)) {
            return false;
        }

        $zip = new \ZipArchive;

        if ($zip->open($fullBackupPath) !== true) {
            return false;
        }

        $this->ensureDirectoryExists();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $category = pathinfo($filename, PATHINFO_FILENAME);

            if ($this->categoryExists($category)) {
                $content = $zip->getFromIndex($i);
                $categoryPath = $this->getCategoryPath($category);
                Storage::disk(self::STORAGE_DISK)->put($categoryPath, $content);
            }
        }

        $zip->close();
        $this->cache = null;

        return true;
    }

    /**
     * 기본 설정값을 반환합니다.
     *
     * config/settings/defaults.json 파일에서 기본값을 로드합니다.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getDefaults(): array
    {
        if ($this->defaultsCache !== null) {
            return $this->defaultsCache['defaults'] ?? [];
        }

        $this->loadDefaultsFile();

        return $this->defaultsCache['defaults'] ?? [];
    }

    /**
     * 프론트엔드 스키마를 반환합니다.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getFrontendSchema(): array
    {
        if ($this->defaultsCache === null) {
            $this->loadDefaultsFile();
        }

        return $this->defaultsCache['frontend_schema'] ?? [];
    }

    /**
     * 기본 설정 파일을 로드합니다.
     */
    private function loadDefaultsFile(): void
    {
        $path = config_path(self::DEFAULTS_FILE);

        if (! file_exists($path)) {
            Log::error('설정 기본값 파일을 찾을 수 없습니다. settings:install 명령을 실행하세요.', ['path' => $path]);
            $this->defaultsCache = ['_meta' => ['categories' => []], 'defaults' => [], 'frontend_schema' => []];

            return;
        }

        try {
            $content = file_get_contents($path);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('설정 기본값 파일 JSON 파싱 실패', [
                    'error' => json_last_error_msg(),
                ]);
                $this->defaultsCache = ['_meta' => ['categories' => []], 'defaults' => [], 'frontend_schema' => []];

                return;
            }

            $this->defaultsCache = $data;
        } catch (\Exception $e) {
            Log::error('설정 기본값 파일 로드 실패', ['error' => $e->getMessage()]);
            $this->defaultsCache = ['_meta' => ['categories' => []], 'defaults' => [], 'frontend_schema' => []];
        }
    }

    /**
     * 특정 카테고리의 기본값을 반환합니다.
     *
     * @return array<string, mixed>
     */
    private function getDefaultsForCategory(string $category): array
    {
        $defaults = $this->getDefaults();

        return $defaults[$category] ?? [];
    }

    /**
     * 카테고리 파일 경로를 반환합니다.
     */
    private function getCategoryPath(string $category): string
    {
        return "{$category}.json";
    }

    /**
     * 설정 디렉토리가 존재하는지 확인하고 없으면 생성합니다.
     */
    private function ensureDirectoryExists(): void
    {
        $disk = Storage::disk(self::STORAGE_DISK);

        if (! $disk->exists('.')) {
            $disk->makeDirectory('.');
        }
    }

    /**
     * 백업 디렉토리가 존재하는지 확인하고 없으면 생성합니다.
     */
    private function ensureBackupDirectoryExists(): void
    {
        $disk = Storage::disk(self::STORAGE_DISK);

        if (! $disk->exists(self::BACKUP_DIR)) {
            $disk->makeDirectory(self::BACKUP_DIR);
        }
    }

    /**
     * 설정을 카테고리별로 그룹화합니다.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, array<string, mixed>>
     */
    private function groupByCategory(array $settings): array
    {
        $grouped = [];

        foreach ($settings as $key => $value) {
            $parts = explode('.', $key, 2);

            if (count($parts) === 2) {
                [$category, $settingKey] = $parts;
                $grouped[$category][$settingKey] = $value;
            }
        }

        return $grouped;
    }
}
