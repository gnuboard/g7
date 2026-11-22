<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Exceptions\TemplateNotFoundException;
use App\Services\LayoutService;
use App\Services\TemplateService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Plugins\Sirsoft\HeroSection\Services\PreviewFrameResolver;
use Plugins\Sirsoft\HeroSection\Tests\Concerns\PinsActiveModules;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;
use RuntimeException;
use Throwable;

/**
 * 관리자 미리보기의 구좌 사슬 — 활성 사용자 템플릿의 실제 레이아웃에서 확장점을 감싼 상자들의 클래스와 반응형 덮어쓰기를 꺼낸다.
 *
 * 미리보기가 기기 viewport(PC 1366·모바일 390)를 그대로 구좌 폭으로 쓰면 실제 사이트(본문 상자 안)와 줄바꿈이 달라진다.
 * 값을 하드코딩하지 않고 템플릿 레이아웃에서 읽는지, 동적 클래스·확장점 밖 노드를 섞지 않는지, 확장점이 없는 위치는 null 인지 잠근다.
 */
class PreviewFrameResolverTest extends PluginTestCase
{
    use PinsActiveModules;

    /**
     * 테스트 준비 — 모듈을 끈 상태(정적 위치 두 개)로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pinActiveModules([]);
    }

    /**
     * 테스트 뒤 목 객체를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * 확장점까지의 조상 상자를 위에서부터 순서대로 내고, 형제·확장점 아래 노드는 넣지 않는다.
     *
     * @scenario surface=preview_frame_chain_from_active_user_template
     *
     * @return void 반환값 없음
     */
    public function test_resolves_ancestor_chain_of_extension_point_from_merged_layout(): void
    {
        $frame = $this->resolver(['home_hero' => ['home'], 'shop_hero' => ['shop/index']], [
            'home' => $this->layout('home_hero'),
            'shop/index' => $this->layout('shop_hero'),
        ])->resolve();

        $this->assertSame('demo-user', $frame['template']);
        $this->assertCount(1, $frame['stylesheets']);
        $this->assertStringContainsString('demo-user', $frame['stylesheets'][0]);
        $this->assertStringContainsString('components.css', $frame['stylesheets'][0]);

        foreach (['home_hero' => 'home', 'shop_hero' => 'shop/index'] as $position => $layout) {
            $this->assertSame($layout, $frame['positions'][$position]['layout']);
            $this->assertSame([
                ['className' => 'min-h-screen flex flex-col', 'responsive' => []],
                ['className' => 'flex-1', 'responsive' => []],
                ['className' => 'min-h-fit px-4', 'responsive' => ['desktop' => 'min-h-fit max-w-7xl mx-auto px-8', '768-1023' => 'px-6']],
            ], $frame['positions'][$position]['chain']);
            // 확장점이 본문 상자를 넘기지 않으면 null — 슬라이더는 자리 폭을 그대로 쓴다
            $this->assertNull($frame['positions'][$position]['content']);
        }
    }

    /**
     * 확장점이 넘긴 본문 상자 클래스(기본·데스크톱)를 구좌 사슬과 같은 모양(`desktop` 반응형 덮어쓰기)으로 낸다 — 바인딩 식은 뺀다.
     * 표시 폭이 본문 폭이면 미리보기가 이 상자로 슬라이더를 감싸고, 화면 전체면 문구 층에 쓴다.
     *
     * @scenario surface=preview_frame_content_box
     *
     * @return void 반환값 없음
     */
    public function test_carries_content_box_props_of_extension_point(): void
    {
        $home = $this->layout('home_hero');
        $home['components'][0]['children'][1]['children'][0]['children'][0]['props'] = ['contentClass' => 'px-4', 'contentClassDesktop' => "max-w-7xl mx-auto px-8 {{_global.x ? 'a' : 'b'}}"];
        $shop = $this->layout('shop_hero');
        $shop['components'][0]['children'][1]['children'][0]['children'][0]['props'] = ['contentClass' => 'px-4'];

        $frame = $this->resolver(['home_hero' => ['home'], 'shop_hero' => ['shop/index']], ['home' => $home, 'shop/index' => $shop])->resolve();

        $this->assertSame(['className' => 'px-4', 'responsive' => ['desktop' => 'max-w-7xl mx-auto px-8']], $frame['positions']['home_hero']['content']);
        $this->assertSame(['className' => 'px-4', 'responsive' => []], $frame['positions']['shop_hero']['content']);
    }

