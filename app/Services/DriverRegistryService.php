<?php

namespace App\Services;

use App\Extension\HookManager;
use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Lang;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Predis\Client;

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
     * 카테고리별 코어 드라이버 ID 목록
     *
     * 라벨은 활성 translatable_locales 별로 lang/{locale}/settings.php 의
     * 'drivers.{category}.{id}' 키에서 동적 조회됩니다.
     *
     * @var array<string, list<string>>
     */
    private const CORE_DRIVER_IDS = [
        'storage' => ['local', 's3'],
        'public_asset' => ['none', 'public', 's3'],
        'cache' => ['file', 'redis'],
        'session' => ['file', 'database', 'redis'],
        'queue' => ['sync', 'database', 'redis'],
        'log' => ['single', 'daily'],
        'websocket' => ['reverb'],
        'mail' => ['smtp', 'mailgun', 'ses'],
        // 검색엔진도 저장 가능한 드라이버 선택이다. 레지스트리에 등재해야 폴백 가드
        // (플러그인 제거 후 죽은 값 → 기본 드라이버)가 이 카테고리에도 적용된다.
        'search' => ['mysql-fulltext'],
    ];

    /**
     * 카테고리별 기본 폴백 드라이버
     *
     * @var array<string, string>
     */
    private const DEFAULT_DRIVERS = [
        'storage' => 'local',
        'public_asset' => 'none',
        'cache' => 'file',
        'session' => 'database',
        'queue' => 'database',
        'log' => 'daily',
        'websocket' => '',
        'mail' => 'smtp',
        'search' => 'mysql-fulltext',
    ];

    /**
     * 카테고리별 Laravel Config 키 매핑
     *
     * @var array<string, string>
     */
    public const CONFIG_KEYS = [
        'storage' => 'filesystems.default',
        'public_asset' => 'core.storage.public_asset_disk',
        'cache' => 'cache.default',
        'session' => 'session.driver',
        'queue' => 'queue.default',
        // 실제 적용 키 — SettingsServiceProvider::applyLogConfig() 가 기록하는 키와 동일해야
        // 플러그인 로그 드라이버 폴백이 실효한다 (logging.default 는 어디에서도 읽지 않는 죽은 키였다).
        // 이 키의 값은 채널 "배열" 이다 — getConfigValueForDriver() 로 형태를 맞춘다.
        'log' => 'logging.channels.stack.channels',
        'websocket' => 'broadcasting.default',
        'mail' => 'mail.default',
        // SettingsServiceProvider::applyDriverConfig() 가 기록하는 키와 동일해야 폴백이 실효한다
        'search' => 'scout.driver',
    ];

    /**
     * 카테고리별 JSON 설정 키 매핑 (JsonConfigRepository 카테고리 + 키)
     *
     * @var array<string, array{category: string, key: string}>
     */
    private const SETTINGS_KEYS = [
        'storage' => ['category' => 'drivers', 'key' => 'storage_driver'],
        'public_asset' => ['category' => 'drivers', 'key' => 'public_asset_disk'],
        'cache' => ['category' => 'drivers', 'key' => 'cache_driver'],
        'session' => ['category' => 'drivers', 'key' => 'session_driver'],
        'queue' => ['category' => 'drivers', 'key' => 'queue_driver'],
        'log' => ['category' => 'drivers', 'key' => 'log_driver'],
        // websocket 은 드라이버 ID 선택 설정이 없다 (불리언 websocket_enabled 뿐) —
        // 종전의 'websocket_driver' 는 어떤 저장 경로에도 없는 유령 키라 항상 skip 이었다.
        // 카테고리 제외로 같은 동작을 명시화한다 (getSettingsKey('websocket') === null).
        'mail' => ['category' => 'mail', 'key' => 'mailer'],
        'search' => ['category' => 'drivers', 'key' => 'search_engine_driver'],
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
     * 라벨은 활성 translatable_locales 전 로케일별로 lang/{locale}/settings.php 의
     * 'drivers.{category}.{id}' 키에서 조회되어 JSON 으로 반환됩니다.
     *
     * @param  string  $category  드라이버 카테고리 (storage, cache, session, queue, log, websocket, mail)
     * @return array<array{id: string, label: array<string, string>, provider?: string}> 사용 가능한 드라이버 배열
     */
    public function getAvailableDrivers(string $category): array
    {
        $coreDrivers = $this->buildCoreDrivers($category);

        // 검색엔진은 Scout 엔진 등록 훅이 SSoT 다 — 플러그인에 제2의 등록 훅을 요구하지 않고
        // 그 훅을 함께 읽는다. 일반 드라이버 훅도 그대로 유지해 양쪽 등록 방식을 모두 인식한다.
        if ($category === 'search') {
            $coreDrivers = $this->mergeSearchEngineDrivers($coreDrivers);
        }

        $hookName = self::HOOK_PREFIX.$category.self::HOOK_SUFFIX;

        return HookManager::applyFilters($hookName, $coreDrivers);
    }

    /**
     * Scout 엔진 등록 훅의 드라이버를 카탈로그 형태로 병합합니다.
     *
     * `core.search.engine_drivers` 는 `[id => EngineClass]` 맵이므로 키만 취해
     * `{id, label}` 형태로 변환한다. 이미 코어 목록에 있는 ID 는 중복 추가하지 않는다.
     *
     * @param  array<array{id: string, label: array<string, string>}>  $coreDrivers  코어 드라이버 목록
     * @return array<array{id: string, label: array<string, string>}> 병합된 목록
     */
    private function mergeSearchEngineDrivers(array $coreDrivers): array
    {
        $engines = HookManager::applyFilters('core.search.engine_drivers', []);

        if (! is_array($engines)) {
            return $coreDrivers;
        }

        $existing = array_map(fn ($driver) => $driver['id'] ?? null, $coreDrivers);
        $locales = config('app.translatable_locales', ['ko', 'en']);

        foreach (array_keys($engines) as $id) {
            if (! is_string($id) || $id === '' || in_array($id, $existing, true)) {
                continue;
            }

            $label = [];
            foreach ($locales as $locale) {
                $label[$locale] = Lang::get("settings.drivers.search.{$id}", [], $locale) ?: $id;
            }

            $coreDrivers[] = ['id' => $id, 'label' => $label];
        }

        return $coreDrivers;
    }

    /**
     * 모든 카테고리의 사용 가능한 드라이버 목록을 반환합니다.
     *
     * @return array<string, array<array{id: string, label: array<string, string>, provider?: string}>>
     */
    public function getAllAvailableDrivers(): array
    {
        $result = [];

        foreach (array_keys(self::CORE_DRIVER_IDS) as $category) {
            $result[$category] = $this->getAvailableDrivers($category);
        }

        return $result;
    }

    /**
     * 카테고리의 코어 드라이버 배열을 동적으로 빌드합니다.
     *
     * 활성 translatable_locales 전 로케일에 대해 lang/{locale}/settings.drivers.{category}.{id}
     * 키를 조회하여 JSON 라벨을 구성합니다. 로케일별 키가 없으면 ID 자체로 폴백합니다.
     *
     * @param  string  $category  드라이버 카테고리
     * @return array<array{id: string, label: array<string, string>}>
     */
    private function buildCoreDrivers(string $category): array
    {
        $ids = self::CORE_DRIVER_IDS[$category] ?? [];
        $locales = config('app.translatable_locales', ['ko', 'en']);

        $drivers = [];
        foreach ($ids as $id) {
            $label = [];
            foreach ($locales as $locale) {
                $label[$locale] = Lang::get("settings.drivers.{$category}.{$id}", [], $locale) ?: $id;
            }
            $drivers[] = ['id' => $id, 'label' => $label];
        }

        return $drivers;
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
        return in_array($driverId, self::CORE_DRIVER_IDS[$category] ?? [], true);
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
     * 주어진 드라이버가 현재 서버에서 실제로 동작 가능한지 판정합니다.
     *
     * isDriverAvailable() 이 "등록 여부"(카탈로그 소속)를 보는 것과 달리, 이 메서드는
     * 어댑터 클래스·PHP 확장 등 런타임 능력의 존재를 검사합니다. 사용 불능 드라이버가
     * 저장되면 다음 부팅에서 사이트 전면 다운으로 이어질 수 있으므로 저장/테스트
     * FormRequest 의 서버 게이트가 이 판정을 사용합니다.
     *
     * @param  string  $category  드라이버 카테고리 (storage, cache, session, queue 등)
     * @param  string  $driverId  드라이버 ID
     * @return bool 동작 가능하면 true
     */
    public function isDriverUsable(string $category, string $driverId): bool
    {
        return $this->usabilityFailureReason($category, $driverId) === null;
    }

    /**
     * 드라이버 사용 불능 사유를 반환합니다.
     *
     * 판정은 드라이버 ID 가 요구하는 런타임 능력 기준입니다. 코어가 능력을 모르는
     * 드라이버(플러그인 등록 드라이버 포함)는 null(사용 가능) — 플러그인 드라이버의
     * 가용성은 그 플러그인의 ServiceProvider 가 보증합니다.
     *
     * @param  string  $category  드라이버 카테고리
     * @param  string  $driverId  드라이버 ID
     * @return string|null 사용 불능 사유 (다국어), 사용 가능하면 null
     */
    public function usabilityFailureReason(string $category, string $driverId): ?string
    {
        return match ($driverId) {
            's3' => class_exists(AwsS3V3Adapter::class)
                ? null
                : __('settings.driver_unusable_s3_adapter'),
            // redis 는 설정된 클라이언트 기준으로 판정한다 — predis 명시 설정이면 predis
            // 존재만 본다. phpredis 설정은 확장 부재여도 부트 폴백(SettingsServiceProvider::
            // shouldFallBackToPredis)이 predis 로 전환하므로 predis 존재까지 사용 가능이다.
            'redis' => (config('database.redis.client', 'phpredis') === 'predis'
                ? class_exists(Client::class)
                : (extension_loaded('redis') || class_exists(Client::class)))
                    ? null
                    : __('settings.driver_unusable_redis_client'),
            'memcached' => extension_loaded('memcached')
                ? null
                : __('settings.driver_unusable_memcached_extension'),
            default => null,
        };
    }

    /**
     * 카테고리의 Config 키에 기록할 값 형태로 드라이버 ID 를 변환합니다.
     *
     * log 카테고리의 적용 키(logging.channels.stack.channels)는 채널 배열이므로
     * 배열로 감싸고, 그 외 카테고리는 드라이버 ID 문자열 그대로입니다.
     *
     * @param  string  $category  드라이버 카테고리
     * @param  string  $driverId  드라이버 ID
     * @return string|array<int, string> Config 기록 값
     */
    public function getConfigValueForDriver(string $category, string $driverId): string|array
    {
        return $category === 'log' ? [$driverId] : $driverId;
    }

    /**
     * 지원되는 카테고리 목록을 반환합니다.
     *
     * @return array<string> 카테고리 이름 배열
     */
    public function getCategories(): array
    {
        return array_keys(self::CORE_DRIVER_IDS);
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
