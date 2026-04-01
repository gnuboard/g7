<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreUserAddressRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateUserAddressRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\UserAddressCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\UserAddressResource;
use Modules\Sirsoft\Ecommerce\Services\UserAddressService;

/**
 * 사용자 배송지 컨트롤러
 *
 * 마이페이지 배송지 관리 API를 제공합니다.
 */
class UserAddressController extends AuthBaseController
{
    public function __construct(
        private UserAddressService $userAddressService
    ) {}

    /**
     * 사용자 배송지 목록 조회
     *
     * @return JsonResponse 배송지 목록을 포함한 JSON 응답
     */
    public function index(): JsonResponse
    {
        try {
            
            $userId = Auth::id();
            $addresses = $this->userAddressService->getUserAddresses($userId);

            return ResponseHelper::success('sirsoft-ecommerce::messages.address.list_fetched', [
                'addresses' => new UserAddressCollection($addresses),
            ]);
        } catch (Exception $e) {
            Log::error('User address list fetch failed', [
                'message' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.address.list_fetch_failed',
                500
            );
        }
    }

    /**
     * 사용자 배송지 상세 조회
     *
     * @param int $id 배송지 ID
     * @return JsonResponse 배송지 정보를 포함한 JSON 응답
     */
    public function show(int $id): JsonResponse
    {
        try {
            
            $userId = Auth::id();
            $address = $this->userAddressService->getAddress($userId, $id);

            if (! $address) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.user_address_not_found',
                    404
                );
            }

            return ResponseHelper::success('sirsoft-ecommerce::messages.address.fetched', [
                'address' => new UserAddressResource($address),
            ]);
        } catch (Exception $e) {
            Log::error('User address fetch failed', [
                'message' => $e->getMessage(),
                'user_id' => Auth::id(),
                'address_id' => $id,
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.address.fetch_failed',
                500
            );
        }
    }

    /**
     * 사용자 배송지 등록
     *
     * @param StoreUserAddressRequest $request 검증된 요청 데이터
     * @return JsonResponse 생성된 배송지 정보를 포함한 JSON 응답
     */
    public function store(StoreUserAddressRequest $request): JsonResponse
    {
        try {
            
            $userId = Auth::id();
            $data = $request->validated();
            $data['user_id'] = $userId;

            $address = $this->userAddressService->createAddress($data);

            return ResponseHelper::success('sirsoft-ecommerce::messages.address.created', [
                'address' => new UserAddressResource($address),
            ], 201);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('User address creation failed', [
                'message' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            // 최대 배송지 개수 초과 예외 처리
            if (str_contains($e->getMessage(), 'max_addresses_exceeded')) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.max_addresses_exceeded',
                    422
                );
            }

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.address.create_failed',
                500
            );
        }
    }

    /**
     * 사용자 배송지 수정
     *
     * @param UpdateUserAddressRequest $request 검증된 요청 데이터
     * @param int $id 배송지 ID
     * @return JsonResponse 수정된 배송지 정보를 포함한 JSON 응답
     */
    public function update(UpdateUserAddressRequest $request, int $id): JsonResponse
    {
        try {
            
            $userId = Auth::id();
            $data = $request->validated();

            $address = $this->userAddressService->updateAddress($userId, $id, $data);

            if (! $address) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.user_address_not_found',
                    404
                );
            }

            return ResponseHelper::success('sirsoft-ecommerce::messages.address.updated', [
                'address' => new UserAddressResource($address),
            ]);
        } catch (Exception $e) {
            Log::error('User address update failed', [
                'message' => $e->getMessage(),
                'user_id' => Auth::id(),
                'address_id' => $id,
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.address.update_failed',
                500
            );
        }
    }

    /**
     * 사용자 배송지 삭제
     *
     * @param int $id 배송지 ID
     * @return JsonResponse 삭제 결과를 포함한 JSON 응답
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            
            $userId = Auth::id();
            $deleted = $this->userAddressService->deleteAddress($userId, $id);

            if (! $deleted) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.user_address_not_found',
                    404
                );
            }

            return ResponseHelper::success('sirsoft-ecommerce::messages.address.deleted');
        } catch (Exception $e) {
            Log::error('User address deletion failed', [
                'message' => $e->getMessage(),
                'user_id' => Auth::id(),
                'address_id' => $id,
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.address.delete_failed',
                500
            );
        }
    }

    /**
     * 기본 배송지 설정
     *
     * @param int $id 배송지 ID
     * @return JsonResponse 설정 결과를 포함한 JSON 응답
     */
    public function setDefault(int $id): JsonResponse
    {
        try {
            
            $userId = Auth::id();
            $result = $this->userAddressService->setDefaultAddress($userId, $id);

            if (! $result) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.user_address_not_found',
                    404
                );
            }

            return ResponseHelper::success('sirsoft-ecommerce::messages.address.default_set');
        } catch (Exception $e) {
            Log::error('User address set default failed', [
                'message' => $e->getMessage(),
                'user_id' => Auth::id(),
                'address_id' => $id,
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.address.set_default_failed',
                500
            );
        }
    }
}
