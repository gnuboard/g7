<?php

namespace Plugins\Sirsoft\Totp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\TimezoneHelper;
use App\Models\User;
use App\Support\LocalizedValue;
use App\Support\ScreenUrl;
use App\Support\SiteUrl;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\Totp\Plugin;

/**
 * 인증 앱 보안 알림 3종(등록·해제·백업 코드 재발급)의 치환 변수를 만들고, 코어 알림 복원 경로에 기본 정의를 제공합니다.
 *
 * 세션을 훔친 쪽이 인증 앱을 바꾸거나 끄거나 백업 코드를 새로 받아도 계정 주인이 알 길이 없었다(KVE-2026-3194).
 * 수신자는 훅의 첫 인자(대상 회원)다 — 관리자 강제 해제도 운영자가 아니라 해제된 회원에게 간다.
 *
 * @since 1.0.3
 */
class TotpNotificationDataListener implements HookListenerInterface
{
    /** 이 리스너가 다루는 알림 유형 */
    private const TYPES = ['totp_enrolled', 'totp_disabled', 'totp_backup_codes_regenerated'];

    /**
     * 구독 메서드와 동기 실행 계약을 선언합니다.
     *
     * @return array<string, array<string, mixed>> 훅 → 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-totp.notification.extract_data' => ['method' => 'extract', 'type' => 'filter', 'priority' => 10],
            'core.notification.filter_default_definitions' => ['method' => 'defaults', 'type' => 'filter', 'priority' => 20],
        ];
    }

    /**
     * 액션 훅 진입점 (필터만 쓰므로 비어 있다).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 코어 알림 복원 경로에 플러그인이 소유한 기본 정의를 제공합니다.
     *
     * @param  array<int, array<string, mixed>>  $definitions  다른 확장이 제공한 기본 정의
     * @return array<int, array<string, mixed>> 소유 정보를 붙인 기본 정의
     */
    public function defaults(array $definitions): array
    {
        foreach ((new Plugin)->getNotificationDefinitions() as $definition) {
            $definitions[] = $definition + ['extension_type' => 'plugin', 'extension_identifier' => 'sirsoft-totp'];
        }

        return $definitions;
    }

    /**
     * 알림 템플릿의 치환 변수와 수신자 문맥을 반환합니다.
     *
     * @param  array<string, mixed>  $data  기본 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array<int, mixed>  $args  훅 원본 인자 — [User $user, array $context]
     * @return array<string, mixed> 치환 변수(data)·수신자 문맥(context)을 채운 구조
     */
    public function extract(array $data, string $type, array $args): array
    {
        $user = $args[0] ?? null;

        if (! in_array($type, self::TYPES, true) || ! $user instanceof User) {
            return $data;
        }

        $context = is_array($args[1] ?? null) ? $args[1] : [];

        $data['data'] = [
            'name' => (string) ($user->name ?? ''),
            'app_name' => (string) config('app.name'),
            // 발송 시점에는 수신자 시간대를 모른다 — 이커머스 입금 기한 알림과 같이 사이트 기본 시간대로 적는다
            'occurred_at' => Carbon::parse($context['occurred_at'] ?? Carbon::now())
                ->setTimezone(TimezoneHelper::getSiteTimezone())
                // audit:allow datetime-display-user-timezone reason: 바로 위에서 사이트 시간대로 바꾼 값이다(알림 = 사이트 시간대 규정)
                ->format('Y-m-d H:i'),
            'ip_address' => (string) ($context['ip_address'] ?? '-'),
            'actor_label' => $this->actorLabel($context),
            'security_url' => $this->securityUrl($user),
        ];
        $data['context'] = [
            'trigger_user_id' => $user->id,
            'trigger_user' => $user,
        ];

        return $data;
    }

    /**
     * 받는 사람이 들어갈 수 있는 보안 설정 화면 주소를 반환합니다. (1.1.1)
     *
     * 받는 사람은 언제나 대상 회원 자신이다. 관리자면 플러그인 설정 화면, 일반 회원이면 회원 화면의 내 정보(보안 카드)다 —
     * 종전에는 관리자 주소로 고정돼 일반 회원이 링크를 열 수 없었다.
     *
     * @param  User  $user  받는 회원
     * @return string 절대 URL
     */
    private function securityUrl(User $user): string
    {
        if ($user->isAdmin()) {
            return SiteUrl::absolute('/admin/plugins/sirsoft-totp/settings');
        }

        return ScreenUrl::absoluteForLinks('mypage.profile', '/mypage/profile');
    }

    /**
     * 누가 바꿨는지 수신자 언어로 표기합니다.
     *
     * 관리자 강제 해제는 context 에 `actor_id` 가 있고, 서버 셸(`totp:reset`)은 `actor_id` 가 null 이다.
     *
     * @param  array<string, mixed>  $context  훅 context
     * @return string|array<string, string> 실행 주체 표기(코어 7.1.5+ 는 언어별 묶음)
     */
    private function actorLabel(array $context): string|array
    {
        $key = match (true) {
            ! array_key_exists('actor_id', $context) && ! ($context['by_admin'] ?? false) => 'sirsoft-totp::messages.notifications.actor_self',
            ($context['actor_id'] ?? null) === null => 'sirsoft-totp::messages.notifications.actor_console',
            default => 'sirsoft-totp::messages.notifications.actor_admin',
        };

        return method_exists(LocalizedValue::class, 'translateAll')
            ? LocalizedValue::translateAll(fn () => __($key))
            : __($key);
    }
}
