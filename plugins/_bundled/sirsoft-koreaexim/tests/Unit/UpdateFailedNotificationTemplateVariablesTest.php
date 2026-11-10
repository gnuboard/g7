<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Unit;

use App\Models\NotificationTemplate;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Listeners\UpdateFailedNotificationDataListener;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Plugin;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

/**
 * 환율 갱신 실패 알림의 템플릿 변수가 모두 채워지고 선언되는지 검증합니다.
 *
 * 메일 제목은 `[{app_name}]` 으로 시작한다. 코어는 수신자 이름(`{recipient_name}`)만 대신 채우므로
 * 리스너가 사이트명을 넣지 않으면 관리자에게 `{app_name}` 글자가 그대로 간다.
 */
class UpdateFailedNotificationTemplateVariablesTest extends PluginTestCase
{
    /**
     * 정의의 모든 템플릿(제목·본문·링크)이 쓰는 변수를 리스너 데이터가 빠짐없이 채우는지 검증합니다.
     *
     * @scenario channel=mail
     *
     * @effects every_template_placeholder_is_filled
     *
     * @return void 반환값 없음
     */
    public function test_listener_data_fills_every_template_placeholder(): void
    {
        config(['app.name' => '테스트 사이트']);

        $run = new KoreaeximRateRun([
            'status' => RunStatus::NoData,
            'message' => 'probe',
            'search_date' => '2026-10-03',
        ]);
        $data = app(UpdateFailedNotificationDataListener::class)->extract([], 'koreaexim_update_failed', [$run])['data'] ?? [];

        $definition = (new Plugin)->getNotificationDefinitions()[0];
        $missing = [];
        foreach ($definition['templates'] as $template) {
            $text = implode(' ', array_merge(
                array_values($template['subject'] ?? []),
                array_values($template['body'] ?? []),
                [(string) ($template['click_url'] ?? '')],
            ));
            preg_match_all('/\{([a-z_]+)\}/', $text, $m);
            foreach (array_unique($m[1]) as $key) {
                if (! array_key_exists($key, $data)) {
                    $missing[] = $template['channel'].': {'.$key.'}';
                }
            }
        }

        $this->assertSame([], $missing, '리스너가 채우지 않는 템플릿 변수가 있어 글자 그대로 발송됩니다.');
        $this->assertSame('테스트 사이트', $data['app_name']);
    }

    /**
     * 상태 이름은 수신자 언어로 나간다 — 발송 시점 앱 언어로 미리 번역하면 영어 메일에 한국어 상태 이름이 섞였다.
     *
     * @scenario channel=mail
     *
     * @effects status_label_follows_recipient_locale
     *
     * @return void 반환값 없음
     */
    public function test_status_label_is_rendered_in_recipient_locale(): void
    {
        config(['app.supported_locales' => ['ko', 'en'], 'app.name' => 'QA']);
        app()->setLocale('ko');

        $run = new KoreaeximRateRun([
            'status' => RunStatus::NoData,
            'message' => 'probe',
            'search_date' => '2026-10-03',
        ]);
        $data = app(UpdateFailedNotificationDataListener::class)->extract([], 'koreaexim_update_failed', [$run])['data'] ?? [];

        $template = new NotificationTemplate;
        $template->subject = ['ko' => '{status_label}', 'en' => '{status_label}'];
        $template->body = ['ko' => '{status_label}', 'en' => '{status_label}'];

        app()->setLocale('en');
        $english = RunStatus::NoData->label();
        app()->setLocale('ko');
        $korean = RunStatus::NoData->label();

        $this->assertNotSame($korean, $english, '번역 파일에 두 언어 상태 이름이 있어야 이 시험이 뜻을 가진다.');
        $this->assertSame($english, $template->replaceVariables($data, 'en')['body']);
        $this->assertSame($korean, $template->replaceVariables($data, 'ko')['body']);
    }

    /**
     * 정의가 수신 대상(audience)과 템플릿이 쓰는 변수를 모두 선언하는지 검증합니다.
     *
     * @scenario channel=database
     *
     * @effects definition_declares_audience_and_variables
     *
     * @return void 반환값 없음
     */
    public function test_definition_declares_audience_and_every_used_variable(): void
    {
        $definition = (new Plugin)->getNotificationDefinitions()[0];
        $declared = array_column($definition['variables'], 'key');

        $this->assertSame('staff', $definition['audience'] ?? null, '관리자에게만 가는 알림이다.');
        foreach (['name', 'app_name', 'status_label', 'message', 'search_date', 'settings_url'] as $key) {
            $this->assertContains($key, $declared, "변수 {$key} 가 선언되지 않아 운영자가 편집 화면에서 알 수 없습니다.");
        }
    }
}
