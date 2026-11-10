<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\TemplateResource;
use Tests\PureTestCase;

/**
 * TemplateResource abilities 테스트
 *
 * TemplateResource의 abilityMap에 레이아웃 편집 권한이 포함되어 있는지 검증합니다.
 */
class TemplateResourceAbilityTest extends PureTestCase
{
    /**
     * abilityMap 이 can_edit_layouts 를 core.templates.layouts.edit 권한에 연결한다.
     *
     * @scenario case=layout_edit_ability
     *
     * @effects can_edit_layouts_mapped_to_layout_edit_permission
     *
     * @return void 반환값 없음
     */
    public function test_ability_map_includes_can_edit_layouts(): void
    {
        $resource = new TemplateResource(['id' => 1]);

        $reflection = new \ReflectionMethod($resource, 'abilityMap');
        $abilityMap = $reflection->invoke($resource);

        $this->assertArrayHasKey('can_edit_layouts', $abilityMap);
        $this->assertEquals('core.templates.layouts.edit', $abilityMap['can_edit_layouts']);
    }

    /**
     * abilityMap 이 설치·활성화·삭제 권한을 유지한 채 정확히 4개 항목만 갖는다.
     *
     * @scenario case=existing_abilities
     *
     * @effects existing_template_abilities_preserved
     *
     * @return void 반환값 없음
     */
    public function test_ability_map_includes_existing_permissions(): void
    {
        $resource = new TemplateResource(['id' => 1]);

        $reflection = new \ReflectionMethod($resource, 'abilityMap');
        $abilityMap = $reflection->invoke($resource);

        $this->assertArrayHasKey('can_install', $abilityMap);
        $this->assertArrayHasKey('can_activate', $abilityMap);
        $this->assertArrayHasKey('can_uninstall', $abilityMap);
        $this->assertArrayHasKey('can_edit_layouts', $abilityMap);
        $this->assertCount(4, $abilityMap);
    }
}
