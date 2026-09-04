<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;

/**
 * 잔액부족 알림(bizppurio_balance_low)의 본문 변수를 채우는 extract_data 필터 리스너.
 *
 * 코어 알림 시스템은 템플릿 본문의 `{변수}` 를 extract_data 필터가 돌려준 `data` 값으로만
 * 치환한다(app/Listeners/NotificationHookListener::dispatch). 잔액부족 알림은
 * `sirsoft-message_bizppurio.balance.low` 훅으로 발화되는데, 그 훅 인자(결과코드·채널)를
 * data 로 옮겨주는 구독이 없어 `{channel_label}`·`{result_code}` 등이 치환되지 않고
 * 중괄호째 노출됐다. 이 리스너가 그 훅 인자와 사이트 메타를 data 로 채워 치환을 성립시킨다.
 *
 * 훅 인자 순서: (string $resultCode, string $channel) — WebhookReportService::notifyBalanceLowOnce.
 */
class BalanceLowNotificationDataListener implements HookListenerInterface
{
    /** 이 리스너가 변수를 채우는 알림 유형 */
    private const NOTIFICATION_TYPE = 'bizppurio_balance_low';

    /** 잔액부족 알림 진입점(설정 페이지) 경로 */
    private const SETTINGS_PATH = '/admin/plugins/sirsoft-message_bizppurio/settings';

    /**
     * 구독할 훅 목록 반환.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-message_bizppurio.notification.extract_data' => [
                'method' => 'injectBalanceLowData',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 필터 메서드로 처리).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * 잔액부족 알림의 data 에 본문 치환 변수를 채웁니다.
     *
     * extract_data 결과(`{notifiable, notifiables, data, context}`)를 받아, 대상이
     * 잔액부족 알림이면 훅 인자(결과코드·채널)와 사이트 메타를 data 에 덧붙여 반환한다.
     * 그 외 유형은 원본을 그대로 통과시킨다. `name` 은 코어가 수신자별로 치환하도록
     * `{recipient_name}` placeholder 를 넣는다(NotificationHookListener 폴백 규약).
     *
     * @param  array<string, mixed>  $result  extract_data 결과
     * @param  string  $type  알림 정의 유형
     * @param  array<int, mixed>  $args  훅 원본 인수 ([$resultCode, $channel])
     * @return array<string, mixed> 변수가 채워진(또는 원본) 결과
     */
    public function injectBalanceLowData(array $result, string $type, array $args): array
    {
        if ($type !== self::NOTIFICATION_TYPE) {
            return $result;
        }

        $resultCode = (string) ($args[0] ?? '');
        $channel = (string) ($args[1] ?? '');

        $result['data'] = array_merge(
            $result['data'] ?? [],
            [
                'name' => '{recipient_name}',
                'app_name' => (string) config('app.name', ''),
                'result_code' => $resultCode,
                'channel_label' => $this->channelLabel($channel),
                'settings_url' => rtrim((string) config('app.url', ''), '/').self::SETTINGS_PATH,
                'site_url' => (string) config('app.url', ''),
            ],
        );

        return $result;
    }

    /**
     * 채널 문자열을 잔액부족 알림용 채널군 라벨(문자/알림톡)로 변환합니다.
     *
     * SMS/LMS 는 "문자" 로 묶고 알림톡은 "알림톡" 으로 표기한다. 알 수 없는 채널은
     * 원본 문자열을 그대로 반환한다(치환 실패보다 원본 노출이 안전).
     *
     * @param  string  $channel  채널 문자열 (sms/lms/alimtalk)
     * @return string 채널군 라벨
     */
    private function channelLabel(string $channel): string
    {
        $enum = DispatchChannel::tryFrom($channel);

        if ($enum === null) {
            return $channel;
        }

        return $enum->isText()
            ? __('sirsoft-message_bizppurio::messages.channel_group.text')
            : __('sirsoft-message_bizppurio::messages.channel_group.alimtalk');
    }
}