<?php

namespace Tests\Unit\Support\MenuIcon;

use App\Enums\ExtensionOwnerType;
use App\Extension\Support\LifecycleWarnings;
use App\Support\MenuIcon\MenuIconDeclaration;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * 메뉴 아이콘 선언 정규화기(MenuIconDeclaration) 단위 테스트.
 *
 * 코어·확장이 선언한 아이콘(문자열·class·image 상대경로·svg path/value)을 저장 정규형으로 바꾸고,
 * 위반 선언은 설치를 막지 않고 null + 경고 로그 + 라이프사이클 경고(menu_icon)로 남기는지 검증한다.
 */
class MenuIconDeclarationTest extends TestCase
{
    /** @var string 테스트용 currentColor SVG */
    private const SAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 2 22h20z"/></svg>';

    /** @var string 픽스처 패키지 루트 */
    private string $root;

    /**
     * 픽스처 패키지 루트(이미지·SVG 파일)를 만들고 루트 해석기를 그곳으로 돌린다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/menu-icon-decl');
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root.'/assets');
        File::put($this->root.'/assets/menu.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        File::put($this->root.'/assets/menu.svg', "\xEF\xBB\xBF".'<?xml version="1.0"?>'."\n".self::SAFE_SVG);
        File::put($this->root.'/assets/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $root = $this->root;
        MenuIconDeclaration::resolveRootUsing(static fn (ExtensionOwnerType $type, string $identifier): string => $root);
        LifecycleWarnings::current()->clear();
    }

    /**
     * 픽스처를 지우고 루트 해석기를 기본으로 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        MenuIconDeclaration::resolveRootUsing(null);
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 모듈 선언으로 정규화합니다.
     *
     * @param  mixed  $declared  선언값
     * @return string|null 정규 저장값
     */
    private function normalize(mixed $declared): ?string
    {
        return MenuIconDeclaration::normalize($declared, ExtensionOwnerType::Module, 'vendor-probe', 'probe-menu');
    }

    /**
     * 이번 테스트에서 쌓인 menu_icon 라이프사이클 경고를 반환합니다.
     *
     * @return list<array<string, mixed>> 경고 항목
     */
    private function menuIconWarnings(): array
    {
        return array_values(array_filter(
            LifecycleWarnings::current()->all(),
            static fn (array $item): bool => $item['step'] === 'menu_icon'
        ));
    }

    /**
     * 경고 로그가 한 번 이상 사유와 함께 남았는지 확인합니다.
     *
     * @param  string  $reason  기대 위반 코드(부분 일치)
     * @return void 반환값 없음
     */
    private function assertWarningLogged(string $reason): void
    {
        Log::shouldHaveReceived('warning')
            ->with('메뉴 아이콘 선언 무시', Mockery::on(static fn (array $context): bool => $context['extension'] === 'vendor-probe'
                && $context['slug'] === 'probe-menu'
                && str_contains((string) $context['reason'], $reason)))
            ->atLeast()->once();
    }

