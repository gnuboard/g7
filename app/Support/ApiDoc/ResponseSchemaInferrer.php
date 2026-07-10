<?php

namespace App\Support\ApiDoc;

/**
 * 응답 스키마 추론기
 *
 * 실측한 응답 JSON(ResponseHelper envelope)에서 data 내부의 필드 스키마를
 * 추론합니다. 각 필드의 타입과 샘플값을 문서용 메타데이터로 변환합니다.
 */
class ResponseSchemaInferrer
{
    /**
     * envelope 응답 body 에서 data 필드 스키마를 추론합니다.
     *
     * @param  array<string, mixed>  $body  실측 응답 body (envelope)
     * @return array{envelope: array<int, string>, shape: string, fields: array<int, array<string, mixed>>, pagination: bool}
     */
    public function infer(array $body): array
    {
        $envelope = array_keys($body);
        $data = $body['data'] ?? null;

        // 목록 응답: data.data 가 배열 (BaseApiCollection)
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $rows = array_values(array_filter($data['data'], 'is_array'));

            return [
                'envelope' => $envelope,
                'shape' => 'collection',
                'fields' => $this->fieldsFromRows($rows),
                'pagination' => isset($data['pagination']),
            ];
        }

        // 단건 응답: data 가 연관 배열
        if (is_array($data) && $this->isAssoc($data)) {
            return [
                'envelope' => $envelope,
                'shape' => 'object',
                'fields' => $this->fieldsFromRow($data),
                'pagination' => false,
            ];
        }

        // data 가 순수 배열(목록만)
        if (is_array($data) && ! $this->isAssoc($data) && isset($data[0]) && is_array($data[0])) {
            $rows = array_values(array_filter($data, 'is_array'));

            return [
                'envelope' => $envelope,
                'shape' => 'array',
                'fields' => $this->fieldsFromRows($rows),
                'pagination' => false,
            ];
        }

        return [
            'envelope' => $envelope,
            'shape' => 'scalar',
            'fields' => [],
            'pagination' => false,
        ];
    }

    /**
     * 한 행(row)에서 필드별 타입·샘플값을 추출합니다.
     *
     * @param  array<string, mixed>  $row  응답 데이터 한 행
     * @return array<int, array<string, mixed>> 필드 메타데이터 목록
     */
    private function fieldsFromRow(array $row): array
    {
        $fields = [];

        foreach ($row as $key => $value) {
            $fields[] = [
                'name' => (string) $key,
                'type' => $this->typeOf($value),
                'sample' => $this->sampleOf($value),
            ];
        }

        return $fields;
    }

    /**
     * 여러 행을 병합해 필드별로 non-null 대표 샘플과 실제 타입을 선택합니다.
     *
     * 첫 행이 우연히 비어있어(null) "항상 null" 처럼 보이는 문제를 방지하기 위해,
     * 각 필드에서 값이 채워진 행을 우선 채택합니다.
     *
     * @param  array<int, array<string, mixed>>  $rows  응답 데이터 행 목록
     * @return array<int, array<string, mixed>> 필드 메타데이터 목록
     */
    private function fieldsFromRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        // 첫 행의 키 순서를 기준으로 필드 목록 확정
        $keys = array_keys($rows[0]);
        $fields = [];

        foreach ($keys as $key) {
            $chosenValue = null;
            $chosenType = 'null';

            foreach ($rows as $row) {
                if (! array_key_exists($key, $row)) {
                    continue;
                }

                $value = $row[$key];
                $type = $this->typeOf($value);

                // 값이 채워진(non-null) 첫 행을 대표로 채택하고 탐색 종료
                if ($type !== 'null') {
                    $chosenValue = $value;
                    $chosenType = $type;
                    break;
                }

                // 아직 non-null 을 못 찾았으면 null 이라도 후보로 유지
                $chosenValue = $value;
            }

            $fields[] = [
                'name' => (string) $key,
                'type' => $chosenType,
                'sample' => $this->sampleOf($chosenValue),
            ];
        }

        return $fields;
    }

    /**
     * 값의 JSON 타입을 판별합니다.
     *
     * @param  mixed  $value  값
     * @return string 타입 문자열
     */
    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_null($value) => 'null',
            is_array($value) && $this->isAssoc($value) => 'object',
            is_array($value) => 'array',
            default => 'mixed',
        };
    }

    /**
     * 값의 샘플 표현을 반환합니다 (문서 표에 표시할 축약형).
     *
     * @param  mixed  $value  값
     * @return string 샘플 표현 (마크다운 표 셀 안전)
     */
    private function sampleOf(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
            $encoded = (string) $encoded;

            if (mb_strlen($encoded) > 60) {
                $encoded = mb_substr($encoded, 0, 57).'…';
            }

            return $this->escapeCell($encoded);
        }

        $str = (string) $value;

        if (mb_strlen($str) > 40) {
            $str = mb_substr($str, 0, 37).'…';
        }

        return $this->escapeCell($str);
    }

    /**
     * 마크다운 표 셀 안에서 안전하도록 파이프/개행을 이스케이프합니다.
     *
     * @param  string  $text  원본 텍스트
     * @return string 이스케이프된 텍스트
     */
    private function escapeCell(string $text): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $text);
    }

    /**
     * 배열이 연관 배열(맵)인지 판별합니다.
     *
     * @param  array<mixed>  $arr  배열
     * @return bool 연관 배열 여부
     */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
