<?php

namespace Tests\Unit\Support\MenuIcon;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Attachment;
use App\Support\AssetUrl;
use App\Support\MenuIcon\MenuIconPresenter;
use Tests\TestCase;

/**
 * 메뉴 아이콘 응답 표현기(MenuIconPresenter) 단위 테스트.
 *
 * 저장값을 호환 문자열 `icon` + 구조형 `icon_spec` 로 바꾸고, 이미지 url 을 응답 시점에
 * 마운트 접두·첨부 해시·확장 자산 URL 로 만드는지 검증한다.
 */
class MenuIconPresenterTest extends TestCase
{
    /** @var string 테스트용 currentColor SVG */
    private const SAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 2 22h20z"/></svg>';

    /**
     * 강제한 마운트 접두를 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * URL 에 경로가 (평문 또는 쿼리 인코딩으로) 실렸는지 확인합니다.
     *
     * @param  string  $path  확장 루트 기준 경로
     * @param  string  $url  생성된 URL
     * @return void 반환값 없음
     */
    private function assertUrlCarriesPath(string $path, string $url): void
    {
        $this->assertTrue(
            str_contains($url, $path) || str_contains($url, rawurlencode($path)),
            "URL 에 경로가 없다: {$url}"
        );
    }

    /**
     * class 아이콘은 호환 icon 과 구조형 icon_spec 을 함께 낸다.
     *
     * @effects legacy_icon_exposed
     *
     * @return void 반환값 없음
     */
    public function test_class_icon_exposes_icon_and_spec(): void
    {
        $this->assertSame(
            ['icon' => 'fas fa-users', 'icon_spec' => ['type' => 'class', 'value' => 'fas fa-users']],
            MenuIconPresenter::present('fas fa-users', null, null)
        );
        $this->assertSame(['icon' => null, 'icon_spec' => null], MenuIconPresenter::present(null, null, null));
        $this->assertSame(['icon' => null, 'icon_spec' => null], MenuIconPresenter::present('  ', null, null));
    }

    /**
     * image·svg 아이콘은 호환 icon 이 null 이고 icon_spec 만 싣는다.
     *
     * @effects legacy_icon_exposed
     *
     * @return void 반환값 없음
     */
    public function test_image_and_svg_have_null_legacy_icon(): void
    {
        $svg = MenuIconPresenter::present((string) json_encode(['type' => 'svg', 'value' => self::SAFE_SVG]), null, null);
        $this->assertNull($svg['icon']);
        $this->assertSame(['type' => 'svg', 'value' => self::SAFE_SVG], $svg['icon_spec']);

        $image = MenuIconPresenter::present('{"path":"/qa/a.png","source":"site","type":"image"}', null, null);
        $this->assertNull($image['icon']);
        $this->assertSame('image', $image['icon_spec']['type']);
        $this->assertSame('/qa/a.png', $image['icon_spec']['path']);
    }

    /**
     * 사이트 경로 이미지 url 에 마운트 접두가 붙는다.
     *
     * @effects url_presented
     *
     * @return void 반환값 없음
     */
    public function test_site_image_url_gets_mount_prefix(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        $result = MenuIconPresenter::present('{"path":"/qa/a.png","source":"site","type":"image"}', null, null);

        $this->assertSame('/g7/qa/a.png', $result['icon_spec']['url']);

        // 레거시 사이트 경로 문자열도 같은 url 을 받는다
        $this->assertSame('/g7/storage/a.png', MenuIconPresenter::present('/storage/a.png', null, null)['icon_spec']['url']);
    }

