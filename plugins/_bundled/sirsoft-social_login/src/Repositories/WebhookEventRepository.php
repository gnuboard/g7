<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\WebhookEventRepositoryInterface;

/**
 * 제공자 웹훅 수신 기록 저장소 구현.
 *
 * @since 1.0.0
 */
class WebhookEventRepository implements WebhookEventRepositoryInterface
{
    /**
     * 처음 받은 알림이면 기록하고, 같은 event_key 가 이미 있으면 null 을 반환합니다.
     *
     * 먼저 조회하고 넣는 방식은 동시에 온 두 알림이 둘 다 통과한다. UNIQUE 제약에 맡기고 위반을 중복으로 읽는다.
     *
     * @param  array<string, mixed>  $attributes  컬럼 값 (event_key 필수)
     * @return SocialLoginWebhookEvent|null 새 행, 중복이면 null
     */
    public function recordIfNew(array $attributes): ?SocialLoginWebhookEvent
    {
        try {
            return SocialLoginWebhookEvent::query()->create($attributes + ['received_at' => now(), 'attempts' => 0]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * ID 로 이벤트를 찾습니다.
     *
     * @param  int  $id  이벤트 ID
     * @return SocialLoginWebhookEvent|null 이벤트
     */
    public function find(int $id): ?SocialLoginWebhookEvent
    {
        return SocialLoginWebhookEvent::query()->find($id);
    }

    /**
     * 사건 키로 이벤트를 찾습니다 (중복 수신 응답).
     *
     * @param  string  $eventKey  사건 키
     * @return SocialLoginWebhookEvent|null 이벤트
     */
    public function findByEventKey(string $eventKey): ?SocialLoginWebhookEvent
    {
        return SocialLoginWebhookEvent::query()->where('event_key', $eventKey)->first();
    }

    /**
     * 확인 코드로 데이터 삭제 요청 이벤트를 찾습니다.
     *
     * @param  string  $code  확인 코드
     * @return SocialLoginWebhookEvent|null 이벤트
     */
    public function findByConfirmationCode(string $code): ?SocialLoginWebhookEvent
    {
        return SocialLoginWebhookEvent::query()->where('confirmation_code', $code)->first();
    }

    /**
     * 이벤트 값을 바꿉니다.
     *
     * @param  SocialLoginWebhookEvent  $event  이벤트
     * @param  array<string, mixed>  $values  컬럼 값
     * @return void 반환값 없음
     */
    public function update(SocialLoginWebhookEvent $event, array $values): void
    {
        $event->forceFill($values)->save();
    }

    /**
     * 조건에 맞는 이벤트를 최신순 페이지로 반환합니다 (진단 화면 — 요약 본문 포함).
     *
     * @param  array<string, mixed>  $filters  provider·status·event_type
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator 이벤트
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = SocialLoginWebhookEvent::query();

        foreach (['provider' => 'provider_key', 'status' => 'status', 'event_type' => 'event_type'] as $input => $column) {
            if (isset($filters[$input]) && $filters[$input] !== '') {
                $query->where($column, $filters[$input]);
            }
        }

        // 수신 이력은 보존 기간(기본 90일)으로 잘리고 화면은 앞쪽 몇 쪽만 넘긴다 — 깊은 OFFSET 이 생기지 않아 지연 조인은 쓰지 않는다.
        return $query->orderByDesc('id')->paginate($perPage, ['id', 'provider_key', 'event_type', 'provider_user_id', 'payload', 'status', 'attempts', 'error', 'received_at', 'processed_at']);
    }

    /**
     * 제공자별 마지막 수신 시각을 반환합니다.
     *
     * @return array<string, string|null> 제공자 키 => 시각(ISO 8601)
     */
    public function lastReceivedByProvider(): array
    {
        return SocialLoginWebhookEvent::query()
            ->selectRaw('provider_key, MAX(received_at) as last_received')
            ->groupBy('provider_key')
            ->pluck('last_received', 'provider_key')
            ->map(fn ($at) => $at !== null ? Carbon::parse($at)->toIso8601String() : null)
            ->all();
    }

    /**
     * 기간 안의 상태별 이벤트 수를 반환합니다.
     *
     * @param  \DateTimeInterface  $since  시작 시각
     * @return array<string, int> 상태 => 수
     */
    public function statusCountsSince(\DateTimeInterface $since): array
    {
        return SocialLoginWebhookEvent::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->where('received_at', '>=', $since)
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * 큐 실패 표에 남은 잡 수를 반환합니다.
     *
     * 잡 본문은 클래스 이름을 JSON 으로 담아 역슬래시가 두 겹이다 — 짧은 클래스 이름으로 찾는다.
     *
     * @param  string  $jobClass  잡 클래스 이름
     * @return int|null 수 (실패 표가 없으면 null)
     */
    public function failedQueueJobCount(string $jobClass): ?int
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        if (! Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)->where('payload', 'like', '%SocialLogin%'.class_basename($jobClass).'%')->count();
    }

    /**
     * 보존 기한이 지난 이벤트를 지웁니다.
     *
     * @param  \DateTimeInterface  $before  이 시각보다 오래된 행
     * @param  bool  $dryRun  true 면 세기만
     * @return int 대상 행 수
     */
    public function pruneBefore(\DateTimeInterface $before, bool $dryRun = false): int
    {
        $query = SocialLoginWebhookEvent::query()->where('received_at', '<', $before);
        if ($dryRun) {
            return $query->count();
        }

        $deleted = 0;
        $query->select('id')->chunkById(500, function ($rows) use (&$deleted) {
            $deleted += SocialLoginWebhookEvent::query()->whereIn('id', $rows->pluck('id'))->delete();
        });

        return $deleted;
    }
}
