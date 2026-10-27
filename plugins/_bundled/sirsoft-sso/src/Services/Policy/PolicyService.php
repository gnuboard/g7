<?php

namespace Plugins\Sirsoft\Sso\Services\Policy;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Models\User;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\SitePolicyRepositoryInterface;

/**
 * SSO 사이트 정책 — 읽기, 저장(W5, W34, W38), 영향 미리보기.
 *
 * 정책은 코어 설정이 아니라 플러그인 표 1행에 저장한다. 최고관리자 전용 항목(사이트 전체 강제, 역할 강제, 비상 계정)은
 * 권한과 별개로 여기서 `is_super` 를 본다 — 권한은 위임할 수 있지만 그 항목은 위임하면 안 된다.
 *
 * @since 1.0.0
 */
class PolicyService
{
    use ResolvesActivityLogType;

    /** @var array<string, mixed> 기본값 (계획서 W16) */
    public const DEFAULTS = [
        'enforce_site' => false,
        'enforce_roles' => [],
        'enforce_linked' => false,
        'emergency_user_ids' => [],
        'enforcement_backup' => null,
        'auto_redirect_idp' => null,
        'session_max_minutes' => 0,
        'discovery_enabled' => true,
        'state_ttl_minutes' => 10,
        'pending_ttl_minutes' => 10,
        'attempts_retention_days' => 90,
        'scim_retention_days' => 90,
        'session_retention_days' => 30,
        'failure_alert_count' => 5,
        'failure_alert_window_minutes' => 10,
        'cert_expiry_notice_days' => [30, 7],
    ];

    /** @var array<int, string> 최고관리자만 바꿀 수 있는 키 (D26) */
    public const SUPER_ONLY_KEYS = ['enforce_site', 'enforce_roles', 'emergency_user_ids'];

    /** @var array<int, string> 저장 API 가 받는 키 (`enforcement_backup` 은 artisan 해제 명령만 쓴다) */
    public const EDITABLE_KEYS = [
        'enforce_site', 'enforce_roles', 'enforce_linked', 'emergency_user_ids', 'auto_redirect_idp', 'session_max_minutes',
        'discovery_enabled', 'state_ttl_minutes', 'pending_ttl_minutes', 'attempts_retention_days', 'scim_retention_days',
        'session_retention_days', 'failure_alert_count', 'failure_alert_window_minutes', 'cert_expiry_notice_days',
    ];

    /** 요청 안 캐시 — 요청 속성에 둔다(인스턴스에 두면 같은 컨트롤러 인스턴스가 다음 요청에서 낡은 정책을 본다) */
    private const REQUEST_CACHE_KEY = 'sirsoft-sso.site_policy';

    /**
     * @param  SitePolicyRepositoryInterface  $repository  정책 저장소
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     */
    public function __construct(
        private readonly SitePolicyRepositoryInterface $repository,
        private readonly IdentityProviderRepositoryInterface $idps,
    ) {}

