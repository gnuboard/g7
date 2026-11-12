<?php

namespace App\Support\FileServing;

/**
 * 웹서버 파일 위임(X-Sendfile / X-Accel-Redirect) 설정 해석기
 *
 * 로컬 파일 응답을 PHP 가 읽어 보내는 대신 웹서버가 직접 보내게 하는 기능의 판정을 단독으로 소유합니다.
 * 모드는 **운영자 설정**(`core.file_serving.delegation` — 환경설정 > 드라이버 또는 `.env` G7_FILE_DELEGATION)
 * 으로만 정해집니다. 요청 헤더 `X-Sendfile-Type`·`X-Accel-Mapping` 은 보지 않습니다 — 그 헤더는 방문자가
 * 위조할 수 있어, 믿으면 임의 경로를 웹서버에 넘기는 표면이 됩니다.
 *
 * nginx 매핑(`X-Accel-Mapping`)은 운영자가 적지 않습니다. 앱이 `realpath(base_path())` 와 접두사로 만들고,
 * 그 결과를 화면 안내(`describe()`)에도 그대로 실어 웹서버 설정에 복사할 수 있게 합니다.
 *
 * 해석 결과는 memoize 하지 않습니다 — 설정 저장·테스트의 `Config::set` 이 즉시 반영되어야 합니다.
 *
 * @since 7.2.0
 */
final class FileDelegation
{
    /**
     * 위임하지 않음 (기본) — PHP 가 파일을 읽어 보냅니다.
     */
    public const MODE_NONE = 'none';

    /**
     * Apache mod_xsendfile 등 — 응답 헤더 `X-Sendfile: <절대 경로>`.
     */
    public const MODE_X_SENDFILE = 'x-sendfile';

    /**
     * nginx — 응답 헤더 `X-Accel-Redirect: <internal 위치 URI>`.
     */
    public const MODE_X_ACCEL_REDIRECT = 'x-accel-redirect';

    /**
     * nginx internal 위치의 기본 접두사.
     *
     * 추측하기 어려운 이름을 쓴다 — `internal;` 을 빠뜨린 설정에서 이 경로가 바깥에 열려도 쉽게 찾지 못하게 한다.
     */
    public const DEFAULT_ACCEL_PREFIX = '/_g7_sendfile/';

    /**
     * 접두사 형식 — `/` 로 시작하고 끝나며, 세그먼트는 영숫자와 `._-` 만. 점만으로 된 세그먼트(`.`·`..`)는 막는다.
     */
    public const ACCEL_PREFIX_PATTERN = '#^/(?!\.+/)[A-Za-z0-9._-]+(/(?!\.+/)[A-Za-z0-9._-]+)*/$#';

    /**
     * 진단 응답 헤더 이름 — 앱이 위임 헤더를 세웠는지(`applied`) 세우지 못했는지(`skipped`).
     */
    public const DIAGNOSTIC_HEADER = 'X-G7-File-Delegation';

    /**
     * 선택할 수 있는 모드 전체 (화면 선택지·검증의 단일 출처).
     *
     * @return list<string> 모드 목록
     */
    public static function modes(): array
    {
        return [self::MODE_NONE, self::MODE_X_SENDFILE, self::MODE_X_ACCEL_REDIRECT];
    }

