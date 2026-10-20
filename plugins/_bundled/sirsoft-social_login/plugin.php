<?php

namespace Plugins\Sirsoft\SocialLogin;

use App\Extension\AbstractPlugin;
use Plugins\Sirsoft\SocialLogin\Database\Seeders\ProviderSeeder;
use Plugins\Sirsoft\SocialLogin\Listeners\ActivityLogListener;
use Plugins\Sirsoft\SocialLogin\Listeners\CleanAccountsOnUserDelete;
use Plugins\Sirsoft\SocialLogin\Listeners\CleanAccountsOnUserWithdraw;
use Plugins\Sirsoft\SocialLogin\Listeners\InjectSocialProvidersIntoUserResource;
use Plugins\Sirsoft\SocialLogin\Listeners\RegisterMockProvidersListener;
use Plugins\Sirsoft\SocialLogin\Listeners\RegisterSocialReauthProviderListener;
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
     *  - ActivityLogListener: 연결·해제·가입 활동 로그
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
            ActivityLogListener::class,
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
