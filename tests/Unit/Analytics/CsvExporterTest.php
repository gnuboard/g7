<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\CsvExporter;
use Tests\TestCase;

/**
 * CSV 내보내기 — 엑셀이 읽을 수 있는 모양인지.
 *
 * 바이트 순서 표식이 빠지면 엑셀이 UTF-8 로 읽지 않아 한글이 전부 깨진다. 응답은
 * 성공하고 파일도 내려오므로, 열어 보기 전까지는 아무도 모른다.
 */
class CsvExporterTest extends TestCase
{
    /**
     * 내보낸 내용을 문자열로 받습니다.
     *
     * @param  array<int, string>  $headers  머리글
     * @param  array<int, array<int, mixed>>  $rows  내용 행
     * @return string 파일 내용
     */
    private function render(array $headers, array $rows): string
    {
        $response = app(CsvExporter::class)->stream('test.csv', $headers, $rows);

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * 파일이 바이트 순서 표식으로 시작하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, format=csv, role=read
     *
     * @effects csv_export_starts_with_a_byte_order_mark
     */
    public function test_the_file_starts_with_a_byte_order_mark(): void
    {
        $body = $this->render(['label'], [['자유게시판']]);

        $this->assertStringStartsWith(CsvExporter::BOM, $body);
        $this->assertStringContainsString('자유게시판', $body);
    }

    /**
     * 줄 끝이 CRLF 인지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, format=csv, role=read
     *
     * @effects csv_export_uses_crlf_line_endings
     */
    public function test_lines_end_with_crlf(): void
    {
        $body = $this->render(['a', 'b'], [['1', '2'], ['3', '4']]);

        $this->assertSame(3, substr_count($body, "\r\n"), '머리글 한 줄 + 내용 두 줄');
    }

    /**
     * 구분자·따옴표·줄바꿈이 든 값이 깨지지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, format=csv, role=read
     *
     * @effects csv_export_starts_with_a_byte_order_mark
     */
    public function test_special_characters_are_escaped(): void
    {
        $body = $this->render(['label'], [['쉼표, 있음'], ['따옴표 " 있음'], ["줄바꿈\n있음"]]);

        $this->assertStringContainsString('"쉼표, 있음"', $body);
        $this->assertStringContainsString('""', $body, '따옴표는 두 번 적어 탈출한다');
        $this->assertStringContainsString('"줄바꿈', $body);
    }

    /**
     * 빈 값과 참거짓이 사람이 읽을 모양으로 나가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, format=csv, role=read
     *
     * @effects csv_export_starts_with_a_byte_order_mark
     */
    public function test_null_and_boolean_cells_are_rendered(): void
    {
        $body = $this->render(['a', 'b', 'c'], [[null, true, false]]);

        $this->assertStringContainsString(",1,0\r\n", $body);
    }

    /**
     * 행을 흘려보내는 방식이라 반복자도 받는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=reports, format=csv, role=read
     *
     * @effects csv_export_covers_the_whole_period_not_one_page
     */
    public function test_rows_can_be_a_generator(): void
    {
        $rows = (function (): iterable {
            foreach (range(1, 3) as $n) {
                yield [$n];
            }
        })();

        $body = $this->render(['n'], iterator_to_array($rows));

        $this->assertStringContainsString("1\r\n2\r\n3\r\n", $body, '몇 만 행짜리 내보내기가 메모리를 통째로 잡으면 안 된다');
    }
}
