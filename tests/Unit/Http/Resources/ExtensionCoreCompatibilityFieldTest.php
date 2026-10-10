<?php

namespace Tests\Unit\Http\Resources;

use App\Extension\CoreVersionChecker;
use App\Http\Resources\ModuleResource;
use App\Http\Resources\PluginResource;
use App\Http\Resources\TemplateResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 확장 목록 행의 코어 호환 여부(`core_compatible`) 노출 (#703)
 *
 * 코어 요구 버전(`g7_version`)을 만족하지 못하는 확장은 서버가 설치·활성을 422
 * (`core_version_mismatch`)로 거절한다. 목록 행이 그 판정을 싣지 않으면 화면은 설치 버튼과
 * 활성 스위치를 켜 둔 채 실패만 보여 준다. 판정은 서버와 같은 한 곳
 * (`CoreVersionChecker::isCompatible`)을 읽는다.
 *
 * 시나리오 매니페스트: tests/scenarios/admin-action-gate-parity.yaml
 */
class ExtensionCoreCompatibilityFieldTest extends TestCase
{
    /**
     * 리소스를 목록 배열로 변환합니다.
     *
     * @param  class-string  $resourceClass  리소스 클래스
     * @param  array<string, mixed>  $row  목록 행 원본
     * @return array<string, mixed> 변환 결과
     */
    private function toListArray(string $resourceClass, array $row): array
    {
        return (new $resourceClass($row))->toArray(Request::create('/'));
    }

    /**
     * 현재 코어가 만족할 수 없는 요구 버전을 가진 확장은 `core_compatible=false` 다.
     *
     * @scenario defect=extension_core_incompatible_actions
     *
     * @effects extension_row_exposes_core_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_incompatible_requirement_is_exposed_as_false(): void
    {
        $row = ['identifier' => 'vendor-x', 'status' => 'inactive', 'requires_core' => '>=999.0.0'];
        $this->assertFalse(CoreVersionChecker::isCompatible('>=999.0.0'), '전제: 현재 코어는 999 미만이어야 합니다.');

        foreach ([ModuleResource::class, PluginResource::class, TemplateResource::class] as $class) {
            $this->assertFalse($this->toListArray($class, $row)['core_compatible'], $class);
        }
    }

    /**
     * 요구 버전이 없거나 만족하면 `core_compatible=true` 다(과차단 금지).
     *
     * @scenario defect=extension_core_incompatible_actions
     *
     * @effects extension_row_exposes_core_compatibility
     *
     * @return void 반환값 없음
     */
    public function test_compatible_or_missing_requirement_is_exposed_as_true(): void
    {
        foreach ([ModuleResource::class, PluginResource::class, TemplateResource::class] as $class) {
            $this->assertTrue($this->toListArray($class, ['identifier' => 'vendor-x', 'status' => 'inactive'])['core_compatible'], $class);
            $this->assertTrue($this->toListArray($class, ['identifier' => 'vendor-x', 'status' => 'inactive', 'requires_core' => '>=0.0.1'])['core_compatible'], $class);
        }
    }
}
