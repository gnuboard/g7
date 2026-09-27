<?php

namespace App\Services\Analytics;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV 내보내기
 *
 * 화면에서 본 표를 엑셀로 여는 길입니다. 라이브러리 없이 PHP 기본 기능만 씁니다.
 *
 * 파일 앞에 바이트 순서 표식(BOM)을 답니다 — 없으면 엑셀이 UTF-8 로 읽지 않아 한글이
 * 전부 깨집니다. 줄 끝도 CRLF 로 맞춥니다.
 */
class CsvExporter
{
    /**
     * 엑셀이 UTF-8 로 읽게 하는 바이트 순서 표식
     */
    public const BOM = "\xEF\xBB\xBF";

    /**
     * 행 목록을 내려받는 응답으로 만듭니다.
     *
     * 한 번에 문자열로 만들지 않고 흘려보냅니다 — 몇 만 행짜리 내보내기가 메모리를
     * 통째로 잡지 않게 합니다.
     *
     * @param  string  $filename  파일 이름
     * @param  array<int, string>  $headers  머리글 행
     * @param  iterable<int, array<int, mixed>>  $rows  내용 행
     * @return StreamedResponse 내려받기 응답
     */
    public function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');

            echo self::BOM;

            $this->writeRow($handle, $headers);

            foreach ($rows as $row) {
                $this->writeRow($handle, $row);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * 행 하나를 CSV 한 줄로 씁니다.
     *
     * @param  resource  $handle  출력 스트림
     * @param  array<int, mixed>  $row  행
     * @return void 없음
     */
    private function writeRow($handle, array $row): void
    {
        // fputcsv 는 줄 끝에 \n 만 붙인다 — 엑셀이 기대하는 CRLF 로 직접 맞춘다.
        $buffer = fopen('php://temp', 'r+');
        fputcsv($buffer, array_map(fn ($value): string => $this->cell($value), $row));
        rewind($buffer);
        $line = rtrim((string) stream_get_contents($buffer), "\r\n");
        fclose($buffer);

        fwrite($handle, $line."\r\n");
    }

    /**
     * 값 하나를 CSV 칸 문자열로 바꿉니다.
     *
     * @param  mixed  $value  값
     * @return string 칸 문자열
     */
    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
