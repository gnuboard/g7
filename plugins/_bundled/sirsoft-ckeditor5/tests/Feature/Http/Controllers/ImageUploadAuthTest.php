<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature\Http\Controllers;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * CKEditor5 이미지 업로드 인가 회귀 테스트 (결함 ⑫⑬)
 *
 * 인가는 라우트 게이트(AdminBaseController: auth:sanctum + admin)가 전담한다.
 * 클라이언트 쿼리스트링(`?permission=`)이 검사 권한명을 정하고 빈 값이면 검사를
 * 통째로 skip 하던 죽은 인가 분기가 제거되었음을 고정한다.
 *
 * POST /api/plugins/sirsoft-ckeditor5/upload
 */
class ImageUploadAuthTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('plugins');
    }

    /**
     * 비로그인 요청은 admin 게이트에서 401 로 거부된다.
     *
     * @scenario case=guest_upload
     *
     * @effects upload_requires_admin
     */
    public function test_upload_rejects_guest(): void
    {
        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->postJson('/api/plugins/sirsoft-ckeditor5/upload', [
            'upload' => $file,
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('ckeditor5_image_uploads', 0);
    }

    /**
     * 인증된 일반 회원(비관리자)은 admin 게이트에서 403 으로 거부된다.
     *
     * @scenario case=non_admin_upload
     *
     * @effects upload_requires_admin
     */
    public function test_upload_rejects_authenticated_non_admin(): void
    {
        $member = User::factory()->create();
        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->actingAs($member)->postJson(
            '/api/plugins/sirsoft-ckeditor5/upload',
            ['upload' => $file]
        );

        $response->assertForbidden();
        $this->assertDatabaseCount('ckeditor5_image_uploads', 0);
    }

    /**
     * 관리자는 정상적으로 업로드된다 (기본 동작 보존).
     *
     * @scenario case=admin_upload
     *
     * @effects upload_requires_admin
     */
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
        $this->assertDatabaseCount('ckeditor5_image_uploads', 1);
    }

    /**
     * 클라이언트가 임의의 `?permission=` 쿼리를 붙여도 응답은 불변이다.
     *
     * 과거 죽은 인가 분기에서는 관리자가 보유하지 않은 권한명을 넘기면 403 이 되었으나,
     * 이제 쿼리는 완전히 무시되고 admin 게이트만 판정하므로 201 이 유지된다.
     *
     * @scenario case=permission_param_ignored
     *
     * @effects client_permission_param_has_no_effect
     */
    public function test_upload_ignores_client_supplied_permission_query(): void
    {
        $admin = $this->createAdminUser();
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100)->size(500);

        $response = $this->actingAs($admin)->postJson(
            '/api/plugins/sirsoft-ckeditor5/upload?permission=some.permission.the.admin.lacks',
            ['upload' => $file]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure(['url']);
        $this->assertDatabaseCount('ckeditor5_image_uploads', 1);
    }
}
