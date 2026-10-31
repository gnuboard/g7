<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\PayKginicis\Concerns\GuardsOrderScope;
use Plugins\Sirsoft\PayKginicis\Services\CbtCvsOperationsService;

class AdminCbtCvsOperationsController extends AdminBaseController
{
    use GuardsOrderScope;

    /**
     * 의존성을 주입받습니다.
     *
     * @param  CbtCvsOperationsService  $operationsService  해외 편의점 결제 운영 서비스
     */
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
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.read')) {
            return $denied;
        }

        $summary = $this->operationsService->summary($orderNumber);
        if ($summary === null) {
            return ResponseHelper::pluginError(
                'sirsoft-pay_kginicis',
                'messages.errors.order_not_found',
                404,
            );
        }

        return ResponseHelper::success('common.success', $summary);
    }

    /**
     * 테스트 모드 CBT 편의점 입금 완료 NOTI 를 시뮬레이션합니다.
     *
     * @param  Request  $request  요청
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 응답
     */
    // audit:allow controller-base-request-injection reason: 본문 입력을 읽지 않음 — 감사 로그용 ip() 만 참조 (검증 대상 필드 없음)
    public function simulateNotify(Request $request, string $orderNumber): JsonResponse
    {
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.update')) {
            return $denied;
        }

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
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.update')) {
            return $denied;
        }

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
        if ($denied = $this->orderScopeDenial($orderNumber, 'sirsoft-ecommerce.orders.update')) {
            return $denied;
        }

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

    /**
     * 운영 서비스의 실패 결과를 플러그인 오류 응답으로 바꿉니다.
     *
     * @param  array<string, mixed>  $result  운영 서비스 결과 (오류 키·상태 포함)
     * @return JsonResponse 오류 응답
     */
    private function operationError(array $result): JsonResponse
    {
        return ResponseHelper::pluginError(
            'sirsoft-pay_kginicis',
            (string) ($result['message_key'] ?? 'messages.errors.cbt_failed'),
            (int) ($result['status'] ?? 422),
        );
    }
}
