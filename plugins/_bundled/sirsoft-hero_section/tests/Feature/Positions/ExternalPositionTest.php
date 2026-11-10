<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Positions;

use App\Extension\HookManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use App\Services\LayoutService;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\HeroSection\Listeners\PositionOutputListener;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Support\Positions\PositionDefinition;
use Plugins\Sirsoft\HeroSection\Support\Positions\PositionFragment;
use Plugins\Sirsoft\HeroSection\Tests\Concerns\PinsActiveModules;
use Plugins\Sirsoft\HeroSection\Tests\Fixtures\FakeTargetResolver;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 다른 확장이 더한 위치 — 위치 등록 필터로 위치를 더하고 템플릿에 같은 이름의 확장점을 두면, 히어로 소스를 고치거나 조각을
 * 복사하지 않아도 관리·검증·공개 API·사이트 출력이 내장 위치와 같은 처리로 이어진다(매니페스트 position-registry.yaml).
 *
 * 사이트 출력은 실제 레이아웃 병합(`LayoutService` → `core.layout_extension.after_apply`)으로 본다.
 */
class ExternalPositionTest extends PluginTestCase
{
    use PinsActiveModules;

    /**
     * 시험 관리자
     */
    private User $admin;

    /**
     * 등록한 위치 필터 콜백 (거둘 때 그것만 뺀다)
     *
     * @var callable|null
     */
    private $filter = null;

    /**
     * 모듈을 끈 상태로 고정합니다 — 플러그인 리스너는 기반 클래스가 실제 등록 경로로 한 번 붙인다(여기서 다시 붙이면 출력 리스너가
     * 두 번 돌아 한 번 실행의 누락을 가린다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pinActiveModules([]);
        $this->admin = $this->fullAdmin();
    }

    /**
     * 레이아웃 서빙 필터에 붙은 출력 리스너 수를 셉니다.
     *
     * @return int 등록 수
     */
    private function outputListenerCount(): int
    {
        $count = 0;
        foreach (HookManager::getFilters()['core.layout_extension.after_apply'] ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $vars = $callback instanceof \Closure ? (new \ReflectionFunction($callback))->getStaticVariables() : [];
                $count += ($vars['listenerClass'] ?? null) === PositionOutputListener::class ? 1 : 0;
            }
        }