    /**
     * 첨부 이미지 url 은 서버 해시 URL 이다.
     *
     * @effects url_presented
     *
     * @return void 반환값 없음
     */
    public function test_attachment_image_url_uses_hash(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        $result = MenuIconPresenter::present('{"attachment_id":3,"hash":"abc123","source":"attachment","type":"image"}', null, null);

        $this->assertSame(Attachment::urlForHash('abc123'), $result['icon_spec']['url']);
        $this->assertSame('/g7/api/attachment/abc123', $result['icon_spec']['url']);
        $this->assertSame(3, $result['icon_spec']['attachment_id']);

        $noHash = MenuIconPresenter::present('{"attachment_id":3,"hash":"","source":"attachment","type":"image"}', null, null);
        $this->assertNull($noHash['icon_spec']['url']);
    }

    /**
     * 모듈·플러그인 패키지 이미지 url 은 확장 자산 URL(식별자 + 경로)이다 — 유형은 enum·문자열 모두 받는다.
     *
     * @effects url_presented
     *
     * @return void 반환값 없음
     */
    public function test_extension_package_image_url_uses_extension_asset(): void
    {
        $stored = '{"path":"assets/menu.png","source":"package","type":"image"}';

        $module = MenuIconPresenter::present($stored, ExtensionOwnerType::Module, 'vendor-probe');
        $this->assertStringContainsString('/api/modules/assets/vendor-probe', $module['icon_spec']['url']);
        $this->assertUrlCarriesPath('assets/menu.png', $module['icon_spec']['url']);
        $this->assertSame(AssetUrl::moduleAsset('vendor-probe', 'assets/menu.png'), $module['icon_spec']['url']);

        $plugin = MenuIconPresenter::present($stored, 'plugin', 'vendor-plug');
        $this->assertStringContainsString('/api/plugins/assets/vendor-plug', $plugin['icon_spec']['url']);
        $this->assertUrlCarriesPath('assets/menu.png', $plugin['icon_spec']['url']);

        $noIdentifier = MenuIconPresenter::present($stored, ExtensionOwnerType::Module, null);
        $this->assertNull($noIdentifier['icon_spec']['url']);
    }

    /**
     * 설치된 확장의 패키지 이미지 url 에는 그 확장의 현재 버전이 실린다 — 업데이트 뒤 브라우저 캐시가 옛 이미지를 쓰지 않게.
     *
     * @effects url_presented
     *
     * @return void 반환값 없음
     */
    public function test_extension_package_image_url_carries_extension_version(): void
    {
        $stored = '{"path":"assets/menu.png","source":"package","type":"image"}';

        $module = \Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getVersion')->andReturn('9.8.7');
        $this->mock(ModuleManager::class, fn ($m) => $m->shouldReceive('getModule')->with('vendor-probe')->andReturn($module));

        $plugin = \Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getVersion')->andReturn('3.2.1');
        $this->mock(PluginManager::class, fn ($m) => $m->shouldReceive('getPlugin')->with('vendor-plug')->andReturn($plugin));

        $moduleUrl = MenuIconPresenter::present($stored, ExtensionOwnerType::Module, 'vendor-probe')['icon_spec']['url'];
        $this->assertSame(AssetUrl::moduleAsset('vendor-probe', 'assets/menu.png', '9.8.7'), $moduleUrl);
        $this->assertNotSame(AssetUrl::moduleAsset('vendor-probe', 'assets/menu.png'), $moduleUrl);
        $this->assertStringContainsString('9.8.7', $moduleUrl);

        $pluginUrl = MenuIconPresenter::present($stored, ExtensionOwnerType::Plugin, 'vendor-plug')['icon_spec']['url'];
        $this->assertSame(AssetUrl::pluginAsset('vendor-plug', 'assets/menu.png', '3.2.1'), $pluginUrl);
        $this->assertStringContainsString('3.2.1', $pluginUrl);
    }

    /**
     * 코어 패키지 이미지 url 은 public/ 기준 사이트 경로다.
     *
     * @effects url_presented
     *
     * @return void 반환값 없음
     */
    public function test_core_package_image_url_is_site_path(): void
    {
        AssetUrl::forceBasePrefix('/g7');
        $stored = '{"path":"images/menu.png","source":"package","type":"image"}';

        $this->assertSame('/g7/images/menu.png', MenuIconPresenter::present($stored, ExtensionOwnerType::Core, 'core')['icon_spec']['url']);
        $this->assertSame('/g7/images/menu.png', MenuIconPresenter::present($stored, null, null)['icon_spec']['url']);
    }