    /**
     * 문자열 선언은 FA 클래스로 정규화되고 경고가 없다.
     *
     * @scenario icon_type=legacy_string, entry=extension_sync
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_string_declaration_is_class(): void
    {
        $this->assertSame('fas fa-users', $this->normalize('  fas   fa-users '));
        $this->assertNull($this->normalize(null));
        $this->assertNull($this->normalize(''));
        $this->assertNull($this->normalize([]));
        $this->assertSame([], $this->menuIconWarnings());
    }

    /**
     * class 배열 선언은 클래스 평문으로 저장되고, 형식 위반 클래스는 버려진다.
     *
     * @scenario icon_type=class, entry=extension_sync
     *
     * @effects canonical_storage, invalid_declaration_ignored_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_class_array_declaration(): void
    {
        Log::spy();

        $this->assertSame('fa-solid fa-star', $this->normalize(['type' => 'class', 'value' => 'fa-solid  fa-star']));
        $this->assertNull($this->normalize(['type' => 'class', 'value' => '<b>x</b>']));
        $this->assertNull($this->normalize('/storage/a.png'));

        $this->assertCount(2, $this->menuIconWarnings());
        $this->assertWarningLogged('class_invalid');
    }

    /**
     * 패키지 상대경로 이미지 선언은 실파일이 있으면 package 출처 JSON 으로 저장된다.
     *
     * @scenario icon_type=image_package, entry=extension_sync
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_relative_image_path_is_kept_when_file_exists(): void
    {
        $this->assertSame(
            '{"path":"assets/menu.png","source":"package","type":"image"}',
            $this->normalize(['type' => 'image', 'path' => 'assets/menu.png'])
        );
        $this->assertSame([], $this->menuIconWarnings());
    }

    /**
     * 확장 선언의 절대경로·URL·상위 탈출·SVG 이미지는 거부되고 라이프사이클 경고가 남는다.
     *
     * @scenario icon_type=image_site, entry=extension_sync
     *
     * @effects external_url_rejected, invalid_declaration_ignored_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_absolute_url_traversal_or_svg_image_is_rejected(): void
    {
        Log::spy();

        foreach (['/storage/menu.png', 'https://example.com/menu.png', '../outside/menu.png', 'assets/menu.svg'] as $path) {
            $this->assertNull($this->normalize(['type' => 'image', 'path' => $path]), $path);
        }

        // 사이트 출처는 운영자 값이라 선언으로 받지 않는다
        $this->assertNull($this->normalize(['type' => 'image', 'source' => 'site', 'path' => '/qa/a.png']));

        $warnings = $this->menuIconWarnings();
        $this->assertCount(5, $warnings);
        foreach ($warnings as $warning) {
            $this->assertSame('vendor-probe', $warning['target']);
            $this->assertSame('declaration', $warning['cause'] ?? null);
            $this->assertSame('warning', $warning['level']);
        }
        $this->assertWarningLogged('absolute_path');
        $this->assertWarningLogged('traversal');
        $this->assertWarningLogged('extension');
        $this->assertWarningLogged('image_source_invalid');
    }

    /**
     * 첨부 출처 이미지는 선언으로 받지 않는다(운영자 업로드 전용).
     *
     * @scenario icon_type=image_attachment, entry=extension_sync
     *
     * @effects invalid_declaration_ignored_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_attachment_source_declaration_is_rejected(): void
    {
        Log::spy();

        $this->assertNull($this->normalize(['type' => 'image', 'source' => 'attachment', 'attachment_id' => 1, 'hash' => 'h']));
        $this->assertCount(1, $this->menuIconWarnings());
        $this->assertWarningLogged('image_source_invalid');
    }

    /**
     * 선언한 이미지 파일이 없으면 null 과 file_missing 경고다.
     *
     * @effects invalid_declaration_ignored_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_missing_image_file_is_rejected(): void
    {
        Log::spy();

        $this->assertNull($this->normalize(['type' => 'image', 'path' => 'assets/nope.png']));
        $this->assertCount(1, $this->menuIconWarnings());
        $this->assertWarningLogged('file_missing');
    }

    /**
     * svg path 선언은 파일을 읽어 검사한 뒤 인라인 svg 코드로 저장된다(BOM·XML 선언 제거).
     *
     * @scenario icon_type=svg, entry=extension_sync
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_svg_path_is_inlined(): void
    {
        $stored = $this->normalize(['type' => 'svg', 'path' => 'assets/menu.svg']);

        $this->assertSame(['type' => 'svg', 'value' => self::SAFE_SVG], json_decode((string) $stored, true));
        $this->assertSame([], $this->menuIconWarnings());
    }

    /**
     * 위험한 SVG 파일·없는 SVG 파일·svg 아닌 path 선언은 거부된다.
     *
     * @effects unsafe_svg_rejected, invalid_declaration_ignored_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_dangerous_or_missing_svg_file_is_rejected(): void
    {
        Log::spy();

        $this->assertNull($this->normalize(['type' => 'svg', 'path' => 'assets/evil.svg']));
        $this->assertNull($this->normalize(['type' => 'svg', 'path' => 'assets/nope.svg']));
        $this->assertNull($this->normalize(['type' => 'svg', 'path' => 'assets/menu.png']));
        $this->assertNull($this->normalize(['type' => 'svg', 'path' => '/abs/menu.svg']));
        $this->assertNull($this->normalize(['type' => 'svg']));

        $this->assertCount(5, $this->menuIconWarnings());
        $this->assertWarningLogged('svg_script_element');
        $this->assertWarningLogged('file_missing');
        $this->assertWarningLogged('svg_required');
    }

    /**
     * svg value 선언도 같은 검사를 받는다 — 안전하면 저장, 위험하면 null.
     *
     * @effects unsafe_svg_rejected, canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_svg_value_is_validated(): void
    {
        Log::spy();

        $this->assertSame(
            ['type' => 'svg', 'value' => self::SAFE_SVG],
            json_decode((string) $this->normalize(['type' => 'svg', 'value' => self::SAFE_SVG]), true)
        );
        $this->assertNull($this->normalize(['type' => 'svg', 'value' => '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"/>']));
        $this->assertWarningLogged('svg_event_handler_attribute');
    }

    /**
     * 알 수 없는 형·스칼라 선언은 invalid_type 으로 버려진다.
     *
     * @effects invalid_declaration_ignored_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_unknown_type_is_rejected(): void
    {
        Log::spy();

        $this->assertNull($this->normalize(['type' => 'video', 'value' => 'x']));
        $this->assertNull($this->normalize(42));
        $this->assertCount(2, $this->menuIconWarnings());
        $this->assertWarningLogged('invalid_type');
    }

    /**
     * 이미 정규형인 JSON 문자열을 다시 넣어도 같은 값이 나온다(재동기화 멱등).
     *
     * @effects canonical_storage, same_value_not_marked
     *
     * @return void 반환값 없음
     */
    public function test_canonical_json_is_idempotent(): void
    {
        $package = $this->normalize(['type' => 'image', 'path' => 'assets/menu.png']);
        $svg = $this->normalize(['type' => 'svg', 'path' => 'assets/menu.svg']);

        $this->assertSame($package, $this->normalize($package));
        $this->assertSame($svg, $this->normalize($svg));
        $this->assertSame('fas fa-users', $this->normalize($this->normalize('fas fa-users')));
        $this->assertSame([], $this->menuIconWarnings());
    }

    /**
     * 해석기가 없으면 코어 루트는 public/, 모듈·플러그인은 설치본 디렉터리다.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_default_package_roots(): void
    {
        MenuIconDeclaration::resolveRootUsing(null);

        $this->assertSame(public_path(), MenuIconDeclaration::packageRoot(ExtensionOwnerType::Core, 'core'));
        $this->assertSame(base_path('modules/sirsoft-board'), MenuIconDeclaration::packageRoot(ExtensionOwnerType::Module, 'sirsoft-board'));
        $this->assertSame(base_path('plugins/sirsoft-sso'), MenuIconDeclaration::packageRoot(ExtensionOwnerType::Plugin, 'sirsoft-sso'));
    }
}