    /**
     * 지금 모드를 반환합니다. 모르는 값·빈 값은 `none` 입니다.
     *
     * @return string 모드
     */
    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('core.file_serving.delegation', self::MODE_NONE)));

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_NONE;
    }

    /**
     * 위임이 켜져 있는지 반환합니다.
     *
     * @return bool 켜져 있으면 true
     */
    public static function active(): bool
    {
        return self::mode() !== self::MODE_NONE;
    }

    /**
     * Symfony `BinaryFileResponse::prepare()` 가 읽는 `X-Sendfile-Type` 값을 반환합니다.
     *
     * @return string|null `X-Sendfile`·`X-Accel-Redirect`, 꺼져 있으면 null
     */
    public static function sendfileType(): ?string
    {
        return match (self::mode()) {
            self::MODE_X_SENDFILE => 'X-Sendfile',
            self::MODE_X_ACCEL_REDIRECT => 'X-Accel-Redirect',
            default => null,
        };
    }

    /**
     * nginx internal 위치 접두사를 반환합니다. 형식에 맞지 않으면 기본값입니다.
     *
     * @return string 접두사 (`/` 로 시작하고 끝남)
     */
    public static function accelPrefix(): string
    {
        $prefix = self::normalizeAccelPrefix((string) config('core.file_serving.accel_prefix', self::DEFAULT_ACCEL_PREFIX));

        return preg_match(self::ACCEL_PREFIX_PATTERN, $prefix) === 1 ? $prefix : self::DEFAULT_ACCEL_PREFIX;
    }

    /**
     * 운영자가 적은 접두사를 다듬습니다 — 앞뒤 공백을 떼고, 비어 있지 않으면 끝에 `/` 를 채웁니다.
     *
     * 저장 검증·설정 점검 검증·설정 주입·점검 결과가 모두 이 한 규칙을 쓴다 — 사본이 갈리면 같은 값이
     * 저장은 되고 점검은 형식 오류가 되는 식으로 화면마다 다르게 판정된다. 형식 판정은 하지 않는다
     * (`ACCEL_PREFIX_PATTERN` 이 맡는다).
     *
     * @param  string  $prefix  입력 접두사
     * @return string 다듬은 접두사 (빈 입력은 빈 문자열)
     */
    public static function normalizeAccelPrefix(string $prefix): string
    {
        $prefix = trim($prefix);

        return ($prefix !== '' && ! str_ends_with($prefix, '/')) ? $prefix.'/' : $prefix;
    }

    /**
     * 웹서버에 알려 줄 설치 위치의 실경로를 반환합니다 (심볼릭 링크를 푼 값).
     *
     * @return string 설치 위치 실경로 (끝 구분자 없음)
     */
    public static function basePath(): string
    {
        $real = realpath(base_path());

        return rtrim($real !== false ? $real : base_path(), '/\\');
    }

    /**
     * `X-Accel-Mapping` 값을 만듭니다 — `<설치 위치 실경로>/=<접두사>`.
     *
     * 실경로 끝에 구분자를 붙여 두어야 접두사 뒤에 구분자가 두 번 오지 않는다(`/_g7_sendfile//storage`).
     * 템플릿·모듈·플러그인·storage 가 모두 설치 위치 아래라 매핑 하나로 덮는다.
     * 「설정 점검」은 저장 전 화면 값의 접두사로 같은 형식을 만들어 보여 준다(`$prefix`).
     *
     * @param  string|null  $prefix  정규화된 접두사 (null 이면 적용 중인 접두사)
     * @return string 매핑 문자열
     */
    public static function accelMapping(?string $prefix = null): string
    {
        return self::basePath().DIRECTORY_SEPARATOR.'='.($prefix ?? self::accelPrefix());
    }

    /**
     * 관리자 화면 `_meta.file_delegation` 에 실을 요약을 반환합니다.
     *
     * 화면은 이 값으로 웹서버 설정 안내(Apache `XSendFilePath`, nginx `alias`)를 실제 경로로 채운다. nginx 안내는
     * `nginx_alias_path`(구분자를 `/` 로 바꾼 값)를 쓴다 — nginx 설정 문법은 역슬래시를 이스케이프로 읽어 Windows 실경로
     * (`C:\…`)를 그대로 붙여 넣으면 `C:Users…` 로 망가진다(2026-11-12 로컬 nginx 실측).
     *
     * @return array{mode: string, base_path: string, nginx_alias_path: string, accel_prefix: string, accel_mapping: string} 요약
     */
    public static function describe(): array
    {
        return [
            'mode' => self::mode(),
            'base_path' => self::basePath(),
            'nginx_alias_path' => str_replace('\\', '/', self::basePath()),
            'accel_prefix' => self::accelPrefix(),
            'accel_mapping' => self::accelMapping(),
        ];
    }
}
