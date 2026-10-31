<?php

namespace Plugins\Sirsoft\SocialLogin;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use Plugins\Sirsoft\SocialLogin\Database\Seeders\ProviderSeeder;
use Plugins\Sirsoft\SocialLogin\Http\Middleware\MockGateMiddleware;
use Plugins\Sirsoft\SocialLogin\Listeners\ActivityLogListener;
use Plugins\Sirsoft\SocialLogin\Listeners\CleanAccountsOnUserDelete;
use Plugins\Sirsoft\SocialLogin\Listeners\CleanAccountsOnUserWithdraw;
use Plugins\Sirsoft\SocialLogin\Listeners\InjectSocialProvidersIntoUserResource;
use Plugins\Sirsoft\SocialLogin\Listeners\NotificationDataListener;
use Plugins\Sirsoft\SocialLogin\Listeners\RegisterMockProvidersListener;
use Plugins\Sirsoft\SocialLogin\Listeners\RegisterSocialReauthProviderListener;
use Plugins\Sirsoft\SocialLogin\Listeners\UserListSocialProviderFilter;
use Plugins\Sirsoft\SocialLogin\Listeners\UserListSocialProvidersColumn;
use Plugins\Sirsoft\SocialLogin\Listeners\ValidateSettingsListener;

/**
 * 소셜 로그인 플러그인.
 *
 * 카카오·네이버·구글·페이스북·일반 OIDC 제공자로 본인을 확인하고, 로그인은 코어 로그인 인계(`AuthService::loginVerifiedUser`
 * → 일회용 코드 → 로그인 화면의 `loginHandoff` 액션)로 넘긴다. 잠금·2단계 인증·비밀번호 변경·휴면 해제 같은 로그인 관문은
 * 코어가 비밀번호 로그인과 똑같이 맡는다 — 이 플러그인은 토큰을 직접 발급하지 않는다.
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
            'keywords' => ['social-login', 'oauth', 'oidc', 'kakao', 'naver', 'google', 'facebook'],
        ];
    }

    /**
     * 플러그인 설정 기본값 반환
     *
     * @return array<string, mixed> 기본 설정값
     */
    public function getConfigValues(): array
    {
        $values = [];
        foreach ($this->getSettingsSchema() as $key => $definition) {
            $values[$key] = $definition['default'];
        }

        return $values;
    }

    /**
     * 플러그인 설정 스키마 반환.
     *
     * 제공자별 자격증명은 설정 키가 아니라 제공자 테이블에 둔다(제공자마다 행이 다르고 커스텀 제공자가 늘어나므로).
     * 여기에는 사이트 전체 정책만 둔다. 어느 값도 공개 설정(frontend_schema)에 내보내지 않는다.
     *
     * @return array<string, array<string, mixed>> 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            'email_conflict_policy' => [
                'type' => 'string', 'default' => 'verify_link',
                'label' => ['ko' => '같은 이메일의 기존 회원', 'en' => 'Existing Member With Same Email'],
                'hint' => [
                    'ko' => '소셜 계정 이메일이 기존 회원과 같을 때: 기존 계정 확인 뒤 연결(권장), 제공자가 검증한 이메일이면 바로 연결, 연결하지 않음.',
                    'en' => 'When the social email matches an existing member: link after confirming the account (recommended), link at once when the provider verified it, or never link.',
                ],
            ],
            'extra_info_mode' => [
                'type' => 'string', 'default' => 'when_missing',
                'label' => ['ko' => '가입 추가 정보 화면', 'en' => 'Signup Extra Info Screen'],
                'hint' => ['ko' => '필수 정보가 비었을 때만 또는 늘 보여 줍니다.', 'en' => 'Show only when required info is missing, or always.'],
            ],
            'two_factor_enabled' => [
                'type' => 'boolean', 'default' => true,
                'label' => ['ko' => '소셜 로그인에도 2단계 인증', 'en' => 'Two-Factor For Social Login'],
                'hint' => ['ko' => '끄면 인증 앱을 등록한 회원도 소셜 로그인 때 2단계 인증을 건너뜁니다.', 'en' => 'When off, even members with an authenticator app skip two-factor on social login.'],
            ],
            'allow_admin_social_login' => [
                'type' => 'boolean', 'default' => false,
                'label' => ['ko' => '관리자 소셜 로그인 허용', 'en' => 'Allow Administrators'],
                'hint' => ['ko' => '관리자 역할 회원의 소셜 로그인을 허용합니다.', 'en' => 'Allow members with an administrator role to sign in with a social account.'],
            ],
            'allow_unlink_last_method' => [
                'type' => 'boolean', 'default' => false,
                'label' => ['ko' => '마지막 로그인 수단 해제 허용', 'en' => 'Allow Unlinking Last Method'],
                'hint' => ['ko' => '비밀번호가 없는 회원이 마지막 연결을 끊는 것을 허용합니다.', 'en' => 'Allow a member without a password to remove their last linked account.'],
            ],
            'webhook_unlink_policy' => [
                'type' => 'string', 'default' => 'unlink_only',
                'label' => ['ko' => '제공자 연결 해제 알림 처리', 'en' => 'Provider Unlink Notice'],
                'hint' => ['ko' => '연결만 지우거나 회원을 탈퇴 처리합니다.', 'en' => 'Remove the link only, or withdraw the member.'],
            ],
            'revoke_on_unlink' => [
                'type' => 'boolean', 'default' => true,
                'label' => ['ko' => '해제 때 제공자 권한 회수', 'en' => 'Revoke On Unlink'],
                'hint' => ['ko' => '연결을 끊을 때 제공자 쪽 앱 권한도 회수합니다.', 'en' => 'Also revoke the app authorization at the provider when unlinking.'],
            ],
            'profile_raw_retention_days' => [
                'type' => 'integer', 'default' => 14,
                'label' => ['ko' => '원본 프로필 보존(일)', 'en' => 'Raw Profile Retention (Days)'],
                'hint' => ['ko' => '0~90. 0 이면 저장하지 않습니다.', 'en' => '0 to 90. 0 keeps nothing.'],
            ],
            'attempt_log_retention_days' => [
                'type' => 'integer', 'default' => 90,
                'label' => ['ko' => '시도 기록 보존(일)', 'en' => 'Attempt Log Retention (Days)'],
                'hint' => ['ko' => '7~365.', 'en' => '7 to 365.'],
            ],
            'webhook_event_retention_days' => [
                'type' => 'integer', 'default' => 90,
                'label' => ['ko' => '웹훅 기록 보존(일)', 'en' => 'Webhook Log Retention (Days)'],
                'hint' => ['ko' => '7~365.', 'en' => '7 to 365.'],
            ],
            'signup_surge_threshold_per_hour' => [
                'type' => 'integer', 'default' => 100,
                'label' => ['ko' => '가입 급증 알림 기준(시간당)', 'en' => 'Signup Surge Threshold (Per Hour)'],
                'hint' => ['ko' => '0 이면 알리지 않습니다.', 'en' => '0 disables the notice.'],
            ],
            'provider_failure_streak_threshold' => [
                'type' => 'integer', 'default' => 10,
                'label' => ['ko' => '제공자 연속 실패 알림 기준', 'en' => 'Provider Failure Streak Threshold'],
                'hint' => ['ko' => '0 이면 알리지 않습니다.', 'en' => '0 disables the notice.'],
            ],
            'pending_signup_ttl_minutes' => [
                'type' => 'integer', 'default' => 30,
                'label' => ['ko' => '가입 대기 유효 시간(분)', 'en' => 'Pending Signup Lifetime (Minutes)'],
                'hint' => ['ko' => '5~120.', 'en' => '5 to 120.'],
            ],
            'state_ttl_minutes' => [
                'type' => 'integer', 'default' => 10,
                'label' => ['ko' => '로그인 왕복 유효 시간(분)', 'en' => 'Sign-In Round Trip Lifetime (Minutes)'],
                'hint' => ['ko' => '3~30. 제공자 화면에서 이 시간 안에 돌아와야 합니다.', 'en' => '3 to 30. The member must return from the provider within this time.'],
            ],
            'custom_provider_limit' => [
                'type' => 'integer', 'default' => 10,
                'label' => ['ko' => '커스텀 제공자 최대 수', 'en' => 'Custom Provider Limit'],
                'hint' => ['ko' => '0~10.', 'en' => '0 to 10.'],
            ],
        ];
    }

    /**
     * 훅 리스너 목록 반환.
     *
     *  - RegisterMockProvidersListener: 모의 게이트가 켜졌을 때 모의 제공자 5종 등록
     *  - ValidateSettingsListener: 설정 저장 검증 규칙
     *  - RegisterSocialReauthProviderListener: 대체 이메일 회원의 본인 재확인 수단(소셜 재로그인)을 코어 본인인증에 등록
     *  - CleanAccountsOnUserDelete / CleanAccountsOnUserWithdraw: 회원 삭제·탈퇴 때 연결 정리(연쇄 삭제에 기대지 않는다)
     *  - InjectSocialProvidersIntoUserResource: 회원 응답에 연결된 제공자 목록(`social_providers`)
     *  - UserListSocialProviderFilter: 관리자 회원 목록 「가입경로」 확장 필터(`ext_filters[]=sirsoft-social_login.provider=…`)
     *  - UserListSocialProvidersColumn: 관리자 회원 목록 「소셜 연결」 열 데이터(한 번의 조회)
     *  - NotificationDataListener: 알림 5종의 치환 값·수신 문맥, 기본 정의 복원
     *  - ActivityLogListener: 연결·해제·가입·제공자 변경·정책 저장 활동 로그
     *
     * @return array<class-string> 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return [
            RegisterMockProvidersListener::class,
            ValidateSettingsListener::class,
            RegisterSocialReauthProviderListener::class,
            CleanAccountsOnUserDelete::class,
            CleanAccountsOnUserWithdraw::class,
            InjectSocialProvidersIntoUserResource::class,
            UserListSocialProviderFilter::class,
            UserListSocialProvidersColumn::class,
            NotificationDataListener::class,
            ActivityLogListener::class,
        ];
    }

    /**
     * 관리자 메뉴 — 진단·통계 화면 (설정 화면은 코어 「플러그인 관리 → 설정」 자동 경로라 메뉴를 두지 않는다).
     *
     * 코어가 설치·업데이트 때 동기화한다. 활성화 때는 선언에서 빠진 옛 메뉴를 거둔다({@see self::activate()}).
     *
     * @return array<int, array<string, mixed>> 메뉴 정의
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => ['ko' => '소셜 로그인', 'en' => 'Social Login'],
                'slug' => 'sirsoft-social_login',
                'url' => '/admin/plugins/sirsoft-social_login/diagnostics',
                'icon' => 'fas fa-right-to-bracket',
                'order' => 60,
                'children' => [
                    [
                        'name' => ['ko' => '진단', 'en' => 'Diagnostics'],
                        'slug' => 'sirsoft-social_login-diagnostics',
                        'url' => '/admin/plugins/sirsoft-social_login/diagnostics',
                        'icon' => 'fas fa-stethoscope',
                        'order' => 1,
                    ],
                    [
                        'name' => ['ko' => '통계', 'en' => 'Stats'],
                        'slug' => 'sirsoft-social_login-stats',
                        'url' => '/admin/plugins/sirsoft-social_login/stats',
                        'icon' => 'fas fa-chart-column',
                        'order' => 2,
                    ],
                ],
            ],
        ];
    }

    /**
     * 플러그인 활성화 — 관리자 메뉴를 등록하고 선언에서 빠진 옛 메뉴를 거둡니다.
     *
     * @return bool 활성화 성공 여부
     */
    public function activate(): bool
    {
        $helper = app(ExtensionMenuSyncHelper::class);
        $currentSlugs = [];

        foreach ($this->getAdminMenus() as $menuData) {
            $helper->syncMenuRecursive($menuData, ExtensionOwnerType::Plugin, $this->getIdentifier());
            $currentSlugs[] = $menuData['slug'];
            foreach ($menuData['children'] ?? [] as $child) {
                $currentSlugs[] = $child['slug'];
            }
        }

        $helper->cleanupStaleMenus(ExtensionOwnerType::Plugin, $this->getIdentifier(), currentSlugs: $currentSlugs);

        return true;
    }

    /**
     * 플러그인 비활성화 — 관리자 메뉴 행은 보존합니다(비활성 플러그인 메뉴는 사이드바에서 자동으로 빠진다).
     *
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(): bool
    {
        return true;
    }

    /**
     * 예약 작업 — 보존 기간 정리(매일 0시, 사이트 시간대).
     *
     * 이름과 설명은 ko, en 으로 적고 그 밖의 언어는 언어팩 seed 가 `name` 으로 채운다. 명령 문자열은 바꾸지 않는다 — 앞서 옛 형식이
     * 명령에서 만든 자동 이름 행(`sirsoft-social-login-prune`)을 동기화가 이 `name` 으로 이어받는 기준이다.
     *
     * @return array<int, array<string, mixed>> 예약 작업
     */
    public function getSchedules(): array
    {
        return [[
            'name' => 'prune',
            'type' => 'artisan',
            'label' => ['ko' => '소셜 로그인 보존 기간 정리', 'en' => 'Social login retention cleanup'],
            'description' => [
                'ko' => '보존 기간이 지난 원본 프로필 사본, 로그인 시도 기록, 웹훅 수신 이력과 만료된 모의 제공자 표지를 정리합니다.',
                'en' => 'Cleans up raw profile copies, sign-in attempts and webhook history past their retention period, and expired mock provider flags.',
            ],
            'command' => 'sirsoft-social_login:prune',
            'frequency' => 'daily',
            'expression' => '0 0 * * *',
        ]];
    }

    /**
     * 알림 정의 5종.
     *
     * 회원 대상 2(로그인 수단 해제 안내 · 관리자 강제 해제)는 대상 회원에게, 운영 3(제공자 연속 실패 · 가입 급증 · 웹훅 처리
     * 실패)은 관리자 역할에게 간다. 치환 값은 {@see NotificationDataListener} 가 만든다. 메일을 받을 수 없는 대체 이메일 회원에게는
     * 코어 메일 채널이 보내지 않는다.
     *
     * @return array<int, array<string, mixed>> 알림 정의
     */
    public function getNotificationDefinitions(): array
    {
        $admins = [['type' => 'role', 'value' => 'admin']];
        $member = [['type' => 'trigger_user']];

        return [
            [
                'type' => 'social_login_last_login_method_removed',
                'hook_prefix' => 'sirsoft-social_login',
                'audience' => 'customer',
                'name' => ['ko' => '소셜 연결 해제 — 로그인 수단 없음', 'en' => 'Social Link Removed — No Sign-In Method Left'],
                'description' => ['ko' => '제공자 쪽에서 연결이 끊겨 로그인할 수단이 없어진 회원에게 비밀번호 설정 방법을 안내합니다.', 'en' => 'Tells a member whose provider link was removed and who has no other sign-in method how to set a password.'],
                'channels' => ['mail'],
                'hooks' => ['sirsoft-social_login.account.unlinked_last_method'],
                'variables' => [
                    ['key' => 'app_name', 'description' => '사이트명'],
                    ['key' => 'name', 'description' => '회원 이름'],
                    ['key' => 'provider_label', 'description' => '제공자 이름'],
                    ['key' => 'site_url', 'description' => '사이트 주소'],
                    ['key' => 'reset_url', 'description' => '비밀번호 찾기 화면 주소'],
                ],
                'templates' => [[
                    'channel' => 'mail',
                    'recipients' => $member,
                    'subject' => ['ko' => '[{app_name}] {provider_label} 연결이 해제되었습니다', 'en' => '[{app_name}] Your {provider_label} link was removed'],
                    'body' => [
                        'ko' => '<p>{name}님, {provider_label} 쪽에서 이 사이트와의 연결이 해제되어 지금은 로그인할 수단이 없습니다.</p><p>아래에서 이메일로 비밀번호를 설정하면 다시 로그인할 수 있습니다.</p><p><a href="{reset_url}">비밀번호 설정하기</a></p><p>{site_url}</p>',
                        'en' => '<p>{name}, your link with this site was removed on {provider_label}, so you have no way to sign in right now.</p><p>Set a password by email below to sign in again.</p><p><a href="{reset_url}">Set a password</a></p><p>{site_url}</p>',
                    ],
                ]],
            ],
            [
                'type' => 'social_login_link_removed_by_admin',
                'hook_prefix' => 'sirsoft-social_login',
                'audience' => 'customer',
                'name' => ['ko' => '소셜 연결 관리자 해제', 'en' => 'Social Link Removed by Administrator'],
                'description' => ['ko' => '관리자가 회원의 소셜 계정 연결을 해제하면 회원에게 알립니다.', 'en' => 'Tells a member when an administrator removes a social account link.'],
                'channels' => ['database', 'mail'],
                'hooks' => ['sirsoft-social_login.account.unlinked_by_admin'],
                'variables' => [
                    ['key' => 'app_name', 'description' => '사이트명'],
                    ['key' => 'name', 'description' => '회원 이름'],
                    ['key' => 'provider_label', 'description' => '제공자 이름'],
                    ['key' => 'site_url', 'description' => '사이트 주소'],
                ],
                'templates' => [
                    [
                        'channel' => 'database',
                        'recipients' => $member,
                        'subject' => ['ko' => '{provider_label} 연결이 해제되었습니다', 'en' => 'Your {provider_label} link was removed'],
                        'body' => ['ko' => '관리자가 {provider_label} 계정 연결을 해제했습니다.', 'en' => 'An administrator removed your {provider_label} account link.'],
                        // 소셜 연결 카드가 있는 마이페이지 프로필로 — 다시 연결하거나 남은 연결을 확인한다
                        'click_url' => '/mypage/profile',
                    ],
                    [
                        'channel' => 'mail',
                        'recipients' => $member,
                        'is_active' => false,
                        'subject' => ['ko' => '[{app_name}] {provider_label} 연결이 해제되었습니다', 'en' => '[{app_name}] Your {provider_label} link was removed'],
                        'body' => [
                            'ko' => '<p>{name}님, 관리자가 {provider_label} 계정 연결을 해제했습니다. 궁금한 점은 사이트에 문의해 주세요.</p><p>{site_url}</p>',
                            'en' => '<p>{name}, an administrator removed your {provider_label} account link. Please contact the site if you have questions.</p><p>{site_url}</p>',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'social_login_provider_failing',
                'hook_prefix' => 'sirsoft-social_login',
                'audience' => 'staff',
                'name' => ['ko' => '소셜 로그인 제공자 연속 실패', 'en' => 'Social Login Provider Failing'],
                'description' => ['ko' => '한 제공자의 로그인 왕복이 10분 안에 기준 횟수 이상 실패하면 관리자에게 하루 한 번 알립니다.', 'en' => 'Notifies administrators once a day when a provider round trip fails the configured number of times within 10 minutes.'],
                'channels' => ['mail', 'database'],
                'hooks' => ['sirsoft-social_login.provider.failing'],
                'variables' => [
                    ['key' => 'app_name', 'description' => '사이트명'],
                    ['key' => 'name', 'description' => '받는 관리자 이름'],
                    ['key' => 'provider_label', 'description' => '제공자 이름'],
                    ['key' => 'failure_count', 'description' => '최근 10분 실패 횟수'],
                    ['key' => 'last_error_code', 'description' => '마지막 오류 코드'],
                    ['key' => 'diagnostics_url', 'description' => '진단 화면 주소'],
                ],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'recipients' => $admins,
                        'subject' => ['ko' => '[{app_name}] {provider_label} 소셜 로그인 실패가 이어집니다', 'en' => '[{app_name}] {provider_label} sign-in keeps failing'],
                        'body' => [
                            'ko' => '<p>{name}님, {provider_label} 로그인이 최근 10분 안에 {failure_count}번 실패했습니다(마지막 오류 {last_error_code}).</p><p><a href="{diagnostics_url}">진단 화면 열기</a></p>',
                            'en' => '<p>{name}, {provider_label} sign-in failed {failure_count} times in the last 10 minutes (last error {last_error_code}).</p><p><a href="{diagnostics_url}">Open diagnostics</a></p>',
                        ],
                    ],
                    [
                        'channel' => 'database',
                        'recipients' => $admins,
                        'subject' => ['ko' => '{provider_label} 로그인 연속 실패', 'en' => '{provider_label} sign-in failing'],
                        'body' => ['ko' => '최근 10분 {failure_count}번 실패 — 마지막 오류 {last_error_code}', 'en' => '{failure_count} failures in 10 minutes — last error {last_error_code}'],
                        'click_url' => '{diagnostics_url}',
                    ],
                ],
            ],
            [
                'type' => 'social_login_signup_surge',
                'hook_prefix' => 'sirsoft-social_login',
                'audience' => 'staff',
                'name' => ['ko' => '소셜 가입 급증', 'en' => 'Social Signup Surge'],
                'description' => ['ko' => '한 제공자의 시간당 소셜 가입이 기준에 닿으면 관리자에게 하루 한 번 알립니다.', 'en' => 'Notifies administrators once a day when hourly social signups for a provider reach the threshold.'],
                'channels' => ['mail', 'database'],
                'hooks' => ['sirsoft-social_login.signup.surge'],
                'variables' => [
                    ['key' => 'app_name', 'description' => '사이트명'],
                    ['key' => 'name', 'description' => '받는 관리자 이름'],
                    ['key' => 'provider_label', 'description' => '제공자 이름'],
                    ['key' => 'count', 'description' => '이번 시간 가입 수'],
                    ['key' => 'threshold', 'description' => '알림 기준'],
                    ['key' => 'stats_url', 'description' => '통계 화면 주소'],
                ],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'recipients' => $admins,
                        'subject' => ['ko' => '[{app_name}] {provider_label} 소셜 가입이 급증했습니다', 'en' => '[{app_name}] {provider_label} social signups surged'],
                        'body' => [
                            'ko' => '<p>{name}님, 지난 한 시간 동안 {provider_label} 가입이 {count}건으로 기준({threshold}건)에 닿았습니다. 비정상 가입이 아닌지 확인해 주세요.</p><p><a href="{stats_url}">통계 화면 열기</a></p>',
                            'en' => '<p>{name}, {provider_label} signups reached {count} in the last hour (threshold {threshold}). Please check for abusive signups.</p><p><a href="{stats_url}">Open stats</a></p>',
                        ],
                    ],
                    [
                        'channel' => 'database',
                        'recipients' => $admins,
                        'subject' => ['ko' => '{provider_label} 가입 급증', 'en' => '{provider_label} signup surge'],
                        'body' => ['ko' => '시간당 {count}건 (기준 {threshold}건)', 'en' => '{count} per hour (threshold {threshold})'],
                        'click_url' => '{stats_url}',
                    ],
                ],
            ],
            [
                'type' => 'social_login_webhook_failed',
                'hook_prefix' => 'sirsoft-social_login',
                'audience' => 'staff',
                'name' => ['ko' => '소셜 로그인 웹훅 처리 실패', 'en' => 'Social Login Webhook Failed'],
                'description' => ['ko' => '제공자 연결 해제 알림을 다시 시도해도 처리하지 못하면 관리자에게 알립니다.', 'en' => 'Notifies administrators when a provider unlink notice could not be processed after retries.'],
                'channels' => ['database'],
                'hooks' => ['sirsoft-social_login.webhook.failed'],
                'variables' => [
                    ['key' => 'app_name', 'description' => '사이트명'],
                    ['key' => 'name', 'description' => '받는 관리자 이름'],
                    ['key' => 'provider_label', 'description' => '제공자 이름'],
                    ['key' => 'event_type', 'description' => '알림 종류'],
                    ['key' => 'error', 'description' => '오류 요약(비밀 없음)'],
                    ['key' => 'diagnostics_url', 'description' => '진단 화면 주소'],
                ],
                'templates' => [[
                    'channel' => 'database',
                    'recipients' => $admins,
                    'subject' => ['ko' => '{provider_label} 웹훅 처리 실패', 'en' => '{provider_label} webhook failed'],
                    'body' => ['ko' => '{event_type} 알림을 처리하지 못했습니다: {error}', 'en' => 'Could not process the {event_type} notice: {error}'],
                    'click_url' => '{diagnostics_url}',
                ]],
            ],
        ];
    }

    /**
     * 플러그인이 발행하는 훅 정보 반환.
     *
     * 토큰·원본 프로필은 훅 인자에 싣지 않는다. 예외는 `provider.filter_registered` 하나 — 확장이 드라이버를 등록하는
     * 통로라 인자가 드라이버 객체이고, 그 객체는 제공자 자격증명(client_secret·어드민 키)을 들고 있다. 같은 요청 안에서만
     * 도는 동기 필터라 큐·로그로 복제되지 않는다. 항목마다 `name` 을 두는 목록 형태라야 확장 문서 생성기가
     * `docs/extension-points.md` 발행 훅 표를 채운다.
     *
     * @return array<int, array<string, mixed>> 훅 정의 목록
     */
    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-social_login.provider.filter_registered',
                'type' => 'filter',
                'description' => [
                    'ko' => '제공자 드라이버 목록을 걸러 냅니다. 확장이 새 키로 ProviderDriverInterface 구현을 더할 수 있습니다(내장 키 덮어쓰기는 무시). 인자: (array<string, ProviderDriverInterface> $drivers)',
                    'en' => 'Filters the provider driver map. Extensions may add a ProviderDriverInterface implementation under a new key (overriding built-in keys is ignored). Arguments: (array<string, ProviderDriverInterface> $drivers)',
                    'ja' => 'プロバイダードライバーの一覧をフィルターします。拡張は新しいキーで ProviderDriverInterface の実装を追加できます(組み込みキーの上書きは無視)。引数: (array<string, ProviderDriverInterface> $drivers)',
                ],
                'parameters' => [
                    'drivers' => 'array<string, ProviderDriverInterface> - 제공자 키별 드라이버',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.profile.filter_normalized',
                'type' => 'filter',
                'description' => [
                    'ko' => '제공자 프로필을 정규화한 직후 걸러 냅니다. 인자: (NormalizedProfile $profile, string $providerKey)',
                    'en' => 'Filters the provider profile right after normalization. Arguments: (NormalizedProfile $profile, string $providerKey)',
                    'ja' => 'プロバイダーのプロフィールを正規化した直後にフィルターします。引数: (NormalizedProfile $profile, string $providerKey)',
                ],
                'parameters' => [
                    'profile' => 'NormalizedProfile - 정규화한 프로필(토큰 없음)',
                    'providerKey' => 'string - 제공자 키',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.signup.filter_registration_data',
                'type' => 'filter',
                'description' => [
                    'ko' => '소셜 가입으로 코어 가입에 넘기기 직전의 가입 데이터를 걸러 냅니다. 필수 키(name, email, 동의)는 지워도 되살립니다. 인자: (array $data, NormalizedProfile $profile)',
                    'en' => 'Filters the registration data right before the social signup calls core registration. Required keys (name, email, consents) are restored if removed. Arguments: (array $data, NormalizedProfile $profile)',
                    'ja' => 'ソーシャル登録でコアの会員登録に渡す直前の登録データをフィルターします。必須キー(name、email、同意)は削除しても復元されます。引数: (array $data, NormalizedProfile $profile)',
                ],
                'parameters' => [
                    'data' => 'array - 가입 데이터(name, nickname, email, agree_terms, agree_privacy, language, social_provider)',
                    'profile' => 'NormalizedProfile - 정규화한 프로필(토큰 없음)',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.account.after_link',
                'type' => 'action',
                'description' => [
                    'ko' => '회원에게 소셜 계정 연결을 새로 만든 직후 발행됩니다. 인자: (SocialAccount $account, User $user, array $context — method: link·existing·auto·signup)',
                    'en' => 'Fired right after a social account is newly linked to a member. Arguments: (SocialAccount $account, User $user, array $context — method: link, existing, auto or signup)',
                    'ja' => '会員にソーシャルアカウントの連携を新しく作成した直後に発行されます。引数: (SocialAccount $account, User $user, array $context — method: link・existing・auto・signup)',
                ],
                'parameters' => [
                    'account' => 'SocialAccount - 연결(토큰·원본 프로필은 직렬화에서 숨김)',
                    'user' => 'User - 회원',
                    'context' => 'array - method(연결 경로)',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.account.before_unlink',
                'type' => 'action',
                'description' => [
                    'ko' => '소셜 계정 연결을 지우기 직전에 발행됩니다. 인자: (SocialAccount $account, User $user, string $reason — user·admin·webhook·withdraw·delete)',
                    'en' => 'Fired right before a social account link is removed. Arguments: (SocialAccount $account, User $user, string $reason — user, admin, webhook, withdraw or delete)',
                    'ja' => 'ソーシャルアカウントの連携を削除する直前に発行されます。引数: (SocialAccount $account, User $user, string $reason — user・admin・webhook・withdraw・delete)',
                ],
                'parameters' => [
                    'account' => 'SocialAccount - 연결',
                    'user' => 'User - 회원',
                    'reason' => 'string - 해제 사유',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.account.after_unlink',
                'type' => 'action',
                'description' => [
                    'ko' => '소셜 계정 연결을 지운 직후 발행됩니다. 인자: (SocialAccount $account, User $user, string $reason)',
                    'en' => 'Fired right after a social account link is removed. Arguments: (SocialAccount $account, User $user, string $reason)',
                    'ja' => 'ソーシャルアカウントの連携を削除した直後に発行されます。引数: (SocialAccount $account, User $user, string $reason)',
                ],
                'parameters' => [
                    'account' => 'SocialAccount - 지운 연결(메모리의 값)',
                    'user' => 'User - 회원',
                    'reason' => 'string - 해제 사유',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.auth.after_social_login',
                'type' => 'action',
                'description' => [
                    'ko' => '연결된 회원에게 로그인 인계 코드를 발급한 직후 발행됩니다. 2단계 인증·비밀번호 변경 같은 코어 관문은 그 뒤 로그인 화면에서 진행됩니다. 인자: (User $user, SocialAccount $account)',
                    'en' => 'Fired right after a login handoff code is issued for a linked member. Core gates such as two-factor and password change follow on the login screen. Arguments: (User $user, SocialAccount $account)',
                    'ja' => '連携済み会員にログイン引き継ぎコードを発行した直後に発行されます。2段階認証・パスワード変更などのコアの関門はその後ログイン画面で進みます。引数: (User $user, SocialAccount $account)',
                ],
                'parameters' => [
                    'user' => 'User - 회원',
                    'account' => 'SocialAccount - 연결',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.auth.after_social_signup',
                'type' => 'action',
                'description' => [
                    'ko' => '소셜 계정으로 회원 가입을 마친 직후(연결 생성 뒤) 발행됩니다. 인자: (User $user, SocialAccount $account)',
                    'en' => 'Fired right after a member signs up with a social account (after the link is created). Arguments: (User $user, SocialAccount $account)',
                    'ja' => 'ソーシャルアカウントで会員登録を終えた直後(連携作成後)に発行されます。引数: (User $user, SocialAccount $account)',
                ],
                'parameters' => [
                    'user' => 'User - 새 회원(비밀번호 없음)',
                    'account' => 'SocialAccount - 연결',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.signup.surge',
                'type' => 'action',
                'description' => [
                    'ko' => '한 제공자의 시간당 소셜 가입 수가 설정 기준에 닿으면 하루 한 번 발행됩니다. 인자: (string $providerKey, int $count, int $threshold)',
                    'en' => 'Fired once a day when a provider\'s hourly social signups reach the configured threshold. Arguments: (string $providerKey, int $count, int $threshold)',
                    'ja' => '一つのプロバイダーの1時間あたりのソーシャル登録数が設定の基準に達すると1日に1回発行されます。引数: (string $providerKey, int $count, int $threshold)',
                ],
                'parameters' => [
                    'providerKey' => 'string - 제공자 키',
                    'count' => 'int - 이번 시간 가입 수',
                    'threshold' => 'int - 기준',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.provider.failing',
                'type' => 'action',
                'description' => [
                    'ko' => '한 제공자의 로그인 왕복이 10분 안에 설정 기준 이상 실패하면 하루 한 번 발행됩니다(회원의 동의 취소·관리자 연결 테스트는 세지 않음). 인자: (string $providerKey, int $count, string $lastErrorCode)',
                    'en' => 'Fired once a day when a provider round trip fails the configured number of times within 10 minutes (member cancellations and admin tests are not counted). Arguments: (string $providerKey, int $count, string $lastErrorCode)',
                    'ja' => '一つのプロバイダーのログイン往復が10分以内に設定の基準回数以上失敗すると1日に1回発行されます(会員の同意取り消し・管理者の接続テストは数えません)。引数: (string $providerKey, int $count, string $lastErrorCode)',
                ],
                'parameters' => [
                    'providerKey' => 'string - 제공자 키',
                    'count' => 'int - 최근 10분 실패 횟수',
                    'lastErrorCode' => 'string - 마지막 오류 코드',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.provider.after_create',
                'type' => 'action',
                'description' => [
                    'ko' => '관리자가 일반 OIDC 제공자를 추가한 직후 발행됩니다. 인자: (array $summary — key·driver·type·enabled·label, 비밀값 없음)',
                    'en' => 'Fired right after an administrator adds a generic OIDC provider. Arguments: (array $summary — key, driver, type, enabled, label; no secrets)',
                    'ja' => '管理者が汎用 OIDC プロバイダーを追加した直後に発行されます。引数: (array $summary — key・driver・type・enabled・label、秘密値なし)',
                ],
                'parameters' => [
                    'summary' => 'array - 제공자 요약(비밀값 없음)',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.provider.after_update',
                'type' => 'action',
                'description' => [
                    'ko' => '관리자가 제공자 설정을 바꾼 직후 발행됩니다(바뀐 칸이 있을 때만). 인자: (array $summary, array $changed — 바뀐 칸 이름, 값은 싣지 않음)',
                    'en' => 'Fired right after an administrator changes provider settings (only when a field changed). Arguments: (array $summary, array $changed — changed field names, no values)',
                    'ja' => '管理者がプロバイダー設定を変更した直後に発行されます(変更された項目がある場合のみ)。引数: (array $summary, array $changed — 変更された項目名、値は含めない)',
                ],
                'parameters' => [
                    'summary' => 'array - 제공자 요약(비밀값 없음)',
                    'changed' => 'array<int, string> - 바뀐 칸 이름',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.provider.after_delete',
                'type' => 'action',
                'description' => [
                    'ko' => '관리자가 일반 OIDC 제공자를 삭제한 직후 발행됩니다. 인자: (array $summary)',
                    'en' => 'Fired right after an administrator deletes a generic OIDC provider. Arguments: (array $summary)',
                    'ja' => '管理者が汎用 OIDC プロバイダーを削除した直後に発行されます。引数: (array $summary)',
                ],
                'parameters' => [
                    'summary' => 'array - 지운 제공자 요약(비밀값 없음)',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.account.unlinked_by_admin',
                'type' => 'action',
                'description' => [
                    'ko' => '관리자가 회원의 소셜 계정 연결을 강제로 해제한 직후 발행됩니다(회원 알림). 인자: (User $user, string $providerKey)',
                    'en' => 'Fired right after an administrator force-removes a member social account link (member notice). Arguments: (User $user, string $providerKey)',
                    'ja' => '管理者が会員のソーシャルアカウント連携を強制解除した直後に発行されます(会員への通知)。引数: (User $user, string $providerKey)',
                ],
                'parameters' => [
                    'user' => 'User - 대상 회원',
                    'providerKey' => 'string - 제공자 키',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.account.unlinked_last_method',
                'type' => 'action',
                'description' => [
                    'ko' => '제공자 웹훅으로 연결이 지워져 회원에게 비밀번호도 다른 연결도 남지 않았을 때 발행됩니다(메일을 받을 수 있는 회원만, 가입 미완료 알림 제외). 인자: (User $user, string $providerKey)',
                    'en' => 'Fired when a provider webhook removed a link and the member has neither a password nor another link (only for members who can receive mail; not for incomplete signups). Arguments: (User $user, string $providerKey)',
                    'ja' => 'プロバイダーの Webhook で連携が削除され、会員にパスワードも他の連携も残らないときに発行されます(メールを受け取れる会員のみ、登録未完了の通知は除く)。引数: (User $user, string $providerKey)',
                ],
                'parameters' => [
                    'user' => 'User - 대상 회원',
                    'providerKey' => 'string - 제공자 키',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.webhook.after_receive',
                'type' => 'action',
                'description' => [
                    'ko' => '제공자 웹훅을 검증해 처음 저장한 직후(처리 잡 예약 전) 발행됩니다. 인자: (SocialLoginWebhookEvent $event — 서명·암호문을 뺀 요약 본문)',
                    'en' => 'Fired right after a provider webhook is verified and stored for the first time (before the processing job is queued). Arguments: (SocialLoginWebhookEvent $event — summary payload without signatures or ciphertext)',
                    'ja' => 'プロバイダーの Webhook を検証して初めて保存した直後(処理ジョブの予約前)に発行されます。引数: (SocialLoginWebhookEvent $event — 署名・暗号文を除いた要約本文)',
                ],
                'parameters' => [
                    'event' => 'SocialLoginWebhookEvent - 수신 이벤트',
                ],
            ],
            [
                'name' => 'sirsoft-social_login.webhook.failed',
                'type' => 'action',
                'description' => [
                    'ko' => '웹훅 처리가 다시 시도해도 실패했거나 정책상 거절됐을 때(관리자 탈퇴 거부) 발행됩니다. 인자: (array $summary — event_id·provider_key·event_type·error)',
                    'en' => 'Fired when webhook processing still failed after retries or was refused by policy (administrator withdrawal refused). Arguments: (array $summary — event_id, provider_key, event_type, error)',
                    'ja' => 'Webhook の処理が再試行しても失敗した、またはポリシーで拒否された(管理者の退会拒否)ときに発行されます。引数: (array $summary — event_id・provider_key・event_type・error)',
                ],
                'parameters' => [
                    'summary' => 'array - 실패 요약(비밀 없음)',
                ],
            ],
        ];
    }

    /**
     * 플러그인 권한 목록 반환 (계층 구조).
     *
     * @return array<string, mixed> 권한 정의 배열
     */
    public function getPermissions(): array
    {
        return [
            'name' => ['ko' => '소셜 로그인', 'en' => 'Social Login'],
            'description' => ['ko' => '소셜 로그인 플러그인이 제공하는 권한', 'en' => 'Permissions provided by the social login plugin'],
            'categories' => [
                [
                    'identifier' => 'settings',
                    'name' => ['ko' => '소셜 로그인 설정', 'en' => 'Social Login Settings'],
                    'description' => ['ko' => '제공자와 정책 설정', 'en' => 'Provider and policy settings'],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => ['ko' => '설정·진단·통계 열람', 'en' => 'View Settings, Diagnostics and Stats'],
                            'description' => ['ko' => '제공자 설정, 진단, 통계를 봅니다.', 'en' => 'View provider settings, diagnostics and stats.'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'update',
                            'name' => ['ko' => '설정 변경·연결 테스트', 'en' => 'Change Settings and Test'],
                            'description' => ['ko' => '제공자와 정책을 바꾸고 연결 테스트를 합니다.', 'en' => 'Change providers and policies and run connection tests.'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
                [
                    'identifier' => 'accounts',
                    'name' => ['ko' => '회원 소셜 연결', 'en' => 'Member Social Links'],
                    'description' => ['ko' => '회원의 소셜 계정 연결 관리', 'en' => 'Manage member social account links'],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => ['ko' => '회원 연결 열람', 'en' => 'View Member Links'],
                            'description' => ['ko' => '회원의 소셜 계정 연결을 봅니다.', 'en' => 'View member social account links.'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'unlink',
                            'name' => ['ko' => '회원 연결 강제 해제', 'en' => 'Force Unlink'],
                            'description' => ['ko' => '회원의 소셜 계정 연결을 강제로 끊습니다.', 'en' => 'Force-remove a member social account link.'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 언인스톨 때 지울 테이블 목록.
     *
     * @return array<int, string> 테이블명 (FK 순서 — 자식 먼저)
     */
    public function getDynamicTables(): array
    {
        return [
            'social_login_webhook_events',
            'social_login_attempts',
            'social_accounts',
            'social_login_providers',
        ];
    }

    /**
     * 확장 미들웨어 — 모의 제공자 라우트군(`web.plugins.sirsoft-social_login.mock.*`)의 게이트. 모의 라우트를 더해도 서브트리
     * 패턴이 덮어 게이트가 빠지지 않는다.
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
                'targets' => ['web.plugins.sirsoft-social_login.mock.*'],
            ],
        ];
    }

    /**
     * 설치·업데이트 때 실행할 시더.
     *
     * @return array<int, class-string> 시더 클래스
     */
    public function getSeeders(): array
    {
        return [
            ProviderSeeder::class,
        ];
    }
}
