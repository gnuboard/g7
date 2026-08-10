<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Models;

use App\Models\IdentityVerificationLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KCP 거래 ↔ challenge_id 매핑 모델.
 *
 * KCP 콜백은 표준창에서 form POST 로 돌아오며, 결과조회 API 는 거래등록 시점의
 * `reg_cert_key` + `ordr_idxx` 를 요구한다. 본 모델이 그 보관/역조회를 담당한다.
 *
 * 코어 `identity_verification_logs` 테이블은 일체 수정하지 않는다.
 *
 * @since 1.0.0
 */
class KcpCertTransaction extends Model
{
    use HasFactory;

    /**
     * 모델과 연결되는 테이블명.
     */
    protected $table = 'nhnkcp_cert_transactions';

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
        'ordr_idxx',
        'reg_cert_key_hash',
        'reg_cert_key_encrypted',
        'challenge_id',
        'consumed_at',
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
            'consumed_at' => 'datetime',
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
