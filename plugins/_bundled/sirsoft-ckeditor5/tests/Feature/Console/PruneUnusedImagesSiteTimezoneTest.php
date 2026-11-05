<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature\Console;

use App\Extension\Storage\PluginStorageDriver;
use Carbon\Carbon;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 미참조 이미지 정리 드라이런 표의 업로드 시각 테스트 (#160)
 *
 * 운영자 콘솔 출력은 사이트 시간대로 찍는다 — UTC 원문이면 사이트 시각과 시간대 차이만큼 어긋난다.
 */
class PruneUnusedImagesSiteTimezoneTest extends PluginTestCase
{
    /**
     * 드라이런 표의 업로드 시각은 사이트 시간대다 — UTC 2026-09-01 16:00 = 서울 2026-09-02 01:00.
     *
     * @scenario surface=dry_run_uploaded_at
     *
     * @effects console_uploaded_at_uses_site_timezone
     *
     * @return void 반환값 없음
     */
    public function test_dry_run_table_prints_uploaded_at_in_site_timezone(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        $filename = uniqid('tz-').'.png';
        (new PluginStorageDriver('sirsoft-ckeditor5', 'plugins'))->put('images', "2026/09/01/{$filename}", 'bytes');
        $upload = Ckeditor5ImageUpload::create([
            'original_name' => $filename,
            'file_path' => "images/2026/09/01/{$filename}",
            'storage_disk' => 'plugins',
            'file_size' => 5,
            'mime_type' => 'image/png',
        ]);
        $upload->forceFill(['created_at' => Carbon::parse('2026-09-01 16:00:00', 'UTC')])->saveQuietly();

        $this->artisan('sirsoft-ckeditor5:prune-unused-images --dry-run --days=7')
            ->expectsOutputToContain('2026-09-02 01:00:00')
            ->assertSuccessful();
    }
}
