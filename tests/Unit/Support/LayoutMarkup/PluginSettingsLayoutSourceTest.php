<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Services\PluginSettingsService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 플러그인 설정 레이아웃의 소스 해석 — 마크업 우선순위 규약 수렴 회귀.
 *
 * `AbstractPlugin::getSettingsLayout()` 은 종전에 `plugin_settings.json` 을 하드코딩해,
 * 레이아웃을 읽는 9개 경로 중 **유일하게** `ResolvesLayoutInstallSource` 규약 밖에 있었다.
 * 그래서 플러그인 설정 UI 만 짝 `.json` 의 디스크 실재를 요구했고, 굽기를 빠뜨린 플러그인은
 * `getSettingsLayout()` 이 null 을 돌려 설정 화면이 통째로 사라졌다.
 *
 * 다른 경로들은 `.g7.html` 을 메모리에서 컴파일해 쓰므로 짝이 디스크에 없어도 동작한다.
 * 이 테스트는 설정 경로가 같은 규약을 따르는지 고정한다.
 */
class PluginSettingsLayoutSourceTest extends TestCase
{
    /**
     * 파싱 대상 플러그인 — 번들 플러그인 중 설정 레이아웃을 가진 것.
     */
    private const PLUGIN = 'sirsoft-gdpr';

    /**
     * 설정 레이아웃 소스 경로(확장자 제외)를 반환합니다.
     *
     * @return string 저장소 기준 절대 경로(확장자 없음)
     */
    private function layoutBase(): string
    {
        return base_path('plugins/_bundled/'.self::PLUGIN.'/resources/layouts/admin/plugin_settings');
    }

    /**
     * private parseLayoutFile() 을 호출합니다.
     *
     * @param  string  $path  레이아웃 파일 경로
     * @return array|null 파싱 결과
     */
    private function parse(string $path): ?array
    {
        $method = new ReflectionMethod(PluginSettingsService::class, 'parseLayoutFile');
        $method->setAccessible(true);

        return $method->invoke(app(PluginSettingsService::class), $path);
    }

    /**
     * 마크업 소스가 메모리에서 컴파일되어 레이아웃 배열이 된다.
     *
     * 짝 `.json` 을 읽는 것이 아니라 `.g7.html` 자체를 컴파일하는지 본다 — 이것이 성립해야
     * 굽기 없이도 설정 화면이 뜬다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects plugin_settings_layout_compiles_markup_in_memory
     *
     * @return void 반환값 없음
     */
    public function test_markup_source_compiles_in_memory(): void
    {
        $markup = $this->layoutBase().'.g7.html';
        $this->assertFileExists($markup, '번들 플러그인 설정 마크업 소스가 없다 — 모집단이 어긋났다');

        $layout = $this->parse($markup);

        $this->assertIsArray($layout, '마크업 소스가 레이아웃 배열로 컴파일되지 않았다');
        $this->assertArrayHasKey('slots', $layout, '컴파일 산출에 slots 가 없다 — 설정 폼이 그려지지 않는다');
    }

    /**
     * 마크업과 짝 JSON 이 같은 레이아웃을 낸다.
     *
     * 우선순위가 바뀌어도(`--layout-source=json`) 화면이 달라지지 않아야 규약이 성립한다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects plugin_settings_layout_falls_back_to_json_twin
     *
     * @return void 반환값 없음
     */
    public function test_json_twin_yields_the_same_layout(): void
    {
        $json = $this->layoutBase().'.json';
        $this->assertFileExists($json, '짝 JSON 이 없다 — 굽기가 최신이 아니다');

        $fromMarkup = $this->parse($this->layoutBase().'.g7.html');
        $fromJson = $this->parse($json);

        $this->assertIsArray($fromJson);
        $this->assertSame(
            array_keys($fromMarkup ?? []),
            array_keys($fromJson),
            '마크업 산출과 짝 JSON 의 최상위 키가 다르다 — 굽기가 갈렸거나 컴파일 규약이 어긋났다'
        );
    }

    /**
     * getSettingsLayout() 이 마크업 소스를 먼저 고른다.
     *
     * 짝이 둘 다 있을 때 어느 쪽을 돌려주는지가 규약의 핵심이다. `.json` 을 고르면 종전
     * 동작이고, 그 경우 굽기를 빠뜨린 플러그인에서 화면이 사라지는 결함이 되살아난다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects plugin_settings_layout_resolves_markup_source_first
     *
     * @return void 반환값 없음
     */
    public function test_get_settings_layout_prefers_markup_source(): void
    {
        $plugin = app(\App\Extension\PluginManager::class)->getPlugin(self::PLUGIN);

        if (! $plugin) {
            $this->markTestSkipped(self::PLUGIN.' 플러그인이 설치되지 않았다');
        }

        $resolved = $plugin->getSettingsLayout();

        $this->assertIsString($resolved, 'getSettingsLayout() 이 경로를 돌려주지 않았다');
        $this->assertStringEndsWith(
            '.g7.html',
            str_replace('\\', '/', $resolved),
            'getSettingsLayout() 이 짝 .json 을 골랐다 — 소스 우선순위 규약 밖으로 되돌아갔다'
        );
    }
}
