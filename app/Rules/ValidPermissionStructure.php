<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 권한 구조 검증 규칙
 *
 * flat array, 구조화 객체(or/and), 중첩 구조를 검증합니다.
 * 레이아웃 최상위 permissions와 컴포넌트 레벨 permissions 모두에서 사용됩니다.
 */
class ValidPermissionStructure implements ValidationRule
{
    /**
     * 최대 중첩 깊이
     */
    private const MAX_DEPTH = 3;

    /**
     * or/and 최소 항목 수
     */
    private const MIN_OPERATOR_ITEMS = 2;

    /**
     * 권한 식별자 정규식
     */
    private const PERMISSION_REGEX = '/^[a-z0-9_-]+\.[a-z0-9_-]+(\.[a-z0-9_-]+)*$/i';

    /**
     * 컴포넌트 인덱스 (컴포넌트 레벨 검증 시 사용)
     */
    private ?string $componentIndex;

    /**
     * @param  string|null  $componentIndex  컴포넌트 인덱스 (null이면 최상위 레벨)
     */
    public function __construct(?string $componentIndex = null)
    {
        $this->componentIndex = $componentIndex;
    }

    /**
     * 권한 구조를 검증합니다.
     *
     * @param  string  $attribute  검증 대상 속성명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // null은 nullable 규칙에서 처리
        if ($value === null) {
            return;
        }

        // 배열이 아닌 경우
        if (! is_array($value)) {
            $fail($this->formatMessage(__('validation.layout.permissions_must_be_array_or_object')));

            return;
        }

        // 빈 배열은 유효 (권한 불필요)
        if (empty($value)) {
            return;
        }

        $this->validateStructure($value, $fail, 1);
    }

    /**
     * 재귀적으로 권한 구조를 검증합니다.
     *
     * @param  array  $permissions  권한 구조
     * @param  Closure  $fail  실패 콜백
     * @param  int  $depth  현재 깊이
     * @return bool 검증 성공 여부
     */
    private function validateStructure(array $permissions, Closure $fail, int $depth): bool
    {
        // 깊이 초과 검증
        if ($depth > self::MAX_DEPTH) {
            $fail($this->formatMessage(__('validation.layout.permissions_max_depth_exceeded', [
                'max' => self::MAX_DEPTH,
            ])));

            return false;
        }

        // flat array (리스트형 배열)
        if (array_is_list($permissions)) {
            return $this->validateFlatArray($permissions, $fail, $depth);
        }

        // 구조화 객체 (or/and)
        return $this->validateOperator($permissions, $fail, $depth);
    }

    /**
     * flat array를 검증합니다.
     *
     * @param  array  $items  권한 항목 배열
     * @param  Closure  $fail  실패 콜백
     * @param  int  $depth  현재 깊이
     * @return bool 검증 성공 여부
     */
    private function validateFlatArray(array $items, Closure $fail, int $depth): bool
    {
        foreach ($items as $index => $item) {
            if (! $this->validateItem($item, $fail, $depth, $index)) {
                return false;
            }
        }

        return true;
    }

    /**
     * or/and 연산자 구조를 검증합니다.
     *
     * @param  array  $structure  구조화 객체
     * @param  Closure  $fail  실패 콜백
     * @param  int  $depth  현재 깊이
     * @return bool 검증 성공 여부
     */
    private function validateOperator(array $structure, Closure $fail, int $depth): bool
    {
        $keys = array_keys($structure);

        // 키가 정확히 1개이고, or 또는 and여야 함
        if (count($keys) !== 1 || ! in_array($keys[0], ['or', 'and'])) {
            $fail($this->formatMessage(__('validation.layout.permissions_invalid_operator')));

            return false;
        }

        $operator = $keys[0];
        $items = $structure[$operator];

        // 값은 리스트형 배열이어야 함
        if (! is_array($items) || ! array_is_list($items)) {
            $fail($this->formatMessage(__('validation.layout.permissions_operator_must_be_array', [
                'operator' => $operator,
            ])));

            return false;
        }

        // 최소 항목 수 검증
        if (count($items) < self::MIN_OPERATOR_ITEMS) {
            $fail($this->formatMessage(__('validation.layout.permissions_operator_min_items', [
                'operator' => $operator,
                'min' => self::MIN_OPERATOR_ITEMS,
            ])));

            return false;
        }

        // 각 항목 검증
        foreach ($items as $index => $item) {
            if (! $this->validateItem($item, $fail, $depth, $index)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 단일 항목을 검증합니다.
     *
     * @param  mixed  $item  항목 (문자열 또는 중첩 구조)
     * @param  Closure  $fail  실패 콜백
     * @param  int  $depth  현재 깊이
     * @param  int|string  $index  항목 인덱스
     * @return bool 검증 성공 여부
     */
    private function validateItem(mixed $item, Closure $fail, int $depth, int|string $index): bool
    {
        if (is_string($item)) {
            return $this->validatePermissionIdentifier($item, $fail, $index);
        }

        if (is_array($item)) {
            return $this->validateStructure($item, $fail, $depth + 1);
        }

        $fail($this->formatMessage(__('validation.layout.permission_must_be_string_or_group', [
            'index' => $index,
        ])));

        return false;
    }

    /**
     * 권한 식별자 형식을 검증합니다.
     *
     * @param  string  $identifier  권한 식별자
     * @param  Closure  $fail  실패 콜백
     * @param  int|string  $index  항목 인덱스
     * @return bool 검증 성공 여부
     */
    private function validatePermissionIdentifier(string $identifier, Closure $fail, int|string $index): bool
    {
        if ($identifier === '') {
            $fail($this->formatMessage(__('validation.layout.permission_must_be_string', [
                'index' => $this->componentIndex ?? 'root',
                'perm_index' => $index,
            ])));

            return false;
        }

        if (! preg_match(self::PERMISSION_REGEX, $identifier)) {
            $fail($this->formatMessage(__('validation.layout.permission_invalid_format', [
                'index' => $this->componentIndex ?? 'root',
                'permission' => $identifier,
            ])));

            return false;
        }

        return true;
    }

    /**
     * 컴포넌트 인덱스를 포함한 에러 메시지를 생성합니다.
     *
     * @param  string  $message  원본 메시지
     * @return string 포맷된 메시지
     */
    private function formatMessage(string $message): string
    {
        return $message;
    }
}
