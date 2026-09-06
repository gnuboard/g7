<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Services\ExtensionStaticCacheService;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿 관련 캐시를 무효화하는 기능을 제공하는 트레이트.
 *
 * 모듈, 플러그인 등의 확장 기능이 활성화/비활성화될 때
 * 캐시 버전을 증가시켜 프론트엔드가 새로운 캐시 키로 요청하도록 합니다.
 * 이전 버전 캐시는 TTL로 자연 만료됩니다.
 */
trait ClearsTemplateCaches
{
    /**
     * 캐시 버전 키 (드라이버 접두사 `g7:core:` 다음에 붙음).
     */
    private static string $extensionCacheVersionKey = 'ext.cache_version';

    /**
     * 확장 캐시 버전·custom 서명 키의 저장 TTL (초) — 10년.
     *
     * 이 키들은 **만료로 재생성되어서는 안 된다.** 버전 키가 만료되면 다음 독자가
     * `regenerateExtensionCacheVersion()` 으로 새 `time()` 을 만들고, 그것은 정적 게시본
     * (`public/build/ext/{v}/`, 실측 715파일·40MB) **전체 재생성** + 병합 번들 재병합 + 전
     * 방문자의 자산 URL 변경이다. TTL 을 넘기지 않으면 `AbstractCacheDriver::put()` 이
     * `cache.default_ttl`(기본 86400) 을 적용해 그 재생성이 **매일** 우발적으로 일어났다(#651 F11).
     *
     * 영구 저장의 두 후보를 쓰지 않는 이유:
     *  - `forever()` 는 `CacheInterface` 밖이다 — 인터페이스 확장은 확장 공개 표면 변경이라
     *    번들 확장 전수의 `g7_version` 동기화 의무를 낳는다.
     *  - `put(…, 0)` 은 Laravel `Repository::put` 이 `seconds <= 0` 을 **forget** 으로 처리해
     *    키를 지운다. 파일 스토어는 만료를 `9999999999` 로 캡하므로 큰 TTL 은 안전하다.
     *
     * 트레이트 안에서는 `self::` 로 읽지 않는다 — 트레이트 정적 메서드를 트레이트 이름으로 직접
     * 부르는 호출(테스트·레거시)에서 `self` 가 트레이트 자신으로 해석되어 "Cannot access trait
     * constant directly" 가 난다. 트레이트를 조합한 코어 서비스 클래스 경유로 읽는다.
     */
    public const PERSISTENT_TTL_SECONDS = 315360000;

    /**
     * 프로세스 1회 메모이즈된 확장 좌표 캐시 스토어 이름 (write/read 스토어 일관성).
     * `extensionCacheStore()` 가 최초 1회 채우며, 테스트는 `resetExtensionCacheStoreMemo()`
     * 로 초기화한다. null = 미해소.
     */
    private static ?string $extensionCacheStore = null;

    /**
     * 확장 기능 캐시 버전을 증가시킵니다.
     *
     * 모듈/플러그인/템플릿 활성화/비활성화/설치/삭제 시 호출되어
     * 프론트엔드가 새로운 캐시 버전으로 API를 요청하도록 합니다.
     * 이전 버전 캐시는 TTL로 자연 만료됩니다.
     */
    protected function incrementExtensionCacheVersion(): void
    {
        try {
            $newVersion = time();
            self::resolveExtensionCache()->put(self::$extensionCacheVersionKey, $newVersion, ExtensionStaticCacheService::PERSISTENT_TTL_SECONDS);

            Log::info('확장 기능 캐시 버전 증가', [
                'new_version' => $newVersion,
            ]);
        } catch (\Exception $e) {
            Log::warning('확장 기능 캐시 버전 증가 중 오류', [
                'error' => $e->getMessage(),
            ]);
        }

        // 부트스트랩 리소스 정적 게시(bake) 예약 — 모든 bump 호출부(수명주기 전체)가
        // 이 단일 지점을 경유하므로 재게시 트리거 누락이 구조적으로 불가능하다 (#122).
        // terminating 시점에 프로세스당 1회, 실행 시점의 최종 버전으로 게시된다
        // (연속 bump 자연 병합). 실패해도 사이트는 API 폴백으로 정상.
        ExtensionStaticCacheService::schedulePublishOnTerminate();
    }

