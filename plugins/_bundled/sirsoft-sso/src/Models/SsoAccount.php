<?php

namespace Plugins\Sirsoft\Sso\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 회원과 IdP 신원의 연결 1행. 연결 키는 (IdP, subject) 다.
 *
 * 갱신 토큰은 encrypted 캐스트로 저장하고 직렬화에서 숨긴다.
 *
 * @property int $id
 * @property int $idp_id
 * @property int $user_id
 * @property string $subject
 * @property string|null $subject_format
 * @property string|null $email
 * @property string|null $display_name
 * @property string $linked_via
 * @property string|null $scim_external_id
 * @property bool $active
 * @property Carbon|null $deprovisioned_at
 * @property string|null $deprovision_reason
 * @property Carbon|null $blocked_user_at
 * @property string|null $refresh_token
 * @property Carbon|null $linked_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $periodic_checked_at
 * @property-read IdentityProvider|null $idp
 * @property-read User|null $user
 *
 * @since 1.0.0
 */
class SsoAccount extends Model
{
    /** @var array<int, string> 연결 방식 */
    public const VIA = ['jit', 'domain', 'verify', 'admin', 'csv', 'scim'];

    /** @var array<int, string> 로그인 성공으로 다시 켤 수 있는 퇴사 출처 — SCIM, 관리자가 끈 연결은 그쪽이 진실이다 */
    public const REACTIVATABLE_ON_LOGIN = ['periodic', 'login'];

    /** @var array<int, string> SCIM `active=true` 로 다시 켤 수 있는 퇴사 출처 — SCIM 이 끈 연결만. 주기 점검·로그인이 끈 연결은 그 경로가 다시 켠다 */
    public const REACTIVATABLE_BY_SCIM = ['scim'];

    /** @var string 테이블명 */
    protected $table = 'sso_accounts';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = [
        'idp_id', 'user_id', 'subject', 'subject_format', 'email', 'display_name', 'linked_via', 'scim_external_id',
        'active', 'deprovisioned_at', 'deprovision_reason', 'blocked_user_at', 'refresh_token', 'linked_at', 'last_login_at',
    ];

    /** @var array<int, string> 직렬화에서 숨길 비밀 컬럼 */
    protected $hidden = ['refresh_token'];

    /** @var array<string, mixed> 기본값 */
    protected $attributes = [
        'active' => true,
    ];

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
            'active' => 'boolean',
            'deprovisioned_at' => 'datetime',
            'blocked_user_at' => 'datetime',
            'refresh_token' => 'encrypted',
            'linked_at' => 'datetime',
            'last_login_at' => 'datetime',
            // 프로비저닝 마이그레이션(000026)이 더한 칸 — 쓰는 쪽은 프로비저닝 저장소.
            'periodic_checked_at' => 'datetime',
        ];
    }

    /**
     * 연결된 IdP.
     *
     * @return BelongsTo<IdentityProvider, $this> IdP 관계
     */
    public function idp(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class, 'idp_id');
    }

    /**
     * 연결된 회원.
     *
     * @return BelongsTo<User, $this> 회원 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
