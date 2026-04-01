<?php

namespace App\Traits;

use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 활동 로그 조회 기능을 제공하는 Trait
 *
 * 모델에서 use LogsActivity; 하여 활동 로그 관계 및 조회 기능을 사용할 수 있습니다.
 * 기록은 Log::channel('activity') → ActivityLogHandler를 통해 직접 수행합니다.
 */
trait LogsActivity
{
    /**
     * 이 모델과 관련된 활동 로그 관계
     *
     * @return MorphMany
     */
    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'loggable')
            ->latest();
    }

    /**
     * 이 모델의 활동 로그 목록을 조회합니다.
     *
     * @param array $filters 필터 조건
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 페이지네이션된 로그 목록
     */
    public function getActivityLogs(array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return app(ActivityLogService::class)->getLogsForModel($this, $filters);
    }
}
