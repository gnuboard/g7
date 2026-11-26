<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Positions;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Board\Contracts\BoardDirectory;
use Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Support\Targets\BoardTargetResolver;
use Plugins\Sirsoft\HeroSection\Tests\Concerns\PinsActiveModules;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 위치 레지스트리 — 위치 목록을 필터(`sirsoft-hero_section.position.registered_positions`)로 모은다(매니페스트 position-registry.yaml).
 *
 * 내장 정적 위치(사이트 홈·쇼핑몰 메인)는 늘 있고, 동적 위치(게시판·상품·카테고리)는 해당 모듈이 켜져 있을 때만 히어로가 스스로 등록한다.
 * 제3 확장은 같은 필터로 위치를 더한다. 어긋난 정의는 버리고 로그를 남긴다 — 화면을 깨지 않는다.
 */
class PositionRegistryTest extends PluginTestCase
{
    use PinsActiveModules;

    /**
     * 테스트 뒤 목 객체를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 모듈이 하나도 켜져 있지 않으면 내장 정적 위치 두 개만 있다.
     *
     * @scenario case=builtin_static_positions
     *
     * @return void 반환값 없음
     */
    public function test_static_positions_only_without_modules(): void
    {
        $this->pinActiveModules([]);

        $registry = app(PositionRegistry::class);

        $this->assertSame(['home_hero', 'shop_hero'], array_keys($registry->all()));
        $this->assertFalse($registry->isDynamic('home_hero'));
        $this->assertTrue($registry->isRegistered('shop_hero'));
        $this->assertFalse($registry->isRegistered('board_hero'));
    }

    /**
     * 게시판·이커머스 모듈이 켜져 있으면 게시판 목록·상품 상세·카테고리 목록 위치가 정적 위치 뒤에 붙는다.
     *
     * @scenario case=builtin_dynamic_positions_follow_modules
     *
     * @return void 반환값 없음
     */
    public function test_dynamic_positions_follow_active_modules(): void
    {
        $this->pinActiveModules(['sirsoft-board']);
        $this->assertSame(['home_hero', 'shop_hero', 'board_hero'], array_keys(app(PositionRegistry::class)->all()));

        $this->pinActiveModules(['sirsoft-board', 'sirsoft-ecommerce']);
        $registry = app(PositionRegistry::class);
        $this->assertSame(['home_hero', 'shop_hero', 'board_hero', 'product_hero', 'category_hero'], array_keys($registry->all()));
        $this->assertTrue($registry->isDynamic('product_hero'));
        $this->assertSame('category', $registry->find('category_hero')->targetType);
    }

    /**
     * 모듈이 켜져 있어도 조회 계약이 없는 옛 판(게시판 1.3.0·이커머스 1.4.0 미만)이면 동적 위치를 넣지 않는다 — 해석기가 계약으로만
     * 대상을 찾으므로, 넣으면 위치를 고를 때 해석기 생성이 실패한다.
     *
     * @scenario case=builtin_dynamic_positions_follow_modules
     *
     * @return void 반환값 없음
     */
    public function test_dynamic_positions_need_module_contracts(): void
    {
        $this->pinActiveModules(['sirsoft-board', 'sirsoft-ecommerce'], contractsBound: false);

        $this->assertSame(['home_hero', 'shop_hero'], array_keys(app(PositionRegistry::class)->all()));
    }

    /**
     * 제3 확장이 같은 필터로 더한 위치도 등록되고, 정렬 순서(order)대로 탭에 선다. 이름은 언어별 객체도 받는다.
     *
     * @scenario case=third_party_position_added
     *
     * @return void 반환값 없음
     */
    public function test_third_party_position_is_added_in_order(): void
    {
        $this->pinActiveModules(['sirsoft-board']);
        HookManager::addFilter('sirsoft-hero_section.position.registered_positions', fn (array $positions) => [
            ...$positions,
            ['key' => 'event_hero', 'label' => ['ko' => '이벤트', 'en' => 'Event'], 'order' => 50],
        ]);

        $registry = app(PositionRegistry::class);

        $this->assertSame(['home_hero', 'shop_hero', 'event_hero', 'board_hero'], array_keys($registry->all()));
        app()->setLocale('en');
        $this->assertSame('Event', $registry->find('event_hero')->label());
    }

    /**
     * 키 형식 위반·이미 있는 키·이름 없음·해석기 계약 위반은 버리고, 남은 위치는 그대로 쓴다. 버린 정의마다 경고를 남긴다.
     *
     * @scenario case=invalid_position_definitions_dropped
     *
     * @return void 반환값 없음
     */
    public function test_invalid_definitions_are_dropped_with_warning(): void
    {
        $this->pinActiveModules([]);
        Log::spy();
        HookManager::addFilter('sirsoft-hero_section.position.registered_positions', fn (array $positions) => [
            ...$positions,
            ['key' => 'Bad-Key', 'label' => ['ko' => '잘못']],
            ['key' => 'home_hero', 'label' => ['ko' => '가로채기']],
            ['key' => 'nameless_hero'],
            ['key' => 'broken_hero', 'label' => ['ko' => '고장'], 'target' => ['type' => 'x', 'label' => ['ko' => '엑스'], 'resolver' => \stdClass::class]],
            'not-an-array',
        ]);

        $registry = app(PositionRegistry::class);

        $this->assertSame(['home_hero', 'shop_hero'], array_keys($registry->all()));
        $this->assertSame('sirsoft-hero_section.admin.position.home_hero', $registry->find('home_hero')->labelKey());
        Log::shouldHaveReceived('warning')->times(5);
    }

    /**
     * 동적 위치는 대상 해석기를 컨테이너로 만들어 돌려준다(정적 위치는 null).
     *
     * @scenario case=builtin_dynamic_positions_follow_modules
     *
     * @return void 반환값 없음
     */
    public function test_dynamic_position_resolver_is_built_from_container(): void
    {
        $this->pinActiveModules(['sirsoft-board']);
        $this->app->instance(BoardDirectory::class, Mockery::mock(BoardDirectory::class));

        $registry = app(PositionRegistry::class);

        $this->assertInstanceOf(BoardTargetResolver::class, $registry->resolver('board_hero'));
        $this->assertInstanceOf(TargetResolverInterface::class, $registry->resolver('board_hero'));
        $this->assertNull($registry->resolver('home_hero'));
        $this->assertNull($registry->resolver('unknown_hero'));
    }
}
