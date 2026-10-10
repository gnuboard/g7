<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;

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
     * @return array
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
     * @return void
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
     * @return array
     */
    private function extractWelcome(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => $user->name ?? '',
                'app_name' => config('app.name'),
                'action_url' => "{$baseUrl}/login",
                'site_url' => $baseUrl,
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
     * @param  array  $args  훅 인수 [$user, $extra]
     * @return array
     */
    private function extractResetPassword(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $extra = $args[1] ?? [];
        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => $user->name ?? '',
                'app_name' => config('app.name'),
                'action_url' => $extra['reset_url'] ?? "{$baseUrl}/reset-password",
                'expire_minutes' => (string) config('auth.passwords.users.expire', 60),
                'site_url' => $baseUrl,
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
     * @param  array  $args  훅 인수 [$user]
     * @return array
     */
    private function extractPasswordChanged(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => $user->name ?? '',
                'app_name' => config('app.name'),
                'action_url' => "{$baseUrl}/login",
                'site_url' => $baseUrl,
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
     * @return array
     */
    private function extractPasswordExpiryNotice(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $extra = is_array($args[1] ?? null) ? $args[1] : [];
        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => $user->name ?? '',
                'app_name' => config('app.name'),
                'action_url' => $user->isAdmin()
                    ? "{$baseUrl}/admin/users/{$user->uuid}/edit"
                    : "{$baseUrl}/mypage/change-password",
                'site_url' => $baseUrl,
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
     * @return array
     */
    private function extractPasswordExpired(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $extra = is_array($args[1] ?? null) ? $args[1] : [];
        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => $user->name ?? '',
                'app_name' => config('app.name'),
                'action_url' => $user->isAdmin() ? "{$baseUrl}/admin/login" : "{$baseUrl}/login",
                'site_url' => $baseUrl,
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
     * @return array
     */
    private function emptyResult(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }
}
