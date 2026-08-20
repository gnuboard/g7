<?php

/**
 * 인스톨러가 실행할 바이너리 경로의 허용 형태를 정의하는 공용 정책.
 *
 * 인스톨러는 운영자가 입력한 PHP/Composer 경로를 `exec()` 로 실행한다. `escapeshellarg()`
 * 는 "이 문자열이 하나의 인자" 임만 보장할 뿐, 그 인자가 실행 파일인지 코드 실행 플래그인지
 * 인터프리터가 실행할 스크립트인지는 보장하지 않는다. 실제로 Composer 칸에
 * `PHP경로 <임의스크립트>` 를 넣으면 그 스크립트가 그대로 실행된다(명령 끝의 `--version`
 * 은 스크립트 인자로 넘어갈 뿐이다).
 *
 * 따라서 셸 메타문자 차단 위에 **인자 자리를 닫는 순수 문자열 규칙**을 얹는다.
 *
 *  1. 하이픈 선두 토큰 거부 (양쪽 토큰) — `-r` `-c` `--define` 등 코드 실행 플래그 차단
 *  2. Composer 자리(둘째 토큰·단일 토큰)만 이름 형태 제한 — composer 계열 또는 `.phar`
 *  3. PHP 자리는 이름을 제한하지 않음 — 래퍼 스크립트·버전 붙은 이름·커스텀 이름 허용
 *  4. 상대경로·`..` 세그먼트 거부 — CWD 에 의존한 실행 차단
 *
 * 파일 시스템 stat(`is_file`/`is_executable`)은 호출하지 않는다. 시놀로지 DSM 등
 * `open_basedir` 가 시스템 binary 영역을 차단하는 환경에서 정상 절대경로가 false negative
 * 로 거부되는 회귀가 있었고(#361), 그 회귀를 구조적으로 재발 불가하게 두기 위함이다.
 * 실제 실행 가능 여부는 exec 결과로 최종 판정한다.
 *
 * 프레임워크·설정에 의존하지 않는다 — 인스톨러 API(라이브러리 모드)와 설치 워커
 * (`task-runner.php`) 양쪽에서 단독으로 `require_once` 할 수 있어야 한다.
 */
if (! function_exists('installer_binary_path_shape_ok')) {
    /**
     * 실행 경로 토큰이 허용 형태인지 판정합니다 (자리 공통 규칙).
     *
     * @param  string  $token  단일 경로 토큰
     * @return bool 허용 형태면 true
     */
    function installer_binary_path_shape_ok(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        // 셸 메타문자·제어문자 차단. 백슬래시는 Windows 경로 구분자이므로 허용한다.
        if (preg_match('/[\s;`$|<>"\'&\x00-\x1F]/', $token)) {
            return false;
        }

        // 하이픈 선두는 실행 파일 경로가 아니라 옵션이다 — 이번 취약점을 닫는 핵심 규칙.
        if ($token[0] === '-') {
            return false;
        }

        // `#` 이후를 주석으로 흘려 `.phar` 접미사를 위장하는 트릭 차단.
        if (str_contains($token, '#')) {
            return false;
        }

        // `..` 세그먼트 차단 (CWD 상대 이동).
        if (preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $token)) {
            return false;
        }

        // bare 명령(PATH 탐색)이 아니면 절대경로여야 한다.
        if ($token === 'php' || $token === 'composer') {
            return true;
        }

        return installer_binary_path_is_absolute($token);
    }
}

if (! function_exists('installer_binary_path_is_absolute')) {
    /**
     * 절대경로인지 판정합니다 (POSIX / Windows 드라이브 / UNC).
     *
     * @param  string  $token  경로 토큰
     * @return bool 절대경로면 true
     */
    function installer_binary_path_is_absolute(string $token): bool
    {
        if ($token[0] === '/') {
            return true;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $token)) {
            return true;
        }

        return str_starts_with($token, '\\\\');
    }
}

if (! function_exists('installer_is_composer_binary_path')) {
    /**
     * Composer 자리에 올 수 있는 경로인지 판정합니다.
     *
     * 이 자리는 인터프리터가 실행하는 스크립트가 되므로 이름 형태를 제한한다.
     * 제한이 없으면 `PHP경로 /tmp/올려둔파일` 형태가 그대로 남는다.
     *
     * @param  string  $token  경로 토큰
     * @return bool Composer 계열이면 true
     */
    function installer_is_composer_binary_path(string $token): bool
    {
        if (! installer_binary_path_shape_ok($token)) {
            return false;
        }

        $basename = basename(str_replace('\\', '/', $token));

        if (preg_match('/^composer[0-9]*(\.[0-9]+)*(\.phar|\.exe|\.bat|\.cmd)?$/i', $basename)) {
            return true;
        }

        return (bool) preg_match('/\.phar$/i', $basename);
    }
}

if (! function_exists('installer_resolve_php_composer_pair')) {
    /**
     * 공백 분리 입력을 (PHP 인터프리터, Composer 바이너리) 두 토큰으로 해석합니다.
     *
     * 멀티 PHP 환경(시놀로지 DSM Web Station, cPanel/Plesk multi-PHP)에서 특정 PHP 로
     * Composer 를 실행하려는 운영 의도를 계속 지원한다. 자리별 규칙을 모두 만족할 때만
     * 해석에 성공한다.
     *
     * 공백이 포함된 디렉토리(`C:\Program Files\...`)는 토큰 분리 휴리스틱과 충돌해
     * 지원하지 않는다 — `#361` 이 known_limitation 으로 명시한 기존 한계이며 이번 정책이
     * 새로 만드는 제약이 아니다.
     *
     * @param  string  $raw  공백으로 구분된 원본 입력
     * @return array{php: string, composer: string}|null 해석 실패 시 null
     */
    function installer_resolve_php_composer_pair(string $raw): ?array
    {
        $raw = trim($raw);

        if (! str_contains($raw, ' ')) {
            return null;
        }

        $tokens = preg_split('/\s+/', $raw, 2);

        if (! is_array($tokens) || count($tokens) !== 2) {
            return null;
        }

        [$php, $composer] = $tokens;

        // PHP 자리는 이름을 검사하지 않는다 — 인자가 고정되어 있어 코드 실행이 되지 않고,
        // 설치 환경마다 실행 파일 이름이 다르다(래퍼 스크립트·버전 접미사·커스텀 이름).
        if (! installer_binary_path_shape_ok($php)) {
            return null;
        }

        if (! installer_is_composer_binary_path($composer)) {
            return null;
        }

        return ['php' => $php, 'composer' => $composer];
    }
}
