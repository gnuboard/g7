<?php

namespace Modules\Sirsoft\Board\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Sirsoft\Board\Database\Factories\ReportFactory;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Enums\ReportType;

class Report extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Service에서 주입되는 reportable 데이터 (Accessor 대체)
     */
    public ?array $reportableData = null;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return ReportFactory::new();
    }

    /**
     * 테이블명
     */
    protected $table = 'boards_reports';

    /**
     * 기본키
     */
    protected $primaryKey = 'id';

    /**
     * 타임스탬프 사용 여부
     */
    public $timestamps = true;

    /**
     * 대량 할당 가능한 속성
     */
    protected $fillable = [
        'board_id',
        'target_type',
        'target_id',
        'author_id',
        'status',
        'processed_by',
        'processed_at',
        'process_histories',
        'metadata',
        'last_reported_at',
        'last_activated_at',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => ReportType::class,
            'status' => ReportStatus::class,
            'process_histories' => 'array',
            'metadata' => 'array',
            'processed_at' => 'datetime',
            'last_reported_at' => 'datetime',
            'last_activated_at' => 'datetime',
        ];
    }

    /**
     * 게시판과의 관계를 정의합니다 (nullable).
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }

    /**
     * 작성자와의 관계를 정의합니다 (nullable).
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * 신고 로그(신고자 기록) 목록과의 관계를 정의합니다.
     *
     * @return HasMany<ReportLog>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ReportLog::class, 'report_id');
    }

    /**
     * 처리자와의 관계를 정의합니다 (nullable).
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * 신고 상태 라벨을 반환합니다.
     */
    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    /**
     * 신고 대상 타입 라벨을 반환합니다.
     */
    public function getTargetTypeLabelAttribute(): string
    {
        return $this->target_type->label();
    }

    /**
     * 특정 상태의 신고만 조회하는 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, ReportStatus|string $status)
    {
        if (is_string($status)) {
            $status = ReportStatus::from($status);
        }

        return $query->where('status', $status);
    }

    /**
     * 특정 타입의 신고만 조회하는 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, ReportType|string $type)
    {
        if (is_string($type)) {
            $type = ReportType::from($type);
        }

        return $query->where('target_type', $type);
    }

    /**
     * 특정 게시판의 신고만 조회하는 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByBoard($query, int $boardId)
    {
        return $query->where('board_id', $boardId);
    }

    /**
     * 날짜 범위로 신고를 조회하는 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
