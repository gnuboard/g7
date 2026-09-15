<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Models\User;
use Illuminate\Database\QueryException;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Services\ProductInquiryService;
use Modules\Sirsoft\Ecommerce\Services\ProductWishlistService;
use Modules\Sirsoft\Ecommerce\Services\UserAddressService;

/**
 * 회원 상세 쇼핑 데이터 요약 주입 리스너 (filter 훅)
 *
 * 코어 UserResource 의 관리자 상세(withAdminInfo)·프로필(/api/me) 응답에 발화하는
 * core.user.filter_resource_data 필터 훅을 구독해, 찜·장바구니·배송지·상품문의 건수를
 * **관리자 응답에만** 병합합니다.
 *
 * 프로필(/api/me)에는 싣지 않는다 — 네 건의 COUNT 를 부팅 경로마다 돌리게 되고,
 * 사용자 화면은 각 탭이 자기 목록 API 로 같은 값을 더 정확하게 낸다.
 *
 * 회원 목록(toListArray)에는 이 훅이 발화하지 않으므로 N+1·목록 풀스캔이 구조적으로 없습니다.
 */
class UserEcommerceDataInfoListener implements HookListenerInterface
{
    /**
     * @param  ProductWishlistService  $wishlistService  찜 서비스
     * @param  CartService  $cartService  장바구니 서비스
     * @param  UserAddressService  $addressService  배송지 서비스
     * @param  ProductInquiryService  $inquiryService  상품문의 서비스
     */
    public function __construct(
        private ProductWishlistService $wishlistService,
        private CartService $cartService,
        private UserAddressService $addressService,
        private ProductInquiryService $inquiryService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, type: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.filter_resource_data' => [
                'method' => 'injectEcommerceData',
                'type' => 'filter',
                'priority' => 21,
            ],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void
    {
        // method 매핑(injectEcommerceData)을 사용하므로 직접 호출되지 않음
    }

    /**
     * 관리자 회원 상세 응답에 쇼핑 데이터 요약을 병합합니다.
     *
     * @param  array<string, mixed>  $data  코어가 직렬화한 회원 응답 데이터
     * @param  User  $user  회원 모델
     * @return array<string, mixed> 요약이 병합된 데이터
     */
    public function injectEcommerceData(array $data, User $user): array
    {
        if (! $this->isAdminRequest()) {
            return $data;
        }

        try {
            $data['ecommerce_data'] = [
                'wishlist_count' => $this->wishlistService->countByUserForAdmin($user->id),
                'cart_count' => $this->cartService->countByUserForAdmin($user->id),
                'address_count' => $this->addressService->countByUserForAdmin($user->id),
                'inquiry_count' => $this->inquiryService->countByUserForAdmin($user->id),
                'inquiry_pending_count' => $this->inquiryService->countPendingByUserForAdmin($user->id),
            ];
        } catch (QueryException $e) {
            // 테이블이 아직 없거나(설치 직후) 집계가 실패해도 회원 상세 화면 자체는 떠야 한다.
            $data['ecommerce_data'] = [
                'wishlist_count' => 0,
                'cart_count' => 0,
                'address_count' => 0,
                'inquiry_count' => 0,
                'inquiry_pending_count' => 0,
            ];
        }

        return $data;
    }

    /**
     * 현재 요청이 관리자 컨트롤러에 도달했는지 판정합니다.
     *
     * 헤더·쿼리 같은 클라이언트 입력이 아니라 «어느 컨트롤러가 받았는가» 로 본다 —
     * 클라이언트가 바꿀 수 있는 값으로 판정하면 게이트가 곧 우회로가 된다.
     *
     * @return bool 관리자 요청 여부
     */
    private function isAdminRequest(): bool
    {
        // 요청 «입력» 을 읽는 것이 아니라 "어느 컨트롤러가 이 응답을 만들고 있는가" 라는 맥락만 본다.
        // 훅(core.user.filter_resource_data)은 그 맥락을 인자로 주지 않고, 클라이언트가 바꿀 수 있는
        // 값(헤더·쿼리)으로 판정하면 게이트가 곧 우회로가 된다 — 도달한 컨트롤러 타입은 위조할 수 없다.
        // audit:allow listener-formrequest-bypass reason: 전송 계층 «입력» 이 아니라 라우트 맥락 판정
        return request()->route()?->getController() instanceof AdminBaseController;
    }
}