    /**
     * 현재 확장 기능 캐시 버전을 반환합니다.
     *
     * 키가 없거나 무효값(0)이면 — `php artisan cache:clear` 로 `ext.cache_version`
     * 키가 소실된 경우 — 그 자리에서 새 `time()` 버전을 생성·저장하고 그 값을 반환한다.
     * 0 을 그대로 내려보내면 모든 자원 URL 이 `?v=0` 으로 수렴하고, 과거 `?v=0` 에
     * 1년 immutable 로 박힌 구버전 에셋을 브라우저가 재검증 없이 영구 사용한다
     * (cache:clear 후 구버전 모듈 JS 가 고착되어 "Unknown action handler" 회귀).
     * 유효한 새 버전을 내려주면 프론트가 새 URL(`?v={새값}`)로 요청해 자동 해소된다.
     *
     * 읽기 메서드에 쓰기 부수효과가 생기지만 호출처는 모두 "현재 유효 버전 1개" 를
     * 원하므로 의미상 정확하다. cache:clear 직후 동시 요청 경합은 (a) 같은 초면 동일
     * `time()`, (b) 달라도 둘 다 유효하고 다음 요청에 수렴 → 0 붕괴보다 명백히 안전하다.
     *
     * @return int 캐시 버전 (타임스탬프). 키 부재/무효 시 새로 생성된 유효 버전.
     */
    public static function getExtensionCacheVersion(): int
    {
        $version = (int) self::resolveExtensionCache()->get(self::$extensionCacheVersionKey, 0);

        if ($version > 0) {
            return $version;
        }

        return self::regenerateExtensionCacheVersion();
    }

    /**
     * 캐시 버전 키 부재/무효 시 새 유효 버전을 생성·저장하고 반환합니다.
     *
     * static 컨텍스트에서 호출되므로 인스턴스 메서드(incrementExtensionCacheVersion)
     * 대신 동일 로직을 직접 수행한다. 저장 실패 시에도 0 으로 붕괴하지 않도록
     * 생성한 `time()` 값을 반환한다(다음 요청이 다시 생성·저장 시도).
     *
     * 키는 `PERSISTENT_TTL_SECONDS` 로 저장되므로 만료로 이 경로에 오지 않는다 — 키 부재는
     * `php artisan cache:clear` 또는 캐시 스토어 소실 때만이다.
     *
     * @return int 새로 생성된 유효 캐시 버전 (타임스탬프)
     */
    private static function regenerateExtensionCacheVersion(): int
    {
        $newVersion = time();

        try {
            self::resolveExtensionCache()->put(self::$extensionCacheVersionKey, $newVersion, ExtensionStaticCacheService::PERSISTENT_TTL_SECONDS);

            Log::info('확장 기능 캐시 버전 재생성 (키 부재/무효)', [
                'new_version' => $newVersion,
            ]);
        } catch (\Exception $e) {
            Log::warning('확장 기능 캐시 버전 재생성 중 오류', [
                'error' => $e->getMessage(),
            ]);
        }

        // 재생성도 bump 다 — 게시를 예약한다. `incrementExtensionCacheVersion()` 과
        // 동형화해 "캐시 버전 갱신 → 게시" 가 **모든 경로**에서 성립하게 한다 (#122).
        //
        // 이 경로가 빠져 있던 동안 `cache:clear` 후 첫 호출자가 CLI 면 포인터만 새 버전으로
        // 점프하고 산출물은 옛 버전에 남았다. 스케줄 GC(`ext-static:cleanup`)가 바로 그
        // 첫 호출자라, cleanup 첫 줄의 `getExtensionCacheVersion()` 이 실존하지 않는 새
        // 버전을 만들어 내고 보존 대상이 `[없는 새 버전, 실존 최신 1개]` 가 되어 **진짜
        // 직전 버전이 삭제**됐다 (매일 04:28 재현). CLI 는 terminating 예약이 그대로
        // 유효하므로 커맨드 종료 시점에 게시가 수행된다.
        ExtensionStaticCacheService::schedulePublishOnTerminate();

        return $newVersion;
    }

    /**
     * 모든 활성 템플릿의 언어 캐시를 무효화합니다.
     *
     * 버전 있는 캐시는 incrementExtensionCacheVersion()으로 무효화됩니다.
     * 이전 버전 캐시는 TTL로 자연 만료됩니다.
     * 이 메서드는 호출 호환성 유지용입니다.
     */
    protected function clearAllTemplateLanguageCaches(): void
    {
        Log::info('템플릿 언어 캐시 무효화 (캐시 버전 증가로 처리)');
    }

    /**
     * 모든 활성 템플릿의 routes 캐시를 무효화합니다.
     *
     * 버전 있는 캐시는 incrementExtensionCacheVersion()으로 무효화됩니다.
     * 이전 버전 캐시는 TTL로 자연 만료됩니다.
     * 이 메서드는 호출 호환성 유지용입니다.
     */
    protected function clearAllTemplateRoutesCaches(): void
    {
        Log::info('템플릿 routes 캐시 무효화 (캐시 버전 증가로 처리)');
    }

