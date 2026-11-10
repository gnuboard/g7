<?php

namespace Tests\Unit\Models;

use App\Models\NotificationTemplate;
use App\Support\LocalizedValue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 알림 변수에 언어별 값 묶음(`['ko' => …, 'en' => …]`)을 넘기면 수신자 언어로 골라 치환하는지 검증합니다.
 *
 * 알림 데이터는 발송 시점에 한 번 만들어 모든 수신자에게 쓴다. 상태 이름 같은 번역 문구를 그때의 앱 언어로
 * 미리 번역해 넣으면, 영어 수신자의 영어 메일 본문에 한국어 상태 이름이 섞였다. 확장은 번역 문구를 지원 언어마다
 * 만든 묶음으로 넘기고, 코어는 수신자 언어(없으면 사이트 기본 언어 → 영어)로 고른다.
 *
 * @scenario-manifest tests/scenarios/notification-recipient-locale-values.yaml
 */
class NotificationTemplateRecipientLocaleValueTest extends TestCase
{
    /**
     * 한국어·영어 템플릿을 만듭니다.
     *
     * @return NotificationTemplate 템플릿
     */
    private function template(): NotificationTemplate
    {
        $template = new NotificationTemplate;
        $template->subject = ['ko' => '[{app_name}] 환율 갱신 실패', 'en' => '[{app_name}] Exchange rate update failed'];
        $template->body = ['ko' => '<p>상태: {status_label}</p>', 'en' => '<p>Status: {status_label}</p>'];

        return $template;
    }

    /**
     * 언어별 값 묶음은 수신자 언어의 값으로 치환한다.
     *
     * @scenario value=locale_map
     *
     * @effects locale_map_value_picks_recipient_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function locale_map_value_is_picked_by_recipient_locale(): void
    {
        $data = ['app_name' => 'QA', 'status_label' => ['ko' => '데이터 없음', 'en' => 'No data']];

        $this->assertSame('<p>Status: No data</p>', $this->template()->replaceVariables($data, 'en')['body']);
        $this->assertSame('<p>상태: 데이터 없음</p>', $this->template()->replaceVariables($data, 'ko')['body']);
    }

    /**
     * 수신자 언어 값이 없으면 사이트 기본 언어 → 영어 순으로 대신한다(LocalizedValue 규칙).
     *
     * @scenario value=locale_map_missing_locale
     *
     * @effects locale_map_value_falls_back_like_localized_value
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function missing_recipient_locale_falls_back_to_site_locale(): void
    {
        config(['app.site_locale' => 'ko']);
        $data = ['app_name' => 'QA', 'status_label' => ['ko' => '데이터 없음', 'en' => 'No data']];

        // ja 템플릿이 없어 본문은 사이트 기본 언어(ko) 판, 값도 같은 규칙으로 ko
        $this->assertSame('데이터 없음', LocalizedValue::pick($data['status_label'], 'ja'));
        $this->assertSame('<p>상태: 데이터 없음</p>', $this->template()->replaceVariables($data, 'ja')['body']);

        config(['app.site_locale' => 'en']);
        $this->assertSame('No data', LocalizedValue::pick($data['status_label'], 'ja'));
    }

    /**
     * 문자열 값은 종전 그대로 치환한다.
     *
     * @scenario value=string
     *
     * @effects string_value_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function string_value_is_unchanged(): void
    {
        $rendered = $this->template()->replaceVariables(['app_name' => 'QA', 'status_label' => '데이터 없음'], 'en');

        $this->assertSame('[QA] Exchange rate update failed', $rendered['subject']);
        $this->assertSame('<p>Status: 데이터 없음</p>', $rendered['body']);
    }

    /**
     * 확장이 번역 문구를 지원 언어마다 만들어 묶음으로 넘길 수 있다 — 만드는 동안 바꾼 앱 언어는 되돌린다.
     *
     * @scenario value=locale_map
     *
     * @effects translate_all_builds_locale_map
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function translate_all_builds_a_map_for_every_supported_locale(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);
        app()->setLocale('ko');

        $map = LocalizedValue::translateAll(fn () => app()->getLocale() === 'en' ? 'No data' : '데이터 없음');

        $this->assertSame(['ko' => '데이터 없음', 'en' => 'No data'], $map);
        $this->assertSame('ko', app()->getLocale());
    }
}
