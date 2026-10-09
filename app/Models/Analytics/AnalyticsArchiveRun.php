<?php

namespace App\Models\Analytics;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\ArchiveSkipReason;
use Database\Factories\Analytics\AnalyticsArchiveRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 배치 실행 이력 모델
 *
 * 집계·정리·익명화가 언제 돌았고 얼마나 처리했는지를 남깁니다. 배치가 조용히
 * 실패하면 화면 숫자가 그대로 멈추므로, 운영자가 진단 화면에서 이 표를 보고
 * 「마지막 집계가 몇 시간 전인지」를 판단할 수 있어야 합니다.
 *
 * @property int $id
 * @property ArchiveRunType $run_type
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string $trigger
 * @property ArchiveRunStatus $status
 * @property int $processed_visits
 * @property int $processed_actions
 * @property int $chunks
 * @property int $progress_percent
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 * @property int|null $triggered_by
 * @property Carbon $created_at
 */
class AnalyticsArchiveRun extends Model
{
    /** @use HasFactory<AnalyticsArchiveRunFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_archive_runs';

    /**
     * 타임스탬프 설정
     * 진행 상태는 started_at·finished_at 이 담는다
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'run_type',
        'period_start',
        'period_end',
        'trigger',
        'status',
        'processed_visits',
        'processed_actions',
        'chunks',
        'progress_percent',
        'started_at',
        'finished_at',
        'error',
        'triggered_by',
        'created_at',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string> 캐스팅 정의
     */
    protected function casts(): array
    {
        return [
            'run_type' => ArchiveRunType::class,
            'status' => ArchiveRunStatus::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'processed_visits' => 'integer',
            'processed_actions' => 'integer',
            'chunks' => 'integer',
            'progress_percent' => 'integer',
            'triggered_by' => 'integer',
        ];
    }

    /**
     * 화면·방송에 내보낼 배열을 만듭니다.
     *
     * 시각 다섯 칸은 원장과 같은 UTC 로 저장돼 있다. 화면은 모든 통계 시각을 사이트 표준시로
     * 읽으므로, 목록 API 와 진행률 방송이 같은 변환을 한곳에서 거쳐야 두 길이 다른 시각을 보이지
     * 않는다(방송만 UTC 원값을 실으면 그 값을 그리는 순간 시각이 어긋난다).
     *
     * @return array<string, mixed> 화면용 실행 이력
     */
    public function toScreenArray(): array
    {
        $row = $this->toArray();

        foreach (['period_start', 'period_end', 'started_at', 'finished_at', 'created_at'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = AnalyticsClock::toSiteDateTimeString(Carbon::parse($row[$key]));
            }
        }

        // 건너뛴 이유는 기록 당시 언어의 문장으로 저장돼 있다. 화면이 「건너뜀」 배지 곁에 이유를
        // 그리려면 어떤 이유인지(코드)와 보는 사람의 언어로 된 문장이 함께 있어야 한다 — 다른 집계에
        // 밀려 건너뛴 재집계를 화면이 그대로 알린다(7.1.0 실측 O-E5).
        $row['skip_reason'] = null;

        if (($row['status'] ?? null) === ArchiveRunStatus::Skipped->value && is_string($row['error'] ?? null)) {
            $reason = ArchiveSkipReason::fromMessage($row['error']);

            if ($reason !== null) {
                $row['skip_reason'] = $reason->value;
                $row['error'] = $reason->label();
            }
        }

        return $row;
    }
}
