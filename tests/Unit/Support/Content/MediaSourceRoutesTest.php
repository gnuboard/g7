<?php

namespace Tests\Unit\Support\Content;

use App\Extension\HookManager;
use App\Support\Content\ContentHtmlPolicy;
use Tests\TestCase;

/**
 * 미디어 출처 허용 패턴 — 확장이 등록한 실제 서빙 라우트에서 만든다 (#887)
 *
 * 경로 이름 규약(`…/content-media/{12자}`)만 맞춘 가짜 경로는 등록된 라우트가 아니므로 통과하지 못한다. 모듈(`api.modules.*`)·
 * 플러그인(`api.plugins.*`) 라우트 모두, 라우트 `where`(해시 12자·slug 한 조각)와 서명 쿼리 꼬리를 그대로 따른다.
 *
 * @scenario-manifest tests/scenarios/content-render-policy.yaml
 */
class MediaSourceRoutesTest extends TestCase
{
    use RegistersMediaSourceRoutes;

    /** 플러그인 서빙 라우트 */
    private const PLUGIN_URI = 'api/plugins/vendor-plugin/content-media/{hash}';

    private const PLUGIN_ROUTE = 'api.plugins.vendor-plugin.content-media.show';

    /**
     * 시험이 더한 출처 패턴 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(ContentHtmlPolicy::MEDIA_SOURCE_FILTER_HOOK);

        parent::tearDown();
    }

    /**
     * 모듈·플러그인 서빙 라우트가 각각 정규식이 된다 — 등록 라우트 주소(서명 쿼리 포함)는 통과, 가짜 경로·다른 해시 길이·여러 조각 slug·
     * 다른 출처 주소는 거부.
     *
     * @scenario case=media_source_routes
     *
     * @effects media_patterns_from_routes, fake_media_path_rejected
     *
     * @return void 반환값 없음
     */
    public function test_patterns_follow_registered_module_and_plugin_routes(): void
    {
        $registry = self::freshMediaSourceRoutes();
        self::registerMediaRoute('api/modules/vendor-module/boards/{slug}/content-media/{hash}', 'api.modules.vendor-module.boards.content-media.show');
        self::registerMediaRoute(self::PLUGIN_URI, self::PLUGIN_ROUTE);

        $this->assertSame([
            '^/api/modules/vendor-module/boards/(?:[^/?#]+)/content-media/(?:[a-zA-Z0-9]{12})(?:\?[^"\'<>\s]*)?$',
            '^/api/plugins/vendor-plugin/content-media/(?:[a-zA-Z0-9]{12})(?:\?[^"\'<>\s]*)?$',
        ], $registry->patterns());
        $this->assertSame(['api.modules.vendor-module.boards.content-media.show', self::PLUGIN_ROUTE], $registry->routeNames());

        foreach ([
            '/api/modules/vendor-module/boards/free/content-media/AAAAAAAAAAAA',
            '/api/modules/vendor-module/boards/free/content-media/AAAAAAAAAAAA?expires=1&signature=z',
            '/api/plugins/vendor-plugin/content-media/abcdefghijkl',
        ] as $allowed) {
            $this->assertTrue($this->matchesAny($registry->patterns(), $allowed), $allowed.' 는 통과');
        }

        foreach ([
            '/api/modules/x/content-media/abcdefghijkl',
            '/api/modules/vendor-module/content-media/abcdefghijkl',
            '/api/modules/vendor-module/boards/free/content-media/AAAAAAAAAAA',
            '/api/modules/vendor-module/boards/a/b/content-media/AAAAAAAAAAAA',
            '/api/plugins/other-plugin/content-media/abcdefghijkl',
            'https://evil.test/api/plugins/vendor-plugin/content-media/abcdefghijkl',
            '/api/plugins/vendor-plugin/content-media/abcdefghijkl"onerror="x',
        ] as $rejected) {
            $this->assertFalse($this->matchesAny($registry->patterns(), $rejected), $rejected.' 는 거부');
        }
    }

