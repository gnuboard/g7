<?php

namespace App\Support;

/**
 * 외부 프로세스 출력·환경값의 문자 인코딩 정규화 (인스톨러·코어 공용 SSoT).
 *
 * 인스톨러(`public/install/`)는 Laravel 오토로드 없이 동작하는 순수 PHP 이므로
 * 이 클래스는 파사드·헬퍼 등 프레임워크 의존성을 일절 참조하지 않는다.
 * 인스톨러는 `require_once` 로 이 파일을 직접 로드해 사용한다.
 *
 * 배경:
 *   `exec('whoami')` 같은 외부 명령은 UTF-8 이 아니라 **그 명령을 실행한 콘솔의
 *   코드페이지**로 출력한다. 한국어 Windows 는 OEM 949(CP949) 이므로 계정명에
 *   한글이 있으면 invalid UTF-8 바이트가 PHP 로 들어온다. 그 값이 응답 배열에 실리면
 *   `json_encode()` 가 `false` 를 반환하고 `echo false` 는 빈 문자열이라
 *   HTTP 200 + 빈 본문이 나간다 — 예외도 로그도 남지 않는다.
 *
 * 변환 순서는 손실이 적은 쪽부터다. 마지막 단계는 의미를 잃더라도
 * 응답 자체는 반드시 살린다.
 */
final class ProcessOutputEncoding
{
    /**
     * 1순위로 시도하는 확정 감지 대상 인코딩.
     *
     * G7 1차 대상 환경(한국어 Windows)을 OS 와 무관하게 같은 답으로 처리하기 위해
     * 플랫폼 분기보다 앞에 둔다. `mb_detect_encoding(strict)` 는 순수 KS X 1001 을
     * `EUC-KR` 로, 확장 한글을 `CP949` 로 판정하므로 두 이름을 모두 나열한다.
     *
     * @var list<string>
     */
    private const DETECT_ORDER = ['EUC-KR', 'CP949'];

    /**
     * 외부 프로세스 출력·환경값 하나를 항상 유효한 UTF-8 로 만듭니다.
     *
     * @param  string  $value  정규화할 원본 문자열
     * @return string 항상 유효한 UTF-8 문자열
     */
    public static function normalize(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // (1) 한국어 코드페이지 확정 감지 — OS 무관(테스트·Linux 에서도 같은 답).
        $detected = mb_detect_encoding($value, self::DETECT_ORDER, true);
        if ($detected !== false) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $detected);
            if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        // (2) Windows: 그 출력을 만든 콘솔(OEM)·시스템(ANSI) 코드페이지로 변환.
        //     일본어(932)·중국어(936/950)·서유럽(437/850) 등을 덮는다.
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_conv') && function_exists('sapi_windows_cp_get')) {
            foreach ([sapi_windows_cp_get('oem'), sapi_windows_cp_get('ansi')] as $codepage) {
                if ($codepage === 0 || $codepage === 65001) {
                    continue;
                }

                $converted = @sapi_windows_cp_conv($codepage, 65001, $value);
                if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                    return $converted;
                }
            }
        }

        // (3) 최종 방어 — 의미는 잃어도 응답은 살린다.
        return mb_scrub($value, 'UTF-8');
    }

    /**
     * 배열 트리 전체(키 포함)에 정규화를 적용합니다.
     *
     * 문자열이 아닌 스칼라(int/float/bool/null)와 객체는 그대로 둡니다.
     *
     * @param  mixed  $value  정규화할 값 (배열이면 재귀)
     * @return mixed 정규화된 값
     */
    public static function normalizeDeep(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::normalize($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? self::normalize($key) : $key;
            $normalized[$normalizedKey] = self::normalizeDeep($item);
        }

        return $normalized;
    }

    /**
     * invalid UTF-8 문자열이 남아 있는 키 경로 목록을 반환합니다.
     *
     * 인코딩 실패를 로그로 남길 때 "어느 필드가 원인인가" 를 운영자에게 알려주는 용도입니다.
     * 반환 형태는 `directories.web_server_user` 같은 점 구분 경로이며,
     * 스칼라 루트가 invalid 이면 빈 문자열 경로 하나를 돌려줍니다.
     *
     * @param  mixed  $value  검사할 값
     * @param  string  $prefix  현재까지의 키 경로 (재귀용)
     * @return list<string> invalid UTF-8 이 발견된 키 경로 목록
     */
    public static function invalidPaths(mixed $value, string $prefix = ''): array
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? [] : [$prefix];
        }

        if (! is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $key => $item) {
            $keyLabel = (string) $key;
            $path = $prefix === '' ? $keyLabel : $prefix.'.'.$keyLabel;

            // 키 자체가 invalid 이면 그 키도 encode 를 실패시킨다.
            if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                $paths[] = $path.' (key)';
            }

            foreach (self::invalidPaths($item, $path) as $nested) {
                $paths[] = $nested;
            }
        }

        return $paths;
    }
}
