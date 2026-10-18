<?php

namespace App\Traits;

/**
 * 환경설정 데이터 정규화 Trait
 *
 * 설정 저장/조회 시 defaults.json 스키마에 맞게 데이터를 정규화합니다.
 * 다국어 필드(배열)가 문자열로 저장된 경우 배열로 변환하는 등의 처리를 수행합니다.
 *
 * @example
 * ```php
 * class EcommerceSettingsService
 * {
 *     use NormalizesSettingsData;
 *
 *     public function getAllSettings(): array
 *     {
 *         $settings = $this->loadSettings();
 *         return $this->normalizeSettingsData($settings, $defaults);
 *     }
 * }
 * ```
 */
trait NormalizesSettingsData
{
    /**
     * 설정 데이터를 defaults 스키마에 맞게 정규화합니다.
     *
     * @param  array  $settings  정규화할 설정 데이터
     * @param  array  $defaults  defaults.json의 defaults 섹션
     * @return array 정규화된 설정 데이터
     */
    protected function normalizeSettingsData(array $settings, array $defaults): array
    {
        foreach ($settings as $category => $categorySettings) {
            if (! is_array($categorySettings)) {
                continue;
            }

            $categoryDefaults = $defaults[$category] ?? [];
            $settings[$category] = $this->normalizeCategoryData($categorySettings, $categoryDefaults);
        }

        return $settings;
    }

    /**
     * 카테고리별 설정 데이터를 정규화합니다.
     *
     * @param  array  $settings  카테고리 설정 데이터
     * @param  array  $defaults  카테고리 기본값
     * @return array 정규화된 카테고리 설정
     */
    protected function normalizeCategoryData(array $settings, array $defaults): array
    {
        foreach ($settings as $key => $value) {
            $defaultValue = $defaults[$key] ?? null;

            // 기본값이 배열인데 현재 값이 문자열인 경우 (다국어 필드)
            if (is_array($defaultValue) && is_string($value)) {
                $settings[$key] = $this->convertStringToMultilingual($value, $defaultValue);
            }

            // 기본값이 숫자인데 현재 값이 숫자 문자열인 경우 (HTML number 입력 등)
            $numeric = $this->normalizeNumericScalar($value, $defaultValue);
            if ($numeric !== null) {
                $settings[$key] = $numeric;
            }

            // 배열 내부의 객체도 정규화 (currencies 같은 경우)
            if (is_array($value) && is_array($defaultValue)) {
                $settings[$key] = $this->isIndexedArray($value)
                    ? $this->normalizeArrayItems($value, $defaultValue)
                    : $this->normalizeNestedGroup($value, $defaultValue);
            }
        }

        return $settings;
    }

    /**
     * 중첩된 연관 배열(설정 그룹)을 같은 규칙으로 재귀 정규화합니다.
     *
     * 인덱스 배열은 "같은 구조의 항목 목록" 이라 normalizeArrayItems 가 담당하지만,
     * 연관 배열은 "하위 설정 그룹" 이므로 카테고리와 동일하게 다루어야 합니다.
     * 이 분기가 없으면 그룹 한 단계 아래의 숫자 문자열이 정규화되지 않고 남습니다.
     *
     * 다국어 필드(`{"ko": "...", "en": "..."}`)도 연관 배열이지만, 기본값의 각 로케일 값이
     * 문자열이라 숫자 캐스트 조건에 걸리지 않아 그대로 통과합니다.
     *
     * @param  array  $group  중첩 설정 그룹
     * @param  array  $defaults  대응 기본값 그룹
     * @return array 정규화된 그룹
     */
    protected function normalizeNestedGroup(array $group, array $defaults): array
    {
        return $this->normalizeCategoryData($group, $defaults);
    }

    /**
     * 배열 내부 항목들을 정규화합니다.
     *
     * currencies 배열처럼 객체 배열인 경우 각 항목을 정규화합니다.
     *
     * @param  array  $items  항목 배열
     * @param  array  $defaultItems  기본 항목 배열
     * @return array 정규화된 항목 배열
     */
    protected function normalizeArrayItems(array $items, array $defaultItems): array
    {
        // 기본값 배열에서 첫 번째 항목의 구조를 참조
        $templateItem = $defaultItems[0] ?? null;

        if (! is_array($templateItem)) {
            return $items;
        }

        // 기본값 항목들을 식별자(code 등)로 인덱싱
        $defaultItemsIndexed = $this->indexItemsByIdentifier($defaultItems);

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            // 해당 항목의 기본값 찾기 (code로 매칭)
            $itemIdentifier = $item['code'] ?? $item['id'] ?? null;
            $matchingDefault = $itemIdentifier !== null
                ? ($defaultItemsIndexed[$itemIdentifier] ?? $templateItem)
                : $templateItem;

            $items[$index] = $this->normalizeItem($item, $matchingDefault);
        }

