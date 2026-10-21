<?php

namespace App\Services;

use App\Contracts\Notifications\GuestRecipientInterface;
use App\Contracts\Repositories\NotificationDeliveryRepositoryInterface;
use App\Enums\NotificationDeliveryClaim;
use App\Enums\NotificationDeliveryStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 알림 수신자별 중복 발송 방지 (#139)
 *
 * 기준은 **누락 방지 우선(at-least-once)** 이다.
 *
 * 1. 보내기 **전** 수신자를 선점한다(리스). 다른 사본이 같은 수신자를 동시에 보내지 못한다.
 * 2. 보낸 **뒤** 「보냈음」으로 확정한다. 재시도·재예약된 사본은 확정된 수신자를 건너뛴다.
 * 3. 발송이 실패하면 선점을 지운다 — 재시도가 다시 보낸다.
 * 4. 선점한 사본이 죽어 리스가 지나면 다른 사본이 넘겨받는다(누락 방지). 리스보다 오래 걸린
 *    발송 1건은 그 대가로 한 번 더 갈 수 있다.
 *
 * 저장소가 실패하면(업데이트 직후 표가 아직 없는 등) **fail-open** — 경고를 한 번 남기고 선점한 것으로 보고
 * 보낸다. 중복보다 누락이 더 나쁘다.
 *
 * 중복 방지 키: sha256(이벤트 ID ␟ 알림 유형 ␟ 채널 ␟ 수신자 키). 이벤트 ID 는 훅 리스너 잡 단위라
 * (HookContextCapture::eventId) 같은 사건의 재시도는 같은 키, 다른 사건은 다른 키가 된다.
 */
class NotificationDeliveryGuard
{
    /** 키 구분 문자 (단위 구분자 U+001F) */
    private const SEPARATOR = "\x1F";

    /** 리스 최소값(초) */
    private const MIN_LEASE_SECONDS = 10;

    /** 보관 일수 최소값 — 0 이하면 방금 보낸 행까지 지워 재시도 중복 방지가 풀린다 */
    private const MIN_RETENTION_DAYS = 1;

    /**
     * 저장소 실패 경고를 이 프로세스에서 이미 남겼는지
     */
    private static bool $failureReported = false;

    /**
     * @param  NotificationDeliveryRepositoryInterface  $deliveries  발송 기록 저장소
     */
    public function __construct(
        private readonly NotificationDeliveryRepositoryInterface $deliveries,
    ) {}

    /**
     * 수신자 키를 만듭니다 — 수신자 해석의 중복 제거 키와 같은 규칙입니다.
     *
     * @param  object  $recipient  수신자 (User·GuestNotifiable·그 밖의 모델)
     * @return string|null `user:{id}` · `guest:{hash}` · `{morph}:{id}` (식별할 수 없으면 null — 중복 방지 없이 보낸다)
     */
    public function recipientKey(object $recipient): ?string
    {
        if ($recipient instanceof GuestRecipientInterface && $recipient->isGuest()) {
            return (string) $recipient->getKey();
        }

        if ($recipient instanceof User) {
            return $recipient->getKey() !== null ? 'user:'.$recipient->getKey() : null;
        }

        if ($recipient instanceof Model && $recipient->getKey() !== null) {
            return $recipient->getMorphClass().':'.$recipient->getKey();
        }

        return null;
    }

    /**
     * 중복 방지 키를 만듭니다.
     *
     * @param  string  $eventId  이벤트 ID
     * @param  string  $notificationType  알림 유형
     * @param  string  $channel  채널
     * @param  string  $recipientKey  수신자 키
     * @return string sha256 16진 문자열 (64자)
     */
    public function deliveryKey(string $eventId, string $notificationType, string $channel, string $recipientKey): string
    {
        return hash('sha256', implode(self::SEPARATOR, [$eventId, $notificationType, $channel, $recipientKey]));
    }

    /**
     * 수신자를 선점합니다.
     *
     * @param  string  $eventId  이벤트 ID
     * @param  string  $notificationType  알림 유형
     * @param  string  $channel  채널
     * @param  string  $recipientKey  수신자 키
     * @return NotificationDeliveryClaim Acquired(보내라) · Done(이미 끝남) · InFlight(다른 사본이 보내는 중)
     */
    public function claim(string $eventId, string $notificationType, string $channel, string $recipientKey): NotificationDeliveryClaim
    {
        $key = $this->deliveryKey($eventId, $notificationType, $channel, $recipientKey);

        try {
            $attributes = [
                'delivery_key' => $key,
                'event_id' => mb_substr($eventId, 0, 64),
                'notification_type' => mb_substr($notificationType, 0, 100),
                'channel' => mb_substr($channel, 0, 50),
                'recipient_key' => mb_substr($recipientKey, 0, 191),
            ];

            if ($this->deliveries->insertClaim($attributes)) {
                return NotificationDeliveryClaim::Acquired;
            }

            $existing = $this->deliveries->findByKey($key);

            if ($existing === null) {
                // 넣으려던 순간과 읽는 순간 사이에 실패 해제로 지워졌다 — 한 번 더 선점해 본다.
                return $this->deliveries->insertClaim($attributes)
                    ? NotificationDeliveryClaim::Acquired
                    : NotificationDeliveryClaim::InFlight;
            }

            if ($existing->status->isDone()) {
                return NotificationDeliveryClaim::Done;
            }

            return $this->deliveries->takeOverStaleClaim($key, Carbon::now()->subSeconds($this->leaseSeconds()))
                ? NotificationDeliveryClaim::Acquired
                : NotificationDeliveryClaim::InFlight;
        } catch (Throwable $e) {
            $this->reportFailure('claim', $e);

            return NotificationDeliveryClaim::Acquired;
        }
    }

    /**
     * 보냈음으로 확정합니다.
     *
     * @param  string  $eventId  이벤트 ID
     * @param  string  $notificationType  알림 유형
     * @param  string  $channel  채널
     * @param  string  $recipientKey  수신자 키
     * @return void 반환값 없음
     */
    public function markSent(string $eventId, string $notificationType, string $channel, string $recipientKey): void
    {
        $this->complete($this->deliveryKey($eventId, $notificationType, $channel, $recipientKey), NotificationDeliveryStatus::Sent);
    }

    /**
     * 건너뛰기로 확정합니다 (탈퇴 회원·채널 꺼짐 등 — 다시 판정하지 않는다).
     *
     * @param  string  $eventId  이벤트 ID
     * @param  string  $notificationType  알림 유형
     * @param  string  $channel  채널
     * @param  string  $recipientKey  수신자 키
     * @return void 반환값 없음
     */
    public function markSkipped(string $eventId, string $notificationType, string $channel, string $recipientKey): void
    {
        $this->complete($this->deliveryKey($eventId, $notificationType, $channel, $recipientKey), NotificationDeliveryStatus::Skipped);
    }

    /**
     * 발송 실패로 선점을 풉니다 — 재시도가 이 수신자에게 다시 보냅니다.
     *
     * @param  string  $eventId  이벤트 ID
     * @param  string  $notificationType  알림 유형
     * @param  string  $channel  채널
     * @param  string  $recipientKey  수신자 키
     * @return void 반환값 없음
     */
    public function release(string $eventId, string $notificationType, string $channel, string $recipientKey): void
    {
        try {
            $this->deliveries->deleteClaim($this->deliveryKey($eventId, $notificationType, $channel, $recipientKey));
        } catch (Throwable $e) {
            $this->reportFailure('release', $e);
        }
    }

    /**
     * 보류했던 수신자 중 아직 다른 사본이 리스 안에서 보내는 중인 키를 돌려줍니다.
     *
     * @param  array<int, string>  $deliveryKeys  보류한 중복 방지 키
     * @return array<int, string> 아직 처리 중인 키 (저장소 실패 시 빈 배열)
     */
    public function stillInFlight(array $deliveryKeys): array
    {
        try {
            return $this->deliveries->findInFlightKeys($deliveryKeys, Carbon::now()->subSeconds($this->leaseSeconds()));
        } catch (Throwable $e) {
            $this->reportFailure('in_flight', $e);

            return [];
        }
    }

    /**
     * 리스 시간(초)을 반환합니다 — SMTP 1건의 최장 응답보다 길어야 합니다.
     *
     * @return int 리스 시간 (최소 10초)
     */
    public function leaseSeconds(): int
    {
        // audit:allow service-config-value-clamp 하한(도메인 상수) — 리스가 너무 짧으면 보내는 중인 수신자를 다른 사본이 넘겨받는다
        return max(self::MIN_LEASE_SECONDS, (int) config('notification.delivery.claim_lease_seconds', 60));
    }

    /**
     * 오래된 발송 기록을 지웁니다.
     *
     * @param  int|null  $days  보관 일수 (null 이면 설정값)
     * @return int 지운 행 수
     */
    public function prune(?int $days = null): int
    {
        // audit:allow service-config-value-clamp 하한(도메인 상수) — 상한이 아니라 「오늘 보낸 행은 남긴다」는 바닥값
        $days = max(self::MIN_RETENTION_DAYS, $days ?? (int) config('notification.delivery.retention_days', 7));

        return $this->deliveries->deleteOlderThan(Carbon::now()->subDays($days));
    }

    /**
     * 선점 행을 확정 상태로 바꿉니다.
     *
     * @param  string  $deliveryKey  중복 방지 키
     * @param  NotificationDeliveryStatus  $status  확정 상태
     * @return void 반환값 없음
     */
    private function complete(string $deliveryKey, NotificationDeliveryStatus $status): void
    {
        try {
            $this->deliveries->complete($deliveryKey, $status);
        } catch (Throwable $e) {
            $this->reportFailure('complete', $e);
        }
    }

    /**
     * 저장소 실패를 프로세스에 한 번만 경고합니다.
     *
     * @param  string  $operation  실패한 동작
     * @param  Throwable  $e  예외
     * @return void 반환값 없음
     */
    private function reportFailure(string $operation, Throwable $e): void
    {
        if (self::$failureReported) {
            return;
        }

        self::$failureReported = true;
        Log::warning('알림 중복 방지 저장소를 쓸 수 없어 중복 방지 없이 발송합니다 (누락 방지 우선)', [
            'operation' => $operation,
            'error' => $e->getMessage(),
        ]);
    }
}
