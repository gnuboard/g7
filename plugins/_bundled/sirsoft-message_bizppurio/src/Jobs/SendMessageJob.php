<?php

// audit:allow job-generator-needs-scale-test 단건 발송 Job(대용량 데이터 생성/처리 아님) — 결과코드 재시도 판정 집합만 변경, 대량 회귀 무관

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchStatus;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioDispatchRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioApiClient;
use Throwable;

/**
 * 비즈뿌리오 메시지 발송 Job.
 *
 * MessagePayloadBuilder 가 조립한 발송 payload 를 큐에서 BizppurioApiClient 로
 * 전송한다. 서버 QUEUE_CONNECTION 을 따르며, 아래 재시도 정책을 적용한다(계획서 D10):
 *
 * - 429(Rate Limit): 예외를 던져 큐가 재시도. sync 환경은 최대 2초 대기 후 1회 재시도.
 * - 일시 오류(5003·5004·5005·9000·3011·3013): 예외를 던져 재시도.
 * - 영구 실패(2000·3001·3004·3006·3007·3009·9070·7436 등): 예외 없이 종료(재시도 안 함).
 *
 * 결과코드의 정식 분류·이력 갱신은 Phase 4(ResultCodeResolver/webhook)가 담당한다.
 * 이 Job 은 발송 수행과 재시도 판정까지의 골격을 제공한다.
 */
class SendMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 최대 재시도 횟수 (429·일시오류 대상). sync 환경 1회 재시도 포함 */
    public int $tries = 2;

    /**
     * 재시도 대상 일시 오류 결과코드.
     *
     * 공통 일시오류(5003/5004/5005/9000/3011/3013) + 알림톡 일시오류(7306 카카오 시스템오류·
     * 7307 처리지연·7421 타임아웃·7437 메시지 요청실패). 7305(성공 불확실)는 중복발송 위험으로 제외.
     * ResultCodeResolver::RETRYABLE_CODES 와 동일 집합을 유지한다.
     */
    private const RETRYABLE_CODES = ['5003', '5004', '5005', '9000', '3011', '3013', '7306', '7307', '7421', '7437'];

    /** HTTP 429 재시도 시 sync 환경 최대 대기(초) */
    private const SYNC_RETRY_MAX_WAIT_SECONDS = 2;

    /**
     * @param  array<string, mixed>  $payload  발송 payload (MessagePayloadBuilder 조립)
     * @param  string  $refkey  우리 부여 키 (이력 매칭용)
     */
    public function __construct(
        public readonly array $payload,
        public readonly string $refkey,
    ) {
        $this->afterCommit = true;
    }

    /**
     * 발송을 수행하고 결과코드에 따라 재시도/실패를 판정합니다.
     *
     * 발송 응답으로 이력(bizppurio_dispatches)을 갱신한다(Phase 4):
     *  - 성공: status=sent(발송 접수 완료, 최종 성공은 webhook 리포트가 확정) + messagekey 저장.
     *  - 재시도: 예외를 던져 큐 재시도(이력은 pending 유지).
     *  - 영구 실패: status=failed + result_code 기록.
     *
     * @param  BizppurioApiClient  $client  발송 API 클라이언트
     * @param  BizppurioDispatchRepositoryInterface  $dispatches  발송 이력 리포지토리
     *
     * @throws BizppurioApiException 일시 오류·429 시(큐 재시도 트리거)
     */
    public function handle(BizppurioApiClient $client, BizppurioDispatchRepositoryInterface $dispatches): void
    {
        $result = $client->sendMessage($this->payload);
        $code = (string) ($result['code'] ?? '');

        if ($client->isSuccess($result)) {
            // 발송 접수 성공 → sent(최종 성공/실패는 webhook 리포트가 확정) + messagekey 저장.
            $this->updateDispatch($dispatches, [
                'status' => DispatchStatus::Sent->value,
                'messagekey' => $result['messagekey'] ?? null,
            ]);

            return;
        }

        if (in_array($code, self::RETRYABLE_CODES, true)) {
            throw new BizppurioApiException(
                __('sirsoft-message_bizppurio::messages.error.send_retryable', ['code' => $code]),
                resultCode: $code,
            );
        }

        // 영구 실패: 재시도하지 않고 종료 + 실패 이력 기록.
        $this->updateDispatch($dispatches, [
            'status' => DispatchStatus::Failed->value,
            'result_code' => $code,
            'result_message' => $result['description'] ?? null,
        ]);

        Log::warning('비즈뿌리오 발송 영구 실패', [
            'refkey' => $this->refkey,
            'code' => $code,
            'description' => $result['description'] ?? null,
        ]);
    }

    /**
     * refkey 로 발송 이력을 조회해 갱신합니다 (이력이 없으면 무시).
     *
     * @param  BizppurioDispatchRepositoryInterface  $dispatches  발송 이력 리포지토리
     * @param  array<string, mixed>  $data  갱신 데이터
     */
    private function updateDispatch(BizppurioDispatchRepositoryInterface $dispatches, array $data): void
    {
        $dispatch = $dispatches->findByRefkey($this->refkey);
        if ($dispatch !== null) {
            $dispatches->update($dispatch, $data);
        }
    }

    /**
     * 재시도 사이 대기 시간(초)을 반환합니다.
     *
     * 429·일시오류 재시도 시 짧게 backoff 한다. sync 실행 시에도 이 값이 적용된다.
     *
     * @return int 대기 초
     */
    public function backoff(): int
    {
        return self::SYNC_RETRY_MAX_WAIT_SECONDS;
    }

    /**
     * 모든 재시도 소진 후 최종 실패 시 로그를 남기고 발송 이력을 실패로 마감합니다.
     *
     * 타임아웃·연결실패(ConnectionException)는 결과코드 없는 예외로 handle() 의 상태 갱신
     * 분기를 우회하므로, 재시도 소진 후 이력이 pending 인 채 방치된다. 여기서 refkey 로 이력을
     * 조회해 failed 로 마감한다(전송 실패라 최종 실패는 webhook 으로도 회수 불가). 이미 확정
     * (success/failed)된 이력은 webhook 이 먼저 결과를 확정한 경우이므로 덮어쓰지 않는다(멱등).
     *
     * @param  Throwable  $exception  마지막으로 발생한 예외
     */
    public function failed(Throwable $exception): void
    {
        $resultCode = $exception instanceof BizppurioApiException
            ? $exception->getResultCode()
            : null;

        Log::error('비즈뿌리오 발송 Job 최종 실패', [
            'refkey' => $this->refkey,
            'error' => $exception->getMessage(),
            'result_code' => $resultCode,
        ]);

        $dispatches = app(BizppurioDispatchRepositoryInterface::class);
        $dispatch = $dispatches->findByRefkey($this->refkey);

        // 이력 없음(비정상) 또는 이미 확정(webhook 선반영) → 마감 불필요(멱등)
        if ($dispatch === null || $dispatch->status->isFinal()) {
            return;
        }

        $dispatches->update($dispatch, [
            'status' => DispatchStatus::Failed->value,
            'result_code' => $resultCode,
            'result_message' => $exception->getMessage(),
        ]);
    }
}
