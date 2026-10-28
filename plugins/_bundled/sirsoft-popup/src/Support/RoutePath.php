<?php

namespace Plugins\Sirsoft\Popup\Support;

/**
 * 노출 페이지 경로 규칙 — 입력 정규화·형식 판정·매칭의 서버 쪽 단일 출처.
 *
 * 사용자 레이어(`resources/js/lib/popupVisibility.ts` 의 `matchesRoute`)가 같은 규칙으로 판정한다. 두 구현이 같은 표본에서
 * 같은 결과를 내는지 양쪽 테스트(`tests/Unit/Support/RoutePathTest.php`·`popupVisibility.test.ts`)가 잠근다.
 *
 * 매칭(PO 결정 #812 D2): 경로 경계 하위 포함 — `/shop` 은 `/shop`·`/shop/…` 에 맞고 `/shopping` 에는 맞지 않는다.
 * `/` 는 홈(`/`)에만 맞는다(「전체 페이지」 선택과 구분). 대소문자는 구분한다.
 */
class RoutePath
{
    /**
     * 한 팝업에 지정할 수 있는 최대 경로 수
     */
    public const MAX_COUNT = 20;

    /**
     * 경로 하나의 최대 길이
     */
    public const MAX_LENGTH = 200;

    /**
     * 입력 경로를 정규화합니다 — 앞뒤 공백을 지우고 끝 `/` 를 하나 뗍니다(`/` 자체는 그대로).
     *
     * @param  string  $route  입력 경로
     * @return string 정규화한 경로
     */
    public static function normalize(string $route): string
    {
        $route = trim($route);
        if ($route !== '/' && str_ends_with($route, '/')) {
            $route = rtrim($route, '/');
            if ($route === '') {
                $route = '/';
            }
        }

        return $route;
    }

    /**
     * 정규화한 경로가 허용 형식인지 판정합니다.
     *
     * 허용: `/` 로 시작, 글자·숫자·`-._~`·`%XX`·`/`·유니코드 글자. 거부: `//` 포함, `..` 구간, `?`·`#`·`*`·공백·제어문자.
     *
     * @param  string  $route  정규화한 경로
     * @return bool 허용이면 true
     */
    public static function isValid(string $route): bool
    {
        if ($route === '' || ! str_starts_with($route, '/') || mb_strlen($route) > self::MAX_LENGTH) {
            return false;
        }

        if (str_contains($route, '//')) {
            return false;
        }

        foreach (explode('/', $route) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        // 경로 글자: 유니코드 글자·숫자, 비예약 기호(-._~), 퍼센트 인코딩, 구분자 /
        return preg_match('/^(?:\/|(?:\/(?:[\p{L}\p{N}\-._~]|%[0-9A-Fa-f]{2})+)+)$/u', $route) === 1;
    }

    /**
     * 경로가 지정 경로에 맞는지 판정합니다(쿼리·해시가 빠진 경로를 받는다).
     *
     * @param  string  $path  현재 경로
     * @param  string  $route  지정 경로 (정규화된 값)
     * @return bool 맞으면 true
     */
    public static function matches(string $path, string $route): bool
    {
        $path = self::normalize($path === '' ? '/' : $path);
        $route = self::normalize($route);

        if ($route === '/') {
            return $path === '/';
        }

        return $path === $route || str_starts_with($path, $route.'/');
    }
}
