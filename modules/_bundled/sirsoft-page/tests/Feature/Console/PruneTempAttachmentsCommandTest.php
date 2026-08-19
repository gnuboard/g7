<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Console;

use App\Extension\Storage\ModuleStorageDriver;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 미연결 임시 페이지 첨부 정리 커맨드 테스트
 *
 * `temp_key` 가 남고 `page_id` 가 비어 있는 행만 대상이어야 합니다 — 연결된 첨부가
 * 섞이면 공개 중인 페이지의 첨부가 사라집니다.
 */
class PruneTempAttachmentsCommandTest extends ModuleTestCase
{
    /**
     * 임시 첨부 파일과 기록을 함께 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return PageAttachment 생성된 첨부
     */
    private function makeAttachment(array $attributes = []): PageAttachment
    {
        $filename = uniqid('temp-page-').'.png';
        $path = $attributes['path'] ?? "temp/tempkey/{$filename}";

        (new ModuleStorageDriver('sirsoft-page', 'modules'))->put('attachments', $path, 'bytes');

        return PageAttachment::create(array_merge([
            'page_id' => null,
            'temp_key' => 'tempkey',
            'original_filename' => $filename,
            'stored_filename' => $filename,
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => 5,
            'collection' => 'attachments',
            'order' => 1,
        ], $attributes));
    }

    /**
     * @scenario age=past_retention, attachment_state=temp_unlinked
     *
     * @effects page_temp_prune_deletes_file_and_record
     */
    #[Test]
    public function stale_temp_attachments_lose_both_file_and_record(): void
    {
        $attachment = $this->makeAttachment();
        $attachment->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('sirsoft-page:prune-temp-attachments')->assertSuccessful();

        $this->assertFalse(
            (new ModuleStorageDriver('sirsoft-page', 'modules'))->exists('attachments', $attachment->path)
        );
        $this->assertDatabaseMissing('page_attachments', ['id' => $attachment->id]);
    }

    /**
     * @scenario age=within_retention, attachment_state=temp_unlinked
     *
     * @effects page_temp_prune_keeps_within_retention
     */
    #[Test]
    public function temp_attachment_within_retention_is_kept(): void
    {
        $attachment = $this->makeAttachment();

        $this->artisan('sirsoft-page:prune-temp-attachments')->assertSuccessful();

        $this->assertDatabaseHas('page_attachments', ['id' => $attachment->id]);
    }

    /**
     * @scenario age=past_retention, attachment_state=linked_to_page
     *
     * @effects page_temp_prune_skips_linked
     */
    #[Test]
    public function attachment_linked_to_a_page_is_never_pruned(): void
    {
        $page = Page::create([
            'slug' => 'prune-temp-attachment-test-'.uniqid(),
            'title' => ['ko' => '연결 첨부 테스트', 'en' => 'Linked Attachment Test'],
            'content' => ['ko' => '', 'en' => ''],
            'published' => true,
        ]);

        $attachment = $this->makeAttachment([
            'page_id' => $page->id,
            'temp_key' => null,
            'path' => '2026/08/14/'.uniqid('linked-').'.png',
        ]);
        $attachment->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('sirsoft-page:prune-temp-attachments')->assertSuccessful();

        $this->assertDatabaseHas('page_attachments', ['id' => $attachment->id]);
    }

    /**
     * @effects page_temp_prune_dry_run
     */
    #[Test]
    public function dry_run_reports_targets_without_deleting(): void
    {
        $attachment = $this->makeAttachment();
        $attachment->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('sirsoft-page:prune-temp-attachments --dry-run')
            ->expectsOutputToContain('[DRY RUN]')
            ->assertSuccessful();

        $this->assertDatabaseHas('page_attachments', ['id' => $attachment->id]);
    }

    /**
     * @effects page_temp_prune_days_guard
     */
    #[Test]
    public function retention_below_one_day_performs_no_cleanup(): void
    {
        $attachment = $this->makeAttachment();
        $attachment->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('sirsoft-page:prune-temp-attachments --days=0')->assertSuccessful();

        $this->assertDatabaseHas('page_attachments', ['id' => $attachment->id]);
    }

    /**
     * 파일을 비운 temp_key 디렉토리는 남기지 않는다 (회귀)
     *
     * 파일만 지우고 디렉토리를 남기면 페이지 작성 폼 세션마다 빈 디렉토리가 쌓여, 정리를
     * 돌려도 저장소 흔적은 계속 늘어난다.
     *
     * @scenario age=past_retention, attachment_state=temp_unlinked
     *
     * @effects page_temp_prune_removes_empty_directory
     */
    #[Test]
    public function emptied_temp_directory_is_removed(): void
    {
        $attachment = $this->makeAttachment(['path' => 'temp/tempkey-empty-dir/'.uniqid('t-').'.png']);
        $attachment->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('sirsoft-page:prune-temp-attachments')->assertSuccessful();

        $storage = new ModuleStorageDriver('sirsoft-page', 'modules');

        $this->assertSame(
            [],
            $storage->files('attachments', 'temp/tempkey-empty-dir'),
            '디렉토리 안의 파일은 모두 삭제되어야 한다.'
        );
        $this->assertDirectoryDoesNotExist(
            rtrim($storage->getBasePath('attachments'), '/\\').'/temp/tempkey-empty-dir',
            '비워진 temp 디렉토리는 남기지 않는다.'
        );
    }

    /**
     * 같은 temp_key 에 처리되지 않은 파일이 남아 있으면 디렉토리를 지우지 않는다.
     *
     * @scenario age=past_retention, attachment_state=temp_unlinked
     *
     * @effects page_temp_prune_keeps_directory_with_remaining_files
     */
    #[Test]
    public function temp_directory_with_remaining_files_is_kept(): void
    {
        $directory = 'temp/tempkey-partial';

        $processed = $this->makeAttachment(['path' => $directory.'/'.uniqid('done-').'.png']);
        $processed->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        // 같은 디렉토리에 이번 회차 대상이 아닌 파일이 남아 있는 상태
        $storage = new ModuleStorageDriver('sirsoft-page', 'modules');
        $storage->put('attachments', $directory.'/keep.png', 'bytes');

        $this->artisan('sirsoft-page:prune-temp-attachments')->assertSuccessful();

        $this->assertTrue($storage->exists('attachments', $directory.'/keep.png'));
    }
}
