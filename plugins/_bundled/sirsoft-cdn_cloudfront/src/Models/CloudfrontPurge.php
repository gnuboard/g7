<?php

namespace Plugins\Sirsoft\CdnCloudfront\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 실패한 CloudFront 캐시 무효화 한 건 (재시도 대기열)
 *
 * 성공하면 행을 지운다. 남은 행 가운데 next_attempt_at 이 있는 것은 재시도 대기, 없는 것은
 * 시도 상한에 닿아 멈춘 건이다.
 *
 * @property int $id
 * @property string $caller_reference 첫 시도에 쓴 호출자 참조 (UUID, 재시도도 같은 값)
 * @property list<string> $paths 무효화할 CDN 경로
 * @property int $attempts 실패한 시도 횟수
 * @property string|null $last_error 마지막 실패 사유
 * @property Carbon|null $next_attempt_at 다음 재시도 시각
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CloudfrontPurge extends Model
{
    use HasUuids;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'cloudfront_purges';

    /**
     * 대량 할당 허용 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'caller_reference',
        'paths',
        'attempts',
        'last_error',
        'next_attempt_at',
    ];

    /**
     * UUID 를 채울 컬럼 — 기본 키(id)는 자동 증가 그대로 두고 호출자 참조만 비어 있으면 만들어 넣는다.
     *
     * @return list<string> 컬럼
     */
    public function uniqueIds(): array
    {
        return ['caller_reference'];
    }

    /**
     * 형 변환
     *
     * @return array<string, string> 컬럼 => 형
     */
    protected function casts(): array
    {
        return [
            'paths' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
        ];
    }
}