        return $count;
    }

    /**
     * 위치 필터로 위치를 더합니다(다른 확장이 하는 일과 같다).
     *
     * @param  array<int, mixed>  $definitions  위치 정의
     * @return void 반환값 없음
     */
    private function addPositions(array $definitions): void
    {
        $this->filter = fn (array $positions) => [...$positions, ...$definitions];
        HookManager::addFilter('sirsoft-hero_section.position.registered_positions', $this->filter);
        $this->app->forgetInstance(PositionRegistry::class);
    }

    /**
     * 더한 위치를 거둡니다(위치를 더한 확장을 끈 상황).
     *
     * @return void 반환값 없음
     */
    private function removePositions(): void
    {
        HookManager::removeFilter('sirsoft-hero_section.position.registered_positions', $this->filter);
        $this->app->forgetInstance(PositionRegistry::class);
    }

    /**
     * 활성 사용자 템플릿과 확장점을 둔 레이아웃을 만들고 병합 결과를 돌려줍니다.
     *
     * @param  array<int, array<string, mixed>>  $slot  확장점 노드 (여러 개 가능)
     * @return array<string, mixed> 병합된 레이아웃
     */
    private function mergedLayoutWith(array $slot): array
    {
        $template = Template::factory()->create(['identifier' => 'test-hero-ext-user', 'type' => 'user', 'status' => 'active']);
        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'acme_event',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'acme_event',
                'components' => [['id' => 'page', 'type' => 'basic', 'name' => 'Div', 'children' => $slot]],
                'data_sources' => [],
                'metadata' => [],
            ],
        ]);

        // 확장 적용(확장점 채우기·after_apply)은 서빙 경로 getLayout 이 한다
        return app(LayoutService::class)->getLayout('test-hero-ext-user', 'acme_event');
    }

    /**
     * 병합 결과에서 이름으로 확장점 노드를 찾습니다.
     *
     * @param  array<int, mixed>  $nodes  노드 목록
     * @param  string  $name  확장점 이름
     * @return array<string, mixed>|null 확장점 노드
     */
    private function slot(array $nodes, string $name): ?array
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === 'extension_point' && ($node['name'] ?? null) === $name) {
                return $node;
            }
            $found = $this->slot($node['children'] ?? [], $name);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 출력 조각은 내장 조각 파일이 원본이다 — 내장 위치 키·id 로 만들면 내장 조각과 한 글자도 다르지 않다(같은 처리를 쓴다는 근거).
     *
     * @scenario case=external_output_shares_builtin_fragment
     *
     * @return void 반환값 없음
     */
    public function test_fragment_builder_reproduces_builtin_fragments(): void
    {
        $home = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/resources/extensions/home_hero.json'), true);
        $built = PositionFragment::for(new PositionDefinition('home_hero', 'x'), 'heroSectionHome');
        $this->assertSame($home['components'], $built['components']);
        $this->assertSame($home['data_sources'], $built['data_sources']);

        $board = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/resources/extensions/board_hero.json'), true);
        $built = PositionFragment::for(new PositionDefinition('board_hero', 'x', targetType: 'board', targetLabel: 'x', resolver: FakeTargetResolver::class, routeParam: 'slug'), 'heroSectionBoard');
        $this->assertSame($board['components'], $built['components']);
        $this->assertSame($board['data_sources'], $built['data_sources']);

        // 동적 위치인데 대상 값 경로가 없으면 만들지 않는다
        $this->assertNull(PositionFragment::for(new PositionDefinition('x_hero', 'x', targetType: 'x', targetLabel: 'x', resolver: FakeTargetResolver::class)));
    }

    /**
     * 화면 렌더 시험(Vitest)이 쓰는 생성 조각 표본은 지금 빌더 출력과 같다 — 다르면 표본을 다시 만든다.
     *
     * @scenario case=external_output_renders
     *
     * @return void 반환값 없음
     */
    public function test_rendering_fixtures_match_generated_fragments(): void
    {
        $fixtures = dirname(__DIR__, 3).'/resources/js/__tests__/fixtures/';
        $static = PositionFragment::for(new PositionDefinition('acme_event_hero', ['ko' => '이벤트']));
        $dynamic = PositionFragment::for(new PositionDefinition('acme_event_page_hero', ['ko' => '이벤트 페이지'], targetType: 'event', targetLabel: ['ko' => '이벤트'], resolver: FakeTargetResolver::class, routeParam: 'code'));

        $this->assertSame($static, json_decode((string) file_get_contents($fixtures.'generated_acme_event_hero.json'), true), '표본이 낡았다 — PositionFragment 출력으로 다시 만든다');
        $this->assertSame($dynamic, json_decode((string) file_get_contents($fixtures.'generated_acme_event_page_hero.json'), true), '표본이 낡았다 — PositionFragment 출력으로 다시 만든다');
    }

    /**
     * 외부 정적 위치 — 필터로 더하면 관리자 위치 목록·등록 검증·목록 통계·순서·위치 설정·공개 API 가 내장 위치처럼 받는다.
     *
     * @scenario case=external_static_position_managed
     *
     * @return void 반환값 없음
     */
    public function test_external_static_position_is_managed_like_builtin(): void
    {
        $this->addPositions([['key' => 'acme_event_hero', 'label' => ['ko' => '이벤트 메인', 'en' => 'Event main'], 'order' => 50]]);

        $positions = collect($this->actingAs($this->admin)->getJson($this->api('/admin/position-settings'))->assertOk()->json('data.positions'))->keyBy('key');
        $this->assertSame(['home_hero', 'shop_hero', 'acme_event_hero'], $positions->keys()->all());
        $this->assertContains($positions['acme_event_hero']['label'], ['이벤트 메인', 'Event main']);
        $this->assertTrue($positions['acme_event_hero']['registered']);
        $this->assertTrue($positions['acme_event_hero']['auto_output']);

        $first = $this->createBannerViaApi($this->admin, ['position' => 'acme_event_hero', 'title' => ['ko' => '이벤트 배너']])->assertCreated()->json('data');
        $second = $this->createBannerViaApi($this->admin, ['position' => 'acme_event_hero'])->assertCreated()->json('data');
        $this->createBannerViaApi($this->admin, ['position' => 'acme_event_hero', 'target_key' => '1'])->assertUnprocessable()->assertJsonValidationErrors('target_key');

        $list = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=acme_event_hero'))->assertOk()->json('data');
        $this->assertSame(2, $list['statistics']['positions']['acme_event_hero']);
        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/order'), ['position' => 'acme_event_hero', 'ids' => [$second['id'], $first['id']]])->assertOk();
        $this->actingAs($this->admin)->putJson($this->api('/admin/position-settings/acme_event_hero'), ['autoplay' => false, 'interval_seconds' => 7, 'effect' => 'fade'])->assertOk();

        $hero = $this->getJson($this->api('/hero?position=acme_event_hero'))->assertOk()->json('data');
        $this->assertSame([$second['id'], $first['id']], array_column($hero['banners'], 'id'));
        $this->assertSame(7, $hero['settings']['interval_seconds']);
        // 다른 위치와 섞이지 않는다
        $this->assertSame([], $this->getJson($this->api('/hero?position=home_hero'))->assertOk()->json('data.banners'));
    }

    /**
     * 템플릿이 위치 키와 같은 확장점을 두면 레이아웃 병합이 그 자리에 출력 조각을 붙인다 — 슬라이더·공개 API 데이터 소스·
     * 확장점 props(본문 상자)가 내장 위치와 같은 모양이고, 동적 위치는 등록한 경로 매개변수로 대상 값을 읽는다.
     *
     * @scenario case=external_output_renders
     *
     * @return void 반환값 없음
     */
    public function test_layout_serving_fills_external_slots(): void
    {
        $this->addPositions([
            ['key' => 'acme_event_hero', 'label' => ['ko' => '이벤트 메인']],
            ['key' => 'acme_event_page_hero', 'label' => ['ko' => '이벤트 페이지'], 'target' => ['type' => 'event', 'label' => ['ko' => '이벤트'], 'resolver' => FakeTargetResolver::class, 'route_param' => 'code']],
        ]);

        $layout = $this->mergedLayoutWith([
            ['type' => 'extension_point', 'name' => 'acme_event_hero', 'props' => ['contentClass' => 'max-w-7xl mx-auto']],
            ['type' => 'extension_point', 'name' => 'acme_event_page_hero'],
        ]);

        $static = $this->slot($layout['components'], 'acme_event_hero');
        $this->assertSame('sirsoft-hero_section.HeroSlider', $static['children'][0]['name']);
        $this->assertSame('acme_event_hero', $static['children'][0]['props']['position']);
        $this->assertSame(['contentClass' => 'max-w-7xl mx-auto'], $static['children'][0]['extensionPointProps']);

        $dynamic = $this->slot($layout['components'], 'acme_event_page_hero');
        $this->assertSame('{{String(route?.code ?? \'\')}}', $dynamic['children'][0]['props']['target']);

        $sources = collect($layout['data_sources'])->keyBy('id');
        $this->assertSame(['position' => 'acme_event_hero'], $sources['heroSection_acme_event_hero']['params']);
        $this->assertSame(['position' => 'acme_event_page_hero', 'target' => '{{route?.code}}'], $sources['heroSection_acme_event_page_hero']['params']);
        $this->assertSame('optional', $sources['heroSection_acme_event_hero']['auth_mode']);
    }

    /**
     * 채우지 않는 경우 — 이미 히어로 슬라이더가 든 자리(내장·자기 조각), 편집기 모드 노드, 등록되지 않은 위치, 경로 매개변수 없는
     * 동적 위치. 어느 쪽이든 레이아웃은 그대로 나온다.
     *
     * @scenario case=external_output_skips
     *
     * @return void 반환값 없음
     */
    public function test_output_listener_leaves_filled_edit_and_unknown_slots(): void
    {
        $this->addPositions([
            ['key' => 'acme_event_hero', 'label' => ['ko' => '이벤트 메인']],
            ['key' => 'acme_page_hero', 'label' => ['ko' => '페이지'], 'target' => ['type' => 'page', 'label' => ['ko' => '페이지'], 'resolver' => FakeTargetResolver::class]],
        ]);
        $own = ['name' => 'sirsoft-hero_section.HeroSlider', 'type' => 'composite', 'props' => ['position' => 'acme_event_hero']];
        $layout = ['layout_name' => 'x', 'data_sources' => [], 'components' => [
            ['type' => 'extension_point', 'name' => 'acme_event_hero', 'children' => [['name' => 'Div', 'children' => [$own]]]],
            ['type' => 'extension_point', 'name' => 'acme_event_hero', '__source' => ['kind' => 'layout']],
            ['type' => 'extension_point', 'name' => 'nobody_hero'],
            ['type' => 'extension_point', 'name' => 'acme_page_hero'],
        ]];

        $this->assertSame($layout, (new PositionOutputListener)->fill($layout, 1));
        $this->assertFalse(collect($this->actingAs($this->admin)->getJson($this->api('/admin/position-settings'))->json('data.positions'))->firstWhere('key', 'acme_page_hero')['auto_output']);
    }

    /**
     * 위치를 더한 확장을 끄면 위치가 빠져 자리를 채우지 않고 배너는 남는다(관리자에게 「등록되지 않은 위치」). 다시 켜면 같은 배너가 그대로 나온다.
     * 잘못된 정의(형식·중복 키·경로 매개변수)는 버려지고 나머지 위치와 화면은 그대로다.
     *
     * @scenario case=external_position_lifecycle
     *
     * @return void 반환값 없음
     */
    public function test_disable_keeps_banners_and_bad_definitions_do_not_break(): void
    {
        $definition = ['key' => 'acme_event_hero', 'label' => ['ko' => '이벤트 메인']];
        $this->addPositions([$definition]);
        $banner = $this->createBannerViaApi($this->admin, ['position' => 'acme_event_hero'])->assertCreated()->json('data');

        $this->removePositions();
        $this->assertTrue(Banner::query()->whereKey($banner['id'])->exists());
        $gone = collect($this->actingAs($this->admin)->getJson($this->api('/admin/position-settings'))->json('data.positions'))->firstWhere('key', 'acme_event_hero');
        $this->assertFalse($gone['registered']);
        $this->getJson($this->api('/hero?position=acme_event_hero'))->assertUnprocessable();
        $layout = ['layout_name' => 'x', 'data_sources' => [], 'components' => [['type' => 'extension_point', 'name' => 'acme_event_hero']]];
        $this->assertSame($layout, (new PositionOutputListener)->fill($layout, 1));

        Log::spy();
        $this->addPositions([
            $definition,
            ['key' => 'acme_event_hero', 'label' => ['ko' => '가로채기']],
            ['key' => 'Acme-Bad', 'label' => ['ko' => '잘못']],
            ['key' => 'acme_bad_route_hero', 'label' => ['ko' => '잘못'], 'target' => ['type' => 'x', 'label' => ['ko' => 'x'], 'resolver' => FakeTargetResolver::class, 'route_param' => 'not-a-param']],
        ]);
        $this->assertSame(['home_hero', 'shop_hero', 'acme_event_hero'], app(PositionRegistry::class)->keys());
        foreach (['이미 등록된 위치 키', '위치 키 형식', '대상 경로 매개변수'] as $reason) {
            Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, $reason));
        }
        $this->assertSame([$banner['id']], array_column($this->getJson($this->api('/hero?position=acme_event_hero'))->assertOk()->json('data.banners'), 'id'));
    }

    /**
     * 출력 리스너는 운영과 같이 한 번만 붙는다(두 번 붙으면 두 번째 실행이 첫 실행의 누락을 메워 시험이 실제보다 좋게 나온다).
     *
     * @scenario case=external_output_skips
     *
     * @return void 반환값 없음
     */
    public function test_output_listener_is_registered_once(): void
    {
        $this->assertSame(1, $this->outputListenerCount());
    }

    /**
     * 자리 안에 다른 위치 자리가 있어도 두 자리 모두 한 번씩 채운다. 안쪽 자리에 그 위치의 슬라이더가 이미 있어도 바깥 자리는 채운다
     * — 다른 확장점 안의 슬라이더는 바깥 자리의 출력이 아니다.
     *
     * @scenario case=external_output_renders
     *
     * @return void 반환값 없음
     */
    public function test_nested_slots_are_each_filled(): void
    {
        $this->addPositions([
            ['key' => 'acme_outer_hero', 'label' => ['ko' => '바깥']],
            ['key' => 'acme_inner_hero', 'label' => ['ko' => '안쪽']],
        ]);
        $own = ['name' => 'sirsoft-hero_section.HeroSlider', 'type' => 'composite', 'props' => ['position' => 'acme_inner_hero']];

        $layout = $this->mergedLayoutWith([
            ['type' => 'extension_point', 'name' => 'acme_outer_hero', 'default' => [['type' => 'extension_point', 'name' => 'acme_inner_hero']]],
        ]);
        $outer = $this->slot($layout['components'], 'acme_outer_hero');
        $inner = $this->slot($outer['children'], 'acme_inner_hero');
        $this->assertSame(['acme_outer_hero'], $this->sliderPositions($outer['children'], false));
        $this->assertSame(['acme_inner_hero'], $this->sliderPositions($inner['children'], false));
        $this->assertSame(['heroSection_acme_outer_hero', 'heroSection_acme_inner_hero'], array_values(array_filter(array_column($layout['data_sources'], 'id'), fn ($id) => str_starts_with($id, 'heroSection_'))));

        $listener = new PositionOutputListener;
        $owned = $listener->fill(['layout_name' => 'x', 'data_sources' => [], 'components' => [
            ['type' => 'extension_point', 'name' => 'acme_outer_hero', 'children' => [['type' => 'extension_point', 'name' => 'acme_inner_hero', 'children' => [$own]]]],
        ]], 1);
        $this->assertSame(['acme_outer_hero'], $this->sliderPositions($owned['components'][0]['children'], false));
        $this->assertSame([$own], $owned['components'][0]['children'][0]['children']);
        $this->assertSame(['heroSection_acme_outer_hero'], array_column($owned['data_sources'], 'id'));
    }

    /**
     * 코어가 확장점을 처리하는 반복 템플릿 자리(표·카드 셀, 펼침 행, 하위 행)에 둔 위치 자리도 채운다.
     *
     * @scenario case=external_output_renders
     *
     * @return void 반환값 없음
     */
    public function test_slots_inside_repeat_templates_are_filled(): void
    {
        $this->addPositions([['key' => 'acme_row_hero', 'label' => ['ko' => '행']]]);
        $slot = ['type' => 'extension_point', 'name' => 'acme_row_hero'];

        $layout = $this->mergedLayoutWith([[
            'id' => 'grid', 'type' => 'composite', 'name' => 'DataGrid',
            'props' => [
                'columns' => [['field' => 'a', 'cellChildren' => [$slot]]],
                'cardColumns' => [['field' => 'a', 'cellChildren' => [$slot]]],
                'expandChildren' => [$slot],
                'subRowChildren' => [$slot],
            ],
        ]]);
        $props = $layout['components'][0]['children'][0]['props'];

        foreach ([$props['columns'][0]['cellChildren'][0], $props['cardColumns'][0]['cellChildren'][0], $props['expandChildren'][0], $props['subRowChildren'][0]] as $filled) {
            $this->assertSame(['acme_row_hero'], $this->sliderPositions($filled['children'] ?? [], false));
        }
        $this->assertSame(['heroSection_acme_row_hero'], array_values(array_filter(array_column($layout['data_sources'], 'id'), fn ($id) => str_starts_with($id, 'heroSection_'))));
    }

    /**
     * 자리에 이미 둔 슬라이더는 위치를 바인딩 식으로 썼거나 반복 템플릿 셀 안에 있어도 알아보고, 자동 슬라이더를 더하지 않는다.
     *
     * @scenario case=external_output_skips
     *
     * @return void 반환값 없음
     */
    public function test_existing_slider_bound_or_in_template_is_recognized(): void
    {
        $this->addPositions([['key' => 'acme_output_hero', 'label' => ['ko' => '출력']]]);
        $bound = ['name' => 'sirsoft-hero_section.HeroSlider', 'type' => 'composite', 'props' => ['position' => '{{"acme_output_hero"}}']];
        $inCell = ['id' => 'grid', 'type' => 'composite', 'name' => 'DataGrid', 'props' => ['columns' => [['field' => 'a', 'cellChildren' => [
            ['name' => 'sirsoft-hero_section.HeroSlider', 'type' => 'composite', 'props' => ['position' => 'acme_output_hero']],
        ]]]]];
        $listener = new PositionOutputListener;

        foreach ([$bound, $inCell] as $manual) {
            $layout = ['layout_name' => 'x', 'data_sources' => [], 'components' => [
                ['type' => 'extension_point', 'name' => 'acme_output_hero', 'children' => [$manual]],
            ]];
            $this->assertSame($layout, $listener->fill($layout, 1));
        }
    }

    /**
     * 레이아웃에 같은 id 의 다른 데이터 소스가 있으면 그 자리는 채우지 않고 경고를 남긴다 — 자동 슬라이더가 다른 API 응답을 읽지 않게.
     *
     * @scenario case=external_output_skips
     *
     * @return void 반환값 없음
     */
    public function test_data_source_id_collision_skips_slot(): void
    {
        $this->addPositions([['key' => 'acme_output_hero', 'label' => ['ko' => '출력']]]);
        Log::spy();
        $foreign = ['id' => 'heroSection_acme_output_hero', 'type' => 'api', 'endpoint' => '/api/acme-events/other', 'method' => 'GET'];
        $layout = ['layout_name' => 'x', 'data_sources' => [$foreign], 'components' => [
            ['type' => 'extension_point', 'name' => 'acme_output_hero'],
        ]];

        $this->assertSame($layout, (new PositionOutputListener)->fill($layout, 1));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, '데이터 소스'));

        // 같은 위치 자리가 둘이면 데이터 소스는 하나만 붙이고 두 자리를 모두 채운다
        $twice = (new PositionOutputListener)->fill(['layout_name' => 'x', 'data_sources' => [], 'components' => [
            ['type' => 'extension_point', 'name' => 'acme_output_hero'],
            ['type' => 'extension_point', 'name' => 'acme_output_hero'],
        ]], 1);
        $this->assertSame(['heroSection_acme_output_hero'], array_column($twice['data_sources'], 'id'));
        $this->assertSame(['acme_output_hero', 'acme_output_hero'], $this->sliderPositions($twice['components']));
    }

    /**
     * 노드 목록 안 히어로 슬라이더의 위치 키를 모읍니다.
     *
     * @param  array<int, mixed>  $nodes  노드 목록
     * @param  bool  $intoSlots  안쪽 확장점까지 내려갈지
     * @return array<int, string> 위치 키
     */
    private function sliderPositions(array $nodes, bool $intoSlots = true): array
    {
        $found = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['name'] ?? null) === 'sirsoft-hero_section.HeroSlider') {
                $found[] = $node['props']['position'] ?? '';
            }
            if (($node['type'] ?? null) !== 'extension_point' || $intoSlots) {
                $found = [...$found, ...$this->sliderPositions($node['children'] ?? [], $intoSlots)];
            }
        }

        return $found;
    }
}
