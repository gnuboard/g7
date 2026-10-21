<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use Tests\TestCase;

/**
 * 봇(SEO) HTML 의 navigate 대체 경로(`params.fallbackPath`) — 엔진과 같은 판정
 *
 * 클라이언트는 path 가 비었거나 거부되면(실행 스킴·다른 출처) fallbackPath 로 이동한다. 봇 HTML 도 같은 자리를
 * fallbackPath 링크로 그려야 검색 로봇과 사람이 같은 착지점을 본다.
 */
class ComponentHtmlMapperNavigateFallbackPathTest extends TestCase
{
    private ComponentHtmlMapper $mapper;

    private ExpressionEvaluator $evaluator;

    /**
     * 테스트용 최소 컴포넌트 매핑으로 매퍼와 평가기를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://g7.test']);

        $this->mapper = new ComponentHtmlMapper;
        $this->evaluator = new ExpressionEvaluator;

        $this->mapper->setComponentMap(['Button' => ['tag' => 'button']]);
        $this->mapper->setRenderModes([]);
        $this->mapper->setSelfClosing([]);
        $this->mapper->setTextProps(['text']);
        $this->mapper->setAttrMap([]);
        $this->mapper->setAllowedAttrs([]);
    }

    /**
     * 로그인 단추처럼 `{{query.redirect}}` 로 이동하는 navigate 를 그립니다.
     *
     * @param  array  $params  navigate 인자
     * @param  array  $query  주소창 쿼리 값
     * @return string 렌더된 HTML
     */
    private function renderNavigate(array $params, array $query = []): string
    {
        return $this->mapper->render(
            [['name' => 'Button', 'text' => '로그인', 'actions' => [['handler' => 'navigate', 'params' => $params]]]],
            ['query' => $query],
            $this->evaluator,
        );
    }

    // @scenario path_kind=same_origin_full_url, fallback_path=valid
    /**
     * 받아들여지는 path 면 fallbackPath 를 쓰지 않는지 검증합니다.
     *
     * @scenario path_kind=same_origin_path, fallback_path=valid
     *
     * @effects accepted_path_ignores_fallback_path
     *
     * @return void 반환값 없음
     */
    public function test_accepted_path_ignores_fallback_path(): void
    {
        $this->assertStringContainsString('<a href="/board/free"', $this->renderNavigate(['path' => '{{query.redirect}}', 'fallbackPath' => '/'], ['redirect' => '/board/free']));
        $this->assertStringContainsString('<a href="/mypage"', $this->renderNavigate(['path' => '{{query.redirect}}', 'fallbackPath' => '/'], ['redirect' => 'https://g7.test/mypage']));
    }

    // @scenario path_kind=offsite, fallback_path=valid
    /**
     * 거부되는 path(실행 스킴·다른 출처)는 fallbackPath 로 링크하는지 검증합니다.
     *
     * @scenario path_kind=script_scheme, fallback_path=valid
     *
     * @effects rejected_path_uses_fallback_path, bot_link_uses_fallback_path
     *
     * @return void 반환값 없음
     */
    public function test_rejected_path_links_to_fallback_path(): void
    {
        foreach (['javascript:alert(1)', '//evil.example/x', '/\\evil.example', 'https://evil.example/'] as $payload) {
            $html = $this->renderNavigate(['path' => '{{query.redirect}}', 'fallbackPath' => '/'], ['redirect' => $payload]);

            $this->assertStringContainsString('<a href="/"', $html, '입력: '.$payload);
            $this->assertStringNotContainsString('evil.example', $html);
        }
    }

