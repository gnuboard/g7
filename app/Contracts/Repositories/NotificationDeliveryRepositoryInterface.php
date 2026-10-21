<?php

namespace App\Contracts\Repositories;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use Illuminate\Support\Carbon;

/**
 * 알림 수신자별 발송 기록 Repository 인터페이스 (#139)
 *
 * 발송 잡이 수신자를 선점(리스)하고, 보낸 뒤 확정하고, 실패하면 풀어 주는 데 쓴다. 판정 규칙은
 * NotificationDeliveryGuard 가 소유하고 이 인터페이스는 원자적 쓰기만 제공한다.
 */
interface NotificationDeliveryRepositoryInterface
{
    /**
     * 발송 중(sending) 선점 행을 넣습니다 — 같은 키가 이미 있으면 아무것도 하지 않습니다.
     *
     * @param  array<string, mixed>  $attributes  delivery_key·event_id·notification_type·channel·recipient_key
     * @return bool 새로 넣었으면 true (선점 성공)
     */
    public function insertClaim(array $attributes): bool;

    /**
     * 키로 발송 기록을 찾습니다.
     *
     * @param  string  $deliveryKey  중복 방지 키
     * @return NotificationDelivery|null 발송 기록
     */
    public function findByKey(string $deliveryKey): ?NotificationDelivery;

    /**
     * 리스가 지난 발송 중 행을 넘겨받습니다 — 선점 시각을 지금으로 바꾸고 시도 횟수를 올립니다.
     *
     * @param  string  $deliveryKey  중복 방지 키
     * @param  Carbon  $staleBefore  이 시각 이전에 선점된 행만 넘겨받는다
     * @return bool 넘겨받았으면 true (다른 사본이 먼저 넘겨받았으면 false)
     */
    public function takeOverStaleClaim(string $deliveryKey, Carbon $staleBefore): bool;

    /**
     * 발송 기록을 보냄·건너뜀으로 확정합니다.
     *
     * @param  string  $deliveryKey  중복 방지 키
     * @param  NotificationDeliveryStatus  $status  확정 상태 (Sent 또는 Skipped)
     * @return void 반환값 없음
     */
    public function complete(string $deliveryKey, NotificationDeliveryStatus $status): void;

    /**
     * 발송 중 선점 행을 지웁니다 — 확정된 행은 지우지 않습니다.
     *
     * @param  string  $deliveryKey  중복 방지 키
     * @return int 지운 행 수
     */
    public function deleteClaim(string $deliveryKey): int;

    /**
     * 주어진 키 중 아직 리스 안에서 발송 중인 키를 돌려줍니다.
     *
     * @param  array<int, string>  $deliveryKeys  중복 방지 키 목록
     * @param  Carbon  $freshAfter  이 시각 이후에 선점된 행만 「아직 처리 중」으로 본다
     * @return array<int, string> 아직 처리 중인 키
     */
    public function findInFlightKeys(array $deliveryKeys, Carbon $freshAfter): array;

    /**
     * 기준 시각보다 오래된 발송 기록을 배치로 지웁니다.
     *
     * @param  Carbon  $before  이 시각 이전에 만든 행을 지운다
     * @return int 지운 행 수
     */
    public function deleteOlderThan(Carbon $before): int;
}