    /**
     * 라우트가 없는 등록 이름(비활성·미설치 확장)은 건너뛰고, 아무것도 등록하지 않으면 빈 목록이다. 같은 이름은 한 번만 등록된다.
     *
     * @scenario case=media_source_routes
     *
     * @effects missing_route_skipped
     *
     * @return void 반환값 없음
     */
    public function test_missing_routes_are_skipped(): void
    {
        $registry = self::freshMediaSourceRoutes();
        $this->assertSame([], $registry->patterns());

        $registry->register('api.modules.disabled-module.content-media.show');
        $registry->register('');
        self::registerMediaRoute(self::PLUGIN_URI, self::PLUGIN_ROUTE);
        $registry->register(self::PLUGIN_ROUTE);

        $this->assertSame(['api.modules.disabled-module.content-media.show', self::PLUGIN_ROUTE], $registry->routeNames());
        $this->assertSame(['^/api/plugins/vendor-plugin/content-media/(?:[a-zA-Z0-9]{12})(?:\?[^"\'<>\s]*)?$'], $registry->patterns());
    }

    /**
     * 고정 조각의 정규식 메타 문자는 벗기고, 선택 파라미터는 앞 `/` 와 함께 선택이다.
     *
     * @scenario case=media_source_routes
     *
     * @effects media_patterns_from_routes
     *
     * @return void 반환값 없음
     */
    public function test_literal_metacharacters_and_optional_parameters(): void
    {
        $registry = self::freshMediaSourceRoutes();
        self::registerMediaRoute('api/plugins/vendor-plugin/media.v1/{hash?}', 'api.plugins.vendor-plugin.media.show', []);

        $pattern = $registry->patterns()[0];
        $this->assertSame('^/api/plugins/vendor-plugin/media\.v1(?:/(?:[^/?#]+))?(?:\?[^"\'<>\s]*)?$', $pattern);
        $this->assertTrue($this->matchesAny([$pattern], '/api/plugins/vendor-plugin/media.v1/x1'));
        $this->assertTrue($this->matchesAny([$pattern], '/api/plugins/vendor-plugin/media.v1'));
        $this->assertFalse($this->matchesAny([$pattern], '/api/plugins/vendor-plugin/mediaXv1/x1'), '점은 글자 그대로');
    }

    /**
     * 본문 규칙이 레지스트리 패턴을 쓴다 — 화면 규칙(`media_src_patterns`)에 실리고, 봇·저장 마무리는 등록 라우트 미디어만 남긴다.
     * 필터 훅 추가분은 더해지고 컴파일되지 않는 값은 버린다. 등록이 없으면 미디어 요소는 지워진다.
     *
     * @scenario case=media_source_routes
     *
     * @effects registered_route_media_kept, fake_media_path_rejected, media_patterns_from_routes
     *
     * @return void 반환값 없음
     */
    public function test_content_policy_uses_registered_routes(): void
    {
        $html = '<video src="/api/modules/sirsoft-board/boards/free/content-media/AAAAAAAAAAAA?expires=1&amp;signature=z" controls></video>'
            .'<video src="/api/modules/x/content-media/abcdefghijkl" controls></video>';
        $policy = ContentHtmlPolicy::fromSettings(['allow_uploaded_video' => true]);

        self::freshMediaSourceRoutes();
        $this->assertSame([], $policy->toFrontendArray()['media_src_patterns']);
        $this->assertStringNotContainsString('<video', $policy->finalize($html, 'bot'), '등록 라우트가 없으면 미디어는 남지 않는다');

        self::registerBoardMediaRoute();
        $patterns = $policy->toFrontendArray()['media_src_patterns'];
        $this->assertSame(['^/api/modules/sirsoft-board/boards/(?:[^/?#]+)/content-media/(?:[a-zA-Z0-9]{12})(?:\?[^"\'<>\s]*)?$'], $patterns);

        foreach (['bot', 'store'] as $mode) {
            $out = $policy->finalize($html, $mode);
            $this->assertStringContainsString('boards/free/content-media/AAAAAAAAAAAA', $out, $mode);
            $this->assertStringNotContainsString('/api/modules/x/content-media/', $out, $mode.' — 가짜 경로 제거');
        }

        HookManager::addFilter(ContentHtmlPolicy::MEDIA_SOURCE_FILTER_HOOK, fn (array $current) => array_merge($current, ['^/media/[a-z]+$', '([']));
        $this->assertSame([$patterns[0], '^/media/[a-z]+$'], $policy->mediaSourcePatterns(), '필터 추가분은 더하고 무효 정규식은 버린다');
    }

    /**
     * 정규식 하나라도 맞는지(PHP `#` 구분자 — 본문 규칙과 같은 컴파일).
     *
     * @param  array<int, string>  $patterns  정규식
     * @param  string  $value  주소
     * @return bool 맞으면 true
     */
    private function matchesAny(array $patterns, string $value): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match('#'.str_replace('#', '\#', $pattern).'#', $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
