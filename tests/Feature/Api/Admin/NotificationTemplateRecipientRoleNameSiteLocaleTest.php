<?php

namespace Tests\Feature\Api\Admin;

use App\Http\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 알림 템플릿 수신자 칩의 역할 이름이 사이트 기본 언어로 대체되는지 테스트 (#148 D7).
 *
 * 관리자 알림 템플릿 화면의 수신자 칩(`display_name(s)`)은 역할 이름에 지금 언어 값이 없으면
 * 한국어로 고정 대체했다. 이제 요청 언어 → 사이트 기본 언어 → 영어 순이고, 그래도 없으면
 * 종전처럼 역할 식별자를 보인다.
 */
class NotificationTemplateRecipientRoleNameSiteLocaleTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 요청 언어 값이 없으면 사이트 기본 언어(en) 이름을 쓴다.
     *
     * @scenario shape=three_letter
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_role_chip_name_falls_back_to_site_locale(): void
    {
        config(['app.site_locale' => 'en', 'app.fallback_locale' => 'ko']);
        app()->setLocale('fil');
        Role::create(['name' => ['ko' => '운영자 QA148', 'en' => 'Operator QA148'], 'identifier' => 'qa148-operator']);

        $recipients = $this->enrich([['type' => 'role', 'value' => ['qa148-operator']]]);

        $this->assertSame(['Operator QA148'], $recipients[0]['display_names']);
        $this->assertSame('Operator QA148', $recipients[0]['display_name']);
    }

    /**
     * 체인에 없는 언어만 있으면 종전 꼬리(역할 식별자)를 보인다.
     *
     * @scenario shape=region_tagged
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_role_chip_name_keeps_identifier_tail(): void
    {
        config(['app.site_locale' => 'en', 'app.fallback_locale' => 'ko']);
        app()->setLocale('fil-PH');
        Role::create(['name' => ['ko' => '운영자 QA148'], 'identifier' => 'qa148-ko-only']);

        $recipients = $this->enrich([['type' => 'role', 'value' => ['qa148-ko-only', 'qa148-missing']]]);

        $this->assertSame(['qa148-ko-only', 'qa148-missing'], $recipients[0]['display_names']);
    }

    /**
     * 리소스의 수신자 표시 이름 보강을 호출합니다.
     *
     * @param  array<int, array<string, mixed>>  $recipients  수신자 규칙
     * @return array<int, array<string, mixed>> 표시 이름이 붙은 수신자 규칙
     */
    private function enrich(array $recipients): array
    {
        $resource = new NotificationTemplateResource(new NotificationTemplate);
        $method = new \ReflectionMethod($resource, 'enrichRecipients');

        return $method->invoke($resource, $recipients);
    }
}
