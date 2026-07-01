<?php

namespace Plugins\Sirsoft\VerificationKginicis\Models;

use App\Models\IdentityVerificationLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 이니시스 mTxId ↔ challenge_id 매핑 모델.
 *
 * STEP2 callback 이 challenge_id 를 echo 하지 않아 STEP3 응답의 mTxId 로 challenge 를
 * 역조회해야 한다. 본 모델이 그 매핑 인덱스를 담당한다.
 *
 * 코어 `identity_verification_logs` 테이블은 일체 수정하지 않는다.
 *
 * @since 1.0.0-beta.1
 */
class InicisChallengeMapping extends Model
{
    use HasFactory;

    /**
     * 모델과 연결되는 테이블명.
     */
    protected $table = 'inicis_challenge_mappings';

    /**
     * updated_at 컬럼 사용 안 함 (created_at 만 보관).
     */
    public $timestamps = false;

    /**
     * 대량 할당 가능한 속성.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'mtxid',
        'challenge_id',
    ];

    /**
     * 속성 캐스팅.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * 매핑된 코어 challenge 로그.
     *
     * @return BelongsTo<IdentityVerificationLog, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(IdentityVerificationLog::class, 'challenge_id');
    }
}
