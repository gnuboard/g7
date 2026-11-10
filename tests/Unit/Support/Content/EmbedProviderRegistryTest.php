<?php

namespace Tests\Unit\Support\Content;

use App\Extension\HookManager;
use App\Rules\EmbedCustomProviderRule;
use App\Support\Content\ContentHtmlPolicy;
use App\Support\Content\EmbedProviderRegistry;
use Tests\TestCase;

/**
 * 외부 영상 서비스 — 프리셋·직접 추가 해석, 주소 형식 컴파일, 우선순위, PHP↔JS 정규식 고정본 (#883 5단계)
 *
 * @scenario-manifest tests/scenarios/content-render-policy.yaml
 */
class EmbedProviderRegistryTest extends TestCase
{
    use RegistersMediaSourceRoutes;

    /** PHP·JS 가 함께 쓰는 고정본 */
    public const FIXTURE = 'resources/js/core/contentPolicy/__tests__/fixtures/render-policy.json';

    /**
     * 고정본을 만드는 설정 — 프리셋 전부 켜고 직접 추가 1개.
     *
     * @return array<string, mixed> 설정
     */
    public static function fixtureSettings(): array
    {
        $settings = ContentHtmlPolicy::defaults();
        $settings['embed_presets'] = array_fill_keys(array_keys(EmbedProviderRegistry::presets()), true);
        $settings['embed_custom_providers'] = [[
            'id' => 'chzzk_clip', 'name' => '치지직 클립', 'enabled' => true,
            'url_patterns' => ['https://chzzk.naver.com/clips/{id}'],
            'player_url' => 'https://chzzk.naver.com/embed/clip/{id}',
            'id_rule' => 'alnum_dash', 'aspect' => '16:9', 'allow' => ['fullscreen'],
        ]];

        return $settings;
    }

    /**
     * 고정본 표본 주소.
     *
     * @return array<int, string> 주소
     */
    public static function fixtureSamples(): array
    {
        return [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ?t=10', 'https://m.youtube.com/shorts/abcdefghijk',
            'https://www.youtube.com/embed/dQw4w9WgXcQ', 'https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871',
            'https://www.dailymotion.com/video/x8abc12', 'https://dai.ly/x8abc12', 'https://tv.naver.com/v/12345678', 'https://tv.naver.com/h/87654321',
            'https://tv.kakao.com/v/445566', 'https://tv.kakao.com/channel/123/cliplink/998877', 'https://vod.sooplive.co.kr/player/1234567',
            'https://www.tiktok.com/@user.name/video/7234567890123456789', 'https://www.instagram.com/p/AbC_dEf-123/', 'https://www.instagram.com/reel/XyZ987/',
            'https://chzzk.naver.com/clips/aB3-cd_9', 'https://www.youtube.com/watch?v=short', 'https://notyoutube.com/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)',
        ];
    }

    /**
     * 지금 PHP 가 만드는 고정본 내용.
     *
     * 미디어 출처 패턴은 등록된 서빙 라우트에서 만들어지므로(#887) 번들 게시판 모양 서빙 라우트 하나를 등록한 상태로 만든다 —
     * 표시기·편집기 시험의 표본 주소(`/api/modules/sirsoft-board/boards/free/content-media/…`)가 이 패턴으로 통과한다.
     *
     * @return array<string, mixed> 고정본
     */
    public static function buildFixture(): array
    {
        self::registerBoardMediaRoute();
        $settings = self::fixtureSettings();
        $providers = EmbedProviderRegistry::enabled($settings);
        $samples = [];
        foreach (self::fixtureSamples() as $url) {
            $r = EmbedProviderRegistry::resolve($url, $providers);
            $samples[] = ['url' => $url, 'provider' => $r['provider']['id'] ?? null, 'id' => $r['id'] ?? null, 'player_url' => $r['player_url'] ?? null, 'aspect' => $r['aspect'] ?? null];
        }

        return ['render_policy' => ContentHtmlPolicy::fromSettings($settings)->toFrontendArray(), 'samples' => $samples];
    }

