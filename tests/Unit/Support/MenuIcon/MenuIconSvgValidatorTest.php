<?php

namespace Tests\Unit\Support\MenuIcon;

use App\Support\MenuIcon\MenuIconSpec;
use App\Support\MenuIcon\MenuIconSvgValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PureTestCase;

/**
 * 메뉴 아이콘 인라인 SVG 검사기 단위 테스트.
 *
 * 인라인 삽입은 문서 안에서 실행되므로 스크립트·이벤트 속성·외부 참조·style·image·use·a·애니메이션을 거부하고,
 * 비정사각·currentColor 는 허용한다. 크기 한도는 32KB 다.
 */
class MenuIconSvgValidatorTest extends PureTestCase
{
    /** @var string SVG 네임스페이스 여는 태그 */
    private const OPEN = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 24 24">';

    /**
     * currentColor 를 쓰는 SVG 와 비정사각 SVG 는 통과한다.
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_current_color_and_non_square_are_allowed(): void
    {
        $square = self::OPEN.'<path fill="currentColor" d="M12 2 2 22h20z"/></svg>';
        $wide = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 24"><rect width="48" height="24" fill="currentColor"/></svg>';
        // 문서 안 조각(#id) 참조는 외부 참조가 아니다
        $internalRef = self::OPEN.'<defs><linearGradient id="g"><stop offset="0"/></linearGradient><linearGradient id="g2" href="#g"/></defs><path d="M0 0h24v24H0z"/></svg>';

        $this->assertSame([], MenuIconSvgValidator::violations($square));
        $this->assertSame([], MenuIconSvgValidator::violations($wide));
        $this->assertSame([], MenuIconSvgValidator::violations($internalRef));
        $this->assertTrue(MenuIconSvgValidator::isSafe($wide));
    }

    /**
     * 화면 정제 허용 목록(svgSanitize.ts SVG_SANITIZE_CONFIG.ALLOWED_TAGS) 밖의 요소는 서버가 먼저 거부한다.
     *
     * 서버가 받아 저장했는데 화면 정제가 지워 빈 아이콘이 되는 어긋남(규정 검토 D8)을 막는다.
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_elements_outside_client_allowlist_are_rejected(): void
    {
        foreach (['mask', 'pattern', 'symbol', 'marker', 'filter'] as $element) {
            $svg = self::OPEN.'<defs><'.$element.' id="x"/></defs><path d="M0 0h24v24H0z"/></svg>';
            $this->assertContains('svg_forbidden_element:'.$element, MenuIconSvgValidator::violations($svg), $element);
        }

        $this->assertSame(array_map('strtolower', MenuIconSvgValidator::ALLOWED_ELEMENTS), MenuIconSvgValidator::ALLOWED_ELEMENTS);
    }

    /**
     * 서버 허용 요소는 화면 정제 허용 목록(svgSanitize.ts ALLOWED_TAGS)과 같은 집합이다 — 한쪽만 바뀌면 실패한다.
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_allowed_elements_match_client_sanitize_allowlist(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/templates/_bundled/sirsoft-admin_basic/src/support/svgSanitize.ts');
        $this->assertSame(1, preg_match('/ALLOWED_TAGS:\s*\[(.*?)\]/s', $source, $m));
        preg_match_all("/'([A-Za-z]+)'/", $m[1], $tags);
        $client = array_map('strtolower', $tags[1]);
        sort($client);
        $server = MenuIconSvgValidator::ALLOWED_ELEMENTS;
        sort($server);

        $this->assertSame($client, $server);
    }

    /**
     * normalize 는 BOM·XML 선언·앞뒤 공백을 걷어 낸다.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_normalize_strips_bom_and_xml_declaration(): void
    {
        $svg = self::OPEN.'<path d="M0 0h1v1z"/></svg>';
        $raw = "\xEF\xBB\xBF".'<?xml version="1.0" encoding="UTF-8"?>'."\n  ".$svg."\n";

        $this->assertSame($svg, MenuIconSvgValidator::normalize($raw));
        $this->assertSame([], MenuIconSvgValidator::violations($raw));
    }

    /**
     * 위험한 SVG 케이스 — 기대 위반 코드.
     *
     * @return array<string, array{0: string, 1: string}> [SVG 코드, 포함돼야 할 위반 코드]
     */
    public static function dangerousCases(): array
    {
        return [
            'script' => [self::OPEN.'<script>alert(1)</script></svg>', 'svg_script_element'],
            'onload_attribute' => [str_replace('viewBox', 'onload="alert(1)" viewBox', self::OPEN).'</svg>', 'svg_event_handler_attribute'],
            'foreign_object' => [self::OPEN.'<foreignObject width="10" height="10"><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject></svg>', 'svg_foreign_object'],
            'external_href' => [self::OPEN.'<linearGradient id="g" href="https://evil.example/x.svg#a"/></svg>', 'svg_external_reference'],
            'style' => [self::OPEN.'<style>body{display:none}</style></svg>', 'svg_forbidden_element:style'],
            'image' => [self::OPEN.'<image href="#a" width="1" height="1"/></svg>', 'svg_forbidden_element:image'],
            'use' => [self::OPEN.'<use href="#a"/></svg>', 'svg_forbidden_element:use'],
            'anchor' => [self::OPEN.'<a href="#a"><path d="M0 0h1v1z"/></a></svg>', 'svg_forbidden_element:a'],
            'animate' => [self::OPEN.'<path d="M0 0h1v1z"><animate attributeName="d" dur="1s"/></path></svg>', 'svg_forbidden_element:animate'],
            'set' => [self::OPEN.'<path d="M0 0h1v1z"><set attributeName="fill" to="red"/></path></svg>', 'svg_forbidden_element:set'],
            'doctype' => ['<!DOCTYPE svg [<!ENTITY x "y">]>'.self::OPEN.'</svg>', 'svg_doctype_or_entity'],
            'non_svg_root' => ['<html xmlns="http://www.w3.org/1999/xhtml"><body/></html>', 'svg_root_not_svg'],
            'malformed' => [self::OPEN.'<path d="M0 0"></svg>', 'svg_not_well_formed'],
        ];
    }

