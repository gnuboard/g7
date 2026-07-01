/**
 * editorTrackers 단위 테스트
 *
 * 검증:
 *  - devtools 비활성 환경에서 no-op (회귀 위험 0)
 *  - devtools 활성 시 trackEditorState 가 LayoutEditorState 의 비-누수 필드만 적재
 *  - clearEditorStateData 호출이 devtools.clearEditorStateData 로 전달
 *  - 노드 메타(편집 중 내용물) 가 적재되지 않음 (누수 가드)
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { trackEditorState, clearEditorStateData } from '../../devtools/editorTrackers';
import type { LayoutEditorState } from '../../LayoutEditorContext';

const baseState: LayoutEditorState = {
  templateIdentifier: 'sirsoft-admin_basic',
  routeTree: [],
  selectedRoute: null,
  isRouteTreeCollapsed: false,
  editMode: 'route',
  returnRoute: null,
  locale: 'ko',
  previewDevice: 'desktop',
  previewZoom: 1,
  history: { canUndo: false, canRedo: false },
};

describe('editorTrackers — devtools 비활성', () => {
  beforeEach(() => {
    (window as any).__g7Devtools = undefined;
  });

  it('trackEditorState — no-op (에러 없음)', () => {
    expect(() => trackEditorState(baseState)).not.toThrow();
  });

  it('clearEditorStateData — no-op', () => {
    expect(() => clearEditorStateData()).not.toThrow();
  });
});

describe('editorTrackers — devtools 활성', () => {
  let trackSpy: ReturnType<typeof vi.fn>;
  let clearSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    trackSpy = vi.fn();
    clearSpy = vi.fn();
    (window as any).__g7Devtools = {
      trackEditorState: trackSpy,
      clearEditorStateData: clearSpy,
    };
  });

  afterEach(() => {
    (window as any).__g7Devtools = undefined;
  });

  it('trackEditorState — devtools.trackEditorState 호출', () => {
    trackEditorState(baseState, 'TOGGLE_ROUTE_TREE');
    expect(trackSpy).toHaveBeenCalledOnce();
    const snap = trackSpy.mock.calls[0][0];
    expect(snap.templateIdentifier).toBe('sirsoft-admin_basic');
    expect(snap.editMode).toBe('route');
    expect(snap.lastAction).toBe('TOGGLE_ROUTE_TREE');
    expect(snap.timestamp).toBeTypeOf('number');
  });

  it('trackEditorState — 노드 메타(편집 중 내용물) 미적재 (누수 가드)', () => {
    // routeTree 에 가상 내용물을 가득 채워도 적재되는 건 size 만
    const stateWithTree: LayoutEditorState = {
      ...baseState,
      routeTree: [
        {
          path: '/',
          layoutName: 'home',
          label: '홈',
          labelSource: 'editor_label',
          source: { kind: 'template', identifier: null },
          kind: 'route',
          children: [],
        },
        {
          path: '/about',
          layoutName: 'about',
          label: '소개',
          labelSource: 'editor_label',
          source: { kind: 'template', identifier: null },
          kind: 'route',
          children: [],
        },
      ],
    };
    trackEditorState(stateWithTree);
    const snap = trackSpy.mock.calls[0][0];
    expect(snap.routeTreeSize).toBe(2);
    // 노드 메타 자체는 적재되지 않음
    expect(snap.routeTree).toBeUndefined();
    expect(JSON.stringify(snap)).not.toContain('children');
  });

  it('selectedRoute 가 null 일 때 selectedRoutePath/selectedLayoutName 도 null', () => {
    trackEditorState(baseState);
    const snap = trackSpy.mock.calls[0][0];
    expect(snap.selectedRoutePath).toBeNull();
    expect(snap.selectedLayoutName).toBeNull();
  });

  it('clearEditorStateData — devtools.clearEditorStateData 호출', () => {
    clearEditorStateData();
    expect(clearSpy).toHaveBeenCalledOnce();
  });

  it('devtools 적재 함수가 throw 해도 본 흐름은 영향 없음', () => {
    trackSpy.mockImplementation(() => {
      throw new Error('boom');
    });
    expect(() => trackEditorState(baseState)).not.toThrow();
  });
});
