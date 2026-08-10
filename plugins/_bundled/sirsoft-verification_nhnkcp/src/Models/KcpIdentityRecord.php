<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * NHN KCP 본인확인 PII 레코드 (사용자 1:1).
 *
 * - 코어 `identity_verification_logs` 가 hash/메타만 저장하는 SSoT 라면, 본 모델은
 *   KCP 결과조회 응답의 평문 PII (실명/생년월일/휴대폰/CI/DI) 를 Crypt 로 암호화하여 보관한다.
 * - 사용자가 본인확인을 다시 수행하면 `re_verified_at` 갱신과 함께 PII 가 UPSERT 된다.
 * - 사용자 탈퇴/삭제 시 `CleanKcpRecordOnUserWithdraw/Delete` listener 가 명시 삭제.
 *
 * @since 1.0.0
 */
class KcpIdentityRecord extends Model
{
    use HasFactory;

    /**
     * 모델과 연결되는 테이블명.
     */
    protected $table = 'nhnkcp_identity_records';

    /**
     * 대량 할당 가능한 속성.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'latest_log_id',
        'comm_id',
        'name_encrypted',
        'phone_encrypted',
        'birthday_encrypted',
        'di_encrypted',
        'di_hash',
        'ci_encrypted',
        'ci_hash',
        'gender',
        'is_foreigner',
        'is_adult',
        'verified_at',
        're_verified_at',
    ];

    /**
     * 속성 캐스팅.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_foreigner' => 'boolean',
            'is_adult' => 'boolean',
            'verified_at' => 'datetime',
            're_verified_at' => 'datetime',
        ];
    }

    /**
     * 레코드 소유 사용자.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 실명 평문 접근자 (Crypt 복호화).
     *
     * @return Attribute 복호화된 실명을 돌려주는 접근자
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->name_encrypted ? Crypt::decryptString($this->name_encrypted) : null,
        );
    }

    /**
     * 휴대폰 평문 접근자.
     *
     * @return Attribute 복호화된 휴대폰 번호를 돌려주는 접근자
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->phone_encrypted ? Crypt::decryptString($this->phone_encrypted) : null,
        );
    }

    /**
     * 생년월일 평문 접근자 (YYYYMMDD).
     *
     * @return Attribute 복호화된 생년월일을 돌려주는 접근자 (미수신 시 빈 문자열)
     */
    protected function birthday(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->birthday_encrypted ? Crypt::decryptString($this->birthday_encrypted) : null,
        );
    }

    /**
     * DI 평문 접근자.
     *
     * @return Attribute 복호화된 DI(사이트별 고유 식별값)를 돌려주는 접근자
     */
    protected function di(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->di_encrypted ? Crypt::decryptString($this->di_encrypted) : null,
        );
    }

    /**
     * CI 평문 접근자.
     *
     * @return Attribute 복호화된 CI(서비스 공통 고유 식별값)를 돌려주는 접근자
     */
    protected function ci(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ci_encrypted ? Crypt::decryptString($this->ci_encrypted) : null,
        );
    }
}
