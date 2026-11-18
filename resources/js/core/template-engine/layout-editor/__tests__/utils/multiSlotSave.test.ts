/**
 * @file multiSlotSave.test.ts
 * @description 슬롯이 둘 이상인 자식 레이아웃의 편집 저장 — 편집분이 슬롯별로 저장되는지
 *
 * 종전 `stripInheritedFromLayoutContent` 는 슬롯 이름을 알 수 없어 슬롯이 둘 이상이면 원본 slots 를 그대로 보냈다.
 * 7.2.0 에서 홈·게시판 목록·쇼핑 목록·상품 상세·카테고리에 `page_top` 슬롯(히어로 자리)이 생기며 이 화면들의 편집이
 * 「저장됨」(PUT 200)인데 하나도 저장되지 않았다. 서버가 편집 모드 슬롯 래퍼에 `__slot`(슬롯 이름)을 달고,
 * 저장 마스킹이 그 래퍼별로 route 콘텐츠를 모은다.
 *
 * @scenario-manifest tests/scenarios/layout-editor-multi-slot-save.yaml
 */

import { describe, it, expect } from 'vitest';
import { stripInheritedFromLayoutContent, type EditorNode } from '../../utils/layoutTreeUtils';

/** 머지된 편집 트리 — base 래퍼 안에 두 슬롯 래퍼(page_top·content), content 안에 data-if 아래 Img */
function mergedContent(thumbPreset: string, slotMarkers = true): Record<string, unknown> {
  const wrapper = (slot: string, children: EditorNode[]): EditorNode =>
    ({
      type: 'basic',
      name: 'Div',
      __source: { kind: 'base', layout: '_user_base' },
      ...(slotMarkers ? { __slot: slot } : {}),
      children,
    }) as EditorNode;
  const route = (node: Record<string, unknown>): EditorNode =>
    ({ ...node, __source: { kind: 'route', layout: 'shop/category' } }) as EditorNode;

  return {
    version: '1.0.0',
    layout_name: 'shop/category',
    components: [
      {
        type: 'layout',
        name: 'Container',
        id: 'user_layout_root',
        _fromBase: true,
        __source: { kind: 'base', layout: '_user_base' },
        children: [
          { type: 'basic', name: 'Header', _fromBase: true, __source: { kind: 'base', layout: '_user_base' } },
          wrapper('page_top', [route({ type: 'extension_point', name: 'category_hero', id: 'category_hero_slot' })]),
          wrapper('content', [
            route({
              type: 'basic',
              name: 'Div',
              if: '{{(category?.data?.images ?? []).length > 0}}',
              children: [route({ type: 'basic', name: 'Img', props: { src: '{{category?.data?.images?.[0]?.preview_url}}', thumbPreset } })],
            }),
          ]),
          { type: 'basic', name: 'Footer', _fromBase: true, __source: { kind: 'base', layout: '_user_base' } },
        ],
      } as EditorNode,
    ],
    __editor: {
      original: {
        version: '1.0.0',
        layout_name: 'shop/category',
        extends: '_user_base',
        slots: {
          page_top: [{ type: 'extension_point', name: 'category_hero', id: 'category_hero_slot' }],
          content: [
            {
              type: 'basic',
              name: 'Div',
              if: '{{(category?.data?.images ?? []).length > 0}}',
              children: [{ type: 'basic', name: 'Img', props: { src: '{{category?.data?.images?.[0]?.preview_url}}', thumbPreset: 'lg' } }],
            },
          ],
        },
      },
    },
  };
}

describe('슬롯이 둘 이상인 자식 레이아웃 저장', () => {
  // @scenario case=multi_slot_edit_saved
  // @effects multi_slot_edits_reach_save_payload
  it('슬롯 래퍼의 __slot 으로 편집분을 슬롯별로 모아 저장한다(data-if 아래 Img 속성 포함)', () => {
    const saved = stripInheritedFromLayoutContent(mergedContent('md'));
    const slots = saved.slots as Record<string, EditorNode[]>;

    expect(Object.keys(slots).sort()).toEqual(['content', 'page_top']);
    expect(slots.page_top).toEqual([{ type: 'extension_point', name: 'category_hero', id: 'category_hero_slot' }]);
    const img = (slots.content[0]!.children as EditorNode[])[0]!;
    expect((img.props as Record<string, unknown>).thumbPreset).toBe('md');
    // 편집기 전용 메타는 저장에 남지 않는다
    expect(JSON.stringify(saved)).not.toContain('__source');
    expect(JSON.stringify(saved)).not.toContain('__slot');
    expect(saved).not.toHaveProperty('components');
  });

  // @scenario case=multi_slot_legacy_response
  // @effects multi_slot_without_markers_keeps_original
  it('슬롯 표식이 없는 응답(옛 서버)은 종전처럼 원본 slots 를 보존한다', () => {
    const saved = stripInheritedFromLayoutContent(mergedContent('md', false));
    const content = (saved.slots as Record<string, EditorNode[]>).content;
    const img = (content[0]!.children as EditorNode[])[0]!;

    expect((img.props as Record<string, unknown>).thumbPreset).toBe('lg');
  });
});
