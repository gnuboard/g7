<?php

namespace Plugins\Sirsoft\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Listeners\NotificationHookListener;
use App\Models\User;
use App\Support\ScreenUrl;
use Plugins\Sirsoft\SocialLogin\Plugin;
use Plugins\Sirsoft\SocialLogin\Services\ProviderSettings;

/**
 * 이 플러그인 알림 5종의 치환 값과 수신 문맥을 만듭니다.
 *
 * 코어 알림 발송기는 정의의 훅이 발화하면 필터 `sirsoft-social_login.notification.extract_data` 로 본문 자리(`{name}` 등)에
 * 넣을 값을 묻는다. 수신자는 정의의 `recipients` 가 정한다 — 회원 대상 알림은 문맥의 `trigger_user_id` 로 대상 회원을 알려 준다.
 * 이 리스너가 없으면 메일 본문에 중괄호가 그대로 나간다. 코어 「기본값으로 복원」 경로에도 기본 정의를 돌려준다.
 *
 * @since 1.0.0
 */
class NotificationDataListener implements HookListenerInterface
{
    /**
     * @param  ProviderSettings  $settings  제공자 표시 이름
     */
    public function __construct(private readonly ProviderSettings $settings) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-social_login.notification.extract_data' => ['method' => 'extract', 'type' => 'filter', 'priority' => 10, 'sync' => true],
            'core.notification.filter_default_definitions' => ['method' => 'defaults', 'type' => 'filter', 'priority' => 20, 'sync' => true],
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
     * 코어 알림 복원 경로에 이 플러그인의 기본 정의를 더합니다.
     *
     * @param  array<int, array<string, mixed>>  $definitions  다른 확장이 더한 기본 정의
     * @return array<int, array<string, mixed>> 정의
     */
    public function defaults(array $definitions): array
    {
        foreach ((new Plugin)->getNotificationDefinitions() as $definition) {
            $definitions[] = $definition + ['extension_type' => 'plugin', 'extension_identifier' => 'sirsoft-social_login'];
        }

        return $definitions;
    }

    /**
     * 알림 유형별 치환 값을 만듭니다.
     *
     * @param  array<string, mixed>  $default  기본 구조 (notifiable·notifiables·data·context)
     * @param  string  $type  알림 유형
     * @param  array<int, mixed>  $args  훅 인자
     * @return array<string, mixed> 추출 결과
     */
    public function extract(array $default, string $type, array $args): array
    {
        return match ($type) {
            'social_login_last_login_method_removed' => $this->forMember($default, $args, [
                'reset_url' => ScreenUrl::absoluteForLinks('auth.forgot_password', '/forgot-password'),
                'site_url' => ScreenUrl::userLinkBase(),
            ]),
            'social_login_link_removed_by_admin' => $this->forMember($default, $args, [
                'site_url' => ScreenUrl::userLinkBase(),
            ]),
            'social_login_provider_failing' => $this->forAdmins($default, [
                'provider_label' => $this->label($args[0] ?? null),
                'failure_count' => (string) (int) ($args[1] ?? 0),
                'last_error_code' => (string) ($args[2] ?? ''),
                'diagnostics_url' => $this->adminUrl('diagnostics'),
            ]),
            'social_login_signup_surge' => $this->forAdmins($default, [
                'provider_label' => $this->label($args[0] ?? null),
                'count' => (string) (int) ($args[1] ?? 0),
                'threshold' => (string) (int) ($args[2] ?? 0),
                'stats_url' => $this->adminUrl('stats'),
            ]),
            'social_login_webhook_failed' => $this->forAdmins($default, [
                'provider_label' => $this->label(is_array($args[0] ?? null) ? ($args[0]['provider_key'] ?? null) : null),
                'event_type' => $this->eventTypeLabel(is_array($args[0] ?? null) ? (string) ($args[0]['event_type'] ?? '') : ''),
                'error' => is_array($args[0] ?? null) ? (string) ($args[0]['error'] ?? '') : '',
                'diagnostics_url' => $this->adminUrl('diagnostics'),
            ]),
            default => $default,
        };
    }

    /**
     * 회원 대상 알림 값 — 인자 (User $user, string $providerKey).
     *
     * @param  array<string, mixed>  $default  기본 구조
     * @param  array<int, mixed>  $args  훅 인자
     * @param  array<string, string>  $extra  더할 값
     * @return array<string, mixed> 추출 결과 (대상 회원이 없으면 발송 건너뜀)
     */
    private function forMember(array $default, array $args, array $extra): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return ['context' => ['skip' => true]] + $default;
        }

        return array_merge($default, [
            'data' => [
                'app_name' => (string) config('app.name'),
                // 받는 사람이 이 회원 본인이다. 자리표시를 실으면 코어가 받는 사람과 채널마다 표시 이름으로 푼다.
                'name' => NotificationHookListener::RECIPIENT_NAME_PLACEHOLDER,
                'provider_label' => $this->label($args[1] ?? null),
            ] + $extra,
            'context' => ['trigger_user_id' => (int) $user->getKey(), 'trigger_user' => $user],
        ]);
    }

    /**
     * 관리자 대상 알림 값 — 수신자는 정의의 역할 규칙이 정한다.
     *
     * @param  array<string, mixed>  $default  기본 구조
     * @param  array<string, string>  $data  치환 값
     * @return array<string, mixed> 추출 결과
     */
    private function forAdmins(array $default, array $data): array
    {
        return array_merge($default, ['data' => ['app_name' => (string) config('app.name'), 'name' => NotificationHookListener::RECIPIENT_NAME_PLACEHOLDER] + $data]);
    }

    /**
     * 제공자 표시 이름.
     *
     * @param  mixed  $key  제공자 키
     * @return string 표시 이름
     */
    private function label(mixed $key): string
    {
        return is_string($key) && $key !== '' ? $this->settings->label($key) : '';
    }

    /**
     * 웹훅 종류의 읽을 수 있는 이름.
     *
     * @param  string  $type  종류 값
     * @return string 이름
     */
    private function eventTypeLabel(string $type): string
    {
        $key = 'sirsoft-social_login::notifications.event_types.'.$type;

        return $type !== '' && __($key) !== $key ? (string) __($key) : $type;
    }

    /**
     * 관리자 화면 절대 주소.
     *
     * @param  string  $screen  diagnostics·stats
     * @return string 주소
     */
    private function adminUrl(string $screen): string
    {
        return ScreenUrl::absoluteForLinks('sirsoft-social_login.admin.'.$screen, '/admin/plugins/sirsoft-social_login/'.$screen, [], ScreenUrl::SCOPE_ADMIN);
    }
}
