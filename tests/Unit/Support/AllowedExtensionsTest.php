<?php

namespace Tests\Unit\Support;

use App\Support\AllowedExtensions;
use PHPUnit\Framework\TestCase;

/**
 * 허용 MIME 목록에서 이름 확장자 허용 목록을 파생한다.
 *
 * 내용(MIME)만 검사하는 업로드 요청이 이름 확장자도 함께 좁힐 수 있게, 설정된 MIME 목록을
 * 그 형식의 알려진 확장자 전부로 바꾼다. 새 설정을 만들지 않는다.
 *
 * @scenario-manifest tests/scenarios/upload-stored-extension.yaml
 */
class AllowedExtensionsTest extends TestCase
{
    /**
     * MIME 마다 알려진 확장자를 모두 합친다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=single_upload__real_png__page_attachment
     *
     * @effects name_extension_allowlist_derived_from_mime_setting
     */
    public function test_derives_known_extensions_from_mime_types(): void
    {
        $extensions = AllowedExtensions::fromMimeTypes(['image/jpeg', 'application/pdf']);

        $this->assertContains('jpg', $extensions);
        $this->assertContains('jpeg', $extensions);
        $this->assertContains('pdf', $extensions);
        $this->assertNotContains('html', $extensions);
        $this->assertNotContains('png', $extensions);
    }

    /**
     * 와일드카드(`image/*`)는 그 대분류의 알려진 확장자 전부로 푼다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=single_upload__real_png__page_attachment
     *
     * @effects name_extension_allowlist_derived_from_mime_setting
     */
    public function test_wildcard_expands_to_every_known_extension_of_the_type(): void
    {
        $extensions = AllowedExtensions::fromMimeTypes(['image/*']);

        $this->assertContains('png', $extensions);
        $this->assertContains('gif', $extensions);
        $this->assertContains('webp', $extensions);
        $this->assertNotContains('docx', $extensions);
        $this->assertNotContains('html', $extensions);
        $this->assertNotContains('php', $extensions);
    }

    /**
     * 빈 목록·모르는 MIME·문자열이 아닌 값은 확장자를 만들지 않고, 결과는 중복 없는 소문자다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=single_upload__real_png__page_attachment
     *
     * @effects name_extension_allowlist_derived_from_mime_setting
     */
    public function test_unknown_or_empty_input_yields_nothing(): void
    {
        $this->assertSame([], AllowedExtensions::fromMimeTypes([]));
        $this->assertSame([], AllowedExtensions::fromMimeTypes(['application/x-g7-unknown', null, 12]));

        $extensions = AllowedExtensions::fromMimeTypes(['IMAGE/PNG', 'image/png']);
        $this->assertSame(array_values(array_unique($extensions)), $extensions);
        $this->assertContains('png', $extensions);
    }

    /**
     * 목록의 모든 형식에서 이름 확장자를 파생할 수 있을 때만 「전부 안다」 고 답한다.
     *
     * 확장자 표에 없는 형식이나 임의 이진 파일이 하나라도 섞이면 false — 호출측이 이름 검사를 생략한다.
     * 페이지 모듈의 기본 허용 형식은 전부 아는 형식이어야 한다(기본 설정에서 이름 검사가 꺼지지 않게).
     *
     * @return void 반환값 없음
     *
     * @scenario case=single_upload__real_png__page_attachment
     *
     * @effects name_extension_allowlist_derived_from_mime_setting
     */
    public function test_covers_every_mime_type_only_when_each_type_has_known_extensions(): void
    {
        $this->assertTrue(AllowedExtensions::coversEveryMimeType(['image/png', 'application/pdf']));
        $this->assertTrue(AllowedExtensions::coversEveryMimeType(['image/*', ' Application/PDF ']));

        $this->assertFalse(AllowedExtensions::coversEveryMimeType(['application/pdf', 'application/octet-stream']));
        $this->assertFalse(AllowedExtensions::coversEveryMimeType(['application/pdf', 'application/x-g7-unlisted']));
        $this->assertFalse(AllowedExtensions::coversEveryMimeType(['application/pdf', '*/*']));
        $this->assertFalse(AllowedExtensions::coversEveryMimeType([]));
        $this->assertFalse(AllowedExtensions::coversEveryMimeType(['', null, 12]));

        $defaults = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/modules/_bundled/sirsoft-page/config/settings/defaults.json'),
            true
        );
        $defaultTypes = $defaults['defaults']['attachment']['allowed_types'] ?? [];

        $this->assertNotSame([], $defaultTypes);
        $this->assertTrue(AllowedExtensions::coversEveryMimeType($defaultTypes));
    }
}
