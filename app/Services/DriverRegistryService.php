<?php

namespace App\Services;

use App\Extension\HookManager;
use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * 코어 드라이버 레지스트리 서비스
 *
 * 코어 환경설정의 드라이버 영역(스토리지, 캐시, 세션, 큐, 로그, 웹소켓, 메일)을
 * 관리합니다. 플러그인이 필터 훅으로 새 드라이버를 등록할 수 있으며,
 * 플러그인 비활성화 시 기본 드라이버로 안전하게 폴백합니다.
 */
class DriverRegistryService
{
    /**
     * 카테고리별 코어 드라이버 목록
     *
     * @var array<string, array<array{id: string, label: array{ko: string, en: string}}>>
     */
    private const CORE_DRIVERS = [
        'storage' => [
            ['id' => 'local', 'label' => ['ko' => '로컬', 'en' => 'Local']],
            ['id' => 's3', 'label' => ['ko' => 'Amazon S3', 'en' => 'Amazon S3']],
        ],
        'cache' => [
            ['id' => 'file', 'label' => ['ko' => '파일', 'en' => 'File']],
            ['id' => 'redis', 'label' => ['ko' => 'Redis', 'en' => 'Redis']],
        ],
        'session' => [
            ['id' => 'file', 'label' => ['ko' => '파일', 'en' => 'File']],
            ['id' => 'database', 'label' => ['ko' => '데이터베이스', 'en' => 'Database']],
            ['id' => 'redis', 'label' => ['ko' => 'Redis', 'en' => 'Redis']],
        ],
        'queue' => [
            ['id' => 'sync', 'label' => ['ko' => '동기', 'en' => 'Sync']],
            ['id' => 'database', 'label' => ['ko' => '데이터베이스', 'en' => 'Database']],
            ['id' => 'redis', 'label' => ['ko' => 'Redis', 'en' => 'Redis']],
        ],
        'log' => [
            ['id' => 'single', 'label' => ['ko' => '단일 파일', 'en' => 'Single File']],
            ['id' => 'daily', 'label' => ['ko' => '일별 파일', 'en' => 'Daily File']],
        ],
        'websocket' => [
            ['id' => 'reverb', 'label' => ['ko' => 'Laravel Reverb', 'en' => 'Laravel Reverb']],
        ],
        'mail' => [
            ['id' => 'smtp', 'label' => ['ko' => 'SMTP', 'en' => 'SMTP']],
            ['id' => 'mailgun', 'label' => ['ko' => 'Mailgun', 'en' => 'Mailgun']],
            ['id' => 'ses', 'label' => ['ko' => 'SES (Amazon)', 'en' => 'SES (Amazon)']],
        ],
    ];

    /**
     * 카테고리별 기본 폴백 드라이버
     *
     * @var array<string, string>
     */
    private const DEFAULT_DRIVERS = [
        'storage' => 'local',
        'cache' => 'file',
        'session' => 'database',
        'queue' => 'database',
        'log' => 'daily',
        'websocket' => '',
        'mail' => 'smtp',
    ];

    /**
     * 카테고리별 Laravel Config 키 매핑
     *
     * @var array<string, string>
     */
    public const CONFIG_KEYS = [
        'storage' => 'filesystems.default',
        'cache' => 'cache.default',
        'session' => 'session.driver',
        'queue' => 'queue.default',
        'log' => 'logging.default',
        'websocket' => 'broadcasting.default',
        'mail' => 'mail.default',
    ];

    /**
     * 카테고리별 JSON 설정 키 매핑 (JsonConfigRepository 카테고리 + 키)
     *
     * @var array<string, array{category: string, key: string}>
     */
    private const SETTINGS_KEYS = [
        'storage' => ['category' => 'drivers', 'key' => 'storage_driver'],
        'cache' => ['category' => 'drivers', 'key' => 'cache_driver'],
        'session' => ['category' => 'drivers', 'key' => 'session_driver'],
        'queue' => ['category' => 'drivers', 'key' => 'queue_driver'],
        'log' => ['category' => 'drivers', 'key' => 'log_driver'],
        'websocket' => ['category' => 'drivers', 'key' => 'websocket_driver'],
        'mail' => ['category' => 'mail', 'key' => 'mailer'],
    ];

    /**
     * 필터 훅 이름 접두사
     */
    private const HOOK_PREFIX = 'core.settings.available_';

    /**
     * 필터 훅 이름 접미사
     */
    private const HOOK_SUFFIX = '_drivers';

