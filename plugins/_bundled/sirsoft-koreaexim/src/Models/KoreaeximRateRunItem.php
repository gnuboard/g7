<?php

namespace Plugins\Sirsoft\Koreaexim\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\Sirsoft\Koreaexim\Enums\ItemStatus;

/** 환율 갱신 통화 스냅샷. */
class KoreaeximRateRunItem extends Model
{
    protected $table = 'koreaexim_rate_run_items';

    public $timestamps = false;

    protected $fillable = ['run_id', 'currency_code', 'api_currency_unit', 'unit_divisor', 'source_rate', 'previous_rate', 'new_rate', 'status', 'currency_snapshot', 'api_fields'];

    protected $casts = ['status' => ItemStatus::class, 'currency_snapshot' => 'array', 'api_fields' => 'array', 'source_rate' => 'decimal:6', 'previous_rate' => 'decimal:8', 'new_rate' => 'decimal:8'];

    /**
     * 항목의 부모 실행 관계를 반환합니다.
     *
     * @return BelongsTo 처리 결과
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(KoreaeximRateRun::class, 'run_id');
    }
}
