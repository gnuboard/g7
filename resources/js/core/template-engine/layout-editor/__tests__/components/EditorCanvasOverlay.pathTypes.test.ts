/**
 * 편집기 path 타입 정합 — 타입 오류(tsc)로 드러난 실제 값 모양 차이 잠금 (#869 이월 0)
 *
 * - `adaptInsertChild`: 인플레이스 오버레이 계약 `onInsertChild(node, parentPath: string, index)` 의 문자열 path 를
 *   코어 삽입(ComponentPath)으로 바꾼다. 종전에는 문자열을 그대로 넘겨 배열로 다뤘다(TS2769).
 * - `lastNodeIndex`: 노드 path 의 형제 위치. 배열 전환 세그먼트로 끝나면 0(TS2365).
 * - `collectIterationVars`: 분기·footer 세그먼트를 따라 내려간다. 종전 호출자는 숫자 세그먼트만 남겨 넘겨 다른
 *   노드의 반복 변수를 모았다(tsc 가 못 잡은 실제 결함).
 *
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): editor_footer_node_patch_remove_move
 */

import { describe, it, expect, vi } from 'vitest';
import { adaptInsertChild, lastNodeIndex } from '../../components/EditorCanvasOverlay';
import { collectIterationVars } from '../../spec/bindingCandidates';

describe('[case:wysiwyg-12] path 타입 정합', () => {
  /** @effects editor_footer_node_patch_remove_move */
  it('adaptInsertChild 는 DOM path 문자열을 ComponentPath 로 바꿔 넘긴다(footer 가상 컨테이너 포함)', () => {
    const insert = vi.fn();
    const onInsertChild = adaptInsertChild(insert);
    const node = { name: 'Button', type: 'basic' };
    onInsertChild(node, '0.children.2', 1);
    expect(insert).toHaveBeenLastCalledWith(node, [0, 2], 1);
    onInsertChild(node, '0.props.footer', 0);
    expect(insert).toHaveBeenLastCalledWith(node, [0, { propSlot: 'footer' }], 0);
  });

  /** @effects editor_footer_node_patch_remove_move */
  it('lastNodeIndex 는 number 로 끝나면 그 값, 배열 전환 세그먼트나 빈 path 면 0', () => {
    expect(lastNodeIndex([0, 3])).toBe(3);
    expect(lastNodeIndex([0, { propSlot: 'footer' }, 1])).toBe(1);
    expect(lastNodeIndex([0, { responsive: 'portable' }])).toBe(0);
    expect(lastNodeIndex([])).toBe(0);
  });

  /** @effects editor_footer_node_patch_remove_move */
  it('collectIterationVars 는 footer·분기 세그먼트를 따라가 그 노드의 반복 조상만 모은다', () => {
    const root = {
      children: [
        {
          name: 'Div',
          iteration: { source: '{{rows}}', item_var: 'row', index_var: 'i' },
          children: [{ name: 'Span' }, { name: 'Span', iteration: { source: '{{wrong}}', item_var: 'wrong' } }],
          props: {
            footer: [
              { name: 'Button' },
              { name: 'Button', children: [{ name: 'Span', iteration: { source: '{{tags}}', item_var: 'tag' } }] },
            ],
          },
          responsive: { portable: { children: [{ name: 'B' }, { name: 'B' }] } },
        },
      ],
    };
    // footer 의 둘째 단추 — 반복 조상은 0번 Div(row, i) 뿐. 숫자만 남기면 [0,1] 로 children[1](wrong)을 탔다.
    const viaFooter = collectIterationVars(root as never, [0, { propSlot: 'footer' }, 1]);
    expect(viaFooter.map((v) => v.name).sort()).toEqual(['i', 'row']);
    // footer 단추 안 반복 자식 — 세그먼트를 따라 끝까지 내려가야 tag 가 잡힌다.
    const deep = collectIterationVars(root as never, [0, { propSlot: 'footer' }, 1, 0]);
    expect(deep.map((v) => v.name).sort()).toEqual(['i', 'row', 'tag']);
    const viaBranch = collectIterationVars(root as never, [0, { responsive: 'portable' }, 1]);
    expect(viaBranch.map((v) => v.name).sort()).toEqual(['i', 'row']);
    // 숫자 path 는 종전과 같다
    expect(collectIterationVars(root as never, [0, 1]).map((v) => v.name).sort()).toEqual(['i', 'row', 'wrong']);
  });
});
