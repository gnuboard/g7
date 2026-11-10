<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Public;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Services\PositionSettingService;
use Plugins\Sirsoft\HeroSection\Services\PublicHeroService;
use Plugins\Sirsoft\HeroSection\Tests\Concerns\RegistersTestPosition;
use Plugins\Sirsoft\HeroSection\Tests\Fixtures\FakeTargetResolver;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 공개 API 의 동적 위치 — 화면 대상 값(slug·키)으로 그 대상의 배너만 낸다(매니페스트 position-registry.yaml).
 *
 * 대상에 배너가 없거나 대상을 풀지 못하면 빈 목록이다(D3 — 사이트를 깨지 않는다). 등록되지 않은 위치는 422, 그 배너는 사이트에 나가지 않는다(D7).
 * 캐시는 위치 + 대상 단위다 — 한 대상의 배너를 고치면 그 대상만, 위치 슬라이더 설정을 고치면 그 위치의 모든 대상이 무효가 된다.
 */
class HeroTargetTest extends PluginTestCase
{
    use RegistersTestPosition;

    /**
     * 테스트 정리 — 목 객체를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 테스트 준비 — 캐시를 끄고 시험용 동적 위치를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->setPluginSettings(['public_cache_minutes' => 0]);
        $this->registerTestPosition();
    }

    /**
     * 화면 값(slug 또는 키)으로 그 대상의 배너만 나오고 다른 대상 배너는 섞이지 않는다. 응답에 푼 대상 키가 실린다.
     *
     * @scenario case=public_exposes_target_banners_only
     *
     * @return void 반환값 없음
     */
    public function test_exposes_only_the_target_banners(): void
    {
        $alpha = Banner::factory()->withImages()->forPosition('fake_hero', '1')->create(['title' => ['ko' => '알파 배너']]);
        Banner::factory()->withImages()->forPosition('fake_hero', '2')->create(['title' => ['ko' => '베타 배너']]);
        Banner::factory()->withImages()->forPosition('home_hero')->create(['title' => ['ko' => '홈 배너']]);

        $bySlug = $this->hero('fake_hero', 'alpha')->assertOk()->json('data');
        $this->assertSame([$alpha->id], array_column($bySlug['banners'], 'id'));
        $this->assertSame('1', $bySlug['target_key']);
        $this->assertSame('alpha', $bySlug['target'], '받은 화면 값을 되돌려 준다 — 화면이 SPA 이동 뒤 도착한 앞 대상 응답을 가려낸다');
        $this->assertSame('alpha', $this->hero('fake_hero', ' alpha ')->json('data.target'), '되돌려 주는 값은 해석에 쓴 값(앞뒤 공백을 걷은 값)이다');
        $this->assertNull($this->hero('home_hero')->json('data.target'));
        $this->assertSame([$alpha->id], array_column($this->hero('fake_hero', '1')->json('data.banners'), 'id'));
        $this->assertSame(['홈 배너'], array_column($this->hero('home_hero', 'alpha')->assertOk()->json('data.banners'), 'title'), '정적 위치는 대상 값을 쓰지 않는다');
    }

    /**
     * 배너 없는 대상·모르는 대상 값은 빈 목록(200), 동적 위치에 대상 값이 없거나 등록되지 않은 위치면 422 다.
     *
     * @scenario case=public_empty_or_invalid_target
     *
     * @return void 반환값 없음
     */
    public function test_empty_target_and_unknown_value_return_empty_list(): void
    {
        Banner::factory()->withImages()->forPosition('fake_hero', '1')->create();

        $this->assertSame([], $this->hero('fake_hero', 'beta')->assertOk()->json('data.banners'));
        $unknown = $this->hero('fake_hero', 'no-such-board')->assertOk()->json('data');
        $this->assertSame([], $unknown['banners']);
        $this->assertNull($unknown['target_key']);

        $this->hero('fake_hero')->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->hero('nowhere_hero', 'alpha')->assertUnprocessable()->assertJsonValidationErrors('position');
    }

