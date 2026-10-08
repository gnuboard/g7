<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Lang;

use App\Helpers\ResponseHelper;
use Modules\Sirsoft\Page\Exceptions\AttachmentLimitExceededException;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 페이지 백엔드 영어 메시지의 단·복수 테스트.
 *
 * 종전 영어 문구는 `:count page(s)` 처럼 수와 상관없이 적었다. 1 이면 단수, 0·2 이상이면 복수로 고르고,
 * 한국어 문구는 그대로다.
 */
class BackendPluralMessagesTest extends ModuleTestCase
{
    /**
     * 사전을 작업 원본(_bundled)에서 읽도록 네임스페이스를 다시 잡습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace('sirsoft-page', base_path('modules/_bundled/sirsoft-page/src/lang'));
    }

    /**
     * 일괄 발행 상태 변경 응답은 1 페이지면 단수, 0·2 페이지면 복수다. 한국어는 그대로다.
     *
     * @scenario surface=response_message
     *
     * @effects response_message_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function bulk_publish_message_follows_count(): void
    {
        $this->app->setLocale('en');

        $message = fn (int $n) => ResponseHelper::success('sirsoft-page::messages.page.bulk_publish_success', null, 200, ['count' => $n])->getData(true)['message'];

        $this->assertSame('Changed the publish status of 1 page.', $message(1));
        $this->assertSame('Changed the publish status of 2 pages.', $message(2));
        $this->assertSame('Changed the publish status of 0 pages.', $message(0));

        $this->app->setLocale('ko');
        $this->assertSame(__('sirsoft-page::messages.page.bulk_publish_success', ['count' => 1]), $message(1));
    }

    /**
     * 첨부 개수 상한 초과 예외·응답은 상한이 1 이면 「1 file」 이다. 한국어는 그대로다.
     *
     * @scenario surface=exception_response
     *
     * @effects exception_and_response_message_follow_number
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function attachment_limit_message_follows_limit(): void
    {
        $this->app->setLocale('en');

        $one = new AttachmentLimitExceededException(1, 2);
        $this->assertSame('You can attach up to 1 file. (requested: 2)', $one->getMessage());
        $this->assertSame(
            'You can attach up to 1 file. (requested: 2)',
            ResponseHelper::error($one->getMessageKey(), 422, null, $one->getMessageParams())->getData(true)['message']
        );
        $this->assertSame('You can attach up to 5 files. (requested: 6)', (new AttachmentLimitExceededException(5, 6))->getMessage());

        $this->app->setLocale('ko');
        $this->assertSame(
            __('sirsoft-page::messages.errors.attachment_limit_exceeded', ['limit' => 1, 'attempted' => 2]),
            (new AttachmentLimitExceededException(1, 2))->getMessage()
        );
    }

    /**
     * 일괄 상태 변경 최소 선택 문구는 규칙이 `min:1` 고정이라 단수로 적는다.
     *
     * @scenario surface=fixed_minimum
     *
     * @effects fixed_minimum_message_is_singular
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function bulk_minimum_selection_is_singular(): void
    {
        $this->app->setLocale('en');

        $this->assertSame('Please select at least :min page.', __('sirsoft-page::validation.ids.min'));
    }
}
