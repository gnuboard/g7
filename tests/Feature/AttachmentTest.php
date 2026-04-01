<?php

namespace Tests\Feature;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Attachment;
use App\Models\Permission;
use App\Models\PermissionHook;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 첨부파일 기능 테스트
 */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        HookManager::resetAll();

        // admin 역할 직접 생성 (시더 대신)
        $this->adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'description' => ['ko' => '시스템 관리자', 'en' => 'System administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );
    }

    /**
     * 사용자에게 admin 역할을 부여하는 헬퍼 메서드
     */
    private function assignAdminRole(User $user): void
    {
        $user->roles()->attach($this->adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
    }

    /**
     * admin 역할에 첨부파일 관련 권한을 부여하는 헬퍼 메서드
     */
    private function grantAttachmentPermissionsToAdmin(): void
    {
        $permissionIdentifiers = [
            'core.attachments.create',
            'core.attachments.update',
            'core.attachments.delete',
        ];

        foreach ($permissionIdentifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier . ' 권한', 'en' => $identifier . ' Permission']),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                    'resource_route_key' => in_array($identifier, ['core.attachments.update', 'core.attachments.delete']) ? 'attachment' : null,
                    'owner_key' => in_array($identifier, ['core.attachments.update', 'core.attachments.delete']) ? 'created_by' : null,
                ]
            );

            // admin 역할에 권한 부여 (scope_type=null → 전체 접근)
            if (! $this->adminRole->permissions()->where('identifier', $identifier)->exists()) {
                $this->adminRole->permissions()->attach($permission->id, ['granted_at' => now()]);
            }
        }
    }

    /**
     * 특정 권한을 가진 관리자 사용자를 생성하는 헬퍼 메서드
     *
     * @param  array  $permissions  권한 식별자 배열
     * @param  string|null  $scopeType  스코프 타입 (null: 전체, 'self': 본인만)
     * @return User 생성된 사용자
     */
    private function createAdminWithPermissions(array $permissions, ?string $scopeType = null): User
    {
        static $roleCounter = 0;
        $roleCounter++;

        $user = User::factory()->create();

        // 테스트용 역할 생성 (admin 역할과 별도)
        $testRole = Role::create([
            'identifier' => 'attachment_test_' . $roleCounter . '_' . uniqid(),
            'name' => json_encode(['ko' => '첨부파일 테스트', 'en' => 'Attachment Test']),
            'description' => json_encode(['ko' => '테스트용', 'en' => 'For testing']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        // 권한 생성 및 역할에 연결
        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier . ' 권한', 'en' => $identifier . ' Permission']),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                    'resource_route_key' => in_array($identifier, ['core.attachments.update', 'core.attachments.delete']) ? 'attachment' : null,
                    'owner_key' => in_array($identifier, ['core.attachments.update', 'core.attachments.delete']) ? 'created_by' : null,
                ]
            );

            // scope_type이 있는 경우 피벗에 설정
            $pivotData = ['granted_at' => now()];
            if ($scopeType !== null) {
                $pivotData['scope_type'] = $scopeType;
            }
            $testRole->permissions()->attach($permission->id, $pivotData);
        }

        // 사용자에게 테스트 역할 부여
        $user->roles()->attach($testRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    // =============================================
    // 업로드 테스트
    // =============================================

    /**
     * 파일 업로드 성공 테스트
     */
    public function test_파일_업로드_성공(): void
    {
        $this->grantAttachmentPermissionsToAdmin();
        $user = User::factory()->create();
        $this->assignAdminRole($user);

        $file = UploadedFile::fake()->image('test.jpg', 800, 600);

        $response = $this->actingAs($user)
            ->postJson('/api/admin/attachments', [
                'file' => $file,
                'collection' => 'images',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'hash',
                    'original_filename',
                    'mime_type',
                    'size',
                    'download_url',
                ],
            ]);

        $this->assertDatabaseHas('attachments', [
            'original_filename' => 'test.jpg',
            'collection' => 'images',
        ]);
    }

    /**
     * 일괄 업로드 성공 테스트
     */
    public function test_일괄_업로드_성공(): void
    {
        $this->grantAttachmentPermissionsToAdmin();
        $user = User::factory()->create();
        $this->assignAdminRole($user);

        $files = [
            UploadedFile::fake()->image('test1.jpg'),
            UploadedFile::fake()->image('test2.jpg'),
        ];

        $response = $this->actingAs($user)
            ->postJson('/api/admin/attachments/batch', [
                'files' => $files,
                'collection' => 'gallery',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('attachments', 2);
    }

    /**
     * 관리자 아닌 사용자의 업로드 시도 403 테스트
     */
    public function test_관리자_아닌_사용자_업로드_403(): void
    {
        $user = User::factory()->create();
        // 관리자 역할 없음

        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->actingAs($user)
            ->postJson('/api/admin/attachments', [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    // =============================================
    // 다운로드 테스트 (공개 API - HookManager만 체크)
    // =============================================

    /**
     * 공개 파일 다운로드 성공 테스트 (비로그인)
     */
    public function test_공개_파일_다운로드_비로그인_성공(): void
    {
        $owner = User::factory()->create();
        $attachment = Attachment::factory()->uploadedBy($owner)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        // 비로그인 사용자도 다운로드 가능 (HookManager 매핑 없음)
        $response = $this->getJson("/api/attachment/{$attachment->hash}");

        $response->assertOk();
    }

    /**
     * 파일 다운로드 업로더 본인 테스트
     */
    public function test_파일_다운로드_업로더_본인(): void
    {
        $user = User::factory()->create();
        $attachment = Attachment::factory()->uploadedBy($user)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        $response = $this->actingAs($user)
            ->get("/api/attachment/{$attachment->hash}");

        $response->assertOk();
    }

    /**
     * 파일 다운로드 관리자 테스트
     */
    public function test_파일_다운로드_관리자(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $this->assignAdminRole($admin);

        $attachment = Attachment::factory()->uploadedBy($owner)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        $response = $this->actingAs($admin)
            ->get("/api/attachment/{$attachment->hash}");

        $response->assertOk();
    }

    /**
     * 존재하지 않는 파일 다운로드 404 테스트
     */
    public function test_존재하지_않는_파일_다운로드_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/api/attachment/nonexistent1');

        $response->assertStatus(404);
    }

    // =============================================
    // HookManager 권한 테스트 (공개 다운로드 기능 레벨)
    // =============================================

    /**
     * 훅 권한 미매핑 시 모든 사용자 허용 테스트
     */
    public function test_훅_권한_미매핑_시_모든_사용자_허용(): void
    {
        $owner = User::factory()->create();
        $attachment = Attachment::factory()->uploadedBy($owner)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        // permission_hooks에 매핑 없음 → 모든 사용자 허용
        $response = $this->getJson("/api/attachment/{$attachment->hash}");

        $response->assertOk();
    }

    /**
     * 훅 권한 매핑 시 권한 있는 사용자 허용 테스트
     */
    public function test_훅_권한_매핑_시_권한_있는_사용자_허용(): void
    {
        // 권한 생성
        $permission = Permission::create([
            'identifier' => 'attachment.download',
            'name' => ['ko' => '첨부파일 다운로드', 'en' => 'Download Attachment'],
            'type' => PermissionType::Admin,
        ]);

        // 훅에 권한 매핑
        PermissionHook::create([
            'permission_id' => $permission->id,
            'hook_name' => 'core.attachment.download',
        ]);

        // 역할에 권한 부여
        $role = Role::factory()->create();
        $role->permissions()->attach($permission->id);

        // 사용자에게 역할 부여
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        $owner = User::factory()->create();
        $attachment = Attachment::factory()->uploadedBy($owner)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        $response = $this->actingAs($user)
            ->get("/api/attachment/{$attachment->hash}");

        $response->assertOk();
    }

    /**
     * 훅 권한 매핑 시 권한 없는 사용자 차단 테스트
     */
    public function test_훅_권한_매핑_시_권한_없는_사용자_차단(): void
    {
        // 권한 생성
        $permission = Permission::create([
            'identifier' => 'attachment.download',
            'name' => ['ko' => '첨부파일 다운로드', 'en' => 'Download Attachment'],
            'type' => PermissionType::Admin,
        ]);

        // 훅에 권한 매핑
        PermissionHook::create([
            'permission_id' => $permission->id,
            'hook_name' => 'core.attachment.download',
        ]);

        // 사용자에게 권한 미부여
        $user = User::factory()->create();

        $owner = User::factory()->create();
        $attachment = Attachment::factory()->uploadedBy($owner)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        $response = $this->actingAs($user)
            ->get("/api/attachment/{$attachment->hash}");

        $response->assertStatus(403);
    }

    /**
     * 훅 권한 매핑 시 비로그인 사용자 차단 테스트
     */
    public function test_훅_권한_매핑_시_비로그인_사용자_차단(): void
    {
        // 권한 생성
        $permission = Permission::create([
            'identifier' => 'attachment.download',
            'name' => ['ko' => '첨부파일 다운로드', 'en' => 'Download Attachment'],
            'type' => PermissionType::Admin,
        ]);

        // 훅에 권한 매핑
        PermissionHook::create([
            'permission_id' => $permission->id,
            'hook_name' => 'core.attachment.download',
        ]);

        $owner = User::factory()->create();
        $attachment = Attachment::factory()->uploadedBy($owner)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        // 비로그인 상태
        $response = $this->getJson("/api/attachment/{$attachment->hash}");

        $response->assertStatus(403);
    }

    /**
     * 훅 권한 매핑 시 관리자 허용 테스트
     */
    public function test_훅_권한_매핑_시_관리자_허용(): void
    {
        // admin 역할에 admin 타입 권한 부여 (isAdmin() = true 조건)
        $this->grantAttachmentPermissionsToAdmin();

        // 다운로드 훅에 별도 권한 매핑
        $permission = Permission::create([
            'identifier' => 'attachment.download',
            'name' => ['ko' => '첨부파일 다운로드', 'en' => 'Download Attachment'],
            'type' => PermissionType::Admin,
        ]);

        // 훅에 권한 매핑
        PermissionHook::create([
            'permission_id' => $permission->id,
            'hook_name' => 'core.attachment.download',
        ]);

        // 관리자 (isAdmin()=true이므로 훅 권한 체크 bypass)
        $admin = User::factory()->create();
        $this->assignAdminRole($admin);

        $owner = User::factory()->create();
        $attachment = Attachment::factory()->uploadedBy($owner)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        $response = $this->actingAs($admin)
            ->get("/api/attachment/{$attachment->hash}");

        $response->assertOk();
    }

    // =============================================
    // 관리자 CRUD 권한 테스트 (PermissionMiddleware)
    // =============================================

    /**
     * 권한 있는 관리자 파일 삭제 성공 테스트
     */
    public function test_권한_있는_관리자_파일_삭제_성공(): void
    {
        $user = $this->createAdminWithPermissions(['core.attachments.delete']);

        $attachment = Attachment::factory()->create();
        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        $response = $this->actingAs($user)
            ->deleteJson("/api/admin/attachments/{$attachment->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    /**
     * scope_type=self 본인 파일 삭제 성공 테스트
     */
    public function test_scope_self_본인_파일_삭제_성공(): void
    {
        $user = $this->createAdminWithPermissions(['core.attachments.delete'], 'self');

        // 본인이 업로드한 파일
        $attachment = Attachment::factory()->create(['created_by' => $user->id]);
        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        $response = $this->actingAs($user)
            ->deleteJson("/api/admin/attachments/{$attachment->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    /**
     * scope_type=self 타인 파일 삭제 403 테스트
     */
    public function test_scope_self_타인_파일_삭제_403(): void
    {
        $user = $this->createAdminWithPermissions(['core.attachments.delete'], 'self');

        // 타인이 업로드한 파일
        $otherUser = User::factory()->create();
        $attachment = Attachment::factory()->create(['created_by' => $otherUser->id]);
        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        $response = $this->actingAs($user)
            ->deleteJson("/api/admin/attachments/{$attachment->id}");

        $response->assertStatus(403);
    }

    // =============================================
    // 순서 변경 테스트
    // =============================================

    /**
     * 파일 순서 변경 성공 테스트
     */
    public function test_파일_순서_변경_성공(): void
    {
        $this->grantAttachmentPermissionsToAdmin();
        $user = User::factory()->create();
        $this->assignAdminRole($user);

        $attachments = Attachment::factory()->count(3)->create();

        $orderData = [
            ['id' => $attachments[2]->id, 'order' => 1],
            ['id' => $attachments[0]->id, 'order' => 2],
            ['id' => $attachments[1]->id, 'order' => 3],
        ];

        $response = $this->actingAs($user)
            ->patchJson('/api/admin/attachments/reorder', [
                'order' => $orderData,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('attachments', [
            'id' => $attachments[2]->id,
            'order' => 1,
        ]);
    }

    /**
     * 파일 삭제 성공 테스트 (admin 역할)
     */
    public function test_파일_삭제_성공(): void
    {
        $this->grantAttachmentPermissionsToAdmin();
        $user = User::factory()->create();
        $this->assignAdminRole($user);

        $attachment = Attachment::factory()->create();
        Storage::disk($attachment->disk)->put($attachment->path, 'test content');

        $response = $this->actingAs($user)
            ->deleteJson("/api/admin/attachments/{$attachment->id}");

        $response->assertOk();
        // 첨부파일은 forceDelete로 영구 삭제됨
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    // =============================================
    // 모델 속성 테스트
    // =============================================

    /**
     * is_image 속성 테스트
     */
    public function test_is_image_속성(): void
    {
        $imageAttachment = Attachment::factory()->image()->create();
        $pdfAttachment = Attachment::factory()->pdf()->create();

        $this->assertTrue($imageAttachment->is_image);
        $this->assertFalse($pdfAttachment->is_image);
    }

    /**
     * size_formatted 속성 테스트
     */
    public function test_size_formatted_속성(): void
    {
        $attachment = Attachment::factory()->create(['size' => 1536]);

        $this->assertEquals('1.5 KB', $attachment->size_formatted);
    }

    /**
     * download_url 속성 테스트
     */
    public function test_download_url_속성(): void
    {
        $attachment = Attachment::factory()->create(['hash' => 'abc123def456']);

        $this->assertEquals('/api/attachment/abc123def456', $attachment->download_url);
    }

    // =============================================
    // 이미지 캐싱 테스트
    // =============================================

    /**
     * 이미지 다운로드 시 캐싱 헤더 포함 테스트
     */
    public function test_이미지_다운로드_캐싱_헤더_포함(): void
    {
        $user = User::factory()->create();
        $attachment = Attachment::factory()->image()->uploadedBy($user)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'fake image content');

        $response = $this->actingAs($user)
            ->get("/api/attachment/{$attachment->hash}");

        $response->assertOk();

        // 캐싱 헤더 확인
        $response->assertHeader('ETag');

        // Cache-Control 헤더 존재 확인 (개발 환경에서는 no-cache일 수 있음)
        $this->assertTrue($response->headers->has('Cache-Control'));
    }

    /**
     * 비이미지 파일 다운로드 시 기존 방식 유지 테스트
     */
    public function test_비이미지_파일_다운로드_기존_방식(): void
    {
        $user = User::factory()->create();
        $attachment = Attachment::factory()->pdf()->uploadedBy($user)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'fake pdf content');

        $response = $this->actingAs($user)
            ->get("/api/attachment/{$attachment->hash}");

        $response->assertOk();

        // Content-Disposition이 attachment (다운로드)인지 확인
        $contentDisposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $contentDisposition);
    }

    /**
     * 이미지 304 Not Modified 응답 테스트
     */
    public function test_이미지_304_not_modified_응답(): void
    {
        $user = User::factory()->create();
        $attachment = Attachment::factory()->image()->uploadedBy($user)->create();

        Storage::disk($attachment->disk)->put($attachment->path, 'fake image content');

        // 첫 번째 요청으로 ETag 획득
        $firstResponse = $this->actingAs($user)
            ->get("/api/attachment/{$attachment->hash}");

        $firstResponse->assertOk();
        $etag = $firstResponse->headers->get('ETag');

        // ETag와 함께 두 번째 요청
        $secondResponse = $this->actingAs($user)
            ->withHeader('If-None-Match', $etag)
            ->get("/api/attachment/{$attachment->hash}");

        // 304 Not Modified 응답 확인
        $secondResponse->assertStatus(304);
    }
}
