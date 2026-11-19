<?php

namespace App\Support;

/**
 * 확장이 다른 확장에 공개하는 PHP 표면 (`public_api`)
 *
 * 모듈·플러그인은 manifest 에 다른 확장이 써도 되는 PHP 네임스페이스·클래스를 선언합니다. 그 밖의 클래스(Model·Service·
 * Repository 등)는 내부 구현이라 다른 확장이 import·생성·정적 호출하면 안 됩니다. 정적 검사가 이 선언으로 경계를 판정하고,
 * 관리자 확장 상세 화면 「공개 API」 칸이 목록을 보여 줍니다. 코어는 런타임에 강제하지 않습니다.
 *
 * 선언 모양:
 * ```json
 * "public_api": {
 *   "version": 1,
 *   "namespaces": ["Modules\\Vendor\\Shop\\Contracts", "Modules\\Vendor\\Shop\\Enums"],
 *   "classes": ["Modules\\Vendor\\Shop\\Support\\ShopPathResolver"],
 *   "readonly_models": ["Modules\\Vendor\\Shop\\Models\\Order"]
 * }
 * ```
 *
 * - `namespaces`: 그 네임스페이스와 하위 전체가 공개.
 * - `classes`: 하나씩 공개하는 클래스.
 * - `readonly_models`: 훅이 인자로 넘기는 Model. 다른 확장은 타입힌트·`instanceof`·속성 읽기만 할 수 있고, 그 Model 로
 *   조회·저장·관계 호출은 할 수 없다(쓰기는 공개 계약 메서드로).
 *
 * 모든 항목은 확장 자기 네임스페이스 하위여야 합니다. 형식이 틀리거나 남의 네임스페이스를 가리키는 항목은 버립니다.
 *
 * @since 7.2.0
 */
class PublicApiManifest
{
    /** 지원하는 선언 판 */
    public const VERSION = 1;

    /** 목록 키 */
    public const LIST_KEYS = ['namespaces', 'classes', 'readonly_models'];

    /** PHP 정규화 클래스 이름(역슬래시 구분 식별자, 앞 역슬래시 없음) */
    private const FQCN_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/';

    /**
     * manifest 의 `public_api` 선언을 정규화합니다.
     *
     * @param  mixed  $raw  manifest 값
     * @param  string  $ownNamespace  확장 루트 네임스페이스(예: `Modules\Sirsoft\Ecommerce`)
     * @return array{version: int, namespaces: array<int, string>, classes: array<int, string>, readonly_models: array<int, string>}|null
     *                                                                                                                                    선언이 없거나 판이 다르면 null
     */
    public static function normalize(mixed $raw, string $ownNamespace): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $version = $raw['version'] ?? self::VERSION;
        if (! is_int($version) || $version !== self::VERSION) {
            return null;
        }

        $root = trim($ownNamespace, '\\');
        $normalized = ['version' => self::VERSION];
        foreach (self::LIST_KEYS as $key) {
            $normalized[$key] = self::normalizeList($raw[$key] ?? [], $root);
        }

        return $normalized;
    }

    /**
     * FQCN 이 정규화한 선언의 공개 범위(`namespaces`·`classes`)에 드는지 판정합니다.
     *
     * `readonly_models` 는 공개 범위가 아닙니다 — 읽기 전용 허용은 별도 판정입니다.
     *
     * @param  array{namespaces?: array<int, string>, classes?: array<int, string>}|null  $publicApi  정규화한 선언
     * @param  string  $fqcn  판정할 클래스
     * @return bool 공개 여부
     */
    public static function isPublic(?array $publicApi, string $fqcn): bool
    {
        if ($publicApi === null) {
            return false;
        }

        $fqcn = ltrim($fqcn, '\\');
        if (in_array($fqcn, $publicApi['classes'] ?? [], true)) {
            return true;
        }

        foreach ($publicApi['namespaces'] ?? [] as $namespace) {
            if ($fqcn === $namespace || str_starts_with($fqcn, $namespace.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 목록 하나를 정규화합니다 — 문자열 FQCN, 자기 네임스페이스 하위만, 중복 제거.
     *
     * @param  mixed  $list  선언 목록
     * @param  string  $root  확장 루트 네임스페이스
     * @return array<int, string> 정규화한 목록
     */
    private static function normalizeList(mixed $list, string $root): array
    {
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item, '\\ ');
            if (preg_match(self::FQCN_PATTERN, $item) !== 1) {
                continue;
            }
            if ($root === '' || ! str_starts_with($item, $root.'\\')) {
                continue;
            }
            $out[$item] = true;
        }

        return array_keys($out);
    }
}
