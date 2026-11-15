<?php

namespace Plugins\Sirsoft\Totp;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Services\SettingsService;
use Plugins\Sirsoft\Totp\Listeners\CleanTotpCredentialOnUserDelete;
use Plugins\Sirsoft\Totp\Listeners\CleanTotpCredentialOnUserWithdraw;
use Plugins\Sirsoft\Totp\Listeners\ConsolePasswordResetNoticeListener;
use Plugins\Sirsoft\Totp\Listeners\ExtendLoginPurposeChannels;
use Plugins\Sirsoft\Totp\Listeners\RegisterTotpProviderListener;
use Plugins\Sirsoft\Totp\Listeners\TotpAdminResetActivityLogListener;
use Plugins\Sirsoft\Totp\Listeners\TotpNotificationDataListener;
use Plugins\Sirsoft\Totp\Listeners\UserTotpEnrollmentInfoListener;

/**
 * 인증 앱(TOTP) 2단계 인증 플러그인.
 *
 * G7 코어 IDV 인프라의 `IdentityVerificationInterface` 를 구현해 RFC 6238 기반 인증 앱
 * 코드를 로그인 2단계 인증 수단으로 등록한다. 로그인 흐름 자체는 코어 소유이며 이
 * 플러그인은 수단만 더한다.
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
            'keywords' => ['totp', 'otp', '2fa', 'two-factor', 'authenticator', 'security'],
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
     * 플러그인 설정 기본값 반환
     *
     * @return array<string, mixed> 기본 설정값
     */
    public function getConfigValues(): array
    {
        return [
            'issuer' => '',
            'allow_mail_fallback' => true,
        ];
    }

    /**
     * 플러그인 설정 스키마 반환.
     *
     * 코드 자릿수·주기·해시 알고리즘(6 / 30초 / SHA1)은 **노출하지 않는다** — 모든 주요
     * 인증 앱이 이 조합만 QR 로 정확히 읽으므로, 열어 두면 운영자가 바꾼 뒤 "앱이 만든
     * 코드가 안 맞는다" 로만 드러나는 고장을 만든다.
     *
     * @return array<string, array<string, mixed>> 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            'issuer' => [
                'type' => 'string',
                'default' => '',
                'max' => 64,
                'label' => ['ko' => '발급자 표기', 'en' => 'Issuer Label'],
                'hint' => [
                    'ko' => '인증 앱 목록에 표시될 사이트 이름. 비워 두면 사이트 이름을 씁니다.',
                    'en' => 'Site name shown in the authenticator app. Defaults to the site name when empty.',
                ],
            ],
            'allow_mail_fallback' => [
                'type' => 'boolean',
                'default' => true,
                'label' => ['ko' => '미등록자 메일 폴백 허용', 'en' => 'Allow Mail Fallback'],
                'hint' => [
                    'ko' => '인증 앱을 등록하지 않은 회원은 이메일로 인증번호를 받습니다. 끄면 등록을 마칠 때까지 로그인이 차단되며, 구제는 관리자의 등록 해제 또는 totp:reset 명령입니다.',
                    'en' => 'Members without an authenticator app receive the code by email. Turning this off blocks their login until they enroll — recovery is an administrator reset or the totp:reset command.',
                ],
            ],
        ];
    }

    /**
     * 훅 리스너 목록 반환.
     *
     *  - RegisterTotpProviderListener: Provider 레지스트리 filter
     *  - ExtendLoginPurposeChannels: login purpose 의 allowed_channels 에 app 추가
     *  - CleanTotpCredentialOnUserWithdraw / ...OnUserDelete: 자격증명 파기
     *  - TotpAdminResetActivityLogListener: 관리자 강제 해제를 활동 로그에 기록
     *  - UserTotpEnrollmentInfoListener: 관리자 회원 상세 응답에 인증 앱 등록 여부(해제 권한자에게만)
     *  - ConsolePasswordResetNoticeListener: 터미널 비밀번호 재설정 결과에 인증 앱 초기화 안내(등록 회원만)
     *  - TotpNotificationDataListener: 등록·해제·백업 코드 재발급 보안 알림의 치환 변수와 기본 정의 복원(1.0.3)
     *
     * @return array<class-string> 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return [
            RegisterTotpProviderListener::class,
            ExtendLoginPurposeChannels::class,
            CleanTotpCredentialOnUserWithdraw::class,
            CleanTotpCredentialOnUserDelete::class,
            TotpAdminResetActivityLogListener::class,
            UserTotpEnrollmentInfoListener::class,
            ConsolePasswordResetNoticeListener::class,
            TotpNotificationDataListener::class,
        ];
    }

    /**
     * 플러그인이 제공하는 훅 정보 반환.
     *
     * 목록 형식(`name` 키)으로 선언한다 — 확장 문서 생성기가 이 형식에서 유형·설명·인자를 읽는다
     * (코어 PluginManager 는 키 형식도 같은 목록으로 정규화하므로 화면 표시는 같다).
     *
     * @return array<int, array<string, mixed>> 훅 정의 목록
     */
    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-totp.credential.admin_reset',
                'type' => 'action',
                'description' => [
                    'ko' => '관리자가 회원의 인증 앱 등록을 강제 해제한 직후 발행. 인자: (User $user, array $context)',
                    'en' => 'Fired right after an administrator forcibly removes a member authenticator app enrollment. Arguments: (User $user, array $context)',
                    'ja' => '管理者が会員の認証アプリ登録を強制解除した直後に発行。引数: (User $user, array $context)',
                ],
                'parameters' => [
                    'user' => 'User - 등록이 해제된 회원',
                    'context' => 'array - actor_id(해제한 관리자, 명령이면 null)·ip_address',
                ],
            ],
            [
                'name' => 'sirsoft-totp.credential.enrolled',
                'type' => 'action',
                'description' => [
                    'ko' => '회원이 첫 코드를 확인해 인증 앱 등록을 확정한 직후 발행(1.0.3). 인자: (User $user, array $context — ip_address·user_agent·occurred_at)',
                    'en' => 'Fired right after a member confirms authenticator app enrollment with the first code (1.0.3). Arguments: (User $user, array $context — ip_address, user_agent, occurred_at)',
                    'ja' => '会員が最初のコードを確認して認証アプリ登録を確定した直後に発行(1.0.3)。引数: (User $user, array $context — ip_address・user_agent・occurred_at)',
                ],
                'parameters' => [
                    'user' => 'User - 인증 앱을 등록한 회원',
                    'context' => 'array - ip_address·user_agent·occurred_at',
                ],
            ],
            [
                'name' => 'sirsoft-totp.credential.disabled',
                'type' => 'action',
                'description' => [
                    'ko' => '회원이 현재 코드를 확인해 인증 앱 등록을 직접 해제한 직후 발행(1.0.3). 인자: (User $user, array $context — ip_address·user_agent·occurred_at·by_admin=false)',
                    'en' => 'Fired right after a member removes their own authenticator app enrollment with a current code (1.0.3). Arguments: (User $user, array $context — ip_address, user_agent, occurred_at, by_admin=false)',
                    'ja' => '会員が現在のコードを確認して認証アプリ登録を自ら解除した直後に発行(1.0.3)。引数: (User $user, array $context — ip_address・user_agent・occurred_at・by_admin=false)',
                ],
                'parameters' => [
                    'user' => 'User - 인증 앱 등록을 해제한 회원',
                    'context' => 'array - ip_address·user_agent·occurred_at',
                ],
            ],
            [
                'name' => 'sirsoft-totp.backup_codes.regenerated',
                'type' => 'action',
                'description' => [
                    'ko' => '회원이 현재 코드를 확인해 백업 코드를 새로 발급한 직후 발행(1.0.3, 이전 코드는 전부 무효). 인자: (User $user, array $context — ip_address·user_agent·occurred_at)',
                    'en' => 'Fired right after a member regenerates backup codes with a current code (1.0.3; all previous codes become invalid). Arguments: (User $user, array $context — ip_address, user_agent, occurred_at)',
                    'ja' => '会員が現在のコードを確認してバックアップコードを再発行した直後に発行(1.0.3、以前のコードはすべて無効)。引数: (User $user, array $context — ip_address・user_agent・occurred_at)',
                ],
                'parameters' => [
                    'user' => 'User - 백업 코드를 재발급한 회원',
                    'context' => 'array - ip_address·user_agent·occurred_at',
                ],
            ],
        ];
    }

    /**
     * 인증 앱 보안 알림 정의 3종 (1.0.3, KVE-2026-3194).
     *
     * 등록·해제(본인 해제·관리자 강제 해제·서버 셸 `totp:reset`)·백업 코드 재발급을 계정 이메일로 알린다 —
     * 세션을 훔친 쪽이 2단계 인증을 바꿔도 계정 주인이 알아챌 수 있게 한다. 운영자는 관리자 알림 설정에서 끄고 켠다.
     * 치환 변수는 `TotpNotificationDataListener` 가 채운다.
     *
     * @return array<int, array<string, mixed>> 알림 정의 목록
     */
    public function getNotificationDefinitions(): array
    {
        $variables = [
            ['key' => 'name', 'description' => '수신자(계정 주인) 이름'],
            ['key' => 'app_name', 'description' => '사이트 이름'],
            ['key' => 'occurred_at', 'description' => '변경 시각'],
            ['key' => 'ip_address', 'description' => '요청 IP'],
            ['key' => 'actor_label', 'description' => '변경한 주체(본인·관리자·서버 셸)'],
            ['key' => 'security_url', 'description' => '인증 앱 설정 화면 주소'],
        ];
        $notice = [
            'ko' => "\n\n본인이 아니면 즉시 비밀번호를 바꾸고 관리자에게 알려 주세요.\n변경한 주체: {actor_label}\n요청 IP: {ip_address}\n\n설정 확인: {security_url}",
            'en' => "\n\nIf this was not you, change your password right away and tell the site administrator.\nChanged by: {actor_label}\nRequest IP: {ip_address}\n\nReview settings: {security_url}",
        ];
        $definition = fn (string $type, array $hooks, array $name, array $description, array $subject, array $lead) => [
            'type' => $type,
            'hook_prefix' => 'sirsoft-totp',
            'name' => $name,
            'description' => $description,
            'channels' => ['mail'],
            'hooks' => $hooks,
            'audience' => 'customer',
            'variables' => $variables,
            'templates' => [[
                'channel' => 'mail',
                'recipients' => [['type' => 'trigger_user']],
                'subject' => $subject,
                'body' => ['ko' => $lead['ko'].$notice['ko'], 'en' => $lead['en'].$notice['en']],
            ]],
        ];

        return [
            $definition(
                'totp_enrolled',
                ['sirsoft-totp.credential.enrolled'],
                ['ko' => '인증 앱 등록 알림', 'en' => 'Authenticator app enrolled'],
                ['ko' => '회원이 인증 앱 등록을 확정하면 계정 이메일로 알립니다.', 'en' => 'Notifies the account email when a member confirms authenticator app enrollment.'],
                ['ko' => '[{app_name}] 인증 앱이 등록되었습니다', 'en' => '[{app_name}] An authenticator app was enrolled'],
                ['ko' => '{name}님, {occurred_at}에 계정에 인증 앱이 등록되었습니다.', 'en' => 'Hello {name}, an authenticator app was enrolled on your account at {occurred_at}.'],
            ),
            $definition(
                'totp_disabled',
                ['sirsoft-totp.credential.disabled', 'sirsoft-totp.credential.admin_reset'],
                ['ko' => '인증 앱 해제 알림', 'en' => 'Authenticator app removed'],
                ['ko' => '인증 앱 등록이 해제되면(본인 해제·관리자 강제 해제 포함) 계정 이메일로 알립니다.', 'en' => 'Notifies the account email when authenticator app enrollment is removed (including removal by an administrator).'],
                ['ko' => '[{app_name}] 인증 앱이 해제되었습니다', 'en' => '[{app_name}] Your authenticator app was removed'],
                ['ko' => '{name}님, {occurred_at}에 계정의 인증 앱 등록이 해제되었습니다. 이제 로그인할 때 인증 앱 코드를 묻지 않습니다.', 'en' => 'Hello {name}, the authenticator app enrollment on your account was removed at {occurred_at}. Sign-in no longer asks for an authenticator code.'],
            ),
            $definition(
                'totp_backup_codes_regenerated',
                ['sirsoft-totp.backup_codes.regenerated'],
                ['ko' => '백업 코드 재발급 알림', 'en' => 'Backup codes regenerated'],
                ['ko' => '회원이 백업 코드를 새로 발급하면 계정 이메일로 알립니다.', 'en' => 'Notifies the account email when a member regenerates backup codes.'],
                ['ko' => '[{app_name}] 백업 코드가 재발급되었습니다', 'en' => '[{app_name}] Your backup codes were regenerated'],
                ['ko' => '{name}님, {occurred_at}에 계정의 백업 코드가 새로 발급되었습니다. 이전 백업 코드는 더 이상 쓸 수 없습니다.', 'en' => 'Hello {name}, new backup codes were issued for your account at {occurred_at}. The previous backup codes no longer work.'],
            ),
        ];
    }

    /**
     * 코어 IDV 인프라에 등록할 커스텀 purpose 목록.
     *
     * 이 플러그인은 코어 `login` purpose 에 수단을 더할 뿐 새 목적을 만들지 않는다.
     *
     * @return array<string, array<string, mixed>> 빈 배열
     */
    public function getIdentityPurposes(): array
    {
        return [];
    }

    /**
     * 코어 IDV 메시지 템플릿 시스템에 등록할 메시지 정의.
     *
     * 인증 앱은 서버가 아무것도 보내지 않으므로 메시지 템플릿을 쓰지 않는다.
     *
     * @return array<int, array<string, mixed>> 빈 배열
     */
    public function getIdentityMessages(): array
    {
        return [];
    }

    /**
     * 플러그인 권한 목록 반환 (계층 구조).
     *
     * 관리자 강제 해제는 코드 없이 남의 2단계 인증을 푸는 경로이므로 회원 관리 권한과
     * 분리한 전용 권한을 둔다 — 회원 정보를 볼 수 있다는 것과 그 사람의 2단계 인증을
     * 해제할 수 있다는 것은 다른 신뢰 수준이다.
     *
     * @return array<string, mixed> 권한 정의 배열
     */
    public function getPermissions(): array
    {
        return [
            'name' => [
                'ko' => '인증 앱 2단계 인증 (TOTP)',
                'en' => 'Authenticator App Two-Factor (TOTP)',
            ],
            'description' => [
                'ko' => '인증 앱 플러그인이 제공하는 권한',
                'en' => 'Permissions provided by the authenticator app plugin',
            ],
            'categories' => [
                [
                    'identifier' => 'credentials',
                    'name' => ['ko' => '인증 앱 자격증명', 'en' => 'Authenticator Credentials'],
                    'description' => [
                        'ko' => '회원의 인증 앱 등록에 대한 관리 권한',
                        'en' => 'Administrative permissions over member authenticator enrollment',
                    ],
                    'permissions' => [
                        [
                            'action' => 'delete',
                            'name' => ['ko' => '회원 인증 앱 등록 해제', 'en' => 'Remove Member Enrollment'],
                            'description' => [
                                'ko' => '단말·백업 코드를 모두 잃은 회원의 인증 앱 등록을 강제 해제 (코드 확인 없음)',
                                'en' => 'Force-remove a member\'s authenticator enrollment when both device and backup codes are lost (no code check)',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'sirsoft-totp.credentials'],
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
                'identifier' => 'sirsoft-totp.credentials',
                'name' => ['ko' => '인증 앱 운영자', 'en' => 'Authenticator Operator'],
                'description' => [
                    'ko' => '회원의 인증 앱 등록 해제 권한 (분실 구제 담당)',
                    'en' => 'May remove member authenticator enrollment (lost-device recovery)',
                ],
            ],
        ];
    }

    /**
     * 관리자 메뉴 정의 — **비운다**.
     *
     * 등록 화면은 코어「플러그인 관리 → 설정」 자동 경로가 연다.
     * 이 화면은 플러그인 환경설정이지 별도 메뉴로 세울 사항이 아니다 — 형제 13종이
     * 전부 `resources/layouts/admin/plugin_settings` 규약을 쓰고, 그 경로는
     * `AbstractPlugin::getSettingsLayoutSource()` 가 해석한다. gdpr 도 같은 이유로
     * 「설정」을 메뉴에서 빼고(주석: "코어 자동 경로 사용 (중복 제거)") 이력 화면만 남겼다.
     *
     * 빈 배열을 유지하는 이유는 activate() 가 이 값을 그대로 sync 하기 때문이다 —
     * 여기가 비면 재활성화 시 잔여 메뉴도 함께 회수된다.
     *
     * @return array<int, array<string, mixed>> 메뉴 정의 배열 (빈 배열)
     */
    public function getAdminMenus(): array
    {
        return [];
    }

    /**
     * 플러그인 활성화 — 관리자 메뉴를 등록합니다.
     *
     * syncMenuRecursive 는 upsert 라 재활성화해도 행이 중복되지 않는다.
     *
     * @return bool 활성화 성공 여부
     */
    public function activate(): bool
    {
        $helper = app(ExtensionMenuSyncHelper::class);
        $currentSlugs = [];

        foreach ($this->getAdminMenus() as $menuData) {
            $helper->syncMenuRecursive(
                $menuData,
                ExtensionOwnerType::Plugin,
                $this->getIdentifier(),
            );
            $currentSlugs[] = $menuData['slug'];
        }

        // 선언에서 빠진 메뉴를 회수한다 — upsert 만 돌리면 이전 판본이 심은 행이 남는다.
        // 1.0.0 이 등록하던 `sirsoft-totp-security` 가 실제로 그렇게 남아 있었다:
        // 설정 화면을 코어「플러그인 관리 → 설정」으로 옮기며 선언을 비웠는데,
        // 이미 활성인 사이트는 deactivate 를 거치지 않으므로 메뉴만 남아
        // 죽은 경로(/admin/plugins/sirsoft-totp/security)를 가리켰다.
        $helper->cleanupStaleMenus(
            ExtensionOwnerType::Plugin,
            $this->getIdentifier(),
            currentSlugs: $currentSlugs,
        );

        return true;
    }

    /**
     * 플러그인 비활성화 — 관리자 메뉴 행은 보존합니다.
     *
     * 비활성 플러그인의 메뉴는 사이드바·메뉴 관리 목록에서 자동으로 빠지므로 행을 지울
     * 필요가 없고, 지우면 운영자가 붙인 메뉴 역할이 사라진다(번들 플러그인 공통 관례).
     * 선언에서 빠진 잔재는 activate() 가, 전체 삭제는 uninstall() 이 맡는다.
     *
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(): bool
    {
        return true;
    }

    /**
     * 플러그인 설치.
     *
     * 로그인 목적의 프로바이더 매핑이 비어 있을 때만 `totp` 로 1회 초기화한다. 값이 이미
     * 있으면 운영자 선택이므로 덮어쓰지 않는다.
     *
     * purpose 키·provider id 는 리터럴을 쓴다 — install() 은 코어 PluginManager 가 이
     * 플러그인의 PSR-4 오토로드를 등록하기 전에 호출되므로 src/ 클래스 상수를 참조할 수 없다.
     *
     * 테이블은 드랍하지 않는다 (uninstall 주석 참조).
     *
     * @return bool 성공 여부
     */
    public function install(): bool
    {
        $settings = app(SettingsService::class);
        $key = 'identity.purpose_providers.login';

        if (! $settings->getSetting($key)) {
            $settings->setSetting($key, 'totp');
        }

        return true;
    }

    /**
     * 플러그인 삭제.
     *
     * install() 이 심은 매핑을 거둔다 — 우리가 심은 값(`totp`)일 때만. 운영자가 다른
     * 프로바이더로 바꿔 두었다면 그 선택은 건드리지 않는다.
     *
     * 자격증명 테이블은 드랍하지 않는다 — 플러그인을 잠시 껐다 켜는 운영에서 회원 전원의
     * 등록이 사라지면 재등록을 강요하게 된다. PII 정리는 사용자 이벤트 리스너 소관이다
     * (KCP 관행 준용).
     *
     * @return bool 성공 여부
     */
    public function uninstall(): bool
    {
        $settings = app(SettingsService::class);
        $key = 'identity.purpose_providers.login';

        if ($settings->getSetting($key) === 'totp') {
            $settings->setSetting($key, null);
        }

        // 메뉴 삭제 — 비활성화는 메뉴 행을 보존하므로 제거가 직접 지운다.
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(
            ExtensionOwnerType::Plugin,
            $this->getIdentifier(),
            currentSlugs: [],
        );

        return true;
    }
}