    /**
     * 구간 classMerge(코어 7.2.1)는 엔진과 같은 규칙으로 기본(또는 구간) 클래스에 합친 실효 클래스로 낸다 — 화면은 구간 값을
     * 그대로 고르므로 미리보기 폭과 실화면 폭이 같아진다. 종전 통째 교체 구간은 그대로다.
     *
     * @scenario surface=preview_frame_chain_from_active_user_template
     *
     * @return void 반환값 없음
     */
    public function test_merges_breakpoint_class_merge_into_effective_class(): void
    {
        $layout = $this->layout('home_hero');
        $layout['components'][0]['children'][1]['children'][0]['responsive'] = [
            'desktop' => ['classMerge' => 'max-w-7xl mx-auto px-8'],
            '768-1023' => ['props' => ['className' => 'min-h-fit px-4 gap-2'], 'classMerge' => "px-6 {{_global.x ? 'a' : 'b'}}"],
            'mobile' => ['props' => ['gap' => 2]],
        ];

        $frame = $this->resolver(['home_hero' => ['home'], 'shop_hero' => []], ['home' => $layout])->resolve();

        $this->assertSame(
            ['desktop' => 'min-h-fit max-w-7xl mx-auto px-8', '768-1023' => 'min-h-fit gap-2 px-6'],
            $frame['positions']['home_hero']['chain'][2]['responsive'],
        );
    }

    /**
     * 바인딩 식이 든 클래스 조각은 서버가 풀 수 없으므로 빼고 정적 클래스만 남긴다.
     *
     * @scenario surface=preview_frame_chain_from_active_user_template
     *
     * @return void 반환값 없음
     */
    public function test_drops_bound_class_tokens(): void
    {
        $layout = $this->layout('home_hero');
        $layout['components'][0]['props']['className'] = "min-h-screen {{_global.dark ? 'bg-black' : 'bg-white'}} flex";

        $frame = $this->resolver(['home_hero' => ['home'], 'shop_hero' => []], ['home' => $layout])->resolve();

        $this->assertSame('min-h-screen flex', $frame['positions']['home_hero']['chain'][0]['className']);
    }

    /**
     * 템플릿에 그 확장점이 없으면 null — 미리보기는 기기 폭 전체를 구좌로 쓴다(종전 동작).
     *
     * @scenario surface=preview_frame_missing_slot_falls_back
     *
     * @return void 반환값 없음
     */
    public function test_position_without_extension_point_is_null(): void
    {
        $frame = $this->resolver(['home_hero' => ['home'], 'shop_hero' => []], ['home' => $this->layout('home_hero')])->resolve();

        $this->assertNull($frame['positions']['shop_hero']);
        $this->assertNotNull($frame['positions']['home_hero']);
    }

