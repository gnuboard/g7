/**
 * dropSlots.test.ts — 명시적 드롭 슬롯 열거
 *
 * 기하 추론 폐기 후 dnd-kit useDroppable 타깃으로 쓸 슬롯을 DOM 기하에서 열거한다.
 * jsdom 은 레이아웃 엔진이 없어 getBoundingClientRect/getComputedStyle 를 모킹한다.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { buildDropSlots, parseSlotId } from '../../dnd/dropSlots';

interface Rect {
  left: number;
  top: number;
  width: number;
  height: number;
}
function domRect(r: Rect): DOMRect {
  return {
    ...r,
    right: r.left + r.width,
    bottom: r.top + r.height,
    x: r.left,
    y: r.top,
    toJSON: () => ({}),
  } as DOMRect;
}

/** frame + (path,rect,parent,display) 자식들. parent 미지정 = frame 직속 */
function buildFrame(
  children: Array<{ path: string; rect: Rect; parent?: string; display?: string }>,
  frameDisplay = 'block'
): HTMLElement {
  const frame = document.createElement('div');
  vi.spyOn(frame, 'getBoundingClientRect').mockReturnValue(
    domRect({ left: 0, top: 0, width: 600, height: 400 })
  );
  const styleMap = new Map<Element, Record<string, string>>();
  styleMap.set(frame, { display: frameDisplay, flexDirection: '', flexWrap: '' });
  const byPath = new Map<string, HTMLElement>();
  for (const c of children) {
    const el = document.createElement('div');
    el.dataset.editorPath = c.path;
    el.setAttribute('data-editor-path', c.path);
    vi.spyOn(el, 'getBoundingClientRect').mockReturnValue(domRect(c.rect));
    styleMap.set(el, { display: c.display ?? 'block', flexDirection: '', flexWrap: '' });
    byPath.set(c.path, el);
    (c.parent ? byPath.get(c.parent)! : frame).appendChild(el);
  }
  vi.spyOn(window, 'getComputedStyle').mockImplementation(
    (el: Element) =>
      (styleMap.get(el) ?? { display: 'block', flexDirection: '', flexWrap: '' }) as unknown as CSSStyleDeclaration
  );
  document.body.appendChild(frame);
  return frame;
}

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

describe('parseSlotId', () => {
  it('slot:<containerPath>:<index> 파싱', () => {
    expect(parseSlotId('slot::2')).toEqual({ containerPath: '', index: 2 });
    expect(parseSlotId('slot:0.children.1:0')).toEqual({ containerPath: '0.children.1', index: 0 });
    expect(parseSlotId('not-a-slot')).toBeNull();
  });
});

describe('buildDropSlots — 루트 형제 gap 슬롯', () => {
  it('루트 직속 자식 N개 → 비드래그 자식 앞 gap + 끝 gap (원본 트리 인덱스)', () => {
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 600, height: 100 } }, // 드래그(orig 0)
      { path: '1', rect: { left: 0, top: 100, width: 600, height: 100 } },
      { path: '2', rect: { left: 0, top: 200, width: 600, height: 100 } },
    ]);
    const slots = buildDropSlots({
      frameEl: frame,
      draggedPath: '0',
      acceptsContainer: (p) => p === '',
      allowsNestSlot: () => false,
    });
    const rootGaps = slots.filter((s) => s.containerPath === '' && s.kind === 'gap');
    // 비드래그 자식 [1(orig1), 2(orig2)] → 앞 gap = origIndex 1,2 + 끝 gap = 마지막 origIndex+1 = 3.
    // (드래그 노드 0 은 경계에서 빠지지만 인덱스는 원본 트리 기준 유지.)
    expect(rootGaps.map((s) => s.index).sort((a, b) => a - b)).toEqual([1, 2, 3]);
    expect(rootGaps.every((s) => s.orientation === 'horizontal')).toBe(true); // block flow → 가로 띠
  });

  it('accepts=false 면 gap 슬롯 미생성', () => {
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 600, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 600, height: 100 } },
    ]);
    const slots = buildDropSlots({
      frameEl: frame,
      draggedPath: '0',
      acceptsContainer: () => false,
      allowsNestSlot: () => false,
    });
    expect(slots.filter((s) => s.containerPath === '').length).toBe(0);
  });
});

