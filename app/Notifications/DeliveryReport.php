<?php

namespace App\Notifications;

/**
 * 알림 1건의 채널별 발송 결과 보고서.
 *
 * 알림 발송 파이프라인은 채널마다 조용히 실패한다 — `GenericNotification::via()` 는 채널이
 * 꺼졌거나 준비되지 않았으면 그 채널을 빼고, `NotificationDispatcher::sendToNotifiable()` 은
 * 예외를 삼켜 null 을 돌려준다. 일반 알림에는 그것이 맞는 설계지만, 본인인증 메일처럼
 * "보내지 못했으면 사용자에게 실패로 알려야 하는" 발송은 결과를 알아야 한다.
 *
 * 호출자가 이 보고서를 알림에 붙이면(`GenericNotification::withDeliveryReport()`) 각 지점이
 * 결과를 기록한다. 붙이지 않으면 아무 동작도 바뀌지 않는다.
 *
 * 상태: sent(발송) / skipped(건너뜀 — 채널 꺼짐·미준비·템플릿 비활성 등) / failed(발송 중 예외).
 * 한 채널에 건너뜀·실패가 먼저 기록되면 뒤이은 성공 기록이 덮어쓰지 않는다 — 건너뛴 메일도
 * 파이프라인상으로는 "성공" 훅을 지나기 때문이다.
 */
final class DeliveryReport
{
    /** @var string 발송 */
    public const SENT = 'sent';

    /** @var string 건너뜀 */
    public const SKIPPED = 'skipped';

    /** @var string 실패 */
    public const FAILED = 'failed';

    /**
     * @var array<string, array{status: string, reason: string|null}> 채널별 결과
     */
    private array $results = [];

    /**
     * 채널 발송 성공을 기록합니다.
     *
     * 이미 건너뜀·실패로 기록된 채널은 바꾸지 않는다.
     *
     * @param  string  $channel  채널 식별자
     * @return void 반환값 없음
     */
    public function markSent(string $channel): void
    {
        if (isset($this->results[$channel])) {
            return;
        }

        $this->results[$channel] = ['status' => self::SENT, 'reason' => null];
    }

    /**
     * 채널 발송 건너뜀을 기록합니다.
     *
     * @param  string  $channel  채널 식별자
     * @param  string|null  $reason  건너뛴 사유 (사람이 읽는 문장)
     * @return void 반환값 없음
     */
    public function markSkipped(string $channel, ?string $reason): void
    {
        if (($this->results[$channel]['status'] ?? null) === self::FAILED) {
            return;
        }

        $this->results[$channel] = ['status' => self::SKIPPED, 'reason' => $reason];
    }

    /**
     * 채널 발송 실패를 기록합니다.
     *
     * @param  string  $channel  채널 식별자
     * @param  string|null  $error  오류 메시지
     * @return void 반환값 없음
     */
    public function markFailed(string $channel, ?string $error): void
    {
        $this->results[$channel] = ['status' => self::FAILED, 'reason' => $error];
    }

    /**
     * 채널이 실제로 발송되었는지 반환합니다.
     *
     * @param  string  $channel  채널 식별자
     * @return bool 발송 여부 (기록이 없으면 false — 채널이 후보에서 빠진 경우)
     */
    public function wasSent(string $channel): bool
    {
        return ($this->results[$channel]['status'] ?? null) === self::SENT;
    }

    /**
     * 채널의 결과 상태를 반환합니다.
     *
     * @param  string  $channel  채널 식별자
     * @return string|null sent | skipped | failed, 기록이 없으면 null
     */
    public function statusFor(string $channel): ?string
    {
        return $this->results[$channel]['status'] ?? null;
    }

    /**
     * 채널이 건너뛰어졌거나 실패한 사유를 반환합니다.
     *
     * @param  string  $channel  채널 식별자
     * @return string|null 사유 (성공·미기록이면 null)
     */
    public function reasonFor(string $channel): ?string
    {
        return $this->results[$channel]['reason'] ?? null;
    }

    /**
     * 전체 결과를 반환합니다.
     *
     * @return array<string, array{status: string, reason: string|null}> 채널별 결과
     */
    public function all(): array
    {
        return $this->results;
    }
}
