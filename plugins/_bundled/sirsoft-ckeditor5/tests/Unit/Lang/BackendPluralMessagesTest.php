<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit\Lang;

use App\Helpers\ResponseHelper;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * CKEditor5 플러그인 백엔드 영어 메시지의 단·복수 테스트.
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

        $this->app['translator']->addNamespace('sirsoft-ckeditor5', base_path('plugins/_bundled/sirsoft-ckeditor5/lang'));
    }

    /**
     * 선택 이미지 일괄 삭제 응답은 1 장이면 단수, 0·2 장이면 복수다. 한국어는 그대로다.
     *
     * @scenario surface=response_message
     *
     * @effects response_message_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function bulk_delete_message_follows_deleted_count(): void
    {
        $message = fn (int $n) => ResponseHelper::success('messages.uploads.bulk_deleted', null, messageParams: ['deleted' => $n, 'count' => $n], domain: 'sirsoft-ckeditor5')->getData(true)['message'];

        $this->app->setLocale('en');
        $this->assertSame('Deleted 1 selected image.', $message(1));
        $this->assertSame('Deleted 2 selected images.', $message(2));
        $this->assertSame('Deleted 0 selected images.', $message(0));

        $this->app->setLocale('ko');
        $this->assertSame(__('sirsoft-ckeditor5::messages.uploads.bulk_deleted', ['deleted' => 1]), $message(1));
    }
}
