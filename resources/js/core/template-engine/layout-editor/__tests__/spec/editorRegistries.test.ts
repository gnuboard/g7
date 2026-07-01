/**
 * editorRegistries.test.ts — 노드 에디터/캔버스 오버레이 레지스트리 + 코어 에디터 등록
 *
 *
 * kind-agnostic 디스패치: 코어는 종류를 모르고 kind 로만 핸들러를 찾는다. 코어 빌트인도
 * 특권 없이 동일 레지스트리에 등록되고, 템플릿이 신규 kind 추가 + 기존 kind 대체를 할 수
 * 있다. 미등록 kind 는 안전 디그레이드(null).
 */

import { describe, it, expect, beforeEach } from 'vitest';
import {
  registerNodeEditor,
  getNodeEditor,
  getRegisteredNodeEditorKinds,
  clearNodeEditorRegistry,
} from '../../spec/nodeEditorRegistry';
import {
  registerCanvasOverlay,
  getCanvasOverlay,
  getRegisteredCanvasOverlayKinds,
  clearCanvasOverlayRegistry,
} from '../../spec/canvasOverlayRegistry';
import {
  registerCoreEditors,
  resetCoreEditorRegistration,
  isCoreEditorsRegistered,
  getCoreEditorKinds,
} from '../../spec/registerCoreEditors';

const Dummy = (): null => null;

describe('nodeEditorRegistry — kind 등록/조회', () => {
  beforeEach(() => {
    clearNodeEditorRegistry();
  });

  it('등록한 kind 를 조회한다', () => {
    registerNodeEditor('table', Dummy);
    expect(getNodeEditor('table')).toBe(Dummy);
    expect(getRegisteredNodeEditorKinds()).toContain('table');
  });

  it('미등록 kind / undefined 는 null (이름 가정 0 디그레이드)', () => {
    expect(getNodeEditor('nope')).toBeNull();
    expect(getNodeEditor(undefined)).toBeNull();
  });

  it('템플릿이 기존 kind 를 대체(덮어쓰기)할 수 있다', () => {
    const Builtin = (): null => null;
    const TemplateImpl = (): null => null;
    registerNodeEditor('table', Builtin);
    registerNodeEditor('table', TemplateImpl);
    expect(getNodeEditor('table')).toBe(TemplateImpl);
  });

  it('템플릿이 신규 kind 를 추가할 수 있다(코어 무수정)', () => {
    registerNodeEditor('calendar', Dummy);
    expect(getNodeEditor('calendar')).toBe(Dummy);
  });
});

describe('canvasOverlayRegistry — kind 등록/조회', () => {
  beforeEach(() => {
    clearCanvasOverlayRegistry();
  });

  it('등록한 kind 를 조회한다', () => {
    registerCanvasOverlay('table', Dummy);
    expect(getCanvasOverlay('table')).toBe(Dummy);
    expect(getRegisteredCanvasOverlayKinds()).toContain('table');
  });

  it('미등록 kind / undefined 는 null (코어 선택/삽입 오버레이 디그레이드)', () => {
    expect(getCanvasOverlay('nope')).toBeNull();
    expect(getCanvasOverlay(undefined)).toBeNull();
  });

  it('템플릿이 기존/신규 kind 를 등록·대체할 수 있다', () => {
    const A = (): null => null;
    const B = (): null => null;
    registerCanvasOverlay('table', A);
    registerCanvasOverlay('table', B);
    expect(getCanvasOverlay('table')).toBe(B);
    registerCanvasOverlay('tabs', A);
    expect(getCanvasOverlay('tabs')).toBe(A);
  });
});

describe('registerCoreEditors — 단계 0 인프라(빌트인 0개)', () => {
  beforeEach(() => {
    clearNodeEditorRegistry();
    clearCanvasOverlayRegistry();
    resetCoreEditorRegistration();
  });

  it('1회 호출 후 등록 플래그가 켜진다', () => {
    expect(isCoreEditorsRegistered()).toBe(false);
    registerCoreEditors();
    expect(isCoreEditorsRegistered()).toBe(true);
  });

  it('단계 2/3-a/3-b/4-a 빌트인 — children/table/array 노드 에디터 + table 캔버스 오버레이 등록', () => {
    registerCoreEditors();
    const kinds = getCoreEditorKinds();
    // 단계 2 빌트인 — children(Ul/Ol/Nav/Form/Li 자식 편집).
    expect(kinds.nodeEditors).toContain('children');
    // 단계 3-a 빌트인 — table 노드 에디터(행/열/병합 속성 패널).
    expect(kinds.nodeEditors).toContain('table');
    // 단계 4-a 빌트인 — array 노드 에디터(props 배열 항목 편집 — tabs/items/columns 등).
    expect(kinds.nodeEditors).toContain('array');
    // 단계 8-b 빌트인 — array-group(다중 배열, BarChart labels+datasets) / array-cell-tree
    // (prop 안 중첩 노드트리 배열, CardGrid cardColumns).
    expect(kinds.nodeEditors).toContain('array-group');
    expect(kinds.nodeEditors).toContain('array-cell-tree');
    // 단계 3-b 빌트인 — table 캔버스 인플레이스 오버레이(셀 단위 핸들).
    expect(kinds.canvasOverlays).toContain('table');
  });

  it('중복 호출은 멱등(no-op)', () => {
    registerCoreEditors();
    resetCoreEditorRegistration();
    // 리셋 후 재호출해도 예외 없이 동작
    expect(() => registerCoreEditors()).not.toThrow();
    expect(isCoreEditorsRegistered()).toBe(true);
  });
});
