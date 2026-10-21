<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationDeliveryRepositoryInterface;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Repositories\Concerns\DeletesInBatches;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 알림 수신자별 발송 기록 Repository (#139)
 *
 * 선점·넘겨받기·확정·해제는 모두 조건부 단일 문장(INSERT IGNORE / 조건부 UPDATE·DELETE)이라
 * 동시에 도는 두 사본 중 하나만 성공한다.
 */
class NotificationDeliveryRepository implements NotificationDeliveryRepositoryInterface
{
    use DeletesInBatches;

    /**
     * {@inheritDoc}
     *
     * @param  array<string, mixed>  $attributes  delivery_key·event_id·notification_type·channel·recipient_key
     * @return bool 새로 넣었으면 true (선점 성공)
     */
    public function insertClaim(array $attributes): bool
    {
        $now = Carbon::now();

        return NotificationDelivery::query()->insertOrIgnore([
            ...$attributes,
            'status' => NotificationDeliveryStatus::Sending->value,
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $deliveryKey  중복 방지 키
     * @return NotificationDelivery|null 발송 기록
     */
    public function findByKey(string $deliveryKey): ?NotificationDelivery
    {
        return NotificationDelivery::query()
            ->select(['id', 'delivery_key', 'status', 'attempts', 'claimed_at', 'completed_at'])
            ->where('delivery_key', $deliveryKey)
            ->first();
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $deliveryKey  중복 방지 키
     * @param  Carbon  $staleBefore  이 시각 이전에 선점된 행만 넘겨받는다
     * @return bool 넘겨받았으면 true
     */
    public function takeOverStaleClaim(string $deliveryKey, Carbon $staleBefore): bool
    {
        return NotificationDelivery::query()
            ->where('delivery_key', $deliveryKey)
            ->where('status', NotificationDeliveryStatus::Sending->value)
            ->where('claimed_at', '<', $staleBefore)
            ->update([
                'claimed_at' => Carbon::now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => Carbon::now(),
            ]) === 1;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $deliveryKey  중복 방지 키
     * @param  NotificationDeliveryStatus  $status  확정 상태
     * @return void 반환값 없음
     */
    public function complete(string $deliveryKey, NotificationDeliveryStatus $status): void
    {
        NotificationDelivery::query()
            ->where('delivery_key', $deliveryKey)
            ->update([
                'status' => $status->value,
                'completed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $deliveryKey  중복 방지 키
     * @return int 지운 행 수
     */
    public function deleteClaim(string $deliveryKey): int
    {
        return NotificationDelivery::query()
            ->where('delivery_key', $deliveryKey)
            ->where('status', NotificationDeliveryStatus::Sending->value)
            ->delete();
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $deliveryKeys  중복 방지 키 목록
     * @param  Carbon  $freshAfter  이 시각 이후에 선점된 행만 본다
     * @return array<int, string> 아직 처리 중인 키
     */
    public function findInFlightKeys(array $deliveryKeys, Carbon $freshAfter): array
    {
        if ($deliveryKeys === []) {
            return [];
        }

        return NotificationDelivery::query()
            ->whereIn('delivery_key', array_values($deliveryKeys))
            ->where('status', NotificationDeliveryStatus::Sending->value)
            ->where('claimed_at', '>=', $freshAfter)
            ->pluck('delivery_key')
            ->all();
    }

    /**
     * {@inheritDoc}
     *
     * @param  Carbon  $before  이 시각 이전에 만든 행을 지운다
     * @return int 지운 행 수
     */
    public function deleteOlderThan(Carbon $before): int
    {
        return $this->deleteInBatches(
            NotificationDelivery::query()->where('created_at', '<', $before)
        );
    }
}
