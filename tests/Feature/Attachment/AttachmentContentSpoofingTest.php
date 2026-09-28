<?php

namespace Tests\Feature\Attachment;

use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Enums\PermissionType;
use App\Models\Attachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayoutAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 첨부 확장자 스푸핑 → SVG 인라인 저장형 XSS 회귀 테스트 (KVE-2026-2366)
 *
 * 수정 전: 업로드 검증은 파일명 확장자(`extensions:`)만 보고, 저장은 내용 MIME(image/svg+xml),
 * 공개 서빙은 `image/` 접두사로 인라인 판정 — `evil.png`(내용=SVG)가 201 로 저장되고
 * 미인증 GET 이 `Content-Disposition: inline` 으로 스크립트를 앱 오리진에서 실행시켰다.
 *
 * 주의: `UploadedFile::fake()->create()` 는 선언 MIME 을 그대로 보고하므로 내용 판정을 시험할 수
 * 없다. 실제 바이트를 쓴 임시 파일을 `test: true` UploadedFile 로 감싸 finfo 가 내용을 읽게 한다.
 */
class AttachmentContentSpoofingTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 스크립트가 든 SVG 내용
     */
    private const SVG_PAYLOAD = '<svg xmlns="http://www.w3.org/2000/svg" onload="document.title=1"><script>document.title=2</script></svg>';

    /**
     * 내용이 일반 텍스트(txt)·PostScript(ai)로 추론되는 텍스트 계열 파일
     *
     * @var array<string, string>
     */
    private const TEXT_FAMILY_FILES = [
        'readme.md' => "# 제목\n\n본문입니다.\n",
        'server.log' => "2026-09-28 00:00:01 INFO started\n",
        'one-col.csv' => "name\na\nb\n",
        'fig.eps' => "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 10 10\n",
    ];

    private User $user;

    /**
     * @var array<int, string> 테스트가 만든 임시 파일 경로
     */
    private array $tempFiles = [];

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        config(['attachment.allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip']]);
        $this->user = $this->createUploaderAdmin();
    }

    /**
     * 임시 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * 파일명과 무관하게 SVG 내용은 422 로 거부된다 — 정직한 .svg 와 .png 로 위장한 SVG 모두.
     *
     * @scenario entry_point=single_upload, content=svg_as_png, surface=core_attachment
     *
     * @effects spoofed_svg_upload_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_single_upload_rejects_svg_content_regardless_of_filename(): void
    {
        foreach (['evil.svg', 'evil.png'] as $name) {
            $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
                'file' => $this->realFile($name, self::SVG_PAYLOAD),
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('file');
        }

        $this->assertSame(0, Attachment::where('mime_type', 'image/svg+xml')->where('created_by', $this->user->id)->count());
    }

    /**
     * 위장 SVG 거부 메시지는 내용 불일치 안내(file_mimetype_invalid)다.
     *
     * @scenario entry_point=single_upload, content=svg_as_png, surface=core_attachment
     *
     * @effects spoofed_svg_upload_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_spoofed_svg_rejection_uses_mimetype_message(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
            'file' => $this->realFile('evil.png', self::SVG_PAYLOAD),
        ]);

        $response->assertStatus(422);
        $expected = __('attachment.validation.file_mimetype_invalid', ['extensions' => implode(', ', config('attachment.allowed_extensions'))]);
        $this->assertContains($expected, $response->json('errors.file'));
    }

    /**
     * 진짜 래스터 이미지와 PDF 는 그대로 업로드된다 (과차단 회귀 가드).
     *
     * @scenario entry_point=single_upload, content=real_png, surface=core_attachment
     *
     * @effects genuine_raster_and_document_upload_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_single_upload_accepts_genuine_png_and_pdf(): void
    {
        $png = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
            'file' => $this->realFile('real.png', $this->pngBytes()),
        ]);
        $png->assertStatus(201);
        $this->assertSame('image/png', $png->json('data.mime_type'));

        $pdf = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
            'file' => $this->realFile('doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
        ]);
        $pdf->assertStatus(201);
    }

    /**
     * 일괄 업로드도 같은 내용 검증을 적용한다 — 정직한 .svg 와 .png 로 위장한 SVG 모두.
     *
     * @scenario entry_point=batch_upload, content=svg_as_png, surface=core_attachment
     *
     * @effects batch_upload_applies_same_content_check
     *
     * @return void 반환값 없음
     */
    public function test_batch_upload_rejects_spoofed_svg(): void
    {
        foreach (['evil.svg', 'evil.png'] as $name) {
            $rejected = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments/batch', [
                'files' => [$this->realFile($name, self::SVG_PAYLOAD)],
            ]);

            $rejected->assertStatus(422);
            $rejected->assertJsonValidationErrors('files.0');
        }
    }

    /**
     * 일괄 업로드도 진짜 래스터 이미지와 PDF 는 그대로 통과한다 (과차단 회귀 가드).
     *
     * @scenario entry_point=batch_upload, content=real_png, surface=core_attachment
     *
     * @effects batch_upload_applies_same_content_check
     *
     * @return void 반환값 없음
     */
    public function test_batch_upload_accepts_genuine_png(): void
    {
        $accepted = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments/batch', [
            'files' => [$this->realFile('real.png', $this->pngBytes())],
        ]);

        $accepted->assertStatus(201);

        $pdf = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments/batch', [
            'files' => [$this->realFile('doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")],
        ]);
        $pdf->assertStatus(201);
    }

    /**
     * 관리자가 허용한 텍스트 계열 형식은 내용이 일반 텍스트(txt)·PostScript(ai)로 추론돼도 통과한다.
     *
     * 수정 전 `mimes:` 만으로는 md·log·쉼표 없는 csv 가 txt 로, eps 가 ai 로 추론되어 txt·ai 를 함께
     * 허용하지 않은 사이트에서 거부됐다.
     *
     * @scenario entry_point=single_upload, content=text_family, surface=core_attachment
     *
     * @effects text_family_upload_succeeds_when_allowed
     *
     * @return void 반환값 없음
     */
    public function test_text_family_files_pass_when_their_extension_is_allowed(): void
    {
        config(['attachment.allowed_extensions' => ['jpg', 'png', 'pdf', 'zip', 'md', 'log', 'csv', 'eps']]);

        foreach (self::TEXT_FAMILY_FILES as $name => $content) {
            $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
                'file' => $this->realFile($name, $content),
            ]);

            $this->assertSame(201, $response->status(), "{$name} 은 허용 형식인데 거부됐습니다: ".$response->getContent());
        }
    }

    /**
     * 일괄 업로드도 허용된 텍스트 계열 형식을 한 번에 통과시킨다.
     *
     * @scenario entry_point=batch_upload, content=text_family, surface=core_attachment
     *
     * @effects text_family_upload_succeeds_when_allowed
     *
     * @return void 반환값 없음
     */
    public function test_batch_text_family_files_pass_when_their_extension_is_allowed(): void
    {
        config(['attachment.allowed_extensions' => ['jpg', 'png', 'pdf', 'zip', 'md', 'log', 'csv', 'eps']]);

        $files = [];
        foreach (self::TEXT_FAMILY_FILES as $name => $content) {
            $files[] = $this->realFile($name, $content);
        }

        $batch = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments/batch', ['files' => $files]);

        $this->assertSame(201, $batch->status(), '일괄 업로드도 텍스트 계열을 통과시켜야 합니다: '.$batch->getContent());
    }

    /**
     * 텍스트 대응은 이름이 텍스트 계열일 때만 txt 추론을 인정한다 — SVG·HTML 내용과 텍스트를 담은
     * 이미지 이름은 여전히 거부된다.
     *
     * @scenario entry_point=single_upload, content=text_family, surface=core_attachment
     *
     * @effects text_alias_never_admits_svg_or_html
     *
     * @return void 반환값 없음
     */
    public function test_text_alias_does_not_admit_svg_html_or_text_named_as_image(): void
    {
        config(['attachment.allowed_extensions' => ['jpg', 'png', 'pdf', 'md', 'csv']]);

        $rejected = [
            'evil.md' => self::SVG_PAYLOAD,
            'page.csv' => "<!DOCTYPE html>\n<html><body><script>document.title=1</script></body></html>\n",
            'note.png' => "plain text\n",
        ];

        foreach ($rejected as $name => $content) {
            $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
                'file' => $this->realFile($name, $content),
            ]);

            $this->assertSame(422, $response->status(), "{$name} 은 거부돼야 합니다: ".$response->getContent());
            $response->assertJsonValidationErrors('file');
        }
    }

    /**
     * 0바이트 파일은 형식 불일치가 아니라 빈 파일 안내로 거부된다.
     *
     * @scenario entry_point=single_upload, content=empty, surface=core_attachment
     *
     * @effects empty_file_rejected_with_empty_message
     *
     * @return void 반환값 없음
     */
    public function test_empty_file_is_rejected_with_empty_file_message(): void
    {
        config(['attachment.allowed_extensions' => ['jpg', 'png', 'pdf', 'txt']]);

        $single = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments', [
            'file' => $this->realFile('empty.txt', ''),
        ]);
        $single->assertStatus(422);
        $this->assertSame([__('attachment.validation.file_empty')], $single->json('errors.file'));
    }

    /**
     * 일괄 업로드도 0바이트 파일을 빈 파일 안내로 거부한다.
     *
     * @scenario entry_point=batch_upload, content=empty, surface=core_attachment
     *
     * @effects empty_file_rejected_with_empty_message
     *
     * @return void 반환값 없음
     */
    public function test_batch_empty_file_is_rejected_with_empty_file_message(): void
    {
        config(['attachment.allowed_extensions' => ['jpg', 'png', 'pdf', 'txt']]);
        $expected = __('attachment.validation.file_empty');

        $batch = $this->actingAs($this->user, 'sanctum')->postJson('/api/admin/attachments/batch', [
            'files' => [$this->realFile('empty.txt', '')],
        ]);
        $batch->assertStatus(422);
        $this->assertSame([$expected], $batch->json('errors')['files.0']);
    }

    /**
     * 이미 저장된 SVG 첨부는 미인증 요청에도 다운로드로 강제되고 nosniff·CSP 가 붙는다.
     *
     * 코드 수정 전 저장분(기존 SVG 행)도 서빙 계층에서 무해화되어야 한다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=core_attachment
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp
     *
     * @return void 반환값 없음
     */
    public function test_stored_svg_is_served_as_download_to_guest(): void
    {
        $attachment = $this->storedAttachment('evil.png', 'image/svg+xml', self::SVG_PAYLOAD);

        $response = $this->get('/api/attachment/'.$attachment->hash);

        $response->assertStatus(200);
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    /**
     * 래스터 이미지는 종전처럼 인라인으로 서빙되고 nosniff 가 붙는다 (게시판·상품 이미지 회귀 가드).
     *
     * @scenario entry_point=serving, content=real_png, surface=core_attachment
     *
     * @effects raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_raster_image_is_still_served_inline(): void
    {
        $attachment = $this->storedAttachment('real.png', 'image/png', $this->pngBytes());

        $response = $this->get('/api/attachment/'.$attachment->hash);

        $response->assertStatus(200);
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * SVG 첨부는 API 응답에서 이미지로 표시되지 않는다 (is_image=false).
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=core_attachment
     *
     * @effects svg_is_not_treated_as_inline_image
     *
     * @return void 반환값 없음
     */
    public function test_svg_attachment_is_not_flagged_as_image(): void
    {
        $svg = $this->storedAttachment('evil.png', 'image/svg+xml', self::SVG_PAYLOAD);
        $png = $this->storedAttachment('real.png', 'image/png', $this->pngBytes());

        $this->assertFalse($svg->is_image);
        $this->assertTrue($png->is_image);
    }

    /**
     * 템플릿 레이아웃 첨부 공개 서빙도 SVG 는 다운로드+nosniff+CSP, 래스터는 인라인+nosniff 다.
     *
     * 수정 전에는 이 라우트가 형식과 무관하게 모든 파일을 인라인으로 내보냈다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=core_attachment
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp, raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_layout_attachment_serving_applies_inline_policy(): void
    {
        $disk = config('attachment.disk', 'attachments');
        Storage::fake($disk);
        $template = Template::create([
            'identifier' => 'kve-layout-serve',
            'vendor' => 'kve',
            'name' => ['ko' => '검수', 'en' => 'Inspect'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '검수', 'en' => 'Inspect'],
        ]);

        $svg = $this->storedLayoutAttachment($template, $disk, 'evil.png', 'image/svg+xml', self::SVG_PAYLOAD);
        $svgResponse = $this->get("/api/templates/{$template->identifier}/layout-attachments/{$svg->id}/file");

        $svgResponse->assertStatus(200);
        $this->assertStringStartsWith('attachment', (string) $svgResponse->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $svgResponse->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $svgResponse->headers->get('Content-Security-Policy'));

        $png = $this->storedLayoutAttachment($template, $disk, 'real.png', 'image/png', $this->pngBytes());
        $pngResponse = $this->get("/api/templates/{$template->identifier}/layout-attachments/{$png->id}/file");

        $pngResponse->assertStatus(200);
        $this->assertStringNotContainsString('attachment', (string) $pngResponse->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $pngResponse->headers->get('X-Content-Type-Options'));
        $this->assertNull($pngResponse->headers->get('Content-Security-Policy'));
    }

    /**
     * 스토리지에 파일을 두고 템플릿 레이아웃 첨부 행을 만듭니다.
     *
     * @param  Template  $template  소속 템플릿
     * @param  string  $disk  저장 디스크
     * @param  string  $name  원본 파일명
     * @param  string  $mime  저장된 MIME
     * @param  string  $content  파일 내용
     * @return TemplateLayoutAttachment 레이아웃 첨부 모델
     */
    private function storedLayoutAttachment(Template $template, string $disk, string $name, string $mime, string $content): TemplateLayoutAttachment
    {
        $path = $template->identifier.'/kve2366/'.Str::random(8).'-'.$name;
        Storage::disk($disk)->put('template-layout-attachments/'.$path, $content);

        return TemplateLayoutAttachment::create([
            'template_id' => $template->id,
            'layout_name' => 'home',
            'disk' => $disk,
            'path' => $path,
            'original_name' => $name,
            'mime_type' => $mime,
            'size' => strlen($content),
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * 실제 바이트를 담은 업로드 파일을 만듭니다 (finfo 가 내용을 읽는다).
     *
     * @param  string  $name  클라이언트 파일명
     * @param  string  $content  파일 내용
     * @return UploadedFile 업로드 파일
     */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kve2366');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * 1x1 PNG 바이트를 반환합니다.
     *
     * @return string PNG 바이트
     */
    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    /**
     * 스토리지에 파일을 두고 첨부 행을 만듭니다 (수정 전 저장분 재현).
     *
     * @param  string  $name  원본 파일명
     * @param  string  $mime  저장된 MIME
     * @param  string  $content  파일 내용
     * @return Attachment 첨부 모델
     */
    private function storedAttachment(string $name, string $mime, string $content): Attachment
    {
        $path = 'kve2366/'.Str::random(8).'-'.$name;
        Storage::disk('attachments')->put($path, $content);

        return Attachment::factory()->create([
            'disk' => 'attachments',
            'path' => $path,
            'original_filename' => $name,
            'mime_type' => $mime,
            'size' => strlen($content),
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * 첨부 업로드 권한을 가진 관리자를 만듭니다 (공용 행은 firstOrCreate — 트랜잭션 격리).
     *
     * @return User 관리자
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
