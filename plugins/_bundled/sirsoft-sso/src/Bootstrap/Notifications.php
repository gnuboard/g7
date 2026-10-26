<?php

namespace Plugins\Sirsoft\Sso\Bootstrap;

/**
 * 알림 정의 (`Plugin::getNotificationDefinitions()` 가 부른다).
 *
 * 정의 형식은 `AbstractPlugin::getNotificationDefinitions()` 의 문서와 같다. 관리자 대상 알림의 수신자는 역할 규칙
 * `{type: role, value: admin}` 이고 운영자가 알림 설정 화면에서 바꿀 수 있다(`user_overrides`). 최고관리자 퇴사 알림과 관리자 자동 탈퇴
 * 건너뜀 알림은 최고관리자 전원이다(`related_user` 규칙 `super_admins` — 문맥의 최고관리자 묶음). 본문 자리(`{name}` 등)는
 * `Listeners\Admin\NotificationDataListener` 가 채운다.
 *
 * @since 1.0.0
 */
final class Notifications
{
    /** 훅 접두 (본문 값 필터 `sirsoft-sso.notification.extract_data`) */
    public const HOOK_PREFIX = 'sirsoft-sso';

    /**
     * 알림 정의를 반환합니다.
     *
     * @return array<int, array<string, mixed>> 알림 정의
     */
    public static function all(): array
    {
        $admins = [['type' => 'role', 'value' => 'admin']];
        $member = [['type' => 'trigger_user']];

        return [
            self::staff('sso_emergency_login', 'sirsoft-sso.auth.emergency_login', $admins,
                ['ko' => 'SSO 비상 계정 로그인', 'en' => 'SSO Emergency Account Sign-In'],
                ['ko' => 'SSO 강제가 켜진 동안 비상 계정이 비밀번호로 로그인하면 관리자에게 알립니다.', 'en' => 'Notifies administrators when an emergency account signs in with a password while SSO is enforced.'],
                [['key' => 'user_name', 'description' => '비상 계정 이름'], ['key' => 'login_at', 'description' => '로그인 시각'], ['key' => 'ip_address', 'description' => '접속 IP'], ['key' => 'settings_url', 'description' => 'SSO 설정 화면 주소']],
                ['ko' => '[{app_name}] 비상 계정 {user_name} 이(가) 비밀번호로 로그인했습니다', 'en' => '[{app_name}] Emergency account {user_name} signed in with a password'],
                ['ko' => '<p>{name}님, SSO 강제가 켜진 동안 비상 계정 {user_name} 이(가) 비밀번호와 2단계 인증으로 로그인했습니다.</p><p>시각 {login_at}, IP {ip_address}</p><p>예상하지 못한 로그인이면 즉시 비밀번호를 바꾸고 활동 로그를 확인해 주세요.</p><p><a href="{settings_url}">SSO 설정 열기</a></p>', 'en' => '<p>{name}, emergency account {user_name} signed in with a password and two-factor authentication while SSO is enforced.</p><p>Time {login_at}, IP {ip_address}</p><p>If you did not expect this, change the password right away and review the activity log.</p><p><a href="{settings_url}">Open SSO settings</a></p>'],
                ['ko' => '비상 계정 {user_name} 비밀번호 로그인', 'en' => 'Emergency account {user_name} password sign-in'],
                ['ko' => 'SSO 강제 중 비상 계정이 로그인했습니다 — {login_at}, IP {ip_address}', 'en' => 'An emergency account signed in while SSO is enforced — {login_at}, IP {ip_address}'],
                '{settings_url}'),
            self::staff('sso_idp_failures', 'sirsoft-sso.idp.failing', $admins,
                ['ko' => 'SSO IdP 연속 실패', 'en' => 'SSO IdP Failing'],
                ['ko' => '한 IdP 의 로그인 실패가 정책의 기준(기본 10분 안 5회)에 닿으면 관리자에게 하루 한 번 알립니다.', 'en' => 'Notifies administrators once a day when an IdP\'s sign-in failures reach the policy threshold (default 5 within 10 minutes).'],
                [['key' => 'idp_label', 'description' => 'IdP 이름'], ['key' => 'failure_count', 'description' => '기준 시간 안 실패 횟수'], ['key' => 'last_error_code', 'description' => '마지막 오류 코드'], ['key' => 'settings_url', 'description' => 'IdP 진단 화면 주소']],
                ['ko' => '[{app_name}] {idp_label} 로그인 실패가 이어집니다', 'en' => '[{app_name}] {idp_label} sign-in keeps failing'],
                ['ko' => '<p>{name}님, {idp_label} 회사 계정 로그인이 최근 {failure_count}번 실패했습니다(마지막 오류 {last_error_code}).</p><p>IdP 인증서, 연결 정보, 서버 시계를 확인해 주세요.</p><p><a href="{settings_url}">진단 화면 열기</a></p>', 'en' => '<p>{name}, {idp_label} company sign-in failed {failure_count} times recently (last error {last_error_code}).</p><p>Check the IdP certificate, connection details and server clock.</p><p><a href="{settings_url}">Open diagnostics</a></p>'],
                ['ko' => '{idp_label} 로그인 연속 실패', 'en' => '{idp_label} sign-in failing'],
                ['ko' => '최근 {failure_count}번 실패 — 마지막 오류 {last_error_code}', 'en' => '{failure_count} recent failures — last error {last_error_code}'],
                '{settings_url}'),
            self::staff('sso_cert_expiring', 'sirsoft-sso.idp.cert_expiring', $admins,
                ['ko' => 'SSO IdP 인증서 만료 예정', 'en' => 'SSO IdP Certificate Expiring'],
                ['ko' => 'IdP 서명 인증서 만료가 다가오면(기본 30일, 7일 전) 관리자에게 알립니다.', 'en' => 'Notifies administrators when an IdP signing certificate is about to expire (default 30 and 7 days before).'],
                [['key' => 'idp_label', 'description' => 'IdP 이름'], ['key' => 'days_left', 'description' => '남은 날'], ['key' => 'expires_at', 'description' => '만료일'], ['key' => 'settings_url', 'description' => 'IdP 설정 화면 주소']],
                ['ko' => '[{app_name}] {idp_label} 인증서가 {days_left}일 뒤 만료됩니다', 'en' => '[{app_name}] {idp_label} certificate expires in {days_left} days'],
                ['ko' => '<p>{name}님, {idp_label} 의 서명 인증서가 {expires_at} 에 만료됩니다({days_left}일 남음).</p><p>만료되면 이 IdP 로 아무도 로그인하지 못합니다. IdP 에서 새 인증서를 발급해 메타데이터를 갱신해 주세요.</p><p><a href="{settings_url}">IdP 설정 열기</a></p>', 'en' => '<p>{name}, the signing certificate of {idp_label} expires on {expires_at} ({days_left} days left).</p><p>After it expires nobody can sign in with this IdP. Issue a new certificate on the IdP and refresh the metadata.</p><p><a href="{settings_url}">Open IdP settings</a></p>'],
                ['ko' => '{idp_label} 인증서 {days_left}일 뒤 만료', 'en' => '{idp_label} certificate expires in {days_left} days'],
                ['ko' => '만료일 {expires_at}', 'en' => 'Expires on {expires_at}'],
                '{settings_url}'),
            self::staff('sso_email_conflict', 'sirsoft-sso.account.email_sync_conflict', $admins,
                ['ko' => 'SSO 이메일 동기화 충돌', 'en' => 'SSO Email Sync Conflict'],
                ['ko' => 'IdP 가 알려 준 새 이메일을 다른 회원이 쓰고 있어 바꾸지 못하면 관리자에게 알립니다.', 'en' => 'Notifies administrators when a new email from the IdP could not be applied because another member uses it.'],
                [['key' => 'user_name', 'description' => '회원 이름'], ['key' => 'idp_label', 'description' => 'IdP 이름'], ['key' => 'idp_email', 'description' => 'IdP 이메일'], ['key' => 'user_url', 'description' => '회원 상세 화면 주소']],
                ['ko' => '[{app_name}] {user_name} 회원의 IdP 이메일을 반영하지 못했습니다', 'en' => '[{app_name}] Could not apply the IdP email for {user_name}'],
                ['ko' => '<p>{name}님, {idp_label} 이(가) {user_name} 회원의 이메일을 {idp_email} 로 알려 왔지만 그 주소를 다른 회원이 쓰고 있어 바꾸지 않았습니다.</p><p><a href="{user_url}">회원 상세 열기</a></p>', 'en' => '<p>{name}, {idp_label} reported {idp_email} as the email of {user_name}, but another member uses that address, so it was not changed.</p><p><a href="{user_url}">Open member details</a></p>'],
                ['ko' => '{user_name} IdP 이메일 충돌', 'en' => '{user_name} IdP email conflict'],
                ['ko' => '{idp_label} 이메일 {idp_email} 을(를) 다른 회원이 사용 중', 'en' => '{idp_label} email {idp_email} is used by another member'],
                '{user_url}'),
            self::staff('sso_super_admin_deprovisioned', 'sirsoft-sso.account.super_admin_deprovisioned', [['type' => 'related_user', 'relation' => 'super_admins']],
                ['ko' => 'SSO 최고관리자 퇴사 처리', 'en' => 'SSO Super Administrator Deprovisioned'],
                ['ko' => '최고관리자의 회사 계정이 퇴사 처리되면 알립니다. 최고관리자는 자동으로 차단하지 않고 로그인 토큰만 회수합니다 — 직접 확인해 주세요.', 'en' => 'Notifies when a super administrator\'s company account is deprovisioned. Super administrators are not blocked automatically; only their sign-in tokens are revoked.'],
                [['key' => 'user_name', 'description' => '최고관리자 이름'], ['key' => 'idp_label', 'description' => 'IdP 이름'], ['key' => 'source', 'description' => '퇴사 확인 경로'], ['key' => 'user_url', 'description' => '회원 상세 화면 주소']],
                ['ko' => '[{app_name}] 최고관리자 {user_name} 의 회사 계정이 퇴사 처리되었습니다', 'en' => '[{app_name}] Company account of super administrator {user_name} was deprovisioned'],
                ['ko' => '<p>{name}님, {idp_label} 에서 최고관리자 {user_name} 의 계정이 비활성화되었습니다({source}).</p><p>로그인 토큰은 모두 회수했지만 최고관리자 계정은 자동으로 차단하지 않습니다. 계정을 정리할지 직접 판단해 주세요.</p><p><a href="{user_url}">회원 상세 열기</a></p>', 'en' => '<p>{name}, {idp_label} deactivated the account of super administrator {user_name} ({source}).</p><p>All sign-in tokens were revoked, but super administrator accounts are not blocked automatically. Please decide how to handle the account.</p><p><a href="{user_url}">Open member details</a></p>'],
                ['ko' => '최고관리자 {user_name} 퇴사 처리', 'en' => 'Super administrator {user_name} deprovisioned'],
                ['ko' => '{idp_label} — 토큰만 회수, 계정은 그대로', 'en' => '{idp_label} — tokens revoked, account kept'],
                '{user_url}'),
            self::staff('sso_admin_withdraw_skipped', 'sirsoft-sso.account.admin_withdraw_skipped', [['type' => 'related_user', 'relation' => 'super_admins']],
                ['ko' => 'SSO 관리자 자동 탈퇴 건너뜀', 'en' => 'SSO Administrator Auto-Withdrawal Skipped'],
                ['ko' => '퇴사 처리된 회원을 정해진 날이 지나 자동 탈퇴시킬 때 관리자 계정은 탈퇴시키지 않고 최고관리자에게 한 번 알립니다. 탈퇴 여부는 직접 정해 주세요.', 'en' => 'When deprovisioned members are withdrawn automatically after the set number of days, administrator accounts are skipped and super administrators are notified once. Please decide on the withdrawal yourself.'],
                [['key' => 'user_name', 'description' => '관리자 이름'], ['key' => 'idp_label', 'description' => 'IdP 이름'], ['key' => 'user_url', 'description' => '회원 상세 화면 주소']],
                ['ko' => '[{app_name}] 관리자 {user_name} 은(는) 자동 탈퇴하지 않았습니다', 'en' => '[{app_name}] Administrator {user_name} was not withdrawn automatically'],
                ['ko' => '<p>{name}님, {idp_label} 에서 퇴사 처리된 {user_name} 계정이 탈퇴 기한을 넘겼지만 관리자 계정이라 자동으로 탈퇴시키지 않았습니다.</p><p>계정의 역할과 상태를 확인하고 탈퇴 여부를 직접 정해 주세요.</p><p><a href="{user_url}">회원 상세 열기</a></p>', 'en' => '<p>{name}, the account of {user_name}, deprovisioned by {idp_label}, has passed the withdrawal period but was not withdrawn automatically because it is an administrator account.</p><p>Review the roles and status of the account and decide whether to withdraw it.</p><p><a href="{user_url}">Open member details</a></p>'],
                ['ko' => '관리자 {user_name} 자동 탈퇴 건너뜀', 'en' => 'Administrator {user_name} withdrawal skipped'],
                ['ko' => '{idp_label} 퇴사 처리 — 탈퇴 여부를 직접 정해 주세요', 'en' => '{idp_label} deprovisioned — decide on the withdrawal'],
                '{user_url}'),
            [
                'type' => 'sso_password_reset_redirect',
                'hook_prefix' => self::HOOK_PREFIX,
                'audience' => 'customer',
                'name' => ['ko' => 'SSO 비밀번호 찾기 안내', 'en' => 'SSO Password Reset Guidance'],
                'description' => ['ko' => '회사 계정으로 로그인해야 하는 회원이 비밀번호 찾기를 요청하면 재설정 링크 대신 회사 계정 로그인 방법을 안내합니다.', 'en' => 'When a member who must sign in with a company account requests a password reset, sends company sign-in guidance instead of a reset link.'],
                'channels' => ['mail'],
                'hooks' => ['sirsoft-sso.auth.password_reset_redirected'],
                'variables' => [
                    ['key' => 'app_name', 'description' => '사이트명'],
                    ['key' => 'name', 'description' => '회원 이름'],
                    ['key' => 'idp_label', 'description' => '회사 계정 이름'],
                    ['key' => 'login_url', 'description' => '로그인 화면 주소'],
                ],
                'templates' => [[
                    'channel' => 'mail',
                    'recipients' => $member,
                    'subject' => ['ko' => '[{app_name}] 이 계정은 회사 계정으로 로그인합니다', 'en' => '[{app_name}] This account signs in with a company account'],
                    'body' => [
                        'ko' => '<p>{name}님, 비밀번호 찾기를 요청하셨지만 이 계정은 {idp_label}(으)로만 로그인합니다. 비밀번호는 회사에서 관리합니다.</p><p>로그인 화면에서 「{idp_label}(으)로 로그인」을 눌러 주세요.</p><p><a href="{login_url}">로그인 화면 열기</a></p><p>직접 요청하지 않았다면 이 메일은 무시해도 됩니다.</p>',
                        'en' => '<p>{name}, you requested a password reset, but this account signs in only with {idp_label}. Your password is managed by your company.</p><p>On the sign-in screen, choose "Sign in with {idp_label}".</p><p><a href="{login_url}">Open the sign-in screen</a></p><p>If you did not request this, you can ignore this email.</p>',
                    ],
                ]],
            ],
        ];
    }