    /**
     * 회귀(plan 5차 C-5a): 7.2.0 이하 API 로 저장된 JSON 모양 아이콘 문자열은 입력 검사를 거치지 않았다.
     * 저장값의 경로가 외부 주소·상위 경로 등 입력 규칙을 어기면 url 을 만들지 않는다(화면은 대체 아이콘) —
     * 같은 `//외부` 가 평문 문자열이면 이미 class 로 읽혀 막히던 것과 같은 규칙이다.
     *
     * @effects external_url_rejected, url_presented
     *
     * @return void 반환값 없음
     */
    public function test_stored_image_path_breaking_input_rules_has_no_url(): void
    {
        foreach (['//evil.example/p.png', 'https://evil.example/p.png', '/a/../../p.png', 'data:image/png;base64,AAAA'] as $path) {
            $stored = json_encode(['path' => $path, 'source' => 'site', 'type' => 'image'], JSON_UNESCAPED_SLASHES);
            $presented = MenuIconPresenter::present($stored, null, null);

            $this->assertNull($presented['icon']);
            $this->assertSame('image', $presented['icon_spec']['type'], $path);
            $this->assertNull($presented['icon_spec']['url'], $path);
        }

        foreach (['//evil.example/p.png', '../outside.png', 'https://evil.example/p.png'] as $path) {
            $stored = json_encode(['path' => $path, 'source' => 'package', 'type' => 'image'], JSON_UNESCAPED_SLASHES);

            $this->assertNull(MenuIconPresenter::present($stored, ExtensionOwnerType::Core, 'core')['icon_spec']['url'], $path);
            $this->assertNull(MenuIconPresenter::present($stored, ExtensionOwnerType::Module, 'sirsoft-page')['icon_spec']['url'], $path);
        }

        // 규칙을 지키는 저장값은 종전대로 url 이 있다(모집단이 비어 통과하는 것을 막는 대조군)
        $this->assertSame('/qa/a.png', MenuIconPresenter::present('{"path":"/qa/a.png","source":"site","type":"image"}', null, null)['icon_spec']['url']);
    }

    /**
     * 회귀(plan 6차 D-6a): 7.2.0 이하 API 로 저장된 JSON 모양 SVG 문자열도 입력 검사를 거치지 않았다.
     * 저장값이 SVG 검사를 통과하지 못하면 응답에 코드를 싣지 않는다(`value: null` — 화면은 대체 아이콘). 정제를 빠뜨린
     * 외부 관리자 템플릿이 그 코드를 그대로 그리지 않게 하는 서버 쪽 방어선이다(C-5a 의 이미지 주소와 같은 부류).
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_stored_svg_breaking_input_rules_has_no_value(): void
    {
        $unsafe = [
            '<svg onload="alert(1)"></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.example/x.png"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><style>body{display:none}</style></svg>',
            '<div>not svg</div>',
        ];

        foreach ($unsafe as $svg) {
            $stored = json_encode(['type' => 'svg', 'value' => $svg], JSON_UNESCAPED_SLASHES);
            $presented = MenuIconPresenter::present($stored, null, null);

            $this->assertNull($presented['icon'], $svg);
            $this->assertSame(['type' => 'svg', 'value' => null], $presented['icon_spec'], $svg);
        }

        // 검사를 통과하는 저장 SVG 는 종전대로 코드를 싣는다(모집단이 비어 통과하는 것을 막는 대조군)
        $safeStored = json_encode(['type' => 'svg', 'value' => self::SAFE_SVG], JSON_UNESCAPED_SLASHES);
        $this->assertSame(self::SAFE_SVG, MenuIconPresenter::present($safeStored, null, null)['icon_spec']['value']);
    }
}