    /**
     * 모든 활성 템플릿의 레이아웃 캐시를 무효화합니다.
     *
     * 버전 있는 캐시는 incrementExtensionCacheVersion()으로 무효화됩니다.
     * 이전 버전 캐시는 TTL로 자연 만료됩니다.
     * 이 메서드는 호출 호환성 유지용입니다.
     */
    protected function clearAllTemplateLayoutCaches(): void
    {
        Log::info('템플릿 레이아웃 캐시 무효화 (캐시 버전 증가로 처리)');
    }

    /**
     * 확장 기능 캐시 버전 저장에 사용할 코어 캐시 드라이버를 반환합니다.
     *
     * 확장 기능 캐시 버전(`ext.cache_version`)은 코어 소유 키이므로 항상
     * `g7:core:` 접두사 네임스페이스에 저장/조회되어야 한다. 따라서
     * 컨테이너의 `CacheInterface` 바인딩(모듈/플러그인 테스트가 일시적으로
     * `PluginCacheDriver` 등으로 재바인딩할 수 있음)에 의존하지 않고
     * 항상 CoreCacheDriver 를 직접 생성한다.
     *
     * 스토어는 **고정 결정적 스토어**(`extensionCacheStore()`)를 쓴다 —
     * `config('cache.default')` 를 직접 쓰면 `SettingsServiceProvider::applyCacheConfig`
     * 가 부팅 중 admin 설정(`g7_core_settings('cache.driver')`)으로 `cache.default` 를
     * 런타임 오버라이드하므로, settings 로드 타이밍/컨텍스트(웹 vs CLI vs 큐, 설정
     * 미시드 환경)에 따라 write 와 read 가 **서로 다른 스토어**를 가리킬 수 있다.
     * 그 경우 bump 한 버전이 read 경로에서 보이지 않아 프론트엔드가 영구 stale
     * 캐시를 받는다(편집기 데이터소스 명칭/레이아웃 변경 미반영 — 반복 회귀).
     * 접두사가 코어 고정이듯 스토어도 코어 고정으로 일관시킨다.
     */
    private static function resolveExtensionCache(): CacheInterface
    {
        return new CoreCacheDriver(self::extensionCacheStore());
    }

    /**
     * custom 자산 변경 서명(`ext.custom_signature`)용 캐시 드라이버를 반환합니다.
     *
     * 버전 키와 **같은 고정 스토어·같은 코어 네임스페이스**를 쓴다. 서명이 버전 키와 다른
     * 스토어에 저장되면(`app(CacheInterface::class)` 재바인딩 등) "첫 관측은 기록만" 규칙이
     * 스토어마다 따로 성립해 실제 변경 1회를 조용히 삼킨다(#651 F8). 서명을 쓰는 뷰 컴포저와
     * 지우는 관리 API 가 같은 통로를 써야 하므로 트레이트가 공급한다.
     *
     * @return CacheInterface 코어 네임스페이스 캐시 드라이버
     */
    protected static function customSignatureCache(): CacheInterface
    {
        return new CoreCacheDriver(self::extensionCacheStore());
    }

    /**
     * 확장 좌표 키(`ext.cache_version`)의 고정 캐시 스토어 이름을 반환합니다.
     *
     * **프로세스 1회 메모이즈** — 최초 호출 시점의 스토어를 캡처해 같은 프로세스 안에서
     * write 와 read 가 항상 동일 스토어를 쓰도록 고정한다. `SettingsServiceProvider::
     * applyCacheConfig` 가 부팅 중 `cache.default` 를 admin 설정으로 오버라이드하므로,
     * 메모이즈 없이 매번 `config('cache.default')` 를 읽으면 settings 적용 전/후 호출이
     * 서로 다른 스토어를 가리켜 bump 가 read 에서 안 보이는 회귀가 난다(편집기 명칭/
     * 레이아웃 변경 미반영 — 반복 제보).
     *
     * 비영속 `array` 스토어는 명시 회피(프로세스 경계에서 유실 → CLI write/웹 read
     * 불일치). `array` 만 가용한 환경(일부 테스트)에서는 그대로 array 를 쓰되, 그 경우
     * 동일 프로세스 안에서는 일관되므로 단위 테스트 격리에는 영향 없다.
     *
     * @return string 캐시 스토어 이름
     */
    private static function extensionCacheStore(): string
    {
        if (self::$extensionCacheStore !== null) {
            return self::$extensionCacheStore;
        }

        $configured = config('cache.default');
        self::$extensionCacheStore = is_string($configured) && $configured !== ''
            ? $configured
            : 'file';

        return self::$extensionCacheStore;
    }

    /**
     * 테스트 격리용 — 메모이즈된 스토어를 초기화한다(setUp/tearDown 에서 호출 가능).
     */
    public static function resetExtensionCacheStoreMemo(): void
    {
        self::$extensionCacheStore = null;
    }
}
