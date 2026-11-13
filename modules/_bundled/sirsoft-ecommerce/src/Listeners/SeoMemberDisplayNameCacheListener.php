<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 회원의 표시 이름이 바뀌면 작성자 이름이 구워진 상품 상세 SEO 캐시를 무효화하는 리스너
 *
 * 검색 엔진용으로 미리 그린 상품 상세 화면(`shop/show`)은 상품 후기와 상품 문의 목록을 함께 굽는다. 표시 이름은 저장하지
 * 않고 볼 때마다 계산하므로, 회원의 이름, 닉네임, 표시 이름 종류, 표시용 항목 값이 바뀌면 그 회원의 후기와 문의가 실린
 * 화면의 작성자가 달라진다. 코어가 그 변화를 액션 `core.user.display_name_changed` 로 알린다.
 *
 * 이름이 구워지는 이커머스 캐시는 상품 상세 SEO 캐시 하나다. 상품 목록 캐시, 카테고리 트리 캐시, 주문 통계 캐시에는
 * 회원 이름이 들어가지 않는다. 표시 이름 설정 자체를 바꾼 경우(환경설정의 회원 탭, 쇼핑몰 환경설정의 기능별 표시 이름)는
 * 코어가 SEO 캐시 전체를 비운다.
 *
 * 무효화는 저장한 요청 안에서 한다(sync). 큐로 가면 워커가 돌 때까지 봇이 옛 이름을 받는다. 본문은 `DB::afterCommit` 으로
 * 감싸 트랜잭션 안에서 발화하면 커밋 뒤에 비운다({@see SeoProductCacheListener} 와 같은 방식).
 */
class SeoMemberDisplayNameCacheListener implements HookListenerInterface
{
    /** 상품 상세 화면의 레이아웃 이름 (후기와 문의가 함께 구워진다) */
    private const PRODUCT_DETAIL_LAYOUT = 'shop/show';

    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.display_name_changed' => [
                'method' => 'onUserDisplayNameChanged',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 회원의 표시 이름이 바뀌면 상품 상세 SEO 캐시를 무효화합니다.
     *
     * 어느 상품에 그 회원의 후기나 문의가 실렸는지 따로 좁히지 않는다. 상품 상세 캐시 전체를 비운다(게시판 화면 캐시와 같은 처리).
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: 회원, 두 번째: 바뀐 값의 키 목록)
     * @return void 반환값 없음
     */
    public function onUserDisplayNameChanged(...$args): void
    {
        DB::afterCommit(function () {
            try {
                app(SeoCacheManagerInterface::class)->invalidateByLayout(self::PRODUCT_DETAIL_LAYOUT);

                Log::debug('[SEO] Product detail cache invalidated on member display name change');
            } catch (\Throwable $e) {
                Log::warning('[SEO] Product detail cache invalidation on member display name change failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
