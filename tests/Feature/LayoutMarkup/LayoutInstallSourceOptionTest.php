<?php

namespace Tests\Feature\LayoutMarkup;

use Tests\TestCase;

/**
 * `--layout-source` 옵션 → 런타임 설정 홉 테스트 (33차 검출 ⚠️F1).
 *
 * forced-json 축 테스트(MarkupInstallSourceOverrideTest·MarkupPartialResolutionTest 등)는 전부
 * `config(['template.layout_markup.install_source' => 'json'])` 를 **직접** 세팅해 우선순위 역전을
 * 검증한다 — 즉 CLI 옵션이 그 설정에 도달하는 홉(`SelectsLayoutInstallSource::applyLayoutInstallSource()`)
 * 은 어느 테스트에도 관측되지 않았다. 계약의 양끝 중 한쪽 끝만 잠긴 상태였다.
 *
 * 여기서는 트레이트를 실제로 쓰는 커맨드(`module:refresh-layout`)를 그대로 태워 세 가지를 고정한다:
 *
 *  - `--layout-source=json` → 설정이 `json` 으로 반영되고 진단용 경고가 출력된다.
 *  - 값은 정규화된다 (대소문자·공백).
 *  - 알 수 없는 값 → 설정을 건드리지 않고 즉시 실패한다 (엉뚱한 소스로 설치가 진행되지 않는다).
 *
 * 커맨드의 뒷단(모듈 갱신 자체)은 이 테스트의 관심사가 아니므로 종료 코드는 invalid 케이스에서만
 * 단언한다 — 옵션 검증은 handle() 최상단에서 일어나 모듈 존재 여부와 무관하다.
 */
class LayoutInstallSourceOptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects layout_source_option_maps_to_runtime_config
     */
    public function test_json_option_reaches_runtime_config_and_warns(): void
    {
        $this->assertNotSame('json', config('template.layout_markup.install_source'),
            '전제: 기본 설정이 이미 json 이면 이 테스트는 홉을 관측하지 못한다');

        $this->artisan('module:refresh-layout', [
            'identifier' => 'nonexistent-module-for-option-probe',
            '--layout-source' => 'json',
        ])
            ->expectsOutputToContain(__('templates.layout_install_source.json_notice'))
            ->run();

        $this->assertSame('json', config('template.layout_markup.install_source'),
            '--layout-source=json 이 런타임 설정에 도달하지 않았다 — 옵션과 설정 사이 홉이 끊겼다');
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects layout_source_option_maps_to_runtime_config
     */
    public function test_option_value_is_normalized_before_config(): void
    {
        // 34차 — 형제 테스트는 이 전제를 명시 단언하는데 여기만 없었다. 기본 설정이 이미 `json`
        // 이면 `trim`+`strtolower` 를 통째로 지워도 이 단언은 초록이다.
        $this->assertNotSame('json', config('template.layout_markup.install_source'),
            '전제: 기본 설정이 이미 json 이면 이 테스트는 정규화를 관측하지 못한다');

        $this->artisan('module:refresh-layout', [
            'identifier' => 'nonexistent-module-for-option-probe',
            '--layout-source' => ' JSON ',
        ])->run();

        $this->assertSame('json', config('template.layout_markup.install_source'),
            '옵션 값 정규화(trim + 소문자)가 설정 반영 전에 일어나야 한다');
    }

    /**
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects invalid_layout_source_option_fails_without_config_change
     */
    public function test_unknown_option_fails_fast_without_touching_config(): void
    {
        $before = config('template.layout_markup.install_source');

        $this->artisan('module:refresh-layout', [
            'identifier' => 'nonexistent-module-for-option-probe',
            '--layout-source' => 'bogus',
        ])
            ->expectsOutputToContain(__('templates.layout_install_source.invalid', ['value' => 'bogus']))
            ->assertExitCode(1)
            ->run();

        $this->assertSame($before, config('template.layout_markup.install_source'),
            '알 수 없는 --layout-source 값은 설정을 건드리기 전에 거부돼야 한다');
    }
}