    /**
     * 고정본이 지금 PHP 출력과 같다 — 다르면 `G7_WRITE_FIXTURE=1` 로 다시 쓴다(JS 시험이 같은 고정본으로 같은 id 를 내는지 본다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=parity_fixture
     *
     * @effects php_js_patterns_identical
     */
    public function test_fixture_matches_php_output(): void
    {
        $built = self::buildFixture();
        $path = base_path(self::FIXTURE);
        if (getenv('G7_WRITE_FIXTURE') === '1') {
            file_put_contents($path, json_encode($built, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->assertSame($built, json_decode((string) file_get_contents($path), true));
    }

    /**
     * 프리셋·직접 추가 주소에서 id·플레이어 주소·비율을 꺼낸다. 남의 호스트·짧은 id·비 http 는 맞지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=resolve
     *
     * @effects presets_resolve_id_and_player, foreign_host_rejected, shorts_vertical
     */
    public function test_resolve_presets_and_custom(): void
    {
        $samples = collect(self::buildFixture()['samples'])->keyBy('url');

        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $samples['https://www.youtube.com/watch?v=dQw4w9WgXcQ']['player_url']);
        $this->assertSame('9:16', $samples['https://m.youtube.com/shorts/abcdefghijk']['aspect']);
        $this->assertSame('https://www.tiktok.com/player/v1/7234567890123456789', $samples['https://www.tiktok.com/@user.name/video/7234567890123456789']['player_url'], 'TikTok 공식 Embed Player 주소');
        // 프리셋은 공식 문서에 iframe 플레이어 주소가 있는 서비스만 둔다 — 종료(네이버TV·카카오TV)·공식 iframe 없음(Instagram)·
        // 계정별 Player ID 필요(Dailymotion)·공식 문서 없음(SOOP)은 빠졌고, 운영자가 「직접 추가」로 넣는다
        $this->assertSame(['youtube', 'vimeo', 'tiktok'], array_keys(EmbedProviderRegistry::presets()));
        foreach (['https://www.dailymotion.com/video/x8abc12', 'https://tv.naver.com/v/12345678', 'https://tv.kakao.com/v/445566', 'https://vod.sooplive.co.kr/player/1234567', 'https://www.instagram.com/p/AbC_dEf-123/'] as $retired) {
            $this->assertNull($samples[$retired]['provider'], $retired.' 은 프리셋으로 해석하지 않는다');
        }
        $this->assertSame('https://chzzk.naver.com/embed/clip/aB3-cd_9', $samples['https://chzzk.naver.com/clips/aB3-cd_9']['player_url']);
        $this->assertNull($samples['https://notyoutube.com/watch?v=dQw4w9WgXcQ']['provider']);
        $this->assertNull($samples['https://www.youtube.com/watch?v=short']['provider']);
        $this->assertNull($samples['javascript:alert(1)']['provider']);
    }

    /**
     * 같은 주소가 여러 서비스에 맞으면 프리셋이 먼저다. 꺼진 프리셋·꺼진 직접 추가는 쓰지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=priority
     *
     * @effects preset_before_custom, disabled_not_used
     */
    public function test_priority_and_toggles(): void
    {
        $settings = ContentHtmlPolicy::defaults();
        $settings['embed_custom_providers'] = [[
            'id' => 'yt_copy', 'name' => '복제', 'enabled' => true, 'url_patterns' => ['https://youtu.be/{id}'],
            'player_url' => 'https://player.example.com/{id}', 'id_rule' => 'alnum_dash',
        ]];
        $resolved = EmbedProviderRegistry::resolve('https://youtu.be/dQw4w9WgXcQ', EmbedProviderRegistry::enabled($settings));
        $this->assertSame('youtube', $resolved['provider']['id']);

        $settings['embed_presets']['youtube'] = false;
        $this->assertSame('yt_copy', EmbedProviderRegistry::resolve('https://youtu.be/dQw4w9WgXcQ', EmbedProviderRegistry::enabled($settings))['provider']['id']);

        $settings['embed_custom_providers'][0]['enabled'] = false;
        $this->assertNull(EmbedProviderRegistry::resolve('https://youtu.be/dQw4w9WgXcQ', EmbedProviderRegistry::enabled($settings)));
    }

    /**
     * 직접 추가 검증 — http 플레이어·{id} 0개/2개·자기 사이트 호스트·호스트 중간 *·프리셋 id 중복·31개째를 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=custom_rule
     *
     * @effects custom_provider_rule_rejections
     */
    public function test_custom_provider_rule(): void
    {
        config(['app.url' => 'https://site.test']);
        $ok = ['id' => 'abc', 'name' => 'A', 'url_patterns' => ['https://*.example.com/v/{id}'], 'player_url' => 'https://example.com/e/{id}'];
        $this->assertSame([], EmbedCustomProviderRule::validateEntry($ok, false));

        $this->assertArrayHasKey('player_url', EmbedCustomProviderRule::validateEntry(['player_url' => 'http://example.com/{id}'] + $ok, false));
        $this->assertArrayHasKey('player_url', EmbedCustomProviderRule::validateEntry(['player_url' => 'https://example.com/x'] + $ok, false));
        $this->assertArrayHasKey('player_url', EmbedCustomProviderRule::validateEntry(['player_url' => 'https://site.test/{id}'] + $ok, false));
        $this->assertArrayHasKey('url_patterns', EmbedCustomProviderRule::validateEntry(['url_patterns' => ['https://a.*.com/{id}']] + $ok, false));
        $this->assertArrayHasKey('url_patterns', EmbedCustomProviderRule::validateEntry(['url_patterns' => ['https://a.com/{id}/{id}']] + $ok, false));

        // 사용 여부는 참/거짓만 — 문자열 "false" 를 받으면 레지스트리(`! empty`)가 켜진 것으로 읽는다
        $this->assertSame([], EmbedCustomProviderRule::validateEntry(['enabled' => true] + $ok, false));
        $this->assertSame([], EmbedCustomProviderRule::validateEntry(['enabled' => false] + $ok, false));
        $this->assertArrayHasKey('enabled', EmbedCustomProviderRule::validateEntry(['enabled' => 'false'] + $ok, false));
        $this->assertArrayHasKey('enabled', EmbedCustomProviderRule::validateEntry(['enabled' => 1] + $ok, false));

        $messages = [];
        (new EmbedCustomProviderRule)->validate('x', [['id' => 'youtube'] + $ok], function ($m) use (&$messages) {
            $messages[] = $m;
        });
        $this->assertNotEmpty($messages, '프리셋 id 와 겹치면 거절');

        $messages = [];
        (new EmbedCustomProviderRule)->validate('x', array_fill(0, 31, $ok), function ($m) use (&$messages) {
            $messages[] = $m;
        });
        $this->assertNotEmpty($messages, '31개째 거절');
    }

    /**
     * 확장 제공 서비스 — 필터 훅으로 들어오고, 운영자가 `ext:{확장}:{id}` 키로 켜야 쓰이며, 검증에 걸리면 버리고, 설정 화면 선택지로 나간다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=extension_providers
     *
     * @effects extension_provider_off_until_toggled, invalid_extension_provider_dropped, custom_before_extension
     */
    public function test_extension_providers_need_operator_toggle(): void
    {
        config(['app.url' => 'https://site.test']);
        $hook = EmbedProviderRegistry::FILTER_HOOK;
        $callback = function (array $providers): array {
            $providers[] = ['extension' => 'vendor-video', 'id' => 'myvideo', 'name' => 'My Video',
                'url_patterns' => ['https://*.myvideo.example/v/{id}'], 'player_url' => 'https://player.myvideo.example/{id}'];
            $providers[] = ['extension' => 'vendor-video', 'id' => 'broken', 'name' => 'Broken',
                'url_patterns' => ['https://broken.example/v/{id}'], 'player_url' => 'http://broken.example/{id}'];

            return $providers;
        };
        HookManager::addFilter($hook, $callback);

        try {
            $options = EmbedProviderRegistry::extensionProviderOptions();
            $this->assertSame([['key' => 'ext:vendor-video:myvideo', 'extension' => 'vendor-video', 'id' => 'myvideo', 'name' => 'My Video']], $options, 'http 플레이어 항목은 버린다');

            $url = 'https://www.myvideo.example/v/abc-123';
            $settings = ContentHtmlPolicy::defaults();
            $this->assertNull(EmbedProviderRegistry::resolve($url, EmbedProviderRegistry::enabled($settings)), '운영자가 켜기 전에는 쓰지 않는다');

            $settings['embed_presets']['ext:vendor-video:myvideo'] = true;
            $resolved = EmbedProviderRegistry::resolve($url, EmbedProviderRegistry::enabled($settings));
            $this->assertSame('abc-123', $resolved['id'] ?? null);
            $this->assertSame('https://player.myvideo.example/abc-123', $resolved['player_url'] ?? null);

            // 우선순위 — 같은 주소에 운영자 직접 추가가 맞으면 확장 제공보다 앞선다(프리셋 → 직접 추가 → 확장)
            $settings['embed_custom_providers'] = [[
                'id' => 'my_own', 'name' => '직접', 'enabled' => true, 'url_patterns' => ['https://*.myvideo.example/v/{id}'],
                'player_url' => 'https://own.example/e/{id}', 'id_rule' => 'alnum_dash',
            ]];
            $this->assertSame('my_own', EmbedProviderRegistry::resolve($url, EmbedProviderRegistry::enabled($settings))['provider']['id'] ?? null, '직접 추가가 확장 제공보다 앞선다');

            $settings['embed_custom_providers'][0]['enabled'] = false;
            $this->assertSame('ext:vendor-video:myvideo', EmbedProviderRegistry::resolve($url, EmbedProviderRegistry::enabled($settings))['provider']['id'] ?? null, '직접 추가를 끄면 확장 제공이 쓰인다');
        } finally {
            HookManager::removeFilter($hook, $callback);
        }
    }
}
