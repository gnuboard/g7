<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductWishlistRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SearchPresetRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\TempOrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;

/**
 * 회원 완전 삭제 시 이커머스 회원 소유 데이터 정리 서비스
 *
 * 회원 행이 지워지기 전에(코어 `core.user.before_delete`, 같은 트랜잭션) 회원에게 묶인 행을 명시적으로 지운다.
 * DB 외래키의 연쇄 삭제는 안전망으로만 남긴다 — 그것에 기대면 훅·파일·로그가 빠지고, 쿠폰 발급 수량처럼
 * 다른 표에 남은 파생값이 어긋난다.
 *
 * 지우는 것: 검색 프리셋, 찜, 장바구니(선택 삭제 훅 발화), 임시 주문(삭제 훅 발화), 쿠폰 발급 내역(발급 수량 보정),
 * 배송지, 리뷰(이미지 행·파일 포함, 파일은 커밋 뒤), 이커머스 회원 프로필.
 * 지우지 않는 것: 주문·결제·클레임 이력(거래 기록 — 회원 연결만 외래키가 끊는다), 마일리지 원장(별도 리스너가 연결을 끊는다),
 * 상품 문의(회원 연결만 끊긴다).
 */
class UserDataPurgeService
{
    /**
     * @param  SearchPresetRepositoryInterface  $searchPresetRepository  검색 프리셋 저장소
     * @param  ProductWishlistRepositoryInterface  $wishlistRepository  찜 저장소
     * @param  CartService  $cartService  장바구니 서비스
     * @param  TempOrderRepositoryInterface  $tempOrderRepository  임시 주문 저장소 (대상 ID 조회)
     * @param  TempOrderService  $tempOrderService  임시 주문 서비스 (삭제 훅 발화)
     * @param  CouponIssueRepositoryInterface  $couponIssueRepository  쿠폰 발급 저장소
     * @param  CouponRepositoryInterface  $couponRepository  쿠폰 저장소 (발급 수량 보정)
     * @param  UserAddressRepositoryInterface  $userAddressRepository  배송지 저장소
     * @param  ProductReviewService  $reviewService  리뷰 서비스 (이미지 파일 포함 삭제)
     * @param  EcommerceUserProfileRepositoryInterface  $userProfileRepository  이커머스 회원 프로필 저장소
     */
    public function __construct(
        protected SearchPresetRepositoryInterface $searchPresetRepository,
        protected ProductWishlistRepositoryInterface $wishlistRepository,
        protected CartService $cartService,
        protected TempOrderRepositoryInterface $tempOrderRepository,
        protected TempOrderService $tempOrderService,
        protected CouponIssueRepositoryInterface $couponIssueRepository,
        protected CouponRepositoryInterface $couponRepository,
        protected UserAddressRepositoryInterface $userAddressRepository,
        protected ProductReviewService $reviewService,
        protected EcommerceUserProfileRepositoryInterface $userProfileRepository,
    ) {}

    /**
     * 삭제되는 회원의 이커머스 소유 데이터를 명시적으로 지웁니다.
     *
     * 한 트랜잭션이다 — 코어 회원 삭제 트랜잭션 안에서 불리면 그 일부가 되어, 회원 삭제가 되돌아가면 함께 되돌아간다.
     * 리뷰 이미지 파일은 커밋된 뒤에만 지운다.
     *
     * @param  int  $userId  삭제되는 회원 ID
     * @return array<string, int> 항목별 삭제 건수 (search_presets·wishlists·carts·temp_orders·coupon_issues·user_addresses·reviews·user_profiles)
     */
    public function purgeForDeletedUser(int $userId): array
    {
        $counts = DB::transaction(function () use ($userId) {
            $counts = [];

            $counts['carts'] = $this->cartService->purgeForUser($userId);

            $counts['temp_orders'] = 0;
            foreach ($this->tempOrderRepository->getIdsByUserId($userId) as $tempOrderId) {
                if ($this->tempOrderService->deleteTempOrderById($tempOrderId)) {
                    $counts['temp_orders']++;
                }
            }

            $counts['wishlists'] = $this->wishlistRepository->deleteByUserId($userId);
            $counts['search_presets'] = $this->searchPresetRepository->deleteByUserId($userId);

            // 쓰지 않은 발급 건수를 먼저 세고, 지운 뒤 그만큼 쿠폰별 발급 수량을 되돌린다 — 그대로 두면 사라진 회원의
            // 미사용 발급분이 수량 한정 쿠폰의 남은 수량을 계속 깎는다. 사용한 발급은 실제로 소진된 수량이라 되돌리지 않는다.
            $issuedByCoupon = $this->couponIssueRepository->countIssuedByCouponForUser($userId);
            $counts['coupon_issues'] = $this->couponIssueRepository->deleteByUserId($userId);
            $this->couponRepository->releaseIssuedCounts($issuedByCoupon);

            $counts['user_addresses'] = $this->userAddressRepository->deleteByUserId($userId);
            $counts['reviews'] = $this->reviewService->purgeForUser($userId);
            $counts['user_profiles'] = $this->userProfileRepository->deleteByUserId($userId);

            return $counts;
        });

        if (array_sum($counts) > 0) {
            Log::info('회원 삭제 — 이커머스 회원 데이터 명시 삭제', ['user_id' => $userId] + $counts);
        }

        return $counts;
    }
}
