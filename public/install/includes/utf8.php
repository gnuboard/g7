<?php

use App\Support\ProcessOutputEncoding;

/**
 * 인스톨러 UTF-8 정규화 / JSON 출력 헬퍼
 *
 * 외부 명령 출력(`whoami` 등)과 예외 메시지는 UTF-8 이라는 보장이 없다.
 * 한국어 Windows 의 `whoami` 는 OEM 949(CP949) 로 출력하므로 계정명에 한글이 있으면
 * invalid UTF-8 바이트가 응답 배열에 실리고 `json_encode()` 가 `false` 를 반환한다.
 * `echo false` 는 빈 문자열이라 HTTP 200 + 빈 본문이 나가고, 프론트는
 * "Unexpected end of JSON input" 으로 죽는데 서버에는 예외도 로그도 남지 않는다.
 *
 * 인스톨러의 모든 JSON 응답은 이 파일의 `installer_json_encode()` 를 거친다.
 *
 * 의존성 0 — 어느 시점에 로드되어도 안전해야 하므로 다른 인스톨러 파일을 include 하지 않는다.
 */

// 코어와 공유하는 정규화 SSoT. BASE_PATH 가 테스트에서 temp 로 바뀌어도
// 실제 파일 경로(__DIR__ 기준)로 찾는다.
if (! class_exists(ProcessOutputEncoding::class, false)) {
    $processOutputEncodingPath = dirname(__DIR__, 3).'/app/Support/ProcessOutputEncoding.php';
    if (is_file($processOutputEncodingPath)) {
        require_once $processOutputEncodingPath;
    }
    unset($processOutputEncodingPath);
}

if (! function_exists('installer_utf8_normalize')) {
    /**
     * 문자열 하나를 항상 유효한 UTF-8 로 만듭니다.
     *
     * @param  string  $value  정규화할 원본 문자열
     * @return string 유효한 UTF-8 문자열
     */
    function installer_utf8_normalize(string $value): string
    {
        if (class_exists(ProcessOutputEncoding::class)) {
            return ProcessOutputEncoding::normalize($value);
        }

        // 코어 파일이 없는 비정상 배치에서도 응답은 살린다.
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }
}

if (! function_exists('installer_utf8_normalize_deep')) {
    /**
     * 배열 트리 전체(키 포함)를 유효한 UTF-8 로 만듭니다.
     *
     * @param  mixed  $value  정규화할 값
     * @return mixed 정규화된 값
     */
    function installer_utf8_normalize_deep(mixed $value): mixed
    {
        if (class_exists(ProcessOutputEncoding::class)) {
            return ProcessOutputEncoding::normalizeDeep($value);
        }

        if (is_string($value)) {
            return installer_utf8_normalize($value);
        }
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[is_string($key) ? installer_utf8_normalize($key) : $key] = installer_utf8_normalize_deep($item);
        }

        return $normalized;
    }
}

if (! function_exists('installer_utf8_invalid_paths')) {
    /**
     * invalid UTF-8 이 남아 있는 키 경로 목록을 반환합니다.
     *
     * @param  mixed  $value  검사할 값
     * @return list<string> 점 구분 키 경로 목록
     */
    function installer_utf8_invalid_paths(mixed $value): array
    {
        if (class_exists(ProcessOutputEncoding::class)) {
            return ProcessOutputEncoding::invalidPaths($value);
        }

        return [];
    }
}

if (! function_exists('installer_normalize_process_output')) {
    /**
     * 외부 프로세스·환경값을 정규화하고, 정규화가 실제로 필요했으면 설치 로그에 1회 남깁니다.
     *
     * 제보 사례(gnuboard/g7#62)의 가장 큰 어려움은 로그에 흔적이 0 이었다는 점이다.
     * 운영자가 "이 서버는 계정명이 UTF-8 이 아니다" 를 로그만으로 알 수 있어야 한다.
     *
     * @param  string  $value  원본 값
     * @param  string  $source  값의 출처 라벨 (예: 'whoami')
     * @return string 유효한 UTF-8 값
     */
    function installer_normalize_process_output(string $value, string $source): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $normalized = installer_utf8_normalize($value);

        // 같은 출처를 요청당 한 번만 기록한다 (getWebServerUser 는 한 응답에서 여러 번 호출된다).
        static $reported = [];
        if (! isset($reported[$source])) {
            $reported[$source] = true;
            $notice = '[env] '.$source.' output was not UTF-8 — normalized (os: '.PHP_OS_FAMILY.
                ', oem cp: '.(function_exists('sapi_windows_cp_get') ? (string) sapi_windows_cp_get('oem') : 'n/a').')';
            error_log($notice);
            if (function_exists('addLog')) {
                addLog($notice);
            }
        }

        return $normalized;
    }
}

if (! function_exists('installer_json_encode')) {
    /**
     * 인스톨러 응답 JSON 을 생성합니다. **절대 false 나 빈 문자열을 반환하지 않습니다.**
     *
     * 1) 값 전체를 유효 UTF-8 로 정규화하고
     * 2) `JSON_INVALID_UTF8_SUBSTITUTE` 를 덧붙여 인코딩하며
     * 3) 그래도 실패하면 실패 사유와 문제 필드 경로를 로그에 남기고
     *    파싱 가능한 오류 JSON 을 반환한다.
     *
     * @param  mixed  $data  직렬화할 값
     * @param  int  $flags  추가 json_encode 플래그
     * @param  array  $fallbackShape  3) 의 오류 JSON 에 함께 실을 키 (호출자 계약 보존용).
     *                                폴링 응답처럼 소비자가 특정 키를 반드시 읽어야 하는
     *                                엔드포인트는 그 최소 형태를 여기로 넘긴다.
     * @return string 항상 파싱 가능한 JSON 문자열
     */
    function installer_json_encode(mixed $data, int $flags = JSON_UNESCAPED_UNICODE, array $fallbackShape = []): string
    {
        $normalized = installer_utf8_normalize_deep($data);

        // json-encode:allow — 이 함수가 인스톨러의 유일한 json_encode 자리다.
        $encoded = json_encode($normalized, $flags | JSON_INVALID_UTF8_SUBSTITUTE);

        if (is_string($encoded) && $encoded !== '') {
            return $encoded;
        }

        $reason = json_last_error_msg();
        $paths = installer_utf8_invalid_paths($data);
        $message = '[installer-json] encode failed ('.$reason.')'.
            ($paths === [] ? '' : ' at: '.implode(', ', array_slice($paths, 0, 20)));

        error_log($message);
        if (function_exists('addLog')) {
            addLog($message);
        }

        // 호출자가 넘긴 최소 형태를 먼저 깔고 그 위에 오류 정보를 얹는다.
        // (소비자가 반드시 읽는 키 — 예: 폴링의 status — 가 폴백에서도 살아 있어야 한다.)
        // json-encode:allow — 폴백 자체는 정규화된 ASCII 라 실패할 수 없다.
        $fallback = json_encode(array_merge(
            installer_utf8_normalize_deep($fallbackShape),
            [
                'success' => false,
                'error' => 'json_encode_failed',
                'message' => $reason,
                'invalid_paths' => array_slice($paths, 0, 20),
            ]
        ), JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($fallback) && $fallback !== ''
            ? $fallback
            : '{"success":false,"error":"json_encode_failed"}';
    }
}