    /**
     * 위치를 더한 확장이 꺼지면 그 위치는 422 이고 배너는 남는다. 다시 켜면 그대로 나온다. 대상이 지워지면 빈 목록이다(D7).
     *
     * @scenario case=public_hides_unregistered_and_missing
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_position_and_missing_target_are_not_exposed(): void
    {
        $banner = Banner::factory()->withImages()->forPosition('fake_hero', '1')->create();

        $this->unregisterTestPosition();
        $this->hero('fake_hero', 'alpha')->assertUnprocessable();
        $this->assertDatabaseHas('hero_section_banners', ['id' => $banner->id]);

        $this->registerTestPosition();
        $this->assertSame([$banner->id], array_column($this->hero('fake_hero', 'alpha')->json('data.banners'), 'id'));

        FakeTargetResolver::forget('1');
        $this->assertSame([], $this->hero('fake_hero', 'alpha')->assertOk()->json('data.banners'));
        $this->assertSame([], $this->hero('fake_hero', '1')->assertOk()->json('data.banners'));
    }

    /**
     * 캐시는 대상마다 따로다 — 한 대상의 배너를 고치면 그 대상만 비고, 위치 슬라이더 설정을 고치면 모든 대상이 빈다.
     * 화면 값 해석도 캐시 시간 동안 기억해 공개 요청마다 대상 테이블을 묻지 않는다.
     *
     * @scenario case=public_cache_per_target
     *
     * @return void 반환값 없음
     */
    public function test_cache_is_per_target_and_resolution_is_remembered(): void
    {
        $this->setPluginSettings(['public_cache_minutes' => 10]);
        $admin = $this->fullAdmin();
        $alpha = Banner::factory()->withImages()->forPosition('fake_hero', '1')->create(['title' => ['ko' => '알파 전']]);
        $beta = Banner::factory()->withImages()->forPosition('fake_hero', '2')->create(['title' => ['ko' => '베타 전']]);

        $this->assertSame(['알파 전'], $this->titles('alpha'));
        $this->assertSame(['베타 전'], $this->titles('beta'));
        $this->assertSame(['알파 전'], $this->titles('alpha'));
        $this->assertSame(2, FakeTargetResolver::$resolveCalls, '같은 화면 값은 다시 풀지 않는다');

        // 훅 없이 베타를 바꿔 둔다 — 알파 배너 수정이 베타 캐시까지 비우면 바뀐 값이 보인다
        DB::table('hero_section_banners')->where('id', $beta->id)->update(['title' => json_encode(['ko' => '베타 몰래'])]);
        $this->actingAs($admin)->putJson($this->api('/admin/banners/'.$alpha->id), $this->bannerPayload(['position' => 'fake_hero', 'target_key' => '1', 'title' => ['ko' => '알파 뒤']]))->assertOk();
        $this->assertSame(['알파 뒤'], $this->titles('alpha'));
        $this->assertSame(['베타 전'], $this->titles('beta'), '다른 대상 캐시는 그대로다');

        $this->actingAs($admin)->putJson($this->api('/admin/position-settings/fake_hero'), ['autoplay' => false, 'interval_seconds' => 5, 'effect' => 'fade'])->assertOk();
        $this->assertSame(['베타 몰래'], $this->titles('beta'), '위치 설정 변경은 모든 대상을 비운다');
    }

    /**
     * 풀지 못한 화면 값은 기억하지 않는다 — 아무 값이나 보내 캐시를 부풀릴 수 없고, 나중에 생긴 대상(같은 slug 로 새로 만든 게시판)은 바로 풀린다.
     *
     * @scenario case=public_unresolved_target_not_cached
     *
     * @return void 반환값 없음
     */
    public function test_unresolved_target_is_not_remembered(): void
    {
        $this->setPluginSettings(['public_cache_minutes' => 10]);

        $this->assertSame([], $this->titles('gamma'));
        $this->assertSame([], $this->titles('gamma'));
        $this->assertSame(2, FakeTargetResolver::$resolveCalls, '없음은 기억하지 않아 매번 다시 푼다');

        FakeTargetResolver::$targets['3'] = ['gamma', '감마'];
        Banner::factory()->withImages()->forPosition('fake_hero', '3')->create(['title' => ['ko' => '감마 배너']]);
        $this->assertSame(['감마 배너'], $this->titles('gamma'), '새로 생긴 대상은 바로 보인다');
    }

    /**
     * 대상이 지워지거나 주소 값이 바뀌면(같은 slug 를 다른 대상이 쓰게 됨) 그 위치의 해석 기억을 버린다 — 지운 대상의 배너가
     * 새 대상 화면에 나오지 않는다(D7). 배너 캐시는 그대로다(대상 수정이 잦아도 배너를 다시 읽지 않는다).
     *
     * @scenario case=public_target_change_forgets_resolution
     *
     * @return void 반환값 없음
     */
    public function test_target_change_forgets_remembered_resolution(): void
    {
        $this->setPluginSettings(['public_cache_minutes' => 10]);
        Banner::factory()->withImages()->forPosition('fake_hero', '1')->create(['title' => ['ko' => '지운 알파']]);
        Banner::factory()->withImages()->forPosition('fake_hero', '3')->create(['title' => ['ko' => '새 알파']]);
        $this->assertSame(['지운 알파'], $this->titles('alpha'));

        // 대상 1(alpha)을 지우고 같은 slug 로 대상 3을 만든다
        FakeTargetResolver::forget('1');
        FakeTargetResolver::$targets['3'] = ['alpha', '새 알파'];
        $this->assertSame(['지운 알파'], $this->titles('alpha'), '알림 전에는 기억한 해석을 쓴다');

        app(PublicHeroService::class)->forgetTargets('fake_hero');
        $this->assertSame(['새 알파'], $this->titles('alpha'));
    }

