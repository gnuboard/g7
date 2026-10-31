<?php

namespace Tests\Unit\Notifications;

use App\Extension\HookManager;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Notifications\NotificationVariables;
use App\Services\NotificationTemplateService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 알림 변수에 언어별 값(`{로케일: 문자열}`)을 줄 수 있는지 검증합니다.
 *
 * 알림 제목·본문 틀은 받는 사람 언어로 고르지만 변수 값은 문자열 하나만 받아(`replaceVariables()` 의 `(string) $value`),
 * IdP 이름처럼 언어마다 다른 값이 알림을 일으킨 요청의 언어로 고정됐다(실측: 한국어 틀 + 「Mock company account」 30건).
 * 값이 언어별 배열이면 받는 사람 언어 → 사이트 기본 언어 → 영어 → 첫 값 순(`LocalizedValue::pick`)으로 고른다.
 * 문자열 변수는 그대로이고, 언어 배열이 아닌 배열 값은 치환하지 않는다(종전에는 배열→문자열 변환 오류).
 *
 * @scenario-manifest tests/scenarios/notification-localized-variable.yaml
 */
class NotificationLocalizedVariableTest extends TestCase
{
    /** 시험용 알림 유형 */
    private const TYPE = 'zz_localized_variable';

    /**
     * 메일·사이트 알림 템플릿을 메모리 객체로 대신합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.site_locale' => 'ko']);

        $template = new NotificationTemplate;
        $template->subject = ['ko' => '[{idp_label}] 연속 실패', 'en' => '[{idp_label}] repeated failures'];
        $template->body = ['ko' => '<p>{idp_label} 로그인이 {count}번 실패했습니다.</p>', 'en' => '<p>{idp_label} sign-in failed {count} times.</p>'];
        $template->click_url = '/admin/sso?label={idp_label}';
        $template->is_active = true;

        $service = $this->createMock(NotificationTemplateService::class);
        $service->method('resolve')->willReturn($template);
        $this->app->instance(NotificationTemplateService::class, $service);
    }

    /**
     * 언어별 IdP 이름을 실은 알림을 만듭니다.
     *
     * @param  array<string, mixed>  $extra  덧붙일 변수
     * @return GenericNotification 알림
     */
    private function notification(array $extra = []): GenericNotification
    {
        return new GenericNotification(self::TYPE, 'core.test', array_merge([
            'idp_label' => ['ko' => '모의 회사 계정', 'en' => 'Mock company account'],
            'count' => 3,
        ], $extra));
    }

    /**
     * 주어진 언어를 쓰는 수신자를 만듭니다.
     *
     * @param  string  $language  수신자 언어
     * @return User 수신자
     */
    private function recipient(string $language): User
    {
        return new User(['email' => $language.'@example.com', 'language' => $language]);
    }

    /**
     * 메일 제목·본문이 받는 사람 언어의 변수 값으로 채워진다.
     *
     * @return void 반환값 없음
     *
     * @scenario channel=mail
     *
     * @effects localized_value_follows_recipient
     */
    #[Test]
    public function mail_uses_each_recipients_language_value(): void
    {
        $notification = $this->notification();

        $ko = $notification->toMail($this->recipient('ko'));
        $en = $notification->toMail($this->recipient('en'));

        $this->assertSame('[모의 회사 계정] 연속 실패', $ko->envelope()->subject);
        $this->assertSame('[Mock company account] repeated failures', $en->envelope()->subject);
        $this->assertSame('<p>모의 회사 계정 로그인이 3번 실패했습니다.</p>', $ko->content()->with['body']);
        $this->assertSame('<p>Mock company account sign-in failed 3 times.</p>', $en->content()->with['body']);
    }

