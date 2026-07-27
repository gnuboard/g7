<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\TemplateManager;
use App\Extension\Traits\CollectsFragmentSources;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * 템플릿 확장 오버라이드(`templates/{t}/extensions/{ext}/`)의 설치 소스 선택 테스트.
 *
 * 모듈·플러그인 fragment 는 `RefreshesLayoutExtensions` 가 `.g7.html` 을 우선 컴파일하는데,
 * 템플릿 오버라이드만 `glob('*.json')` 로 남아 있어 **저작 SSoT 를 무시**하고 있었다.
 * 세 등록 경로가 같은 규약(`.g7.html` 우선 + 짝 없는 `.json`)을 쓰는지 여기서 고정한다.
 *
 * @see CollectsFragmentSources
 * @see MarkupFragmentInstallTest 모듈·플러그인 fragment 대칭 케이스
 */
class TemplateOverrideMarkupInstallTest extends TestCase
{
    private TemplateManager $manager;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        // 소스 수집·컴파일은 인스턴스 상태를 쓰지 않으므로 생성자 의존성 없이 만든다.
        $this->manager = (new ReflectionClass(TemplateManager::class))->newInstanceWithoutConstructor();

        $this->tmp = base_path('storage/framework/testing/tpl_override_markup_'.uniqid());
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * 보호된 메서드를 호출합니다.
     *
     * @param  string  $method  메서드명
     * @param  array<int, mixed>  $args  인자
     * @return mixed 반환값
     */
    private function invokeProtected(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(TemplateManager::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->manager, $args);
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_override_markup_only_is_registered
     */
    public function test_template_override_markup_without_json_sibling_is_collected(): void
    {
        // 짝 `.json` 없이 저작본만 둔 상태 — 종전 `glob('*.json')` 구현은 0건으로 흘려보냈다.
        File::put("{$this->tmp}/user-address-search.g7.html", "<extension extension-point=\"address_search_slot\"/>\n");

        $sources = $this->invokeProtected('collectFragmentSources', [$this->tmp, []]);

        $this->assertCount(1, $sources, '저작본만 있어도 등록 소스로 수집되어야 한다');
        $this->assertTrue($sources[0]['markup']);
        $this->assertStringEndsWith('user-address-search.g7.html', $sources[0]['path']);
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_override_markup_wins_over_json_sibling
     */
    public function test_template_override_markup_wins_over_json_sibling(): void
    {
        File::put("{$this->tmp}/frag.g7.html", "<extension extension-point=\"slot\"/>\n");
        File::put("{$this->tmp}/frag.json", '{"extension_point":"stale"}');

        $sources = $this->invokeProtected('collectFragmentSources', [$this->tmp, ["{$this->tmp}/frag.json"]]);

        $this->assertCount(1, $sources, '같은 논리 fragment 는 한 소스만 — 짝 .json 은 건너뜀');
        $this->assertTrue($sources[0]['markup']);
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_override_json_only_still_registered
     */
    public function test_template_override_json_without_markup_is_collected(): void
    {
        File::put("{$this->tmp}/legacy.json", '{"extension_point":"slot"}');

        $sources = $this->invokeProtected('collectFragmentSources', [$this->tmp, ["{$this->tmp}/legacy.json"]]);

        $this->assertCount(1, $sources, '마크업 미이관 오버라이드는 종전대로 .json 으로 등록');
        $this->assertFalse($sources[0]['markup']);
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_override_markup_compiles_to_extension_json
     */
    public function test_template_override_markup_compiles_to_extension_json(): void
    {
        $file = "{$this->tmp}/frag.g7.html";
        File::put($file, "<extension extension-point=\"address_search_slot\" priority=\"50\"/>\n");

        $json = $this->invokeProtected('compileMarkupFragmentFile', [$file]);

        $this->assertSame('address_search_slot', $json['extension_point']);
        $this->assertSame(50, $json['priority']);
    }
}