    /**
     * 특정 카테고리의 사용 가능한 드라이버 목록을 반환합니다.
     *
     * 코어 드라이버 + 플러그인 필터 훅으로 추가된 드라이버를 병합합니다.
     *
     * @param  string  $category  드라이버 카테고리 (storage, cache, session, queue, log, websocket, mail)
     * @return array<array{id: string, label: array{ko: string, en: string}, provider?: string}> 사용 가능한 드라이버 배열
     */
    public function getAvailableDrivers(string $category): array
    {
        $coreDrivers = self::CORE_DRIVERS[$category] ?? [];

        $hookName = self::HOOK_PREFIX.$category.self::HOOK_SUFFIX;

        return HookManager::applyFilters($hookName, $coreDrivers);
    }

    /**
     * 모든 카테고리의 사용 가능한 드라이버 목록을 반환합니다.
     *
     * @return array<string, array<array{id: string, label: array{ko: string, en: string}, provider?: string}>>
     */
    public function getAllAvailableDrivers(): array
    {
        $result = [];

        foreach (array_keys(self::CORE_DRIVERS) as $category) {
            $result[$category] = $this->getAvailableDrivers($category);
        }

        return $result;
    }

    /**
     * 주어진 드라이버가 코어 드라이버인지 확인합니다.
     *
     * @param  string  $category  드라이버 카테고리
     * @param  string  $driverId  드라이버 ID
     * @return bool 코어 드라이버이면 true
     */
    public function isCoreDriver(string $category, string $driverId): bool
    {
        $coreDrivers = self::CORE_DRIVERS[$category] ?? [];

        foreach ($coreDrivers as $driver) {
            if ($driver['id'] === $driverId) {
                return true;
            }
        }

        return false;
    }

    /**
     * 주어진 드라이버가 현재 사용 가능한지 확인합니다.
     *
     * 코어 드라이버이거나, 플러그인 필터 훅으로 등록된 드라이버인 경우 true.
     *
     * @param  string  $category  드라이버 카테고리
     * @param  string  $driverId  드라이버 ID
     * @return bool 사용 가능하면 true
     */
    public function isDriverAvailable(string $category, string $driverId): bool
    {
        $availableDrivers = $this->getAvailableDrivers($category);

        foreach ($availableDrivers as $driver) {
            if ($driver['id'] === $driverId) {
                return true;
            }
        }

        return false;
    }

    /**
     * 카테고리별 기본 폴백 드라이버 ID를 반환합니다.
     *
     * @param  string  $category  드라이버 카테고리
     * @return string 기본 드라이버 ID
     */
    public function getDefaultDriver(string $category): string
    {
        return self::DEFAULT_DRIVERS[$category] ?? '';
    }

    /**
     * 특정 플러그인이 제공하는 드라이버 중 현재 사용 중인 것을 반환합니다.
     *
     * @param  string  $pluginIdentifier  플러그인 식별자 (예: sirsoft-custom_mail)
     * @return array<array{category: string, driver_id: string}> 사용 중인 드라이버 목록
     */
    public function getPluginProvidedDriversInUse(string $pluginIdentifier): array
    {
        $inUse = [];

        $configRepository = app(JsonConfigRepository::class);

        foreach (self::SETTINGS_KEYS as $category => $settingKey) {
            $settings = $configRepository->getCategory($settingKey['category']);
            $selectedDriver = $settings[$settingKey['key']] ?? '';

            if (empty($selectedDriver)) {
                continue;
            }

            // 코어 드라이버면 스킵
            if ($this->isCoreDriver($category, $selectedDriver)) {
                continue;
            }

            // 필터 훅으로 등록된 드라이버 중 해당 플러그인이 제공하는지 확인
            $availableDrivers = $this->getAvailableDrivers($category);

            foreach ($availableDrivers as $driver) {
                if ($driver['id'] === $selectedDriver && ($driver['provider'] ?? '') === $pluginIdentifier) {
                    $inUse[] = [
                        'category' => $category,
                        'driver_id' => $selectedDriver,
                    ];
                    break;
                }
            }
        }

        return $inUse;
    }

    /**
     * 지원되는 카테고리 목록을 반환합니다.
     *
     * @return array<string> 카테고리 이름 배열
     */
    public function getCategories(): array
    {
        return array_keys(self::CORE_DRIVERS);
    }

    /**
     * 카테고리에 해당하는 JSON 설정 키 정보를 반환합니다.
     *
     * @param  string  $category  드라이버 카테고리
     * @return array{category: string, key: string}|null 설정 키 정보 또는 null
     */
    public function getSettingsKey(string $category): ?array
    {
        return self::SETTINGS_KEYS[$category] ?? null;
    }

    /**
     * 카테고리에 해당하는 Laravel Config 키를 반환합니다.
     *
     * @param  string  $category  드라이버 카테고리
     * @return string|null Config 키 또는 null
     */
    public function getConfigKey(string $category): ?string
    {
        return self::CONFIG_KEYS[$category] ?? null;
    }
}
