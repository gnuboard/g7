<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Listeners;

use Illuminate\Support\Facades\App;
use Plugins\Sirsoft\MessageBizppurio\Listeners\BalanceLowNotificationDataListener;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BalanceLowNotificationDataListener — 잔액부족 알림 본문 변수 치환 데이터 주입 검증.
 *
 * 회귀 배경: extract_data 구독 부재로 `{channel_label}`·`{result_code}` 등이 치환되지 않고
 * 중괄호째 노출되던 결함. 이 리스너가 훅 인자·사이트 메타를 data 로 채워 치환을 성립시킨다.
 */
class BalanceLowNotificationDataListenerTest extends PluginTestCase
{
    private BalanceLowNotificationDataListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        App::setLocale('ko');
        $this->listener = new BalanceLowNotificationDataListener;
    }

    private function emptyResult(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }

    /**
     * 문자(sms) 잔액부족: channel_label="문자", result_code 주입 + 사이트 메타 채움.
     */
    public function test_injects_variables_for_sms_balance_low(): void
    {
        $out = $this->listener->injectBalanceLowData(
            $this->emptyResult(),
            'bizppurio_balance_low',
            ['9070', 'sms'],
        );

        $data = $out['data'];
        $this->assertSame('9070', $data['result_code']);
        $this->assertSame('문자', $data['channel_label']);
        $this->assertSame('{recipient_name}', $data['name']);
        $this->assertNotSame('', $data['app_name']);
        $this->assertStringContainsString('/admin/plugins/sirsoft-message_bizppurio/settings', $data['settings_url']);
        $this->assertArrayHasKey('site_url', $data);
    }

    /**
     * 알림톡(alimtalk) 잔액부족: channel_label="알림톡", result_code=7436.
     */
    public function test_injects_variables_for_alimtalk_balance_low(): void
    {
        $out = $this->listener->injectBalanceLowData(
            $this->emptyResult(),
            'bizppurio_balance_low',
            ['7436', 'alimtalk'],
        );

        $this->assertSame('7436', $out['data']['result_code']);
        $this->assertSame('알림톡', $out['data']['channel_label']);
    }

    /**
     * lms 도 문자군으로 "문자" 라벨.
     */
    public function test_lms_maps_to_text_label(): void
    {
        $out = $this->listener->injectBalanceLowData(
            $this->emptyResult(),
            'bizppurio_balance_low',
            ['9071', 'lms'],
        );

        $this->assertSame('문자', $out['data']['channel_label']);
        $this->assertSame('9071', $out['data']['result_code']);
    }

    /**
     * 다른 알림 유형은 원본을 그대로 통과(변수 미주입).
     */
    public function test_ignores_other_notification_types(): void
    {
        $result = $this->emptyResult();
        $out = $this->listener->injectBalanceLowData($result, 'order_confirmed', ['9070', 'sms']);

        $this->assertSame($result, $out);
        $this->assertArrayNotHasKey('result_code', $out['data']);
    }

    /**
     * 알 수 없는 채널은 원본 문자열을 라벨로 유지(치환 실패보다 원본 노출).
     */
    public function test_unknown_channel_keeps_raw(): void
    {
        $out = $this->listener->injectBalanceLowData(
            $this->emptyResult(),
            'bizppurio_balance_low',
            ['9999', 'unknown'],
        );

        $this->assertSame('unknown', $out['data']['channel_label']);
    }

    /**
     * 주입된 data 로 알림 본문 템플릿의 변수가 실제 값으로 치환된다(엔드투엔드).
     */
    public function test_injected_data_resolves_template_body(): void
    {
        $out = $this->listener->injectBalanceLowData(
            $this->emptyResult(),
            'bizppurio_balance_low',
            ['9071', 'alimtalk'],
        );

        $body = '비즈뿌리오 잔액 부족으로 {channel_label} 발송이 실패했습니다 (코드: {result_code}).';
        $rendered = strtr($body, [
            '{channel_label}' => $out['data']['channel_label'],
            '{result_code}' => $out['data']['result_code'],
        ]);

        $this->assertSame('비즈뿌리오 잔액 부족으로 알림톡 발송이 실패했습니다 (코드: 9071).', $rendered);
        $this->assertStringNotContainsString('{', $rendered);
    }
}