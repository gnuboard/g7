<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;

/**
 * 소스 값의 두 가지 공통 해석 — 타입 리터럴 디코드와 조건식 정규화.
 *
 * 노드(S2)·상태 초기화(S2)·액션(S3) 층이 같은 규칙으로 값을 읽는다. 규칙을 각 층에 복제하면
 * 한쪽만 고쳐 서로 어긋나므로(같은 `:attr` 이 층마다 다르게 디코드되는 함정) 여기에 모은다.
 */
final class MarkupValue
{
    /**
     * `:attr` 값을 JSON 리터럴로 디코드합니다.
     *
     * 무접두 속성값은 문자열이고, `:` 접두 속성값만 JSON 타입(숫자·불리언·객체·배열·null)으로
     * 읽는다. 값이 없는 불리언 표기(`<div :x/>`)는 타입을 정할 수 없으므로 거부한다.
     *
     * @param  MarkupAttribute  $attribute  `:attr` 속성
     * @param  string  $suggestion  오류 메시지에 실을 무접두 표기 제안
     * @param  string|null  $path  소스 파일 경로
     * @return mixed 디코드된 값
     *
     * @throws CompileError 값이 없거나 JSON 이 아닌 경우
     */
    public static function decodeTypeLiteral(MarkupAttribute $attribute, string $suggestion, ?string $path): mixed
    {
        if ($attribute->isBoolean()) {
            throw new CompileError(
                LayoutMarkupErrorCode::DirectiveNeedsValue,
                $attribute->line,
                $attribute->column,
                ['attr' => $attribute->name],
                $path,
            );
        }

        $raw = (string) $attribute->value;
        $value = json_decode($raw, true);

        if ($value === null && strtolower(trim($raw)) !== 'null') {
            throw new CompileError(
                LayoutMarkupErrorCode::InvalidTypeLiteral,
                $attribute->line,
                $attribute->column,
                ['attr' => $attribute->name, 'found' => $raw, 'suggestion' => $suggestion],
                $path,
            );
        }

        // 빈 객체 리터럴 `{}` 는 빈 배열 `[]` 와 구별해 표식으로 돌려준다 — PHP 배열은 list/map 을
        // 구분하지 않아 여기서 뭉개면 작성자가 적은 `{}` 가 산출 JSON 에서 `[]` 이 되고, 그 값이
        // 서버 요청 본문으로 실려 가면 검증이 대상을 찾지 못한다({@see EmptyMap}).
        if ($value === [] && str_starts_with(ltrim($raw), '{')) {
            return EmptyMap::instance();
        }

        return $value;
    }

    /**
     * 조건 표현식을 정규화합니다 (`if`/`data-if`/액션 `if` 공통).
     *
     * `{{}}` 가 없으면 전체를 감싼다 — 감싸지 않은 식은 엔진이 문자열 보간으로 처리해 **항상 참**이
     * 되는 함정이라(비어 있지 않은 문자열은 truthy) 작성 의도와 정반대로 동작한다. 반대로 값의
     * **일부만** 감싼 혼합형(`{{x}} === 'y'`)은 거부한다(D4). 리터럴 불리언(`false`/`true`)은
     * 타입 리터럴 한 표기(`:if="false"`)로 모아 역변환이 갈리지 않게 한다.
     *
     * @param  string  $value  원문 값
     * @param  int  $line  오류 좌표 라인
     * @param  int  $column  오류 좌표 컬럼
     * @param  string  $name  속성명 (오류 메시지용)
     * @param  string  $typedSuggestion  리터럴 불리언일 때 제안할 타입 리터럴 표기
     * @param  string|null  $path  소스 파일 경로
     * @return string 정규화된 표현식
     *
     * @throws CompileError 혼합형이거나 리터럴 불리언인 경우
     */
    public static function normalizeCondition(
        string $value,
        int $line,
        int $column,
        string $name,
        string $typedSuggestion,
        ?string $path,
    ): string {
        if (in_array(strtolower(trim($value)), ['true', 'false'], true)) {
            throw new CompileError(
                LayoutMarkupErrorCode::ConditionBooleanLiteral,
                $line,
                $column,
                ['attr' => $name, 'found' => trim($value), 'suggestion' => $typedSuggestion],
                $path,
            );
        }

        if (! str_contains($value, '{{')) {
            return '{{'.$value.'}}';
        }

        $trimmed = trim($value);
        $inner = substr($trimmed, 2, -2);
        $isSingleBinding = str_starts_with($trimmed, '{{')
            && str_ends_with($trimmed, '}}')
            && ! str_contains($inner, '{{')
            && ! str_contains($inner, '}}');

        if (! $isSingleBinding) {
            throw new CompileError(
                LayoutMarkupErrorCode::MixedIfExpression,
                $line,
                $column,
                ['attr' => $name, 'found' => $value],
                $path,
            );
        }

        return $trimmed;
    }
}
