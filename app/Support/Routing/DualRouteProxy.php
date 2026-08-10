<?php

namespace App\Support\Routing;

use Illuminate\Routing\Route;

/**
 * 이중 등록된 두 라우트(확장자 형태 · 확장자 없는 형태)를 하나처럼 체이닝하기 위한 프록시.
 *
 * `Route::dualSuffix()` / `Route::dualAsset()` 매크로가 반환하며, `name()` 을 제외한
 * 모든 호출(`middleware()`, `where()`, `defaults()` 등)을 양쪽 라우트에 그대로 전달한다.
 * 한쪽에만 적용되어 조용히 어긋나는 상황을 구조적으로 차단하는 것이 목적이다.
 *
 * @mixin Route
 */
class DualRouteProxy
{
    /**
     * 확장자 없는 형태의 라우트 이름에 붙는 접미사.
     *
     * 두 라우트에 같은 이름을 주면 Laravel 의 이름 조회가 나중에 등록된 쪽으로
     * 덮여 `route()` 결과가 조용히 바뀐다. 확장자 형태가 기존 이름을 유지하고
     * 확장자 없는 형태만 접미사를 갖는다 (하위호환).
     */
    public const EXTENSIONLESS_NAME_SUFFIX = '.extensionless';

    /**
     * @param  Route  $extension  확장자 형태 라우트 (예: `templates/{id}/routes.json`)
     * @param  Route  $extensionless  확장자 없는 형태 라우트 (예: `templates/{id}/routes`)
     */
    public function __construct(
        private readonly Route $extension,
        private readonly Route $extensionless,
    ) {}

    /**
     * 확장자 형태 라우트를 반환합니다.
     *
     * @return Route 확장자 형태 라우트 인스턴스
     */
    public function extensionRoute(): Route
    {
        return $this->extension;
    }

    /**
     * 확장자 없는 형태 라우트를 반환합니다.
     *
     * @return Route 확장자 없는 형태 라우트 인스턴스
     */
    public function extensionlessRoute(): Route
    {
        return $this->extensionless;
    }

    /**
     * 두 라우트에 이름을 부여합니다.
     *
     * 확장자 형태는 전달된 이름을 그대로, 확장자 없는 형태는
     * `.extensionless` 접미사를 붙여 등록합니다.
     *
     * @param  string  $name  라우트 이름
     * @return self 체이닝을 위한 자기 자신
     */
    public function name(string $name): self
    {
        $this->extension->name($name);
        $this->extensionless->name($name.self::EXTENSIONLESS_NAME_SUFFIX);

        return $this;
    }

    /**
     * 그 외 모든 호출을 두 라우트에 동일하게 전달합니다.
     *
     * @param  string  $method  호출된 메서드명
     * @param  array<int, mixed>  $arguments  전달 인자
     * @return self 체이닝을 위한 자기 자신
     */
    public function __call(string $method, array $arguments): self
    {
        $this->extension->{$method}(...$arguments);
        $this->extensionless->{$method}(...$arguments);

        return $this;
    }
}
