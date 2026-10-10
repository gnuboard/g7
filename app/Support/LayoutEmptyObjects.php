<?php

namespace App\Support;

use stdClass;

/**
 * 레이아웃 JSON 의 빈 객체 `{}` 보존 보조
 *
 * 레이아웃 content 는 `array` 캐스트(연관 배열 디코드)로 읽혀 빈 객체 `{}` 가 빈 배열 `[]` 과
 * 구별되지 않는다. 그대로 응답하면 원본·DB 행이 `{}` 인 자리가 `[]` 로 나가, 화면이
 * `_local.expandedNotifications` 같은 키-값 상태를 배열로 받는다(7.1.0 실측 O-K3-4).
 *
 * 병합·확장 적용 단계의 PHP 코드는 섹션·구조 키(`props`, `params` 자체, `initLocal` 자체 등)를
 * 배열로 다루므로 그 자리는 배열로 둔다. 빈 객체를 stdClass 로 보존하는 곳은 PHP 가 해석하지
 * 않고 화면에 그대로 넘기는 「값 자리」뿐이다.
 *
 * - `initLocal`·`initGlobal`·`initIsolated`·`state` 섹션의 값과 그 아래
 * - 어느 깊이든 `params`·`fallback` 키의 값 아래 (키 자신의 값이 빈 객체면 배열로 둔다)
 *
 * 섹션 자체가 비어 있으면(`"meta": {}` 등) 응답 직전 {@see restoreRootMapSections()} 가 `{}` 로 돌려놓는다.
 */
final class LayoutEmptyObjects
{
    /**
     * 값이 화면 상태 초기값인 루트 섹션 — 이 섹션 안의 빈 객체를 보존한다.
     *
     * @var list<string>
     */
    private const VALUE_SECTIONS = ['initLocal', 'initGlobal', 'initIsolated', 'state'];

    /**
     * 이 키의 값 아래 빈 객체를 보존한다 (액션 인자, 데이터 소스 대체값).
     *
     * @var list<string>
     */
    private const VALUE_KEYS = ['params', 'fallback'];

    /**
     * 키-값 맵으로 정의된 루트 섹션 — 비어 있으면 응답에서 `{}` 로 낸다.
     *
     * @var list<string>
     */
    private const ROOT_MAP_SECTIONS = [
        'meta', 'initLocal', 'initGlobal', 'initIsolated', 'state', 'computed', 'defines', 'named_actions',
    ];

    /**
     * 저장된 JSON 원문을 연관 배열로 읽되, 값 자리의 빈 객체는 stdClass 로 보존합니다.
     *
     * @param  mixed  $raw  저장된 JSON 원문 (모델의 raw attribute)
     * @param  array  $fallback  원문을 읽지 못할 때 쓸 값 (모델 캐스트 결과)
     * @return array 디코드된 레이아웃
     */
    public static function decode(mixed $raw, array $fallback): array
    {
        if (! is_string($raw) || $raw === '') {
            return $fallback;
        }

        $decoded = json_decode($raw);

        if (! $decoded instanceof stdClass) {
            return $fallback;
        }

        $result = [];

        foreach (get_object_vars($decoded) as $key => $value) {
            // 섹션 자체는 배열로 두고(병합 코드가 배열로 다룬다) 그 안의 값만 보존한다
            $result[$key] = self::convert($value, false, in_array($key, self::VALUE_SECTIONS, true));
        }

        return $result;
    }

    /**
     * 보존해 둔 빈 객체를 다시 빈 배열로 되돌립니다 (배열만 다루는 소비자용 — SEO 렌더러 등).
     *
     * @param  array  $layout  레이아웃
     * @return array 빈 객체가 모두 배열로 바뀐 레이아웃
     */
    public static function toArrays(array $layout): array
    {
        foreach ($layout as $key => $value) {
            if ($value instanceof stdClass) {
                $layout[$key] = self::toArrays(get_object_vars($value));
            } elseif (is_array($value)) {
                $layout[$key] = self::toArrays($value);
            }
        }

        return $layout;
    }

    /**
     * 비어 있는 루트 맵 섹션을 응답용 `{}` 로 바꿉니다.
     *
     * @param  array  $layout  병합된 레이아웃
     * @return array 빈 맵 섹션이 stdClass 로 바뀐 레이아웃
     */
    public static function restoreRootMapSections(array $layout): array
    {
        foreach (self::ROOT_MAP_SECTIONS as $section) {
            if (array_key_exists($section, $layout) && $layout[$section] === []) {
                $layout[$section] = new stdClass;
            }
        }

        return $layout;
    }

    /**
     * 디코드된 노드를 배열로 바꾸되, 값 자리의 빈 객체는 stdClass 로 둡니다.
     *
     * @param  mixed  $node  json_decode(객체 모드) 결과 노드
     * @param  bool  $preserveSelf  이 노드가 빈 객체일 때 보존할지 (노드가 값 자리에 있는지)
     * @param  bool  $childrenInValue  이 노드의 자식이 값 자리인지
     * @return mixed 변환된 노드
     */
    private static function convert(mixed $node, bool $preserveSelf, bool $childrenInValue): mixed
    {
        if ($node instanceof stdClass) {
            $vars = get_object_vars($node);

            if ($vars === [] && $preserveSelf) {
                return new stdClass;
            }

            $result = [];

            foreach ($vars as $key => $value) {
                // params·fallback 키는 자기 값은 구조로 두고 그 아래부터 값 자리로 본다
                $result[$key] = self::convert(
                    $value,
                    $childrenInValue,
                    $childrenInValue || in_array($key, self::VALUE_KEYS, true)
                );
            }

            return $result;
        }

        if (is_array($node)) {
            return array_map(static fn ($item) => self::convert($item, $childrenInValue, $childrenInValue), $node);
        }

        return $node;
    }
}
