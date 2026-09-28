<?php

namespace Tests\Feature\Validate;

use App\Http\Resources\BaseApiResource;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BaseApiResource::formatFileSize() 의 형식 계약과 하위 호환 공지 (#599 validate 코어 minor)
 *
 * #599 가 레이아웃 리소스 세 곳의 private 변환을 부모의 공용 메서드로 합치면서
 * `size_formatted` 자릿수가 소수 첫째 → 둘째 자리로, 상한 단위가 MB → GB 로 바뀌었다.
 * 응답 값이 달라지는 변경인데 개발 문서에 공지가 없었다.
 */
class BaseApiResourceFileSizeContractTest extends TestCase
{
    /**
     * 형식 계약 표.
     *
     * @return array<string, array{0: int|null, 1: string}> [바이트, 기대 문자열]
     */
    public static function sizes(): array
    {
        return [
            'null' => [null, '0 B'],
            'zero' => [0, '0 B'],
            'bytes' => [500, '500 B'],
            'kb_boundary' => [1024, '1 KB'],
            'kb_exact_half' => [1536, '1.5 KB'],
            'kb_two_decimals' => [1500, '1.46 KB'],
            'kb_12345' => [12345, '12.06 KB'],
            'mb_two_decimals' => [1234567, '1.18 MB'],
            'gb_boundary' => [1073741824, '1 GB'],
            'gb_fraction' => [3758096384, '3.5 GB'],
        ];
    }

    /**
     * 공용 변환이 문서의 표와 같은 값을 낸다.
     *
     * 수정 전에도 통과한다 — 문서가 약속한 계약을 코드에 고정하는 핀이다.
     *
     * @scenario case=file_size_contract
     *
     * @effects format_file_size_follows_documented_contract
     *
     * @param  int|null  $bytes  바이트
     * @param  string  $expected  기대 문자열
     * @return void 반환값 없음
     */
    #[DataProvider('sizes')]
    public function test_format_file_size_follows_documented_contract(?int $bytes, string $expected): void
    {
        $resource = new class(null) extends BaseApiResource
        {
            /**
             * 보호된 변환을 호출합니다.
             *
             * @param  int|null  $bytes  바이트
             * @return string 변환 결과
             */
            public function format(?int $bytes): string
            {
                return $this->formatFileSize($bytes);
            }
        };

        $this->assertSame($expected, $resource->format($bytes));
    }

    /**
     * 개발 문서가 공용 메서드와 자릿수 변경(하위 호환 영향)을 공지한다.
     *
     * 수정 전 실패 이유: docs/backend/api-resources.md 에 formatFileSize 와 자릿수 변경 예가 없었다.
     *
     * @scenario case=file_size_bc_notice
     *
     * @effects format_file_size_change_is_documented
     *
     * @return void 반환값 없음
     */
    public function test_format_file_size_change_is_documented(): void
    {
        $doc = file_get_contents(base_path('docs/backend/api-resources.md'));

        $this->assertStringContainsString('formatFileSize', $doc, '공용 메서드가 문서에 없습니다.');
        $this->assertStringContainsString('`12.1 KB` | `12.06 KB`', $doc, '자릿수 변경 전후 예가 문서에 없습니다.');
        $this->assertStringContainsString('`1024 MB` | `1 GB`', $doc, '상한 단위 변경 예가 문서에 없습니다.');
    }
}
