<?php

namespace Tests\Feature\Attachment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 파비콘 컬렉션 업로드 정책 테스트 (#434)
 *
 * 파비콘 컬렉션에서만 ico·svg 포함 7형식을 받고(전역 허용 목록과 무관), svg 내용·ico 매직바이트·1MB 를 검사한다.
 * 다른 컬렉션의 svg 차단은 그대로다.
 */
class FaviconUploadValidationTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    private const SAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><rect width="16" height="16" fill="#3b5bdb"/></svg>';

    /**
     * 내용으로 MIME 을 판정하는 실제 업로드 파일을 만듭니다(가짜 파일은 이름으로 MIME 을 보고해 내용 검사를 우회한다).
     *
     * @param  string  $name  원래 파일명
     * @param  string  $content  내용
     * @return UploadedFile 업로드 파일
     */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qa434');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * 업로드 요청을 보냅니다.
     *
     * @param  UploadedFile  $file  파일
     * @param  string  $collection  컬렉션
     * @return \Illuminate\Testing\TestResponse 응답
     */
    private function upload(UploadedFile $file, string $collection)
    {
        return $this->actingAs($this->makeAdmin(['core.settings.update', 'core.attachments.create']))
            ->postJson('/api/admin/attachments', ['file' => $file, 'collection' => $collection]);
    }

    /**
     * 파비콘 컬렉션은 ico·svg·png 를 받는다(전역 허용 목록에 ico·svg 가 없어도).
     *
     * @scenario surface=api
     *
     * @effects favicon_upload_policy_scoped_to_collection
     *
     * @return void 반환값 없음
     */
    public function test_favicon_collections_accept_favicon_formats(): void
    {
        config(['attachment.allowed_extensions' => ['jpg', 'png', 'pdf']]);

        $this->upload($this->realFile('icon.svg', self::SAFE_SVG), 'site_favicon')->assertCreated();
        $this->upload($this->realFile('icon.ico', file_get_contents(public_path('assets/core/favicon/favicon.ico'))), 'template_settings.qa434-x.branding.favicon')->assertCreated();
        $this->upload(UploadedFile::fake()->image('icon.png', 32, 32), 'site_favicon')->assertCreated();
        $this->upload(UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'), 'site_favicon')->assertStatus(422);
    }

    /**
     * 파비콘이 아닌 컬렉션의 svg 차단은 그대로다.
     *
     * @scenario surface=api
     *
     * @effects favicon_upload_policy_scoped_to_collection
     *
     * @return void 반환값 없음
     */
    public function test_other_collections_still_reject_svg(): void
    {
        config(['attachment.allowed_extensions' => ['jpg', 'png']]);

        $this->upload($this->realFile('icon.svg', self::SAFE_SVG), 'default')->assertStatus(422);
        $this->upload($this->realFile('icon.svg', self::SAFE_SVG), 'template_settings.qa434-x.banner')->assertStatus(422);
    }

    /**
     * 위험한 svg·가짜 ico·이름만 바꾼 파일·1MB 초과는 422.
     *
     * @scenario surface=api
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unsafe_files_are_rejected(): void
    {
        $cases = [
            'script' => $this->realFile('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'onload' => $this->realFile('b.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
            'external' => $this->realFile('c.svg', '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image href="https://evil.example/x.png"/></svg>'),
            'foreignobject' => $this->realFile('d.svg', '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div/></foreignObject></svg>'),
            'fake_ico' => $this->realFile('e.ico', 'not an icon at all'),
            'renamed' => $this->realFile('f.png', 'plain text pretending'),
            'too_big' => $this->realFile('g.png', "\x89PNG\r\n\x1a\n".str_repeat('a', 1100 * 1024)),
        ];

        foreach ($cases as $name => $file) {
            $this->assertSame(422, $this->upload($file, 'site_favicon')->status(), "{$name} 가 거부되어야 한다");
        }
    }

    /**
     * 정사각 검사는 확장 아이콘 규칙이라 파비콘 svg 에는 쓰지 않고, 거부 사유는 검사기 코드가 아닌 번역 문구로 알린다.
     *
     * 브라우저 실측(2026-11-04)에서 크기 표기가 없는 안전한 svg 가 `not_square` 로 거부되고, 화면 토스트에
     * `svg_script_element, not_square` 같은 코드가 그대로 보였다.
     *
     * @scenario surface=api
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_svg_square_check_is_skipped_and_reasons_are_translated(): void
    {
        $this->upload($this->realFile('wide.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 16"><rect width="32" height="16"/></svg>'), 'site_favicon')->assertCreated();
        $this->upload($this->realFile('nosize.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="8" r="8"/></svg>'), 'site_favicon')->assertCreated();

        $message = (string) $this->upload($this->realFile('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'site_favicon')
            ->assertStatus(422)
            ->json('errors.file.0');

        $this->assertStringContainsString(__('extensions.icon.warnings.svg_script_element'), $message);
        $this->assertStringNotContainsString('svg_script_element', $message);
        $this->assertStringNotContainsString(__('extensions.icon.warnings.not_square'), $message);
    }

    /**
     * 파비콘 컬렉션은 gif·jpg·jpeg·webp 도 받는다 — 전역 허용 목록에 없어도(목록을 파비콘 7형식으로 교체).
     *
     * @scenario surface=api
     *
     * @effects favicon_upload_policy_scoped_to_collection
     *
     * @return void 반환값 없음
     */
    public function test_favicon_collections_accept_raster_formats(): void
    {
        config(['attachment.allowed_extensions' => ['png', 'pdf']]);

        foreach (['icon.gif', 'icon.jpg', 'icon.jpeg', 'icon.webp'] as $name) {
            $this->assertSame(201, $this->upload(UploadedFile::fake()->image($name, 32, 32), 'site_favicon')->status(), $name.' 가 파비콘 컬렉션에 올라가야 한다');
        }

        $this->upload(UploadedFile::fake()->image('icon.webp', 32, 32), 'template_settings.qa434-x.branding.favicon')->assertCreated();
    }

    /**
     * 파비콘이 아닌 `default` 컬렉션은 ico 를 받지 않는다(파비콘 형식 교체는 파비콘 컬렉션에만).
     *
     * @scenario surface=api
     *
     * @effects favicon_upload_policy_scoped_to_collection
     *
     * @return void 반환값 없음
     */
    public function test_default_collection_rejects_ico(): void
    {
        config(['attachment.allowed_extensions' => ['jpg', 'png']]);
        $ico = file_get_contents(public_path('assets/core/favicon/favicon.ico'));

        $this->upload($this->realFile('icon.ico', $ico), 'default')->assertStatus(422);
        $this->upload($this->realFile('icon.ico', $ico), 'site_favicon')->assertCreated();
    }
}
