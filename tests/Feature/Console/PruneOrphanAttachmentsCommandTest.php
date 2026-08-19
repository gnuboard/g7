<?php

namespace Tests\Feature\Console;

use App\Enums\AttachmentSourceType;
use App\Extension\Storage\CoreStorageDriver;
use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 고아 첨부 정리 커맨드 테스트
 *
 * 판정식이 하나라도 느슨해지면 현역 첨부가 지워진다. 네 조건(소유자 없음 / 보존기간 경과 /
 * 확장 소유 아님 / 현역 아님)을 각각 회귀로 고정합니다.
 */
class PruneOrphanAttachmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 개발 환경 설정 파일에 실제 사이트 로고가 남아 있으면 그 ID 가 보호 목록에 실려
        // 같은 ID 로 생성된 픽스처까지 보호된다. 보호 목록은 각 테스트가 명시적으로 정한다.
        Config::set('g7_settings.core.general.site_logo', []);
    }

    /**
     * 첨부 파일과 기록을 함께 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @param  int  $ageDays  업로드 경과 일수
     * @return Attachment 생성된 첨부
     */
    private function makeAttachment(array $attributes = [], int $ageDays = 40): Attachment
    {
        $filename = uniqid('orphan-').'.png';
        $path = "2026/08/14/{$filename}";
        $disk = config('attachment.disk');

        (new CoreStorageDriver($disk))->put('', $path, 'bytes');

        $attachment = Attachment::create(array_merge([
            'attachmentable_type' => null,
            'attachmentable_id' => null,
            'source_type' => AttachmentSourceType::Core,
            'source_identifier' => null,
            'original_filename' => $filename,
            'stored_filename' => $filename,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => 5,
            'collection' => 'default',
            'order' => 0,
        ], $attributes));

        $attachment->forceFill(['created_at' => now()->subDays($ageDays)])->saveQuietly();

        return $attachment->refresh();
    }

    /**
     * @effects core_orphan_prune_gate_off_by_default
     */
    #[Test]
    public function scheduled_run_deletes_nothing_when_the_toggle_is_off(): void
    {
        $attachment = $this->makeAttachment();

        $this->artisan('attachments:prune-orphans --scheduled')->assertSuccessful();

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    /**
     * @effects core_orphan_prune_gate_on
     */
    #[Test]
    public function scheduled_run_prunes_when_the_toggle_is_on(): void
    {
        Config::set('g7_settings.core.upload.orphan_cleanup_enabled', true);
        Config::set('g7_settings.core.upload.orphan_retention_days', 30);

        $attachment = $this->makeAttachment();

        $this->artisan('attachments:prune-orphans --scheduled')->assertSuccessful();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    /**
     * 소유자가 있는 첨부는 어떤 경우에도 고아가 아니다.
     *
     * @scenario age=past_retention, owner_state=has_owner, protected=not_protected, source_owner=core
     *
     * @effects core_orphan_prune_skips_owned
     */
    #[Test]
    public function attachment_with_an_owner_is_never_pruned(): void
    {
        $attachment = $this->makeAttachment([
            'attachmentable_type' => 'App\\Models\\User',
            'attachmentable_id' => 1,
        ]);

        $this->artisan('attachments:prune-orphans --days=1')->assertSuccessful();

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    /**
     * 확장이 소유한 첨부는 그 확장의 라이프사이클 소관이라 코어가 지우지 않는다.
     *
     * @scenario age=past_retention, owner_state=no_owner, protected=not_protected, source_owner=extension
     *
     * @effects core_orphan_prune_skips_extension_owned
     */
    #[Test]
    public function attachment_owned_by_an_extension_is_never_pruned(): void
    {
        $attachment = $this->makeAttachment([
            'source_type' => AttachmentSourceType::Module,
            'source_identifier' => 'sirsoft-board',
        ]);

        $this->artisan('attachments:prune-orphans --days=1')->assertSuccessful();

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    /**
     * 현역 사이트 로고는 소유자가 없어도 설정이 직접 참조하므로 보호해야 한다.
     *
     * @scenario age=past_retention, owner_state=no_owner, protected=current_site_logo, source_owner=core
     *
     * @effects core_orphan_prune_protects_site_logo
     */
    #[Test]
    public function the_current_site_logo_is_protected(): void
    {
        $logo = $this->makeAttachment(['collection' => 'site_logo']);
        $orphan = $this->makeAttachment();

        Config::set('g7_settings.core.general.site_logo', [$logo->id]);

        $this->artisan('attachments:prune-orphans --days=1')->assertSuccessful();

        $this->assertDatabaseHas('attachments', ['id' => $logo->id]);
        $this->assertDatabaseMissing('attachments', ['id' => $orphan->id]);
    }

    /**
     * 로고 보호는 커맨드를 거치지 않아도 성립해야 한다 (조회의 불변식).
     *
     * 보호 대상을 호출자가 넘기는 값으로 두면 그 인자를 빠뜨린 호출 하나가 운영 중인 로고를
     * 파기한다. 나머지 세 조건은 조회 자체의 불변식이므로 보호 조건만 규약으로 남을 수 없다.
     *
     * @scenario age=past_retention, owner_state=no_owner, protected=current_site_logo, source_owner=core
     *
     * @effects core_orphan_prune_protects_site_logo
     */
    #[Test]
    public function the_site_logo_is_protected_even_when_the_service_is_called_directly(): void
    {
        $logo = $this->makeAttachment(['collection' => 'site_logo']);
        $orphan = $this->makeAttachment();

        Config::set('g7_settings.core.general.site_logo', [$logo->id]);

        app(AttachmentService::class)->pruneOrphans(1, 500);

        $this->assertDatabaseHas('attachments', ['id' => $logo->id]);
        $this->assertDatabaseMissing('attachments', ['id' => $orphan->id]);
    }

    /**
     * 보호는 **현역** 로고에만 걸린다 — 설정에서 빠진 구 로고는 회수 대상이다.
     *
     * 로고 교체는 저장이 성공한 뒤에야 구 파일을 파기한다. 저장이 실패하면 파기하지 않으므로
     * (설정에는 없는데 파일만 남는 상태를 막기 위한 순서다) 그렇게 남은 첨부를 거둘 마지막
     * 통로가 이 GC 다. 보호 조건이 컬렉션 전체로 넓어지면 그 통로가 막혀 구 로고가 영구
     * 잔존하므로, 보호 방향(현역 보존)과 반대 방향(구 로고 회수)을 함께 고정한다.
     *
     * @scenario age=past_retention, owner_state=no_owner, protected=not_protected, source_owner=core
     *
     * @effects core_orphan_prune_collects_replaced_site_logo
     */
    #[Test]
    public function a_replaced_site_logo_is_collected_once_it_leaves_the_setting(): void
    {
        $previous = $this->makeAttachment(['collection' => 'site_logo']);
        $current = $this->makeAttachment(['collection' => 'site_logo']);

        // 교체 후 설정에는 새 로고만 남아 있다.
        Config::set('g7_settings.core.general.site_logo', [$current->id]);

        $this->artisan('attachments:prune-orphans --days=1')->assertSuccessful();

        $this->assertDatabaseHas('attachments', ['id' => $current->id]);
        $this->assertDatabaseMissing('attachments', ['id' => $previous->id]);
    }

    /**
     * @scenario age=within_retention, owner_state=no_owner, protected=not_protected, source_owner=core
     *
     * @effects core_orphan_prune_keeps_within_retention
     */
    #[Test]
    public function attachment_within_retention_is_kept(): void
    {
        $attachment = $this->makeAttachment(ageDays: 3);

        $this->artisan('attachments:prune-orphans --days=30')->assertSuccessful();

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    /**
     * @effects core_orphan_prune_dry_run
     */
    #[Test]
    public function dry_run_reports_targets_without_deleting(): void
    {
        $attachment = $this->makeAttachment();

        $this->artisan('attachments:prune-orphans --days=1 --dry-run')
            ->expectsOutputToContain('[DRY RUN]')
            ->assertSuccessful();

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    /**
     * @scenario age=past_retention, owner_state=no_owner, protected=not_protected, source_owner=core
     *
     * @effects core_orphan_prune_deletes_file
     */
    #[Test]
    public function pruning_removes_the_physical_file_as_well(): void
    {
        $attachment = $this->makeAttachment();
        $storage = new CoreStorageDriver($attachment->disk);

        $this->assertTrue($storage->exists('', $attachment->path));

        $this->artisan('attachments:prune-orphans --days=1')->assertSuccessful();

        $this->assertFalse($storage->exists('', $attachment->path));
    }

    /**
     * 소프트삭제된 고아도 회수된다.
     *
     * 후보 조회는 소프트삭제 행까지 포함하므로, 삭제 경로가 기본 조회를 쓰면 대상을 찾지 못해
     * 매 회차 같은 행을 실패로만 계상하고 파일·기록이 영원히 남는다.
     *
     * @scenario age=past_retention, owner_state=no_owner, protected=not_protected, source_owner=core
     *
     * @effects core_orphan_prune_deletes_soft_deleted
     */
    #[Test]
    public function soft_deleted_orphan_is_purged_too(): void
    {
        $attachment = $this->makeAttachment();
        $storage = new CoreStorageDriver($attachment->disk);

        $attachment->delete();

        $this->assertNotNull(Attachment::withTrashed()->find($attachment->id));

        $this->artisan('attachments:prune-orphans --days=1')->assertSuccessful();

        $this->assertNull(Attachment::withTrashed()->find($attachment->id));
        $this->assertFalse($storage->exists('', $attachment->path));
    }
}
