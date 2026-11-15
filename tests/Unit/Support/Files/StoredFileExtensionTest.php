<?php

namespace Tests\Unit\Support\Files;

use App\Support\Files\StoredFileExtension;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 저장 파일명 확장자는 올린 이름이 아니라 파일 내용에서 정한다.
 *
 * 올린 이름의 확장자가 저장 경로에 실리면, 그 디스크를 직접 내보내는 웹서버가 확장자로 처리해
 * 같은 출처에서 실행되는 파일이 된다. 저장 지점이 함께 쓰는 판정 한 곳을 잠근다.
 *
 * @scenario-manifest tests/scenarios/upload-stored-extension.yaml
 */
class StoredFileExtensionTest extends TestCase
{
    /**
     * 내용은 PNG 인데 이름만 다른 업로드를 만듭니다.
     *
     * @param  string  $clientName  올린 파일 이름
     * @return UploadedFile 업로드 파일
     */
    private function disguisedPng(string $clientName): UploadedFile
    {
        $png = UploadedFile::fake()->image('real.png', 8, 8);
        $path = tempnam(sys_get_temp_dir(), 'g7sx');
        copy($png->getRealPath(), $path);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /**
     * 내용이 PNG 면 이름이 `evil.html` 이어도 저장 확장자는 png 다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=single_upload__real_png__layout_attachment
     *
     * @effects uploaded_name_extension_never_reaches_stored_path
     */
    public function test_content_decides_the_extension_not_the_uploaded_name(): void
    {
        $this->assertSame('png', StoredFileExtension::resolve($this->disguisedPng('evil.html')));
        $this->assertSame('png', StoredFileExtension::resolve($this->disguisedPng('EVIL.PHP')));
    }

    /**
     * 내용에서 확장자를 정하지 못하면 넘겨받은 기본값을 쓴다(이름 확장자로 물러나지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=single_upload__real_png__layout_attachment
     *
     * @effects uploaded_name_extension_never_reaches_stored_path
     */
    public function test_falls_back_to_the_given_default_when_content_is_unknown(): void
    {
        $file = new class(tempnam(sys_get_temp_dir(), 'g7sx'), 'evil.html', null, null, true) extends UploadedFile
        {
            /**
             * 내용 추론 결과를 시험이 정한 값으로 고정합니다.
             *
             * @return string|null 추론 확장자
             */
            public function guessExtension(): ?string
            {
                return null;
            }
        };

        $this->assertSame('bin', StoredFileExtension::resolve($file));
        $this->assertSame('jpg', StoredFileExtension::resolve($file, 'jpg'));
    }

    /**
     * 결과는 항상 소문자다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=single_upload__real_png__layout_attachment
     *
     * @effects uploaded_name_extension_never_reaches_stored_path
     */
    public function test_result_is_lowercase(): void
    {
        $file = new class(tempnam(sys_get_temp_dir(), 'g7sx'), 'a.bin', null, null, true) extends UploadedFile
        {
            /**
             * 내용 추론 결과를 시험이 정한 값으로 고정합니다.
             *
             * @return string|null 추론 확장자
             */
            public function guessExtension(): ?string
            {
                return 'PNG';
            }
        };

        $this->assertSame('png', StoredFileExtension::resolve($file));
        $this->assertSame('jpg', StoredFileExtension::resolve(
            new class(tempnam(sys_get_temp_dir(), 'g7sx'), 'a.bin', null, null, true) extends UploadedFile
            {
                /**
                 * 내용 추론 결과를 시험이 정한 값으로 고정합니다.
                 *
                 * @return string|null 추론 확장자
                 */
                public function guessExtension(): ?string
                {
                    return '';
                }
            },
            'JPG'
        ));
    }
}
