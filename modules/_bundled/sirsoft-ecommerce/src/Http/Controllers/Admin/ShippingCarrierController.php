<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingCarrierOperationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ActiveShippingCarrierRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\IndexShippingCarrierRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreShippingCarrierRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateShippingCarrierRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ShippingCarrierCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ShippingCarrierResource;
use Modules\Sirsoft\Ecommerce\Services\ShippingCarrierService;

/**
 * 배송사 관리 컨트롤러
 */
class ShippingCarrierController extends AdminBaseController
{
    public function __construct(
        private ShippingCarrierService $carrierService
    ) {}

    /**
     * 배송사 목록 조회
     *
     * @param  IndexShippingCarrierRequest  $request
     * @return JsonResponse
     */
    public function index(IndexShippingCarrierRequest $request): JsonResponse
    {
        $carriers = $this->carrierService->getAllCarriers($request->filters());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_carriers.list_retrieved',
            new ShippingCarrierCollection($carriers)
        );
    }

    /**
     * 배송사 생성
     *
     * @param  StoreShippingCarrierRequest  $request
     * @return JsonResponse
     */
    public function store(StoreShippingCarrierRequest $request): JsonResponse
    {
        $carrier = $this->carrierService->createCarrier($request->validated());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_carriers.created',
            new ShippingCarrierResource($carrier),
            201
        );
    }

    /**
     * 활성 배송사 목록 조회 (Select 옵션용)
     *
     * @param  ActiveShippingCarrierRequest  $request
     * @return JsonResponse
     */
    public function active(ActiveShippingCarrierRequest $request): JsonResponse
    {
        $carriers = $this->carrierService->getActiveCarriers($request->type());

        $data = $carriers->map(function ($carrier) {
            return [
                'value' => $carrier->id,
                'label' => $carrier->getLocalizedName(),
                'code' => $carrier->code,
                'type' => $carrier->type,
                'tracking_url' => $carrier->tracking_url,
            ];
        })->values()->toArray();

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_carriers.list_retrieved',
            $data
        );
    }

    /**
     * 배송사 상세 조회
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $carrier = $this->carrierService->getCarrier($id);

        if (! $carrier) {
            return ResponseHelper::notFound(
                'messages.shipping_carriers.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_carriers.retrieved',
            new ShippingCarrierResource($carrier)
        );
    }

    /**
     * 배송사 수정
     *
     * @param  UpdateShippingCarrierRequest  $request
     * @param  int  $carrier
     * @return JsonResponse
     */
    public function update(UpdateShippingCarrierRequest $request, int $carrier): JsonResponse
    {
        try {
            $updatedCarrier = $this->carrierService->updateCarrier($carrier, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_carriers.updated',
                new ShippingCarrierResource($updatedCarrier)
            );
        } catch (ShippingCarrierOperationException $e) {
            // 도메인 규칙 위반 — 운영자에게 안내 가능한 상황이므로 기존 400 유지
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 배송사 삭제
     *
     * @param  int  $carrier
     * @return JsonResponse
     */
    public function destroy(int $carrier): JsonResponse
    {
        $carrierModel = $this->carrierService->getCarrier($carrier);

        if (! $carrierModel) {
            return ResponseHelper::notFound(
                'messages.shipping_carriers.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $result = $this->carrierService->deleteCarrier($carrier);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_carriers.deleted',
                $result
            );
        } catch (ShippingCarrierOperationException $e) {
            // 삭제 불가 사유(미존재/사용 중)를 전용 키로 안내 — 예외 메시지 원문을
            // 키 자리에 넘기면(종전 동작) 키 해석에 실패해 원문이 그대로 노출된다.
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 배송사 상태 토글
     *
     * @param  int  $carrier
     * @return JsonResponse
     */
    public function toggleStatus(int $carrier): JsonResponse
    {
        try {
            $updatedCarrier = $this->carrierService->toggleStatus($carrier);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_carriers.status_changed',
                new ShippingCarrierResource($updatedCarrier)
            );
        } catch (ShippingCarrierOperationException $e) {
            // 도메인 규칙 위반 — 운영자에게 안내 가능한 상황이므로 기존 400 유지
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }
}
