<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use Modules\Sirsoft\Page\Upgrades\Upgrade_1_2_0;
use Psr\Log\LoggerInterface;

/**
 * 1.2.0 업그레이드 — 키 없이 올린 예전 페이지 첨부의 `temp//` 경로를 합치는지 검증 (KVE-2026-2349 후속)
 *
 * 1.2.0 이전에는 페이지 번호·임시 키 없이 올린 첨부가 DB 에 `temp//{파일}` 로 저장됐고 실제 파일은
 * 파일 시스템 정규화로 `temp/{파일}` 에 있었다. 1.2.0 의 경로 검사가 빈 조각을 거부하므로 보정한다.
 */
class LegacyTempAttachmentPathUpgradeTest extends ModuleTestCase
{
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
     * 예전 `temp//` 행은 실제 파일 위치로 합쳐지고, 보정 뒤 저장소 드라이버로 읽힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario row_state=legacy_empty_segment
     *
     * @effects legacy_empty_segment_path_collapsed, collapsed_path_readable_through_driver
     */
    public function test_legacy_empty_segment_paths_are_collapsed_to_the_real_file(): void
    {
        Storage::disk('modules')->put('sirsoft-page/attachments/temp/a.pdf', 'A');
        $id = $this->insertAttachment('temp//a.pdf');

        $this->runUpgrade();

        $path = DB::table('page_attachments')->where('id', $id)->value('path');
        $this->assertSame('temp/a.pdf', $path);
        $this->assertSame('A', (new ModuleStorageDriver('sirsoft-page', 'modules'))->get('attachments', $path));
    }

    /**
     * 정상 경로는 건드리지 않고, 이 모듈이 만들지 않은 조각이 섞인 행은 고치지 않고 위치를 남긴다.
     * 두 번 돌려도 결과가 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario row_state=normal_or_foreign
     *
     * @effects normal_path_untouched, foreign_segment_row_left_and_logged, collapse_is_idempotent
     */
    public function test_normal_and_foreign_rows_and_idempotence(): void
    {
        $normal = $this->insertAttachment('2026/01/01/b.pdf');
        $foreign = $this->insertAttachment('temp//..\\x.pdf');
        $legacy = $this->insertAttachment('temp//c.pdf');
        $spy = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->andReturn($spy);

        $this->runUpgrade();
        $this->runUpgrade();

        $this->assertSame('2026/01/01/b.pdf', DB::table('page_attachments')->where('id', $normal)->value('path'));
        $this->assertSame('temp//..\\x.pdf', DB::table('page_attachments')->where('id', $foreign)->value('path'));
        $this->assertSame('temp/c.pdf', DB::table('page_attachments')->where('id', $legacy)->value('path'));
        $spy->shouldHaveReceived('warning')->with(Mockery::on(fn ($m) => str_contains($m, "page_attachments#{$foreign}")));
    }

    /**
     * 1.2.0 업그레이드 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        (new Upgrade_1_2_0)->run(new UpgradeContext('1.1.2', '1.2.0', '1.2.0', 'extension-upgrade'));
    }

    /**
     * 페이지 첨부 행을 직접 넣습니다 (예전 코드가 남긴 형태 재현).
     *
     * @param  string  $path  저장 경로
     * @return int 첨부 ID
     */
    private function insertAttachment(string $path): int
    {
        return DB::table('page_attachments')->insertGetId([
            'page_id' => null,
            'temp_key' => null,
            'hash' => substr(md5($path), 0, 12),
            'original_filename' => basename($path),
            'stored_filename' => basename($path),
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 1,
            'collection' => 'attachments',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
