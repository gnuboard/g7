<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../FeatureTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use App\Support\Files\StoredFileExtension;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 페이지 첨부 — 이름 확장자도 허용 형식 안이어야 하고, 저장 확장자는 파일 내용에서 정한다.
 *
 * 허용 형식 설정은 MIME 목록 하나뿐이다. 내용(MIME)만 검사하면 내용은 PNG 이고 이름만 `.html` 인 파일이
 * 통과하고, 저장 경로가 올린 이름의 확장자를 그대로 쓰면 그 확장자로 저장된다. 이름 확장자 허용 목록은
 * 같은 MIME 설정에서 파생한다(새 설정 없음).
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-attachment-stored-extension.yaml
 */
class PageAttachmentStoredExtensionTest extends FeatureTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-page/admin/attachments';

    protected User $adminUser;

    /**
     * 관리자와 저장소 대역을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.create',
            'sirsoft-page.pages.update',
            'sirsoft-page.pages.delete',
        ]);

        $storageMock = Mockery::mock(StorageInterface::class);
        $storageMock->shouldReceive('put')->andReturn(true);
        $storageMock->shouldReceive('exists')->andReturn(true);
        $storageMock->shouldReceive('delete')->andReturn(true);
        $storageMock->shouldReceive('deleteDirectory')->andReturn(true);
        $storageMock->shouldReceive('getDisk')->andReturn('local');
        $storageMock->shouldReceive('url')->andReturn(null);
        $storageMock->shouldReceive('response')->andReturn(null);
        $this->app->instance(StorageInterface::class, $storageMock);

        config(['g7_settings.modules.sirsoft-page.attachment' => [
            'max_count' => 5,
            'max_size_mb' => 10,
            'allowed_types' => ['image/png', 'application/pdf', 'text/plain'],
        ]]);
    }

    /**
     * 시험이 만든 페이지를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'stored-ext-%')->forceDelete();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 시험용 페이지를 만듭니다.
     *
     * @param  string  $slug  페이지 슬러그
     * @return Page 생성된 페이지
     */
    private function makePage(string $slug): Page
    {
        return Page::factory()->create([
            'slug' => $slug,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);
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
        $path = tempnam(sys_get_temp_dir(), 'g7pa');
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /**
     * 실제 PNG 내용을 반환합니다.
     *
     * @return string 파일 내용
     */
    private function pngContent(): string
    {
        // 가짜 이미지의 임시 파일은 객체가 사라지면 지워진다 — 읽는 동안 붙잡아 둔다.
        $png = UploadedFile::fake()->image('real.png', 8, 8);

        return (string) file_get_contents($png->getRealPath());
    }

    /**
     * 내용이 PNG 여도 이름이 `.html` 이면 거부한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=disguised_name
     *
     * @effects disguised_name_upload_returns_422
     */
    public function test_png_content_with_html_name_is_rejected(): void
    {
        $page = $this->makePage('stored-ext-disguised');

        $response = $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
            'file' => $this->fileWithContent($this->pngContent(), 'poc.html'),
            'page_id' => $page->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertSame(0, PageAttachment::where('page_id', $page->id)->count());
    }

    /**
     * 이름과 내용이 허용 형식 안에서 서로 다르면 통과하되 저장 확장자는 내용을 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=name_and_content_differ_within_allowlist
     *
     * @effects uploaded_name_extension_never_reaches_stored_path
     */
    public function test_stored_extension_follows_content_not_the_uploaded_name(): void
    {
        $page = $this->makePage('stored-ext-content');

        $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
            'file' => $this->fileWithContent($this->pngContent(), 'manual.pdf'),
            'page_id' => $page->id,
        ])->assertStatus(201);

        $attachment = PageAttachment::where('page_id', $page->id)->firstOrFail();
        $this->assertStringEndsWith('.png', $attachment->stored_filename);
        $this->assertStringEndsWith('.png', $attachment->path);
        $this->assertSame('manual.pdf', $attachment->original_filename);
    }

    /**
     * 정상 파일은 그대로 올라가고(과잉 차단 없음), 이름 확장자 허용 목록은 MIME 설정에서 파생된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=genuine
     *
     * @effects name_extension_allowlist_derived_from_mime_setting
     */
    public function test_genuine_files_pass_and_name_allowlist_comes_from_mime_setting(): void
    {
        $page = $this->makePage('stored-ext-genuine');

        $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
            'file' => $this->fileWithContent($this->pngContent(), 'PHOTO.PNG'),
            'page_id' => $page->id,
        ])->assertStatus(201);

        // 설정에서 png 를 빼면 같은 파일이 이름·내용 모두에서 거부된다.
        config(['g7_settings.modules.sirsoft-page.attachment.allowed_types' => ['application/pdf']]);

        $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
            'file' => $this->fileWithContent($this->pngContent(), 'photo.png'),
            'page_id' => $page->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->assertSame(1, PageAttachment::where('page_id', $page->id)->count());
    }

    /**
     * 허용 형식에 이름 확장자를 알 수 없는 형식이 섞여 있으면 이름 검사를 하지 않는다(내용 검사만).
     *
     * 운영자가 「임의 이진 파일」 이나 확장자 표에 없는 형식을 허용해 둔 설정에서는 그 형식의 이름 확장자를
     * 파생할 수 없다. 이름 검사를 켜면 종전에 올라가던 파일이 거부되므로, 그런 설정에서는 내용 검사만 한다.
     * 저장 파일명은 여전히 내용 기준이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=genuine
     *
     * @effects name_extension_allowlist_derived_from_mime_setting
     */
    public function test_name_check_is_skipped_when_an_allowed_type_has_no_known_extension(): void
    {
        $page = $this->makePage('stored-ext-unknown-type');
        $binary = "\x00\x01\x02\x03\xFF\xFE\x00\x10".str_repeat("\x00\xA5", 64);

        config(['g7_settings.modules.sirsoft-page.attachment.allowed_types' => ['application/pdf', 'application/octet-stream']]);

        $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
            'file' => $this->fileWithContent($binary, 'document.hwp'),
            'page_id' => $page->id,
        ])->assertStatus(201);

        $attachment = PageAttachment::where('page_id', $page->id)->latest('id')->firstOrFail();
        $this->assertSame('document.hwp', $attachment->original_filename);
        $this->assertStringEndsNotWith('.hwp', $attachment->path);

        // 확장자 표에 없는 형식이 섞인 설정도 같다 — 허용된 pdf 는 이름이 달라도 내용으로 받는다.
        config(['g7_settings.modules.sirsoft-page.attachment.allowed_types' => ['application/pdf', 'application/x-g7-unlisted']]);

        $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
            'file' => $this->fileWithContent("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n", 'scan.data'),
            'page_id' => $page->id,
        ])->assertStatus(201);

        // 내용이 허용 형식이 아니면 그대로 거부한다.
        $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
            'file' => $this->fileWithContent($this->pngContent(), 'photo.png'),
            'page_id' => $page->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    /**
     * 텍스트 계열은 내용 추론이 txt 라 `.txt` 로 저장되고, 내려받는 이름은 원본 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=text_family
     *
     * @effects text_family_stored_as_txt_with_original_download_name
     */
    public function test_text_file_is_stored_as_txt_and_keeps_original_name(): void
    {
        $page = $this->makePage('stored-ext-text');

        $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
            'file' => $this->fileWithContent("name,price\napple,100\n", 'list.txt'),
            'page_id' => $page->id,
        ])->assertStatus(201);

        $attachment = PageAttachment::where('page_id', $page->id)->firstOrFail();
        $this->assertStringEndsWith('.txt', $attachment->stored_filename);
        $this->assertSame('list.txt', $attachment->original_filename);
    }

    /**
     * 이름이 csv·md 인 텍스트 파일은 올라가되 저장 확장자는 이름이 아니라 내용 추론을 따르고, 내려받는 이름은 원본 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=text_family_other_names
     *
     * @effects text_family_stored_as_txt_with_original_download_name
     */
    public function test_csv_and_markdown_names_are_stored_by_content_and_keep_original_name(): void
    {
        config(['g7_settings.modules.sirsoft-page.attachment.allowed_types' => ['text/plain', 'text/csv', 'text/markdown']]);
        $page = $this->makePage('stored-ext-text-names');

        $uploads = [
            'notes.md' => "# 제목\n\n본문 한 줄.\n",
            'list.csv' => "name,price\napple,100\n",
        ];

        foreach ($uploads as $clientName => $content) {
            $file = $this->fileWithContent($content, $clientName);
            $expected = StoredFileExtension::resolve($file);

            $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
                'file' => $file,
                'page_id' => $page->id,
            ])->assertStatus(201);

            $attachment = PageAttachment::where('page_id', $page->id)
                ->where('original_filename', $clientName)
                ->firstOrFail();

            // 저장 확장자는 내용 추론 결과다 — 올린 이름의 확장자를 그대로 쓰지 않는다.
            $this->assertStringEndsWith('.'.$expected, $attachment->stored_filename, $clientName);
            $this->assertSame($clientName, $attachment->original_filename);
        }

        // 마크다운은 내용만으로 구분되지 않아 일반 텍스트(txt)로 저장된다.
        $markdown = PageAttachment::where('page_id', $page->id)->where('original_filename', 'notes.md')->firstOrFail();
        $this->assertStringEndsWith('.txt', $markdown->stored_filename);
    }

    /**
     * 허용 형식이 비어 있으면(제한 없음) 이름 검사도 걸지 않되, 저장 확장자는 여전히 내용을 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=allowlist_empty
     *
     * @effects uploaded_name_extension_never_reaches_stored_path
     */
    public function test_empty_allowlist_skips_name_check_but_still_stores_by_content(): void
    {
        config(['g7_settings.modules.sirsoft-page.attachment.allowed_types' => []]);
        $page = $this->makePage('stored-ext-open');

        $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
            'file' => $this->fileWithContent($this->pngContent(), 'poc.html'),
            'page_id' => $page->id,
        ])->assertStatus(201);

        $attachment = PageAttachment::where('page_id', $page->id)->firstOrFail();
        $this->assertStringEndsWith('.png', $attachment->stored_filename);
        $this->assertStringNotContainsString('html', $attachment->path);
    }
}
