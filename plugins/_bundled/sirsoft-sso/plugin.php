<?php

namespace Plugins\Sirsoft\Sso;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Plugins\Sirsoft\Sso\Bootstrap\Listeners;
use Plugins\Sirsoft\Sso\Bootstrap\Notifications;
use Plugins\Sirsoft\Sso\Bootstrap\Schedules;
use Plugins\Sirsoft\Sso\Bootstrap\SettingsSchema;
use Plugins\Sirsoft\Sso\Bootstrap\Tables;
use Plugins\Sirsoft\Sso\Mock\MockGateMiddleware;
use Plugins\Sirsoft\Sso\Http\Middleware\ScimBearerAuth;

/**
 * SSO 연동 플러그인.
 *
 * 회사 IdP(SAML 2.0, OpenID Connect, LDAP/Active Directory)로 로그인하고 SCIM 2.0 으로 회원을 만들고 지운다. 이 플러그인이
 * 하는 일은 「IdP 가 확인한 신원 → G7 회원」 결정과 코어 로그인 인계 코드 발급까지다. 잠금, 회원 상태, 2단계 인증, 비밀번호
 * 변경, 휴면 해제, 토큰 발급은 코어(`LoginHandoffService` → `AuthService::loginVerifiedUser`)가 비밀번호 로그인과 같게 처리한다.
 *
 * 훅 리스너, 명령, 스케줄, 표, 알림, 설정 스키마는 `Bootstrap\*` 이 영역별 파일을 모아 돌려준다 — 영역을 더할 때 이 파일을
 * 고치지 않는다.
 *
 * @since 1.0.0
 */
class Plugin extends AbstractPlugin
{
    /**
     * 플러그인 메타데이터 반환
     *
     * @return array<string, mixed> 메타데이터 배열
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'Sirsoft',
            'license' => 'MIT',
            'homepage' => 'https://sir.kr',
            'keywords' => ['sso', 'saml', 'oidc', 'openid-connect', 'ldap', 'active-directory', 'scim', 'enterprise'],
        ];
    }

    /**
     * 플러그인 의존성 반환
     *
     * @return array<string, array<string, string>> 의존성 배열
     */
    public function getDependencies(): array
    {
        return [
            'modules' => [],
            'plugins' => [],
        ];
    }

    /**
     * 플러그인 설정 기본값 반환.
     *
     * 정책과 IdP 설정은 플러그인 표(`sso_site_policies`, `sso_identity_providers`)에 플러그인 API 로 저장한다 — 코어 설정
     * 저장 경로로는 최고관리자 전용 검사를 403 으로 낼 수 없고 같은 값을 우회 저장할 수 있기 때문이다.
     *
     * @return array<string, mixed> 기본 설정값 (빈 배열)
     */
    public function getConfigValues(): array
    {
        return [];
    }

