<?php

namespace App\Support;

/**
 * 선택 연동 선언 (`integrations`) 정규화
 *
 * `dependencies` 는 「없으면 설치·활성화할 수 없다」이고, `integrations` 는 「있으면 연동되고 없어도 동작한다」입니다.
 * 예: 문자 발송 플러그인이 쇼핑몰·게시판의 알림 설정 화면에 탭을 끼우지만, 그 확장이 없어도 플러그인은 섭니다.
 * 설치를 막지 않고 관리자 확장 상세에 「함께 쓰면 연동되는 확장」으로 보이며, 정적 검사는 `dependencies` 에도
 * `integrations` 에도 없는 확장 참조를 오류로 봅니다.
 *
 * 모양: `{ "modules": ["sirsoft-ecommerce"], "plugins": [], "templates": [] }`
 *
 * @since 7.1.0
 */
class ExtensionIntegrations
{
    /** 연동 대상 유형 */
    public const TYPES = ['modules', 'plugins', 'templates'];

    /** 확장 식별자 규칙 (vendor-name) */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9][a-z0-9_]*-[a-z0-9][a-z0-9_]*$/';

    /**
     * manifest 의 `integrations` 선언을 정규화합니다. 모르는 유형·형식이 틀린 식별자는 버립니다.
     *
     * 객체 형식(`{"sirsoft-board": "설명"}`)도 받아 키를 식별자로 씁니다.
     *
     * @param  mixed  $raw  manifest 값
     * @return array{modules: array<int, string>, plugins: array<int, string>, templates: array<int, string>}
     *                                                                                                        정규화한 선언
     */
    public static function normalize(mixed $raw): array
    {
        $normalized = array_fill_keys(self::TYPES, []);

        if (! is_array($raw)) {
            return $normalized;
        }

        foreach (self::TYPES as $type) {
            $list = $raw[$type] ?? [];
            if (! is_array($list)) {
                continue;
            }

            $identifiers = array_is_list($list) ? $list : array_keys($list);
            foreach ($identifiers as $identifier) {
                if (is_string($identifier) && preg_match(self::IDENTIFIER_PATTERN, $identifier) === 1) {
                    $normalized[$type][] = $identifier;
                }
            }

            $normalized[$type] = array_values(array_unique($normalized[$type]));
        }

        return $normalized;
    }
}
