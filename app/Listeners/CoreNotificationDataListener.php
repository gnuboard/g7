<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use App\Support\ScreenUrl;
use App\Support\SiteUrl;

/**
 * 코어 알림 데이터 필터 리스너
 *
 * notification_definitions의 extract_data 필터를 처리하여
 * 코어 알림(welcome, reset_password, password_changed, password_expiry_notice, password_expired) 발송에
 * 필요한 데이터와 컨텍스트를 제공합니다.
 * 수신자 결정은 notification_definitions.recipients 설정에 위임합니다.
 */
class CoreNotificationDataListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 훅 이름 => 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.notification.extract_data' => [
                'method' => 'extractData',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다.
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 알림 유형에 따라 데이터와 컨텍스트를 추출합니다.
     *
     * @param  array  $default  기본 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array  $args  훅에서 전달된 원본 인수
     * @return array{notifiable: null, notifiables: null, data: array, context: array}
     */
    public function extractData(array $default, string $type, array $args): array
    {
        return match ($type) {
            'welcome' => $this->extractWelcome($args),
            'reset_password' => $this->extractResetPassword($args),
            'password_changed' => $this->extractPasswordChanged($args),
            'password_expiry_notice' => $this->extractPasswordExpiryNotice($args),
            'password_expired' => $this->extractPasswordExpired($args),
            default => $default,
        };
    }

    /**
     * 회원가입 환영 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$user, $extra]
     * @return array 알림 데이터 (notifiable·notifiables·data·context)
     */
    private function extractWelcome(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => NotificationHookListener::RECIPIENT_NAME_PLACEHOLDER,
                'app_name' => config('app.name'),
                'action_url' => ScreenUrl::absoluteForLinks('auth.login', '/login'),
                'site_url' => ScreenUrl::userLinkBase(),
            ],
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }

    /**
     * 비밀번호 재설정 요청 알림 데이터를 추출합니다.
     *
     * 관리자 로그인 화면에서 요청한 재설정(`redirect_prefix` 있음)은 재설정 화면이 관리자 쪽이므로 메일의 사이트 링크도
     * 사이트 주소다. 회원 재설정은 「사용자 화면 URL」(비우면 사이트 주소).
     *
     * @param  array  $args  훅 인수 [$user, {reset_url, redirect_prefix}]
     * @return array 알림 데이터 (notifiable·notifiables·data·context)
     */
    private function extractResetPassword(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $extra = is_array($args[1] ?? null) ? $args[1] : [];
        $isAdmin = ! empty($extra['redirect_prefix']);

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => NotificationHookListener::RECIPIENT_NAME_PLACEHOLDER,
                'app_name' => config('app.name'),
                'action_url' => $extra['reset_url'] ?? ($isAdmin
                    ? ScreenUrl::absoluteForLinks('admin.reset_password', '/admin/reset-password', [], ScreenUrl::SCOPE_ADMIN)
                    : ScreenUrl::absoluteForLinks('auth.reset_password', '/reset-password')),
                'expire_minutes' => (string) config('auth.passwords.users.expire', 60),
                'site_url' => $isAdmin ? SiteUrl::forOutboundLinks() : ScreenUrl::userLinkBase(),
            ],
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }

    /**
     * 비밀번호 변경 완료 알림 데이터를 추출합니다.
     *
     * 수신자에 따라 로그인 링크와 사이트 링크가 다르다 — 관리자는 관리자 로그인 화면과 사이트 주소
     * (비밀번호 만료 안내·만료 메일과 같은 판정), 회원은 로그인 화면과 「사용자 화면 URL」.
     *
     * @param  array  $args  훅 인수 [$user]
     * @return array 알림 데이터 (notifiable·notifiables·data·context)
     */
    private function extractPasswordChanged(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $isAdmin = $user->isAdmin();

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => NotificationHookListener::RECIPIENT_NAME_PLACEHOLDER,
                'app_name' => config('app.name'),
                'action_url' => $isAdmin
                    ? ScreenUrl::absoluteForLinks('admin.login', '/admin/login', [], ScreenUrl::SCOPE_ADMIN)
                    : ScreenUrl::absoluteForLinks('auth.login', '/login'),
                'site_url' => $isAdmin ? SiteUrl::forOutboundLinks() : ScreenUrl::userLinkBase(),
            ],
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }

    /**
     * 비밀번호 만료 사전 안내 알림 데이터를 추출합니다 (#736).
     *
     * 변경 화면 주소는 수신자에 따라 다르다 — 관리자는 마이페이지가 아니라 관리자 회원 수정 화면에서
     * 자기 비밀번호를 바꾼다. 날짜는 배치가 수신자 시간대로 계산해 넘긴 값을 그대로 쓴다.
     *
     * @param  array  $args  훅 인수 [$user, {expires_at, days_left}]
     * @return array 알림 데이터 (notifiable·notifiables·data·context)
     */
    private function extractPasswordExpiryNotice(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $extra = is_array($args[1] ?? null) ? $args[1] : [];
        $isAdmin = $user->isAdmin();

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => NotificationHookListener::RECIPIENT_NAME_PLACEHOLDER,
                'app_name' => config('app.name'),
                'action_url' => $isAdmin
                    ? ScreenUrl::absoluteForLinks('admin.user.edit', '/admin/users/{uuid}/edit', ['uuid' => $user->uuid], ScreenUrl::SCOPE_ADMIN)
                    : ScreenUrl::absoluteForLinks('mypage.change_password', '/mypage/change-password'),
                'site_url' => $isAdmin ? SiteUrl::forOutboundLinks() : ScreenUrl::userLinkBase(),
                'expires_at' => (string) ($extra['expires_at'] ?? ''),
                'days_left' => (string) ($extra['days_left'] ?? ''),
            ],
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }

    /**
     * 비밀번호 만료 알림 데이터를 추출합니다 (#736).
     *
     * 다음 로그인 때 변경 단계가 나오므로 로그인 화면으로 안내한다 — 관리자는 관리자 로그인 화면.
     *
     * @param  array  $args  훅 인수 [$user, {expired_at, sessions_revoked}]
     * @return array 알림 데이터 (notifiable·notifiables·data·context)
     */
    private function extractPasswordExpired(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $extra = is_array($args[1] ?? null) ? $args[1] : [];
        $isAdmin = $user->isAdmin();

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => NotificationHookListener::RECIPIENT_NAME_PLACEHOLDER,
                'app_name' => config('app.name'),
                'action_url' => $isAdmin
                    ? ScreenUrl::absoluteForLinks('admin.login', '/admin/login', [], ScreenUrl::SCOPE_ADMIN)
                    : ScreenUrl::absoluteForLinks('auth.login', '/login'),
                'site_url' => $isAdmin ? SiteUrl::forOutboundLinks() : ScreenUrl::userLinkBase(),
                'expired_at' => (string) ($extra['expired_at'] ?? ''),
            ],
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }

    /**
     * 빈 결과를 반환합니다.
     *
     * @return array 데이터가 비어 있는 알림 결과
     */
    private function emptyResult(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }
}