describe('buildDropSlots — display:contents 래퍼 (검수 핵심: 자식 rect 기준)', () => {
  it('contents 래퍼(rect 0) 안 카드들의 gap 슬롯이 자식 rect 기준으로 생성', () => {
    // 0 = contents 래퍼(0 크기), 0.children.0/1 = 카드(실제 rect)
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 0, height: 0 }, display: 'contents' },
      { path: '0.children.0', rect: { left: 0, top: 0, width: 180, height: 180 }, parent: '0' },
      { path: '0.children.1', rect: { left: 200, top: 0, width: 180, height: 180 }, parent: '0' },
    ]);
    const slots = buildDropSlots({
      frameEl: frame,
      draggedPath: '0.children.0', // 첫 카드 드래그
      acceptsContainer: (p) => p === '0' || p === '',
      allowsNestSlot: () => false,
    });
    const wrapperGaps = slots.filter((s) => s.containerPath === '0' && s.kind === 'gap');
    // base children [0.children.1] → index 0(앞) + 1(끝) = 2 슬롯. rect 0 래퍼여도 자식 rect 로 생성.
    expect(wrapperGaps.length).toBe(2);
    // 슬롯 box 는 자식(200,0,180,180) 기준 좌표 — 0 크기 래퍼가 아님
    expect(wrapperGaps.every((s) => s.box.height > 0 || s.box.width > 0)).toBe(true);
  });
});

describe('buildDropSlots — contents 래퍼 시각 흐름 투명화', () => {
  it('G 의 contents 래퍼(W) 자식은 G 레벨 경계에서 제외하되, 일반 형제(Welcome) 주변 gap 은 유지', () => {
    // G(grid) 의 자식: Welcome(0, 실제 rect) + W(1, contents rect 0). W 자손 = 카드 2개.
    // 드래그 노드는 W 안의 card0.
    //  - contents 래퍼 W 위치에 G gap 을 만들면 카드가 G 로 튀어나가 reflow("딸려옴") → W 는 경계 제외.
    //  - 일반 형제 Welcome 주변 gap 은 유지 → 카드를 Welcome 옆 grid 위치로 옮기는 동작 허용(후속 요구).
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 600, height: 300 }, display: 'grid' }, // G
      { path: '0.children.0', rect: { left: 0, top: 0, width: 280, height: 300 }, parent: '0' }, // Welcome
      { path: '0.children.1', rect: { left: 0, top: 0, width: 0, height: 0 }, parent: '0', display: 'contents' }, // W
      { path: '0.children.1.children.0', rect: { left: 300, top: 0, width: 280, height: 140 }, parent: '0.children.1' }, // card0 (드래그)
      { path: '0.children.1.children.1', rect: { left: 300, top: 160, width: 280, height: 140 }, parent: '0.children.1' }, // card1
    ]);
    const slots = buildDropSlots({
      frameEl: frame,
      draggedPath: '0.children.1.children.0',
      acceptsContainer: () => true,
      allowsNestSlot: () => false,
      includeContainer: (p) => p === '0' || p === '0.children.1',
    });
    // G gap = Welcome(orig0) 주변만 → 앞(index 0) + 끝(index 1). contents 래퍼 W(orig1) 경계는 없음.
    const gGaps = slots.filter((s) => s.containerPath === '0' && s.kind === 'gap');
    expect(gGaps.map((s) => s.index).sort((a, b) => a - b), 'Welcome 주변 gap 만(W 경계 제외)').toEqual([0, 1]);
    // 재배치는 W 내부 gap 도 담당 — W 의 남은 카드(card1, orig1) 기준 앞/끝 = index 1, 2
    const wGaps = slots.filter((s) => s.containerPath === '0.children.1' && s.kind === 'gap');
    expect(wGaps.map((s) => s.index).sort((a, b) => a - b), 'W 내부 재배치 gap').toEqual([1, 2]);
  });

  it('채워진 형제 카드도 allowsNestSlot=true 면 nest 슬롯 생성 — 카드 내부 아이콘을 다른 카드로', () => {
    // card0 안의 아이콘(0.children.0.children.0)을 드래그. 형제 card1(채워짐)이 nest 타깃.
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 600, height: 200 }, display: 'grid' }, // G
      { path: '0.children.0', rect: { left: 0, top: 0, width: 280, height: 180 }, parent: '0' }, // card0
      { path: '0.children.0.children.0', rect: { left: 10, top: 10, width: 80, height: 40 }, parent: '0.children.0' }, // icon (드래그)
      { path: '0.children.1', rect: { left: 300, top: 0, width: 280, height: 180 }, parent: '0' }, // card1 (채워진 형제)
      { path: '0.children.1.children.0', rect: { left: 310, top: 10, width: 80, height: 40 }, parent: '0.children.1' }, // card1 내용
    ]);
    const slots = buildDropSlots({
      frameEl: frame,
      draggedPath: '0.children.0.children.0', // 아이콘
      acceptsContainer: () => true,
      // card1 은 채워졌지만 nest 허용(결함 a 수정 — accepting 컨테이너면 nest)
      allowsNestSlot: (p) => p === '0.children.1',
      includeContainer: (p) => p === '0.children.0' || p === '0.children.1' || p === '0',
    });
    // card1 은 채워져 있어 gap 슬롯(자식 사이) + 부모로 안 가고, nest 슬롯은 별도 분기.
    // 채워진 컨테이너는 children>0 분기라 gap 만 — nest 슬롯은 빈 컨테이너 분기에서만 생성됨.
    // 따라서 채워진 card1 으로의 nest 는 gap 슬롯(card1 내부 자식 사이/끝)으로 표현된다.
    const card1Slots = slots.filter((s) => s.containerPath === '0.children.1');
    expect(card1Slots.length, '형제 card1 으로 드롭할 슬롯이 존재(아이콘을 다른 카드로)').toBeGreaterThan(0);
  });
});

