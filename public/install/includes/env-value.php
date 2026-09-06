<?php

/**
 * 인스톨러 .env 값 직렬화 정책.
 *
 * `.env` 는 줄 단위 형식이라 값에 개행이 섞이면 그 뒤가 새로운 환경변수 줄로 해석된다.
 * 그래서 사용자 입력에서 온 모든 값은 이 파일의 serializeEnvValue() 한 관문을 지난다.
 * 관문이 하나면 나중에 필드가 늘어도 그 값이 자동으로 같은 검사를 받는다.
 *
 * 개행을 조용히 지우지 않고 예외로 거부하는 이유: 삭제하면 운영자가 입력한 값과 실제로
 * 저장된 값이 달라지는데 그 사실이 화면에 나타나지 않는다. 정상 입력에는 개행이 들어갈
 * 일이 없으므로 거부가 안전한 기본값이다. (KVE-2026-2042)
 *
 * 이 파일은 의존성이 없어야 한다 — 인스톨러 본 흐름(functions.php)과 설치 워커
 * (installer-runtime.php) 양쪽에서 단독으로 require 된다.
 */
if (! function_exists('installer_env_value_is_single_line')) {
    /**
     * 값이 한 줄인지(개행·NUL 이 없는지) 확인합니다.
     *
     * 서버측 사전 거부(폼 검증)에서 예외를 던지지 않고 판정만 필요할 때 씁니다.
     *
     * @param  string  $value  검사할 값
     * @return bool 개행·NUL 이 없으면 true
     */
    function installer_env_value_is_single_line(string $value): bool
    {
        return strpbrk($value, "\r\n\0") === false;
    }
}

if (! function_exists('serializeEnvValue')) {
    /**
     * .env 에 기록할 값을 직렬화합니다.
     *
     * CR/LF/NUL 이 포함되면 InvalidArgumentException 을 던집니다(조용한 삭제 금지).
     * 그 외에는 백슬래시·큰따옴표를 이스케이프하고 큰따옴표로 감쌉니다.
     *
     * @param  string  $value  직렬화할 값
     * @return string 큰따옴표로 감싼 값
     *
     * @throws InvalidArgumentException 값에 개행 또는 NUL 이 포함된 경우
     */
    function serializeEnvValue(string $value): string
    {
        if (! installer_env_value_is_single_line($value)) {
            throw new InvalidArgumentException(
                'Environment values must not contain line breaks or NUL bytes.'
            );
        }

        if ($value === '') {
            return '""';
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"'.$escaped.'"';
    }
}
