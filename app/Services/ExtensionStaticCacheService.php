<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Exceptions\StaticCachePublishException;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Helpers\ResponseHelper;
use App\Models\Template;
use App\Rules\AllowedTemplateFileType;
use App\Support\CustomAssets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 부트스트랩 리소스 정적 게시(bake) 서비스.
 *
 * 병합 결과물(다국어·컴포넌트 정의·라우트·확장 번들·템플릿 dist 에셋)을
 * 캐시 버전 디렉토리(`public/build/ext/{v}/`)에 실파일로 게시해 웹서버가
 * rewrite 전에 직접 서빙하게 한다 (#122). 병합 로직은 새로 만들지 않고
 * 전부 기존 SSoT(TemplateService/ExtensionBundleService)를 호출한다.
 *
 * 원자성: `{v}.tmp/` 에 전부 쓴 뒤 디렉토리 rename → `{v}/`, manifest.json 은
 * rename 후 마지막에 기록한다. manifest 존재 = 게시 완료(부분 게시 참조 방지).
 *
 * 실패 정책: 쓰기 실패는 예외를 삼키고 Log::warning + tmp 정리 — 사이트는
 * API 폴백으로 정상 유지된다(fail-open 이 아니라 "정적 fast path 미적용" 상태).
 */
class ExtensionStaticCacheService
{
    use ClearsTemplateCaches;

    /** 게시 완료 마커 파일명 */
    private const MANIFEST_FILE = 'manifest.json';

    /** 게시 락 이름 접두사 */
    private const LOCK_PREFIX = 'ext-static.publish.';

    /** 확장 식별자 패턴 (vendor-name) — 경로 세그먼트 화이트리스트 */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9]+-[a-z0-9_]+$/';

    /** 로케일 패턴 — 경로 세그먼트 화이트리스트 */
    private const LOCALE_PATTERN = '/^[a-z]{2}(?:[-_][A-Za-z0-9]{2,8})?$/';

    /** 게시 작업 디렉토리(.tmp/.old)를 미완료 잔존물로 보는 나이 (초) — 게시 락 TTL 300 의 2배 */
    private const WORK_DIR_STALE_SECONDS = 600;

    /** 게시 실패 마커 캐시 키 */
    private const FAILURE_MARKER_KEY = 'ext.static.publish_failure';

    /** 실패 마커 TTL (초) — 이 창 안에서는 같은 버전의 재예약을 억제한다 */
    private const FAILURE_MARKER_TTL = 300;

    /** 게시 트리 디렉토리 권한 — umask 무력화 대상 */
    private const PUBLISH_DIR_MODE = 0775;

    /** 게시 트리 디렉토리 rename 시도 횟수 (일시 거부 흡수) */
    private const RENAME_ATTEMPTS = 3;

    /** rename 재시도 간 대기 (마이크로초) — 실측 표본은 전부 1회 재시도로 해소됐다 */
    private const RENAME_RETRY_DELAY_US = 200_000;

    /** terminating 게시 예약 플래그 (프로세스당 1회) */
    private static bool $publishScheduled = false;

    /** 테스트 전용 — root 프로세스 판정 오버라이드 (null = 실판정) */
    private static ?bool $rootProcessForTesting = null;

    /** isPublished 요청당 메모이즈 (version => 존재 여부) */
    private array $publishedMemo = [];

    public function __construct(
        private TemplateService $templateService,
        private TemplateRepositoryInterface $templateRepository,
        private ModuleRepositoryInterface $moduleRepository,
        private PluginRepositoryInterface $pluginRepository,
        private ExtensionBundleService $bundleService,
        private LanguagePackService $languagePackService,
    ) {}

    /**
     * 현재 확장 캐시 버전 기준으로 게시합니다.
     *
     * 이미 게시 완료(manifest 존재) 상태면 skip(멱등). `Cache::lock` 으로 단일
     * 실행을 보장하며, 락 미획득 시 다른 프로세스가 게시 중인 것으로 보고 skip.
     *
     * @param  bool  $force  게시 완료 상태여도 강제 재게시
     * @return bool 게시 완료 상태로 끝났으면 true (skip 포함), 실패/비활성이면 false
     */
    public function publishCurrent(bool $force = false): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $version = self::getExtensionCacheVersion();

        if (! $force && $this->isPublished($version)) {
            return true;
        }

        // 락 미획득은 두 상황이고 조치가 다르다.
        //
        //  (a) 다른 프로세스가 게시 중 (정상)  — `get()` 이 예외 없이 false.
        //      건너뛰는 것이 옳은 동작이므로 마커를 남기지 않는다. 남기면 정상적인 동시
        //      요청 경합이 실패로 집계돼 대시보드에 거짓 장애 알림이 뜬다.
        //  (b) 캐시 저장소가 락을 제공하지 못함 (장애) — `Cache::lock()`/`get()` 이 던진다
        //      (락 미지원 드라이버, 파일 캐시 디렉토리 권한 불일치 등). 이 경우 게시는
        //      매 요청 조용히 스킵되므로 사유를 마커에 남겨 진단 표면까지 도달시킨다.
        try {
            $lock = Cache::lock(self::LOCK_PREFIX.$version, 300);
            $acquired = $lock->get();
        } catch (\Throwable $e) {
            Log::warning('정적 게시 락 획득 불가 — 캐시 저장소를 확인하세요', [
                'version' => $version,
                'store' => config('cache.default'),
                'error' => $e->getMessage(),
            ]);

            self::recordFailure($version, 'lock_unavailable', $e->getMessage());

            return false;
        }

        if (! $acquired) {
            Log::debug('정적 게시 락 미획득 — 이번 호출은 건너뜁니다', [
                'version' => $version,
                'store' => config('cache.default'),
            ]);

            return false;
        }

        try {
            // 락 대기 중 다른 프로세스가 완료했을 수 있다 (멱등 재확인)
            unset($this->publishedMemo[$version]);
            if (! $force && $this->isPublished($version)) {
                return true;
            }

            return $this->publishVersion($version);
        } finally {
            $lock->release();
        }
    }

    /**
     * 해당 버전이 게시 완료 상태인지 확인합니다 (manifest 존재 = 완료).
     *
     * AssetUrl 게이트가 요청당 여러 번 호출하므로 메모이즈한다.
     *
     * @param  int  $version  확장 캐시 버전
     * @return bool 게시 완료 여부
     */
    public function isPublished(int $version): bool
    {
        return $this->publishedMemo[$version] ??= is_file(
            $this->versionDir($version).DIRECTORY_SEPARATOR.self::MANIFEST_FILE
        );
    }

    /**
     * 현재 버전 + 직전 1개를 보존하고 나머지 게시 디렉토리를 삭제합니다.
     *
     * 직전 버전을 남기는 이유: 브라우저에 캐시된 직전 렌더 HTML 이 아직 구버전
     * 정적 URL 을 참조할 수 있다 (asset-url-recovery 파샬이 최후 방어).
     *
     * @return int 삭제된 디렉토리 수
     */
    public function cleanup(): int
    {
        $base = $this->baseDir();

        if (! File::isDirectory($base)) {
            return 0;
        }

        $current = self::getExtensionCacheVersion();
        $versions = [];
        $deleted = 0;

        foreach (File::directories($base) as $dir) {
            $name = basename($dir);

            // 진행 중 게시의 작업 디렉토리(`{v}.tmp`)와 스왑 대기분(`{v}.old`)은 나이로
            // 가른다 — 락은 **버전별**이라 서로 다른 버전의 게시가 동시에 진행될 수 있고,
            // 나이 무관 삭제는 그 순간 살아 있는 남의 tmp 를 파괴한다. 게시 락 TTL 이
            // 300초이므로 그 두 배를 미완료 판정 기준으로 삼는다.
            if (str_ends_with($name, '.tmp') || str_ends_with($name, '.old')) {
                if (! $this->isStaleWorkDirectory($dir)) {
                    continue;
                }

                $deleted += $this->deleteVersionDirectory($dir) ? 1 : 0;

                continue;
            }

            if (ctype_digit($name)) {
                $versions[(int) $name] = $dir;
            }
        }

        // 현재 버전과, 현재를 제외한 최신 1개(직전) 보존.
        //
        // 현재 버전 디렉토리가 **실존하지 않으면** 그 자리를 보존 슬롯으로 쓰지 않는다 —
        // 없는 것을 보존해 봐야 슬롯 하나를 버리는 셈이고, 그만큼 실존 버전이 한 개 더
        // 지워진다. 그 경우 "실존하는 최신 2개" 를 보존한다. 게시는 GC 시점 이후
        // (terminating)에 수행되므로 "포인터는 새 버전인데 산출물은 아직 없음" 은
        // 정상 상태이며, 브라우저에 배달된 직전 HTML 은 여전히 옛 버전 URL 을 참조한다.
        $keep = File::isDirectory($this->versionDir($current)) ? [$current] : [];
        $others = array_keys($versions);
        rsort($others);
        foreach ($others as $v) {
            if (in_array($v, $keep, true)) {
                continue;
            }

            $keep[] = $v;

            if (count($keep) >= 2) {
                break;
            }
        }

        foreach ($versions as $v => $dir) {
            if (! in_array($v, $keep, true)) {
                $deleted += $this->deleteVersionDirectory($dir) ? 1 : 0;
            }
        }

        return $deleted;
    }

    /**
     * 게시 작업 디렉토리(`.tmp` / `.old`)가 미완료 잔존물로 판정될 만큼 오래됐는지 봅니다.
     *
     * 나이를 읽지 못하는 경우(권한·경합으로 `filemtime` 실패)는 **삭제하지 않는다** —
     * 진행 중인 게시를 파괴하는 쪽이 잔존물을 한 주기 더 남기는 쪽보다 나쁘다.
     *
     * @param  string  $dir  검사할 작업 디렉토리 절대 경로
     * @return bool 삭제 대상 여부
     */
    private function isStaleWorkDirectory(string $dir): bool
    {
        $mtime = @filemtime($dir);

        if ($mtime === false) {
            return false;
        }

        return (time() - $mtime) > self::WORK_DIR_STALE_SECONDS;
    }

    /**
     * 게시 디렉토리를 삭제하고 성공 여부를 반환합니다.
     *
     * 반환값을 검사하지 않으면 소유권 불일치로 삭제가 실패해도 "N개 삭제" 로 보고되어,
     * 구버전·고아 tmp 가 무한 누적되는 동안 운영자에게는 정상으로 보인다.
     *
     * @param  string  $dir  삭제할 디렉토리 절대 경로
     * @return bool 삭제 성공 여부
     */
    private function deleteVersionDirectory(string $dir): bool
    {
        if (File::deleteDirectory($dir)) {
            return true;
        }

        Log::warning('정적 게시 디렉토리 삭제 실패 — 잔존물이 누적됩니다 (소유권/권한 확인 필요)', [
            'path' => $dir,
        ]);

        return false;
    }

    /**
     * 수명주기 이벤트에서 호출되는 terminating 게시 예약.
     *
     * `incrementExtensionCacheVersion()` 내부 단일 지점에서 호출된다.
     * 프로세스당 1회만 등록하며, 게시는 예약 시점이 아니라 **실행 시점의 현재
     * 버전**으로 수행되어 연속 bump(일괄 업데이트)를 자연 병합한다.
     *
     * 프로덕션 전용 — 비프로덕션은 blade 가 정적 URL 을 방출하지 않으므로
     * 게시 자체가 무의미하고(§2-2), testing 환경의 파일 쓰기 부수효과도 차단한다.
     */
    public static function schedulePublishOnTerminate(): void
    {
        if (self::$publishScheduled || ! app()->environment('production')) {
            return;
        }

        // root 프로세스(sudo 코어 업데이트 등)에서는 예약하지 않는다 — 게시가 만드는
        // 캐시 락 샤드 디렉토리(storage/framework/cache/data/xx)와 병합 번들
        // (storage/app/ext-bundles)이 root 소유로 남아, 이후 웹 프로세스의 캐시
        // 쓰기가 그 샤드에 해시되는 순간 Permission denied 로 죽는다 (실사례:
        // sudo 업데이트 직후 전면 500). normalizeOwnership 은 게시 트리(build/ext)만
        // 다루므로 storage 측 부수 산출물은 회피가 정답이다. 게시는 다음 웹 렌더의
        // 자가 치유(웹 계정)가 수행하고, 명시적 `ext-static:publish` 커맨드는 이
        // 게이트를 거치지 않는다 (운영자 책임 — 규정 문서 §6).
        if (self::isRootProcess()) {
            return;
        }

        // 신선한 실패 마커가 있으면 예약하지 않는다 (백오프).
        //
        // 쓰기 불가 환경(게시 트리 소유권 불일치 등)에서는 게시가 **매 요청** 실패하는데,
        // 실패 전까지 전 로케일 lang 병합 + 전 템플릿 dist 복사를 이미 다 헛돈 뒤다.
        // 사이트는 API 폴백으로 살아 있어 아무도 눈치채지 못한 채 모든 프로덕션 요청이
        // 그 비용을 낸다. 마커 TTL 창당 1회로 억제한다.
        //
        // 억제 대상은 **같은 버전**뿐이다. 버전이 오르면 그 버전은 아직 한 번도 시도된
        // 적이 없으므로, 이전 버전의 마커로 막으면 "버전 갱신 → 게시" 규율(D1)이 TTL
        // 창(최대 300초) 동안 도로 끊긴다.
        if (self::hasFreshFailureMarker(self::getExtensionCacheVersion())) {
            return;
        }

        self::$publishScheduled = true;

        app()->terminating(static function (): void {
            // 실행 시점에 재무장 가능 상태로 복귀 — 요청마다 앱 인스턴스를 새로 쓰는
            // 장수 프로세스(Octane 류)에서는 static 플래그만 살아남으므로, 리셋 없이는
            // 2번째 이후 bump 가 새 앱에 콜백을 등록하지 못한 채 영구 미게시가 된다
            // (자가 치유도 같은 플래그 공유). FPM(요청=프로세스)에서는 무영향.
            self::$publishScheduled = false;

            try {
                app(self::class)->publishCurrent();
            } catch (\Throwable $e) {
                Log::warning('정적 게시 terminating 실행 실패 — 다음 렌더의 자가 치유가 재시도합니다', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 테스트 전용 — terminating 예약 플래그의 현재 값을 반환합니다.
     *
     * 게시 예약은 부수효과가 `app()->terminating()` 콜백 등록뿐이라 밖에서 관측할 방법이
     * 없다. "예약했는가" 를 단언하려면 이 플래그가 유일한 통로다.
     *
     * @return bool 예약 여부
     */
    public static function isPublishScheduledForTesting(): bool
    {
        return self::$publishScheduled;
    }

    /**
     * 테스트 격리용 — terminating 예약 플래그를 초기화합니다.
     */
    public static function resetPublishScheduleForTesting(): void
    {
        self::$publishScheduled = false;
        self::$rootProcessForTesting = null;
    }

    /**
     * 최근 게시 실패 마커를 반환합니다 (없으면 null).
     *
     * 진단 표면(`ext-static:status`, 대시보드 알림)이 읽는 유일한 통로다 — 게시 실패는
     * 사이트를 멈추지 않고 API 폴백으로 넘어가므로, 이 마커가 없으면 정상 운영 환경에서
     * 실패를 확인할 방법이 로그뿐이다.
     *
     * @return array{version:int, at:string, reason:string, count:int, message:string}|null 실패 마커
     */
    public static function failureMarker(): ?array
    {
        try {
            $marker = self::markerCache()->get(self::FAILURE_MARKER_KEY);
        } catch (\Throwable) {
            // 마커 저장소 자체가 불능인 환경 — best-effort 다 (아래 recordFailure 주석 참조)
            return null;
        }

        return is_array($marker) ? $marker : null;
    }

    /**
     * 게시 실패를 마커에 기록합니다 (연속 실패 횟수 누적).
     *
     * best-effort 다 — 마커를 쓰지 못하는 환경(캐시 저장소 자체가 불능)이 실재하며,
     * 그 경우 백오프는 걸리지 않는다. 다만 in-process `$publishScheduled` 가드는 그대로
     * 유효하므로 한 요청 안에서 반복되지는 않는다.
     *
     * @param  int  $version  실패한 확장 캐시 버전
     * @param  string  $reason  실패 사유 코드 (`parent_not_writable` / `write_failed` / `lock_unavailable`)
     * @param  string  $message  진단용 원문 메시지
     */
    private static function recordFailure(int $version, string $reason, string $message): void
    {
        try {
            $previous = self::failureMarker();
            $count = ($previous !== null && ($previous['version'] ?? null) === $version)
                ? (int) ($previous['count'] ?? 0) + 1
                : 1;

            self::markerCache()->put(self::FAILURE_MARKER_KEY, [
                'version' => $version,
                'at' => now()->toIso8601String(),
                'reason' => $reason,
                'count' => $count,
                'message' => $message,
            ], self::FAILURE_MARKER_TTL);
        } catch (\Throwable $e) {
            Log::debug('정적 게시 실패 마커 기록 실패 (백오프 미적용)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 게시 성공 시 실패 마커를 제거합니다.
     */
    private static function clearFailureMarker(): void
    {
        try {
            self::markerCache()->forget(self::FAILURE_MARKER_KEY);
        } catch (\Throwable) {
            // 마커 제거 실패는 무해하다 — TTL 로 자연 만료된다
        }
    }

    /**
     * 지정 버전에 대한 TTL 창 안의 실패 마커가 존재하는지 판정합니다 (백오프 게이트).
     *
     * 버전을 한정하지 않으면 bump 직후의 새 버전이 이전 버전의 마커로 억제된다 —
     * 아직 한 번도 시도되지 않은 버전을 실패로 취급하는 셈이다.
     *
     * @param  int  $version  판정할 확장 캐시 버전
     * @return bool 해당 버전의 신선한 실패 마커 존재 여부
     */
    private static function hasFreshFailureMarker(int $version): bool
    {
        $marker = self::failureMarker();

        return $marker !== null && ($marker['version'] ?? null) === $version;
    }

    /**
     * 실패 마커용 캐시 드라이버를 반환합니다.
     *
     * 캐시 버전 키와 **같은 스토어**를 쓴다 — 마커를 쓰는 쪽(게시)과 읽는 쪽(진단 커맨드·
     * 대시보드 알림)이 다른 스토어를 가리키면 실패가 기록돼도 화면에는 영영 뜨지 않는다.
     * `ClearsTemplateCaches::extensionCacheStore()` 는 프로세스 1회 메모이즈된 고정 스토어라
     * settings 로드 타이밍에 좌우되지 않는다.
     *
     * @return CacheInterface 코어 네임스페이스 캐시 드라이버
     */
    private static function markerCache(): CacheInterface
    {
        return new CoreCacheDriver(self::extensionCacheStore());
    }

    /**
     * 테스트 전용 — root 프로세스 판정을 강제합니다 (null 로 실판정 복귀).
     *
     * @param  bool|null  $isRoot  강제할 판정값
     */
    public static function fakeRootProcessForTesting(?bool $isRoot): void
    {
        self::$rootProcessForTesting = $isRoot;
    }

    /**
     * 현재 프로세스가 root(euid 0)로 실행 중인지 판정합니다.
     *
     * posix 확장이 없는 환경(Windows, 함수 비활성 호스팅)은 root 아님으로 본다.
     *
     * @return bool root 실행 여부
     */
    private static function isRootProcess(): bool
    {
        if (self::$rootProcessForTesting !== null) {
            return self::$rootProcessForTesting;
        }

        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * 게시 루트 디렉토리 절대 경로를 반환합니다.
     *
     * @return string `public/build/ext` 절대 경로
     */
    public function baseDir(): string
    {
        return public_path('build/ext');
    }

    /**
     * 버전 디렉토리 절대 경로를 반환합니다.
     *
     * @param  int  $version  확장 캐시 버전
     * @return string 버전 디렉토리 절대 경로
     */
    public function versionDir(int $version): string
    {
        return $this->baseDir().DIRECTORY_SEPARATOR.$version;
    }

    /**
     * kill-switch 판정 (`core.static_cache.enabled`, .env `G7_STATIC_CACHE`).
     *
     * @return bool 정적 게시 활성 여부
     */
    public function isEnabled(): bool
    {
        return (bool) config('core.static_cache.enabled', true);
    }

    /**
     * 한 버전의 게시를 실제 수행합니다 (tmp 쓰기 → rename → manifest → GC).
     *
     * @param  int  $version  게시할 확장 캐시 버전
     * @return bool 성공 여부
     */
    private function publishVersion(int $version): bool
    {
        $base = $this->baseDir();
        $tmp = $base.DIRECTORY_SEPARATOR.$version.'.tmp';
        $final = $this->versionDir($version);
        $old = $final.'.old';
        $swapped = false;

        // 프리플라이트 — 게시 트리를 만들 수 없는 환경이면 병합을 시작하기 전에 끊는다.
        // 전 로케일 lang 병합 + 전 템플릿 dist 복사를 다 헛돈 뒤 mkdir 에서 실패하는
        // 것과, 시작 전에 사유를 남기고 끊는 것은 비용이 전혀 다르다.
        if (! $this->ensurePublishRootWritable($version)) {
            return false;
        }

        try {
            File::deleteDirectory($tmp);
            $this->makeDirectory($tmp);

            $files = [];

            $this->writeHtaccess($tmp, $files);

            $locales = $this->publishableLocales();

            foreach ($this->templateRepository->getActive() as $template) {
                $this->publishTemplate($tmp, $template, $locales, $files);
            }

            $this->publishBundles($tmp, $version, $files);

            $this->publishExtensionCustomAssets($tmp, $files);

            // 원자적 스왑 — 기존 디렉토리를 **먼저 지우지 않는다.** 삭제 후 rename 사이의
            // 창에서는 이미 배달된 HTML 이 참조하는 CSS/JS/폰트가 전부 404 가 되고, 폰트는
            // 복구기가 없다. 대신 `.old` 로 비켜낸 뒤 rename 하고, 성공을 확인한 다음에
            // `.old` 를 지운다. 중간 실패 시 `.old` 를 제자리로 되돌린다.
            File::deleteDirectory($old);

            if (File::isDirectory($final) && ! $this->renameDirectory($final, $old)) {
                throw new StaticCachePublishException("Failed to move aside publish directory: {$final} -> {$old}");
            }

            $swapped = File::isDirectory($old);

            if (! $this->renameDirectory($tmp, $final)) {
                // 새 디렉토리를 앉히지 못했다 — 비켜낸 기존 버전을 즉시 되돌린다.
                if ($swapped && $this->renameDirectory($old, $final)) {
                    $swapped = false;
                }

                throw new StaticCachePublishException("Failed to rename publish directory: {$tmp} -> {$final}");
            }

            // manifest 는 rename 후 마지막 기록 — 존재 = 게시 완료
            $this->writeManifest($final, $version, $files);
            unset($this->publishedMemo[$version]);

            // 새 버전이 완성된 뒤에만 구버전을 치운다 (여기까지 오면 404 창이 없다)
            if ($swapped) {
                File::deleteDirectory($old);
                $swapped = false;
            }

            // sudo/root CLI 게시 대응 — terminating 게시는 코어 업데이트의
            // restoreOwnership **이후**(프로세스 종료 시)에 실행되므로, root 소유로
            // 남으면 이후 php-fpm 의 재게시·GC 가 영구 실패한다. 부모(public/build)
            // 소유권을 상속시킨다 (FilePermissionHelper::copyFile 의 sudo 대응 선례).
            $this->normalizeOwnership();

            // 인라인 GC (현재 + 직전 1개 보존)
            $this->cleanup();

            self::clearFailureMarker();

            Log::info('부트스트랩 리소스 정적 게시 완료', [
                'version' => $version,
                'files' => count($files),
            ]);

            return true;
        } catch (\Throwable $e) {
            // 예외 종류와 발생 위치까지 남긴다. 메시지만으로는 파일시스템 오류인지 병합
            // 오류인지 구분되지 않아, 운영자도 개발자도 재현부터 다시 해야 한다 —
            // 게시 실패는 사이트를 멈추지 않고 폴백으로 넘어가므로 이 로그가 유일한 흔적이다.
            Log::warning('부트스트랩 리소스 정적 게시 실패 — API 폴백으로 동작합니다', [
                'version' => $version,
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
            ]);

            // 정리 대상은 **실제 존재하는 쪽**이다. rename 이 이미 성공했다면 `$tmp` 는
            // 존재하지 않으므로 그것만 지우는 종전 코드는 no-op 이었고, manifest 기록이
            // 실패한 경우 manifest 없는 완성 디렉토리가 영구 잔존했다 —
            // `isPublished()` 가 영원히 false 라 요청마다 지웠다 만들기를 반복한다.
            File::deleteDirectory($tmp);

            if (! File::isDirectory($final.DIRECTORY_SEPARATOR)
                || ! is_file($final.DIRECTORY_SEPARATOR.self::MANIFEST_FILE)) {
                File::deleteDirectory($final);
            }

            // 비켜낸 기존 버전이 남아 있으면 제자리로 되돌린다 — 되돌리지 못하면
            // `.old` 로 둔 채 나이 가드가 붙은 GC 에 맡긴다(즉시 삭제하지 않는다).
            if ($swapped && ! File::isDirectory($final)) {
                $this->renameDirectory($old, $final);
            }

            unset($this->publishedMemo[$version]);

            self::recordFailure($version, 'write_failed', $e->getMessage());

            return false;
        }
    }

    /**
     * 게시 루트(`public/build/ext`)를 만들 수 있고 쓸 수 있는지 확인합니다.
     *
     * 부모(`public/build`)가 다른 계정 소유 + `g-w` 면 웹 프로세스는 `ext` 를 **mkdir 조차
     * 하지 못한다.** 그 경우 최초 게시가 CLI 로 강제되고, 그 순간 트리 소유권이 CLI 계정으로
     * 고정되어 이후 웹 재게시가 영구 실패한다 (제보 본건).
     *
     * @param  int  $version  게시 대상 버전 (실패 마커 기록용)
     * @return bool 게시를 진행해도 되는지 여부
     */
    private function ensurePublishRootWritable(int $version): bool
    {
        $base = $this->baseDir();

        if (! File::isDirectory($base)) {
            // 게시 루트가 아직 없으면 만든다. 검사 대상은 `public/build` 고정이 아니라
            // **실재하는 최근접 조상**이다 — `public/build` 자체가 없는 환경(신규 설치,
            // 테스트 격리 public 경로)에서 부모 존재를 요구하면 정상 상황을 실패로 만든다.
            $ancestor = $this->nearestExistingAncestor($base);

            if ($ancestor === null || ! is_writable($ancestor)) {
                $this->failPreflight(
                    $version,
                    $ancestor ?? dirname($base),
                    '게시 루트를 만들 상위 디렉토리에 쓸 수 없습니다'
                );

                return false;
            }

            // 조상이 쓰기 가능해도 mkdir 은 실패할 수 있다 — 경로 중간이 **파일**이거나
            // 경합으로 사라지는 경우다. `ensureDirectoryExists` 는 그 실패를 예외로
            // 던지는데, 이 프리플라이트는 `publishVersion` 의 try 블록 **밖**에서 돌므로
            // 잡지 않으면 예외가 호출자에게 그대로 새어 나간다 — 게시 실패는 사이트를
            // 멈추지 않는다는 계약이 그 지점에서 깨진다.
            try {
                $this->makeDirectory($base);
            } catch (\Throwable $e) {
                $this->failPreflight($version, $base, '게시 루트를 만들지 못했습니다: '.$e->getMessage());

                return false;
            }
        }

        clearstatcache(true, $base);

        if (! File::isDirectory($base) || ! is_writable($base)) {
            $this->failPreflight($version, $base, '게시 루트에 쓸 수 없습니다');

            return false;
        }

        return true;
    }

    /**
     * 경로에서 위로 올라가며 실재하는 첫 디렉토리를 찾습니다.
     *
     * @param  string  $path  기준 경로
     * @return string|null 실재하는 최근접 조상 (루트까지 없으면 null)
     */
    private function nearestExistingAncestor(string $path): ?string
    {
        $current = dirname($path);

        while (! File::isDirectory($current)) {
            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }

        return $current;
    }

    /**
     * 프리플라이트 실패를 로그 + 실패 마커에 남깁니다.
     *
     * @param  int  $version  게시 대상 버전
     * @param  string  $path  쓰기 불가로 판정된 경로
     * @param  string  $summary  사람이 읽는 요약
     */
    private function failPreflight(int $version, string $path, string $summary): void
    {
        $detail = sprintf(
            '%s (%s, owner=%s, perms=%s, process_user=%s)',
            $summary,
            $path,
            (string) (@fileowner($path) ?: 'unknown'),
            File::exists($path) ? substr(sprintf('%o', @fileperms($path)), -4) : 'absent',
            self::currentProcessUser(),
        );

        Log::warning('부트스트랩 리소스 정적 게시 프리플라이트 실패 — API 폴백으로 동작합니다', [
            'version' => $version,
            'path' => $path,
            'reason' => 'parent_not_writable',
            'detail' => $detail,
        ]);

        self::recordFailure($version, 'parent_not_writable', $detail);
    }

    /**
     * 현재 프로세스의 실행 계정명을 반환합니다 (진단용, 미지원 환경은 'unknown').
     *
     * @return string 계정명 또는 uid 문자열
     */
    public static function currentProcessUser(): string
    {
        if (! function_exists('posix_geteuid')) {
            return 'unknown';
        }

        $uid = posix_geteuid();

        if (function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid($uid);

            if (is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }

        return (string) $uid;
    }

    /**
     * 게시 트리 디렉토리를 만들고 umask 와 무관하게 권한·소유권을 정합화합니다.
     *
     * `File::ensureDirectoryExists($dir, 0775)` 의 mode 인자는 **umask 로 깎인다** —
     * umask 022 환경에서는 0755 가 되어 웹 계정(그룹 공유)이 쓸 수 없다. 명시 `chmod` 로
     * umask 를 무력화하고, 부모 소유권을 상속시켜 CLI 계정 고정을 막는다.
     * (선례: `CoreUpdateService::ensureWritableDirectories`)
     *
     * @param  string  $dir  생성할 디렉토리 절대 경로
     */
    private function makeDirectory(string $dir): void
    {
        File::ensureDirectoryExists($dir, self::PUBLISH_DIR_MODE);

        // ensureDirectoryExists 의 mode 는 umask 로 깎이므로 명시 chmod 로 확정한다.
        @chmod($dir, self::PUBLISH_DIR_MODE);

        FilePermissionHelper::inheritOwnershipFromParent($dir);
    }

    /**
     * 게시 트리의 디렉토리 rename 을 유한 재시도와 함께 수행합니다.
     *
     * 갓 쓰여진 파일이 든 디렉토리의 rename 은 **목적지가 비어 있는데도** 첫 시도가
     * 거부될 수 있다. 실측(4표본): 실패 순간 `$final` 은 부재였고 `.old` 스왑도
     * 관여하지 않았는데 `rename` 이 false 를 돌려줬으며, 상태를 바꾸지 않고 그대로
     * 다시 호출하면 전부 성공했다(즉시 1건 / 200ms 후 3건, 1,200ms 까지 간 표본 0).
     * 운영 환경에서도 같은 사유의 게시 실패가 관측됐다 — 재시도 한 번이면 성공했을
     * 게시가 실패 마커와 대시보드 알림까지 올라간다.
     *
     * 특정 OS 로 분기하지 않는다. 이 거부는 파일시스템·스토리지 계층(네트워크 마운트,
     * 스냅샷, 백신·인덱서 등)이면 어디서든 성립하는 조건이고, 분기를 두면 그 플랫폼
     * 밖에서 같은 실패가 조용히 남는다. 재시도가 불필요한 환경에서는 첫 시도가
     * 성공하므로 비용이 0 이다.
     *
     * 성공하지 못하면 false 를 돌려주며 **실패 계약은 종전 그대로다** — 호출부가
     * `.old` 롤백과 예외를 그대로 수행한다.
     *
     * @param  string  $from  원본 경로
     * @param  string  $to  목적지 경로
     * @return bool rename 성공 여부
     */
    private function renameDirectory(string $from, string $to): bool
    {
        for ($attempt = 1; $attempt <= self::RENAME_ATTEMPTS; $attempt++) {
            if (@rename($from, $to)) {
                return true;
            }

            if ($attempt < self::RENAME_ATTEMPTS) {
                usleep(self::RENAME_RETRY_DELAY_US);
                clearstatcache();
            }
        }

        return false;
    }

    /**
     * root 로 실행된 CLI 게시의 산출물 소유권을 부모 디렉토리 기준으로 정상화합니다.
     *
     * root 가 아닌 프로세스는 chown 자체가 불가능하고 필요도 없다(자기 소유로 생성됨)
     * — 그 경우 즉시 no-op. 실패는 chownRecursive 가 경고 로그로 누적한다.
     * 게시 루트 전체(`build/ext`)를 대상으로 하므로 방금 게시된 버전 디렉토리와
     * 잔존 구버전이 함께 정상화된다.
     */
    private function normalizeOwnership(): void
    {
        $base = $this->baseDir();

        if (! File::isDirectory($base)) {
            return;
        }

        // ① root 갈래 — 소유권을 부모 기준으로 되돌린다 (sudo CLI 게시 대응).
        if (function_exists('chown') && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $parent = dirname($base);
            $owner = @fileowner($parent);
            $group = @filegroup($parent);

            if ($owner !== false && $owner !== 0) {
                FilePermissionHelper::chownRecursive($base, $owner, $group);
            }
        }

        // ② 항상 실행 갈래 — 비-root CLI 계정(`deploy` 등)으로 게시한 경우를 덮는다.
        //
        // 종전에는 root 가 아니면 즉시 no-op 이었다. 그러나 실제 제보는 **비-root CLI 계정
        // ≠ 웹 계정** 이었다: CLI 가 최초 게시하면서 트리가 `0755 deploy:deploy` 로 굳고,
        // 이후 웹(php-fpm)의 재게시가 영구 실패한 채 로그 warning 만 남았다.
        //
        // chgrp 로 부모 그룹을 상속시키고 트리에 `g+w` 를 승격하면 그룹을 공유하는 웹
        // 계정이 재게시할 수 있다. 비-root 에서 `@chown` 은 실패해도 무해하고(false 반환),
        // `chgrp`(자기가 속한 그룹으로) 와 `chmod g+w`(자기 소유 파일)는 성립한다.
        //
        // 한계: 이 방식은 CLI 계정과 웹 계정이 **그룹을 공유**할 때만 성립한다. 공유하지
        // 않는 환경은 프리플라이트가 실패 마커를 남기고 진단 표면(`ext-static:status`,
        // 대시보드 알림)이 그 사실을 운영자에게 전달한다 — 규정 문서 §6 참조.
        FilePermissionHelper::inheritOwnershipFromParent($base);
        FilePermissionHelper::syncGroupWritabilityDetailed($base, force: true);
    }

    /**
     * 활성 템플릿 1개의 게시물(lang/components/routes/assets)을 기록합니다.
     *
     * @param  string  $tmp  tmp 디렉토리 절대 경로
     * @param  Template  $template  활성 템플릿
     * @param  array<string>  $locales  게시 대상 로케일
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     */
    private function publishTemplate(string $tmp, Template $template, array $locales, array &$files): void
    {
        $identifier = (string) $template->identifier;

        if (! preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            Log::warning('정적 게시 제외 — 식별자 패턴 불일치', ['identifier' => $identifier]);

            return;
        }

        $templateDir = $tmp.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.$identifier;

        // 1. lang 병합 결과 (코어→템플릿→모듈→플러그인→언어팩 훅) — 현 lang API 와 동일 형상(raw)
        foreach ($locales as $locale) {
            $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

            if (! ($result['success'] ?? false) || ! is_array($result['data'] ?? null)) {
                continue;
            }

            $this->writeJson(
                $templateDir.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.'.json',
                $result['data'],
                $files,
                $tmp
            );
        }

        // 2. components.json 사본 (raw)
        $componentsPath = base_path("templates/{$identifier}/components.json");
        if (is_file($componentsPath)) {
            $this->copyFile($componentsPath, $templateDir.DIRECTORY_SEPARATOR.'components.json', $files, $tmp);
        }

        // 3. routes 병합 결과 — 프론트 소비 코드 무변경을 위한 성공 봉투 포함.
        //    열화 스냅샷(확장 업데이트 진행 중 등)은 게시하지 않는다 — 정적 파일은
        //    스스로 회복되지 않으므로 열화 상태가 다음 bump 까지 박제된다 (getRoutes 와 동일 규율)
        $routesResult = $this->templateService->getRoutesDataWithModules($identifier);

        if (($routesResult['success'] ?? false) && ! $this->templateService->lastRouteMergeWasDegraded()) {
            $this->writeJson(
                $templateDir.DIRECTORY_SEPARATOR.'routes.json',
                ['success' => true, 'message' => '', 'data' => $routesResult['data']],
                $files,
                $tmp
            );
        } elseif ($routesResult['success'] ?? false) {
            Log::warning('정적 게시에서 routes 제외 — 라우트 병합 열화 상태 (확장 업데이트 진행 중 추정)', [
                'template' => $identifier,
            ]);
        }

        // 4. dist 에셋 사본 (확장자 화이트리스트, *.map 제외)
        $this->publishDistAssets(
            base_path("templates/{$identifier}/dist"),
            $templateDir.DIRECTORY_SEPARATOR.'assets',
            $files,
            $tmp
        );

        // 5. 운영자 소유 디렉토리(`custom/`) 사본
        //
        // 게시하지 않으면 이 자산만 API 경로에 남는데, 그 경로에서는 CSS 내부 상대
        // `url()` 이 해석되지 않는다 — `?file=` 형태는 기준 URL 이 `/api/templates/assets/`
        // 라 `url('./font.woff2')` 가 그 디렉토리를 가리키고, 확장자 형태는 정적 최적화
        // 서버가 먼저 가로챈다(그래서 `extensionless` 모드가 존재한다). 즉 정적 확장자
        // URL 은 **public 아래 실제 파일일 때만** 200 이 되므로, 문서가 안내하는
        // "폰트·이미지를 custom/ 에 두고 상대 경로로 참조" 를 성립시키는 방법은 게시뿐이다.
        //
        // 갱신 축은 확장 자산과 동일하다 — 운영자가 파일을 고치면 `CustomAssets` 가
        // 그것을 감지해 `ext.cache_version` 을 올리고, 그 단일 지점이 재게시까지 예약한다.
        $this->publishDistAssets(
            base_path("templates/{$identifier}/".CustomAssets::DIRECTORY),
            $templateDir.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.CustomAssets::DIRECTORY,
            $files,
            $tmp,
            excludeCustom: false
        );
    }

    /**
     * 템플릿 dist 디렉토리를 재귀 복사합니다 (허용 확장자만, 소스맵 제외).
     *
     * @param  string  $sourceDir  원본 절대 경로 (dist 또는 custom)
     * @param  string  $targetDir  게시 대상 절대 경로
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     * @param  string  $tmp  tmp 루트 (상대 경로 계산용)
     * @param  bool  $excludeCustom  원본 안의 `custom/` 하위를 건너뛸지 (dist 원본에서만 참)
     */
    private function publishDistAssets(
        string $sourceDir,
        string $targetDir,
        array &$files,
        string $tmp,
        bool $excludeCustom = true
    ): void {
        $realSource = realpath($sourceDir);

        if ($realSource === false || ! is_dir($realSource)) {
            return;
        }

        // 소스맵은 배포 금지 정책(`*.map` gitignore)과 동일하게 제외
        $allowed = array_diff(AllowedTemplateFileType::getAllowedExtensions(), ['map']);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realSource, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (! in_array($extension, $allowed, true)) {
                continue;
            }

            // 컨테인먼트 검증 — 심볼릭 링크 등으로 dist 밖을 가리키는 실경로 차단
            $realFile = $file->getRealPath();
            if ($realFile === false || ! str_starts_with($realFile, $realSource.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = substr($realFile, strlen($realSource) + 1);

            // dist 원본에서는 `custom/` 하위를 건너뛴다 — 운영자 파일은 자기 원본
            // 루트로 따로 게시되므로, dist 를 통해 한 번 더 실리면 같은 파일이 두 경로에
            // 놓여 어느 쪽이 유효한지가 갈린다. custom 원본으로 호출될 때는 끄고 들어온다
            // (그러지 않으면 `custom/custom/…` 이 조용히 누락된다).
            if ($excludeCustom && $this->isCustomAssetPath($relative)) {
                continue;
            }

            $this->copyFile($realFile, $targetDir.DIRECTORY_SEPARATOR.$relative, $files, $tmp);
        }
    }

    /**
     * 상대 경로가 운영자 소유 디렉토리(`custom/`) 소속인지 판정합니다.
     *
     * @param  string  $relative  게시 원본 기준 상대 경로
     * @return bool custom 소속이면 true
     */
    private function isCustomAssetPath(string $relative): bool
    {
        $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        return $normalized === CustomAssets::DIRECTORY
            || str_starts_with($normalized, CustomAssets::DIRECTORY.'/');
    }

    /**
     * 확장 병합 번들 4종(modules/plugins × js/css)을 게시합니다.
     *
     * @param  string  $tmp  tmp 디렉토리 절대 경로
     * @param  int  $version  확장 캐시 버전
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     */
    private function publishBundles(string $tmp, int $version, array &$files): void
    {
        $map = ['module' => 'modules', 'plugin' => 'plugins'];

        foreach ($map as $type => $plural) {
            foreach (['js', 'css'] as $kind) {
                $path = $this->bundleService->getBundleFilePath($type, $kind, $version);

                if ($path === '' || ! is_file($path)) {
                    continue;
                }

                $this->copyFile(
                    $path,
                    $tmp.DIRECTORY_SEPARATOR.'bundles'.DIRECTORY_SEPARATOR."{$plural}.{$kind}",
                    $files,
                    $tmp
                );
            }
        }
    }

    /**
     * 활성 모듈·플러그인의 운영자 소유 디렉토리(`custom/`)를 게시합니다.
     *
     * 모듈·플러그인의 빌드 산출물은 **병합 번들**로만 게시되는데, `custom/` 은 그 번들에
     * 들어가지 않는다(운영자 파일은 번들보다 뒤에 따로 로드되어야 재정의가 성립한다).
     * 그래서 게시하지 않으면 확장 자산 중 이것만 요청마다 PHP 를 거치고, 무엇보다
     * CSS 내부 상대 `url()` 이 해석되지 않는다 — `?file=` 형태는 기준 URL 이
     * `/api/modules/assets/` 라 `url('./font.woff2')` 가 그 디렉토리를 가리키고,
     * 확장자 형태는 정적 최적화 서버가 먼저 가로챈다. 정적 확장자 URL 은 **public 아래
     * 실제 파일일 때만** 200 이 되므로 게시가 유일한 방법이다 (템플릿과 같은 사유).
     *
     * **활성 확장만** 게시한다. 자산 서빙이 활성 확장에만 응답하므로, 비활성 확장의 파일을
     * 게시해 봐야 아무도 참조하지 않는 사본이 버전 디렉토리마다 쌓일 뿐이다.
     *
     * @param  string  $tmp  tmp 디렉토리 절대 경로
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     */
    private function publishExtensionCustomAssets(string $tmp, array &$files): void
    {
        // 활성 목록은 **레포지토리**에서 읽는다. 매니저(`getActiveModules()`)는 확장을
        // 실제로 적재하는 부작용이 있어, 게시 중에 그 부작용을 끌어들이면 게시가 확장
        // 부팅 상태에 좌우된다. 같은 메서드가 템플릿을 `templateRepository->getActive()`
        // 로 세는 것과 대칭이다.
        $sources = [
            'modules' => $this->moduleRepository->getActiveModuleIdentifiers(),
            'plugins' => $this->pluginRepository->getActivePluginIdentifiers(),
        ];

        foreach ($sources as $root => $identifiers) {
            foreach ($identifiers as $identifier) {
                $identifier = (string) $identifier;

                if (! preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
                    Log::warning('정적 게시 제외 — 식별자 패턴 불일치', [
                        'type' => $root,
                        'identifier' => $identifier,
                    ]);

                    continue;
                }

                $this->publishDistAssets(
                    base_path("{$root}/{$identifier}/".CustomAssets::DIRECTORY),
                    $tmp.DIRECTORY_SEPARATOR.$root.DIRECTORY_SEPARATOR.$identifier
                        .DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.CustomAssets::DIRECTORY,
                    $files,
                    $tmp,
                    excludeCustom: false
                );
            }
        }
    }

    /**
     * 게시 대상 로케일을 열거합니다 — `/api/locales/active` 와 동일 소스
     * (언어팩이 추가한 로케일 포함).
     *
     * @return array<string> 로케일 목록 (패턴 검증 통과분)
     */
    private function publishableLocales(): array
    {
        $locales = $this->languagePackService->getActiveLocales();

        return array_values(array_filter(
            is_array($locales) ? $locales : [],
            static fn ($locale) => is_string($locale) && preg_match(self::LOCALE_PATTERN, $locale)
        ));
    }

    /**
     * JSON 파일을 API 와 동일 인코딩 옵션으로 기록합니다.
     *
     * @param  string  $absolutePath  기록 대상 절대 경로
     * @param  mixed  $data  직렬화할 데이터
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     * @param  string  $tmp  tmp 루트 (상대 경로 계산용)
     */
    private function writeJson(string $absolutePath, mixed $data, array &$files, string $tmp): void
    {
        $this->makeDirectory(dirname($absolutePath));

        $json = json_encode($data, ResponseHelper::JSON_ENCODE_OPTIONS);

        if ($json === false) {
            throw new StaticCachePublishException("Failed to encode JSON payload: {$absolutePath}");
        }

        $this->putVerified($absolutePath, $json);

        $files[] = $this->relativePath($absolutePath, $tmp);
    }

    /**
     * 파일 1개를 복사합니다.
     *
     * @param  string  $source  원본 절대 경로
     * @param  string  $target  대상 절대 경로
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     * @param  string  $tmp  tmp 루트 (상대 경로 계산용)
     */
    private function copyFile(string $source, string $target, array &$files, string $tmp): void
    {
        $this->makeDirectory(dirname($target));

        if (! File::copy($source, $target)) {
            throw new StaticCachePublishException("Failed to copy file: {$source} -> {$target}");
        }

        // 복사도 절단될 수 있다 — 디스크 풀/quota 에서 `copy()` 는 true 를 반환하면서
        // 짧은 파일을 남긴다. 크기 대조로 그 자리에서 잡는다.
        clearstatcache(true, $target);
        $expected = @filesize($source);
        $actual = @filesize($target);

        if ($expected !== false && $actual !== $expected) {
            throw new StaticCachePublishException(
                "Truncated copy: {$target} ({$actual} of {$expected} bytes)"
            );
        }

        $files[] = $this->relativePath($target, $tmp);
    }

    /**
     * Apache 용 불변 캐시 헤더 + 압축 .htaccess 를 기록합니다.
     *
     * 정적 서빙은 Laravel 압축 미들웨어(GzipEncodeResponse)를 우회하므로 압축을
     * 여기서 직접 선언한다 — 없으면 종전 API 대비 전송량 회귀다 (실측: lang/ko.json
     * 524,915B 비압축). nginx 는 서버 기본(ETag/Last-Modified) 재검증으로 충분하며
     * 권장 gzip/expires 스니펫은 규정 문서(§8)에 안내한다.
     *
     * @param  string  $tmp  tmp 디렉토리 절대 경로
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     */
    private function writeHtaccess(string $tmp, array &$files): void
    {
        $content = <<<'HTACCESS'
        <IfModule mod_headers.c>
            Header set Cache-Control "public, max-age=31536000, immutable"
        </IfModule>
        <IfModule mod_deflate.c>
            AddOutputFilterByType DEFLATE application/json application/javascript text/css image/svg+xml
        </IfModule>

        HTACCESS;

        $this->putVerified($tmp.DIRECTORY_SEPARATOR.'.htaccess', $content);

        $files[] = '.htaccess';
    }

    /**
     * 게시 완료 마커(manifest.json)를 기록합니다.
     *
     * @param  string  $finalDir  최종 버전 디렉토리 절대 경로
     * @param  int  $version  확장 캐시 버전
     * @param  array<string>  $files  게시된 상대 경로 목록
     */
    private function writeManifest(string $finalDir, int $version, array $files): void
    {
        $manifest = [
            'cache_version' => $version,
            'published_at' => now()->toIso8601String(),
            'files' => array_values($files),
        ];

        $json = json_encode($manifest, ResponseHelper::JSON_ENCODE_OPTIONS);

        if ($json === false) {
            throw new StaticCachePublishException('Failed to encode manifest');
        }

        $this->putVerified($finalDir.DIRECTORY_SEPARATOR.self::MANIFEST_FILE, $json);
    }

    /**
     * 파일을 기록하고 **기록된 바이트 수를 검증**합니다.
     *
     * `File::put()` 은 실패 시에만 `false` 를 돌려주는 게 아니다 — 디스크 풀·quota 초과에서는
     * 쓴 만큼의 짧은 `int` 를 반환하며 성공한 것처럼 보인다. `=== false` 검사만 하면 그
     * **절단된 JSON 이 200 으로 서빙**되고, 프론트의 `fetchStaticFirst` 는 `response.ok` 만
     * 보므로 폴백하지 않은 채 `response.json()` 이 던져 부팅 전체가 실패한다. 3층 폴백이
     * 유일하게 개입하지 못하는 경로라, 여기서 잡지 못하면 다른 어디서도 잡히지 않는다.
     *
     * @param  string  $absolutePath  기록 대상 절대 경로
     * @param  string  $contents  기록할 내용
     *
     * @throws StaticCachePublishException 쓰기 실패 또는 바이트 수 불일치
     */
    private function putVerified(string $absolutePath, string $contents): void
    {
        $expected = strlen($contents);
        $written = File::put($absolutePath, $contents);

        if ($written === false) {
            throw new StaticCachePublishException("Failed to write file: {$absolutePath}");
        }

        if ($written !== $expected) {
            throw new StaticCachePublishException(
                "Truncated write: {$absolutePath} ({$written} of {$expected} bytes)"
            );
        }
    }

    /**
     * tmp 루트 기준 상대 경로를 반환합니다.
     *
     * @param  string  $absolutePath  절대 경로
     * @param  string  $tmp  tmp 루트
     * @return string 상대 경로 (구분자 `/` 정규화)
     */
    private function relativePath(string $absolutePath, string $tmp): string
    {
        return str_replace('\\', '/', substr($absolutePath, strlen($tmp) + 1));
    }
}