    /**
     * 플러그인 설정 스키마 반환 — `Bootstrap\SettingsSchema` 가 모은다(정책 값은 넣지 않는다).
     *
     * @return array<string, array<string, mixed>> 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return SettingsSchema::all();
    }

    /**
     * 훅 리스너 목록 반환 — `Bootstrap\Listeners` 가 영역별 목록을 합친다.
     *
     * @return array<class-string> 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return Listeners::all();
    }

    /**
     * 플러그인이 발행하는 훅 정보 반환.
     *
     * 인자에는 토큰, 비밀값, 어설션 XML, id_token 원문을 싣지 않는다.
     *
     * @return array<string, mixed> 훅 정의 배열
     */
    public function getHooks(): array
    {
        return [
            'sirsoft-sso.protocol.filter_registered' => [
                'type' => 'filter',
                'description' => [
                    'ko' => 'SSO 프로토콜 드라이버 목록을 거릅니다. 다른 확장이 새 프로토콜을 더할 수 있고 내장 키(oidc, saml, ldap) 덮어쓰기는 무시됩니다. 인자: (array $drivers)',
                    'en' => 'Filters the SSO protocol driver list. Other extensions may add protocols; overriding built-in keys (oidc, saml, ldap) is ignored. Arguments: (array $drivers)',
                    'ja' => 'SSO プロトコルドライバー一覧をフィルタします。他の拡張が新しいプロトコルを追加でき、組み込みキー(oidc, saml, ldap)の上書きは無視されます。引数: (array $drivers)',
                ],
            ],
            'sirsoft-sso.identity.filter_resolved' => [
                'type' => 'filter',
                'description' => [
                    'ko' => 'IdP 가 돌려준 신원을 회원 결정 전에 거릅니다. 인자: (ExternalIdentity $identity, IdentityProvider $idp)',
                    'en' => 'Filters the identity returned by the IdP before member resolution. Arguments: (ExternalIdentity $identity, IdentityProvider $idp)',
                    'ja' => 'IdP が返した身元を会員決定の前にフィルタします。引数: (ExternalIdentity $identity, IdentityProvider $idp)',
                ],
            ],
            'sirsoft-sso.jit.filter_registration_data' => [
                'type' => 'filter',
                'description' => [
                    'ko' => '처음 온 사람의 자동 가입 데이터를 거릅니다. 인자: (array $data, ExternalIdentity $identity, IdentityProvider $idp)',
                    'en' => 'Filters the just-in-time registration data for a first-time user. Arguments: (array $data, ExternalIdentity $identity, IdentityProvider $idp)',
                    'ja' => '初回ユーザーの自動登録データをフィルタします。引数: (array $data, ExternalIdentity $identity, IdentityProvider $idp)',
                ],
            ],
            'sirsoft-sso.account.after_resolve' => [
                'type' => 'action',
                'description' => [
                    'ko' => 'IdP 신원이 회원으로 결정된 직후(로그인 인계 전) 발행. outcome: login, jit, domain, verify, link. 인자: (User $user, SsoAccount $account, ExternalIdentity $identity, string $outcome)',
                    'en' => 'Fired right after an IdP identity is resolved to a member (before login handoff). outcome: login, jit, domain, verify, link. Arguments: (User $user, SsoAccount $account, ExternalIdentity $identity, string $outcome)',
                    'ja' => 'IdP の身元が会員に決定された直後(ログイン引き継ぎ前)に発行。outcome: login, jit, domain, verify, link。引数: (User $user, SsoAccount $account, ExternalIdentity $identity, string $outcome)',
                ],
            ],
            'sirsoft-sso.account.after_link' => [
                'type' => 'action',
                'description' => [
                    'ko' => '회원과 IdP 신원이 연결된 직후 발행. via: jit, domain, verify, admin, csv, scim. 인자: (SsoAccount $account, User $user, string $via)',
                    'en' => 'Fired right after a member is linked to an IdP identity. via: jit, domain, verify, admin, csv, scim. Arguments: (SsoAccount $account, User $user, string $via)',
                    'ja' => '会員と IdP の身元が連携された直後に発行。via: jit, domain, verify, admin, csv, scim。引数: (SsoAccount $account, User $user, string $via)',
                ],
            ],
            'sirsoft-sso.account.before_unlink' => [
                'type' => 'action',
                'description' => [
                    'ko' => '회원과 IdP 신원의 연결을 지우기 직전 발행. 인자: (SsoAccount $account, User $user, string $reason)',
                    'en' => 'Fired right before a member-IdP link is removed. Arguments: (SsoAccount $account, User $user, string $reason)',
                    'ja' => '会員と IdP の連携を削除する直前に発行。引数: (SsoAccount $account, User $user, string $reason)',
                ],
            ],
            'sirsoft-sso.account.after_unlink' => [
                'type' => 'action',
                'description' => [
                    'ko' => '회원과 IdP 신원의 연결을 지운 직후 발행. 인자: (SsoAccount $account, User $user, string $reason)',
                    'en' => 'Fired right after a member-IdP link is removed. Arguments: (SsoAccount $account, User $user, string $reason)',
                    'ja' => '会員と IdP の連携を削除した直後に発行。引数: (SsoAccount $account, User $user, string $reason)',
                ],
            ],
            'sirsoft-sso.account.after_deprovision' => [
                'type' => 'action',
                'description' => [
                    'ko' => '퇴사 처리(연결 비활성)를 마친 직후 발행. source: scim, periodic, login, admin. 인자: (SsoAccount $account, User $user, string $source)',
                    'en' => 'Fired right after deprovisioning (link deactivation). source: scim, periodic, login, admin. Arguments: (SsoAccount $account, User $user, string $source)',
                    'ja' => '退職処理(連携の無効化)を終えた直後に発行。source: scim, periodic, login, admin。引数: (SsoAccount $account, User $user, string $source)',
                ],
            ],
            'sirsoft-sso.account.after_reactivate' => [
                'type' => 'action',
                'description' => [
                    'ko' => '비활성 연결이 다시 활성이 된 직후 발행. 인자: (SsoAccount $account, User $user, string $source)',
                    'en' => 'Fired right after an inactive link becomes active again. Arguments: (SsoAccount $account, User $user, string $source)',
                    'ja' => '無効な連携が再び有効になった直後に発行。引数: (SsoAccount $account, User $user, string $source)',
                ],
            ],
            'sirsoft-sso.roles.after_sync' => [
                'type' => 'action',
                'description' => [
                    'ko' => 'IdP 그룹으로 역할을 맞춘 직후 발행. 인자: (User $user, array $added, array $removed, IdentityProvider $idp, ?string $skipped)',
                    'en' => 'Fired right after roles are synchronized from IdP groups. Arguments: (User $user, array $added, array $removed, IdentityProvider $idp, ?string $skipped)',
                    'ja' => 'IdP グループでロールを同期した直後に発行。引数: (User $user, array $added, array $removed, IdentityProvider $idp, ?string $skipped)',
                ],
            ],
            'sirsoft-sso.auth.after_sso_login' => [
                'type' => 'action',
                'description' => [
                    'ko' => 'SSO 로그인 인계 코드를 발급한 직후 발행. 인자: (User $user, SsoAccount $account)',
                    'en' => 'Fired right after an SSO login handoff code is issued. Arguments: (User $user, SsoAccount $account)',
                    'ja' => 'SSO ログイン引き継ぎコードを発行した直後に発行。引数: (User $user, SsoAccount $account)',
                ],
            ],
            'sirsoft-sso.auth.emergency_login' => [
                'type' => 'action',
                'description' => [
                    'ko' => 'SSO 강제 중 비상 계정이 비밀번호로 로그인한 직후 발행. 인자: (User $user)',
                    'en' => 'Fired right after an emergency account signs in with a password while SSO is enforced. Arguments: (User $user)',
                    'ja' => 'SSO 強制中に緊急アカウントがパスワードでログインした直後に発行。引数: (User $user)',
                ],
            ],
            'sirsoft-sso.handoff.filter_context' => [
                'type' => 'filter',
                'description' => [
                    'ko' => '코어 로그인 인계 문맥을 발급 직전에 거릅니다(세션 상한, 외부 세션 참조, 2단계 인증 생략). 인자: (array $context, User $user, SsoAccount $account, ExternalIdentity $identity, IdentityProvider $idp)',
                    'en' => 'Filters the core login handoff context right before issue (session cap, external session reference, two-factor skip). Arguments: (array $context, User $user, SsoAccount $account, ExternalIdentity $identity, IdentityProvider $idp)',
                    'ja' => 'コアのログイン引き継ぎ文脈を発行直前にフィルタします(セッション上限、外部セッション参照、2段階認証の省略)。引数: (array $context, User $user, SsoAccount $account, ExternalIdentity $identity, IdentityProvider $idp)',
                ],
            ],
            'sirsoft-sso.user.filter_attributes' => [
                'type' => 'filter',
                'description' => [
                    'ko' => '회원의 IdP 추가 속성(부서, 직급, 사번 등)을 다른 확장에 돌려주기 전에 거릅니다. 인자: (array $attributes, User $user)',
                    'en' => 'Filters a member\'s extra IdP attributes (department, title, employee number, ...) before they are returned to other extensions. Arguments: (array $attributes, User $user)',
                    'ja' => '会員の IdP 追加属性(部署、職位、社員番号など)を他の拡張に返す前にフィルタします。引数: (array $attributes, User $user)',
                ],
            ],
            'sirsoft-sso.auth.login_failed' => [
                'type' => 'action',
                'description' => [
                    'ko' => 'SSO 로그인 왕복이 실패한 직후 발행(시도 이력 1행과 같은 시점). outcome 은 failed(IdP·검증 실패) 또는 denied(정책 거절). 인자: (?IdentityProvider $idp, string $errorCode, string $intent, string $guard, string $outcome)',
                    'en' => 'Fired right after an SSO round trip fails (same moment as the attempt log row). outcome is failed (IdP or validation failure) or denied (policy rejection). Arguments: (?IdentityProvider $idp, string $errorCode, string $intent, string $guard, string $outcome)',
                    'ja' => 'SSO ログインの往復が失敗した直後に発行(試行履歴 1 行と同じ時点)。outcome は failed(IdP・検証の失敗)または denied(ポリシーによる拒否)。引数: (?IdentityProvider $idp, string $errorCode, string $intent, string $guard, string $outcome)',
                ],
            ],
            'sirsoft-sso.idp.failing' => [
                'type' => 'action',
                'description' => [
                    'ko' => '한 IdP 의 로그인 실패가 정책의 기준(기본 10분 안 5회)에 닿으면 하루 한 번 발행. 인자: (IdentityProvider $idp, int $failureCount, string $lastErrorCode)',
                    'en' => 'Fired once a day when an IdP\'s sign-in failures reach the policy threshold (default 5 within 10 minutes). Arguments: (IdentityProvider $idp, int $failureCount, string $lastErrorCode)',
                    'ja' => '1 つの IdP のログイン失敗がポリシーの基準(既定 10 分以内に 5 回)に達すると 1 日 1 回発行。引数: (IdentityProvider $idp, int $failureCount, string $lastErrorCode)',
                ],
            ],
            'sirsoft-sso.idp.cert_expiring' => [
                'type' => 'action',
                'description' => [
                    'ko' => 'IdP 인증서 만료가 다가오면(기본 30일, 7일 전) 발행. 인자: (IdentityProvider $idp, int $daysLeft)',
                    'en' => 'Fired when an IdP certificate is about to expire (default 30 and 7 days before). Arguments: (IdentityProvider $idp, int $daysLeft)',
                    'ja' => 'IdP 証明書の有効期限が近づくと(既定 30 日前、7 日前)発行。引数: (IdentityProvider $idp, int $daysLeft)',
                ],
            ],
            'sirsoft-sso.account.email_sync_conflict' => [
                'type' => 'action',
                'description' => [
                    'ko' => 'IdP 이메일로 회원 이메일을 바꾸려 했으나 다른 회원이 쓰고 있어 건너뛴 직후 발행. 인자: (User $user, SsoAccount $account, string $idpEmail)',
                    'en' => 'Fired right after an email sync from the IdP is skipped because another member uses the address. Arguments: (User $user, SsoAccount $account, string $idpEmail)',
                    'ja' => 'IdP のメールで会員メールを変更しようとしたが他の会員が使用中のためスキップした直後に発行。引数: (User $user, SsoAccount $account, string $idpEmail)',
                ],
            ],
            'sirsoft-sso.account.super_admin_deprovisioned' => [
                'type' => 'action',
                'description' => [
                    'ko' => '최고관리자의 회사 계정이 퇴사 처리돼 토큰만 회수한 직후 발행(최고관리자는 차단하지 않는다). 인자: (User $user, SsoAccount $account, string $source)',
                    'en' => 'Fired right after a super administrator\'s company account is deprovisioned and only their tokens are revoked (super administrators are not blocked). Arguments: (User $user, SsoAccount $account, string $source)',
                    'ja' => '最高管理者の会社アカウントが退職処理され、トークンのみ回収した直後に発行(最高管理者はブロックしない)。引数: (User $user, SsoAccount $account, string $source)',
                ],
            ],
            'sirsoft-sso.account.admin_withdraw_skipped' => [
                'type' => 'action',
                'description' => [
                    'ko' => '퇴사 처리된 관리자 계정이 탈퇴 기한을 넘겼지만 관리자라 자동 탈퇴를 건너뛴 직후 발행(계정당 1회). 인자: (User $user, SsoAccount $account)',
                    'en' => 'Fired right after the automatic withdrawal of a deprovisioned administrator account is skipped because it is an administrator (once per account). Arguments: (User $user, SsoAccount $account)',
                    'ja' => '退職処理された管理者アカウントが退会期限を過ぎたが、管理者のため自動退会をスキップした直後に発行(アカウントごとに1回)。引数: (User $user, SsoAccount $account)',
                ],
            ],
            'sirsoft-sso.auth.password_reset_redirected' => [
                'type' => 'action',
                'description' => [
                    'ko' => '회사 계정으로 로그인해야 하는 회원의 비밀번호 찾기 요청을 막고 안내 메일을 보내기 직전 발행. 인자: (User $user, ?IdentityProvider $idp)',
                    'en' => 'Fired right before a password reset request is blocked for a member who must sign in with a company account, and a guidance email is sent. Arguments: (User $user, ?IdentityProvider $idp)',
                    'ja' => '会社アカウントでログインすべき会員のパスワード再設定要求を止め、案内メールを送る直前に発行。引数: (User $user, ?IdentityProvider $idp)',
                ],
            ],
        ];
    }

