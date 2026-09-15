<?php

namespace Plugins\Sirsoft\Koreaexim\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Plugins\Sirsoft\Koreaexim\Enums\RateType;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Enums\RunTrigger;

/** 환율 갱신 실행 이력. */
class KoreaeximRateRun extends Model
{
    protected $table = 'koreaexim_rate_runs';

    protected $fillable = ['trigger', 'status', 'search_date', 'rate_type', 'base_currency', 'base_unit', 'request_count', 'applied_count', 'unchanged_count', 'skipped_count', 'message', 'raw_response', 'source_run_id', 'started_at', 'finished_at', 'triggered_by'];

    protected $casts = ['trigger' => RunTrigger::class, 'status' => RunStatus::class, 'rate_type' => RateType::class, 'search_date' => 'date', 'raw_response' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    /**
     * 실행에 속한 통화별 스냅샷 관계를 반환합니다.
     *
     * @return HasMany 처리 결과
     */
    public function items(): HasMany
    {
        return $this->hasMany(KoreaeximRateRunItem::class, 'run_id');
    }
}
