<?php

namespace App\Support\MenuIcon;

use App\Support\ExtensionIcon\ExtensionIconValidator;

/**
 * 메뉴 아이콘 인라인 SVG 코드 검사기.
 *
 * 확장 아이콘 검사기(`ExtensionIconValidator`)의 SVG 판정(DOCTYPE·엔티티·스크립트·foreignObject·
 * 이벤트 속성·외부 참조)을 그대로 쓰고, 인라인 삽입에만 해당하는 규칙을 더한다.
 * - 비정사각 허용(`not_square` 제외), 크기 한도는 이 클래스의 MAX_BYTES(32KB)
 * - `<style>` 은 거부 — 인라인 SVG 의 style 은 문서 전체 CSS 로 샌다(확장 아이콘은 `<img>` 라 무해)
 * - `<image>`·`<use>`·`<a>`·애니메이션·임베드 요소 거부
 *
 * `ExtensionIconValidator` 는 웹 인스톨러가 직접 require 하는 무의존 파일이라 수정하지 않는다.
 *
 * @since 7.2.1
 */
final class MenuIconSvgValidator
{
    /** @var int 인라인 SVG 최대 바이트 — 사이드바 응답마다 실리므로 확장 아이콘(100KB)보다 작다 */
    public const MAX_BYTES = 32768;

    /** @var int 저장 JSON 최대 바이트 — `menus.icon` TEXT 컬럼 용량 */
    public const STORED_MAX_BYTES = 65535;

    /**
     * 허용 요소(소문자) — 화면 정제 허용 목록(`templates/_bundled/sirsoft-admin_basic/src/support/svgSanitize.ts` 의 `SVG_SANITIZE_CONFIG.ALLOWED_TAGS`)과 같다.
     * 이 밖의 요소는 화면이 지워 아이콘이 비거나 일부만 그려지므로 저장 단계에서 사유와 함께 거부한다.
     *
     * @var list<string>
     */
    public const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc',
        'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path',
        'text', 'tspan',
        'lineargradient', 'radialgradient', 'stop',
        'clippath',
    ];

    /** @var list<string> 인라인 삽입에서 추가로 거부하는 요소(소문자) — ALLOWED_ELEMENTS 밖이라 함께 걸리지만 위험 요소를 명시해 둔다 */
    public const FORBIDDEN_ELEMENTS = [
        'style', 'image', 'use', 'a', 'animate', 'animatemotion', 'animatetransform', 'set',
        'iframe', 'embed', 'object', 'audio', 'video', 'canvas',
    ];

    /**
     * SVG 코드를 정규화합니다 — BOM·XML 선언을 걷고 앞뒤 공백을 지운다.
     *
     * @param  string  $svg  SVG 코드
     * @return string 정규화된 SVG 코드
     */
    public static function normalize(string $svg): string
    {
        $svg = preg_replace('/^\xEF\xBB\xBF/', '', $svg) ?? $svg;
        $svg = preg_replace('/^\s*<\?xml\b[^>]*\?>/i', '', $svg) ?? $svg;

        return trim($svg);
    }

    /**
     * SVG 코드의 규칙 위반 목록을 반환합니다 (정규화한 코드 기준).
     *
     * @param  string  $svg  SVG 코드
     * @return list<string> 위반 코드 목록 (비어 있으면 통과). 크기 초과는 `too_large`
     */
    public static function violations(string $svg): array
    {
        $normalized = self::normalize($svg);

        if ($normalized === '') {
            return ['empty_file'];
        }

        // 저장 JSON 은 따옴표·줄바꿈·역슬래시를 두 바이트로 늘린다 — 32KB 안이어도 `menus.icon`(TEXT) 을 넘으면 저장이 깨진다
        if (strlen($normalized) > self::MAX_BYTES || strlen(MenuIconSpec::svg($normalized)->toStored()) > self::STORED_MAX_BYTES) {
            return ['too_large'];
        }

        $result = ExtensionIconValidator::validateBytes($normalized, 'svg');
        $errors = array_values(array_filter(
            $result['errors'],
            static fn (string $code): bool => $code !== 'not_square' && $code !== 'too_large'
        ));

        // 파싱이 되지 않았으면 요소 검사는 의미가 없다
        if (in_array('svg_not_well_formed', $errors, true) || in_array('svg_doctype_or_entity', $errors, true)) {
            return $errors;
        }

        // php-ext-guard: libxml 은 필수 dom·xml 의 토대
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $loaded = $document->loadXML($normalized, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors(); // php-ext-guard: libxml 은 필수 dom·xml 의 토대
        libxml_use_internal_errors($previous); // php-ext-guard: libxml 은 필수 dom·xml 의 토대

        if ($loaded !== false) {
            foreach ($document->getElementsByTagName('*') as $element) {
                $name = strtolower($element->localName);

                // script·foreignObject 는 위에서 고유 코드로 이미 걸렸다
                if ($name === 'script' || $name === 'foreignobject') {
                    continue;
                }

                if (in_array($name, self::FORBIDDEN_ELEMENTS, true) || ! in_array($name, self::ALLOWED_ELEMENTS, true)) {
                    $errors[] = 'svg_forbidden_element:'.$name;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * 통과 여부를 반환합니다.
     *
     * @param  string  $svg  SVG 코드
     * @return bool 위반이 없으면 true
     */
    public static function isSafe(string $svg): bool
    {
        return self::violations($svg) === [];
    }

    /**
     * 위반 코드를 사람이 읽는 요약(요소·속성 이름)으로 바꿉니다 — 검증 메시지 :reasons 자리에 쓴다.
     *
     * @param  list<string>  $violations  위반 코드 목록
     * @return string 쉼표로 이은 요약
     */
    public static function describe(array $violations): string
    {
        $map = [
            'svg_script_element' => 'script',
            'svg_foreign_object' => 'foreignObject',
            'svg_event_handler_attribute' => 'on*',
            'svg_external_reference' => 'href',
            'svg_style_external_reference' => 'style url()',
            'svg_doctype_or_entity' => 'DOCTYPE/ENTITY',
            'svg_stylesheet_pi' => 'xml-stylesheet',
            'svg_not_well_formed' => 'XML',
            'svg_root_not_svg' => 'root',
            'empty_file' => 'empty',
        ];

        $labels = array_map(static function (string $code) use ($map): string {
            if (str_starts_with($code, 'svg_forbidden_element:')) {
                return substr($code, strlen('svg_forbidden_element:'));
            }

            return $map[$code] ?? $code;
        }, $violations);

        return implode(', ', array_values(array_unique($labels)));
    }
}
