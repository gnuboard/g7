<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Exceptions\UnsafeStoragePathException;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_2_0;
use Psr\Log\LoggerInterface;

/**
 * 1.2.0 업그레이드 — 키 없이 올린 예전 첨부의 `temp//` 경로를 합치는지 검증 (KVE-2026-2349 후속)
 *
 * 1.2.0 이전에는 글 번호·임시 키 없이 올린 첨부가 DB 에 `{slug}/temp//{파일}` 로 저장됐고 실제 파일은
 * 파일 시스템 정규화로 `{slug}/temp/{파일}` 에 있었다. 1.2.0 의 경로 검사가 빈 조각을 거부하므로
 * 보정하지 않으면 다운로드·정리·파기가 그 행에서 실패한다.
 */
class LegacyTempAttachmentPathUpgradeTest extends BoardTestCase
{
    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'legacy-temp-path';
    }

    /**
     * 모듈 저장소 디스크를 격리합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');
    }

    /**
     * 예전 `temp//` 행(삭제된 행 포함)은 실제 파일 위치로 합쳐지고, 보정 뒤 저장소 드라이버로 읽힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario row_state=legacy_empty_segment
     *
     * @effects legacy_empty_segment_path_collapsed, soft_deleted_row_collapsed, collapsed_path_readable_through_driver
     */
    public function test_legacy_empty_segment_paths_are_collapsed_to_the_real_file(): void
    {
        $slug = $this->getTestBoardSlug();
        $driver = new ModuleStorageDriver('sirsoft-board', 'modules');
        // 예전 저장: DB 는 `temp//`, 파일은 정규화된 위치
        Storage::disk('modules')->put("sirsoft-board/attachments/{$slug}/temp/a.png", 'A');
        Storage::disk('modules')->put("sirsoft-board/attachments/{$slug}/temp/b.png", 'B');
        $live = $this->insertAttachment("{$slug}/temp//a.png");
        $deleted = $this->insertAttachment("{$slug}/temp//b.png", deleted: true);

        try {
            $driver->exists('attachments', "{$slug}/temp//a.png");
            $this->fail('보정 전 경로가 경로 검사를 통과했습니다 — 전제가 깨졌습니다.');
        } catch (UnsafeStoragePathException) {
            $this->addToAssertionCount(1);
        }

        $this->runUpgrade();

        $this->assertSame("{$slug}/temp/a.png", DB::table('board_attachments')->where('id', $live)->value('path'));
        $this->assertSame("{$slug}/temp/b.png", DB::table('board_attachments')->where('id', $deleted)->value('path'));
        $this->assertSame('A', $driver->get('attachments', DB::table('board_attachments')->where('id', $live)->value('path')));
    }

    /**
     * 정상 경로는 건드리지 않고, 이 모듈이 만들지 않은 조각이 섞인 행은 고치지 않고 위치를 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario row_state=normal_or_foreign
     *
     * @effects normal_path_untouched, foreign_segment_row_left_and_logged
     */
    public function test_normal_rows_untouched_and_foreign_rows_logged(): void
    {
        $slug = $this->getTestBoardSlug();
        $normal = $this->insertAttachment("{$slug}/2026/01/01/c.png");
        $foreign = $this->insertAttachment("{$slug}/temp//../x.png");
        $spy = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->andReturn($spy);

        $this->runUpgrade();

        $this->assertSame("{$slug}/2026/01/01/c.png", DB::table('board_attachments')->where('id', $normal)->value('path'));
        $this->assertSame("{$slug}/temp//../x.png", DB::table('board_attachments')->where('id', $foreign)->value('path'));
        $spy->shouldHaveReceived('warning')->with(Mockery::on(fn ($m) => str_contains($m, "board_attachments#{$foreign}")));
    }

    /**
     * 두 번 돌려도 결과가 같다 (합친 경로에는 `//` 가 없다).
     *
     * @return void 반환값 없음
     *
     * @scenario row_state=legacy_empty_segment
     *
     * @effects collapse_is_idempotent
     */
    public function test_collapse_is_idempotent(): void
    {
        $slug = $this->getTestBoardSlug();
        $id = $this->insertAttachment("{$slug}/temp//d.png");

        $this->runUpgrade();
        $first = DB::table('board_attachments')->where('id', $id)->first(['path', 'updated_at']);
        $this->runUpgrade();
        $second = DB::table('board_attachments')->where('id', $id)->first(['path', 'updated_at']);

        $this->assertSame("{$slug}/temp/d.png", $first->path);
        $this->assertEquals($first, $second);
    }

    /**
     * 1.2.0 업그레이드 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        (new Upgrade_1_2_0)->run(new UpgradeContext('1.1.1', '1.2.0', '1.2.0', 'extension-upgrade'));
    }

    /**
     * 테스트 게시판에 첨부 행을 직접 넣습니다 (예전 코드가 남긴 형태 재현).
     *
     * @param  string  $path  저장 경로
     * @param  bool  $deleted  soft delete 여부
     * @return int 첨부 ID
     */
    private function insertAttachment(string $path, bool $deleted = false): int
    {
        return DB::table('board_attachments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => null,
            'temp_key' => null,
            'hash' => substr(md5($path), 0, 12),
            'original_filename' => basename($path),
            'stored_filename' => basename($path),
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => 1,
            'collection' => 'attachments',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
