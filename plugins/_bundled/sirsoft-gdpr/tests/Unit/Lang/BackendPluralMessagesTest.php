<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Lang;

use App\Helpers\ResponseHelper;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 플러그인 백엔드 영어 메시지의 단·복수 테스트.
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

        $this->app['translator']->addNamespace('sirsoft-gdpr', base_path('plugins/_bundled/sirsoft-gdpr/lang'));
    }

    /**
     * 동의 일괄 갱신 응답은 갱신 수를 실제 숫자로 채우고(종전엔 `{renewed}` 가 그대로 노출), 1 이면 단수다.
     *
     * @scenario surface=response_message
     *
     * @effects response_message_fills_and_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function renew_all_message_fills_the_number_and_follows_it(): void
    {
        $message = fn (int $n) => ResponseHelper::success('sirsoft-gdpr::messages.consent.renew_all_success', ['renewed' => $n], 200, ['renewed' => $n, 'count' => $n])->getData(true)['message'];

        $this->app->setLocale('en');
        $this->assertSame('1 active consent item renewed to the new policy version.', $message(1));
        $this->assertSame('2 active consent items renewed to the new policy version.', $message(2));
        $this->assertSame('0 active consent items renewed to the new policy version.', $message(0));

        $this->app->setLocale('ko');
        $this->assertSame('활성 항목 2 개를 새 정책 버전으로 갱신했습니다.', $message(2));
    }
}
