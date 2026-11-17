<?php

namespace Plugins\Sirsoft\VerificationKginicis\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * KG이니시스 동일인 식별값 선점 행 (해시 1건 = 회원 1명).
 *
 * 가입 트랜잭션 안에서 `InicisIdentityClaimRepository::claim()` 이 넣고, 탈퇴·삭제 listener 가 지운다.
 *
 * @since 1.1.4
 */
class InicisIdentityClaim extends Model
{
    /**
     * 모델과 연결되는 테이블명.
     */
    protected $table = 'inicis_identity_claims';

    /**
     * updated_at 컬럼이 없다 — 선점은 바뀌지 않고 지워질 뿐이다.
     */
    public const UPDATED_AT = null;

    /**
     * 대량 할당 가능한 속성.
     *
     * @var array<int, string>
     */
    protected $fillable = ['hash_type', 'hash', 'user_id', 'created_at'];

    /**
     * 속성 캐스팅.
     *
     * @return array<string, string> 캐스팅 맵
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
