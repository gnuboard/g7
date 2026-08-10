<?php

namespace App\Support;

use App\Support\Routing\DualExtensionRoute;

/**
 * 자산·동적 엔드포인트 URL 생성기 (서버측 SSoT).
 *
 * ## 왜 필요한가
 *
 * G7 은 동적 API 엔드포인트에 정적 파일 확장자를 붙여 쓴다. 그런데 nginx/Apache 의
 * 표준적 정적 최적화 블록(`location ~* \.(js|css|json)$`)은 URL 마지막 확장자로
 * 분기하며, nginx 에서 정규식 location 은 프리픽스 location 보다 먼저 매칭되므로
 * `try_files ... /index.php` 폴백이 실행될 기회가 없다. 그런 환경에서는 확장자 붙은
 * 동적 응답이 PHP 에 도달하지 못하고 404 가 된다.
 *
 * 라우트는 이미 두 형태로 등록되어 있다(`DualExtensionRoute`). 남은 문제는 **URL 을
 * 만드는 쪽**이 13개 지점에 흩어져 하드코딩되어 있었다는 것이다. 한 곳만 빠뜨려도
 * 그 자산만 404 가 되고, 어느 지점이 빠졌는지는 화면이 죽어야 알 수 있다.
 * 그래서 생성 경로를 여기 하나로 모은다.
 *
 * ## 모드
 *
 * `general.asset_url_mode` 설정값을 따른다.
 *
 * | 모드 | 의미 |
 * |---|---|
 * | `extension` (기본) | 확장자 유지 — 정상 환경. 확장자 기반 캐시/gzip 최적화를 보존한다 |
 * | `extensionless` | 확장자 제거 — 정적 블록이 가로채는 환경 |
 *
 * 기본값이 `extension` 인 이유는 계획서 §"채택 방향" 을 따른다. 확장자를 일괄 제거하면
 * `expires max` / `gzip_static` / CDN TTL 규칙이 함께 걸린 다수의 정상 환경에서
 * 그 최적화를 전부 잃는다.
 *
 * @see DualExtensionRoute 라우트 이중 등록
 */
class AssetUrl
{
    /**
     * 확장자 유지 모드 식별자.
     */
    public const MODE_EXTENSION = 'extension';

    /**
     * 확장자 제거 모드 식별자.
     */
    public const MODE_EXTENSIONLESS = 'extensionless';

    /**
     * 확장자 없는 자산 URL 에서 파일 경로를 담는 쿼리 파라미터명.
     */
    public const FILE_QUERY_PARAM = 'file';

    /**
     * 테스트/렌더 단위에서 모드를 강제하기 위한 오버라이드 값.
     *
     * null 이면 설정값을 조회한다.
     */
    private static ?string $modeOverride = null;

    /**
     * 현재 자산 URL 모드를 반환합니다.
     *
     * 설정 조회가 실패해도(설치 전·마이그레이션 전 등) 예외를 던지지 않고
     * 기본 모드로 폴백한다 — 이 값은 blade 렌더 경로에서 읽히므로 여기서
     * 터지면 화면 전체가 죽는다.
     *
     * @return string `extension` 또는 `extensionless`
     */
    public static function mode(): string
    {
        if (self::$modeOverride !== null) {
            return self::$modeOverride;
        }

        try {
            $mode = g7_core_settings('general.asset_url_mode', self::MODE_EXTENSION);
        } catch (\Throwable $e) {
            return self::MODE_EXTENSION;
        }

        return $mode === self::MODE_EXTENSIONLESS ? self::MODE_EXTENSIONLESS : self::MODE_EXTENSION;
    }

    /**
     * 현재 모드가 확장자 없는 모드인지 여부를 반환합니다.
     *
     * @return bool 확장자 없는 모드이면 true
     */
    public static function isExtensionless(): bool
    {
        return self::mode() === self::MODE_EXTENSIONLESS;
    }

    /**
     * 모드를 강제로 지정합니다 (테스트 전용).
     *
     * @param  string|null  $mode  강제할 모드. null 이면 오버라이드 해제
     */
    public static function forceMode(?string $mode): void
    {
        self::$modeOverride = $mode;
    }

