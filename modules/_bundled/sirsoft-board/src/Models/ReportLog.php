<?php

namespace Modules\Sirsoft\Board\Models;

use App\Casts\AsUnicodeJson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Board\Database\Factories\ReportLogFactory;
use Modules\Sirsoft\Board\Enums\ReportReasonType;

class ReportLog extends Model
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return ReportLogFactory::new();
    }

    /**
     * 테이블명
     */
    protected $table = 'boards_report_logs';

    /**
     * 대량 할당 가능한 속성
     */
    protected $fillable = [
        'report_id',
        'reporter_id',
        'snapshot',
        'reason_type',
        'reason_detail',
        'metadata',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason_type' => ReportReasonType::class,
            'snapshot' => AsUnicodeJson::class,
            'metadata' => 'array',
        ];
    }

    /**
     * 케이스(boards_reports)와의 관계를 정의합니다.
     *
     * @return BelongsTo<Report, ReportLog>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    /**
     * 신고자와의 관계를 정의합니다 (탈퇴 시 NULL).
     *
     * @return BelongsTo<User, ReportLog>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