    /**
     * 현재 정책을 기본값과 합쳐 반환합니다.
     *
     * 정책 행이 없으면 비상 계정을 가장 먼저 만든 최고관리자 1명으로 둔 기본 행을 만든다(설치 직후 첫 읽기). 표가 없으면(설치 전)
     * 기본값만 돌려준다.
     *
     * @return array<string, mixed> 정책
     */
    public function current(): array
    {
        $cached = request()?->attributes->get(self::REQUEST_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        if (! $this->repository->tableExists()) {
            return self::DEFAULTS;
        }

        $row = $this->repository->current();

        if ($row === null) {
            $superId = $this->repository->firstSuperAdminId();
            $initial = self::DEFAULTS;
            $initial['emergency_user_ids'] = $superId !== null ? [$superId] : [];
            $row = $this->repository->save($initial, null);
        }

        $policy = $this->normalize(array_merge(self::DEFAULTS, is_array($row->policy) ? $row->policy : []));
        request()?->attributes->set(self::REQUEST_CACHE_KEY, $policy);

        return $policy;
    }

    /**
     * 정책 값 하나를 읽습니다.
     *
     * @param  string  $key  키
     * @param  mixed  $default  없을 때 값
     * @return mixed 값
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $policy = $this->current();

        return array_key_exists($key, $policy) ? $policy[$key] : $default;
    }

    /**
     * 요청 안 캐시를 비웁니다 (저장 직후, 시험).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        request()?->attributes->remove(self::REQUEST_CACHE_KEY);
    }

    /**
     * 정책을 저장합니다.
     *
     * - 최고관리자가 아닌 행위자가 최고관리자 전용 키를 바꾸면 403(`super_only`).
     * - 비상 계정 0명 저장, 사이트 전체 강제를 켤 때 활성 IdP 0개 또는 비상 계정 0명이면 422(W5).
     * - 저장 뒤 새로 강제 대상이 된 회원(비상 계정 제외)의 비밀번호 로그인 토큰을 회수한다(W34).
     *
     * @param  array<string, mixed>  $changes  바꿀 값 (FormRequest 가 검증한 값)
     * @param  User  $actor  저장하는 관리자
     * @return array{policy: array<string, mixed>, revoked_tokens: int} 저장된 정책과 회수한 토큰 수
     *
     * @throws SsoException 거절일 때 (`super_only` 403, `emergency_required` 422, `site_enforce_requires_idp` 422)
     */
    public function update(array $changes, User $actor): array
    {
        $before = $this->current();
        $changes = array_intersect_key($changes, array_flip(self::EDITABLE_KEYS));
        $after = $this->normalize(array_merge($before, $changes));

        if (! $actor->isSuperAdmin()) {
            foreach (self::SUPER_ONLY_KEYS as $key) {
                if ($this->differs($before[$key] ?? null, $after[$key] ?? null)) {
                    throw new SsoException('super_only', $key, 403);
                }
            }
        }

        $after['emergency_user_ids'] = $this->repository->existingUserIds($after['emergency_user_ids']);

        if ($after['emergency_user_ids'] === []) {
            throw new SsoException('emergency_required', '', 422);
        }

        if ($after['enforce_site'] && $this->idps->enabled()->isEmpty()) {
            throw new SsoException('site_enforce_requires_idp', '', 422);
        }

        if ($after['auto_redirect_idp'] !== null && $this->idps->findByKey((string) $after['auto_redirect_idp']) === null) {
            $after['auto_redirect_idp'] = null;
        }

        $this->repository->save($after, (int) $actor->getKey());
        $this->flush();

        $domains = $this->repository->enforcedDomains();
        $revoked = $this->repository->revokeNewlyEnforcedPasswordTokens(
            $this->scope($before, $domains),
            $this->scope($after, $domains),
            $after['emergency_user_ids'],
        );

        $changed = array_values(array_filter(self::EDITABLE_KEYS, fn ($key) => $this->differs($before[$key] ?? null, $after[$key] ?? null)));

        if ($changed !== []) {
            $this->logActivity('sso.enforcement_changed', [
                'description_key' => 'sirsoft-sso::activity_log.description.sso.enforcement_changed',
                'description_params' => [],
                'properties' => [
                    'changed' => $changed,
                    'before' => array_intersect_key($before, array_flip($changed)),
                    'after' => array_intersect_key($after, array_flip($changed)),
                    'revoked_tokens' => $revoked,
                ],
            ]);
        }

        return ['policy' => $after, 'revoked_tokens' => $revoked];
    }

    /**
     * 정책 변경의 영향을 미리 셉니다 (저장하지 않는다).
     *
     * @param  array<string, mixed>  $changes  바꿀 값
     * @return array{password_users: int, password_sessions: int} 비밀번호 로그인을 못 하게 되는 회원 수, 로그아웃될 회원 수
     */
    public function impact(array $changes): array
    {
        $before = $this->current();
        $after = $this->normalize(array_merge($before, array_intersect_key($changes, array_flip(self::EDITABLE_KEYS))));
        $domains = $this->repository->enforcedDomains();
        $exclude = $this->repository->existingUserIds($after['emergency_user_ids']);

        return [
            'password_users' => $this->repository->countNewlyEnforcedWithPassword($this->scope($before, $domains), $this->scope($after, $domains), $exclude),
            'password_sessions' => $this->repository->countNewlyEnforcedPasswordSessions($this->scope($before, $domains), $this->scope($after, $domains), $exclude),
        ];
    }

    /**
     * IdP 의 「이 도메인 비밀번호 로그인 막기」 변화에 따른 비밀번호 토큰을 회수합니다 (W34 — IdP 저장 경로).
     *
     * @param  array<int, string>  $domainsBefore  저장 전 강제 도메인
     * @param  array<int, string>  $domainsAfter  저장 뒤 강제 도메인
     * @return int 회수한 토큰 수
     */
    public function revokeForDomainChange(array $domainsBefore, array $domainsAfter): int
    {
        $policy = $this->current();

        return $this->repository->revokeNewlyEnforcedPasswordTokens(
            $this->scope($policy, $domainsBefore),
            $this->scope($policy, $domainsAfter),
            $policy['emergency_user_ids'],
        );
    }

    /**
     * 강제 단위가 하나라도 켜져 있는지 확인합니다 (비상 계정 2단계 인증 필수 판정 — W29).
     *
     * @return bool 켜져 있으면 true
     */
    public function anyEnforcementOn(): bool
    {
        $policy = $this->current();

        return $policy['enforce_site'] || $policy['enforce_linked'] || $policy['enforce_roles'] !== []
            || $this->repository->enforcedDomains() !== [];
    }

    /**
     * 비상 계정 ID 목록.
     *
     * @return array<int, int> 회원 ID
     */
    public function emergencyUserIds(): array
    {
        return $this->current()['emergency_user_ids'];
    }

    /**
     * 비상 계정 회원 묶음 (정책 화면 표시).
     *
     * @param  array<int, int>  $ids  회원 ID
     * @return \Illuminate\Support\Collection<int, User> 회원
     */
    public function emergencyUsers(array $ids): \Illuminate\Support\Collection
    {
        return $this->repository->usersByIds($ids);
    }

    /**
     * artisan 해제 명령이 강제 값을 직접 저장합니다 (검사 없이 — 장애 복구 경로).
     *
     * @param  array<string, mixed>  $policy  정책 전체
     * @return void 반환값 없음
     */
    public function storeRaw(array $policy): void
    {
        $this->repository->save($this->normalize(array_merge(self::DEFAULTS, $policy)), null);
        $this->flush();
    }

    /**
     * 정책 값을 강제 범위 모양으로 바꿉니다.
     *
     * @param  array<string, mixed>  $policy  정책
     * @param  array<int, string>  $domains  강제 도메인
     * @return array{site: bool, roles: array<int, string>, linked: bool, domains: array<int, string>} 범위
     */
    private function scope(array $policy, array $domains): array
    {
        return [
            'site' => (bool) $policy['enforce_site'],
            'roles' => $policy['enforce_roles'],
            'linked' => (bool) $policy['enforce_linked'],
            'domains' => $domains,
        ];
    }

    /**
     * 정책 값의 형을 맞춥니다.
     *
     * @param  array<string, mixed>  $policy  정책
     * @return array<string, mixed> 정리한 정책
     */
    private function normalize(array $policy): array
    {
        $int = static fn ($value, int $min, int $max, int $fallback) => is_numeric($value) ? max($min, min($max, (int) $value)) : $fallback;

        $policy['enforce_site'] = (bool) $policy['enforce_site'];
        $policy['enforce_linked'] = (bool) $policy['enforce_linked'];
        $policy['discovery_enabled'] = (bool) $policy['discovery_enabled'];
        $policy['enforce_roles'] = array_values(array_unique(array_filter(array_map('strval', (array) $policy['enforce_roles']), fn ($v) => $v !== '')));
        $policy['emergency_user_ids'] = array_values(array_unique(array_filter(array_map('intval', (array) $policy['emergency_user_ids']), fn ($v) => $v > 0)));
        $policy['auto_redirect_idp'] = is_string($policy['auto_redirect_idp']) && $policy['auto_redirect_idp'] !== '' ? $policy['auto_redirect_idp'] : null;
        $policy['session_max_minutes'] = $int($policy['session_max_minutes'], 0, 10080, 0);
        $policy['state_ttl_minutes'] = $int($policy['state_ttl_minutes'], 1, 60, 10);
        $policy['pending_ttl_minutes'] = $int($policy['pending_ttl_minutes'], 1, 60, 10);
        $policy['attempts_retention_days'] = $int($policy['attempts_retention_days'], 1, 3650, 90);
        $policy['scim_retention_days'] = $int($policy['scim_retention_days'], 1, 3650, 90);
        $policy['session_retention_days'] = $int($policy['session_retention_days'], 1, 3650, 30);
        $policy['failure_alert_count'] = $int($policy['failure_alert_count'], 1, 1000, 5);
        $policy['failure_alert_window_minutes'] = $int($policy['failure_alert_window_minutes'], 1, 1440, 10);
        $days = array_values(array_unique(array_filter(array_map('intval', (array) $policy['cert_expiry_notice_days']), fn ($v) => $v > 0 && $v <= 365)));
        rsort($days);
        $policy['cert_expiry_notice_days'] = $days;

        return $policy;
    }

    /**
     * 두 값이 다른지 비교합니다 (배열은 순서 무시).
     *
     * @param  mixed  $a  이전 값
     * @param  mixed  $b  새 값
     * @return bool 다르면 true
     */
    private function differs(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            $sa = $a;
            $sb = $b;
            sort($sa);
            sort($sb);

            return $sa !== $sb;
        }

        return $a !== $b;
    }
}
