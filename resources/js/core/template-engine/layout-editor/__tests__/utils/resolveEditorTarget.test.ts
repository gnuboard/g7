// e2e:allow 순수 DOM 유틸 단위 테스트 — jsdom 으로 충분(네트워크/렌더 무관).
/**
 * resolveEditorTarget — 캔버스 클릭 좌표에서 편집 대상 노드 탐색
 *
 * 반복 항목 편집 모드의 dnd 핸들(`g7le-dnd-handle-<path>`)이 텍스트 위를 덮어 `e.target` 이
 * 핸들이 되어도, 핸들 testid path 역추출/좌표 폴백으로 실제 편집 노드를 찾는지 검증.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect, afterEach } from 'vitest';
import {
  extractPathFromHandleTestId,
  resolveEditorTargetElement,
  DND_HANDLE_TESTID_PREFIX,
} from '../../utils/resolveEditorTarget';

describe('extractPathFromHandleTestId', () => {
  it('핸들 testid 에서 editorPath 추출', () => {
    expect(extractPathFromHandleTestId(`${DND_HANDLE_TESTID_PREFIX}2.children.5.children.0`)).toBe(
      '2.children.5.children.0',
    );
  });

  it('핸들 형식이 아니면 null', () => {
    expect(extractPathFromHandleTestId('g7le-selection-box')).toBeNull();
    expect(extractPathFromHandleTestId('')).toBeNull();
    expect(extractPathFromHandleTestId(null)).toBeNull();
    expect(extractPathFromHandleTestId(undefined)).toBeNull();
  });

  it('path 가 빈 핸들 testid 는 null', () => {
    expect(extractPathFromHandleTestId(DND_HANDLE_TESTID_PREFIX)).toBeNull();
  });
});

describe('resolveEditorTargetElement', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  function buildFrame(): { frame: HTMLElement; node: HTMLElement } {
    const frame = document.createElement('div');
    const node = document.createElement('div');
    node.setAttribute('data-editor-path', '2.children.5.children.0');
    node.setAttribute('data-editor-id', 'auto_Span_x');
    node.textContent = '샘플 텍스트';
    frame.appendChild(node);
    document.body.appendChild(frame);
    return { frame, node };
  }

  it('1차 — target 이 편집 노드 자손이면 closest 로 해석', () => {
    const { frame, node } = buildFrame();
    const inner = document.createElement('span');
    node.appendChild(inner);
    expect(resolveEditorTargetElement(inner, frame)).toBe(node);
  });

  it('2차 — handleFallback:true 면 dnd 핸들 testid path 로 frame 안 노드 역질의 (D-33, iteration_item/modal)', () => {
    const { frame, node } = buildFrame();
    // 핸들은 frame 밖(형제 오버레이) 에 있고 data-editor-path 가 없다.
    const handle = document.createElement('div');
    handle.setAttribute('data-testid', `${DND_HANDLE_TESTID_PREFIX}2.children.5.children.0`);
    document.body.appendChild(handle);
    expect(resolveEditorTargetElement(handle, frame, undefined, { handleFallback: true })).toBe(node);
  });

  // route 모드(handleFallback:false, 기본값)는 핸들 폴백을 끈다. route 의 iteration 묶음
  // 핸들 클릭은 반복 영역 통짜로 선택돼야 하므로 핸들 testid 의 내부 노드 path 로 내려가면 안 된다.
  it('2차 차단 — handleFallback 기본(false, route 모드)이면 핸들에서 null (통짜 선택 보존)', () => {
    const { frame } = buildFrame();
    const handle = document.createElement('div');
    handle.setAttribute('data-testid', `${DND_HANDLE_TESTID_PREFIX}2.children.5.children.0`);
    document.body.appendChild(handle);
    // 좌표 미제공 + 핸들 폴백 off → null(핸들 자체 onClick 이 통짜 선택 담당).
    expect(resolveEditorTargetElement(handle, frame)).toBeNull();
  });

  it('핸들 testid 의 path 가 frame 에 없으면 null (handleFallback:true 라도 역질의 실패)', () => {
    const { frame } = buildFrame();
    const handle = document.createElement('div');
    handle.setAttribute('data-testid', `${DND_HANDLE_TESTID_PREFIX}9.children.9`);
    document.body.appendChild(handle);
    // 좌표 폴백 미제공 → null.
    expect(resolveEditorTargetElement(handle, frame, undefined, { handleFallback: true })).toBeNull();
  });

  it('편집 노드도 핸들도 아니면 null (좌표 미제공)', () => {
    const { frame } = buildFrame();
    const stray = document.createElement('div');
    document.body.appendChild(stray);
    expect(resolveEditorTargetElement(stray, frame)).toBeNull();
  });

  it('target=null 이고 좌표 미제공이면 null', () => {
    const { frame } = buildFrame();
    expect(resolveEditorTargetElement(null, frame)).toBeNull();
  });

  // 좌표 폴백(3차)도 handleFallback 전용. route 모드(false)는 좌표로 내부 노드를 잡지 않는다.
  it('3차 좌표 폴백 — handleFallback 기본(false)이면 좌표 제공돼도 적용 안 함(통짜 보존)', () => {
    const { frame, node } = buildFrame();
    const r = node.getBoundingClientRect();
    const point = { x: Math.round(r.x + 1), y: Math.round(r.y + 1) };
    // 핸들 아닌 stray target + 좌표 제공. handleFallback off → 좌표 폴백 미적용 → null.
    const stray = document.createElement('div');
    document.body.appendChild(stray);
    expect(resolveEditorTargetElement(stray, frame, point)).toBeNull();
  });

  // 어포던스 가드: 클릭 대상이 편집기 어포던스 버튼(요소추가/속성/부모, `g7le-` testid)이면
  // handleFallback 이 켜져도 폴백을 적용하지 않고 null 반환(그 버튼 onClick 이 살아야 함).
  it('어포던스 버튼(g7le-insertion-below) 위 클릭 → handleFallback:true 라도 null (버튼 onClick 보존)', () => {
    const { frame } = buildFrame();
    const addBtn = document.createElement('button');
    addBtn.setAttribute('data-testid', 'g7le-insertion-below');
    document.body.appendChild(addBtn);
    const r = addBtn.getBoundingClientRect();
    const point = { x: Math.round(r.x + 1), y: Math.round(r.y + 1) };
    // 어포던스라 좌표 폴백/핸들 폴백 모두 건너뛰고 null.
    expect(resolveEditorTargetElement(addBtn, frame, point, { handleFallback: true })).toBeNull();
  });

  it('속성 버튼(g7le-overlay-info-button) 위 클릭 → null', () => {
    const { frame } = buildFrame();
    const info = document.createElement('button');
    info.setAttribute('data-testid', 'g7le-overlay-info-button');
    document.body.appendChild(info);
    expect(resolveEditorTargetElement(info, frame, undefined, { handleFallback: true })).toBeNull();
  });

  // 어포던스 가드는 dnd 핸들을 제외한다 — 핸들은 폴백 대상(2차 역질의)이므로 가드에 안 걸린다.
  it('dnd 핸들은 어포던스 가드에서 제외 — handleFallback:true 면 역질의', () => {
    const { frame, node } = buildFrame();
    const handle = document.createElement('div');
    handle.setAttribute('data-testid', `${DND_HANDLE_TESTID_PREFIX}2.children.5.children.0`);
    document.body.appendChild(handle);
    expect(resolveEditorTargetElement(handle, frame, undefined, { handleFallback: true })).toBe(node);
  });
});
