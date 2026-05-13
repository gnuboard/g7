<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\ToggleWishlistRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\WishlistCollection;
use Modules\Sirsoft\Ecommerce\Services\ProductWishlistService;

/**
 * 찜(위시리스트) 컨트롤러
 *
 * 인증된 사용자의 상품 찜 기능 API를 제공합니다.
 */
class WishlistController extends PublicBaseController
{
    public function __construct(
        private ProductWishlistService $wishlistService
    ) {}

    /**
     * 찜 토글 (추가/제거)
     *
     * @param ToggleWishlistRequest $request 검증된 요청
     * @return JsonResponse 토글 결과
     */
    public function toggle(ToggleWishlistRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('wishlist.toggle');

            $userId = Auth::id();
            $productId = (int) $request->validated('product_id');

            $result = $this->wishlistService->toggle($userId, $productId);

            $messageKey = $result['added']
                ? 'messages.wishlist.added'
                : 'messages.wishlist.removed';

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', $messageKey, [
                'added' => $result['added'],
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.wishlist.toggle_failed');
        }
    }

    /**
     * 사용자 찜 목록 조회
     *
     * @param Request $request 요청
     * @return JsonResponse 찜 목록
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $this->logApiUsage('wishlist.index');

            $userId = Auth::id();
            $perPage = min((int) $request->input('per_page', 20), 100);

            $wishlists = $this->wishlistService->getByUser($userId, $perPage);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.wishlist.retrieved',
                new WishlistCollection($wishlists)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.wishlist.retrieve_failed');
        }
    }

    /**
     * 찜 삭제
     *
     * @param int $id 찜 ID
     * @return JsonResponse 삭제 결과
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->logApiUsage('wishlist.destroy');

            $userId = Auth::id();
            $deleted = $this->wishlistService->destroy($id, $userId);

            if (! $deleted) {
                return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.wishlist.not_found', 404);
            }

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.wishlist.removed');
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.wishlist.delete_failed');
        }
    }
}