    /**
     * 위치마다 그 자리가 있는 레이아웃의 사이트 주소를 함께 낸다 — 목록의 「사이트에서 보기」가 쓴다. 매개변수(`:slug`)·와일드카드가
     * 든 주소나 라우트가 없는 레이아웃은 한 주소로 정할 수 없으므로 null 이다.
     *
     * @scenario surface=preview_frame_site_path
     *
     * @return void 반환값 없음
     */
    public function test_position_carries_site_path_of_its_layout(): void
    {
        $merged = ['home' => $this->layout('home_hero'), 'shop/index' => $this->layout('shop_hero')];

        $frame = $this->resolver(['home_hero' => ['home'], 'shop_hero' => ['shop/index']], $merged, ['home' => '/', 'shop/index' => '/store/products'])->resolve();
        $this->assertSame('/', $frame['positions']['home_hero']['path']);
        $this->assertSame('/store/products', $frame['positions']['shop_hero']['path']);

        $frame = $this->resolver(['home_hero' => ['home'], 'shop_hero' => ['shop/index']], $merged, ['shop/index' => '/category/:slug'])->resolve();
        $this->assertNull($frame['positions']['home_hero']['path']);
        $this->assertNull($frame['positions']['shop_hero']['path']);
    }

    /**
     * 라우트 목록을 읽지 못해도 미리보기 틀은 그대로 내고 주소만 비운다 — 원인은 오류 로그로 남긴다.
     *
     * @scenario surface=preview_frame_site_path
     *
     * @return void 반환값 없음
     */
    public function test_route_map_failure_keeps_frame_and_logs_error(): void
    {
        Log::spy();
        $resolver = $this->resolver(['home_hero' => ['home'], 'shop_hero' => []], ['home' => $this->layout('home_hero')], new RuntimeException('routes.json broken'));

        $frame = $resolver->resolve();

        $this->assertSame('home', $frame['positions']['home_hero']['layout']);
        $this->assertNull($frame['positions']['home_hero']['path']);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['template'] === 'demo-user'
            && $context['error'] === 'routes.json broken');
    }

    /**
     * 확장점을 가진 레이아웃이 깨져 병합에 실패하면 그 레이아웃만 건너뛰되, 원인을 찾을 수 있게 오류 로그를 남긴다.
     *
     * 출하 기본 로그 수준이 error 라 warning 은 기본 설치에서 기록되지 않는다. 틀 결과가 캐시되므로 같은 레이아웃은 캐시 주기마다 한 번만 남는다.
     *
     * @scenario surface=preview_frame_layout_error_logged
     *
     * @return void 반환값 없음
     */
    public function test_logs_error_when_layout_fails_to_merge(): void
    {
        Log::spy();
        $templates = Mockery::mock(TemplateService::class);
        $templates->shouldReceive('getActiveTemplateIdentifier')->with('user')->andReturn('demo-user');
        $templates->shouldReceive('findByIdentifier')->with('demo-user')->andReturn((object) ['id' => 7, 'identifier' => 'demo-user']);
        $templates->shouldReceive('getLayoutRoutePathMap')->with('demo-user')->andReturn([]);
        $layouts = Mockery::mock(LayoutRepositoryInterface::class);
        $layouts->shouldReceive('findLayoutNamesWithExtensionPoint')->with(7, 'home_hero')->andReturn(['broken', 'home']);
        $layouts->shouldReceive('findLayoutNamesWithExtensionPoint')->with(7, 'shop_hero')->andReturn([]);
        $service = Mockery::mock(LayoutService::class);
        $service->shouldReceive('getLayout')->with('demo-user', 'broken')->andThrow(new RuntimeException('parent layout missing'));
        $service->shouldReceive('getLayout')->with('demo-user', 'home')->andReturn($this->layout('home_hero'));

        $frame = (new PreviewFrameResolver($templates, $layouts, $service, $this->passThroughCache()))->resolve();

        $this->assertSame('home', $frame['positions']['home_hero']['layout']);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['layout'] === 'broken'
            && $context['template'] === 'demo-user'
            && $context['extension_point'] === 'home_hero'
            && $context['error'] === 'parent layout missing');
    }

    /**
     * 활성 사용자 템플릿이 없으면 템플릿·CSS 없이 모든 위치가 null 이다.
     *
     * @scenario surface=preview_frame_missing_slot_falls_back
     *
     * @return void 반환값 없음
     */
    public function test_without_active_user_template_returns_empty_frame(): void
    {
        $templates = Mockery::mock(TemplateService::class);
        $templates->shouldReceive('getActiveTemplateIdentifier')->with('user')->andThrow(new TemplateNotFoundException('user'));

        $frame = (new PreviewFrameResolver($templates, Mockery::mock(LayoutRepositoryInterface::class), Mockery::mock(LayoutService::class), $this->passThroughCache()))->resolve();

        $this->assertNull($frame['template']);
        $this->assertSame([], $frame['stylesheets']);
        $this->assertSame(['home_hero' => null, 'shop_hero' => null], $frame['positions']);
    }

    /**
     * 실측과 같은 꼴의 병합 레이아웃 — 기본 틀 두 겹 + 본문 상자 안의 확장점, 형제 노드 포함.
     *
     * @param  string  $point  확장점 이름
     * @return array<string, mixed> 병합 레이아웃
     */
    private function layout(string $point): array
    {
        return ['components' => [[
            'id' => 'root', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'min-h-screen flex flex-col'],
            'children' => [
                ['id' => 'header', 'type' => 'composite', 'name' => 'Header', 'props' => ['className' => 'sticky top-0']],
                ['id' => 'area', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'flex-1'], 'children' => [[
                    'id' => 'main', 'type' => 'layout', 'name' => 'Container', 'props' => ['className' => 'min-h-fit px-4'],
                    'responsive' => ['desktop' => ['props' => ['className' => 'min-h-fit max-w-7xl mx-auto px-8']], '768-1023' => ['props' => ['className' => 'px-6']], 'mobile' => ['props' => ['gap' => 2]]],
                    'children' => [
                        ['id' => 'slot', 'type' => 'extension_point', 'name' => $point, 'default' => [['id' => 'inner', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'nope']]]],
                        ['id' => 'after', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'py-6']],
                    ],
                ]]],
            ],
        ]]];
    }

    /**
     * 목 협력자로 해석기를 만듭니다.
     *
     * @param  array<string, array<int, string>>  $layoutsByPoint  확장점 → 그 확장점을 가진 레이아웃 이름
     * @param  array<string, array<string, mixed>>  $merged  레이아웃 이름 → 병합 레이아웃
     * @param  array<string, string>|Throwable  $routePaths  레이아웃 이름 → 라우트 주소 (예외면 읽기 실패)
     * @return PreviewFrameResolver 해석기
     */
    private function resolver(array $layoutsByPoint, array $merged, array|Throwable $routePaths = []): PreviewFrameResolver
    {
        $templates = Mockery::mock(TemplateService::class);
        $templates->shouldReceive('getActiveTemplateIdentifier')->with('user')->andReturn('demo-user');
        $templates->shouldReceive('findByIdentifier')->with('demo-user')->andReturn((object) ['id' => 7, 'identifier' => 'demo-user']);
        $routeMap = $templates->shouldReceive('getLayoutRoutePathMap')->with('demo-user');
        $routePaths instanceof Throwable ? $routeMap->andThrow($routePaths) : $routeMap->andReturn($routePaths);

        $layouts = Mockery::mock(LayoutRepositoryInterface::class);
        foreach ($layoutsByPoint as $point => $names) {
            $layouts->shouldReceive('findLayoutNamesWithExtensionPoint')->with(7, $point)->andReturn($names);
        }

        $service = Mockery::mock(LayoutService::class);
        foreach ($merged as $name => $layout) {
            $service->shouldReceive('getLayout')->with('demo-user', $name)->andReturn($layout);
        }

        return new PreviewFrameResolver($templates, $layouts, $service, $this->passThroughCache());
    }

    /**
     * 콜백을 그대로 실행하는 캐시 목.
     *
     * @return CacheInterface 캐시
     */
    private function passThroughCache(): CacheInterface
    {
        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn (string $key, callable $callback) => $callback());

        return $cache;
    }
}
