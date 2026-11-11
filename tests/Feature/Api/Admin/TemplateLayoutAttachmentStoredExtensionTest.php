<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionStatus;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayoutAttachment;
use App\Models\User;
use App\Support\PublicAssetDisk;
use App\Support\TemplateLayoutAttachmentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 레이아웃 첨부 — 저장 확장자는 내용에서, 허용 형식은 이름과 내용 둘 다, 공개 디스크는 내보낼 확장자만.
 *
 * 올린 이름의 확장자가 저장 경로에 실리고 그 파일이 공개 디스크의 직접 주소로 나가면, 내용은 이미지이고
 * 이름만 `.html` 인 파일이 같은 출처에서 실행된다. 업로드 관문·저장 경로·디스크 선택 세 곳을 잠근다.
 *
 * @scenario-manifest tests/scenarios/upload-stored-extension.yaml
 */
class TemplateLayoutAttachmentStoredExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private User $adminUser;

    private string $adminToken;

    private Template $template;

    /**
     * 편집 권한을 가진 관리자와 사용자 템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('attachment.disk', 'attachments'));

        // 개발 사이트에 저장된 환경설정 값이 시험에 새지 않게 세 설정을 기본 상태로 고정한다.
        config([
            'attachment.layout_attachment_extensions' => TemplateLayoutAttachmentPolicy::DEFAULT_EXTENSIONS,
            'core.storage.public_asset_disk' => 'none',
            'core.storage.public_asset_extensions' => PublicAssetDisk::DEFAULT_EXPORTABLE_EXTENSIONS,
        ]);

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

        config(['core.storage.public_asset_disk' => '']);
    }

    /**
     * 시험이 건 필터를 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(TemplateLayoutAttachmentPolicy::FILTER_HOOK);

        parent::tearDown();
    }

    /**
     * 주어진 내용과 올린 이름으로 업로드 파일을 만듭니다.
     *
     * @param  string  $content  파일 내용
     * @param  string  $clientName  올린 파일 이름
     * @return UploadedFile 업로드 파일
     */
    private function fileWithContent(string $content, string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'g7la');
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /**
     * GIF 머리글 뒤에 스크립트를 붙인 내용(내용 판정은 GIF)을 반환합니다.
     *
     * @return string 파일 내용
     */
    private function gifWithScript(): string
    {
        return "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,"
            ."\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;<script>document.title='x'</script>";
    }

    /**
     * 최소 PDF 내용을 반환합니다.
     *
     * @return string 파일 내용
     */
    private function pdfContent(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * 레이아웃 첨부 업로드 API 를 호출합니다.
     *
     * @param  UploadedFile  $file  업로드 파일
     * @param  string|null  $identifier  템플릿 식별자 (없으면 기본 템플릿)
     * @return TestResponse 응답
     */
    private function upload(UploadedFile $file, ?string $identifier = null): TestResponse
    {
        $identifier ??= $this->template->identifier;

        return $this->withHeaders(['Authorization' => "Bearer {$this->adminToken}", 'Accept' => 'application/json'])
            ->postJson("/api/admin/templates/{$identifier}/layout-attachments", ['file' => $file]);
    }

    /**
     * 공개 자산 디스크로 쓸 가짜 CDN 디스크를 등록하고 선언합니다.
     *
     * @return void 반환값 없음
     */
    private function declareFakeCdnDisk(): void
    {
        config(['filesystems.disks.fake_cdn' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]]);
        Storage::fake('fake_cdn', ['url' => 'https://cdn.test/assets']);
        config(['core.storage.public_asset_disk' => 'fake_cdn']);
    }

    /**
     * 내용이 GIF 여도 이름이 `.html` 이면 거부하고 아무것도 저장하지 않는다(대소문자·이중 확장자 포함).
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__disguised_name
     *
     * @effects disguised_name_upload_returns_422
     */
    public function test_image_content_with_html_name_is_rejected(): void
    {
        foreach (['poc.html', 'POC.HTML', 'x.png.html'] as $name) {
            $response = $this->upload($this->fileWithContent($this->gifWithScript(), $name));

            $response->assertStatus(422)->assertJsonValidationErrors(['file']);
        }

        $this->assertDatabaseCount('template_layout_attachments', 0);
        $this->assertSame([], Storage::disk(config('attachment.disk', 'attachments'))->allFiles());
    }

    /**
     * 이름이 이미지여도 내용이 허용 형식이 아니면 거부한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__disguised_content
     *
     * @effects disguised_name_upload_returns_422
     */
    public function test_html_content_with_image_name_is_rejected(): void
    {
        $response = $this->upload($this->fileWithContent('<html><script>alert(1)</script></html>', 'bg.png'));

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertDatabaseCount('template_layout_attachments', 0);
    }

    /**
     * 정상 PNG 는 올라가고 저장 경로 확장자는 내용 기준이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__genuine
     *
     * @effects uploaded_name_extension_never_reaches_stored_path
     */
    public function test_genuine_png_is_stored_with_content_extension(): void
    {
        $response = $this->upload(UploadedFile::fake()->image('BG.PNG', 20, 20));

        $response->assertStatus(200);
        $this->assertStringEndsWith('.png', TemplateLayoutAttachment::first()->path);
    }

    /**
     * 이름은 `.png` 이고 내용이 GIF 면 통과하되 저장 확장자는 내용(gif)을 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__name_and_content_differ_within_allowlist
     *
     * @effects uploaded_name_extension_never_reaches_stored_path
     */
    public function test_allowed_name_with_other_allowed_content_is_stored_by_content(): void
    {
        $response = $this->upload($this->fileWithContent($this->gifWithScript(), 'x.html.png'));

        $response->assertStatus(200);

        $attachment = TemplateLayoutAttachment::first();
        $this->assertStringEndsWith('.gif', $attachment->path);
        $this->assertStringNotContainsString('html', $attachment->path);
        $this->assertSame('x.html.png', $attachment->original_name);
    }

    /**
     * 운영자가 허용 목록에 pdf 를 넣으면 pdf 가 올라간다(기본 목록에서는 거부).
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__allowlist_core_setting
     *
     * @effects operator_allowed_extension_upload_succeeds
     */
    public function test_operator_can_allow_another_extension(): void
    {
        $this->upload($this->fileWithContent($this->pdfContent(), 'guide.pdf'))->assertStatus(422);

        config(['attachment.layout_attachment_extensions' => ['png', 'pdf']]);

        $response = $this->upload($this->fileWithContent($this->pdfContent(), 'guide.pdf'));

        $response->assertStatus(200);
        $this->assertStringEndsWith('.pdf', TemplateLayoutAttachment::first()->path);
    }

    /**
     * 허용 확장자 필터는 요청 경로의 템플릿 식별자를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__allowlist_template_filter
     *
     * @effects layout_allowlist_priority_template_then_core_then_default
     */
    public function test_allowlist_filter_receives_the_template_identifier(): void
    {
        $seen = null;
        HookManager::addFilter(
            TemplateLayoutAttachmentPolicy::FILTER_HOOK,
            function (array $extensions, string $identifier) use (&$seen) {
                $seen = $identifier;

                return ['pdf'];
            }
        );

        $this->upload($this->fileWithContent($this->pdfContent(), 'guide.pdf'))->assertStatus(200);
        $this->assertSame('sirsoft-basic', $seen);

        // 템플릿 목록이 pdf 뿐이므로 기본 형식인 png 는 이 템플릿에서 거부된다.
        $this->upload(UploadedFile::fake()->image('bg.png', 20, 20))->assertStatus(422);
    }

    /**
     * 공개 디스크가 선언돼 있어도 내보낼 확장자 밖의 파일은 비공개 디스크에 저장되고 서버를 거치는 주소를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__not_exportable_with_public_disk
     *
     * @effects non_exportable_file_stays_on_private_disk_with_proxy_url
     */
    public function test_non_exportable_extension_stays_on_private_disk_with_proxy_url(): void
    {
        $this->declareFakeCdnDisk();
        config(['attachment.layout_attachment_extensions' => ['png', 'pdf']]);

        $response = $this->upload($this->fileWithContent($this->pdfContent(), 'guide.pdf'));

        $response->assertStatus(200);

        $attachment = TemplateLayoutAttachment::first();
        $this->assertSame(config('attachment.disk', 'attachments'), $attachment->disk);
        $this->assertSame([], Storage::disk('fake_cdn')->allFiles());
        $this->assertStringStartsWith('/api/templates/', (string) $response->json('data.url'));
        $this->assertStringEndsWith('/file', (string) $response->json('data.url'));
    }

    /**
     * 운영자가 내보낼 확장자에도 pdf 를 넣으면 공개 디스크에 저장되고 직접 주소를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__operator_exportable_with_public_disk
     *
     * @effects exportable_file_goes_to_public_disk_with_direct_url
     */
    public function test_operator_exportable_extension_goes_to_public_disk(): void
    {
        $this->declareFakeCdnDisk();
        config([
            'attachment.layout_attachment_extensions' => ['png', 'pdf'],
            'core.storage.public_asset_extensions' => ['png', 'pdf'],
        ]);

        $response = $this->upload($this->fileWithContent($this->pdfContent(), 'guide.pdf'));

        $response->assertStatus(200);
        $this->assertSame('fake_cdn', TemplateLayoutAttachment::first()->disk);
        $this->assertStringStartsWith('https://cdn.test/assets', (string) $response->json('data.url'));
        $this->assertStringEndsWith('.pdf', (string) $response->json('data.url'));
    }

    /**
     * 공개 디스크에 이미 놓인 목록 밖 확장자 행은 직접 주소 대신 서버를 거치는 주소를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__legacy_row_on_public_disk
     *
     * @effects non_exportable_file_stays_on_private_disk_with_proxy_url
     */
    public function test_existing_non_exportable_row_on_public_disk_gets_proxy_url(): void
    {
        $this->declareFakeCdnDisk();
        Storage::disk('fake_cdn')->put('template-layout-attachments/sirsoft-basic/legacy.html', '<script></script>');

        TemplateLayoutAttachment::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'disk' => 'fake_cdn',
            'path' => 'sirsoft-basic/legacy.html',
            'original_name' => 'legacy.html',
            'mime_type' => 'image/gif',
            'size' => 17,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->adminToken}", 'Accept' => 'application/json'])
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-attachments");

        $response->assertStatus(200);

        $urls = collect($response->json('data'))->pluck('url')->all();
        $this->assertCount(1, $urls);
        $this->assertStringStartsWith('/api/templates/', (string) $urls[0]);
    }

    /**
     * 원본 이름이 컬럼 길이(255자)를 넘어도 업로드는 성공하고, 표시용 이름만 255자로 줄여 기록한다.
     *
     * 줄이지 않으면 파일을 저장한 뒤 행 기록에서 실패해 500 과 함께 주인 없는 파일이 디스크에 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=layout_attachment__overlong_original_name
     *
     * @effects overlong_original_name_is_truncated_without_orphan_file
     */
    public function test_overlong_original_name_is_truncated_instead_of_failing(): void
    {
        $name = str_repeat('가', 300).'.png';
        $source = UploadedFile::fake()->image('bg.png', 20, 20);

        $response = $this->upload($this->fileWithContent((string) file_get_contents($source->getRealPath()), $name));

        $response->assertStatus(200);

        $row = TemplateLayoutAttachment::query()->sole();
        $this->assertSame(255, mb_strlen($row->original_name));
        $this->assertStringEndsWith('.png', $row->path);
        $this->assertCount(1, Storage::disk(config('attachment.disk', 'attachments'))->allFiles());
    }
}
