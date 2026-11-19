<?php

namespace Plugins\Sirsoft\Sso\Listeners\Admin;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Support\MemberName;

/**
 * 이 플러그인의 발행 훅을 활동 로그로 남깁니다 (§6.19 — 감사 로그는 코어 활동 로그, D23).
 *
 * 발행 지점(흐름, 프로비저닝, 역할 동기화 서비스)은 훅만 쏘고 기록은 이 리스너가 맡는다. 활동 로그에는 IdP 키, 연결 방식, 사유만
 * 남기고 subject, 토큰, 어설션 원문은 싣지 않는다. 관리자 화면이 직접 하는 행위(IdP 추가, 정책 저장, 사전 등록, CSV)는 그 서비스가
 * 직접 쓴다.
 *
 * @since 1.0.0
 */
class ActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-sso.auth.after_sso_login' => ['method' => 'onLogin', 'priority' => 50, 'sync' => true],
            'sirsoft-sso.auth.login_failed' => ['method' => 'onLoginFailed', 'priority' => 50, 'sync' => true],
            'sirsoft-sso.account.after_link' => ['method' => 'onLinked', 'priority' => 50, 'sync' => true],
            'sirsoft-sso.account.after_unlink' => ['method' => 'onUnlinked', 'priority' => 50, 'sync' => true],
            'sirsoft-sso.account.after_deprovision' => ['method' => 'onDeprovisioned', 'priority' => 50, 'sync' => true],
            'sirsoft-sso.account.after_reactivate' => ['method' => 'onReactivated', 'priority' => 50, 'sync' => true],
            'sirsoft-sso.roles.after_sync' => ['method' => 'onRolesSynced', 'priority' => 50, 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * SSO 로그인 인계를 기록합니다.
     *
     * @param  mixed  $user  회원
     * @param  mixed  $account  연결
     * @return void 반환값 없음
     */
    public function onLogin(mixed $user = null, mixed $account = null): void
    {
        if ($user instanceof User && $account instanceof SsoAccount) {
            $this->write('sso.login', $user, $account->idp, ['account_id' => (int) $account->id]);
        }
    }

    /**
     * SSO 로그인 실패를 기록합니다 (회원이 정해지기 전이라 IdP 와 사유만).
     *
     * @param  mixed  $idp  IdP (없는 키면 null)
     * @param  string  $errorCode  오류 코드
     * @param  string  $intent  의도
     * @param  string  $guard  로그인 경로
     * @param  string  $outcome  failed, denied
     * @return void 반환값 없음
     */
    public function onLoginFailed(mixed $idp = null, string $errorCode = '', string $intent = 'login', string $guard = 'user', string $outcome = 'failed'): void
    {
        $label = $idp instanceof IdentityProvider ? $idp->localizedLabel() : '';

        $this->logActivity('sso.login_failed', [
            'description_key' => 'sirsoft-sso::activity_log.description.sso.login_failed',
            'description_params' => ['idp' => $label, 'code' => $errorCode],
            'properties' => [
                'idp_key' => $idp instanceof IdentityProvider ? $idp->key : null,
                'reason_code' => $errorCode,
                'intent' => $intent,
                'guard' => $guard,
                'outcome' => $outcome,
            ],
        ]);
    }

    /**
     * 연결을 기록합니다 — 자동 생성(JIT)은 「자동 가입」으로, 동의를 건너뛴 자동 가입은 그 사실도 남긴다.
     *
     * @param  mixed  $account  연결
     * @param  mixed  $user  회원
     * @param  string  $via  연결 방식 (jit, domain, verify, admin, csv, scim)
     * @return void 반환값 없음
     */
    public function onLinked(mixed $account = null, mixed $user = null, string $via = 'admin'): void
    {
        if (! $account instanceof SsoAccount || ! $user instanceof User) {
            return;
        }

        $idp = $account->idp;

        if ($via === 'jit') {
            $this->write('sso.jit_created', $user, $idp, ['via' => $via]);

            if ($idp instanceof IdentityProvider && ! $idp->require_consent) {
                $this->write('sso.consent_skipped', $user, $idp, ['via' => $via]);
            }

            return;
        }

        $this->write('sso.linked', $user, $idp, ['via' => $via], ['via' => $this->viaLabel($via)]);
    }

    /**
     * 연결 해제를 기록합니다.
     *
     * @param  mixed  $account  연결
     * @param  mixed  $user  회원
     * @param  string  $reason  사유 (user, admin, withdraw, delete)
     * @return void 반환값 없음
     */
    public function onUnlinked(mixed $account = null, mixed $user = null, string $reason = 'user'): void
    {
        if ($account instanceof SsoAccount && $user instanceof User) {
            $this->write('sso.unlinked', $user, $account->idp, ['reason' => $reason]);
        }
    }

    /**
     * 퇴사 처리를 기록합니다.
     *
     * @param  mixed  $account  연결
     * @param  mixed  $user  회원
     * @param  string  $source  scim, periodic, login, admin
     * @return void 반환값 없음
     */
    public function onDeprovisioned(mixed $account = null, mixed $user = null, string $source = 'scim'): void
    {
        if ($account instanceof SsoAccount && $user instanceof User) {
            $this->write('sso.deprovisioned', $user, $account->idp, ['source' => $source]);
        }
    }

    /**
     * 재활성을 기록합니다.
     *
     * @param  mixed  $account  연결
     * @param  mixed  $user  회원
     * @param  string  $source  scim, periodic, login
     * @return void 반환값 없음
     */
    public function onReactivated(mixed $account = null, mixed $user = null, string $source = 'login'): void
    {
        if ($account instanceof SsoAccount && $user instanceof User) {
            $this->write('sso.reactivated', $user, $account->idp, ['source' => $source]);
        }
    }

    /**
     * 역할 동기화를 기록합니다 (붙인 역할, 뗀 역할, 건너뛴 사유).
     *
     * 바뀐 역할 없이 건너뛰기만 했으면(최고관리자, 코어 등급 검사) 같은 활동으로 「건너뜀」 설명을 남긴다(§6.13, W13).
     *
     * @param  mixed  $user  회원
     * @param  array<int, mixed>  $added  붙인 역할 identifier
     * @param  array<int, mixed>  $removed  뗀 역할 identifier
     * @param  mixed  $idp  그룹을 알려 준 IdP (훅 4번째 인자)
     * @param  mixed  $skipped  건너뛴 사유 `super_admin`·`core_guard` 또는 null (훅 5번째 인자)
     * @return void 반환값 없음
     */
    public function onRolesSynced(mixed $user = null, array $added = [], array $removed = [], mixed $idp = null, mixed $skipped = null): void
    {
        $skipped = is_string($skipped) && $skipped !== '' ? $skipped : null;
        if (! $user instanceof User || ($added === [] && $removed === [] && $skipped === null)) {
            return;
        }

        $properties = [
            'added' => array_values(array_map('strval', $added)),
            'removed' => array_values(array_map('strval', $removed)),
        ];
        if ($skipped === null) {
            $this->write('sso.roles_synced', $user, $idp, $properties);

            return;
        }

        $reasonKey = 'sirsoft-sso::activity_log.skip_reason.'.$skipped;
        $reason = __($reasonKey) !== $reasonKey ? (string) __($reasonKey) : $skipped;
        $descriptionAction = $added === [] && $removed === [] ? 'sso.roles_sync_skipped' : 'sso.roles_synced_partly';
        $this->write('sso.roles_synced', $user, $idp, $properties + ['skipped' => $skipped], ['reason' => $reason], $descriptionAction);
    }

    /**
     * 회원 대상 기록 1건.
     *
     * @param  string  $action  활동
     * @param  User  $user  대상 회원
     * @param  mixed  $idp  IdP
     * @param  array<string, mixed>  $properties  추가 속성
     * @param  array<string, string>  $params  추가 설명 값
     * @param  string|null  $descriptionAction  설명 문구 키(없으면 활동 이름과 같은 키)
     * @return void 반환값 없음
     */
    private function write(string $action, User $user, mixed $idp, array $properties, array $params = [], ?string $descriptionAction = null): void
    {
        $this->logActivity($action, [
            'loggable' => $user,
            'description_key' => 'sirsoft-sso::activity_log.description.'.($descriptionAction ?? $action),
            'description_params' => [
                // 기록에 남기는 이름은 그 시점의 관리자 영역 표시 이름이다(이미 남은 기록은 바꾸지 않는다)
                'user_name' => MemberName::forRecord($user),
                'idp' => $idp instanceof IdentityProvider ? $idp->localizedLabel() : '',
            ] + $params,
            'properties' => [
                'target_user_id' => (int) $user->getKey(),
                'idp_key' => $idp instanceof IdentityProvider ? $idp->key : null,
            ] + $properties,
        ]);
    }

    /**
     * 연결 방식의 읽을 수 있는 이름.
     *
     * @param  string  $via  방식
     * @return string 이름
     */
    private function viaLabel(string $via): string
    {
        $key = 'sirsoft-sso::activity_log.via.'.$via;

        return __($key) !== $key ? (string) __($key) : $via;
    }
}
