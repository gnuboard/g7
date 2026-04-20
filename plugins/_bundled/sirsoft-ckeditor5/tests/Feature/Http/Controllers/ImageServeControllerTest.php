<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * CKEditor5 이미지 서빙 API 테스트
 *
 * GET /api/plugins/sirsoft-ckeditor5/images/{hash}
 */
class ImageServeControllerTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('plugins');
    }

    // ── 성공 ──

    public function test_serve_returns_image_for_valid_hash(): void
    {
        // 파일을 fake 스토리지에 저장
        $filePath = 'sirsoft-ckeditor5/images/2026/04/06/test.jpg';
        Storage::disk('plugins')->put($filePath, 'fake-image-content');

        $record = Ckeditor5ImageUpload::create([
            'original_name' => 'test.jpg',
            'file_path'     => 'images/2026/04/06/test.jpg',
            'storage_disk'  => 'plugins',
            'file_size'     => 100,
            'mime_type'     => 'image/jpeg',
            'uploaded_by'   => null,
        ]);

        $response = $this->get('/api/plugins/sirsoft-ckeditor5/images/'.$record->hash);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_serve_is_accessible_without_authentication(): void
    {
        $filePath = 'sirsoft-ckeditor5/images/2026/04/06/public.jpg';
        Storage::disk('plugins')->put($filePath, 'fake-image-content');

        $record = Ckeditor5ImageUpload::create([
            'original_name' => 'public.jpg',
            'file_path'     => 'images/2026/04/06/public.jpg',
            'storage_disk'  => 'plugins',
            'file_size'     => 100,
            'mime_type'     => 'image/jpeg',
            'uploaded_by'   => null,
        ]);

        // 인증 없이 접근
        $response = $this->get('/api/plugins/sirsoft-ckeditor5/images/'.$record->hash);

        $response->assertOk();
    }

    // ── 실패 ──

    public function test_serve_returns_404_for_unknown_hash(): void
    {
        $response = $this->get('/api/plugins/sirsoft-ckeditor5/images/unknown00000');

        $response->assertNotFound();
    }

    public function test_serve_returns_404_when_file_missing_from_storage(): void
    {
        // DB 레코드는 있지만 실제 파일은 없는 경우
        $record = Ckeditor5ImageUpload::create([
            'original_name' => 'missing.jpg',
            'file_path'     => 'images/2026/04/06/missing.jpg',
            'storage_disk'  => 'plugins',
            'file_size'     => 100,
            'mime_type'     => 'image/jpeg',
            'uploaded_by'   => null,
        ]);

        $response = $this->get('/api/plugins/sirsoft-ckeditor5/images/'.$record->hash);

        $response->assertNotFound();
    }
}
