<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Console;

use App\Extension\Storage\ModuleStorageDriver;
use App\Services\ModuleSettingsService;
use Mockery;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 첨부 정리 커맨드 테스트
 *
 * 임시 첨부 정리는 상시, 소프트 삭제 첨부 영구 정리는 운영자 옵트인입니다.
 * 두 파트의 게이트가 서로 섞이지 않는지를 회귀로 고정합니다.
 */
class PruneAttachmentsCommandTest extends ModuleTestCase
{
    /**
     * 모듈 설정 조회를 지정 값으로 고정합니다.
     *
     * @param  array<string, mixed>  $values  설정 키 => 값
     */
    private function fakeModuleSettings(array $values): void
    {
        $service = Mockery::mock(ModuleSettingsService::class);

        $service->shouldReceive('get')
            ->andReturnUsing(function (string $identifier, ?string $key = null, mixed $default = null) use ($values) {
                return array_key_exists($key, $values) ? $values[$key] : $default;
            });

        $this->app->instance(ModuleSettingsService::class, $service);
    }

    /**
     * 첨부 파일과 기록을 함께 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return Attachment 생성된 첨부
     */
    private function makeAttachment(array $attributes = []): Attachment
    {
        $filename = uniqid('board-att-').'.png';
        $path = $attributes['path'] ?? "free/temp/tempkey/{$filename}";

        (new ModuleStorageDriver('sirsoft-board', 'modules'))->put('attachments', $path, 'bytes');

        return Attachment::create(array_merge([
            'board_id' => 0,
            'post_id' => null,
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
     * @scenario age=past_retention, attachment_state=temp_unlinked, purge_toggle=off
     *
     * @effects board_temp_prune_deletes_file_and_record
     */
    #[Test]
    public function stale_temp_attachments_lose_both_file_and_record(): void
    {
        $attachment = $this->makeAttachment();
        $attachment->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('sirsoft-board:prune-attachments')->assertSuccessful();

        $this->assertFalse(
            (new ModuleStorageDriver('sirsoft-board', 'modules'))->exists('attachments', $attachment->path)
        );
        $this->assertDatabaseMissing('board_attachments', ['id' => $attachment->id]);
    }

    /**
     * @scenario age=within_retention, attachment_state=temp_unlinked, purge_toggle=off
     *
     * @effects board_temp_prune_keeps_within_retention
     */
    #[Test]
    public function temp_attachment_within_retention_is_kept(): void
    {
        $attachment = $this->makeAttachment();

        $this->artisan('sirsoft-board:prune-attachments')->assertSuccessful();

        $this->assertDatabaseHas('board_attachments', ['id' => $attachment->id]);
    }

    /**
     * 게시글에 연결된(temp_key 가 비워진) 첨부는 임시 정리 대상이 아니다.
     *
     * @scenario age=past_retention, attachment_state=linked, purge_toggle=off
     *
     * @effects board_temp_prune_skips_linked
     */
    #[Test]
    public function linked_attachment_is_never_treated_as_temporary(): void
    {
        $attachment = $this->makeAttachment([
            'board_id' => 1,
            'post_id' => 1,
            'temp_key' => null,
            'path' => 'free/2026/08/14/'.uniqid('linked-').'.png',
        ]);
        $attachment->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('sirsoft-board:prune-attachments')->assertSuccessful();

        $this->assertDatabaseHas('board_attachments', ['id' => $attachment->id]);
    }

    /**
     * @scenario age=past_retention, attachment_state=soft_deleted, purge_toggle=off
     *
     * @effects board_purge_gate_off_by_default
     */
    #[Test]
    public function scheduled_run_does_not_purge_soft_deleted_when_the_toggle_is_off(): void
    {
        $this->fakeModuleSettings(['attachment_settings.purge_enabled' => false]);

        $attachment = $this->makeAttachment([
            'board_id' => 1,
            'post_id' => 1,
            'temp_key' => null,
            'path' => 'free/2026/08/14/'.uniqid('soft-').'.png',
        ]);
        $attachment->delete();
        $attachment->forceFill(['deleted_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('sirsoft-board:prune-attachments --scheduled')->assertSuccessful();

        $this->assertNotNull(Attachment::withTrashed()->find($attachment->id));
    }

    /**
     * @scenario age=past_retention, attachment_state=soft_deleted, purge_toggle=on
     *
     * @effects board_purge_gate_on
     */
    #[Test]
    public function scheduled_run_purges_soft_deleted_when_the_toggle_is_on(): void
    {
        $this->fakeModuleSettings([
            'attachment_settings.purge_enabled' => true,
            'attachment_settings.purge_retention_days' => 30,
        ]);

        $attachment = $this->makeAttachment([
            'board_id' => 1,
            'post_id' => 1,
            'temp_key' => null,
            'path' => 'free/2026/08/14/'.uniqid('purge-').'.png',
        ]);
        $path = $attachment->path;
        $attachment->delete();
        $attachment->forceFill(['deleted_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('sirsoft-board:prune-attachments --scheduled')->assertSuccessful();

        $this->assertNull(Attachment::withTrashed()->find($attachment->id));
        $this->assertFalse(
            (new ModuleStorageDriver('sirsoft-board', 'modules'))->exists('attachments', $path)
        );
    }

    /**
     * 보존기간 안의 소프트 삭제 첨부는 복원 가능해야 하므로 파기하지 않는다.
     *
     * @scenario age=within_retention, attachment_state=soft_deleted, purge_toggle=on
     *
     * @effects board_purge_keeps_restorable_window
     */
    #[Test]
    public function soft_deleted_attachment_within_retention_stays_restorable(): void
    {
        $this->fakeModuleSettings([
            'attachment_settings.purge_enabled' => true,
            'attachment_settings.purge_retention_days' => 30,
        ]);

        $attachment = $this->makeAttachment([
            'board_id' => 1,
            'post_id' => 1,
            'temp_key' => null,
            'path' => 'free/2026/08/14/'.uniqid('recent-').'.png',
        ]);
        $attachment->delete();

        $this->artisan('sirsoft-board:prune-attachments --scheduled')->assertSuccessful();

        $trashed = Attachment::withTrashed()->find($attachment->id);
        $this->assertNotNull($trashed);
        $this->assertTrue(
            (new ModuleStorageDriver('sirsoft-board', 'modules'))->exists('attachments', $attachment->path),
            '복원 가능 기간 안에는 파일도 남아 있어야 한다.'
        );
    }

    /**
     * @effects board_prune_dry_run_no_delete
     */
    #[Test]
    public function dry_run_reports_targets_without_deleting(): void
    {
        $attachment = $this->makeAttachment();
        $attachment->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('sirsoft-board:prune-attachments --dry-run')
            ->expectsOutputToContain('[DRY RUN]')
            ->assertSuccessful();

        $this->assertDatabaseHas('board_attachments', ['id' => $attachment->id]);
    }

    /**
     * @effects board_temp_prune_days_guard
     */
    #[Test]
    public function temp_retention_below_one_day_performs_no_cleanup(): void
    {
        $attachment = $this->makeAttachment();
        $attachment->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('sirsoft-board:prune-attachments --temp-days=0 --purge-days=0')
            ->assertSuccessful();

        $this->assertDatabaseHas('board_attachments', ['id' => $attachment->id]);
    }

    /**
     * 파일을 비운 temp_key 디렉토리는 남기지 않는다 (회귀)
     *
     * 파일만 지우고 디렉토리를 남기면 폼 세션마다 빈 디렉토리가 쌓여, 정리를 돌려도
     * 저장소 흔적은 계속 늘어난다.
     *
     * @scenario age=past_retention, attachment_state=temp_unlinked, purge_toggle=off
     *
     * @effects board_temp_prune_removes_empty_directory
     */
    #[Test]
    public function emptied_temp_directory_is_removed(): void
    {
        $attachment = $this->makeAttachment(['path' => 'free/temp/tempkey-empty-dir/'.uniqid('t-').'.png']);
        $attachment->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('sirsoft-board:prune-attachments')->assertSuccessful();

        $storage = new ModuleStorageDriver('sirsoft-board', 'modules');

        $this->assertSame(
            [],
            $storage->files('attachments', 'free/temp/tempkey-empty-dir'),
            '디렉토리 안의 파일은 모두 삭제되어야 한다.'
        );
        $this->assertDirectoryDoesNotExist(
            rtrim($storage->getBasePath('attachments'), '/\\').'/free/temp/tempkey-empty-dir',
            '비워진 temp 디렉토리는 남기지 않는다.'
        );
    }

    /**
     * 같은 temp_key 에 처리되지 않은 파일이 남아 있으면 디렉토리를 지우지 않는다.
     *
     * @scenario age=past_retention, attachment_state=temp_unlinked, purge_toggle=off
     *
     * @effects board_temp_prune_keeps_directory_with_remaining_files
     */
    #[Test]
    public function temp_directory_with_remaining_files_is_kept(): void
    {
        $directory = 'free/temp/tempkey-partial';

        $processed = $this->makeAttachment(['path' => $directory.'/'.uniqid('done-').'.png']);
        $processed->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        // 같은 디렉토리에 이번 회차 대상이 아닌 파일이 남아 있는 상태
        $storage = new ModuleStorageDriver('sirsoft-board', 'modules');
        $storage->put('attachments', $directory.'/keep.png', 'bytes');

        $this->artisan('sirsoft-board:prune-attachments')->assertSuccessful();

        $this->assertTrue($storage->exists('attachments', $directory.'/keep.png'));
    }

    /**
     * 대상이 한 회차 상한을 넘어도 누락 없이 전수 처리된다 (경계).
     *
     * 순회는 `--limit` 으로 잘린 단일 윈도우를 훑는다. 처리하며 행을 지우므로, 남은 대상이
     * 다음 회차에서 반드시 잡혀야 적체가 수렴한다 — 커서가 밀려 영영 건너뛰는 행이 있으면
     * 정리를 매일 돌려도 잔존물이 남는다.
     *
     * @scenario age=past_retention, attachment_state=temp_unlinked, purge_toggle=off
     *
     * @effects board_temp_prune_processes_all_across_runs
     */
    #[Test]
    public function targets_beyond_the_limit_are_processed_on_the_next_run(): void
    {
        $attachments = [];

        for ($i = 0; $i < 3; $i++) {
            $attachment = $this->makeAttachment([
                'path' => "free/temp/tempkey-batch-{$i}/".uniqid('b-').'.png',
            ]);
            $attachment->forceFill(['created_at' => now()->subDays(5 + $i)])->saveQuietly();
            $attachments[] = $attachment;
        }

        // 1회차: 상한 2건까지만 처리된다
        $this->artisan('sirsoft-board:prune-attachments --limit=2')->assertSuccessful();

        $remaining = Attachment::withTrashed()
            ->whereIn('id', array_column($attachments, 'id'))
            ->count();

        $this->assertSame(1, $remaining, '한 회차는 --limit 건까지만 처리한다.');

        // 2회차: 남은 1건이 반드시 잡힌다 (커서 밀림으로 건너뛰지 않는다)
        $this->artisan('sirsoft-board:prune-attachments --limit=2')->assertSuccessful();

        foreach ($attachments as $attachment) {
            $this->assertDatabaseMissing('board_attachments', ['id' => $attachment->id]);
            $this->assertFalse(
                (new ModuleStorageDriver('sirsoft-board', 'modules'))->exists('attachments', $attachment->path)
            );
        }
    }
}
