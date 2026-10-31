<?php

namespace Tests\Unit\Services\Extension;

use App\Enums\LanguagePackScope;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 설치 미리보기 — 이미 충족된 의존 확장의 언어팩은 의존 선택에 묶이지 않는다 (Q-G2 Hello 실측)
 *
 * 설치 창은 `depends_on_extension` 이 있는 언어팩을 「그 의존 확장을 함께 설치하도록 골랐을 때」만 켤 수 있게 한다.
 * 그런데 이미 설치·활성화되어 충족된 의존 확장은 고를 수 없는 항목(`available:false`)이라, 그 확장의 언어팩은
 * 늘 꺼진 채 잠겨 함께 설치할 길이 없었다(실측: Hello 모듈을 켠 뒤 Hello 플러그인 설치 창 — Hello 모듈 ja 언어팩
 * 체크박스 비활성, 요청 본문에서 빠짐). 충족된 의존 확장의 언어팩은 의존 선택과 무관하게 고를 수 있어야 한다.
 *
 * 시나리오 매니페스트: tests/scenarios/extension-install-language-pack-failures.yaml
 */
class InstallPreviewMetDependencyLanguagePackTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 의존 확장 메타 한 건으로 언어팩 후보를 만든다.
     *
     * @param  bool  $isMet  의존성 충족 여부
     * @return array<int, array<string, mixed>> 언어팩 후보
     */
    private function candidatesFor(bool $isMet): array
    {
        $builder = app(ExtensionInstallPreviewBuilder::class);
        $method = new ReflectionMethod($builder, 'buildLanguagePacks');

        return $method->invoke($builder, LanguagePackScope::Plugin, 'gnuboard7-hello_plugin', [[
            'type' => 'module',
            'identifier' => 'gnuboard7-hello_module',
            'name' => 'Hello',
            'required_version' => '>=0.1.0',
            'installed_version' => $isMet ? '0.2.0' : null,
            'is_installed' => $isMet,
            'is_active' => $isMet,
            'is_met' => $isMet,
            'available' => ! $isMet,
            'default_selected' => ! $isMet,
        ]]);
    }

    /**
     * 후보 중 의존 확장(Hello 모듈)의 언어팩 행을 찾는다.
     *
     * @param  array<int, array<string, mixed>>  $candidates  언어팩 후보
     * @return array<string, mixed>|null 행
     */
    private function dependencyRow(array $candidates): ?array
    {
        foreach ($candidates as $row) {
            if (str_starts_with($row['bundled_identifier'], 'g7-module-gnuboard7-hello_module-')) {
                return $row;
            }
        }

        return null;
    }

    /**
     * 충족된 의존 확장의 언어팩은 의존 선택에 묶이지 않는다.
     *
     * @scenario case=met_dependency_language_pack_unbound
     *
     * @effects met_dependency_language_pack_selectable
     *
     * @return void 반환값 없음
     */
    public function test_met_dependency_language_pack_is_not_bound_to_dependency_selection(): void
    {
        $row = $this->dependencyRow($this->candidatesFor(true));

        $this->assertNotNull($row, 'Hello 모듈 번들 언어팩 후보가 있어야 한다(동봉 ja).');
        $this->assertNull($row['depends_on_extension']);
        $this->assertSame('gnuboard7-hello_module', $row['target_identifier'] ?? null, '어느 확장의 언어팩인지는 남아야 창이 구분해 보인다.');
    }

    /**
     * 아직 충족되지 않은 의존 확장의 언어팩은 종전처럼 그 의존 선택에 묶인다(과허용 금지).
     *
     * @scenario case=unmet_dependency_language_pack_bound
     *
     * @effects met_dependency_language_pack_selectable
     *
     * @return void 반환값 없음
     */
    public function test_unmet_dependency_language_pack_stays_bound(): void
    {
        $row = $this->dependencyRow($this->candidatesFor(false));

        $this->assertNotNull($row);
        $this->assertSame('gnuboard7-hello_module', $row['depends_on_extension']);
    }
}