    /**
     * 템플릿 자산 URL 을 생성합니다.
     *
     * 템플릿은 서버가 `dist/` 를 자동 부가하므로 `$path` 는 `dist/` 를 포함하지 않는다
     * (`TemplateService::getAssetFilePath`). 모듈/플러그인과 비대칭이므로 주의.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $path  `dist/` 이하 파일 경로 (예: `js/components.iife.js`)
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function templateAsset(string $identifier, string $path, int|string|null $version = null): string
    {
        return self::asset('templates', $identifier, $path, $version);
    }

    /**
     * 모듈 자산 URL 을 생성합니다.
     *
     * 모듈은 모듈 루트 기준이라 `$path` 에 `dist/` 를 직접 포함해야 한다
     * (`ModuleService::getAssetFilePath`).
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string  $path  모듈 루트 기준 파일 경로 (예: `dist/js/x.iife.js`)
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function moduleAsset(string $identifier, string $path, int|string|null $version = null): string
    {
        return self::asset('modules', $identifier, $path, $version);
    }

    /**
     * 플러그인 자산 URL 을 생성합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  string  $path  플러그인 루트 기준 파일 경로 (예: `dist/js/x.iife.js`)
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function pluginAsset(string $identifier, string $path, int|string|null $version = null): string
    {
        return self::asset('plugins', $identifier, $path, $version);
    }

    /**
     * 확장 타입을 인자로 받는 자산 URL 생성기.
     *
     * 모듈/플러그인을 같은 코드 경로로 처리하는 호출부(공용 트레이트 등)용.
     * 타입이 컴파일 시점에 정해져 있으면 `moduleAsset()` / `pluginAsset()` 를 쓴다.
     *
     * @param  string  $type  `templates` / `modules` / `plugins`
     * @param  string  $identifier  확장 식별자
     * @param  string  $path  파일 경로
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function extensionAsset(string $type, string $identifier, string $path, int|string|null $version = null): string
    {
        return self::asset($type, $identifier, $path, $version);
    }

    /**
     * 확장 병합 번들 URL 을 생성합니다.
     *
     * 접미사(js/css)가 번들 종류를 구분하므로 제거할 수 없다.
     * 확장자 없는 모드에서는 경로 세그먼트로 내린다 (`bundle.js` → `bundle/js`).
     *
     * @param  string  $type  `modules` 또는 `plugins`
     * @param  string  $kind  `js` 또는 `css`
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function extensionBundle(string $type, string $kind, int|string|null $version = null): string
    {
        $base = self::isExtensionless()
            ? "/api/{$type}/bundle/{$kind}"
            : "/api/{$type}/bundle.{$kind}";

        return $base.self::versionQuery($version);
    }

    /**
     * 고정 접미사를 갖는 동적 엔드포인트 URL 을 생성합니다.
     *
     * 확장자 없는 모드에서는 접미사를 제거한다 (`routes.json` → `routes`).
     *
     * @param  string  $path  접미사를 제외한 경로 (예: `/api/templates/foo/routes`)
     * @param  string  $suffix  접미사 (예: `json`)
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function suffixed(string $path, string $suffix, int|string|null $version = null): string
    {
        $base = rtrim($path, '/');
        $normalized = ltrim($suffix, '.');

        $url = self::isExtensionless() ? $base : $base.'.'.$normalized;

        return $url.self::versionQuery($version);
    }

    /**
     * 확장 자산 URL 을 생성하는 공통 구현.
     *
     * 확장자 없는 모드에서는 파일 경로를 `?file=` 쿼리로 옮긴다. 경로가 곧 파일명이라
     * 접미사만 떼어낼 수 없기 때문이며, nginx 의 location 정규식이 쿼리스트링을 제외한
     * 경로에만 매칭되므로 이 형태가 안전하다.
     *
     * @param  string  $type  `templates` / `modules` / `plugins`
     * @param  string  $identifier  확장 식별자
     * @param  string  $path  파일 경로
     * @param  int|string|null  $version  캐시 무효화 버전
     * @return string 생성된 URL
     */
    private static function asset(string $type, string $identifier, string $path, int|string|null $version): string
    {
        $path = ltrim($path, '/');

        if (! self::isExtensionless()) {
            return "/api/{$type}/assets/{$identifier}/{$path}".self::versionQuery($version);
        }

        $query = self::FILE_QUERY_PARAM.'='.rawurlencode($path);

        if ($version !== null && $version !== '') {
            $query .= '&v='.$version;
        }

        return "/api/{$type}/assets/{$identifier}?{$query}";
    }

    /**
     * 캐시 무효화 쿼리스트링을 생성합니다.
     *
     * @param  int|string|null  $version  버전 값
     * @return string `?v=...` 또는 빈 문자열
     */
    private static function versionQuery(int|string|null $version): string
    {
        if ($version === null || $version === '') {
            return '';
        }

        return '?v='.$version;
    }
}
