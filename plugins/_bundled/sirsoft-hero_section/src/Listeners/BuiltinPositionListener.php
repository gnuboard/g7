<?php

namespace Plugins\Sirsoft\HeroSection\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\ModuleManager;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Support\Targets\BoardTargetResolver;
use Plugins\Sirsoft\HeroSection\Support\Targets\CategoryTargetResolver;
use Plugins\Sirsoft\HeroSection\Support\Targets\ProductTargetResolver;

/**
 * 내장 노출 위치 — 위치 필터에 히어로 자신의 위치를 맨 앞으로 넣는다.
 *
 * 사이트 홈·쇼핑몰 메인(정적)은 늘 넣고, 게시판 목록(게시판마다)·상품 상세(상품마다)·카테고리 목록(카테고리마다)은 그 모듈이 켜져 있을
 * 때만 넣는다. 모듈 코드는 고치지 않는다 — 해석기가 모듈 저장소 계약만 쓴다. 모듈을 끄면 그 위치가 목록에서 빠지고, 배너는 남는다.
 */
class BuiltinPositionListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            PositionRegistry::FILTER => ['method' => 'register', 'type' => 'filter', 'priority' => 1],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 내장 위치를 맨 앞에 넣습니다 — 같은 키는 먼저 온 쪽이 이기므로 다른 확장이 내장 키를 가로채지 못한다.
     *
     * @param  array<int, mixed>  $positions  지금까지 모은 위치 정의
     * @return array<int, mixed> 내장 위치를 앞에 더한 정의
     */
    public function register(array $positions): array
    {
        return [...$this->builtins(), ...$positions];
    }

    /**
     * 켜져 있는 모듈을 반영한 내장 위치 정의를 반환합니다.
     *
     * @return array<int, array<string, mixed>> 위치 정의
     */
    private function builtins(): array
    {
        $active = $this->activeModules();
        $positions = [
            ['key' => 'home_hero', 'label' => 'sirsoft-hero_section.admin.position.home_hero', 'order' => 10],
            ['key' => 'shop_hero', 'label' => 'sirsoft-hero_section.admin.position.shop_hero', 'order' => 20],
        ];

        if (in_array('sirsoft-board', $active, true)) {
            $positions[] = ['key' => 'board_hero', 'label' => 'sirsoft-hero_section.admin.position.board_hero', 'order' => 100,
                'target' => ['type' => 'board', 'label' => 'sirsoft-hero_section.admin.target.board', 'resolver' => BoardTargetResolver::class]];
        }

        if (in_array('sirsoft-ecommerce', $active, true)) {
            $positions[] = ['key' => 'product_hero', 'label' => 'sirsoft-hero_section.admin.position.product_hero', 'order' => 110,
                'target' => ['type' => 'product', 'label' => 'sirsoft-hero_section.admin.target.product', 'resolver' => ProductTargetResolver::class]];
            $positions[] = ['key' => 'category_hero', 'label' => 'sirsoft-hero_section.admin.position.category_hero', 'order' => 120,
                'target' => ['type' => 'category', 'label' => 'sirsoft-hero_section.admin.target.category', 'resolver' => CategoryTargetResolver::class]];
        }

        return $positions;
    }

    /**
     * 켜져 있는 모듈 식별자를 반환합니다(시험에서 바꿔 끼운다).
     *
     * @return array<int, string> 모듈 식별자
     */
    protected function activeModules(): array
    {
        return ModuleManager::getActiveModuleIdentifiers();
    }
}
