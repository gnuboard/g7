<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Repositories;

use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioDispatchRepositoryInterface;

/**
 * 비즈뿌리오 발송 이력 Repository 구현체.
 */
class BizppurioDispatchRepository implements BizppurioDispatchRepositoryInterface
{
    use PaginatesWithDeferredJoin;

    /**
     * 발송 이력 1건을 생성합니다.
     *
     * @param  array<string, mixed>  $data  이력 데이터
     * @return BizppurioDispatch 생성된 이력
     */
    public function create(array $data): BizppurioDispatch
    {
        return BizppurioDispatch::create($data);
    }

    /**
     * refkey 로 발송 이력을 조회합니다.
     *
     * @param  string  $refkey  우리 부여 키
     * @return BizppurioDispatch|null 매칭된 이력 또는 null
     */
    public function findByRefkey(string $refkey): ?BizppurioDispatch
    {
        return BizppurioDispatch::query()->byRefkey($refkey)->first();
    }

    /**
     * 발송 이력의 속성을 갱신합니다.
     *
     * @param  BizppurioDispatch  $dispatch  대상 이력
     * @param  array<string, mixed>  $data  갱신 데이터
     * @return BizppurioDispatch 갱신된 이력
     */
    public function update(BizppurioDispatch $dispatch, array $data): BizppurioDispatch
    {
        $dispatch->fill($data)->save();

        return $dispatch;
    }

    /**
     * 필터·검색 조건으로 발송 이력을 페이지네이션 조회합니다.
     *
     * @param  array<string, mixed>  $filters  channel / status / date_from / date_to / keyword
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator<BizppurioDispatch>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = BizppurioDispatch::query();

        if (! empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('sent_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('sent_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('to_number', 'like', "%{$keyword}%")
                    ->orWhere('to_name', 'like', "%{$keyword}%")
                    ->orWhere('refkey', 'like', "%{$keyword}%");
            });
        }

        // 발송 이력은 발송할 때마다 쌓여 뒤쪽 페이지가 깊어진다. 지연 조인으로 OFFSET 구간에서는
        // 키 컬럼만 훑고, 본문·요청/webhook 페이로드 같은 넓은 컬럼은 이번 페이지 행에서만 읽는다.
        // 정렬 스펙 끝에 키 컬럼이 자동으로 덧붙어 동률(같은 시각 발송) 구간의 전순서도 보장된다.
        //
        // columns 를 좁히지 않은 이유: 이 목록을 소비하는 화면이 아직 없어 표시 컬럼 계약이
        // 확정되지 않았다. OFFSET 구간의 넓은 컬럼 읽기는 지연 조인으로 이미 사라졌으므로,
        // 컬럼 프루닝은 화면이 생겨 실제 사용 컬럼이 정해질 때 얹는다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: [['column' => 'created_at', 'direction' => 'desc']],
            perPage: $perPage,
        );
    }

    /**
     * refkey 로 dispatch 를 찾아 코어 알림 로그 id 를 연결합니다 (A-2 연결고리).
     *
     * @param  string  $refkey  발송 사이클에서 부여한 refkey
     * @param  int  $notificationLogId  코어 notification_logs.id
     * @return bool 연결 성공 여부(대상 없으면 false)
     */
    public function linkNotificationLog(string $refkey, int $notificationLogId): bool
    {
        $dispatch = BizppurioDispatch::query()->byRefkey($refkey)->first();

        if ($dispatch === null) {
            return false;
        }

        $dispatch->notification_log_id = $notificationLogId;
        $dispatch->save();

        return true;
    }

    /**
     * 코어 알림 로그 id 목록으로 dispatch 를 일괄 조회해 log id 키 맵으로 반환합니다 (A-2 표시).
     *
     * @param  array<int, int>  $notificationLogIds  코어 로그 id 목록
     * @return Collection<int, BizppurioDispatch> notification_log_id 를 키로 하는 dispatch 맵
     */
    public function findByNotificationLogIdsKeyed(array $notificationLogIds): Collection
    {
        if ($notificationLogIds === []) {
            return collect();
        }

        return BizppurioDispatch::query()
            ->whereIn('notification_log_id', $notificationLogIds)
            ->get()
            ->keyBy('notification_log_id');
    }

    /**
     * 코어 로그에 연결된 최근 dispatch 를 log id 키 맵으로 반환합니다 (A-2 표시, 타이밍 무관).
     *
     * @param  int  $limit  최근 dispatch 조회 상한
     * @return Collection<int, BizppurioDispatch> notification_log_id 를 키로 하는 dispatch 맵
     */
    public function recentLinkedKeyed(int $limit = 1000): Collection
    {
        return BizppurioDispatch::query()
            ->whereNotNull('notification_log_id')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->keyBy('notification_log_id');
    }
}
