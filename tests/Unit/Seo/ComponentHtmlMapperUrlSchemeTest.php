<?php

namespace Tests\Unit\Seo;

use App\Seo\ComponentHtmlMapper;
use App\Seo\ExpressionEvaluator;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * SEO 렌더러의 URL 속성 실행 스킴 무해화 (KVE-2026-2348 — React 렌더러와 패리티)
 *
 * 클라이언트는 이동 sink·`<a href>` 에서 실행 스킴을 막는데, 봇 HTML 이 같은 값을 `<a href>` 로
 * 되살리면 그 자리가 우회로가 된다. href 는 `#`, src 는 스크립트 스킴만 제거, navigate 링크
 * 주입은 실행 스킴이면 생략한다.
 */
class ComponentHtmlMapperUrlSchemeTest extends TestCase
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

        $this->mapper = new ComponentHtmlMapper;
        $this->evaluator = new ExpressionEvaluator;

        $this->mapper->setComponentMap([
            'A' => ['tag' => 'a'],
            'Img' => ['tag' => 'img'],
            'Button' => ['tag' => 'button'],
        ]);
        $this->mapper->setRenderModes([]);
        $this->mapper->setSelfClosing(['img']);
        $this->mapper->setTextProps(['text']);
        $this->mapper->setAttrMap([]);
        $this->mapper->setAllowedAttrs(['href', 'src', 'alt']);
    }

    /**
     * query 에서 온 실행 스킴 href 가 # 로 무해화되는지 검증합니다.
     *
     * @scenario target_kind=script_scheme, sink=new_window_or_link
     *
     * @effects dangerous_href_neutralized
     *
     * @return void 반환값 없음
     */
    public function test_executable_href_is_neutralized_to_hash(): void
    {
        foreach (['javascript:alert(1)', ' JaVa'."\t".'ScRiPt:alert(1)', 'data:text/html,<b>x</b>'] as $payload) {
            $html = $this->mapper->render(
                [['name' => 'A', 'text' => 'go', 'props' => ['href' => '{{query.redirect_url}}']]],
                ['query' => ['redirect_url' => $payload]],
                $this->evaluator,
            );

            $this->assertStringContainsString('href="#"', $html);
            $this->assertStringNotContainsStringIgnoringCase('script:', $html);
        }
    }

    /**
     * 정상 외부 링크·mailto·data: 이미지가 그대로 렌더되는지(과차단 방지) 검증합니다.
     *
     * @scenario target_kind=same_origin, sink=new_window_or_link
     *
     * @effects external_link_preserved
     *
     * @return void 반환값 없음
     */
    public function test_regular_links_and_data_images_are_preserved(): void
    {
        $html = $this->mapper->render(
            [
                ['name' => 'A', 'text' => 'ext', 'props' => ['href' => 'https://sir.kr/']],
                ['name' => 'A', 'text' => 'mail', 'props' => ['href' => 'mailto:help@example.com']],
                ['name' => 'Img', 'props' => ['src' => 'data:image/png;base64,iVBORw0KGgo=', 'alt' => 'x']],
            ],
            [],
            $this->evaluator,
        );

        $this->assertStringContainsString('href="https://sir.kr/"', $html);
        $this->assertStringContainsString('href="mailto:help@example.com"', $html);
        $this->assertStringContainsString('src="data:image/png;base64,iVBORw0KGgo="', $html);
    }

    /**
     * 스크립트 스킴 src 가 속성째 제거되는지 검증합니다.
     *
     * @effects dangerous_href_neutralized
     *
     * @return void 반환값 없음
     */
    public function test_script_scheme_src_is_dropped(): void
    {
        $html = $this->mapper->render(
            [['name' => 'Img', 'props' => ['src' => 'javascript:alert(1)', 'alt' => 'x']]],
            [],
            $this->evaluator,
        );

        $this->assertStringNotContainsString('src=', $html);
    }

    /**
     * 실행 스킴 navigate 경로로는 링크를 주입하지 않는지 검증합니다.
     *
     * @scenario target_kind=script_scheme, sink=same_tab
     *
     * @effects nav_sink_rejects_script_scheme
     *
     * @return void 반환값 없음
     */
    public function test_navigate_link_is_not_injected_for_executable_path(): void
    {
        $html = $this->mapper->render(
            [[
                'name' => 'Button',
                'text' => '로그인',
                'actions' => [['handler' => 'navigate', 'params' => ['path' => '{{query.redirect}}']]],
            ]],
            ['query' => ['redirect' => 'javascript:alert(1)']],
            $this->evaluator,
        );

        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * same-origin navigate 경로는 종전대로 링크가 주입되는지 검증합니다.
     *
     * @scenario target_kind=same_origin, sink=same_tab
     *
     * @effects same_origin_redirect_preserved
     *
     * @return void 반환값 없음
     */
    public function test_navigate_link_is_injected_for_same_origin_path(): void
    {
        $html = $this->mapper->render(
            [[
                'name' => 'Button',
                'text' => '로그인',
                'actions' => [['handler' => 'navigate', 'params' => ['path' => '/login']]],
            ]],
            [],
            $this->evaluator,
        );

        $this->assertStringContainsString('<a href="/login"', $html);
    }

    /**
     * 첫 화면 `/` 로 가는 navigate(정적 경로·쿼리에서 온 값 모두)도 링크가 되는지 검증합니다.
     *
     * 평가기의 「빈 바인딩 뒤 남은 연산자 정리」가 `/` 한 글자를 지워 홈 링크가 사라지던 결함의 잠금.
     *
     * @scenario target_kind=same_origin, sink=same_tab
     *
     * @effects same_origin_redirect_preserved
     *
     * @return void 반환값 없음
     */
    public function test_navigate_link_to_home_path_is_injected(): void
    {
        $render = fn (string $path, array $query = []): string => $this->mapper->render(
            [['name' => 'Button', 'text' => '홈', 'actions' => [['handler' => 'navigate', 'params' => ['path' => $path]]]]],
            ['query' => $query],
            $this->evaluator,
        );

        $this->assertStringContainsString('<a href="/"', $render('/'));
        $this->assertStringContainsString('<a href="/"', $render('{{query.redirect}}', ['redirect' => '/']));
    }

    /**
     * 다른 출처로 풀리는 navigate 경로(`//host`·백슬래시 우회·사용자 정보 포함)는 링크로 만들지 않는지 검증합니다.
     *
     * 클라이언트는 이 값들로 이동하지 않는다(무동작) — 봇 HTML 이 바깥 링크로 내보내면 쿼리 유래 주소가
     * 검색 로봇용 화면에 주입된다.
     *
     * @scenario target_kind=offsite, sink=same_tab
     *
     * @effects nav_sink_rejects_offsite_target
     *
     * @return void 반환값 없음
     */
    public function test_navigate_link_is_not_injected_for_offsite_path(): void
    {
        config(['app.url' => 'https://g7.test']);

        $payloads = [
            'https://evil.example/x',
            'HTTPS://EVIL.EXAMPLE/x',
            ' https://evil.example/x',
            '//evil.example/x',
            '///evil.example/x',
            '/\\evil.example/x',
            '\\\\evil.example/x',
            "/\t/evil.example/x",
            'https://g7.test@evil.example/x',
            'https://g7.test:8443/x',
            'http://g7.test/x',
            'ftp://g7.test/x',
        ];

        foreach ($payloads as $payload) {
            $html = $this->mapper->render(
                [[
                    'name' => 'Button',
                    'text' => '이동',
                    'actions' => [['handler' => 'navigate', 'params' => ['path' => '{{query.redirect}}']]],
                ]],
                ['query' => ['redirect' => $payload]],
                $this->evaluator,
            );

            $this->assertStringNotContainsString('<a ', $html, '링크가 만들어진 값: '.json_encode($payload));
            $this->assertStringNotContainsStringIgnoringCase('evil.example', $html);
        }
    }

    /**
     * 같은 출처 전체 주소 navigate 는 경로(+쿼리·해시)로 바꿔 링크를 만드는지 검증합니다.
     *
     * 같은 출처 = 사이트 주소(`app.url`) 또는 이 요청의 주소. 어느 쪽이든 내보내는 값은 경로뿐이라
     * 위조한 Host 헤더로 바깥 링크가 만들어지지 않는다.
     *
     * @scenario target_kind=same_origin_full_url, sink=same_tab
     *
     * @effects same_origin_full_url_becomes_path
     *
     * @return void 반환값 없음
     */
    public function test_navigate_same_origin_full_url_becomes_relative_path(): void
    {
        config(['app.url' => 'https://g7.test']);

        $cases = [
            'https://g7.test/board/free?page=2#c1' => '/board/free?page=2#c1',
            'HTTPS://G7.TEST:443/shop' => '/shop',
            'https://g7.test' => '/',
        ];

        foreach ($cases as $payload => $expected) {
            $html = $this->mapper->render(
                [[
                    'name' => 'Button',
                    'text' => '이동',
                    'actions' => [['handler' => 'navigate', 'params' => ['path' => '{{query.redirect}}']]],
                ]],
                ['query' => ['redirect' => $payload]],
                $this->evaluator,
            );

            $this->assertStringContainsString('<a href="'.e($expected).'"', $html, '입력: '.$payload);
            $this->assertStringNotContainsString('g7.test', $html);
        }

        // 이 요청의 주소(사이트 주소의 별칭 도메인)도 같은 출처 — 경로만 내보낸다
        $this->app->instance('request', Request::create('https://alias.g7.test/'));

        $html = $this->mapper->render(
            [[
                'name' => 'Button',
                'text' => '이동',
                'actions' => [['handler' => 'navigate', 'params' => ['path' => 'https://alias.g7.test/mypage']]],
            ]],
            [],
            $this->evaluator,
        );

        $this->assertStringContainsString('<a href="/mypage"', $html);
    }

    /**
     * openWindow 의 같은 탭(`target: _self`)은 navigate 와 같은 판정, 새 창은 실행 스킴·https 아닌 외부 주소만 막는지 검증합니다.
     *
     * 엔진 `handleOpenWindow` 와 같다 — 같은 탭은 같은 출처 경로만, 새 창의 외부 주소는 https 만 연다.
     *
     * @scenario target_kind=offsite, sink=same_tab
     *
     * @effects nav_sink_rejects_offsite_target
     *
     * @return void 반환값 없음
     */
    public function test_open_window_link_follows_engine_policy(): void
    {
        config(['app.url' => 'https://g7.test']);

        $render = fn (array $params): string => $this->mapper->render(
            [['name' => 'Button', 'text' => '열기', 'actions' => [['handler' => 'openWindow', 'params' => $params]]]],
            [],
            $this->evaluator,
        );

        $this->assertStringNotContainsString('<a ', $render(['path' => 'https://evil.example/x', 'target' => '_self']));
        $this->assertStringContainsString('<a href="/x"', $render(['path' => 'https://g7.test/x', 'target' => '_self']));
        $this->assertStringNotContainsString('<a ', $render(['path' => 'http://insecure.example/x']));
        $this->assertStringNotContainsString('<a ', $render(['path' => '//evil.example/x']));
    }

    /**
     * openWindow 새 창 링크에 `rel="noopener noreferrer"` 가 붙고 정상 외부 https 주소는 그대로인지 검증합니다.
     *
     * @effects external_link_preserved, new_window_link_has_noopener
     *
     * @return void 반환값 없음
     */
    public function test_open_window_link_has_noopener(): void
    {
        config(['app.url' => 'https://g7.test']);

        $html = $this->mapper->render(
            [
                ['name' => 'Button', 'text' => '외부', 'actions' => [['handler' => 'openWindow', 'params' => ['path' => 'https://sir.kr/']]]],
                ['name' => 'A', 'text' => '안내', 'actions' => [['handler' => 'openWindow', 'params' => ['path' => '/guide']]]],
            ],
            [],
            $this->evaluator,
        );

        $this->assertStringContainsString('<a href="https://sir.kr/" target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('<a href="/guide" target="_blank" rel="noopener noreferrer"', $html);
    }
}
