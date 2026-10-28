<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Support;

use Plugins\Sirsoft\Popup\Support\RoutePath;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 노출 페이지 경로 판정 테스트 — 서버 층 (매니페스트 route-matching.yaml, layer=php)
 *
 * 브라우저 판정(`resources/js/lib/popupVisibility.ts`)과 같은 표본에서 같은 결과를 내야 한다(결정 D2).
 * 쿼리·해시 제거는 브라우저가 경로를 받을 때만 하므로 이 층에는 그 케이스가 없다.
 */
class RoutePathTest extends PluginTestCase
{
    /**
     * 지정 경로와 같은 경로에 맞는다.
     *
     * @scenario case=exact_path, layer=php
     *
     * @effects php_and_ts_agree
     *
     * @return void 반환값 없음
     */
    public function test_exact_path(): void
    {
        $this->assertTrue(RoutePath::matches('/shop', '/shop'));
    }

    /**
     * 경로 경계 아래 하위 경로에 맞는다.
     *
     * @scenario case=child_path, layer=php
     *
     * @effects php_and_ts_agree
     *
     * @return void 반환값 없음
     */
    public function test_child_path(): void
    {
        $this->assertTrue(RoutePath::matches('/shop/products', '/shop'));
    }

    /**
     * 여러 단계 아래 하위 경로에도 맞는다.
     *
     * @scenario case=deep_child_path, layer=php
     *
     * @effects php_and_ts_agree
     *
     * @return void 반환값 없음
     */
    public function test_deep_child_path(): void
    {
        $this->assertTrue(RoutePath::matches('/shop/products/1/reviews', '/shop'));
    }

    /**
     * 경로 경계가 아니면 맞지 않는다 — /shop 은 /shopping 에 뜨지 않는다.
     *
     * @scenario case=prefix_without_boundary_excluded, layer=php
     *
     * @effects php_and_ts_agree
     *
     * @return void 반환값 없음
     */
    public function test_prefix_without_boundary_excluded(): void
    {
        $this->assertFalse(RoutePath::matches('/shopping', '/shop'));
        $this->assertFalse(RoutePath::matches('/shop-event', '/shop'));
    }

    /**
     * `/` 는 홈에만 맞는다.
     *
     * @scenario case=root_matches_home_only, layer=php
     *
     * @effects php_and_ts_agree
     *
     * @return void 반환값 없음
     */
    public function test_root_matches_home_only(): void
    {
        $this->assertTrue(RoutePath::matches('/', '/'));
        $this->assertTrue(RoutePath::matches('', '/'));
    }

    /**
     * `/` 는 하위 경로에 맞지 않는다(「전체 페이지」와 구분).
     *
     * @scenario case=root_excludes_child, layer=php
     *
     * @effects php_and_ts_agree
     *
     * @return void 반환값 없음
     */
    public function test_root_excludes_child(): void
    {
        $this->assertFalse(RoutePath::matches('/shop', '/'));
        $this->assertFalse(RoutePath::matches('/board/notice', '/'));
    }

    /**
     * 지정 경로 끝 `/` 는 떼고 판정·저장한다.
     *
     * @scenario case=route_trailing_slash_normalized, layer=php
     *
     * @effects php_and_ts_agree
     *
     * @return void 반환값 없음
     */
    public function test_route_trailing_slash_normalized(): void
    {
        $this->assertSame('/shop', RoutePath::normalize(' /shop/ '));
        $this->assertSame('/', RoutePath::normalize('/'));
        $this->assertSame('/', RoutePath::normalize('///'));
        $this->assertTrue(RoutePath::matches('/shop/1', '/shop/'));
    }

    /**
     * 현재 경로 끝 `/` 도 떼고 판정한다.
     *
     * @scenario case=path_trailing_slash_normalized, layer=php
     *
     * @effects php_and_ts_agree
     *
     * @return void 반환값 없음
     */
    public function test_path_trailing_slash_normalized(): void
    {
        $this->assertTrue(RoutePath::matches('/shop/', '/shop'));
        $this->assertFalse(RoutePath::matches('/shopping/', '/shop'));
    }

    /**
     * 대소문자를 구분한다.
     *
     * @scenario case=case_sensitive, layer=php
     *
     * @effects php_and_ts_agree
     *
     * @return void 반환값 없음
     */
    public function test_case_sensitive(): void
    {
        $this->assertFalse(RoutePath::matches('/Shop', '/shop'));
    }

    /**
     * 「전체 페이지」는 경로 판정을 하지 않는다 — 서버 층에서는 형식 검증이 모든 경로를 허용하지 않는 것만 본다.
     *
     * 범위가 전체면 경로 목록을 저장하지 않으므로(`PopupControllerTest::test_store_routes_normalized_and_deduplicated`),
     * 여기서는 형식 판정이 정상 경로를 받고 이상 경로를 막는지 확인한다.
     *
     * @scenario case=scope_all_matches_everything, layer=php
     *
     * @effects php_and_ts_agree
     *
     * @return void 반환값 없음
     */
    public function test_scope_all_and_valid_format(): void
    {
        foreach (['/', '/shop', '/page/회사소개', '/a-b_c.d~e', '/%EA%B0%80'] as $ok) {
            $this->assertTrue(RoutePath::isValid($ok), $ok);
        }
        foreach (['', 'shop', '//x', '/a//b', '/a/../b', '/a/./b', '/a?b', '/a#b', '/a*', '/a b', str_repeat('/a', 101)] as $bad) {
            $this->assertFalse(RoutePath::isValid($bad), $bad);
        }
    }
}
