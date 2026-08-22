<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature\Http\Controllers\Admin;

use App\Enums\PermissionType;
use App\Extension\Storage\PluginStorageDriver;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Services\ImageUploadAdminService;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 업로드 이미지 관리 API 테스트
 *
 * 조회·삭제 권한이 분리돼 있으므로 권한 경계(403)와 게스트(401)를 함께 고정합니다.
 */
class ImageUploadAdminControllerTest extends PluginTestCase
{
    private const INDEX_URL = '/api/plugins/sirsoft-ckeditor5/admin/uploads';

    /**
     * 지정한 업로드 권한만 가진 사용자를 만듭니다.
     *
     * @param  array<int, string>  $actions  부여할 액션 (read|delete)
     * @return User 생성된 사용자
     */
    private function userWithUploadPermissions(array $actions): User
    {
        $role = Role::firstOrCreate(
            ['identifier' => 'ckeditor5-uploads-test-'.implode('-', $actions ?: ['none'])],
            ['name' => ['ko' => '테스트 역할', 'en' => 'Test Role'], 'description' => ['ko' => '', 'en' => '']]
        );

        $adminAccess = Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            ['name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'], 'type' => PermissionType::Admin]
        );

        $permissionIds = [$adminAccess->id];

        foreach ($actions as $action) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => "sirsoft-ckeditor5.uploads.{$action}"],
                ['name' => ['ko' => $action, 'en' => $action], 'type' => PermissionType::Admin]
            )->id;
        }

        $role->permissions()->syncWithoutDetaching($permissionIds);

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * 파일과 기록을 함께 만듭니다.
     *
     * @param  string|null  $hash  이미지 해시 (null 이면 자동 생성)
     * @return Ckeditor5ImageUpload 생성된 기록
     */
    private function makeUpload(?string $hash = null): Ckeditor5ImageUpload
    {
        $filename = uniqid('admin-').'.png';
        (new PluginStorageDriver('sirsoft-ckeditor5', 'plugins'))->put('images', "2026/08/14/{$filename}", 'bytes');

        return Ckeditor5ImageUpload::create(array_filter([
            'hash' => $hash,
            'original_name' => $filename,
            'file_path' => "images/2026/08/14/{$filename}",
            'storage_disk' => 'plugins',
            'file_size' => 5,
            'mime_type' => 'image/png',
        ]));
    }

    /**
     * @effects admin_index_lists_uploads
     */
    #[Test]
    public function index_returns_uploads_with_reference_state_and_pagination(): void
    {
        $upload = $this->makeUpload('aaaaaaaaaaaa');

        $response = $this->actingAs($this->userWithUploadPermissions(['read']))
            ->getJson(self::INDEX_URL);

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.meta.scan_limited', false)
            ->assertJsonPath('data.data.0.id', $upload->id)
            ->assertJsonPath('data.data.0.referenced', false);
    }

    /**
     * 목록 응답은 화면이 실제로 그리는 필드만 싣는다.
     *
     * 저장 경로(`file_path`)·디스크(`storage_disk`)는 화면이 쓰지 않는 내부 값이라 노출하지
     * 않는다. 조회도 같은 폭이어야 프루닝이 의미를 갖는다.
     *
     * @effects admin_index_column_pruning
     */
    #[Test]
    public function index_exposes_only_the_declared_columns(): void
    {
        $this->makeUpload('a1a1a1a1a1a1');

        $response = $this->actingAs($this->userWithUploadPermissions(['read']))
            ->getJson(self::INDEX_URL);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        ['id', 'hash', 'original_name', 'file_size', 'mime_type', 'created_at', 'download_url', 'referenced'],
                    ],
                ],
            ]);

        $row = $response->json('data.data.0');

        $this->assertArrayNotHasKey('file_path', $row);
        $this->assertArrayNotHasKey('storage_disk', $row);
    }

    /**
     * @effects admin_index_referenced_badge
     */
    #[Test]
    public function referenced_upload_is_reported_as_referenced(): void
    {
        $upload = $this->makeUpload('bbbbbbbbbbbb');

        DB::table('mail_templates')->insert([
            'type' => 'admin-list-test',
            'subject' => json_encode(['ko' => '목록 테스트']),
            'body' => '<img src="/api/plugins/sirsoft-ckeditor5/images/bbbbbbbbbbbb">',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->userWithUploadPermissions(['read']))
            ->getJson(self::INDEX_URL)
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $upload->id)
            ->assertJsonPath('data.data.0.referenced', true);
    }

    /**
     * @effects admin_index_unreferenced_filter
     */
    #[Test]
    public function unreferenced_filter_returns_only_unreferenced_rows(): void
    {
        $this->makeUpload('cccccccccccc');
        $referenced = $this->makeUpload('dddddddddddd');

        DB::table('mail_templates')->insert([
            'type' => 'admin-filter-test',
            'subject' => json_encode(['ko' => '필터 테스트']),
            'body' => '<img src="/api/plugins/sirsoft-ckeditor5/images/dddddddddddd">',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->userWithUploadPermissions(['read']))
            ->getJson(self::INDEX_URL.'?referenced=unreferenced');

        $response->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->assertSame('cccccccccccc', $response->json('data.data.0.hash'));
        $this->assertNotSame($referenced->id, $response->json('data.data.0.id'));
    }

    /**
     * 참조 필터는 최신 N건만 훑는다. 그 상한에 걸리면 목록이 전수가 아니라는 사실을
     * 응답이 스스로 알려야 한다.
     *
     * `scan_limited` 가 false 로 굳으면 화면은 "최근 500건 기준" 안내를 띄우지 않고,
     * 운영자는 윈도우 밖 이미지를 "이미 정리된 것" 으로 읽는다 — 오류도 경고도 남지 않는
     * 조용한 오해라 응답 메타가 유일한 통로다.
     *
     * @effects admin_index_scan_window_limited
     */
    #[Test]
    public function reference_filter_reports_scan_limited_when_the_window_is_full(): void
    {
        $rows = [];

        for ($i = 0; $i < ImageUploadAdminService::SCAN_WINDOW; $i++) {
            $filename = sprintf('window-%04d.png', $i);

            $rows[] = [
                'hash' => sprintf('%012d', $i),
                'original_name' => $filename,
                'file_path' => "images/2026/08/14/{$filename}",
                'storage_disk' => 'plugins',
                'file_size' => 5,
                'mime_type' => 'image/png',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // 파일 실체는 목록 판정에 관여하지 않는다 — 윈도우 상한만 재현하면 되므로 일괄 삽입한다.
        DB::table((new Ckeditor5ImageUpload)->getTable())->insert($rows);

        $this->actingAs($this->userWithUploadPermissions(['read']))
            ->getJson(self::INDEX_URL.'?referenced=unreferenced&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.meta.scan_limited', true)
            ->assertJsonPath('data.meta.scan_window', ImageUploadAdminService::SCAN_WINDOW);
    }

    /**
     * @effects admin_index_rejects_unknown_sort
     */
    #[Test]
    public function unknown_sort_column_is_rejected(): void
    {
        $this->actingAs($this->userWithUploadPermissions(['read']))
            ->getJson(self::INDEX_URL.'?sort_by=file_path')
            ->assertStatus(422);
    }

    /**
     * @effects admin_index_requires_read_permission
     */
    #[Test]
    public function index_is_forbidden_without_the_read_permission(): void
    {
        $this->actingAs($this->userWithUploadPermissions([]))
            ->getJson(self::INDEX_URL)
            ->assertStatus(403);
    }

    /**
     * @effects admin_index_requires_authentication
     */
    #[Test]
    public function index_is_unauthorized_for_guests(): void
    {
        $this->getJson(self::INDEX_URL)->assertStatus(401);
    }

    /**
     * @effects admin_destroy_removes_file_and_record
     */
    #[Test]
    public function destroy_removes_both_the_file_and_the_record(): void
    {
        $upload = $this->makeUpload();
        $relativePath = substr($upload->file_path, strlen('images/'));

        $this->actingAs($this->userWithUploadPermissions(['read', 'delete']))
            ->deleteJson(self::INDEX_URL.'/'.$upload->id)
            ->assertOk();

        $this->assertFalse(
            (new PluginStorageDriver('sirsoft-ckeditor5', 'plugins'))->exists('images', $relativePath)
        );
        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @effects admin_destroy_not_found
     */
    #[Test]
    public function destroy_returns_not_found_for_a_missing_id(): void
    {
        $this->actingAs($this->userWithUploadPermissions(['read', 'delete']))
            ->deleteJson(self::INDEX_URL.'/999999')
            ->assertStatus(404);
    }

    /**
     * @effects admin_destroy_requires_delete_permission
     */
    #[Test]
    public function destroy_is_forbidden_with_read_permission_only(): void
    {
        $upload = $this->makeUpload();

        $this->actingAs($this->userWithUploadPermissions(['read']))
            ->deleteJson(self::INDEX_URL.'/'.$upload->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('ckeditor5_image_uploads', ['id' => $upload->id]);
    }

    /**
     * @effects admin_bulk_delete_removes_selected
     */
    #[Test]
    public function bulk_delete_removes_every_selected_upload(): void
    {
        $first = $this->makeUpload();
        $second = $this->makeUpload();

        $this->actingAs($this->userWithUploadPermissions(['read', 'delete']))
            ->postJson(self::INDEX_URL.'/bulk-delete', ['ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $first->id]);
        $this->assertDatabaseMissing('ckeditor5_image_uploads', ['id' => $second->id]);
    }

    /**
     * @effects admin_bulk_delete_rejects_empty
     */
    #[Test]
    public function bulk_delete_rejects_an_empty_selection(): void
    {
        $this->actingAs($this->userWithUploadPermissions(['read', 'delete']))
            ->postJson(self::INDEX_URL.'/bulk-delete', ['ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    /**
     * @effects admin_bulk_delete_rejects_unknown_id
     */
    #[Test]
    public function bulk_delete_rejects_ids_that_do_not_exist(): void
    {
        $this->actingAs($this->userWithUploadPermissions(['read', 'delete']))
            ->postJson(self::INDEX_URL.'/bulk-delete', ['ids' => [999999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids.0');
    }
}
