<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\LanguagePack\InstallFromFileRequest;
use App\Http\Requests\LanguagePack\ManifestPreviewRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 언어팩 ZIP — 미리보기와 설치가 같은 파일을 받는가
 *
 * 두 경로의 규칙이 갈리면 **"설치는 되는데 내용은 확인할 수 없는 팩"** 이 생긴다.
 * 실제로 미리보기는 5MB·`mimes:zip`, 설치는 10MB·`mimetypes:` 였고, 그 사이 크기의 팩은
 * 설치만 됐다. 운영자에게는 "왜 미리보기만 안 되지" 로만 보인다.
 *
 * 규칙을 한 trait 로 모았으니, **그 정렬이 풀리면 여기서 빨개져야 한다.**
 */
class LanguagePackArchiveRuleParityTest extends TestCase
{
    /**
     * 두 요청의 `file` 규칙이 문자 그대로 같은가.
     *
     * @effects language_pack_preview_and_install_share_one_file_rule
     */
    public function test_preview_and_install_declare_the_same_file_rule(): void
    {
        $preview = (new ManifestPreviewRequest)->rules()['file'];
        $install = (new InstallFromFileRequest)->rules()['file'];

        $this->assertSame(
            $install,
            $preview,
            '미리보기 규칙이 설치보다 좁으면 설치할 수 있는 팩의 내용을 확인할 수 없습니다.'
        );
    }

    /**
     * 예전에 갈라졌던 구간(5~10MB)의 팩이 **양쪽 모두** 통과하는가.
     *
     * 7MB 는 옛 미리보기 상한(5MB)을 넘고 공통 상한(10MB)에는 못 미치는 값이라,
     * 정렬이 풀리는 순간 미리보기 쪽만 422 가 된다.
     *
     * @scenario archive_shape=within_limit
     *
     * @effects language_pack_seven_megabyte_archive_passes_both_paths
     */
    public function test_a_seven_megabyte_archive_passes_both_paths(): void
    {
        foreach ($this->archiveRuleSets() as $label => $rules) {
            $validator = Validator::make(
                ['file' => UploadedFile::fake()->create('pack.zip', 7 * 1024, 'application/zip')],
                ['file' => $rules]
            );

            $this->assertFalse(
                $validator->fails(),
                "{$label}: 7MB 언어팩이 거절됐습니다 — ".json_encode($validator->errors()->all(), JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * 공통 상한을 넘는 팩은 **양쪽 모두** 거절하는가 — 한쪽만 느슨해도 계약이 갈린다.
     *
     * @scenario archive_shape=over_limit
     *
     * @effects language_pack_oversized_archive_fails_both_paths
     */
    public function test_an_oversized_archive_fails_both_paths(): void
    {
        $overLimit = InstallFromFileRequest::LANGUAGE_PACK_MAX_KILOBYTES + 1024;

        foreach ($this->archiveRuleSets() as $label => $rules) {
            $validator = Validator::make(
                ['file' => UploadedFile::fake()->create('pack.zip', $overLimit, 'application/zip')],
                ['file' => $rules]
            );

            $this->assertTrue($validator->fails(), "{$label}: 상한을 넘는 팩이 통과했습니다.");
            $this->assertArrayHasKey('file', $validator->errors()->toArray());
        }
    }

    /**
     * 브라우저가 zip 을 `application/octet-stream` 으로 올려도 양쪽이 받는가.
     *
     * `mimes:zip` 로 되돌리면 OS·브라우저에 따라 같은 파일이 한쪽에서만 거절된다.
     *
     * @scenario archive_shape=octet_stream_mime
     *
     * @effects language_pack_octet_stream_archive_passes_both_paths
     */
    public function test_an_octet_stream_archive_passes_both_paths(): void
    {
        foreach ($this->archiveRuleSets() as $label => $rules) {
            $validator = Validator::make(
                ['file' => UploadedFile::fake()->create('pack.zip', 1024, 'application/octet-stream')],
                ['file' => $rules]
            );

            $this->assertFalse(
                $validator->fails(),
                "{$label}: octet-stream 으로 올라온 zip 이 거절됐습니다."
            );
        }
    }

    /**
     * 두 경로의 `file` 규칙을 라벨과 함께 돌려줍니다.
     *
     * @return array<string, array<int, string>> 라벨 ⇒ 규칙
     */
    private function archiveRuleSets(): array
    {
        return [
            '미리보기' => (new ManifestPreviewRequest)->rules()['file'],
            '설치' => (new InstallFromFileRequest)->rules()['file'],
        ];
    }
}
