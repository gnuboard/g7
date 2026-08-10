<?php

namespace App\Support\Routing;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * 동적 응답 라우트를 "확장자 형태"와 "확장자 없는 형태" 두 벌로 동시 등록하는 Route 매크로.
 *
 * ## 배경
 *
 * G7 은 동적 API 엔드포인트에 정적 파일 확장자를 붙여 쓴다 (`/api/templates/{id}/routes.json`).
 * 그런데 nginx/Apache 의 표준적 정적 최적화 블록은 URL 마지막 확장자로 분기하며,
 * nginx 에서 정규식 location 은 프리픽스 location 보다 먼저 매칭되므로
 * `try_files ... /index.php` 폴백이 실행될 기회 자체가 없다.
 *
 * ```nginx
 * location ~* \.(js|css|json)$ { expires max; access_log off; }
 * ```
 *
 * 이 설정 하에서는 PHP 로 요청이 넘어가지 않고 nginx 가 직접 파일시스템을 열려 시도해
 * 404 가 되며, G7 은 부트스트랩 자산부터 죽는다. 관례를 깬 쪽이 G7 이므로 해소 책임도
 * G7 에 있다는 판단으로, 두 형태를 모두 서빙하고 환경에 따라 택일한다.
 *
 * ## 규칙
 *
 * - 고정 접미사 엔드포인트는 접미사를 뗀다 (`routes.json` → `routes`)
 * - 와일드카드 자산 라우트는 경로가 곧 파일명이라 뗄 수 없으므로 쿼리로 옮긴다
 *   (`assets/{id}/js/a.js` → `assets/{id}?file=js/a.js`).
 *   nginx 의 location 정규식은 쿼리스트링을 제외한 경로에만 매칭되므로 안전하다.
 * - 두 형태 모두 영구 유지한다. 확장자 형태를 제거하면 URL 을 하드코딩한
 *   서드파티 확장이 깨진다.
 *
 * ## 등록 순서
 *
 * 확장자 형태를 항상 **먼저** 등록한다. 확장자 없는 형태가 더 느슨한 패턴이라
 * (예: `layouts/{tpl}/{layoutName}` 의 `layoutName` 정규식은 `.` 를 포함해 greedy)
 * 순서가 뒤집히면 확장자 없는 라우트가 `.json` 요청까지 삼킨다.
 *
 * @see DualRouteProxy
 */
class DualExtensionRoute
{
    /**
     * 확장자 없는 자산 라우트에서 파일 경로를 담는 쿼리 파라미터명.
     */
    public const FILE_QUERY_PARAM = 'file';

    /**
     * Route 매크로 3종(`dualSuffix` / `dualSuffixSegment` / `dualAsset`)을 등록합니다.
     *
     * `AppServiceProvider::register()` 에서 호출됩니다. boot() 가 아닌 이유:
     * 라우트 파일은 프레임워크 라우팅 부트스트랩(boot 단계)에서 로드되므로,
     * 프로바이더 간 boot 순서에 의존하면 매크로 미정의 시점에 라우트가 로드될 수 있다.
     * 모든 프로바이더의 register() 는 어떤 boot() 보다 먼저 실행된다.
     */
    public static function register(): void
    {
        static::registerDualSuffix();
        static::registerDualSuffixSegment();
        static::registerDualAsset();
    }

    /**
     * `Route::dualSuffix()` 매크로를 등록합니다.
     *
     * 고정 접미사를 갖는 엔드포인트를 두 형태로 등록합니다.
     *
     * ```php
     * Route::dualSuffix('templates/{identifier}/routes', 'json', [Ctrl::class, 'getRoutes'])
     *     ->name('api.public.templates.routes');
     * // → GET templates/{identifier}/routes.json  (name: api.public.templates.routes)
     * // → GET templates/{identifier}/routes       (name: api.public.templates.routes.extensionless)
     * ```
     */
    private static function registerDualSuffix(): void
    {
        Route::macro('dualSuffix', function (string $uri, string $suffix, mixed $action): DualRouteProxy {
            /** @var Router $this */
            $base = rtrim($uri, '/');

            // 확장자 형태를 먼저 등록 (더 구체적인 패턴이 우선 매칭되어야 함)
            $extension = $this->get($base.'.'.ltrim($suffix, '.'), $action);
            $extensionless = $this->get($base, $action);

            return new DualRouteProxy($extension, $extensionless);
        });
    }

    /**
     * `Route::dualSuffixSegment()` 매크로를 등록합니다.
     *
     * 접미사 자체가 의미를 담아 **뗄 수 없는** 엔드포인트용입니다.
     * `bundle.js` 와 `bundle.css` 는 접미사를 제거하면 둘 다 `bundle` 이 되어 충돌하므로,
     * 접미사를 삭제하는 대신 경로 세그먼트로 내린다.
     *
     * ```php
     * Route::dualSuffixSegment('modules/bundle', 'js', [Ctrl::class, 'serveBundleJs'])
     *     ->name('api.public.modules.bundle.js');
     * // → GET modules/bundle.js  (name: api.public.modules.bundle.js)
     * // → GET modules/bundle/js  (name: api.public.modules.bundle.js.extensionless)
     * ```
     */
    private static function registerDualSuffixSegment(): void
    {
        Route::macro('dualSuffixSegment', function (string $uri, string $suffix, mixed $action): DualRouteProxy {
            /** @var Router $this */
            $base = rtrim($uri, '/');
            $normalized = ltrim($suffix, '.');

            $extension = $this->get($base.'.'.$normalized, $action);
            $extensionless = $this->get($base.'/'.$normalized, $action);

            return new DualRouteProxy($extension, $extensionless);
        });
    }

    /**
     * `Route::dualAsset()` 매크로를 등록합니다.
     *
     * 와일드카드 파일 경로를 갖는 자산 서빙 엔드포인트를 두 형태로 등록합니다.
     * 확장자 형태는 `{path}` 세그먼트로, 확장자 없는 형태는 `?file=` 쿼리로 경로를 받으며
     * 양쪽 모두 동일 컨트롤러로 들어갑니다 (흡수는 각 FormRequest 의
     * `prepareForValidation()` 이 담당).
     *
     * ```php
     * Route::dualAsset('templates/assets/{identifier}', [Ctrl::class, 'serveAsset'])
     *     ->name('api.public.templates.assets');
     * // → GET templates/assets/{identifier}/{path}  where path = .*
     * // → GET templates/assets/{identifier}          (?file=js/a.js)
     * ```
     */
    private static function registerDualAsset(): void
    {
        Route::macro('dualAsset', function (string $uri, mixed $action): DualRouteProxy {
            /** @var Router $this */
            $base = rtrim($uri, '/');

            // 확장자 형태를 먼저 등록 (경로 세그먼트가 더 구체적)
            $extension = $this->get($base.'/{path}', $action)->where('path', '.*');
            $extensionless = $this->get($base, $action);

            return new DualRouteProxy($extension, $extensionless);
        });
    }
}