    /**
     * 게시판·상품·카테고리 모듈이 대상을 만들거나 고치거나 지우면 그 위치의 해석 기억을 버린다 — 실제 훅 체인(모듈 훅 → 리스너 →
     * 공개 API)으로 본다. 게시판 저장소만 목으로 바꾼다(지운 게시판 slug 를 새 게시판이 쓰는 상황).
     *
     * @scenario case=public_target_change_forgets_resolution
     *
     * @return void 반환값 없음
     */
    public function test_module_target_hooks_forget_resolution_of_their_position(): void
    {
        $this->setPluginSettings(['public_cache_minutes' => 10]);
        $this->pinActiveModules(['sirsoft-board', 'sirsoft-ecommerce']);
        $boards = Mockery::mock(BoardRepositoryInterface::class);
        $boards->shouldReceive('findBySlug')->with('free')->andReturn($this->board(2), $this->board(7));
        $this->app->instance(BoardRepositoryInterface::class, $boards);
        Banner::factory()->withImages()->forPosition('board_hero', '2')->create(['title' => ['ko' => '지운 게시판']]);
        Banner::factory()->withImages()->forPosition('board_hero', '7')->create(['title' => ['ko' => '새 게시판']]);

        $this->assertSame(['지운 게시판'], array_column($this->hero('board_hero', 'free')->json('data.banners'), 'title'));
        $this->assertSame(['지운 게시판'], array_column($this->hero('board_hero', 'free')->json('data.banners'), 'title'), '기억한 해석을 쓴다(저장소를 다시 묻지 않음)');

        HookManager::doAction('sirsoft-board.board.after_delete', 2);
        $this->assertSame(['새 게시판'], array_column($this->hero('board_hero', 'free')->json('data.banners'), 'title'));

        // 상품·카테고리 훅도 자기 위치의 해석 버전만 올린다
        $cache = (new \ReflectionProperty(PublicHeroService::class, 'cache'))->getValue(app(PublicHeroService::class));
        $marks = fn () => array_map(fn ($p) => $cache->get('hero:resolve-version:'.$p), ['board_hero', 'product_hero', 'category_hero']);
        $before = $marks();
        HookManager::doAction('sirsoft-ecommerce.product.after_update', 1);
        $afterProduct = $marks();
        $this->assertSame([$before[0], $before[2]], [$afterProduct[0], $afterProduct[2]]);
        $this->assertNotSame($before[1], $afterProduct[1]);
        HookManager::doAction('sirsoft-ecommerce.category.after_create', 2);
        $this->assertNotSame($afterProduct[2], $marks()[2]);
    }

    /**
     * 시험용 게시판을 만듭니다(저장하지 않음).
     *
     * @param  int  $id  게시판 id
     * @return Board 게시판
     */
    private function board(int $id): Board
    {
        return (new Board)->forceFill(['id' => $id, 'slug' => 'free', 'name' => ['ko' => '자유게시판']]);
    }

    /**
     * 첫 로드 힌트(`slot_*`)에는 정적 위치만 있다 — 대상마다 값을 줄 수 없다(D8).
     *
     * @scenario case=slot_hints_static_only
     *
     * @return void 반환값 없음
     */
    public function test_slot_hints_carry_static_positions_only(): void
    {
        Banner::factory()->withImages()->forPosition('fake_hero', '1')->running()->create();
        $service = app(PositionSettingService::class);

        $this->assertSame(['home_hero', 'shop_hero'], array_keys($service->slotWidths()));
        $this->assertSame(['home_hero', 'shop_hero'], array_keys($service->slotOverlays()));
        $this->assertSame(['home_hero' => false, 'shop_hero' => false], $service->slotBanners());
    }

    /**
     * 공개 API 를 부릅니다.
     *
     * @param  string  $position  위치 키
     * @param  string|null  $target  화면 대상 값
     * @return TestResponse 응답
     */
    private function hero(string $position, ?string $target = null): TestResponse
    {
        $query = http_build_query(array_filter(['position' => $position, 'target' => $target], fn ($v) => $v !== null));

        return $this->getJson($this->api('/hero?'.$query));
    }

    /**
     * 시험용 동적 위치의 공개 배너 제목 목록을 반환합니다.
     *
     * @param  string  $target  화면 대상 값
     * @return array<int, string> 제목 목록
     */
    private function titles(string $target): array
    {
        return array_column($this->hero('fake_hero', $target)->assertOk()->json('data.banners'), 'title');
    }
}
