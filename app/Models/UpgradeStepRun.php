<?php

namespace App\Models;

use App\Enums\UpgradeStepRunStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * 업그레이드 스텝 실행 대장 한 행.
 *
 * @property int $id
 * @property string $scope
 * @property string $identifier
 * @property string $version
 * @property string $step
 * @property string|null $fingerprint 스텝 내용 지문 (sha256, 클로저 스텝·지문 도입 전 행은 null)
 * @property UpgradeStepRunStatus $status
 * @property string|null $host
 *
 * @since 7.2.0
 */
class UpgradeStepRun extends Model
{
    /**
     * @var string 테이블 이름
     */
    protected $table = 'upgrade_step_runs';

    /**
     * @var array<int, string> 대량 할당 허용 컬럼
     */
    protected $fillable = ['scope', 'identifier', 'version', 'step', 'fingerprint', 'status', 'host', 'started_at', 'finished_at'];

    /**
     * 컬럼 형변환.
     *
     * @return array<string, string> 형변환 규칙
     */
    protected function casts(): array
    {
        return [
            'status' => UpgradeStepRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
