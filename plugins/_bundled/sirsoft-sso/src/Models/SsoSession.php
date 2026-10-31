<?php

namespace Plugins\Sirsoft\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SSO 로그인 1회 — G7 토큰과 IdP 세션(SAML SessionIndex, OIDC sid)을 잇는다.
 *
 * @property int $id
 * @property int $idp_id
 * @property int $user_id
 * @property int|null $token_id
 * @property string|null $external_session
 * @property string|null $name_id
 * @property Carbon|null $created_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property-read IdentityProvider|null $idp
 *
 * @since 1.0.0
 */
class SsoSession extends Model
{
    /** 로그아웃 */
    public const END_LOGOUT = 'logout';

    /** IdP 발 로그아웃 (SAML SLS, OIDC back-channel) */
    public const END_IDP_LOGOUT = 'idp_logout';

    /** 퇴사 처리 */
    public const END_DEPROVISIONED = 'deprovisioned';

    /** 회원 탈퇴, 삭제 */
    public const END_WITHDRAWN = 'withdrawn';

    /** updated_at 없음 */
    public const UPDATED_AT = null;

    /** @var string 테이블명 */
    protected $table = 'sso_sessions';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = ['idp_id', 'user_id', 'token_id', 'external_session', 'name_id', 'ended_at', 'end_reason'];

    /**
     * 캐스트 정의.
     *
     * @return array<string, string> 컬럼 => 캐스트
     */
    protected function casts(): array
    {
        return [
            'idp_id' => 'integer',
            'user_id' => 'integer',
            'token_id' => 'integer',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * 세션의 IdP.
     *
     * @return BelongsTo<IdentityProvider, $this> IdP 관계
     */
    public function idp(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class, 'idp_id');
    }
}