describe('buildDropSlots — nest 슬롯', () => {
  it('빈 컨테이너 + allowsNestSlot=true → 내부 영역 nest 슬롯 생성', () => {
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 600, height: 200 } }, // 드래그
      { path: '1', rect: { left: 0, top: 200, width: 600, height: 200 } }, // 빈 컨테이너
    ]);
    const slots = buildDropSlots({
      frameEl: frame,
      draggedPath: '0',
      acceptsContainer: (p) => p === '1' || p === '',
      allowsNestSlot: (p) => p === '1',
    });
    const nest = slots.find((s) => s.containerPath === '1' && s.kind === 'nest');
    expect(nest, '빈 컨테이너 1 에 nest 슬롯').toBeTruthy();
    expect(nest!.orientation).toBe('area');
  });

  it('includeContainer 로 관련 레벨만 슬롯 생성 — 카드 내부 자손 컨테이너 제외', () => {
    // G(grid) > W(contents) > card0/card1. card0 안에 내부 flex(inner) 자손이 있어
    // 레거시(includeContainer 미전달)면 inner 까지 슬롯이 깔린다. includeContainer 로
    // W(부모)/G(조상)/root 만 허용 → 카드 내부 inner 슬롯은 생성 안 됨.
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 600, height: 200 }, display: 'grid' }, // G
      { path: '0.children.0', rect: { left: 0, top: 0, width: 0, height: 0 }, parent: '0', display: 'contents' }, // W
      { path: '0.children.0.children.0', rect: { left: 0, top: 0, width: 280, height: 180 }, parent: '0.children.0' }, // card0 (드래그)
      { path: '0.children.0.children.1', rect: { left: 300, top: 0, width: 280, height: 180 }, parent: '0.children.0' }, // card1
      // card0 내부 자손(inner) — 슬롯이 깔리면 안 됨
      { path: '0.children.0.children.0.children.0', rect: { left: 10, top: 10, width: 100, height: 40 }, parent: '0.children.0.children.0' },
    ]);
    const draggedPath = '0.children.0.children.0';
    const slots = buildDropSlots({
      frameEl: frame,
      draggedPath,
      acceptsContainer: () => true,
      allowsNestSlot: () => false,
      // 관련 레벨 = W(부모) + G(조상) + root. card0 자신/자손은 제외(buildDropSlots 가 자체 제외),
      // card1(형제 카드, 채워짐)도 includeContainer 가 거부.
      includeContainer: (p) => p === '0.children.0' || p === '0',
    });
    const containers = new Set(slots.map((s) => s.containerPath));
    // W 내부 재배치 gap 은 생성. G(contents 래퍼 W 를 가짐)는 시각 흐름 투명화로 gap 미생성(결함 b).
    expect(containers.has('0.children.0'), 'W 내부 재배치 gap').toBe(true); // W
    expect(containers.has('0'), 'G 는 contents 래퍼 보유 → gap 미생성(break-out 차단)').toBe(false); // G
    // 핵심: 카드 내부 자손 컨테이너에는 슬롯이 깔리지 않는다 (레벨 제한 + 자손 제외)
    expect([...containers].some((c) => c.startsWith('0.children.0.children.1'))).toBe(false); // card1 내부 없음
    expect([...containers].some((c) => c === '0.children.0.children.0.children.0')).toBe(false); // card0 inner 없음
  });

  it('드래그 노드 자신/자손인 컨테이너는 슬롯 제외', () => {
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 600, height: 400 } }, // 드래그(컨테이너)
      { path: '0.children.0', rect: { left: 10, top: 10, width: 100, height: 100 }, parent: '0' },
    ]);
    const slots = buildDropSlots({
      frameEl: frame,
      draggedPath: '0',
      acceptsContainer: () => true,
      allowsNestSlot: () => true,
    });
    // 0 과 0.children.0 은 드래그 노드/자손 → 슬롯 없음. 루트만 가능.
    expect(slots.every((s) => s.containerPath === '')).toBe(true);
  });
});
