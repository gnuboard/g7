<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Concerns;

use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;

/**
 * webhook replay 방어 — 동일 발송 이력에 리포트가 중복 도착하면 멱등 처리한다.
 *
 * 비즈뿌리오 URL PUSH 는 동일 리포트를 재전송할 수 있다. 발송 이력의 `reported_at`
 * 이 이미 채워져 있으면 리포트를 이미 반영한 것이므로, 상태를 다시 뒤집거나 잔액부족
 * 자체 알림을 재발송하지 않도록 조기 리턴한다(계획서 D13).
 */
trait PreventsReplayWebhook
{
    /**
     * 이 이력이 이미 리포트를 반영했는지(=replay) 확인합니다.
     *
     * @param  BizppurioDispatch  $dispatch  refkey 로 매칭된 발송 이력
     * @return bool true 면 중복 리포트 — 멱등 응답으로 처리해야 함
     */
    protected function wasAlreadyReported(BizppurioDispatch $dispatch): bool
    {
        return $dispatch->reported_at !== null;
    }

    /**
     * replay 감지를 통일된 형식으로 로깅합니다 (운영 모니터링용).
     *
     * @param  string  $refkey  우리 부여 키
     * @param  string|null  $resultCode  리포트 결과 코드
     */
    protected function logReplayDetected(string $refkey, ?string $resultCode): void
    {
        Log::info('비즈뿌리오 webhook: replay 감지 — 이미 리포트 반영됨, 멱등 응답', [
            'refkey' => $refkey,
            'result_code' => $resultCode,
        ]);
    }
}
