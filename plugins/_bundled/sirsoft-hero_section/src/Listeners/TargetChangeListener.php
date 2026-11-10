<?php

namespace Plugins\Sirsoft\HeroSection\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\HeroSection\Services\PublicHeroService;

/**
 * 내장 동적 위치의 대상 변경 감지 — 게시판·상품·카테고리가 생기거나 바뀌거나 지워지면 그 위치의 대상 해석 기억을 버린다.
 *
 * 공개 API 는 화면 값(slug·상품 코드) → 대상 id 를 캐시 시간 동안 기억한다. 지운 게시판의 slug 를 새 게시판이 쓰거나 slug 를 바꿨는데
 * 옛 해석이 남으면 지운(또는 다른) 대상의 배너가 나온다(D7 위반). 배너는 지우지 않는다 — 해석 기억만 버린다.
 * 모듈이 꺼져 있으면 훅이 오지 않을 뿐이다(모듈 코드는 고치지 않는다, D2).
 */
class TargetChangeListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환 — 대상의 생성·수정·삭제 직후(대상 주소 값·존재가 바뀌는 때)
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        $sync = ['priority' => 20, 'sync' => true];

        return [
            'sirsoft-board.board.after_create' => ['method' => 'onBoardChanged'] + $sync,
            'sirsoft-board.board.after_update' => ['method' => 'onBoardChanged'] + $sync,
            'sirsoft-board.board.after_delete' => ['method' => 'onBoardChanged'] + $sync,
            'sirsoft-ecommerce.product.after_create' => ['method' => 'onProductChanged'] + $sync,
            'sirsoft-ecommerce.product.after_update' => ['method' => 'onProductChanged'] + $sync,
            'sirsoft-ecommerce.product.after_delete' => ['method' => 'onProductChanged'] + $sync,
            'sirsoft-ecommerce.category.after_create' => ['method' => 'onCategoryChanged'] + $sync,
            'sirsoft-ecommerce.category.after_update' => ['method' => 'onCategoryChanged'] + $sync,
            'sirsoft-ecommerce.category.after_delete' => ['method' => 'onCategoryChanged'] + $sync,
        ];
    }

    /**
     * TargetChangeListener 생성자
     *
     * @param  PublicHeroService  $hero  공개 노출 서비스
     */
    public function __construct(private PublicHeroService $hero) {}

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 게시판이 바뀌면 게시판 목록 위치의 해석 기억을 버립니다.
     *
     * @param  mixed  ...$args  게시판 훅 인자 (쓰지 않는다)
     * @return void 반환값 없음
     */
    public function onBoardChanged(...$args): void
    {
        $this->hero->forgetTargets('board_hero');
    }

    /**
     * 상품이 바뀌면 상품 상세 위치의 해석 기억을 버립니다.
     *
     * @param  mixed  ...$args  상품 훅 인자 (쓰지 않는다)
     * @return void 반환값 없음
     */
    public function onProductChanged(...$args): void
    {
        $this->hero->forgetTargets('product_hero');
    }

    /**
     * 카테고리가 바뀌면 카테고리 목록 위치의 해석 기억을 버립니다.
     *
     * @param  mixed  ...$args  카테고리 훅 인자 (쓰지 않는다)
     * @return void 반환값 없음
     */
    public function onCategoryChanged(...$args): void
    {
        $this->hero->forgetTargets('category_hero');
    }
}