    /**
     * 사이트 알림의 제목·본문·이동 주소·저장 data 도 받는 사람 언어 값이다.
     *
     * @return void 반환값 없음
     *
     * @scenario channel=database
     *
     * @effects localized_value_follows_recipient
     */
    #[Test]
    public function database_payload_uses_each_recipients_language_value(): void
    {
        $notification = $this->notification();

        $ko = $notification->toArray($this->recipient('ko'));
        $en = $notification->toArray($this->recipient('en'));

        $this->assertSame('[모의 회사 계정] 연속 실패', $ko['subject']);
        $this->assertSame('[Mock company account] repeated failures', $en['subject']);
        $this->assertSame('/admin/sso?label=모의 회사 계정', $ko['click_url']);
        $this->assertSame('/admin/sso?label=Mock company account', $en['click_url']);
        $this->assertSame('모의 회사 계정', $ko['data']['idp_label']);
        $this->assertSame('Mock company account', $en['data']['idp_label']);
        $this->assertSame(3, $en['data']['count']);
    }

    /**
     * 받는 사람 언어 값이 없으면 사이트 기본 언어 → 영어 → 첫 값 순으로 고른다.
     *
     * @return void 반환값 없음
     *
     * @scenario channel=helper
     *
     * @effects localized_value_falls_back
     */
    #[Test]
    public function missing_language_falls_back_to_site_then_english_then_first(): void
    {
        $this->assertSame('모의', NotificationVariables::resolve(['v' => ['ko' => '모의', 'en' => 'Mock']], 'ja')['v']);
        $this->assertSame('Mock', NotificationVariables::resolve(['v' => ['en' => 'Mock', 'ja' => 'モック']], 'fr')['v']);
        $this->assertSame('モック', NotificationVariables::resolve(['v' => ['ja' => 'モック']], 'fr')['v']);
        $this->assertSame('Mock', NotificationVariables::resolve(['v' => ['ko' => '  ', 'en' => 'Mock']], 'ko')['v']);
    }

    /**
     * 문자열 변수는 그대로이고, 언어 배열이 아닌 배열 값은 치환하지 않으며 오류도 내지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario channel=helper
     *
     * @effects plain_values_unchanged
     */
    #[Test]
    public function plain_and_structured_values_are_left_alone(): void
    {
        $template = new NotificationTemplate;
        $template->subject = ['ko' => '{name}/{items}/{empty}'];
        $template->body = ['ko' => '{name}'];

        $rendered = $template->replaceVariables([
            'name' => '홍길동',
            'items' => [['sku' => 'A-1'], ['sku' => 'B-2']],
            'empty' => null,
        ], 'ko');

        $this->assertSame('홍길동/{items}/', $rendered['subject']);
        $this->assertSame(
            ['name' => '홍길동', 'items' => [['sku' => 'A-1'], ['sku' => 'B-2']]],
            NotificationVariables::resolve(['name' => '홍길동', 'items' => [['sku' => 'A-1'], ['sku' => 'B-2']]], 'en'),
        );
    }

    /**
     * 확장 채널(`{접두사}.notification.to_{채널}` 필터)에도 받는 사람 언어로 고른 data 가 간다.
     *
     * @return void 반환값 없음
     *
     * @scenario channel=extension
     *
     * @effects localized_value_follows_recipient
     */
    #[Test]
    public function extension_channel_receives_resolved_data(): void
    {
        $received = null;
        HookManager::addFilter('core.test.notification.to_zzsms', function (array $data) use (&$received) {
            $received = $data;

            return $data;
        });

        try {
            $this->notification()->toZzsms($this->recipient('en'));
        } finally {
            HookManager::clearFilter('core.test.notification.to_zzsms');
        }

        $this->assertSame('Mock company account', $received['idp_label'] ?? null);
        $this->assertSame(3, $received['count'] ?? null);

        // 자체 템플릿으로 본문을 만드는 채널 드라이버가 쓰는 getDataFor() 도 같은 규칙이다.
        $this->assertSame('모의 회사 계정', $this->notification()->getDataFor($this->recipient('ko'))['idp_label']);
    }
}
