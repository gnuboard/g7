<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionStatus;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayoutAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 템플릿 레이아웃 첨부 파일 컨트롤러 Feature 테스트
 *
 * golden path(업로드/조회/삭제) + 권한 경계 + 유효성 실패 + 스토리지 실삭제 검증.
 */
class TemplateLayoutAttachmentControllerTest extends TestCase
{
    use RefreshDatabase;

    // 같은 스위트의 다른 레이아웃 테스트(LayoutSourceMetaServingTest 등)가
    // GDPR 플러그인 미들웨어(CookieConsentMiddleware → g7_gdpr_policy_versions)를
    // 모든 요청에서 거치므로, 공유 migrate:fresh 가 실행 순서와 무관하게 GDPR 테이블을
    // 포함하도록 동일 확장을 선언한다 (migrate:fresh 1회 함정 회피).
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private User $adminUser;

    private string $adminToken;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('attachment.disk', 'attachments'));

        $editPermission = Permission::firstOrCreate(
            ['identifier' => 'core.templates.layouts.edit'],
            ['name' => '레이아웃 편집', 'display_name' => '레이아웃 편집', 'type' => 'admin'],
        );
        $role = Role::firstOrCreate(
            ['identifier' => 'super-admin'],
            ['name' => 'Super Admin', 'display_name' => 'Super Admin', 'is_default' => false],
        );
        $role->permissions()->syncWithoutDetaching([$editPermission->id]);

        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->syncWithoutDetaching([$role->id]);
        $this->adminToken = $this->adminUser->createToken('admin')->plainTextToken;

        $this->template = Template::create([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본', 'en' => 'Basic'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본', 'en' => 'Basic'],
        ]);
    }

    /** 인증 헤더 헬퍼 */
    private function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->adminToken}", 'Accept' => 'application/json'];
    }

    public function test_upload_creates_attachment_and_stores_file(): void
    {
        $file = UploadedFile::fake()->image('bg.png', 100, 100);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/admin/templates/{$this->template->identifier}/layout-attachments", [
                'file' => $file,
                'layout_name' => 'home',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.original_name', 'bg.png')
            ->assertJsonPath('data.layout_name', 'home');

        // url 은 공개 서빙 라우트를 가리켜야 한다
        $url = $response->json('data.url');
        $this->assertNotNull($url);
        $this->assertStringContainsString('/layout-attachments/', (string) $url);
        $this->assertStringContainsString('/file', (string) $url);

        $attachment = TemplateLayoutAttachment::first();
        $this->assertNotNull($attachment);
        $this->assertSame($this->template->id, $attachment->template_id);
        $this->assertSame($this->adminUser->id, $attachment->created_by);

        // 스토리지에 실제 저장됐는지 (category/path)
        Storage::disk($attachment->disk)->assertExists('template-layout-attachments/'.$attachment->path);
    }

    public function test_upload_fires_hooks(): void
    {
        // Arrange
        $beforeUploadFired = false;
        $afterUploadFired = false;
        $filterApplied = false;

        HookManager::addAction('core.template_layout_attachment.before_upload', function () use (&$beforeUploadFired) {
            $beforeUploadFired = true;
        });

        HookManager::addFilter('core.template_layout_attachment.filter_upload_file', function ($file) use (&$filterApplied) {
            $filterApplied = true;

            return $file;
        });

        HookManager::addAction('core.template_layout_attachment.after_upload', function () use (&$afterUploadFired) {
            $afterUploadFired = true;
        });

        // Act
        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/admin/templates/{$this->template->identifier}/layout-attachments", [
                'file' => UploadedFile::fake()->image('hooked.png', 10, 10),
                'layout_name' => 'home',
            ]);

        // Assert
        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertTrue($beforeUploadFired, 'before_upload hook should be fired');
        $this->assertTrue($filterApplied, 'filter_upload_file hook should be applied');
        $this->assertTrue($afterUploadFired, 'after_upload hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('core.template_layout_attachment.before_upload');
        HookManager::clearFilter('core.template_layout_attachment.filter_upload_file');
        HookManager::clearAction('core.template_layout_attachment.after_upload');
    }

    public function test_index_lists_template_attachments(): void
    {
        TemplateLayoutAttachment::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'disk' => config('attachment.disk', 'attachments'),
            'path' => 'sirsoft-basic/2026/05/29/a.png',
            'original_name' => 'a.png',
            'mime_type' => 'image/png',
            'size' => 123,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-attachments");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.0.original_name', 'a.png');
    }

    public function test_index_filters_by_layout_name(): void
    {
        foreach (['home', 'about'] as $layout) {
            TemplateLayoutAttachment::create([
                'template_id' => $this->template->id,
                'layout_name' => $layout,
                'disk' => 'attachments',
                'path' => "p/{$layout}.png",
                'original_name' => "{$layout}.png",
                'mime_type' => 'image/png',
                'size' => 1,
                'created_by' => $this->adminUser->id,
            ]);
        }

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-attachments?layout_name=home");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('home.png', $response->json('data.0.original_name'));
    }

    public function test_destroy_removes_row_and_storage_file(): void
    {
        $disk = config('attachment.disk', 'attachments');
        $path = 'sirsoft-basic/2026/05/29/del.png';
        Storage::disk($disk)->put('template-layout-attachments/'.$path, 'data');

        $attachment = TemplateLayoutAttachment::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'disk' => $disk,
            'path' => $path,
            'original_name' => 'del.png',
            'mime_type' => 'image/png',
            'size' => 4,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/admin/templates/layout-attachments/{$attachment->id}");

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertNull(TemplateLayoutAttachment::find($attachment->id));
        // 스토리지 파일도 명시적으로 삭제됐는지 (CASCADE 미의존)
        Storage::disk($disk)->assertMissing('template-layout-attachments/'.$path);
    }

    public function test_upload_rejects_non_image_file(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/admin/templates/{$this->template->identifier}/layout-attachments", [
                'file' => $file,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('template_layout_attachments', 0);
    }

    public function test_upload_requires_permission(): void
    {
        // 권한 없는 사용자
        $noPermUser = User::factory()->create();
        $token = $noPermUser->createToken('np')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/admin/templates/{$this->template->identifier}/layout-attachments", [
            'file' => UploadedFile::fake()->image('x.png'),
        ]);

        $response->assertStatus(403);
    }

    public function test_upload_to_nonexistent_template_returns_404(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/admin/templates/nope-nope/layout-attachments', [
                'file' => UploadedFile::fake()->image('x.png'),
            ]);

        $response->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 공개 서빙 라우트 — 발행 배경 이미지 공개 로드
    // ─────────────────────────────────────────────────────────────────────────

    /** 업로드된 첨부를 생성하고 모델 반환 (서빙 테스트용 헬퍼) */
    private function makeStoredAttachment(?Template $template = null, string $path = 'sirsoft-basic/2026/05/29/served.png'): TemplateLayoutAttachment
    {
        $template ??= $this->template;
        $disk = config('attachment.disk', 'attachments');
        Storage::disk($disk)->put('template-layout-attachments/'.$path, 'PNGDATA');

        return TemplateLayoutAttachment::create([
            'template_id' => $template->id,
            'layout_name' => 'home',
            'disk' => $disk,
            'path' => $path,
            'original_name' => 'served.png',
            'mime_type' => 'image/png',
            'size' => 7,
            'created_by' => $this->adminUser->id,
        ]);
    }

    public function test_serve_file_returns_image_publicly_without_auth(): void
    {
        $attachment = $this->makeStoredAttachment();

        // 인증 헤더 없이(공개) 접근 — 발행 배경은 방문자에게 로드되어야 함
        $response = $this->get("/api/templates/{$this->template->identifier}/layout-attachments/{$attachment->id}/file");

        $response->assertStatus(200);
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
        // 캐싱 헤더(ETag) 동반
        $this->assertNotNull($response->headers->get('ETag'));
    }

    public function test_serve_file_cross_template_returns_404(): void
    {
        // 다른 템플릿 소속 첨부를 본 템플릿 경로로 요청 → 404 (교차 접근 차단)
        $other = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '관리자', 'en' => 'Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '관리자', 'en' => 'Admin'],
        ]);
        $attachment = $this->makeStoredAttachment($other, 'sirsoft-admin_basic/x.png');

        $response = $this->getJson("/api/templates/{$this->template->identifier}/layout-attachments/{$attachment->id}/file");

        $response->assertStatus(404);
    }

    public function test_serve_file_missing_storage_returns_404(): void
    {
        // DB 행은 있으나 스토리지 파일이 없는 경우 → 404
        $attachment = TemplateLayoutAttachment::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'disk' => config('attachment.disk', 'attachments'),
            'path' => 'sirsoft-basic/missing.png',
            'original_name' => 'missing.png',
            'mime_type' => 'image/png',
            'size' => 1,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->getJson("/api/templates/{$this->template->identifier}/layout-attachments/{$attachment->id}/file");

        $response->assertStatus(404);
    }

    /**
     * 공개 자산 디스크로 쓸 가짜 CDN 디스크를 등록합니다.
     *
     * `Storage::fake()` 는 해석된 디스크 인스턴스만 교체하고 `filesystems.disks.*`
     * config 는 건드리지 않는다. 공개 자산 디스크 게이트는 그 config 존재를 보므로,
     * fake 만으로는 고아 디스크로 판정되어 프록시가 나온다.
     */
    private function registerFakeCdnDisk(): void
    {
        config(['filesystems.disks.fake_cdn' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]]);
        Storage::fake('fake_cdn', ['url' => 'https://cdn.test/assets']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 공개 자산 디스크 — 업로드 저장 위치와 응답 URL 형태 (공개 #134)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 공개 자산 디스크 미설정이면 기존 첨부 디스크에 저장되고 프록시 URL 이어야 합니다.
     *
     * @scenario public_asset_disk=unset,row_disk=attachments,filter_url_hook=absent
     *
     * @effects upload_stores_on_public_disk, proxy_url_otherwise
     */
    public function test_upload_without_public_asset_disk_uses_attachment_disk(): void
    {
        config(['core.storage.public_asset_disk' => '']);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/admin/templates/{$this->template->identifier}/layout-attachments", [
                'file' => UploadedFile::fake()->image('bg.png', 20, 20),
                'layout_name' => 'home',
            ]);

        $response->assertStatus(200);

        $attachment = TemplateLayoutAttachment::first();
        $this->assertSame(config('attachment.disk', 'attachments'), $attachment->disk);
        $this->assertStringContainsString('/layout-attachments/', (string) $response->json('data.url'));
        $this->assertStringContainsString('/file', (string) $response->json('data.url'));
    }

    /**
     * 공개 자산 디스크가 선언되면 그 디스크에 저장되고 직접 URL 이 발급돼야 합니다.
     *
     * @scenario public_asset_disk=public,row_disk=public,filter_url_hook=absent
     *
     * @effects upload_stores_on_public_disk, direct_url_when_row_matches_public_disk
     */
    public function test_upload_with_public_asset_disk_stores_there_and_returns_direct_url(): void
    {
        $this->registerFakeCdnDisk();
        config(['core.storage.public_asset_disk' => 'fake_cdn']);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/admin/templates/{$this->template->identifier}/layout-attachments", [
                'file' => UploadedFile::fake()->image('cdn.png', 20, 20),
                'layout_name' => 'home',
            ]);

        $response->assertStatus(200);

        $attachment = TemplateLayoutAttachment::first();
        $this->assertSame('fake_cdn', $attachment->disk);
        Storage::disk('fake_cdn')->assertExists('template-layout-attachments/'.$attachment->path);

        $this->assertStringStartsWith('https://cdn.test/assets', (string) $response->json('data.url'));
    }

    /**
     * 공개 자산 디스크를 켜도 그 이전에 올라간(다른 disk) 행은 프록시를 유지해야 합니다.
     *
     * @scenario public_asset_disk=public,row_disk=attachments,filter_url_hook=absent
     *
     * @effects proxy_url_otherwise
     */
    public function test_legacy_row_keeps_proxy_url_after_enabling_public_asset_disk(): void
    {
        $legacy = $this->makeStoredAttachment();

        $this->registerFakeCdnDisk();
        config(['core.storage.public_asset_disk' => 'fake_cdn']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-attachments");

        $response->assertStatus(200);
        $url = (string) $response->json('data.0.url');
        $this->assertStringContainsString("/layout-attachments/{$legacy->id}/file", $url);
        $this->assertStringNotContainsString('cdn.test', $url);
    }
}
