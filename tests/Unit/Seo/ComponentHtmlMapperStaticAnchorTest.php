<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use Tests\TestCase;

/**
 * 봇(SEO) 화면의 정적 링크(`A` 의 href·target)가 사람 화면 `A` 컴포넌트와 같은 규칙으로 그려지는지 (7.1.1 출시 전 실측)
 *
 * 사람 화면 `A`(basic·hello)는 `target="_blank"` 면 rel 에 `noopener noreferrer` 를 합치고(넘겨받은 rel 토큰은 남긴다),
 * `fallbackHref` 를 받으면 이 사이트 주소가 아닌 href(다른 사이트·`//host`·`https://이 사이트//다른호스트`·실행 스킴)를
 * 그 주소로 바꾸며, `disabled` 면 href 를 뺀다. 봇 화면은 이동 액션에서 만든 링크에만 이 규칙을 썼고 정적 `A` 는
 * 적힌 그대로 그렸다 — 새 창 링크가 `rel` 없이 나가고, 대체 주소를 준 알림 링크도 원래 주소로 나갔다.
 *
 * 대체 주소가 없는 href 는 사람 화면도 손대지 않으므로(엔진은 실행 스킴만 `#`) 봇 화면도 그대로 둔다 — 과차단 방지.
 */
class ComponentHtmlMapperStaticAnchorTest extends TestCase
{
    private ComponentHtmlMapper $mapper;

    private ExpressionEvaluator $evaluator;

    /**
     * 사이트 주소와 최소 컴포넌트 매핑으로 매퍼와 평가기를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://g7.test']);

        $this->mapper = new ComponentHtmlMapper;
        $this->evaluator = new ExpressionEvaluator;

        $this->mapper->setComponentMap([
            'A' => ['tag' => 'a'],
            'Div' => ['tag' => 'div'],
        ]);
        $this->mapper->setRenderModes([]);
        $this->mapper->setSelfClosing([]);
        $this->mapper->setTextProps(['text']);
        $this->mapper->setAttrMap([]);
        $this->mapper->setAllowedAttrs(['href', 'target', 'rel', 'role', 'class']);
    }

    /**
     * 컴포넌트 하나를 그립니다.
     *
     * @param  array<string, mixed>  $props  A 의 props
     * @param  array<string, mixed>  $context  데이터 컨텍스트
     * @return string 렌더링 결과
     */
    private function renderA(array $props, array $context = []): string
    {
        return $this->mapper->render([['name' => 'A', 'text' => 'go', 'props' => $props]], $context, $this->evaluator);
    }

    /**
     * 새 창 링크는 rel 에 noopener noreferrer 가 붙습니다 — 넘겨받은 rel 토큰은 남기고 대소문자 무시로 중복은 넣지 않습니다.
     *
     * @scenario target_kind=offsite, sink=new_window_or_link
     *
     * @effects static_blank_link_has_noopener
     *
     * @return void 반환값 없음
     */
    public function test_blank_target_link_gets_noopener_noreferrer(): void
    {
        $this->assertStringContainsString(
            'rel="noopener noreferrer"',
            $this->renderA(['href' => 'https://partner.example/a', 'target' => '_blank']),
        );

        $this->assertStringContainsString(
            'rel="nofollow noopener noreferrer"',
            $this->renderA(['href' => 'https://partner.example/a', 'target' => '_blank', 'rel' => 'nofollow']),
        );

        $this->assertStringContainsString(
            'rel="NoOpener noreferrer"',
            $this->renderA(['href' => '/page/faq', 'target' => '_blank', 'rel' => 'NoOpener']),
        );

        // target 이 식이어도 푼 값으로 판정한다
        $this->assertStringContainsString(
            'rel="noopener noreferrer"',
            $this->renderA(['href' => '/page/faq', 'target' => '{{query.t}}'], ['query' => ['t' => '_blank']]),
        );
    }

    /**
     * 새 창이 아니면 rel 을 만들지 않고 받은 값 그대로 둡니다(사람 화면 `mergeRelForTarget` 과 같음).
     *
     * @scenario target_kind=same_origin, sink=new_window_or_link
     *
     * @effects static_blank_link_has_noopener
     *
     * @return void 반환값 없음
     */
    public function test_non_blank_target_keeps_rel_as_given(): void
    {
        $self = $this->renderA(['href' => '/page/faq', 'target' => '_self']);
        $this->assertStringNotContainsString('rel=', $self);

        $this->assertStringContainsString(
            'rel="nofollow"',
            $this->renderA(['href' => 'https://partner.example/a', 'rel' => 'nofollow']),
        );
    }

    /**
     * fallbackHref 를 받으면 이 사이트 주소가 아닌 href 를 대체 주소로 바꿉니다 — 사람 화면 `A` 와 같은 판정.
     *
     * @scenario target_kind=offsite, sink=new_window_or_link
     *
     * @effects static_link_fallback_href_applied
     *
     * @return void 반환값 없음
     */
    public function test_fallback_href_replaces_non_site_href(): void
    {
        foreach ([
            'http://evil.test/x',
            '//evil.example/x',
            '/\\evil.example/y',
            'https://g7.test//evil.example/z',
            'javascript:alert(1)',
        ] as $payload) {
            $html = $this->renderA(
                ['href' => '{{n.url}}', 'fallbackHref' => '/mypage/notifications'],
                ['n' => ['url' => $payload]],
            );

            $this->assertStringContainsString('href="/mypage/notifications"', $html, $payload);
            $this->assertStringNotContainsString('evil', $html, $payload);
        }
    }

