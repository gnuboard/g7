<?php

namespace Tests\Unit\Resources;

use App\Enums\ExtensionStatus;
use App\Http\Resources\TemplateResource;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 템플릿 목록·상세 응답의 의존 충족 표시 — 카드 미충족 표시와 dependencies_met 가 활성화 판정과 같다 (#152 F10·F3)
 *
 * 회귀: 목록 응답의 의존 항목이 `{identifier, name, type}` 뿐이라 카드가 충족·미충족을 구분하지 못했고, 응답의 충족 판정은
 * 활성화 경로와 다른 사본이라 빈 제약에서 서로 엇갈렸다.
 *
 * 시나리오 매니페스트: tests/scenarios/template-dependency-version.yaml
 */
class TemplateResourceDependencyStatusTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 의존 대상(모듈 0.1.0 활성·플러그인 1.1.2 꺼짐)을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Module::create([
            'identifier' => 'test-dep152-mod', 'vendor' => 'test', 'name' => ['ko' => '의존 모듈', 'en' => 'Dep Module'],
            'version' => '0.1.0', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        Plugin::create([
            'identifier' => 'test-dep152-plg', 'vendor' => 'test', 'name' => ['ko' => '의존 플러그인', 'en' => 'Dep Plugin'],
            'version' => '1.1.2', 'status' => ExtensionStatus::Inactive->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
    }

    /**
     * 응답 배열을 만든다.
     *
     * @param  array<string, mixed>  $dependencies  manifest 의존 선언
     * @param  bool  $detail  상세 응답이면 true
     * @return array<string, mixed> 응답 배열
     */
    private function resource(array $dependencies, bool $detail = false): array
    {
        $resource = new TemplateResource([
            'identifier' => 'test-dep152-tpl',
            'name' => ['ko' => '시험', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => 'inactive',
            'dependencies' => $dependencies,
        ]);

        return $detail ? $resource->toDetailArray() : $resource->toArray(Request::create('/api/admin/templates'));
    }

    /**
     * 목록 의존 항목이 충족 여부·사유·버전을 싣고 dependencies_met 가 거짓이다.
     *
     * @scenario surface=resource_list_status
     *
     * @effects list_dependencies_carry_status
     *
     * @return void 반환값 없음
     */
    public function test_list_dependencies_carry_met_status_and_versions(): void
    {
        $array = $this->resource(['modules' => ['test-dep152-mod' => '>=9.9.9'], 'plugins' => ['test-dep152-plg' => '>=1.0.0']]);

        $module = $array['dependencies']['modules'][0];
        $this->assertFalse($module['is_met']);
        $this->assertSame('version_mismatch', $module['status']);
        $this->assertSame('>=9.9.9', $module['required_version']);
        $this->assertSame('0.1.0', $module['installed_version']);
        $this->assertSame('inactive', $array['dependencies']['plugins'][0]['status']);
        $this->assertFalse($array['dependencies_met']);
    }

    /**
     * 빈 제약·null·목록형은 충족이고(활성화와 같은 판정) 상세 응답도 같은 사유를 싣는다.
     *
     * @scenario surface=resource_met_agree
     *
     * @effects resource_met_matches_activation
     *
     * @return void 반환값 없음
     */
    public function test_empty_constraints_are_met_like_activation(): void
    {
        foreach ([['test-dep152-mod' => ''], ['test-dep152-mod' => null], ['test-dep152-mod']] as $modules) {
            $array = $this->resource(['modules' => $modules]);
            $this->assertTrue($array['dependencies_met'], json_encode($modules));
            $this->assertTrue($array['dependencies']['modules'][0]['is_met']);
        }

        $detail = $this->resource(['modules' => ['test-dep152-mod' => '>=9.9.9']], true);
        $this->assertSame('version_mismatch', $detail['dependencies']['modules'][0]['status']);
        $this->assertFalse($detail['dependencies']['modules'][0]['is_met']);
        $this->assertTrue($detail['dependencies']['modules'][0]['is_active']);
    }
}
