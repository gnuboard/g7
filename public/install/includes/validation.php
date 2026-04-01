<?php

/**
 * 입력값 검증 함수
 *
 * 그누보드7 웹 인스톨러의 입력값 검증 기능을 제공합니다.
 */

/**
 * 검증 에러 메시지를 다국어로 반환
 *
 * @param string $key 번역 키
 * @param string $lang 언어 코드
 * @param array $replacements 치환할 플레이스홀더 배열
 * @return string 번역된 메시지
 */
function getValidationMessage(string $key, string $lang, array $replacements = []): string
{
    $langFile = __DIR__ . "/../lang/{$lang}.php";

    if (!file_exists($langFile)) {
        return $key;
    }

    $translations = require $langFile;
    $message = $translations[$key] ?? $key;

    foreach ($replacements as $placeholder => $value) {
        $message = str_replace(":{$placeholder}", $value, $message);
    }

    return $message;
}

/**
 * 입력값 검증
 *
 * 주어진 규칙에 따라 입력값을 검증합니다.
 *
 * @param string $field 필드명
 * @param mixed $value 검증할 값
 * @param array $rules 검증 규칙 배열 (예: ['required', 'email', 'min:8'])
 * @param array $data 전체 데이터 배열 (confirmed 규칙에 필요)
 * @param string $lang 언어 코드 (ko 또는 en)
 * @return array 검증 에러 배열 (빈 배열이면 검증 성공)
 */
function validateInput(string $field, $value, array $rules, array $data = [], string $lang = 'ko'): array
{
    $errors = [];

    foreach ($rules as $rule) {
        // 규칙과 파라미터 분리 (예: 'min:8' => ['min', '8'])
        $ruleParts = explode(':', $rule, 2);
        $ruleName = $ruleParts[0];
        $ruleParam = $ruleParts[1] ?? null;

        // 각 규칙 검증
        $errorMessage = null;

        switch ($ruleName) {
            case 'required':
                $errorMessage = validateRequired($field, $value, $lang);
                break;

            case 'email':
                $errorMessage = validateEmail($field, $value, $lang);
                break;

            case 'min':
                $errorMessage = validateMin($field, $value, $ruleParam, $lang);
                break;

            case 'max':
                $errorMessage = validateMax($field, $value, $ruleParam, $lang);
                break;

            case 'url':
                $errorMessage = validateUrl($field, $value, $lang);
                break;

            case 'alpha_num':
                $errorMessage = validateAlphaNum($field, $value, $lang);
                break;

            case 'confirmed':
                $errorMessage = validateConfirmed($field, $value, $data, $lang);
                break;

            case 'numeric':
                $errorMessage = validateNumeric($field, $value, $lang);
                break;

            case 'integer':
                $errorMessage = validateInteger($field, $value, $lang);
                break;

            case 'in':
                $allowedValues = $ruleParam ? explode(',', $ruleParam) : [];
                $errorMessage = validateIn($field, $value, $allowedValues, $lang);
                break;

            default:
                // 알 수 없는 규칙은 무시
                break;
        }

        // 에러 메시지가 있으면 배열에 추가
        if ($errorMessage !== null) {
            $errors[] = $errorMessage;
        }
    }

    return $errors;
}

/**
 * 필수 입력 검증
 *
 * @param string $field 필드명
 * @param mixed $value 값
 * @param string $lang 언어
 * @return string|null 에러 메시지 (성공 시 null)
 */
function validateRequired(string $field, $value, string $lang): ?string
{
    $isEmpty = $value === null
        || $value === ''
        || (is_array($value) && empty($value));

    if ($isEmpty) {
        $fieldLabel = getFieldLabel($field, $lang);
        return getValidationMessage('validation_required', $lang, ['field' => $fieldLabel]);
    }

    return null;
}

/**
 * 이메일 형식 검증
 *
 * @param string $field 필드명
 * @param mixed $value 값
 * @param string $lang 언어
 * @return string|null 에러 메시지 (성공 시 null)
 */
function validateEmail(string $field, $value, string $lang): ?string
{
    // 빈 값은 required 규칙에서 처리
    if ($value === null || $value === '') {
        return null;
    }

    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $fieldLabel = getFieldLabel($field, $lang);
        return getValidationMessage('validation_email', $lang, ['field' => $fieldLabel]);
    }

    return null;
}

/**
 * 최소 길이 검증
 *
 * @param string $field 필드명
 * @param mixed $value 값
 * @param string|int $min 최소 길이
 * @param string $lang 언어
 * @return string|null 에러 메시지 (성공 시 null)
 */
function validateMin(string $field, $value, $min, string $lang): ?string
{
    // 빈 값은 required 규칙에서 처리
    if ($value === null || $value === '') {
        return null;
    }

    $length = is_string($value) ? mb_strlen($value) : (is_numeric($value) ? $value : 0);

    if ($length < (int) $min) {
        $fieldLabel = getFieldLabel($field, $lang);
        return getValidationMessage('validation_min', $lang, ['field' => $fieldLabel, 'min' => $min]);
    }

    return null;
}

/**
 * 최대 길이 검증
 *
 * @param string $field 필드명
 * @param mixed $value 값
 * @param string|int $max 최대 길이
 * @param string $lang 언어
 * @return string|null 에러 메시지 (성공 시 null)
 */
