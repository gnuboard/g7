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
if (! function_exists('installer_binary_path_unquote')) {
    /**
     * 큰따옴표로 감싼 경로 토큰의 따옴표를 벗깁니다.
     *
     * Windows 의 기본 설치 위치(`C:\Program Files\PHP\php.exe`)는 공백을 포함해 형태 규칙의
     * 공백 차단에 걸린다. 그러면 입력이 조용히 무시되고 `'php'` 로 되돌아가, 운영자는
     * 자기가 넣은 경로가 왜 없는 셈이 되는지 알 수 없다.
     *
     * 그래서 **인용부호로 감싼 형태**를 받아 단일 토큰임을 명시하게 한다. 벗긴 뒤에도
     * 셸 메타문자·제어문자 검사는 그대로 받으므로(공백만 허용) 인용이 검사를 우회하는
     * 통로가 되지 않는다. 따옴표가 안쪽에 또 있으면 인용 구조가 모호해지므로 거부한다.
     *
     * @param  string  $token  원본 토큰
     * @return array{token: string, quoted: bool}|null 해석 결과 (구조가 모호하면 null)
     */
    function installer_binary_path_unquote(string $token): array
    {
        if (strlen($token) >= 2 && $token[0] === '"' && substr($token, -1) === '"') {
            $inner = substr($token, 1, -1);

            // 안쪽에 따옴표가 또 있으면 어디까지가 한 토큰인지 정할 수 없다.
            if (str_contains($inner, '"')) {
                return ['token' => $token, 'quoted' => false];
            }

            return ['token' => $inner, 'quoted' => true];
        }

        return ['token' => $token, 'quoted' => false];
    }
}

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

        $parsed = installer_binary_path_unquote($token);
        $value = $parsed['token'];

        if ($value === '') {
            return false;
        }

        // 셸 메타문자·제어문자 차단. 백슬래시는 Windows 경로 구분자이므로 허용한다.
        // 인용된 토큰은 **공백만** 추가로 허용한다 — 인용이 "여기까지가 한 토큰" 을
        // 명시하므로 토큰 분리 모호성이 사라진다. 나머지 메타문자·제어문자는 그대로 차단하므로
        // 인용이 검사를 우회하는 통로가 되지 않는다.
        $meta = $parsed['quoted']
            ? '/[;`$|<>"\'&\x00-\x1F]/'
            : '/[\s;`$|<>"\'&\x00-\x1F]/';

        if (preg_match($meta, $value)) {
            return false;
        }

        // 하이픈 선두는 실행 파일 경로가 아니라 옵션이다 — 이번 취약점을 닫는 핵심 규칙.
        if ($value[0] === '-') {
            return false;
        }

        // `#` 이후를 주석으로 흘려 `.phar` 접미사를 위장하는 트릭 차단.
        if (str_contains($value, '#')) {
            return false;
        }

        // `..` 세그먼트 차단 (CWD 상대 이동).
        if (preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $value)) {
            return false;
        }

        // bare 명령(PATH 탐색)이 아니면 절대경로여야 한다.
        if ($value === 'php' || $value === 'composer') {
            return true;
        }

        return installer_binary_path_is_absolute($value);
    }
}

if (! function_exists('installer_binary_path_is_absolute')) {
    /**
     * 절대경로인지 판정합니다 (POSIX / Windows 드라이브).
     *
     * UNC(`\\server\share`, `//server/share`)와 스트림 래퍼(`scheme://`)는 절대경로로
     * 인정하지 않는다. UNC 는 공격자가 지정한 원격 SMB 공유의 파일을 그대로 실행하게 만들고,
     * 스트림 래퍼는 로컬 파일이 아닌 것을 실행 대상으로 삼는다 (KVE-2026-2043).
     * 판정은 전부 순수 문자열 규칙이라 `open_basedir` 회귀(#361)와 무관하다.
     *
     * @param  string  $token  경로 토큰
     * @return bool 절대경로면 true
     */
    function installer_binary_path_is_absolute(string $token): bool
    {
        // 스트림 래퍼(`scheme://`) 거부. 단일 문자 스킴은 Windows 드라이브와 구분되지
        // 않으므로 두 글자 이상만 스킴으로 본다.
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]+://#', $token)) {
            return false;
        }

        // UNC 거부 — 슬래시/백슬래시 두 형태 모두.
        if (str_starts_with($token, '\\\\') || str_starts_with($token, '//')) {
            return false;
        }

        if ($token[0] === '/') {
            return true;
        }

        return (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $token);
    }
}