    /**
     * 위험 요소·속성·DOCTYPE·비 svg 루트·깨진 XML 은 위반으로 거부되고, describe 는 사람이 읽는 이름을 낸다.
     *
     * @param  string  $svg  SVG 코드
     * @param  string  $expected  포함돼야 할 위반 코드
     * @return void 반환값 없음
     *
     * @effects unsafe_svg_rejected
     */
    #[DataProvider('dangerousCases')]
    public function test_dangerous_svg_is_rejected(string $svg, string $expected): void
    {
        $violations = MenuIconSvgValidator::violations($svg);

        $this->assertContains($expected, $violations);
        $this->assertFalse(MenuIconSvgValidator::isSafe($svg));
        $this->assertNotSame('', MenuIconSvgValidator::describe($violations));
    }

    /**
     * 32KB 를 넘는 SVG 는 too_large 하나로 거부된다.
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_oversized_svg_is_rejected(): void
    {
        $paths = str_repeat('<path fill="currentColor" d="M1 1h2v2H1z"/>', 800);
        $svg = self::OPEN.$paths.'</svg>';

        $this->assertGreaterThan(MenuIconSvgValidator::MAX_BYTES, strlen($svg));
        $this->assertSame(['too_large'], MenuIconSvgValidator::violations($svg));
    }

    /**
     * 회귀(plan 5차 ⓓ): 32KB 이하여도 따옴표·줄바꿈처럼 JSON 이스케이프로 두 배가 되는 글자가 대부분이면
     * 저장 JSON 이 `menus.icon`(TEXT, 65,535바이트)을 넘어 저장이 500 으로 깨졌다 — 저장될 크기로도 too_large 를 낸다.
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_svg_whose_stored_json_overflows_column_is_too_large(): void
    {
        $svg = '<svg>'.str_repeat("\n", 32757).'</svg>';

        $this->assertLessThanOrEqual(MenuIconSvgValidator::MAX_BYTES, strlen($svg));
        $this->assertGreaterThan(65535, strlen(MenuIconSpec::svg($svg)->toStored()));
        $this->assertSame(['too_large'], MenuIconSvgValidator::violations($svg));

        // 같은 길이라도 이스케이프가 없으면 저장 크기 안이라 통과한다(대조군)
        $plain = '<svg>'.str_repeat(' ', 32757).'</svg>';
        $this->assertNotContains('too_large', MenuIconSvgValidator::violations($plain));
    }

    /**
     * 빈 코드는 empty_file 이다.
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_empty_svg_is_rejected(): void
    {
        $this->assertSame(['empty_file'], MenuIconSvgValidator::violations("\xEF\xBB\xBF   "));
    }

    /**
     * describe 는 위반 코드를 요소·속성 이름으로 바꾸고 중복을 없앤다.
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_describe_maps_codes_to_labels(): void
    {
        $this->assertSame(
            'script, on*, style, href',
            MenuIconSvgValidator::describe(['svg_script_element', 'svg_event_handler_attribute', 'svg_forbidden_element:style', 'svg_external_reference', 'svg_script_element'])
        );
    }
}