        return $items;
    }

    /**
     * 단일 항목을 정규화합니다.
     *
     * @param  array  $item  정규화할 항목
     * @param  array  $defaultItem  기본값 항목
     * @return array 정규화된 항목
     */
    protected function normalizeItem(array $item, array $defaultItem): array
    {
        foreach ($item as $key => $value) {
            $defaultValue = $defaultItem[$key] ?? null;

            // 기본값이 배열인데 현재 값이 문자열인 경우 (다국어 필드)
            if (is_array($defaultValue) && is_string($value)) {
                $item[$key] = $this->convertStringToMultilingual($value, $defaultValue);
            }

            // 기본값이 숫자인데 현재 값이 숫자 문자열인 경우 (환율/소수 자릿수 등)
            $numeric = $this->normalizeNumericScalar($value, $defaultValue);
            if ($numeric !== null) {
                $item[$key] = $numeric;
            }

            // 항목 내부의 중첩 그룹도 동일 규칙으로 정규화
            if (is_array($value) && is_array($defaultValue) && ! $this->isIndexedArray($value)) {
                $item[$key] = $this->normalizeNestedGroup($value, $defaultValue);
            }
        }

        return $item;
    }

    /**
     * 숫자 기본값을 가진 설정의 숫자 문자열을 스칼라 숫자로 정규화합니다.
     *
     * HTML number 입력의 DOM 값은 문자열이고 검증 규칙(integer/numeric)은 숫자 문자열을
     * 통과시키되 캐스트하지 않으므로, 설정 파일에 문자열로 영속될 수 있습니다.
     * 그 값이 Carbon 처럼 strict 타입을 요구하는 경계에 닿으면 TypeError 가 발생하므로
     * 조회 시점에 defaults 스키마의 타입으로 되돌립니다.
     *
     * 기본값이 int/float 스칼라이고 값이 숫자 문자열일 때만 변환하며,
     * null·불리언·배열·비숫자 문자열은 변경하지 않습니다.
     *
     * @param  mixed  $value  현재 설정값
     * @param  mixed  $defaultValue  defaults 스키마의 기본값
     * @return int|float|null 정규화된 값 (대상이 아니면 null)
     */
    protected function normalizeNumericScalar(mixed $value, mixed $defaultValue): int|float|null
    {
        if (! is_string($value) || ! is_numeric(trim($value))) {
            return null;
        }

        // is_int/is_float 는 불리언을 포함하지 않으므로 bool 기본값은 자동 제외된다.
        if (is_int($defaultValue)) {
            return (int) trim($value);
        }

        if (is_float($defaultValue)) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * 문자열을 다국어 배열로 변환합니다.
     *
     * 기본값의 구조를 참조하여 다국어 배열을 생성합니다.
     * 기본값에 해당 로케일의 값이 있으면 사용하고, 없으면 입력 문자열을 사용합니다.
     *
     * @param  string  $value  변환할 문자열
     * @param  array  $defaultValue  기본값 (다국어 배열)
     * @return array 다국어 배열
     */
    protected function convertStringToMultilingual(string $value, array $defaultValue): array
    {
        // 기본값이 연관 배열(다국어 형식)인지 확인
        if (! $this->isMultilingualArray($defaultValue)) {
            return [$value]; // 일반 배열이면 그냥 배열로 감싸서 반환
        }

        $result = [];
        $locales = array_keys($defaultValue);

        foreach ($locales as $locale) {
            // 기본값에서 해당 로케일의 값이 입력 문자열과 동일하면 기본값 사용
            // 아니면 입력 문자열을 모든 로케일에 할당
            $result[$locale] = $value;
        }

        return $result;
    }

    /**
     * 항목 배열을 식별자로 인덱싱합니다.
     *
     * @param  array  $items  항목 배열
     * @return array 식별자를 키로 하는 연관 배열
     */
    protected function indexItemsByIdentifier(array $items): array
    {
        $indexed = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $identifier = $item['code'] ?? $item['id'] ?? null;
            if ($identifier !== null) {
                $indexed[$identifier] = $item;
            }
        }

        return $indexed;
    }

    /**
     * 인덱스 배열인지 확인합니다.
     *
     * @param  array  $array  확인할 배열
     * @return bool 인덱스 배열이면 true
     */
    protected function isIndexedArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * 다국어 배열인지 확인합니다.
     *
     * 로케일 코드(ko, en 등)를 키로 하는 연관 배열인지 확인합니다.
     *
     * @param  array  $array  확인할 배열
     * @return bool 다국어 배열이면 true
     */
    protected function isMultilingualArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        // 인덱스 배열이면 다국어 배열이 아님
        if ($this->isIndexedArray($array)) {
            return false;
        }

        // 키가 로케일 코드 형식인지 확인 (2-3자 알파벳)
        $keys = array_keys($array);
        foreach ($keys as $key) {
            if (! is_string($key) || ! preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $key)) {
                return false;
            }
        }

        return true;
    }
}
