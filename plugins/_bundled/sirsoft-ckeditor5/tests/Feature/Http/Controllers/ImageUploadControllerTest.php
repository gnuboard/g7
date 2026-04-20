<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature\Http\Controllers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * CKEditor5 이미지 업로드 API 테스트
 *
 * POST /api/plugins/sirsoft-ckeditor5/upload
 */
class ImageUploadControllerTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('plugins');
    }

    // ── 인증 ──

    public function test_upload_requires_authentication(): void
    {
        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->postJson('/api/plugins/sirsoft-ckeditor5/upload', [
            'upload' => $file,
        ]);

        $response->assertUnauthorized();
    }

    // ── 성공 ──

    public function test_upload_succeeds_for_admin(): void
    {
        $admin = $this->createAdminUser();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100)->size(500);

        $response = $this->actingAs($admin)->postJson(
            '/api/plugins/sirsoft-ckeditor5/upload',
            ['upload' => $file]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure(['url']);
        $this->assertStringContainsString('/api/plugins/sirsoft-ckeditor5/images/', $response->json('url'));
    }

    public function test_upload_stores_record_in_database(): void
    {
        $admin = $this->createAdminUser();
        $file = UploadedFile::fake()->image('photo.png')->size(200);

        $this->actingAs($admin)->postJson(
            '/api/plugins/sirsoft-ckeditor5/upload',
            ['upload' => $file]
        );

        $this->assertDatabaseCount('ckeditor5_image_uploads', 1);

        $record = Ckeditor5ImageUpload::first();
        $this->assertNotNull($record->hash);
        $this->assertEquals(12, strlen($record->hash));
        $this->assertEquals('photo.png', $record->original_name);
        $this->assertEquals('plugins', $record->storage_disk);
        $this->assertStringStartsWith('images/', $record->file_path);
        $this->assertEquals($admin->id, $record->uploaded_by);
    }

    public function test_upload_returns_correct_url_format(): void
    {
        $admin = $this->createAdminUser();
        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->actingAs($admin)->postJson(
            '/api/plugins/sirsoft-ckeditor5/upload',
            ['upload' => $file]
        );

        $url = $response->json('url');
        $record = Ckeditor5ImageUpload::first();

        $this->assertEquals('/api/plugins/sirsoft-ckeditor5/images/'.$record->hash, $url);
    }

    // ── 검증 ──

    public function test_upload_rejects_non_image_file(): void
    {
        $admin = $this->createAdminUser();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($admin)->postJson(
            '/api/plugins/sirsoft-ckeditor5/upload',
            ['upload' => $file]
        );

        $response->assertUnprocessable();
    }

    public function test_upload_rejects_missing_file(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson(
            '/api/plugins/sirsoft-ckeditor5/upload',
            []
        );

        $response->assertUnprocessable();
    }
}