    // @scenario path_kind=relative, fallback_path=rejected
    // @scenario path_kind=relative, fallback_path=absent
    /**
     * 상대 경로·쿼리만 있는 path 는 클라이언트 같은 탭 판정이 거부하는 값이라 fallbackPath 로 링크하고,
     * fallbackPath 가 없거나 그것도 상대 경로면 링크를 만들지 않는지 검증합니다.
     *
     * @scenario path_kind=relative, fallback_path=valid
     *
     * @effects rejected_path_uses_fallback_path, rejected_fallback_path_is_no_op, absent_fallback_path_keeps_no_op
     *
     * @return void 반환값 없음
     */
    public function test_relative_path_follows_same_tab_policy(): void
    {
        foreach (['admin/users', './x', '../x', '?q=1'] as $payload) {
            $html = $this->renderNavigate(['path' => '{{query.redirect}}', 'fallbackPath' => '/admin'], ['redirect' => $payload]);
            $this->assertStringContainsString('<a href="/admin"', $html, '입력: '.$payload);
        }

        $this->assertStringNotContainsString('<a ', $this->renderNavigate(['path' => '{{query.redirect}}', 'fallbackPath' => 'admin'], ['redirect' => 'admin/users']));
        $this->assertStringNotContainsString('<a ', $this->renderNavigate(['path' => '{{query.redirect}}'], ['redirect' => 'admin/users']));
    }

    /**
     * 빈 path 는 fallbackPath 로 링크하는지 검증합니다.
     *
     * @scenario path_kind=empty, fallback_path=valid
     *
     * @effects empty_path_uses_fallback_path, bot_link_uses_fallback_path
     *
     * @return void 반환값 없음
     */
    public function test_empty_path_links_to_fallback_path(): void
    {
        $html = $this->renderNavigate(['path' => '{{query.redirect}}', 'fallbackPath' => '/mypage'], ['redirect' => '']);

        $this->assertStringContainsString('<a href="/mypage"', $html);
    }

    // @scenario path_kind=offsite, fallback_path=rejected
    // @scenario path_kind=empty, fallback_path=rejected
    // @scenario path_kind=script_scheme, fallback_path=absent
    // @scenario path_kind=offsite, fallback_path=absent
    // @scenario path_kind=empty, fallback_path=absent
    /**
     * fallbackPath 도 거부·빈 값이면 링크를 만들지 않고, fallbackPath 가 없으면 종전대로 링크를 만들지 않는지 검증합니다.
     *
     * @scenario path_kind=script_scheme, fallback_path=rejected
     *
     * @effects rejected_fallback_path_is_no_op, absent_fallback_path_keeps_no_op
     *
     * @return void 반환값 없음
     */
    public function test_no_link_when_fallback_path_rejected_or_absent(): void
    {
        $this->assertStringNotContainsString('<a ', $this->renderNavigate(['path' => '{{query.redirect}}', 'fallbackPath' => '//evil.example'], ['redirect' => 'javascript:x']));
        $this->assertStringNotContainsString('<a ', $this->renderNavigate(['path' => '{{query.redirect}}', 'fallbackPath' => ''], ['redirect' => '']));
        $this->assertStringNotContainsString('<a ', $this->renderNavigate(['path' => '{{query.redirect}}'], ['redirect' => 'https://evil.example/']));
        $this->assertStringNotContainsString('<a ', $this->renderNavigate(['path' => '{{query.redirect}}'], ['redirect' => '']));
    }

    /**
     * path 가 실행 시점 값(`_local`)이라 봇이 풀 수 없으면 fallbackPath 로 대신 링크하지 않는지 검증합니다.
     *
     * 사람은 실행 시점 값으로 다른 곳에 가므로, 봇 HTML 이 대체 경로를 링크하면 실제 착지점과 어긋난다.
     *
     * @scenario path_kind=same_origin_path, fallback_path=valid
     *
     * @effects accepted_path_ignores_fallback_path
     *
     * @return void 반환값 없음
     */
    public function test_unresolved_runtime_path_does_not_link_fallback(): void
    {
        $this->assertStringNotContainsString('<a ', $this->renderNavigate(['path' => '{{_local.returnTo}}', 'fallbackPath' => '/']));
    }
}