if (! function_exists('installer_is_composer_binary_path')) {
    /**
     * Composer 자리에 올 수 있는 경로인지 판정합니다.
     *
     * 이 자리는 인터프리터가 실행하는 스크립트가 되므로 이름 형태를 제한한다.
     * 제한이 없으면 `PHP경로 /tmp/올려둔파일` 형태가 그대로 남는다.
     *
     * 이름은 composer 계열만 허용한다. 임의 이름의 `.phar` 를 열어 두면 업로드해 둔
     * 아카이브를 그대로 실행시킬 수 있다 (KVE-2026-2043).
     *
     * @param  string  $token  경로 토큰
     * @return bool Composer 계열이면 true
     */
    function installer_is_composer_binary_path(string $token): bool
    {
        if (! installer_binary_path_shape_ok($token)) {
            return false;
        }

        $basename = basename(str_replace('\\', '/', installer_binary_path_unquote($token)['token']));

        return (bool) preg_match('/^composer[0-9]*(\.[0-9]+)*(\.phar|\.exe|\.bat|\.cmd)?$/i', $basename);
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
     * 공백이 포함된 디렉토리(`C:\Program Files\...`)는 **인용부호로 감싸** 전달한다 —
     * `"C:\Program Files\PHP\php.exe" "C:\composer\composer.phar"`. 인용이 없으면 종전대로
     * 공백을 토큰 경계로 본다(`#361` 이 명시한 기존 한계). Windows 기본 설치 위치가 공백을
     * 포함하므로, 인용 입력을 받지 않으면 그 환경은 입력이 조용히 무시되는 상태로 남는다.
     *
     * @param  string  $raw  공백으로 구분된 원본 입력 (각 토큰은 인용 가능)
     * @return array{php: string, composer: string}|null 해석 실패 시 null (값은 따옴표를 벗긴 실제 경로)
     */
    function installer_resolve_php_composer_pair(string $raw): ?array
    {
        $raw = trim($raw);

        if (! str_contains($raw, ' ')) {
            return null;
        }

        $tokens = installer_binary_path_tokenize($raw);

        if ($tokens === null || count($tokens) !== 2) {
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

        // 따옴표는 "여기까지가 한 토큰" 을 알리는 표기일 뿐이다 — 호출부는 실행 시
        // escapeshellarg 로 다시 인용하므로 실제 경로를 돌려준다.
        return [
            'php' => installer_binary_path_unquote($php)['token'],
            'composer' => installer_binary_path_unquote($composer)['token'],
        ];
    }
}

if (! function_exists('installer_binary_path_tokenize')) {
    /**
     * 입력을 공백 기준으로 토큰화하되 인용된 구간은 하나로 봅니다.
     *
     * @param  string  $raw  원본 입력
     * @return array<int, string>|null 토큰 목록 (인용부호 포함 보존). 인용이 닫히지 않으면 null
     */
    function installer_binary_path_tokenize(string $raw): ?array
    {
        $raw = trim($raw);
        $length = strlen($raw);
        $tokens = [];
        $index = 0;

        while ($index < $length) {
            while ($index < $length && $raw[$index] === ' ') {
                $index++;
            }

            if ($index >= $length) {
                break;
            }

            if ($raw[$index] === '"') {
                $close = strpos($raw, '"', $index + 1);

                if ($close === false) {
                    return null;
                }

                $tokens[] = substr($raw, $index, $close - $index + 1);
                $index = $close + 1;

                // 인용 뒤에 공백 없이 다른 글자가 붙으면 경계가 모호하다 (`"a"b`).
                if ($index < $length && $raw[$index] !== ' ') {
                    return null;
                }

                continue;
            }

            $space = strpos($raw, ' ', $index);
            $space = $space === false ? $length : $space;
            $tokens[] = substr($raw, $index, $space - $index);
            $index = $space;
        }

        return $tokens;
    }
}

if (! function_exists('installer_php_binary_from_state')) {
    /**
     * 설치 상태에 저장된 PHP 실행 경로를 돌려준다 (artisan 을 띄울 실행 파일).
     *
     * 설치 워커의 artisan 호출과 설치 완료 직후 .env 병합의 설정 캐시 다시 만들기가 같은 규칙을 쓴다.
     * 형태 규칙을 통과하지 못하면 시스템 기본값(php)으로 폴백한다 — 설치 흐름은 유지하되
     * 사용자 입력이 인자 자리로 흘러가지 않도록 한다.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return string PHP 실행 경로
     */
    function installer_php_binary_from_state(array $state): string
    {
        $phpBinary = (string) ($state['config']['php_binary'] ?? '');

        if ($phpBinary === '' || $phpBinary === 'php') {
            return 'php';
        }

        return installer_binary_path_shape_ok($phpBinary) ? $phpBinary : 'php';
    }
}
