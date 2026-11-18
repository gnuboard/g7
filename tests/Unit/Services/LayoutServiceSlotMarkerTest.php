<?php

namespace Tests\Unit\Services;

use App\Services\LayoutService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 편집 모드 머지 트리의 슬롯 래퍼에 슬롯 이름(`__slot`)이 붙는지 검증합니다.
 *
 * 편집기 저장 마스킹은 머지 트리에서 자식 콘텐츠를 뽑아 slots 로 되돌린다. 슬롯 래퍼에 이름이 없으면 슬롯이 둘 이상인
 * 화면(7.2.0 의 page_top + content)의 편집분을 어느 슬롯에 넣을지 몰라 원본을 그대로 보냈다 — 「저장됨」인데 저장되지 않음.
 * 표식은 편집 모드(출처 메타가 있을 때)에만 붙고 공개 렌더 트리에는 없다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/layout-editor-multi-slot-save.yaml 참조.
 */
class LayoutServiceSlotMarkerTest extends TestCase
{
    /**
     * 편집 모드에서는 슬롯 래퍼마다 슬롯 이름이, 공개 머지에서는 표식이 없다.
     *
     * @scenario case=server_marks_slot_wrapper
     *
     * @effects edit_mode_slot_wrapper_carries_slot_name
     *
     * @return void 반환값 없음
     */
    public function test_edit_mode_slot_wrappers_carry_slot_name(): void
    {
        $merge = new ReflectionMethod(LayoutService::class, 'mergeComponents');
        $merge->setAccessible(true);
        $service = app(LayoutService::class);

        $parent = [[
            'type' => 'layout',
            'name' => 'Container',
            'id' => 'root',
            'children' => [
                ['type' => 'basic', 'name' => 'Div', 'id' => 'top', 'slot' => 'page_top'],
                ['type' => 'basic', 'name' => 'Div', 'id' => 'main', 'slot' => 'content'],
            ],
        ]];
        $slots = [
            'page_top' => [['type' => 'basic', 'name' => 'P', 'id' => 'hero']],
            'content' => [['type' => 'basic', 'name' => 'Img', 'id' => 'banner', 'props' => ['thumbPreset' => 'lg']]],
        ];

        $edit = $merge->invoke($service, $parent, $slots, ['kind' => 'base', 'layout' => '_user_base']);
        $wrappers = $edit[0]['children'];
        $this->assertSame('page_top', $wrappers[0]['__slot'] ?? null);
        $this->assertSame('content', $wrappers[1]['__slot'] ?? null);
        $this->assertSame('banner', $wrappers[1]['children'][0]['id']);

        $public = $merge->invoke($service, $parent, $slots, null);
        $this->assertArrayNotHasKey('__slot', $public[0]['children'][0]);
        $this->assertArrayNotHasKey('__slot', $public[0]['children'][1]);
    }
}
