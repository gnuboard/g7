<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Listeners;

use App\ActivityLog\ChangeDetector;
use App\Enums\ActivityLogType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Page\Listeners\PageActivityLogListener;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Models\PageVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 활동 로그 리스너 단위 테스트
 *
 * PageActivityLogListener의 모든 훅 핸들러를 검증합니다.
 * - 훅 구독 등록 (7개 훅: logging only)
 * - Page CRUD + publish/restore 로그
 * - PageAttachment upload/delete 로그
 * - 예외 발생 시 graceful 처리
 */
class PageActivityLogListenerTest extends ModuleTestCase
{
    private PageActivityLogListener $listener;

    private $logChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('request', Request::create('/api/admin/test'));

        $this->listener = new PageActivityLogListener();
        $this->logChannel = Mockery::mock(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
        // catch 블록의 Log::error() 호출 허용 (기본적으로 호출되지 않아야 함)
        Log::shouldReceive('error')->byDefault();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function getSubscribedHooks는_7개_훅을_반환한다(): void
    {
        $hooks = PageActivityLogListener::getSubscribedHooks();

        $this->assertCount(7, $hooks);
    }

    #[Test]
    public function getSubscribedHooks는_올바른_메서드_매핑을_포함한다(): void
    {
        $hooks = PageActivityLogListener::getSubscribedHooks();

        // Page 훅
        $this->assertEquals('handlePageAfterCreate', $hooks['sirsoft-page.page.after_create']['method']);
        $this->assertEquals('handlePageAfterUpdate', $hooks['sirsoft-page.page.after_update']['method']);
        $this->assertEquals('handlePageAfterDelete', $hooks['sirsoft-page.page.after_delete']['method']);
        $this->assertEquals('handlePageAfterPublish', $hooks['sirsoft-page.page.after_publish']['method']);
        $this->assertEquals('handlePageAfterRestore', $hooks['sirsoft-page.page.after_restore']['method']);

        // PageAttachment 훅
        $this->assertEquals('handleAttachmentAfterUpload', $hooks['sirsoft-page.attachment.after_upload']['method']);
        $this->assertEquals('handleAttachmentAfterDelete', $hooks['sirsoft-page.attachment.after_delete']['method']);

        // before_update 훅은 더 이상 존재하지 않음
        $this->assertArrayNotHasKey('sirsoft-page.page.before_update', $hooks);
    }

    #[Test]
    public function 모든_로깅_훅은_priority_20이다(): void
    {
        $hooks = PageActivityLogListener::getSubscribedHooks();

        $this->assertEquals(20, $hooks['sirsoft-page.page.after_create']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_update']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_delete']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_publish']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.page.after_restore']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.attachment.after_upload']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-page.attachment.after_delete']['priority']);
    }

    // ═══════════════════════════════════════════
    // Page 핸들러 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function handlePageAfterCreate는_page_create_로그를_기록한다(): void
    {
        $page = $this->createMockPage(1, '소개 페이지', 'about');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('page.create', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-page::activity_log.description.page_create'
                    && $context['description_params']['title'] === '소개 페이지'
                    && $context['properties']['title'] === '소개 페이지'
                    && $context['properties']['slug'] === 'about';
            }));

        $this->listener->handlePageAfterCreate($page, ['title' => '소개 페이지']);
    }

    #[Test]
    public function handlePageAfterUpdate는_변경사항과_함께_page_update_로그를_기록한다(): void
    {
        // 수정 전 스냅샷 (Service에서 캡처하여 전달)
        $snapshot = ['id' => 1, 'title' => '소개 페이지', 'slug' => 'about'];

        // 업데이트된 페이지
        $updatedPage = $this->createMockPage(1, '수정된 페이지', 'about');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('page.update', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-page::activity_log.description.page_update'
                    && $context['description_params']['title'] === '수정된 페이지'
                    && array_key_exists('changes', $context);
            }));

        $this->listener->handlePageAfterUpdate($updatedPage, ['title' => '수정된 페이지'], $snapshot);
    }

    #[Test]
    public function handlePageAfterDelete는_page_delete_로그를_기록한다(): void
    {
        $page = $this->createMockPage(1, '소개 페이지', 'about');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('page.delete', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-page::activity_log.description.page_delete'
                    && $context['description_params']['title'] === '소개 페이지'
                    && $context['properties']['title'] === '소개 페이지'
                    && $context['properties']['slug'] === 'about';
            }));

        $this->listener->handlePageAfterDelete($page);
    }

    /**
     * 페이지 공개/비공개 전환 데이터 프로바이더
     *
     * @return array<string, array{bool, string, string}>
     */
    public static function publishDataProvider(): array
    {
        return [
            'publish (공개)' => [
                true,
                'page.publish',
                'sirsoft-page::activity_log.description.page_publish',
            ],
            'unpublish (비공개)' => [
                false,
                'page.unpublish',
                'sirsoft-page::activity_log.description.page_unpublish',
            ],
        ];
    }

    #[Test]
    #[DataProvider('publishDataProvider')]
    public function handlePageAfterPublish는_공개_비공개에_따라_올바른_로그를_기록한다(
        bool $published,
        string $expectedAction,
        string $expectedDescriptionKey
    ): void {
        $page = $this->createMockPage(1, '소개 페이지', 'about');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with($expectedAction, Mockery::on(function (array $context) use ($expectedDescriptionKey, $published) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === $expectedDescriptionKey
                    && $context['description_params']['title'] === '소개 페이지'
                    && $context['properties']['published'] === $published;
            }));

        $this->listener->handlePageAfterPublish($page, $published);
    }

    #[Test]
    public function handlePageAfterRestore는_page_restore_로그를_기록한다(): void
    {
        $page = $this->createMockPage(1, '소개 페이지', 'about');
        $version = $this->createMockPageVersion(5, 3);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('page.restore', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-page::activity_log.description.page_restore'
                    && $context['description_params']['title'] === '소개 페이지'
                    && $context['description_params']['version_id'] === 5
                    && $context['properties']['version_id'] === 5
                    && $context['properties']['version_number'] === 3;
            }));

        $this->listener->handlePageAfterRestore($page, $version);
    }

    // ═══════════════════════════════════════════
    // PageAttachment 핸들러 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function handleAttachmentAfterUpload은_page_attachment_upload_로그를_기록한다(): void
    {
        $page = $this->createMockPage(1, '소개 페이지', 'about');
        $attachment = $this->createMockPageAttachment('image.png', 2048, 1, $page);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('page_attachment.upload', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-page::activity_log.description.page_attachment_upload'
                    && $context['description_params']['title'] === '소개 페이지'
                    && $context['properties']['original_name'] === 'image.png'
                    && $context['properties']['size'] === 2048;
            }));

        $this->listener->handleAttachmentAfterUpload($attachment);
    }

    #[Test]
    public function handleAttachmentAfterDelete는_page_attachment_delete_로그를_기록한다(): void
    {
        $page = $this->createMockPage(1, '소개 페이지', 'about');
        $attachment = $this->createMockPageAttachment('image.png', 2048, 1, $page);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('page_attachment.delete', Mockery::on(function (array $context) {
                return $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-page::activity_log.description.page_attachment_delete'
                    && $context['description_params']['title'] === '소개 페이지'
                    && $context['properties']['original_name'] === 'image.png';
            }));

        $this->listener->handleAttachmentAfterDelete($attachment);
    }

    // ═══════════════════════════════════════════
    // 예외 처리 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function logActivity_예외_발생_시_Log_error로_기록한다(): void
    {
        $page = $this->createMockPage(1, '소개 페이지', 'about');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->andThrow(new \Exception('DB connection failed'));

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to record activity log', Mockery::on(function (array $context) {
                return $context['action'] === 'page.create'
                    && $context['error'] === 'DB connection failed';
            }));

        // 예외가 전파되지 않아야 한다
        $this->listener->handlePageAfterCreate($page, []);
    }

    // ═══════════════════════════════════════════
    // handle() 기본 핸들러 검증
    // ═══════════════════════════════════════════

    #[Test]
    public function handle_기본_핸들러는_아무_작업도_수행하지_않는다(): void
    {
        // handle()은 빈 메서드 — 예외 없이 호출되어야 한다
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════
    // 엣지 케이스: 스냅샷 없이 update 호출
    // ═══════════════════════════════════════════

    #[Test]
    public function handlePageAfterUpdate_스냅샷_없이_호출해도_정상_동작한다(): void
    {
        $page = $this->createMockPage(99, '테스트', 'test');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('page.update', Mockery::on(function (array $context) {
                // 스냅샷 없으면 ChangeDetector::detect는 null 반환
                return $context['changes'] === null;
            }));

        // snapshot을 null로 명시적으로 전달 (Service가 전달하지 않는 경우와 동일)
        $this->listener->handlePageAfterUpdate($page, [], null);
    }

    // ═══════════════════════════════════════════
    // 엣지 케이스: null 속성 처리
    // ═══════════════════════════════════════════

    #[Test]
    public function handlePageAfterCreate_title이_null이면_빈문자열로_기록한다(): void
    {
        $page = $this->createMockPage(1, null, null);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('page.create', Mockery::on(function (array $context) {
                return $context['description_params']['title'] === ''
                    && $context['properties']['slug'] === '';
            }));

        $this->listener->handlePageAfterCreate($page, []);
    }

    #[Test]
    public function handlePageAfterRestore_version이_null이면_version_number는_null로_기록한다(): void
    {
        $page = $this->createMockPage(1, '소개 페이지', 'about');
        $version = $this->createMockPageVersion(5, null);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('page.restore', Mockery::on(function (array $context) {
                return $context['properties']['version_number'] === null;
            }));

        $this->listener->handlePageAfterRestore($page, $version);
    }

    #[Test]
    public function handleAttachmentAfterUpload_original_name이_null이면_빈문자열로_기록한다(): void
    {
        $attachment = $this->createMockPageAttachment(null, null, null, null);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->with('page_attachment.upload', Mockery::on(function (array $context) {
                return $context['description_params']['title'] === ''
                    && $context['properties']['original_name'] === ''
                    && $context['properties']['size'] === 0;
            }));

        $this->listener->handleAttachmentAfterUpload($attachment);
    }

    // ═══════════════════════════════════════════
    // Mock 헬퍼 메서드
    // ═══════════════════════════════════════════

    /**
     * Page 모의 객체를 생성합니다.
     *
     * makePartial()로 Eloquent __get → getAttribute 체인이 정상 동작하도록 합니다.
     *
     * @param int $id 페이지 ID
     * @param string|null $title 페이지 제목
     * @param string|null $slug 페이지 슬러그
     * @return Page&\Mockery\MockInterface
     */
    private function createMockPage(int $id, ?string $title, ?string $slug): Page
    {
        $page = Mockery::mock(Page::class)->makePartial();
        $page->shouldReceive('getAttribute')->with('id')->andReturn($id);
        $page->shouldReceive('getAttribute')->with('title')->andReturn($title);
        $page->shouldReceive('getAttribute')->with('slug')->andReturn($slug);
        $page->shouldReceive('toArray')->andReturn([
            'id' => $id,
            'title' => $title,
            'slug' => $slug,
        ]);

        return $page;
    }

    /**
     * PageVersion 모의 객체를 생성합니다.
     *
     * @param int $id 버전 ID
     * @param int|null $versionNumber 버전 번호
     * @return PageVersion&\Mockery\MockInterface
     */
    private function createMockPageVersion(int $id, ?int $versionNumber): PageVersion
    {
        $version = Mockery::mock(PageVersion::class)->makePartial();
        $version->shouldReceive('getAttribute')->with('id')->andReturn($id);
        $version->shouldReceive('getAttribute')->with('version')->andReturn($versionNumber);

        return $version;
    }

    /**
     * PageAttachment 모의 객체를 생성합니다.
     *
     * @param string|null $originalName 원본 파일명
     * @param int|null $size 파일 크기
     * @return PageAttachment&\Mockery\MockInterface
     */
    private function createMockPageAttachment(?string $originalName, ?int $size, ?int $pageId = null, $page = null): PageAttachment
    {
        $attachment = Mockery::mock(PageAttachment::class)->makePartial();
        $attachment->shouldReceive('getAttribute')->with('original_name')->andReturn($originalName);
        $attachment->shouldReceive('getAttribute')->with('size')->andReturn($size);
        $attachment->shouldReceive('getAttribute')->with('page_id')->andReturn($pageId);
        $attachment->shouldReceive('getAttribute')->with('page')->andReturn($page);
        $attachment->shouldReceive('loadMissing')->with('page')->andReturnSelf();

        return $attachment;
    }


}
