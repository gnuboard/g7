<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * SEO 알림 데이터 필터 리스너
 *
 * notification_definitions 의 `core.seo.notification.extract_data` 필터를 처리하여
 * 사이트맵 재생성 완료/실패 알림 발송에 필요한 데이터와 컨텍스트를 제공합니다.
 *
 * 정책: 사이트맵 재생성은 스케줄러/증분/봇에 의해서도 완료되므로, 관리자가 직접
 * 실행(수동 재생성)한 경우에만 알림을 보냅니다. 수동 실행 여부는 훅에 함께 전달된
 * 사용자 ID(triggered_by)로 판정하며, 없으면 context.skip 으로 발송을 중단시킵니다.
 * 수신자 결정은 notification_definitions.recipients(trigger_user) 설정에 위임합니다.
 */
class SeoNotificationDataListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 훅 이름 → 메서드/우선순위/타입 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.seo.notification.extract_data' => [
                'method' => 'extractData',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다(HookListenerInterface 필수 메서드).
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void {}

    /**
     * 알림 유형에 따라 데이터와 컨텍스트를 추출합니다.
     *
     * @param  array  $default  기본 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array  $args  훅에서 전달된 원본 인수 [$result, $triggeredBy]
     * @return array{notifiable: null, notifiables: null, data: array, context: array}
     */
    public function extractData(array $default, string $type, array $args): array
    {
        return match ($type) {
            'sitemap_regenerated' => $this->extractRegenerated($args),
            'sitemap_regenerate_failed' => $this->extractRegenerateFailed($args),
            default => $default,
        };
    }

    /**
     * 사이트맵 재생성 완료 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$result, $triggeredBy]
     * @return array
     */
    private function extractRegenerated(array $args): array
    {
        $triggeredBy = $args[1] ?? null;
        if (! is_int($triggeredBy)) {
            return $this->skip();
        }

        $result = is_array($args[0] ?? null) ? $args[0] : [];
        $data = $result['data'] ?? [];
        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'app_name' => config('app.name'),
                'url_count' => (string) ($data['url_count'] ?? 0),
                'child_count' => (string) ($data['child_count'] ?? 1),
                'action_url' => "{$baseUrl}/admin/settings?tab=seo",
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $triggeredBy,
            ],
        ];
    }

    /**
     * 사이트맵 재생성 실패 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$result, $triggeredBy]
     * @return array
     */
    private function extractRegenerateFailed(array $args): array
    {
        $triggeredBy = $args[1] ?? null;
        if (! is_int($triggeredBy)) {
            return $this->skip();
        }

        $result = is_array($args[0] ?? null) ? $args[0] : [];
        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'app_name' => config('app.name'),
                'error' => (string) ($result['message'] ?? ''),
                'action_url' => "{$baseUrl}/admin/settings?tab=seo",
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $triggeredBy,
            ],
        ];
    }

    /**
     * 발송을 건너뛰도록 지시하는 결과를 반환합니다(비수동 재생성 = 유발 관리자 없음).
     *
     * @return array
     */
    private function skip(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => ['skip' => true]];
    }
}
