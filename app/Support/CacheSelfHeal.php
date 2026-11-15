<?php

namespace App\Support;

use Illuminate\Foundation\Application;

/**
 * 생성형 캐시 파일이 **없을 때**의 자기 복구 공용 도구.
 *
 * `bootstrap/cache/` 의 확장 오토로드·훅·설정·라우트 캐시는 명령이 만들어 두는 파일이다. 서버를 옮기거나
 * 배포 도구가 디렉토리를 비우면 파일이 통째로 사라지는데, 낡은 캐시를 알아채는 기존 복구(7.1.3)는 파일이
 * **있을 때만** 돌았다. 파일이 없으면 폴백만 하고 다시 만들지 않아, 확장 의존성이 빠진 채 500 이 나거나
 * 매 요청이 캐시 없이 돌았다.
 *
 * 이 클래스는 네 캐시가 같이 쓰는 규칙만 둔다:
 *   - 끄는 스위치 (`.env` `G7_CACHE_SELF_HEAL=false` → `config('app.cache_self_heal')`)
 *   - root 프로세스는 만들지 않는다 (root 가 만든 파일은 웹 서버 사용자가 고쳐 쓰지 못한다)
 *   - 실패가 되풀이되지 않게 표식 파일로 잠시 쉰다
 * 그리고 설정·라우트 캐시의 「응답 뒤 재생성」 예약을 맡는다.
 */
final class CacheSelfHeal
{
    /**
     * 설정·라우트 캐시 재생성 사이의 최소 간격(초). 재생성은 일회용 앱을 부팅해 DB 연결을 쓴다.
     */
    public const CONFIG_ROUTE_BACKOFF_SECONDS = 300;

    /**
     * 확장 오토로드·훅 캐시 재생성 사이의 최소 간격(초).
     */
    public const EXTENSION_BACKOFF_SECONDS = 60;

    /**
     * root 판정 강제값 (시험용, null 이면 실제 판정).
     */
    private static ?bool $fakeRoot = null;

    /**
     * 표식 파일을 둘 디렉토리 (시험용, null 이면 bootstrap/cache).
     */
    private static ?string $markerDirectory = null;

    /**
     * 설정·라우트 캐시 재생성 함수 (시험에서 바꿔 끼운다).
     *
     * @var array{config?: callable(): void, route?: callable(): void}
     */
    private static array $rebuilders = [];

    /**
     * 이번 요청에서 이미 예약한 캐시.
     *
     * @var array<string, bool>
     */
    private static array $scheduled = [];

    /**
     * 자기 복구가 켜져 있는지.
     *
     * @return bool 켜져 있으면 true (기본)
     */
    public static function enabled(): bool
    {
        return (bool) config('app.cache_self_heal', true);
    }

