/**
 * buildDropSlots — 디바이스 분기 컨테이너(`{노드}.responsive.{key}`)의 드롭 칸
 *
 * 결함: 모바일 보기에서 분기 안 자식을 끌면 같은 분기 안에 드롭 칸이 생기지 않았다. 분기 자식 path 는
 * `X.responsive.portable.children.N` 이라 부모 컨테이너가 `X.responsive.portable` 인데, 그 path 를 단 DOM 요소는
 * 없다(분기를 가진 노드 자신이 `X`). 컨테이너 요소를 못 찾아 그 컨테이너를 건너뛰었다(HEAD 번들에서도 실패).
 *
 * 고침: 분기 컨테이너도 footer 가상 컨테이너처럼 첫 자식 요소의 부모(분기 자식을 그리는 자리)를 컨테이너
 * 요소로 본다.
 *
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): branch_drop_slots_render
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { buildDropSlots } from '../../dnd/dropSlots';

function rect(left: number, top: number, width: number, height: number): DOMRect {
  return { left, top, width, height, right: left + width, bottom: top + height, x: left, y: top, toJSON: () => ({}) } as DOMRect;
}

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

describe('분기 컨테이너 드롭 칸', () => {
  /** @effects branch_drop_slots_render */
  it('분기 자식 사이와 끝에 `{노드}.responsive.{key}` 컨테이너 칸이 생긴다', () => {
    const frame = document.createElement('div');
    const owner = document.createElement('div');
    owner.setAttribute('data-editor-path', '0');
    const a = document.createElement('div');
    a.setAttribute('data-editor-path', '0.responsive.portable.children.0');
    const b = document.createElement('div');
    b.setAttribute('data-editor-path', '0.responsive.portable.children.1');
    owner.append(a, b);
    frame.appendChild(owner);
    document.body.appendChild(frame);
    const rects = new Map<Element, DOMRect>([
      [frame, rect(0, 0, 390, 600)],
      [owner, rect(0, 0, 390, 200)],
      [a, rect(0, 0, 390, 80)],
      [b, rect(0, 100, 390, 80)],
    ]);
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
      return rects.get(this) ?? rect(0, 0, 0, 0);
    });
    vi.spyOn(window, 'getComputedStyle').mockImplementation(
      () => ({ display: 'block', flexDirection: '', flexWrap: '' }) as unknown as CSSStyleDeclaration,
    );

    const slots = buildDropSlots({
      frameEl: frame,
      draggedPath: '0.responsive.portable.children.0',
      acceptsContainer: () => true,
      allowsNestSlot: () => false,
    });
    const branchSlots = slots.filter((s) => s.containerPath === '0.responsive.portable' && s.kind === 'gap');
    expect(branchSlots.length).toBeGreaterThan(0);
    expect(branchSlots.map((s) => s.index).sort((x, y) => x - y)).toEqual([1, 2]);
  });
});
