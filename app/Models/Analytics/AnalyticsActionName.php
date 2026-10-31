<?php

namespace App\Models\Analytics;

use Database\Factories\Analytics\AnalyticsActionNameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 이름 사전 모델
 *
 * 같은 URL·제목·파일명·검색어가 수천 번 반복되므로 원장에 문자열을 그대로 쌓지 않고
 * 이 사전에 한 번만 적은 뒤 액션은 id 로 가리킵니다. 정리 배치는 이 표를 지우지
 * 않습니다 — 집계 행이 이 id 로 라벨을 찾기 때문입니다.
 *
 * @property int $id
 * @property string $type
 * @property string $name
 * @property string $name_hash
 * @property int|null $url_prefix
 * @property Carbon $created_at
 */
class AnalyticsActionName extends Model
{
    /** @use HasFactory<AnalyticsActionNameFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_action_names';

    /**
     * 타임스탬프 설정
     * created_at 만 쓴다 (한 번 적으면 바뀌지 않는 사전이라 updated_at 이 없다)
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
        'type',
        'name',
        'name_hash',
        'url_prefix',
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
            'url_prefix' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
