<?php

namespace App\Support\LayoutMarkup;

/**
 * 산출 JSON 에 실리는 저작 출처(provenance) `meta.authoring` 를 다루는 헬퍼 (S7).
 *
 * `.g7.html` 을 컴파일해 원 위치에 병존시키는 `.json` 은 손으로 쓴 레이아웃과 파일 모양이
 * 똑같다. 설치 스캔이 둘을 구분하지 못하면, 같은 이름의 `.g7.html` 과 `.json` 이 나란히 있을 때
 * 어느 쪽이 진짜 SSoT 인지 알 수 없다(이중 SSoT). 그래서 산출 JSON 에 **어느 소스에서 왔는지**를
 * 표식으로 남긴다:
 *
 *   meta.authoring = { source, source_hash, compiler_version }
 *
 * 이 표식은 두 곳에서 쓰인다.
 *
 *  1. **설치 시 공존 판별** — `.g7.html` 옆의 `.json` 이 그 소스에서 컴파일된 것이면
 *     `source_hash` 가 현재 소스와 일치한다. 불일치(소스 편집 후 미재컴파일)하거나 표식이 아예
 *     없으면(손으로 쓴 중복), 두 SSoT 가 다투는 상태이므로 설치가 거부한다.
 *  2. **편집기 drift 마커** — DB 에 실린 `meta.authoring` 은 "이 레이아웃은 `.g7.html` 에서
 *     왔다" 는 뜻이다. 운영자가 편집기에서 저장하면 이 키가 화이트리스트 밖이라 자연히 탈락하고,
 *     그 **부재**가 "소스에서 갈라졌다" 는 신호가 된다.
 *
 * 컴파일러 자체(`LayoutMarkupCompiler::compile`)는 이 표식을 붙이지 않는다 — 그래야 컴파일 산출과
 * 골든 JSON 을 있는 그대로 deep-equal 비교하는 fixture·왕복 하네스가 깨지지 않는다. 표식은 산출을
 * **파일로 쓸 때**(레지스트리 커맨드)만 얹고, 비교·왕복은 항상 strip 후 수행한다.
 */
final class LayoutProvenance
{
    /**
     * provenance 표식의 봉투 키.
     */
    public const META_KEY = 'authoring';

    /**
     * 컴파일러 계약 버전. 산출 JSON 의 해석 규칙이 바뀌면 올린다.
     */
    public const COMPILER_VERSION = '1.0.0';

    /**
     * `.g7.html` 소스의 정규화 해시를 계산합니다.
     *
     * 토크나이저와 같은 전처리(BOM 제거 + CRLF→LF)를 먼저 적용한다. 그렇지 않으면 Windows 에서
     * 저작한 파일의 `\r` 때문에 같은 내용이 플랫폼마다 다른 해시를 내어, 편집도 안 했는데 공존
     * 판별이 거짓 불일치(drift)를 보고한다.
     *
     * @param  string  $source  `.g7.html` 원문
     * @return string 정규화된 소스의 sha256 해시
     */
    public static function hash(string $source): string
    {
        return hash('sha256', self::normalize($source));
    }

    /**
     * 산출 JSON 에 provenance 표식을 얹습니다.
     *
     * `meta` 의 다른 키(is_partial·seo·editor_label 등)는 그대로 두고 `authoring` 만 더한다.
     * 결정적 직렬화를 위해 `meta` 는 항상 산출의 자연스러운 위치(다른 최상위 키 사이)에 남는다.
     *
     * @param  array<string, mixed>  $json  컴파일 산출 JSON
     * @param  string  $source  `.g7.html` 원문 (해시 계산용)
     * @param  string  $relativeSource  base_path 기준 소스 상대 경로 (슬래시 정규화됨)
     * @return array<string, mixed> 표식이 얹힌 JSON
     */
    public static function stamp(array $json, string $source, string $relativeSource): array
    {
        $meta = $json['meta'] ?? [];
        $meta[self::META_KEY] = [
            'source' => $relativeSource,
            'source_hash' => self::hash($source),
            'compiler_version' => self::COMPILER_VERSION,
        ];
        $json['meta'] = $meta;

        return $json;
    }

    /**
     * 산출 JSON 에서 provenance 표식을 걷어냅니다.
     *
     * `authoring` 만 없앤 뒤, 표식 때문에 생겼던 빈 `meta` 는 원래 모양대로 되돌리려 함께 없앤다
     * (표식을 얹기 전에 `meta` 가 없던 레이아웃과 deep-equal 이 성립하도록).
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @return array<string, mixed> 표식이 제거된 JSON
     */
    public static function strip(array $json): array
    {
        if (! isset($json['meta']) || ! is_array($json['meta'])) {
            return $json;
        }

        unset($json['meta'][self::META_KEY]);

        if ($json['meta'] === []) {
            unset($json['meta']);
        }

        return $json;
    }

    /**
     * 산출 JSON 의 표식이 주어진 `.g7.html` 소스에서 컴파일된 것인지 판별합니다.
     *
     * 표식이 없거나(손으로 쓴 중복) 해시가 다르면(소스 편집 후 미재컴파일) false.
     *
     * @param  array<string, mixed>  $json  공존 후보 `.json` 데이터
     * @param  string  $source  `.g7.html` 원문
     * @return bool 표식이 이 소스의 컴파일 산출이면 true
     */
    public static function matches(array $json, string $source): bool
    {
        $recorded = $json['meta'][self::META_KEY]['source_hash'] ?? null;

        return is_string($recorded) && hash_equals(self::hash($source), $recorded);
    }

    /**
     * 산출 JSON 에 provenance 표식이 있는지 반환합니다.
     *
     * @param  array<string, mixed>  $json  `.json` 데이터
     * @return bool 표식이 있으면 true
     */
    public static function isStamped(array $json): bool
    {
        return isset($json['meta'][self::META_KEY]);
    }

    /**
     * 토크나이저와 동일한 전처리(BOM 제거 + CRLF/CR → LF)를 적용합니다.
     *
     * @param  string  $source  원문
     * @return string 정규화된 소스
     */
    private static function normalize(string $source): string
    {
        // UTF-8 BOM 제거
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            $source = substr($source, 3);
        }

        // CRLF·CR → LF
        return str_replace(["\r\n", "\r"], "\n", $source);
    }
}
