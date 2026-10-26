<?php

namespace Plugins\Sirsoft\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SSO 로그인 시도 이력 1행 (FK 없음 — 이력).
 *
 * @property int $id
 * @property int|null $idp_id
 * @property int|null $user_id
 * @property string $intent
 * @property string $guard
 * @property string $outcome
 * @property string|null $reason_code
 * @property array{signed: bool, not_on_or_after: string|null, audiences: array<int, string>}|null $response_summary 실패한 IdP 응답 요약(원문 없음)
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 *
 * @since 1.0.0
 */
class SsoLoginAttempt extends Model
{
    /** 결과 — 로그인 인계 코드 발급 */
    public const OUTCOME_HANDOFF = 'handoff';

    /** 결과 — 약관 동의 대기 */
    public const OUTCOME_PENDING = 'pending';

    /** 결과 — 기존 회원 연결 확인 대기 */
    public const OUTCOME_LINK_PENDING = 'link_pending';

    /** 결과 — 로그인한 회원에게 연결 추가 */
    public const OUTCOME_LINKED = 'linked';

    /** 결과 — 재확인 완료 */
    public const OUTCOME_REAUTH = 'reauth';

    /** 결과 — 관리자 테스트 완료 */
    public const OUTCOME_TEST = 'test';

    /** 결과 — 실패(검증, IdP 오류) */
    public const OUTCOME_FAILED = 'failed';

    /** 결과 — 거절(정책: 사전 등록 아님, 퇴사 등) */
    public const OUTCOME_DENIED = 'denied';

    /** 생성 시각만 둔다 */
    public const UPDATED_AT = null;

    /** @var string 테이블명 */
    protected $table = 'sso_login_attempts';

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = [
        'idp_id', 'user_id', 'intent', 'guard', 'outcome', 'reason_code', 'response_summary', 'ip_address', 'user_agent', 'created_at',
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
            'response_summary' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
