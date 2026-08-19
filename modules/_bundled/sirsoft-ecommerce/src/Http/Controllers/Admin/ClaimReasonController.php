<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Exceptions\ClaimReasonException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ActiveClaimReasonRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\IndexClaimReasonRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreClaimReasonRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateClaimReasonRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimReasonCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimReasonResource;
use Modules\Sirsoft\Ecommerce\Services\ClaimReasonService;

/**
 * 클레임 사유 관리 컨트롤러
 */
class ClaimReasonController extends AdminBaseController
{
    /**
     * @param  ClaimReasonService  $service  클레임 사유 서비스
     */
    public function __construct(
        protected ClaimReasonService $service,
    ) {}

    /**
     * 클레임 사유 목록 조회
     *
     * @param  IndexClaimReasonRequest  $request  목록 필터 요청
     * @return JsonResponse 클레임 사유 목록 응답
     */
    public function index(IndexClaimReasonRequest $request): JsonResponse
    {
        $reasons = $this->service->getAllReasons($request->filters());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.claim_reasons.list_retrieved',
            new ClaimReasonCollection($reasons)
        );
    }

    /**
     * 클레임 사유 생성
     *
     * @param  StoreClaimReasonRequest  $request  생성 요청
     * @return JsonResponse 생성된 클레임 사유 응답
     */
    public function store(StoreClaimReasonRequest $request): JsonResponse
    {
        $reason = $this->service->createReason($request->validated());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.claim_reasons.created',
            new ClaimReasonResource($reason),
            201
        );
    }

    /**
     * 활성 클레임 사유 목록 조회 (Select 옵션용)
     *
     * @param  ActiveClaimReasonRequest  $request  유형 필터 요청
     * @return JsonResponse 활성 클레임 사유 목록 응답 (Select 옵션용)
     */
    public function active(ActiveClaimReasonRequest $request): JsonResponse
    {
        $reasons = $this->service->getActiveReasons($request->type());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.claim_reasons.list_retrieved',
            new ClaimReasonCollection($reasons)
        );
    }

    /**
     * 클레임 사유 상세 조회
     *
     * @param  int  $id  사유 ID
     * @return JsonResponse 클레임 사유 상세 응답
     */
    public function show(int $id): JsonResponse
    {
        $reason = $this->service->getReason($id);

        if (! $reason) {
            return ResponseHelper::notFound(
                'messages.claim_reasons.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.claim_reasons.retrieved',
            new ClaimReasonResource($reason)
        );
    }

    /**
     * 클레임 사유 수정
     *
     * @param  UpdateClaimReasonRequest  $request  수정 요청
     * @param  int  $id  사유 ID
     * @return JsonResponse 수정된 클레임 사유 응답
     */
    public function update(UpdateClaimReasonRequest $request, int $id): JsonResponse
    {
        try {
            $reason = $this->service->updateReason($id, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claim_reasons.updated',
                new ClaimReasonResource($reason)
            );
        } catch (ClaimReasonException $e) {
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
     * 클레임 사유 삭제
     *
     * @param  int  $id  사유 ID
     * @return JsonResponse 삭제 결과 응답
     */
    public function destroy(int $id): JsonResponse
    {
        $reason = $this->service->getReason($id);

        if (! $reason) {
            return ResponseHelper::notFound(
                'messages.claim_reasons.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $result = $this->service->deleteReason($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claim_reasons.deleted',
                $result
            );
        } catch (ClaimReasonException $e) {
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
     * 클레임 사유 상태 토글
     *
     * @param  int  $id  사유 ID
     * @return JsonResponse 상태가 토글된 클레임 사유 응답
     */
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $reason = $this->service->toggleStatus($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claim_reasons.toggled',
                new ClaimReasonResource($reason)
            );
        } catch (ClaimReasonException $e) {
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
     * 사용자 선택 가능한 클레임 사유 목록 (User API용)
     *
     * @param  ActiveClaimReasonRequest  $request  유형 필터 요청
     * @return JsonResponse 사용자 선택 가능한 클레임 사유 목록 응답
     */
    public function userSelectableReasons(ActiveClaimReasonRequest $request): JsonResponse
    {
        $reasons = $this->service->getUserSelectableReasons($request->type());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.claim_reasons.list_retrieved',
            new ClaimReasonCollection($reasons)
        );
    }
}
