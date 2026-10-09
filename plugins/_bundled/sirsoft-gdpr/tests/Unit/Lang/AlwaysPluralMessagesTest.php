<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Lang;

use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Gdpr\Services\GdprAnnualNoticeService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 괄호 없이 늘 복수로 적혀 있던 GDPR 서버 영어 문구의 단·복수 테스트.
 *
 * 연간 통지 명령 출력 「1 recipients queued」 를 `단수|복수` + `trans_choice` 로 1 이면 단수로 고른다.
 * 한국어 출력은 그대로다.
 */
class AlwaysPluralMessagesTest extends PluginTestCase
{
    /**
     * 사전을 작업 원본(_bundled)에서 읽도록 네임스페이스를 다시 잡습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace('sirsoft-gdpr', base_path('plugins/_bundled/sirsoft-gdpr/lang'));
    }

    /**
     * 연간 통지 명령은 접수한 수신자가 1 명이면 「1 recipient queued」 다.
     *
     * @scenario surface=console_output
     *
     * @effects console_output_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function annual_notice_command_output_is_singular_for_one(): void
    {
        foreach ([1 => '1 recipient queued', 2 => '2 recipients queued'] as $queued => $expected) {
            $service = \Mockery::mock(GdprAnnualNoticeService::class);
            $service->shouldReceive('run')->andReturn($queued);
            $this->app->instance(GdprAnnualNoticeService::class, $service);
            $this->app->setLocale('en');

            $this->artisan('sirsoft-gdpr:send-annual-notices')->expectsOutput($expected)->assertSuccessful();
        }

        $this->app->setLocale('ko');
        $this->assertSame(
            __('sirsoft-gdpr::messages.annual_notice.queued_count', ['count' => 1]),
            trans_choice('sirsoft-gdpr::messages.annual_notice.queued_count', 1)
        );
    }
}
