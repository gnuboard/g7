<?php

namespace App\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Exceptions\CoreVersionMismatchException;
use App\Extension\Cache\CoreCacheDriver;
use Composer\Semver\Semver;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * 그누보드7 코어 버전 호환성 검증 유틸리티
 *
 * 확장(모듈, 플러그인, 템플릿)이 요구하는 그누보드7 코어 버전과
 * 현재 설치된 코어 버전의 호환성을 검증합니다.
 */
class CoreVersionChecker
{
    /**
     * 캐시 키 접두사 (드라이버 접두사 `g7:core:` 다음에 붙음)
     */
    private const CACHE_PREFIX = 'ext.version_check.';

    /**
     * 캐시 유효 시간 (초)
     */
    private const CACHE_TTL = 3600;

    /**
     * 현재 설치된 그누보드7 코어 버전 반환
     *
     * 반환 우선순위:
     *   1. 환경변수 `APP_VERSION` (getenv / $_ENV / $_SERVER)
     *   2. `config('app.version')`
     *
     * 왜 env 를 우선 읽는가:
     *   코어 업그레이드 중 `core:update` 는 `core:execute-upgrade-steps` / 각 업그레이드 스텝의
     *   inline 스크립트를 `proc_open` 으로 spawn 한다. 디스크 `.env` 의 `APP_VERSION` 은
     *   `updateVersionInEnv()` 가 최종 단계(Step 11)에서 기록하므로, spawn 이 부팅되는
     *   Step 10 시점에는 여전히 이전 버전이 남아있다. 이를 회피하기 위해 spawn 호출부는
     *   `APP_VERSION={toVersion}` 를 `proc_open` 의 `$env` 로 주입하지만, `bootstrap/cache/config.php`
     *   가 생성되어 있으면 `LoadConfiguration` 부트스트랩이 **캐시된 리터럴** 을 사용해
     *   env 오버라이드가 반영되지 않는 회귀가 있었다 (확장이 `>= 신버전` 요구 시
     *   `validateAndDeactivateIncompatibleExtensions` 가 전 확장을 자동 비활성화).
     *
     *   env 를 우선 읽는 본 구현은 config cache 유무와 무관하게 spawn 이 전달한 버전을 그대로
     *   신뢰한다. 일반 요청 경로에서는 `APP_VERSION` 이 `.env` 에 기록된 값 그대로이므로
     *   동작에 차이가 없다.
     *
     *   규정 예외: "env() 는 config 파일에서만 사용" 규칙의 본문 예외. 정당성은 버전 판정이
     *   config cache 우회를 요구하기 때문이다.
     *
     * @return string 코어 버전 문자열 (예: "7.0.0-beta.4")
     */
    public static function getCoreVersion(): string
    {
        $envVersion = $_ENV['APP_VERSION'] ?? $_SERVER['APP_VERSION'] ?? getenv('APP_VERSION');
        if (is_string($envVersion) && $envVersion !== '') {
            return $envVersion;
        }

        return config('app.version');
    }

    /**
     * 버전 제약 조건 충족 여부 확인
     *
     * @param  string  $constraint  Semantic Versioning 제약 문자열
     * @return bool 충족 여부
     */
    public static function satisfies(string $constraint): bool
    {
        try {
            return Semver::satisfies(self::getCoreVersion(), $constraint);
        } catch (Exception $e) {
            Log::warning(__('extensions.errors.version_check_failed'), [
                'constraint' => $constraint,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 확장의 코어 버전 호환성 검증
     *
     * @param  string|null  $requiredVersion  요구 버전 제약
     * @param  string  $identifier  확장 식별자
     * @param  string  $type  확장 타입 (module, plugin, template)
     * @return bool 항상 true (검증 실패 시 예외 발생)
     *
     * @throws Exception 버전 미충족 시
     */
    public static function validateExtension(
        ?string $requiredVersion,
        string $identifier,
        string $type
    ): bool {
        if ($requiredVersion === null) {
            return true;
        }

        if (! self::satisfies($requiredVersion)) {
            throw new CoreVersionMismatchException(
                $type,
                $identifier,
                $requiredVersion,
                self::getCoreVersion(),
            );
        }

        return true;
    }

    /**
     * 확장의 코어 버전 호환성 확인 (예외 없음)
     *
     * @param  string|null  $requiredVersion  요구 버전 제약
     * @return bool 호환 여부
     */
    public static function isCompatible(?string $requiredVersion): bool
    {
        if ($requiredVersion === null) {
            return true;
        }

        return self::satisfies($requiredVersion);
    }

    /**
     * 버전 검증 캐시 키 생성
     *
     * @param  string  $type  확장 타입 (modules, plugins, templates)
     * @return string 캐시 키
     */
    public static function getCacheKey(string $type): string
    {
        return self::CACHE_PREFIX.$type.'.'.self::getCoreVersion();
    }

    /**
     * 모든 버전 검증 캐시 삭제
     */
    public static function clearCache(): void
    {
        $cache = self::resolveCache();
        $cache->forget(self::getCacheKey('modules'));
        $cache->forget(self::getCacheKey('plugins'));
        $cache->forget(self::getCacheKey('templates'));
    }

    /**
     * CacheInterface 인스턴스를 컨테이너에서 lazy 조회합니다.
     *
     * 컨테이너 미구성 환경(예: 일부 단위 테스트)에서도 동작하도록
     * fallback 으로 직접 CoreCacheDriver 를 생성합니다.
     *
     * @return CacheInterface
     */
    private static function resolveCache(): CacheInterface
    {
        try {
            return app(CacheInterface::class);
        } catch (\Throwable $e) {
            return new CoreCacheDriver(config('cache.default', 'array'));
        }
    }

    /**
     * 캐시 TTL 반환
     *
     * @return int 캐시 유효 시간 (초)
     */
    public static function getCacheTtl(): int
    {
        return (int) g7_core_settings('cache.version_check_ttl', self::CACHE_TTL);
    }
}
