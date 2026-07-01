<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\PayKginicis\Services\CbtCvsOperationsService;

class AdminCbtCvsOperationsController extends AdminBaseController
{
    public function __construct(
        private readonly CbtCvsOperationsService $operationsService,
    ) {
        parent::__construct();
    }

    /**
     * CBT 편의점 입금 운영 요약을 조회합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 응답
     */
    public function show(string $orderNumber): JsonResponse
    {
        $summary = $this->operationsService->summary($orderNumber);
        if ($summary === null) {
            return ResponseHelper::pluginError(
                'sirsoft-pay_kginicis',
                'messages.errors.order_not_found',
                404,
            );
        }

        return ResponseHelper::success('messages.success', $summary);
    }

    /**
     * 테스트 모드 CBT 편의점 입금 완료 NOTI 를 시뮬레이션합니다.
     *
     * @param  Request  $request  요청
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 응답
     */
    public function simulateNotify(Request $request, string $orderNumber): JsonResponse
    {
        $result = $this->operationsService->simulatePaidNotify($orderNumber, $request->ip());

        if (! ($result['ok'] ?? false)) {
            return $this->operationError($result);
        }

        return ResponseHelper::pluginSuccess(
            'sirsoft-pay_kginicis',
            'messages.cbt_cvs.simulate_success',
            $result['summary'] ?? null,
        );
    }

    /**
     * 입금 기한이 지난 CBT 편의점 결제를 만료 처리합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 응답
     */
    public function expire(string $orderNumber): JsonResponse
    {
        $result = $this->operationsService->expireOverdue($orderNumber);

        if (! ($result['ok'] ?? false)) {
            return $this->operationError($result);
        }

        return ResponseHelper::pluginSuccess(
            'sirsoft-pay_kginicis',
            'messages.cbt_cvs.expire_success',
            $result['summary'] ?? null,
        );
    }

    /**
     * CBT 편의점 결제의 로컬 상태 재확인 시각을 기록합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 응답
     */
    public function recheck(string $orderNumber): JsonResponse
    {
        $result = $this->operationsService->markRechecked($orderNumber);

        if (! ($result['ok'] ?? false)) {
            return $this->operationError($result);
        }

        return ResponseHelper::pluginSuccess(
            'sirsoft-pay_kginicis',
            'messages.cbt_cvs.recheck_success',
            $result['summary'] ?? null,
        );
    }

    private function operationError(array $result): JsonResponse
    {
        return ResponseHelper::pluginError(
            'sirsoft-pay_kginicis',
            (string) ($result['message_key'] ?? 'messages.errors.cbt_failed'),
            (int) ($result['status'] ?? 422),
        );
    }
}
