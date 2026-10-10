<?php

namespace Plugins\Sirsoft\Marketing\Tests\Unit\Lang;

use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

/**
 * 마케팅 플러그인 백엔드 영어 메시지의 단·복수 테스트.
 *
 * 종전 영어 문구는 수와 상관없이 `(s)` 를 붙였다. 1 이면 단수, 0·2 이상이면 복수로 고르고(`단수|복수` + trans_choice),
 * 한국어 문구는 그대로다.
 */
class BackendPluralMessagesTest extends PluginTestCase
{
    /**
     * 사전을 작업 원본(_bundled)에서 읽도록 네임스페이스를 다시 잡습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace('sirsoft-marketing', base_path('plugins/_bundled/sirsoft-marketing/lang'));
    }

    /**
     * 동의 회원이 있는 채널 삭제 차단 문구는 1 명이면 단수, 0·2 명이면 복수다. 한국어는 그대로다.
     *
     * @scenario surface=validation_message
     *
     * @effects validation_message_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function channel_has_consents_message_follows_count(): void
    {
        $key = 'sirsoft-marketing::messages.channel_has_consents';

        $this->app->setLocale('en');
        $this->assertSame('Channel (sms) has 1 consented member and cannot be deleted.', trans_choice($key, 1, ['key' => 'sms']));
        $this->assertSame('Channel (sms) has 2 consented members and cannot be deleted.', trans_choice($key, 2, ['key' => 'sms']));
        $this->assertSame('Channel (sms) has 0 consented members and cannot be deleted.', trans_choice($key, 0, ['key' => 'sms']));

        $this->app->setLocale('ko');
        $this->assertSame(__($key, ['key' => 'sms', 'count' => 2]), trans_choice($key, 2, ['key' => 'sms']));
    }
}
