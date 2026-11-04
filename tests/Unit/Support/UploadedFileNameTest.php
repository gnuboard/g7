<?php

namespace Tests\Unit\Support;

use App\Support\UploadedFileName;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * 업로드 파일 원래 이름 복원 테스트.
 *
 * 브라우저는 multipart/form-data 의 파일 이름에서 큰따옴표를 `%22`, CR 을 `%0D`, LF 를 `%0A` 로 바꿔 보낸다.
 * 그 세 가지만 되돌리고 다른 퍼센트 문자열은 사용자가 붙인 이름으로 보고 그대로 두는지 고정한다.
 *
 * @scenario-manifest tests/scenarios/upload-original-filename.yaml
 */
class UploadedFileNameTest extends TestCase
{
    /**
     * 브라우저가 바꾼 큰따옴표(`%22`)를 원래 글자로 되돌린다.
     *
     * @scenario input=quote
     *
     * @effects upload_name_quote_restored
     *
     * @return void 반환값 없음
     */
    public function test_restores_percent_encoded_double_quote(): void
    {
        $this->assertSame('견적 "최종" \'사본\'.pdf', UploadedFileName::restore('견적 %22최종%22 \'사본\'.pdf'));
    }

    /**
     * 대문자 `%0D`·`%0A` 를 CR·LF 로 되돌린다.
     *
     * @scenario input=crlf_upper
     *
     * @effects upload_name_crlf_restored
     *
     * @return void 반환값 없음
     */
    public function test_restores_upper_case_cr_and_lf(): void
    {
        $this->assertSame("a\r\nb.txt", UploadedFileName::restore('a%0D%0Ab.txt'));
    }

    /**
     * 소문자 `%0d`·`%0a` 도 같은 글자로 되돌린다.
     *
     * @scenario input=crlf_lower
     *
     * @effects upload_name_crlf_restored
     *
     * @return void 반환값 없음
     */
    public function test_restores_lower_case_cr_and_lf(): void
    {
        $this->assertSame("a\r\nb\n.txt", UploadedFileName::restore('a%0d%0ab%0A.txt'));
    }

    /**
     * 세 가지 밖의 퍼센트 문자열(`%20`·`%25`·`%2522`·`%0B`)은 사용자가 붙인 이름이라 그대로 둔다.
     *
     * @scenario input=other_percent
     *
     * @effects upload_name_other_percent_kept
     *
     * @return void 반환값 없음
     */
    public function test_keeps_other_percent_sequences(): void
    {
        foreach (['100%20off.pdf', '50%25 할인.txt', 'a%2522b.txt', 'tab%0Bname.txt', '100%.txt', 'end%2'] as $name) {
            $this->assertSame($name, UploadedFileName::restore($name), $name);
        }
    }

    /**
     * 되돌린 결과가 다시 치환되지 않는다 — 한 번에 치환한다.
     *
     * @scenario input=quote
     *
     * @effects upload_name_quote_restored, upload_name_other_percent_kept
     *
     * @return void 반환값 없음
     */
    public function test_restores_in_a_single_pass(): void
    {
        $this->assertSame('%"0A.txt', UploadedFileName::restore('%%220A.txt'));
    }

    /**
     * 퍼센트가 없는 이름과 빈 이름은 그대로 돌려준다.
     *
     * @scenario input=plain
     *
     * @effects upload_name_plain_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_plain_and_empty_names_are_unchanged(): void
    {
        $this->assertSame('보고서.txt', UploadedFileName::restore('보고서.txt'));
        $this->assertSame('', UploadedFileName::restore(''));
    }

    /**
     * 업로드 파일에서 바로 읽을 때도 같은 규칙을 쓴다 (경로는 `getClientOriginalName()` 이 이미 떼어 낸다).
     *
     * @scenario input=quote
     *
     * @effects upload_name_quote_restored, upload_name_read_from_uploaded_file
     *
     * @return void 반환값 없음
     */
    public function test_original_reads_the_uploaded_file_name(): void
    {
        $file = new UploadedFile(__FILE__, 'dir/견적 %22최종%22.pdf', null, null, true);

        $this->assertSame('견적 "최종".pdf', UploadedFileName::original($file));
    }

    /**
     * 빈 업로드 이름은 빈 문자열이다.
     *
     * @scenario input=empty
     *
     * @effects upload_name_plain_unchanged, upload_name_read_from_uploaded_file
     *
     * @return void 반환값 없음
     */
    public function test_original_of_an_empty_client_name_is_empty(): void
    {
        $file = new UploadedFile(__FILE__, '', null, null, true);

        $this->assertSame('', UploadedFileName::original($file));
    }
}