    /**
     * 코어 IDV 인프라에 등록할 커스텀 purpose 목록 — 새 목적을 만들지 않는다(재인증은 코어 `sensitive_action` 에 수단을 더한다).
     *
     * @return array<string, array<string, mixed>> 빈 배열
     */
    public function getIdentityPurposes(): array
    {
        return [];
    }

    /**
     * 플러그인 권한 목록 반환 (계층 구조).
     *
     * 최고관리자 전용 항목(관리자 역할로 이어지는 매핑, 사이트 전체 강제, 비상 계정 지정)은 권한과 별개로 서비스가 `is_super` 를
     * 본다 — 권한은 위임할 수 있지만 그 항목은 위임하면 안 된다.
     *
     * @return array<string, mixed> 권한 정의 배열
     */
    public function getPermissions(): array
    {
        return [
            'name' => ['ko' => 'SSO 연동', 'en' => 'Single Sign-On'],
            'description' => [
                'ko' => 'SSO 연동 플러그인이 제공하는 권한',
                'en' => 'Permissions provided by the Single Sign-On plugin',
            ],
            'categories' => [
                [
                    'identifier' => 'settings',
                    'name' => ['ko' => 'SSO 설정', 'en' => 'SSO Settings'],
                    'description' => ['ko' => 'IdP 와 SSO 정책 설정', 'en' => 'IdP and SSO policy settings'],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => ['ko' => 'SSO 설정 조회', 'en' => 'View SSO Settings'],
                            'description' => ['ko' => 'IdP 목록, 연결 정보, 정책을 봅니다(비밀값은 가려짐)', 'en' => 'View IdPs, connection details and policies (secrets are masked)'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'update',
                            'name' => ['ko' => 'SSO 설정 변경', 'en' => 'Update SSO Settings'],
                            'description' => ['ko' => 'IdP 추가, 수정, 삭제, 연결 테스트, 정책 저장', 'en' => 'Add, edit, delete and test IdPs and save policies'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
                [
                    'identifier' => 'accounts',
                    'name' => ['ko' => '회원 연결', 'en' => 'Member Links'],
                    'description' => ['ko' => '회원과 회사 계정의 연결 관리', 'en' => 'Manage links between members and company accounts'],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => ['ko' => '회원 연결 열람', 'en' => 'View Member Links'],
                            'description' => ['ko' => '회원 연결 목록과 회원 상세의 SSO 정보를 봅니다', 'en' => 'View member links and the SSO section of member details'],
                            'type' => 'admin',
                            'roles' => ['admin', 'sirsoft-sso.manager'],
                        ],
                        [
                            'action' => 'unlink',
                            'name' => ['ko' => '회원 연결 강제 해제', 'en' => 'Force Unlink'],
                            'description' => ['ko' => '회원과 회사 계정의 연결을 관리자가 해제합니다', 'en' => 'Remove the link between a member and a company account'],
                            'type' => 'admin',
                            'roles' => ['admin', 'sirsoft-sso.manager'],
                        ],
                        [
                            'action' => 'provision',
                            'name' => ['ko' => '회원 연결 추가와 사전 등록', 'en' => 'Add Links and Pre-register'],
                            'description' => ['ko' => '연결 추가, 비밀번호 없는 회원 사전 등록, CSV 가져오기', 'en' => 'Add links, pre-register passwordless members and import CSV'],
                            'type' => 'admin',
                            'roles' => ['admin', 'sirsoft-sso.manager'],
                        ],
                    ],
                ],
                [
                    'identifier' => 'logs',
                    'name' => ['ko' => 'SSO 이력', 'en' => 'SSO Logs'],
                    'description' => ['ko' => '로그인 시도와 SCIM 수신 이력', 'en' => 'Login attempts and SCIM request history'],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => ['ko' => 'SSO 이력 조회', 'en' => 'View SSO Logs'],
                            'description' => ['ko' => '로그인 시도와 SCIM 수신 이력을 봅니다', 'en' => 'View login attempts and SCIM request history'],
                            'type' => 'admin',
                            'roles' => ['admin', 'sirsoft-sso.manager'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 플러그인 역할 목록 반환.
     *
     * @return array<int, array<string, mixed>> 역할 정의 배열
     */
    public function getRoles(): array
    {
        return [
            [
                'identifier' => 'sirsoft-sso.manager',
                'name' => ['ko' => 'SSO 운영자', 'en' => 'SSO Operator'],
                'description' => [
                    'ko' => '회원 연결 열람, 추가, 해제와 SSO 이력 조회 (설정 변경은 없음)',
                    'en' => 'View, add and remove member links and view SSO logs (no settings changes)',
                ],
            ],
        ];
    }

    /**
     * 관리자 메뉴 정의.
     *
     * 설정 화면은 코어「플러그인 관리 → 설정」 자동 경로(`/admin/plugins/sirsoft-sso/settings`)가 연다 — 메뉴로 세우지 않는다.
     * 메뉴는 설정이 아닌 운영 화면 둘(회원 연결, 이력)만 둔다.
     *
     * @return array<int, array<string, mixed>> 메뉴 정의 배열
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => ['ko' => 'SSO 연동', 'en' => 'Single Sign-On'],
                'slug' => 'sirsoft-sso',
                'icon' => 'fas fa-building-shield',
                'order' => 52,
                'children' => [
                    [
                        'name' => ['ko' => '회원 연결', 'en' => 'Member Links'],
                        'slug' => 'sirsoft-sso-accounts',
                        'url' => '/admin/plugins/sirsoft-sso/accounts',
                        'icon' => 'fas fa-link',
                        'order' => 1,
                    ],
                    [
                        'name' => ['ko' => '이력', 'en' => 'Logs'],
                        'slug' => 'sirsoft-sso-logs',
                        'url' => '/admin/plugins/sirsoft-sso/logs',
                        'icon' => 'fas fa-list',
                        'order' => 2,
                    ],
                ],
            ],
        ];
    }

    /**
     * 스케줄 정의 — `Bootstrap\Schedules` 가 영역별 목록을 합친다.
     *
     * @return array<int, array<string, mixed>> 스케줄 정의
     */
    public function getSchedules(): array
    {
        return Schedules::all();
    }

    /**
     * 알림 정의 — `Bootstrap\Notifications` 가 돌려준다.
     *
     * @return array<int, array<string, mixed>> 알림 정의
     */
    public function getNotificationDefinitions(): array
    {
        return Notifications::all();
    }

    /**
     * 플러그인이 만든 표 목록 — 자식 표가 먼저 온다(삭제 순서).
     *
     * @return array<int, string> 표 이름
     */
    public function getDynamicTables(): array
    {
        return Tables::all();
    }

    /**
     * 확장 미들웨어.
     *
     *  - 모의 IdP 라우트군(`web.plugins.sirsoft-sso.mock.*`)의 게이트 — 모의 라우트를 더해도 서브트리 패턴이 덮는다.
     *  - 쿠키 대기열 — api 그룹에는 쿠키 대기열 미들웨어가 없어, api 응답에서 처음 굽는 브라우저 묶음 쿠키(`g7_sso_bind`)가 빠진다.
     *    시작 티켓을 발급하는 플러그인 api 라우트와 코어 본인인증 요청 라우트(재확인 수단이 시작 주소를 만드는 곳)에만 붙인다.
     *  - SCIM 2.0 라우트군(`api.plugins.sirsoft-sso.scim.*`)의 IdP 별 Bearer 토큰 인증 — 토큰 해시 대조, SCIM 꺼짐이면 403, 수신 이력 기록.
     *
     * @return array<int, array{class: class-string, groups: array<int, string>, timing?: string, targets: array<int, string>}> 미들웨어 선언
     */
    public function getMiddleware(): array
    {
        return [
            [
                'class' => MockGateMiddleware::class,
                'groups' => ['web'],
                'timing' => 'after_core',
                'targets' => ['web.plugins.sirsoft-sso.mock.*'],
            ],
            [
                'class' => AddQueuedCookiesToResponse::class,
                'groups' => ['api'],
                'timing' => 'after_core',
                'targets' => ['api.plugins.sirsoft-sso.*', 'api.identity.challenges.request'],
            ],
            [
                'class' => ScimBearerAuth::class,
                'groups' => ['api'],
                'timing' => 'after_core',
                'targets' => ['api.plugins.sirsoft-sso.scim.*'],
            ],
        ];
    }

    /**
     * 플러그인 활성화 — 관리자 메뉴를 등록하고 선언에서 빠진 메뉴를 회수합니다.
     *
     * @return bool 활성화 성공 여부
     */
    public function activate(): bool
    {
        $helper = app(ExtensionMenuSyncHelper::class);
        $menus = $this->getAdminMenus();

        foreach ($menus as $menuData) {
            $helper->syncMenuRecursive($menuData, ExtensionOwnerType::Plugin, $this->getIdentifier());
        }

        $helper->cleanupStaleMenus(
            ExtensionOwnerType::Plugin,
            $this->getIdentifier(),
            currentSlugs: $helper->collectSlugsRecursive($menus),
        );

        return true;
    }

    /**
     * 플러그인 비활성화 — 관리자 메뉴 행은 보존합니다(비활성 플러그인 메뉴는 자동으로 숨는다).
     *
     * 비활성화되면 코어 필터 구독이 사라지므로 SSO 강제도 함께 풀린다(비밀번호 로그인 복귀) — IdP 가 없는데 강제만 남으면
     * 모두 잠기기 때문이다.
     *
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(): bool
    {
        return true;
    }

    /**
     * 플러그인 설치 — 별도 초기화가 없다(표는 마이그레이션이 만든다).
     *
     * install() 은 이 플러그인의 PSR-4 오토로드 등록 전에 불리므로 src/ 클래스를 참조하지 않는다.
     *
     * @return bool 성공 여부
     */
    public function install(): bool
    {
        return true;
    }

    /**
     * 플러그인 삭제 — 메뉴를 지운다. 표는 지우지 않는다(회원 정리는 회원 탈퇴, 삭제 리스너 소관).
     *
     * @return bool 성공 여부
     */
    public function uninstall(): bool
    {
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(
            ExtensionOwnerType::Plugin,
            $this->getIdentifier(),
            currentSlugs: [],
        );

        return true;
    }
}