    /**
     * 관리자 대상 알림 정의 (메일 + 사이트 알림).
     *
     * @param  string  $type  알림 유형
     * @param  string  $hook  발화 훅
     * @param  array<int, array<string, string>>  $recipients  수신자 규칙
     * @param  array<string, string>  $name  이름
     * @param  array<string, string>  $description  설명
     * @param  array<int, array<string, string>>  $variables  본문 자리
     * @param  array<string, string>  $mailSubject  메일 제목
     * @param  array<string, string>  $mailBody  메일 본문
     * @param  array<string, string>  $dbSubject  사이트 알림 제목
     * @param  array<string, string>  $dbBody  사이트 알림 본문
     * @param  string  $clickUrl  사이트 알림 클릭 주소
     * @return array<string, mixed> 정의
     */
    private static function staff(string $type, string $hook, array $recipients, array $name, array $description, array $variables, array $mailSubject, array $mailBody, array $dbSubject, array $dbBody, string $clickUrl): array
    {
        $variables = array_merge([
            ['key' => 'app_name', 'description' => '사이트명'],
            ['key' => 'name', 'description' => '받는 관리자 이름'],
        ], $variables);

        return [
            'type' => $type,
            'hook_prefix' => self::HOOK_PREFIX,
            'audience' => 'staff',
            'name' => $name,
            'description' => $description,
            'channels' => ['mail', 'database'],
            'hooks' => [$hook],
            'variables' => $variables,
            'templates' => [
                ['channel' => 'mail', 'recipients' => $recipients, 'subject' => $mailSubject, 'body' => $mailBody],
                ['channel' => 'database', 'recipients' => $recipients, 'subject' => $dbSubject, 'body' => $dbBody, 'click_url' => $clickUrl],
            ],
        ];
    }
}