    /**
     * 이 프로세스가 root 인지.
     *
     * @return bool root 면 true
     */
    public static function isRootProcess(): bool
    {
        if (self::$fakeRoot !== null) {
            return self::$fakeRoot;
        }

        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * root 판정을 강제합니다 (시험용).
     *
     * @param  bool|null  $isRoot  강제값 (null 이면 실제 판정으로 되돌림)
     * @return void 반환값 없음
     */
    public static function fakeRootProcessForTesting(?bool $isRoot): void
    {
        self::$fakeRoot = $isRoot;
    }

    /**
     * 표식 파일 경로.
     *
     * @param  string  $name  캐시 이름 (예: autoload-extensions)
     * @return string 표식 파일 절대경로
     */
    public static function markerPath(string $name): string
    {
        return (self::$markerDirectory ?? base_path('bootstrap/cache')).DIRECTORY_SEPARATOR.$name.'.retry';
    }

    /**
     * 다시 시도할 때가 됐는지 (표식이 없거나 간격이 지났는지).
     *
     * @param  string  $name  캐시 이름
     * @param  int  $seconds  최소 간격(초)
     * @return bool 시도해도 되면 true
     */
    public static function due(string $name, int $seconds): bool
    {
        $marker = self::markerPath($name);
        clearstatcache(true, $marker);
        $modified = @filemtime($marker);

        return $modified === false || (time() - $modified) >= $seconds;
    }

    /**
     * 「방금 시도했음」 표식을 남깁니다. 남기지 못해도 오류를 내지 않습니다.
     *
     * @param  string  $name  캐시 이름
     * @return void 반환값 없음
     */
    public static function mark(string $name): void
    {
        $marker = self::markerPath($name);
        if (! is_dir(dirname($marker))) {
            return;
        }
        @touch($marker);
    }

    /**
     * 표식을 지웁니다 (복구가 끝났을 때).
     *
     * @param  string  $name  캐시 이름
     * @return void 반환값 없음
     */
    public static function clear(string $name): void
    {
        @unlink(self::markerPath($name));
    }

    /**
     * 캐시가 없어 다시 만들어도 되는 상황인지 — 스위치·root·간격을 한 번에 봅니다.
     *
     * 통과하면 표식을 남깁니다(같은 순간의 다른 요청이 같은 일을 겹쳐 하지 않게).
     *
     * @param  string  $name  캐시 이름
     * @param  int  $seconds  최소 간격(초)
     * @return bool 다시 만들어도 되면 true
     */
    public static function claim(string $name, int $seconds): bool
    {
        if (! self::enabled() || self::isRootProcess() || ! self::due($name, $seconds)) {
            return false;
        }

        self::mark($name);

        return true;
    }

    /**
     * 캐시를 「운영 중인 사이트의 웹 요청」에서만 스스로 만들지 판정합니다.
     *
     * 콘솔은 제외한다 — `config:clear`·`route:clear` 처럼 일부러 지운 것과 싸우지 않게.
     * 개발 환경(디버그 켬·production 아님)도 제외한다 — 캐시 없이 돌리는 것이 의도다.
     *
     * @param  Application  $app  앱
     * @return bool 스스로 만들어도 되는 실행이면 true
     */
    public static function isProductionWebRequest(Application $app): bool
    {
        return ! $app->runningInConsole()
            && $app->environment('production')
            && ! config('app.debug')
            && InstallerResidual::isInstallCompleted($app->basePath());
    }

    /**
     * 설정·라우트 캐시 파일이 없으면 응답 뒤에 다시 만들도록 예약합니다.
     *
     * 운영 환경의 웹 요청에서만 한다. 한 요청에서 캐시마다 한 번만 예약하고, 간격 안의 다음 요청은 예약하지 않는다.
     *
     * @param  Application  $app  앱
     * @return list<string> 이번에 예약한 캐시 이름 (config · route)
     */
    public static function scheduleMissingConfigAndRouteCaches(Application $app): array
    {
        if (! self::enabled() || self::isRootProcess() || ! self::isProductionWebRequest($app)) {
            return [];
        }

        $targets = [
            'config' => $app->getCachedConfigPath(),
            'route' => $app->getCachedRoutesPath(),
        ];

        $scheduled = [];
        foreach ($targets as $name => $path) {
            if (is_file($path) || isset(self::$scheduled[$name]) || ! self::due($name.'-cache', self::CONFIG_ROUTE_BACKOFF_SECONDS)) {
                continue;
            }

            self::mark($name.'-cache');
            self::$scheduled[$name] = true;
            $scheduled[] = $name;

            $app->terminating(static function () use ($name): void {
                try {
                    (self::$rebuilders[$name] ?? self::defaultRebuilder($name))();
                } catch (\Throwable) {
                    // 응답은 이미 나갔다 — 실패는 표식이 남아 간격 뒤에 다시 시도한다
                }
            });
        }

        return $scheduled;
    }

    /**
     * 설정·라우트 캐시 재생성 함수를 바꿉니다 (시험용 — 실제 캐시를 굽지 않게).
     *
     * @param  array{config?: callable(): void, route?: callable(): void}  $rebuilders  이름 => 함수
     * @return void 반환값 없음
     */
    public static function useRebuildersForTesting(array $rebuilders): void
    {
        self::$rebuilders = $rebuilders;
    }

    /**
     * 표식 파일을 둘 디렉토리를 바꿉니다 (시험용 — 실제 bootstrap/cache 를 건드리지 않게).
     *
     * @param  string|null  $directory  디렉토리 (null 이면 bootstrap/cache 로 되돌림)
     * @return void 반환값 없음
     */
    public static function useMarkerDirectoryForTesting(?string $directory): void
    {
        self::$markerDirectory = $directory;
    }

    /**
     * 시험 사이에 내부 상태를 비웁니다.
     *
     * @return void 반환값 없음
     */
    public static function resetForTesting(): void
    {
        self::$fakeRoot = null;
        self::$markerDirectory = null;
        self::$rebuilders = [];
        self::$scheduled = [];
    }

    /**
     * 기본 재생성 함수.
     *
     * @param  string  $name  config 또는 route
     * @return callable(): void 재생성 함수
     */
    private static function defaultRebuilder(string $name): callable
    {
        return $name === 'config'
            ? static fn () => ConfigCacheHelper::rebuild()
            : static fn () => RouteCacheHelper::rebuild();
    }
}