function validateMax(string $field, $value, $max, string $lang): ?string
{
    // 빈 값은 required 규칙에서 처리
    if ($value === null || $value === '') {
        return null;
    }

    $length = is_string($value) ? mb_strlen($value) : (is_numeric($value) ? $value : 0);

    if ($length > (int) $max) {
        $fieldLabel = getFieldLabel($field, $lang);
        return getValidationMessage('validation_max', $lang, ['field' => $fieldLabel, 'max' => $max]);
    }

    return null;
}

/**
 * URL 형식 검증
 *
 * @param string $field 필드명
 * @param mixed $value 값
 * @param string $lang 언어
 * @return string|null 에러 메시지 (성공 시 null)
 */
function validateUrl(string $field, $value, string $lang): ?string
{
    // 빈 값은 required 규칙에서 처리
    if ($value === null || $value === '') {
        return null;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        $fieldLabel = getFieldLabel($field, $lang);
        return getValidationMessage('validation_url', $lang, ['field' => $fieldLabel]);
    }

    return null;
}

/**
 * 영문자와 숫자만 허용 검증
 *
 * @param string $field 필드명
 * @param mixed $value 값
 * @param string $lang 언어
 * @return string|null 에러 메시지 (성공 시 null)
 */
function validateAlphaNum(string $field, $value, string $lang): ?string
{
    // 빈 값은 required 규칙에서 처리
    if ($value === null || $value === '') {
        return null;
    }

    if (!ctype_alnum((string) $value)) {
        $fieldLabel = getFieldLabel($field, $lang);
        return getValidationMessage('validation_alpha_num', $lang, ['field' => $fieldLabel]);
    }

    return null;
}

/**
 * 확인 필드 일치 검증
 *
 * @param string $field 필드명
 * @param mixed $value 값
 * @param array $data 전체 데이터
 * @param string $lang 언어
 * @return string|null 에러 메시지 (성공 시 null)
 */
function validateConfirmed(string $field, $value, array $data, string $lang): ?string
{
    // 빈 값은 required 규칙에서 처리
    if ($value === null || $value === '') {
        return null;
    }

    $confirmField = $field . '_confirmation';
    $confirmValue = $data[$confirmField] ?? null;

    if ($value !== $confirmValue) {
        $fieldLabel = getFieldLabel($field, $lang);
        return getValidationMessage('validation_confirmed', $lang, ['field' => $fieldLabel]);
    }

    return null;
}

/**
 * 숫자 형식 검증
 *
 * @param string $field 필드명
 * @param mixed $value 값
 * @param string $lang 언어
 * @return string|null 에러 메시지 (성공 시 null)
 */
function validateNumeric(string $field, $value, string $lang): ?string
{
    // 빈 값은 required 규칙에서 처리
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        $fieldLabel = getFieldLabel($field, $lang);
        return getValidationMessage('validation_numeric', $lang, ['field' => $fieldLabel]);
    }

    return null;
}

/**
 * 정수 형식 검증
 *
 * @param string $field 필드명
 * @param mixed $value 값
 * @param string $lang 언어
 * @return string|null 에러 메시지 (성공 시 null)
 */
function validateInteger(string $field, $value, string $lang): ?string
{
    // 빈 값은 required 규칙에서 처리
    if ($value === null || $value === '') {
        return null;
    }

    if (!filter_var($value, FILTER_VALIDATE_INT)) {
        $fieldLabel = getFieldLabel($field, $lang);
        return getValidationMessage('validation_integer', $lang, ['field' => $fieldLabel]);
    }

    return null;
}

/**
 * 허용된 값 목록 검증
 *
 * @param string $field 필드명
 * @param mixed $value 값
 * @param array $allowedValues 허용된 값 목록
 * @param string $lang 언어
 * @return string|null 에러 메시지 (성공 시 null)
 */
function validateIn(string $field, $value, array $allowedValues, string $lang): ?string
{
    // 빈 값은 required 규칙에서 처리
    if ($value === null || $value === '') {
        return null;
    }

    if (!in_array($value, $allowedValues, true)) {
        $fieldLabel = getFieldLabel($field, $lang);
        $allowedList = implode(', ', $allowedValues);
        return getValidationMessage('validation_in', $lang, ['field' => $fieldLabel, 'values' => $allowedList]);
    }

    return null;
}

/**
 * 필드 라벨 반환
 *
 * 다국어 파일에서 필드 라벨을 가져옵니다.
 *
 * @param string $field 필드명
 * @param string $lang 언어 코드 (ko 또는 en)
 * @return string 필드 라벨
 */
function getFieldLabel(string $field, string $lang): string
{
    // 다국어 파일 경로
    $langFile = __DIR__ . "/../lang/{$lang}.php";

    // 다국어 파일이 존재하지 않으면 필드명 반환
    if (!file_exists($langFile)) {
        return $field;
    }

    $translations = require $langFile;

    // fields 키에서 해당 필드 반환 (없으면 필드명 반환)
    return $translations['fields'][$field] ?? $field;
}

/**
 * 여러 필드 일괄 검증
 *
 * @param array $data 검증할 데이터
 * @param array $rules 필드별 검증 규칙 (예: ['email' => ['required', 'email']])
 * @param string $lang 언어 코드
 * @return array 필드별 에러 배열 (빈 배열이면 검증 성공)
 */
function validateMultiple(array $data, array $rules, string $lang = 'ko'): array
{
    $errors = [];

    foreach ($rules as $field => $fieldRules) {
        $value = $data[$field] ?? null;
        $fieldErrors = validateInput($field, $value, $fieldRules, $data, $lang);

        if (!empty($fieldErrors)) {
            $errors[$field] = $fieldErrors;
        }
    }

    return $errors;
}
