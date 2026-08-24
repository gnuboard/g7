<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioDispatchRepositoryInterface;

/**
 * 코어 알림 발송 이력 화면에 붙일 비즈뿌리오 발송 결과 조회 서비스 (A-2 표시 주입).
 *
 * 코어 화면이 현재 페이지의 코어 알림 로그 id 배열을 넘기면, 그 id 로 연결된 dispatch 를 일괄
 * 조회해(N+1 회피) 로그 id 를 키로 하는 결과 맵을 만든다. 결과 코드 사유·분류는 ResultCodeResolver
 * 를 재사용한다. 매칭되지 않는 로그(메일·DB 등 비-비즈뿌리오)는 맵에서 빠져 화면에서 빈 셀이 된다.
 *
 * 이 서비스는 표시용 파생 값(상태 라벨·`사유 (코드)`·잔액부족 여부·대체발송 라벨)만 계산하며,
 * 전화번호 등 민감정보는 다루지 않는다(A-2 결과 컬럼엔 전화번호를 노출하지 않음).
 */
class DispatchResultService
{
    /**
     * @param  BizppurioDispatchRepositoryInterface  $dispatches  dispatch 배치 조회
     * @param  ResultCodeResolver  $resultCodes  결과 코드 사유·분류 해석
     */
    public function __construct(
        private readonly BizppurioDispatchRepositoryInterface $dispatches,
        private readonly ResultCodeResolver $resultCodes,
    ) {}

    /**
     * 코어 알림 로그 id 배열에 대한 비즈뿌리오 결과 맵을 반환합니다.
     *
     * @param  array<int, int>  $notificationLogIds  코어 로그 id 목록(현재 페이지)
     * @return array<int, array<string, mixed>> notification_log_id → 결과 표시 데이터
     */
    public function resultsForLogIds(array $notificationLogIds): array
    {
        $dispatchMap = $this->dispatches->findByNotificationLogIdsKeyed($notificationLogIds);

        return $this->buildResultMap($dispatchMap);
    }

    /**
     * 최근 연결된 dispatch 결과를 log id 키 맵으로 반환합니다 (A-2 표시, 타이밍 무관).
     *
     * 화면이 파라미터 없이 조회해 row.id 로 매칭한다(kginicis test-map 선례). 목록 data_source
     * 로드 순서에 의존하지 않아 결과 컬럼이 빈 배열로 비는 문제를 원천 차단한다.
     *
     * @param  int  $limit  최근 dispatch 조회 상한
     * @return array<int, array<string, mixed>> notification_log_id → 결과 표시 데이터
     */
    public function recentResults(int $limit = 1000): array
    {
        return $this->buildResultMap($this->dispatches->recentLinkedKeyed($limit));
    }

    /**
     * dispatch 맵을 log id 키의 결과 표시 배열 맵으로 변환합니다.
     *
     * @param  \Illuminate\Support\Collection<int, BizppurioDispatch>  $dispatchMap  log id 키 dispatch 맵
     * @return array<int, array<string, mixed>> notification_log_id → 결과 표시 데이터
     */
    private function buildResultMap(\Illuminate\Support\Collection $dispatchMap): array
    {
        $results = [];

        foreach ($dispatchMap as $logId => $dispatch) {
            $results[(int) $logId] = $this->buildResult($dispatch);
        }

        return $results;
    }

    /**
     * dispatch 1건을 화면 표시용 결과 배열로 변환합니다.
     *
     * @param  BizppurioDispatch  $dispatch  연결된 발송 이력
     * @return array<string, mixed> 상태·결과 라벨·잔액부족·대체발송·검수 모드 표시 데이터
     */
    private function buildResult(BizppurioDispatch $dispatch): array
    {
        $code = $dispatch->result_code;
        $hasCode = $code !== null && $code !== '';

        return [
            'status' => $dispatch->status?->value,
            'status_label' => $dispatch->status?->label(),
            'result_code' => $code,
            // `사유 (코드)` 표시 라벨. 코드가 없으면(리포트 미수신) null → 화면은 상태 라벨만 표시.
            'result_label' => $hasCode ? $this->resultCodes->label($code) : null,
            'is_low_balance' => $hasCode && $this->resultCodes->isBalanceLow($code),
            'fallback_status' => $dispatch->fallback_status,
            'channel' => $dispatch->channel?->value,
            // 발송 시점 검수 모드 스냅샷. null=컬럼 신설 이전 이력(검수 여부 판단 불가 → 화면 미표시).
            'is_test_mode' => $dispatch->is_test_mode,
            // 실제 비즈뿌리오에 발송한 본문. 알림톡은 코어 notification_logs.body(대체발송용 코어
            // 템플릿 본문)와 다른 값(카카오 승인 템플릿 실제 내용)이라, 코어 "본문" 표시만으로는
            // 실제 발송 내용을 알 수 없다 — 화면이 채널별로 구분해 별도 노출할 수 있도록 포함.
            'content' => $dispatch->content,
        ];
    }
}
