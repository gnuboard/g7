<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\TemplateResource;
use Tests\TestCase;

/**
 * TemplateResource abilities 테스트
 *
 * TemplateResource의 abilityMap에 레이아웃 편집 권한이 포함되어 있는지 검증합니다.
 */
class TemplateResourceAbilityTest extends TestCase
{
    /**
     * abilityMap에 can_edit_layouts가 포함되어 있는지 확인
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
     * abilityMap에 기존 권한이 유지되어 있는지 확인
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
