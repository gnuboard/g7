<?php

namespace Tests\Unit\Support\MenuIcon;

use App\Models\Menu;
use App\Support\MenuIcon\MenuIconSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PureTestCase;

/**
 * 메뉴 아이콘 값 객체(MenuIconSpec) 단위 테스트.
 *
 * 저장 정규형(class 평문 · image/svg 키 사전순 JSON)과 레거시 문자열 해석, 사이트·패키지 경로 규칙,
 * 활동 로그 요약을 고정한다. 같은 뜻이면 같은 바이트여야 사용자 수정 표식이 오탐하지 않는다.
 */
class MenuIconSpecTest extends PureTestCase
{
    /** @var string 테스트용 currentColor SVG */
    private const SAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 2 22h20z"/></svg>';

    /**
     * 평문 문자열은 FA 클래스로 읽힌다.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_plain_string_is_class_icon(): void
    {
        $spec = MenuIconSpec::fromStored('fas fa-users');

        $this->assertNotNull($spec);
        $this->assertSame(MenuIconSpec::TYPE_CLASS, $spec->type);
        $this->assertSame('fas fa-users', $spec->value);
        $this->assertSame('fas fa-users', $spec->toStored());
    }

    /**
     * null·빈 문자열·공백만 있는 값은 아이콘 없음(null)이다.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_null_or_blank_is_no_icon(): void
    {
        $this->assertNull(MenuIconSpec::fromStored(null));
        $this->assertNull(MenuIconSpec::fromStored(''));
        $this->assertNull(MenuIconSpec::fromStored("  \t "));
        $this->assertNull(MenuIconSpec::canonicalize(null));
        $this->assertNull(MenuIconSpec::canonicalize('   '));
        $this->assertNull(MenuIconSpec::canonicalize(123));
    }

    /**
     * 구조형 네 가지(class·image site·image package·image attachment·svg) 입력 케이스.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}> [입력 배열, 기대 정규 저장값]
     */
    public static function structuredKinds(): array
    {
        return [
            'class' => [['type' => 'class', 'value' => 'fas fa-users'], 'fas fa-users'],
            'image_site' => [['type' => 'image', 'source' => 'site', 'path' => '/qa/a.png'], '{"path":"/qa/a.png","source":"site","type":"image"}'],
            'image_package' => [['type' => 'image', 'source' => 'package', 'path' => 'assets/menu.png'], '{"path":"assets/menu.png","source":"package","type":"image"}'],
            'image_attachment' => [['type' => 'image', 'source' => 'attachment', 'attachment_id' => 7, 'hash' => 'abc123'], '{"attachment_id":7,"hash":"abc123","source":"attachment","type":"image"}'],
            'svg' => [['type' => 'svg', 'value' => self::SAFE_SVG], (string) json_encode(['type' => 'svg', 'value' => self::SAFE_SVG], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        ];
    }

    /**
     * 구조형 배열과 그 저장 문자열이 같은 정규형으로 왕복한다.
     *
     * @param  array<string, mixed>  $input  구조형 입력
     * @param  string  $expected  기대 정규 저장값
     * @return void 반환값 없음
     *
     * @effects canonical_storage
     */
    #[DataProvider('structuredKinds')]
    public function test_structured_kinds_round_trip(array $input, string $expected): void
    {
        $this->assertSame($expected, MenuIconSpec::canonicalize($input));
        $this->assertSame($expected, MenuIconSpec::fromStored($expected)?->toStored());
        $this->assertSame($input['type'], MenuIconSpec::fromStored($expected)?->type);
    }

    /**
     * 깨진 JSON 은 구조형으로 읽지 않고 class 로 읽는다(사이트 경로도 아니므로).
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_broken_json_falls_back_to_class(): void
    {
        $spec = MenuIconSpec::fromStored('{"type":"image"');

        $this->assertSame(MenuIconSpec::TYPE_CLASS, $spec?->type);

        // 형을 알 수 없는 JSON 객체도 class 로 남는다
        $this->assertSame(MenuIconSpec::TYPE_CLASS, MenuIconSpec::fromStored('{"type":"video"}')?->type);
    }

    /**
     * 옛 API 로 저장된 사이트 이미지 경로 문자열은 image·site 로 구제된다.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_legacy_site_path_is_rescued_as_site_image(): void
    {
        $spec = MenuIconSpec::fromStored('/storage/a.png');

        $this->assertSame(MenuIconSpec::TYPE_IMAGE, $spec?->type);
        $this->assertSame(MenuIconSpec::SOURCE_SITE, $spec?->source);
        $this->assertSame('/storage/a.png', $spec?->path);
        $this->assertSame('{"path":"/storage/a.png","source":"site","type":"image"}', MenuIconSpec::canonicalize('/storage/a.png'));
    }

    /**
     * FA 클래스의 앞뒤·연속 공백(탭·줄바꿈 포함)은 공백 하나로 정규화된다.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_class_whitespace_is_normalized(): void
    {
        $this->assertSame('fas fa-users', MenuIconSpec::canonicalize("  fas \t\n fa-users  "));
        $this->assertSame('fas fa-users', MenuIconSpec::canonicalize(['type' => 'class', 'value' => ' fas   fa-users ']));
    }

    /**
     * 구조형 JSON 은 키 사전순으로 저장되고 표시용 url 은 저장되지 않는다.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_json_keys_are_sorted_and_url_is_not_stored(): void
    {
        $stored = MenuIconSpec::canonicalize([
            'url' => 'https://evil.example/x.png',
            'type' => 'image',
            'path' => '/qa/a.png',
            'source' => 'site',
        ]);

        $this->assertSame('{"path":"/qa/a.png","source":"site","type":"image"}', $stored);
        $this->assertStringNotContainsString('url', (string) $stored);

        $keys = array_keys((array) json_decode((string) $stored, true));
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
    }

    /**
     * 레거시 class 문자열과 구조형 class 는 같은 바이트로 정규화된다.
     *
     * @effects canonical_storage, same_value_not_marked
     *
     * @return void 반환값 없음
     */
    public function test_legacy_string_equals_structured_class(): void
    {
        $this->assertSame(
            MenuIconSpec::canonicalize('fas fa-users'),
            MenuIconSpec::canonicalize(['type' => 'class', 'value' => 'fas fa-users'])
        );
        $this->assertSame(
            MenuIconSpec::canonicalize(' fas  fa-users '),
            MenuIconSpec::canonicalize(MenuIconSpec::classIcon('fas fa-users'))
        );
    }

    /**
     * 표시용 키(url·label 등)는 해석에서 무시돼 정규형이 바뀌지 않는다.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_display_keys_are_ignored(): void
    {
        $plain = MenuIconSpec::canonicalize(['type' => 'svg', 'value' => self::SAFE_SVG]);
        $decorated = MenuIconSpec::canonicalize(['type' => 'svg', 'value' => self::SAFE_SVG, 'url' => '/x', 'label' => 'y']);

        $this->assertSame($plain, $decorated);

        $attachment = MenuIconSpec::fromArray(['type' => 'image', 'source' => 'attachment', 'attachment_id' => '9', 'hash' => 'h', 'url' => '/api/attachment/h']);
        $this->assertSame(9, $attachment?->attachmentId);
        $this->assertArrayNotHasKey('url', $attachment?->toArray() ?? []);
    }

    /**
     * 필수 값이 빠진 구조형은 해석되지 않는다(null).
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_incomplete_structures_are_rejected(): void
    {
        $this->assertNull(MenuIconSpec::fromArray(['type' => 'class', 'value' => '  ']));
        $this->assertNull(MenuIconSpec::fromArray(['type' => 'svg']));
        $this->assertNull(MenuIconSpec::fromArray(['type' => 'image', 'source' => 'site']));
        $this->assertNull(MenuIconSpec::fromArray(['type' => 'image', 'source' => 'attachment', 'attachment_id' => 0]));
        $this->assertNull(MenuIconSpec::fromArray(['type' => 'image', 'source' => 'remote', 'path' => '/a.png']));
        $this->assertNull(MenuIconSpec::fromArray(['value' => 'fas fa-users']));
    }

    /**
     * 호환 문자열 icon 은 class 만 값을 갖고 image·svg 는 null 이다.
     *
     * @effects legacy_icon_exposed
     *
     * @return void 반환값 없음
     */
    public function test_legacy_icon_is_exposed_only_for_class(): void
    {
        $this->assertSame('fas fa-users', MenuIconSpec::classIcon('fas fa-users')->legacyIcon());
        $this->assertNull(MenuIconSpec::siteImage('/qa/a.png')->legacyIcon());
        $this->assertNull(MenuIconSpec::packageImage('assets/a.png')->legacyIcon());
        $this->assertNull(MenuIconSpec::attachmentImage(1, 'h')->legacyIcon());
        $this->assertNull(MenuIconSpec::svg(self::SAFE_SVG)->legacyIcon());
    }

    /**
     * 사이트 이미지 경로 위반 코드 케이스.
     *
     * @return array<string, array{0: string, 1: string|null}> [경로, 기대 위반 코드]
     */
    public static function sitePathCases(): array
    {
        return [
            'http' => ['http://example.com/x.png', 'external'],
            'https' => ['https://example.com/x.png', 'external'],
            'protocol_relative' => ['//example.com/x.png', 'external'],
            'data_uri' => ['data:image/png;base64,AAA', 'external'],
            'javascript' => ['javascript:alert(1)', 'external'],
            'relative' => ['images/a.png', 'external'],
            'traversal' => ['/a/../b.png', 'traversal'],
            'dot_segment' => ['/a/./b.png', 'traversal'],
            'backslash' => ['/a\\b.png', 'backslash'],
            'query' => ['/a.png?q=1', 'query'],
            'fragment' => ['/a.png#x', 'query'],
            'svg' => ['/a.svg', 'extension'],
            'no_extension' => ['/a', 'extension'],
            'null_byte' => ["/a\0.png", 'null_byte'],
            'empty' => ['  ', 'empty'],
            'too_long' => ['/'.str_repeat('a', 500).'.png', 'too_long'],
            // 사이트 안 경로에 공백 — 외부 주소가 아니라 경로 형식 오류다(plan O1)
            'whitespace' => ['/qa/my icon.png', 'whitespace'],
            'ok_png' => ['/storage/icons/a.png', null],
            'ok_upper_webp' => ['/qa/A.WEBP', null],
            'ok_jpg' => ['/qa/a.jpg', null],
        ];
    }

    /**
     * 사이트 이미지 경로 규칙 — 외부 주소·data:·상위 탈출·역슬래시·쿼리·SVG·길이 초과를 거부한다.
     *
     * @param  string  $path  경로
     * @param  string|null  $expected  기대 위반 코드
     * @return void 반환값 없음
     *
     * @effects external_url_rejected
     */
    #[DataProvider('sitePathCases')]
    public function test_site_path_violation(string $path, ?string $expected): void
    {
        $this->assertSame($expected, MenuIconSpec::sitePathViolation($path));
    }

    /**
     * 패키지 상대경로 규칙 — jpg·gif 는 허용, 절대경로·URL·탈출·SVG 는 거부한다.
     *
     * @effects external_url_rejected
     *
     * @return void 반환값 없음
     */
    public function test_package_path_violation(): void
    {
        $this->assertNull(MenuIconSpec::packagePathViolation('assets/menu.png'));
        $this->assertNull(MenuIconSpec::packagePathViolation('assets/menu.jpg'));
        $this->assertNull(MenuIconSpec::packagePathViolation('assets/menu.gif'));
        $this->assertNull(MenuIconSpec::packagePathViolation('assets/menu.webp'));

        $this->assertSame('absolute_path', MenuIconSpec::packagePathViolation('/assets/menu.png'));
        $this->assertSame('absolute_path', MenuIconSpec::packagePathViolation('https://example.com/menu.png'));
        $this->assertSame('absolute_path', MenuIconSpec::packagePathViolation('//example.com/menu.png'));
        $this->assertSame('traversal', MenuIconSpec::packagePathViolation('../other/menu.png'));
        $this->assertSame('backslash', MenuIconSpec::packagePathViolation('assets\\menu.png'));
        $this->assertSame('extension', MenuIconSpec::packagePathViolation('assets/menu.svg'));
        $this->assertSame('extension', MenuIconSpec::packagePathViolation('assets/menu.txt'));
        $this->assertSame('too_long', MenuIconSpec::packagePathViolation(str_repeat('a', 501).'.png'));
    }

    /**
     * FA 클래스 형식 위반 — 길이 초과·허용 밖 문자.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_class_violation(): void
    {
        $this->assertNull(MenuIconSpec::classViolation('fas fa-users'));
        $this->assertNull(MenuIconSpec::classViolation(' fa-solid   fa-star '));
        $this->assertSame('class_invalid', MenuIconSpec::classViolation('<i>'));
        $this->assertSame('class_invalid', MenuIconSpec::classViolation('/storage/a.png'));
        $this->assertSame('class_invalid', MenuIconSpec::classViolation(''));
        $this->assertSame('class_max', MenuIconSpec::classViolation(str_repeat('a', 101)));
    }

    /**
     * 활동 로그 요약 — 형별 요약 문자열, svg 는 해시 앞 8자와 바이트 수만 남긴다.
     *
     * @effects activity_log_summary
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_summary_per_kind(): void
    {
        $this->assertSame('fas fa-users', MenuIconSpec::activityLogSummary('fas fa-users'));
        $this->assertSame('image:/qa/a.png', MenuIconSpec::activityLogSummary('{"path":"/qa/a.png","source":"site","type":"image"}'));
        $this->assertSame('image:package:assets/a.png', MenuIconSpec::activityLogSummary(['type' => 'image', 'source' => 'package', 'path' => 'assets/a.png']));
        $this->assertSame('image:attachment#12', MenuIconSpec::activityLogSummary(['type' => 'image', 'source' => 'attachment', 'attachment_id' => 12, 'hash' => 'h']));
        $this->assertNull(MenuIconSpec::activityLogSummary(null));
        $this->assertNull(MenuIconSpec::activityLogSummary(''));

        $svgSummary = MenuIconSpec::activityLogSummary(MenuIconSpec::svg(self::SAFE_SVG)->toStored());
        $this->assertSame(sprintf('svg:%s(%dB)', substr(sha1(self::SAFE_SVG), 0, 8), strlen(self::SAFE_SVG)), $svgSummary);
        $this->assertMatchesRegularExpression('/^svg:[0-9a-f]{8}\(\d+B\)$/', (string) $svgSummary);
        $this->assertStringNotContainsString('<svg', (string) $svgSummary);
    }

    /**
     * 메뉴 모델의 활동 로그 icon 필드는 이 요약을 display 콜러블로 선언한다 — SVG 원문이 로그에 쌓이지 않는다.
     *
     * @effects activity_log_summary
     *
     * @return void 반환값 없음
     */
    public function test_menu_activity_log_icon_field_uses_summary(): void
    {
        $meta = Menu::$activityLogFields['icon'];

        $this->assertTrue(is_callable($meta['display']));
        $this->assertSame('fas fa-users', ($meta['display'])('fas fa-users'));
        $this->assertStringStartsWith('svg:', (string) ($meta['display'])(MenuIconSpec::svg(self::SAFE_SVG)->toStored()));
    }
}
