<?php

namespace App\Models\Analytics;

use Database\Factories\Analytics\AnalyticsSaltFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 일자별 솔트 모델
 *
 * 익명 모드에서 방문자를 구분하는 해시는 날마다 바뀌는 이 솔트로 만듭니다. 솔트가
 * 며칠 뒤 사라지면 어제의 해시와 오늘의 해시를 이어 붙일 방법이 없어져, 같은 사람을
 * 여러 날에 걸쳐 추적하는 일이 원리적으로 불가능해집니다.
 *
 * @property Carbon $salt_date
 * @property string $salt
 * @property Carbon $created_at
 */
class AnalyticsSalt extends Model
{
    /** @use HasFactory<AnalyticsSaltFactory> */
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'analytics_salts';

    /**
     * 기본 키
     *
     * @var string
     */
    protected $primaryKey = 'salt_date';

    /**
     * 기본 키 자동 증가 여부
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * 기본 키 타입
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * 타임스탬프 설정
     * created_at 만 쓴다 (솔트는 한 번 만들면 바뀌지 않는다)
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
        'salt_date',
        'salt',
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
            'salt_date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