    /**
     * fallbackHref 가 있어도 이 사이트 주소(경로·같은 출처 전체 주소)는 그대로, 빈 href 는 링크가 아닌 채로 둡니다.
     *
     * @scenario target_kind=same_origin_full_url, sink=new_window_or_link
     *
     * @effects static_link_fallback_href_applied
     *
     * @return void 반환값 없음
     */
    public function test_fallback_href_keeps_site_href_and_empty_href(): void
    {
        $this->assertStringContainsString(
            'href="/mypage/orders/1"',
            $this->renderA(['href' => '/mypage/orders/1', 'fallbackHref' => '/mypage/notifications']),
        );

        $this->assertStringContainsString(
            'href="https://g7.test/page/faq?x=1"',
            $this->renderA(['href' => 'https://g7.test/page/faq?x=1', 'fallbackHref' => '/mypage/notifications']),
        );

        $empty = $this->renderA(['href' => '{{n.url}}', 'fallbackHref' => '/mypage/notifications'], ['n' => ['url' => '']]);
        $this->assertStringNotContainsString('href=', $empty);
    }

    /**
     * 비활성 링크는 href 를 빼고 role="link" 로 그립니다.
     *
     * @scenario target_kind=same_origin, sink=new_window_or_link
     *
     * @effects static_disabled_link_has_no_href
     *
     * @return void 반환값 없음
     */
    public function test_disabled_link_has_no_href(): void
    {
        $html = $this->renderA(['href' => '/page/faq', 'disabled' => '{{route.locked}}'], ['route' => ['locked' => true]]);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringContainsString('role="link"', $html);

        $this->assertStringContainsString(
            'href="/page/faq"',
            $this->renderA(['href' => '/page/faq', 'disabled' => '{{route.locked}}'], ['route' => ['locked' => false]]),
        );
    }

    /**
     * 대체 주소가 없으면 href 는 사람 화면처럼 그대로입니다 — 같은 출처 전체 주소·외부 주소·`//` 경로 유지, 실행 스킴만 `#`.
     *
     * @scenario target_kind=script_scheme, sink=new_window_or_link
     *
     * @effects external_link_preserved
     *
     * @return void 반환값 없음
     */
    public function test_href_without_fallback_matches_human_component(): void
    {
        $this->assertStringContainsString('href="https://g7.test/page/faq"', $this->renderA(['href' => 'https://g7.test/page/faq']));
        $this->assertStringContainsString('href="http://partner.example/x"', $this->renderA(['href' => 'http://partner.example/x']));
        $this->assertStringContainsString('href="https://g7.test//evil.example/z"', $this->renderA(['href' => 'https://g7.test//evil.example/z']));
        $this->assertStringContainsString('href="#"', $this->renderA(['href' => 'javascript:alert(1)']));
    }

    /**
     * 홈 링크(`/`)는 href 가 그대로 나갑니다 — 정적·바인딩·빈 바인딩 뒤 `/`·대체 주소 `/`·이동 액션 경로 `/` 모두 (7.1.1).
     *
     * 평가기의 「고립 연산자 정리」가 `/` 한 글자를 지워 basic `_user_base` 의 모바일 로고·서랍 「홈」 이 봇 화면에서
     * href 없는 `<a>` 로 나갔다. 사람 화면 A 는 `href="/"` 다.
     *
     * @scenario target_kind=same_origin, sink=new_window_or_link
     *
     * @effects static_root_href_kept
     *
     * @return void 반환값 없음
     */
    public function test_root_href_is_kept(): void
    {
        $this->assertStringContainsString('href="/"', $this->renderA(['href' => '/']));
        $this->assertStringContainsString('href="/"', $this->renderA(['href' => '{{home}}'], ['home' => '/']));
        $this->assertStringContainsString('href="/"', $this->renderA(['href' => '/{{n.missing}}'], ['n' => []]));
        $this->assertStringContainsString(
            'href="/"',
            $this->renderA(['href' => 'http://evil.test/x', 'fallbackHref' => '/']),
        );
        $this->assertStringContainsString(
            'href="/" target="_blank" rel="noopener noreferrer"',
            $this->renderA(['href' => '/', 'target' => '_blank']),
        );

        $navigate = $this->mapper->render(
            [['name' => 'Div', 'text' => 'home', 'actions' => [['type' => 'click', 'handler' => 'navigate', 'params' => ['path' => '/']]]]],
            [],
            $this->evaluator,
        );
        $this->assertStringContainsString('href="/"', $navigate);
    }

    /**
     * 링크가 아닌 태그의 target·rel 은 건드리지 않습니다.
     *
     * @scenario target_kind=same_origin, sink=new_window_or_link
     *
     * @effects static_blank_link_has_noopener
     *
     * @return void 반환값 없음
     */
    public function test_non_anchor_tags_are_untouched(): void
    {
        $html = $this->mapper->render(
            [['name' => 'Div', 'text' => 'x', 'props' => ['target' => '_blank']]],
            [],
            $this->evaluator,
        );

        $this->assertStringNotContainsString('rel=', $html);
    }
}
