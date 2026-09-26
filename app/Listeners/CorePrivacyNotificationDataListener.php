<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;

/**
 * 개인정보(휴면) 알림 데이터 필터 리스너
 *
 * `core.privacy` 를 hook_prefix 로 쓰는 알림 정의 3종(휴면 사전 안내·전환 완료·탈퇴 예정)의
 * 치환값과 수신자 컨텍스트를 제공합니다. 수신자 결정 자체는 정의의 `recipients`
 * (`trigger_user`)에 위임하고, 여기서는 그 대상이 누구인지(`trigger_user_id`)만 알려 줍니다.
 *
 * 커맨드가 넘기는 인수는 `[$user, $extra]` 이며 `$extra` 에는 화면·본문에 실을 날짜가 담깁니다.
 * 날짜를 여기서 다시 계산하지 않는 이유: 판정과 문구가 각자 계산하면 하루 차이로 어긋나
 * "오늘 전환된다" 는 메일을 받은 다음 날 전환되는 식의 불일치가 생깁니다.
 */
class CorePrivacyNotificationDataListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.privacy.notification.extract_data' => [
                'method' => 'extractData',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다 (이 리스너는 필터만 사용).
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 알림 유형에 따라 데이터와 컨텍스트를 추출합니다.
     *
     * @param  array<string, mixed>  $default  기본 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array<int, mixed>  $args  훅에서 전달된 원본 인수
     * @return array<string, mixed> notifiable/notifiables/data/context 구조
     */
    public function extractData(array $default, string $type, array $args): array
    {
        return match ($type) {
            'dormant_pre_notice',
            'dormant_converted',
            'dormant_withdraw_notice' => $this->extractDormant($args),
            default => $default,
        };
    }

    /**
     * 휴면 생명주기 알림의 치환값을 추출합니다.
     *
     * @param  array<int, mixed>  $args  훅 인수 [$user, $extra]
     * @return array<string, mixed> notifiable/notifiables/data/context 구조
     */
    private function extractDormant(array $args): array
    {
        $user = $args[0] ?? null;

        if (! $user instanceof User) {
            return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
        }

        $extra = is_array($args[1] ?? null) ? $args[1] : [];
        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => (string) ($user->name ?? ''),
                'app_name' => config('app.name'),
                'action_url' => $baseUrl.'/login',
                'site_url' => $baseUrl,
                // 커맨드가 계산한 값을 그대로 쓴다 — 없으면 빈 문자열로 두고 문구가 날짜 없이
                // 읽히게 한다. 여기서 임의로 오늘 날짜를 넣으면 사실과 다른 고지가 나간다.
                'dormant_days' => (string) ($extra['dormant_days'] ?? ''),
                'scheduled_date' => (string) ($extra['scheduled_date'] ?? ''),
            ],
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }
}
