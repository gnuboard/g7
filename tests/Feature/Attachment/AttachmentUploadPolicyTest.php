<?php

namespace Tests\Feature\Attachment;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 업로드 정책(용량·확장자) 강제 테스트 (C2)
 *
 * 수정 전에는 존재하지 않는 config 키(`attachment.max_size`)를 읽어 폴백 상수로 동작했고,
 * 확장자 제한은 어느 경로에서도 강제되지 않았습니다.
 *
 * 주의: 폴백(10 * 1024 = 10240)이 실제 기본값(10240 KB)과 우연히 같아 **기본 설정 상태에서는
 * 회귀 테스트가 green 이 되는 함정**이 있습니다. 반드시 기본값과 다른 값을 주입해 판정합니다.
 */
class AttachmentUploadPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        $this->user = $this->createUploaderAdmin();
    }

    /**
     * @test
     */
    public function 설정된_최대_용량을_초과하면_422_로_차단된다(): void
    {
        // 기본값(10240KB)과 다른 값을 주입해야 폴백과의 우연한 일치를 피할 수 있다
        config(['attachment.max_file_size' => 100]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
            'file' => UploadedFile::fake()->create('big.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(422);
    }

    /**
     * @test
     */
    public function 설정된_최대_용량_이내는_통과한다(): void
    {
        config(['attachment.max_file_size' => 100]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
            'file' => UploadedFile::fake()->create('small.pdf', 50, 'application/pdf'),
        ]);

        $response->assertStatus(201);
    }

    /**
     * @test
     */
    public function 허용되지_않는_확장자는_422_로_차단된다(): void
    {
        config(['attachment.allowed_extensions' => ['png', 'pdf']]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
            'file' => UploadedFile::fake()->create('shell.php', 10, 'text/x-php'),
        ]);

        $response->assertStatus(422);
    }

    /**
     * @test
     */
    public function 허용된_확장자는_통과한다(): void
    {
        config(['attachment.allowed_extensions' => ['png', 'pdf']]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
            'file' => UploadedFile::fake()->image('photo.png'),
        ]);

        $response->assertStatus(201);
    }

    /**
     * @test
     */
    public function 허용_확장자_목록이_비면_제한하지_않는다(): void
    {
        // 운영 중 예상치 못한 확장자로 업로드가 막히는 상황의 탈출구
        config(['attachment.allowed_extensions' => []]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
            'file' => UploadedFile::fake()->create('anything.php', 10, 'text/x-php'),
        ]);

        $response->assertStatus(201);
    }

    /**
     * @test
     */
    public function 확장_훅으로_허용_확장자를_조정할_수_있다(): void
    {
        config(['attachment.allowed_extensions' => ['png']]);

        HookManager::addFilter(
            'core.attachment.allowed_extensions',
            fn (array $extensions) => array_merge($extensions, ['pdf']),
        );

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ]);

        $response->assertStatus(201);
    }

    /**
     * @test
     */
    public function 일괄_업로드도_동일한_정책을_적용한다(): void
    {
        config(['attachment.allowed_extensions' => ['png']]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments/batch', [
            'files' => [
                UploadedFile::fake()->image('ok.png'),
                UploadedFile::fake()->create('shell.php', 10, 'text/x-php'),
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * 첨부파일 업로드 권한을 가진 관리자를 생성합니다.
     *
     * @return User 관리자 사용자
     */
    private function createUploaderAdmin(): User
    {
        $user = User::factory()->create(['is_super' => true, 'status' => 'active']);

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.attachments.create'],
            [
                'name' => json_encode(['ko' => '첨부파일 업로드', 'en' => 'Upload Attachments']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => PermissionType::Admin,
            ]
        );

        $role = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
