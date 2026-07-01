/**
 * useExtensionDocument 테스트
 *
 * 확장 편집 문서 로드/편집/저장 + content 두 형태(extension_point/overlay) 추출·재조립 +
 *  호스트 병합 렌더(호스트 트리에 편집 조각 합성, 호스트 본체 잠금, 저장 시 확장 노드
 * 추출).
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import React from 'react';
import {
  useExtensionDocument,
  extractExtensionIdFromPath,
  extractEditableComponents,
  reassembleContent,
  extractCurrentExtensionNodes,
  mergeEditableIntoHost,
} from '../../hooks/useExtensionDocument';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import type { NodeSource } from '../../utils/layoutTreeUtils';

function makeWrapper(): React.FC<{ children: React.ReactNode }> {
  return ({ children }) =>
    React.createElement(
      LayoutEditorProvider,
      { templateIdentifier: 'sirsoft-admin_basic', initialLocale: 'ko' },
      children,
    );
}

function combinedHook() {
  const editor = useLayoutEditor();
  const document = useExtensionDocument();
  return { editor, document };
}

const EXT_META: NodeSource = { kind: 'extension', extensionId: 7 };

/** 확장 응답 mock 빌더 */
function extResponse(data: Record<string, unknown>) {
  return { ok: true, status: 200, json: async () => ({ data }) };
}
/** 호스트 레이아웃 응답 mock 빌더 (with_source_meta=1) */
function hostResponse(components: unknown[], dataSources?: unknown[]) {
  return {
    ok: true,
    status: 200,
    json: async () => ({
      data: { components, lock_version: 0, ...(dataSources ? { data_sources: dataSources } : {}) },
    }),
  };
}

// ============================================================================
// 순수 헬퍼
// ============================================================================
describe('extractExtensionIdFromPath', () => {
  it('__extension__/{id} 에서 id 추출', () => {
    expect(extractExtensionIdFromPath('__extension__/42')).toBe('42');
  });
  it('형식 불일치 → null', () => {
    expect(extractExtensionIdFromPath('/about')).toBeNull();
    expect(extractExtensionIdFromPath('__modal__/x')).toBeNull();
    expect(extractExtensionIdFromPath('__extension__/')).toBeNull();
    expect(extractExtensionIdFromPath('__extension__/4/2')).toBeNull();
    expect(extractExtensionIdFromPath(null)).toBeNull();
  });
});

describe('extractEditableComponents', () => {
  it('extension_point — content.components 반환', () => {
    const content = {
      extension_point: 'header_ext',
      mode: 'replace',
      components: [{ id: 'a' }, { id: 'b' }],
    };
    const roots = extractEditableComponents(content as any);
    expect(roots.map((n: any) => n.id)).toEqual(['a', 'b']);
  });

  it('overlay — components 보유 injection 들을 평탄화 + __injectionIndex 부여', () => {
    const content = {
      target_layout: 'admin_user_detail',
      injections: [
        { target_id: 'tabs', position: 'inject_props', props: { x: 1 } }, // components 없음
        { target_id: 'slot', position: 'append_child', components: [{ id: 'c1' }] },
        { target_id: 'slot2', position: 'append', components: [{ id: 'c2' }, { id: 'c3' }] },
      ],
    };
    const roots = extractEditableComponents(content as any);
    expect(roots.map((n: any) => n.id)).toEqual(['c1', 'c2', 'c3']);
    expect((roots[0] as any).__injectionIndex).toBe(1);
    expect((roots[1] as any).__injectionIndex).toBe(2);
    expect((roots[2] as any).__injectionIndex).toBe(2);
  });

  it('components/injections 둘 다 없으면 빈 배열', () => {
    expect(extractEditableComponents({} as any)).toEqual([]);
  });
});

describe('reassembleContent', () => {
  it('extension_point — components 교체 + 비편집 키 보존 + 합성 메타 제거', () => {
    const content = {
      extension_point: 'header_ext',
      mode: 'replace',
      priority: 100,
      components: [{ id: 'old' }],
    };
    // 호스트 병합 후 편집 조각은 __source 메타가 붙어 있을 수 있다 → 저장 시 제거되어야 한다.
    const result = reassembleContent(content as any, [
      { id: 'new', __source: EXT_META, children: [{ id: 'kid', __source: EXT_META }] },
    ] as any);
    expect((result as any).components).toEqual([{ id: 'new', children: [{ id: 'kid' }] }]);
    expect((result as any).extension_point).toBe('header_ext');
    expect((result as any).mode).toBe('replace');
    expect((result as any).priority).toBe(100);
  });

  it('overlay — __injectionIndex 로 각 injection 에 재분배 + inject_props 보존 + 메타 제거', () => {
    const content = {
      target_layout: 'admin_user_detail',
      injections: [
        { target_id: 'tabs', position: 'inject_props', props: { x: 1 } },
        { target_id: 'slot', position: 'append_child', components: [{ id: 'old1' }] },
        { target_id: 'slot2', position: 'append', components: [{ id: 'old2' }] },
      ],
    };
    const editedRoots = [
      { id: 'new1', __injectionIndex: 1, __source: EXT_META },
      { id: 'new2a', __injectionIndex: 2 },
      { id: 'new2b', __injectionIndex: 2 },
    ];
    const result = reassembleContent(content as any, editedRoots as any) as any;
    expect(result.injections[0].position).toBe('inject_props');
    expect(result.injections[0].props).toEqual({ x: 1 });
    expect(result.injections[1].components).toEqual([{ id: 'new1' }]);
    expect(result.injections[2].components).toEqual([{ id: 'new2a' }, { id: 'new2b' }]);
    expect(result.injections[1].components[0].__injectionIndex).toBeUndefined();
    expect(result.injections[1].components[0].__source).toBeUndefined();
  });
});

// ============================================================================
//  호스트 병합 — 순수 헬퍼
// ============================================================================
describe('extractCurrentExtensionNodes ', () => {
  it('호스트 트리에서 현재 확장 진입점 노드만 수집(자식은 안 내려감)', () => {
    const host = [
      { id: 'header', __source: { kind: 'base' } },
      { id: 'ext-root', __source: EXT_META, children: [{ id: 'ext-kid', __source: EXT_META }] },
      { id: 'other-ext', __source: { kind: 'extension', extensionId: 99 } },
      {
        id: 'content',
        __source: { kind: 'route' },
        children: [{ id: 'nested-ext', __source: EXT_META }],
      },
    ];
    const found = extractCurrentExtensionNodes(host as any, 7);
    // 최상위 ext-root + content 자식의 nested-ext (호스트/타 확장은 건너뛰되 자식 재귀).
    expect(found.map((n: any) => n.id)).toEqual(['ext-root', 'nested-ext']);
    // 진입점 자식은 별도 수집되지 않음(통짜).
    expect(found[0].children[0].id).toBe('ext-kid');
  });

  it('현재 확장 노드 없으면 빈 배열', () => {
    const host = [{ id: 'a', __source: { kind: 'base' } }];
    expect(extractCurrentExtensionNodes(host as any, 7)).toEqual([]);
  });
});

describe('mergeEditableIntoHost ', () => {
  it('호스트 내 현재 확장 노드 run 을 편집 조각으로 치환 + 메타 부여', () => {
    const host = [
      { id: 'header', __source: { kind: 'base' } },
      { id: 'old-ext-1', __source: EXT_META },
      { id: 'old-ext-2', __source: EXT_META },
      { id: 'footer', __source: { kind: 'base' } },
    ];
    const editable = [{ id: 'new-ext' }];
    const merged = mergeEditableIntoHost(host as any, editable as any, 7, EXT_META);
    expect(merged.map((n: any) => n.id)).toEqual(['header', 'new-ext', 'footer']);
    // 편집 조각에 확장 메타가 부여돼 잠금 매트릭스에서 편집 가능으로 분류.
    const injected = merged.find((n: any) => n.id === 'new-ext')!;
    expect(injected.__source).toEqual(EXT_META);
    // 호스트 본체 메타 보존(잠금).
    expect((merged.find((n: any) => n.id === 'header') as any).__source.kind).toBe('base');
  });

  it('확장이 호스트 깊은 곳에 주입되면 자식에서 치환', () => {
    const host = [
      {
        id: 'wrap',
        __source: { kind: 'base' },
        children: [{ id: 'old-ext', __source: EXT_META }],
      },
    ];
    const merged = mergeEditableIntoHost(host as any, [{ id: 'fresh' }] as any, 7, EXT_META);
    expect((merged[0] as any).children.map((n: any) => n.id)).toEqual(['fresh']);
  });

  // 실제 extension_point 구조: 컨테이너(kind:'route', type:'extension_point')의
  // children 에 확장 노드(extId)가 깊이 중첩. 편집 조각이 그 자리에 합성되고 편집 가능해야 한다.
  it('extension_point 컨테이너(route) children 의 확장 노드 → 편집 조각 합성', () => {
    const host = [
      {
        name: 'Div',
        __source: { kind: 'route' },
        children: [
          {
            name: 'Form',
            __source: { kind: 'route' },
            children: [
              { name: 'TitleInput', __source: { kind: 'route' } },
              {
                type: 'extension_point',
                name: 'html_content',
                __source: { kind: 'route' },
                children: [
                  { name: 'HtmlContent', __source: EXT_META },
                  { name: 'Div', __source: EXT_META },
                ],
              },
            ],
          },
        ],
      },
    ];
    const editable = [{ name: 'EditedHtml' }, { name: 'EditedDiv' }];
    const merged = mergeEditableIntoHost(host as any, editable as any, 7, EXT_META);
    // extension_point 자리에 편집 조각이 합성됐는지 — 확장 노드를 추출해 확인.
    const extracted = extractCurrentExtensionNodes(merged, 7);
    expect(extracted.map((n: any) => n.name)).toEqual(['EditedHtml', 'EditedDiv']);
    // 합성된 조각은 확장 메타가 부여돼 편집 가능(잠금 매트릭스).
    expect(extracted[0].__source).toEqual(EXT_META);
    // 호스트 본체(TitleInput, Form)는 보존.
    expect(JSON.stringify(merged)).toContain('TitleInput');
  });

  // extension_point 컨테이너 자체가 extension 출처로 태깅된 경우(overlay 등 변종).
  it('컨테이너 자체가 확장 출처면 통째 치환', () => {
    const host = [
      { name: 'Header', __source: { kind: 'base' } },
      {
        name: 'PaymentSlot',
        __source: EXT_META, // 컨테이너가 확장 출처
        children: [{ name: 'KcpButton', __source: EXT_META }],
      },
    ];
    const merged = mergeEditableIntoHost(host as any, [{ name: 'EditedKcp' }] as any, 7, EXT_META);
    const extracted = extractCurrentExtensionNodes(merged, 7);
    expect(extracted.map((n: any) => n.name)).toEqual(['EditedKcp']);
  });
});

// ============================================================================
// 훅 — 로드/저장/호스트 병합
// ============================================================================
describe('useExtensionDocument — 로드/저장', () => {
  let fetchSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchSpy = vi.fn();
    (global as any).fetch = fetchSpy;
    if (typeof window !== 'undefined') window.localStorage?.clear();
  });

  function enterExtension(result: any, extId: string) {
    act(() => {
      result.current.editor.dispatch({ type: 'ENTER_EXTENSION_EDIT', extensionId: extId });
    });
  }

  it('extension 모드 진입 → layout-extensions/{id} GET, content(JSON 문자열) 파싱 (host 없음=조각)', async () => {
    fetchSpy.mockResolvedValue(
      extResponse({
        id: 1,
        extension_type: 'extension_point',
        lock_version: 3,
        content: JSON.stringify({
          extension_point: 'header_ext',
          mode: 'replace',
          components: [{ id: 'root', type: 'basic', name: 'Div' }],
        }),
        // host_layouts 없음 → 조각 단독(디그레이드) 로드.
      }),
    );

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterExtension(result, '1');

    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    const url = fetchSpy.mock.calls[0][0] as string;
    expect(url).toContain('/api/admin/templates/sirsoft-admin_basic/layout-extensions/1');
    const doc = result.current.document.document!;
    expect(doc.extensionId).toBe(1);
    expect(doc.extensionType).toBe('extension_point');
    expect(doc.lockVersion).toBe(3);
    expect(doc.components[0].id).toBe('root');
  });

  it('/D-41 호스트 병합 — 호스트 트리를 그대로 캔버스에 렌더(치환 안 함), 호스트 본체+확장 노드 보존', async () => {
    // 1차: 확장 GET, 2차: 호스트 레이아웃 GET.
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('/layout-extensions/')) {
        return Promise.resolve(
          extResponse({
            id: 7,
            extension_type: 'extension_point',
            lock_version: 2,
            content: JSON.stringify({
              extension_point: 'header_ext',
              components: [{ id: 'ext-frag', type: 'basic', name: 'Span' }],
            }),
            host_layouts: ['admin_home'],
          }),
        );
      }
      // 호스트: 헤더(base) + 확장 주입 노드(ext meta) + 푸터(base).
      return Promise.resolve(
        hostResponse([
          { id: 'host-header', name: 'Div', __source: { kind: 'base' } },
          { id: 'host-ext-old', name: 'Span', __source: { kind: 'extension', extensionId: 7 } },
          { id: 'host-footer', name: 'Div', __source: { kind: 'base' } },
        ]),
      );
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterExtension(result, '7');
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    const doc = result.current.document.document!;
    //  캔버스 트리 = **호스트 트리 그대로**(헤더/푸터 + 백엔드가 주입·평가한 확장 노드
    // 보존). 호스트 노드를 확장 content 원본(editableComponents)으로 **치환하지 않는다** — 치환하면
    // HtmlContent 처럼 `{{extensionPointProps.*}}` 를 읽는 위젯이 빈값 평가돼 미렌더되기 때문.
    const ids = doc.components.map((n: any) => n.id);
    expect(ids).toContain('host-header');
    expect(ids).toContain('host-footer');
    expect(ids).toContain('host-ext-old'); // 호스트 확장 노드 보존(치환 안 함 — extensionPointProps 유지)
    expect(ids).not.toContain('ext-frag'); // 편집 조각은 캔버스 합성 안 함(저장 시 추출용)
    // 호스트 fetch 가 with_source_meta=1 로 호출됐는지.
    const hostCall = fetchSpy.mock.calls.find((c) => String(c[0]).includes('/api/layouts/'));
    expect(String(hostCall![0])).toContain('with_source_meta=1');
    expect(String(hostCall![0])).toContain('admin_home');
    // G3 — 호스트 raw 가 문서에 보존돼 호스트 바인딩 샘플 해석에 쓰인다.
    expect(doc.hostRaw && typeof doc.hostRaw === 'object').toBe(true);
    //  호스트에 이 확장 주입 노드가 존재 → 'ok'(시각 편집 가능 후보).
    expect(doc.editability).toBe('ok');
  });

  it(' 호스트에 확장 주입 노드가 없으면 no-injection (append 디그레이드 안 함)', async () => {
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('/layout-extensions/')) {
        return Promise.resolve(
          extResponse({
            id: 9,
            extension_type: 'extension_point',
            lock_version: 1,
            content: JSON.stringify({
              extension_point: 'absent_ext',
              components: [{ id: 'ext-frag', type: 'basic', name: 'Span' }],
            }),
            host_layouts: ['admin_home'],
          }),
        );
      }
      // 호스트에 extensionId=9 주입 노드가 전혀 없음(진짜 주입 0건 케이스).
      return Promise.resolve(
        hostResponse([
          { id: 'host-header', name: 'Div', __source: { kind: 'base' } },
          { id: 'host-footer', name: 'Div', __source: { kind: 'base' } },
        ]),
      );
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterExtension(result, '9');
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    const doc = result.current.document.document!;
    // editability=no-injection — PreviewCanvas 가 라이브 렌더 대신 폴백 안내를 띄운다.
    expect(doc.editability).toBe('no-injection');
    // 종전 "호스트 끝 append" 디그레이드 제거 — 편집 조각(ext-frag)을 호스트 끝에 붙이지 않음.
    const ids = doc.components.map((n: any) => n.id);
    expect(ids).toContain('host-header');
    expect(ids).toContain('host-footer');
    expect(ids).not.toContain('ext-frag');
  });

  it(' — 주입 노드가 modals 안에만 있으면 그 모달 노드를 isOpen=true 표시용 append (editability ok)', async () => {
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('/layout-extensions/')) {
        return Promise.resolve(
          extResponse({
            id: 35,
            extension_type: 'extension_point',
            lock_version: 1,
            content: JSON.stringify({
              extension_point: 'html_content',
              components: [{ id: 'ext-frag', type: 'basic', name: 'Span' }],
            }),
            host_layouts: ['auth/register'],
          }),
        );
      }
      // 호스트 components 에는 주입 노드 없음 — modals[] 안에만 주입(register termsModal 케이스).
      return Promise.resolve({
        ok: true,
        status: 200,
        json: async () => ({
          data: {
            components: [{ id: 'host-form', name: 'Div', __source: { kind: 'base' } }],
            modals: [
              {
                id: 'termsModal',
                type: 'composite',
                name: 'Modal',
                props: { title: '이용약관', size: 'lg' },
                children: [
                  { id: 'terms-ext', name: 'Div', __source: { kind: 'extension', extensionId: 35 } },
                ],
              },
              {
                id: 'otherModal',
                type: 'composite',
                name: 'Modal',
                children: [{ id: 'other-body', name: 'Div', __source: { kind: 'base' } }],
              },
            ],
            lock_version: 0,
          },
        }),
      });
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterExtension(result, '35');
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    const doc = result.current.document.document!;
    // 주입 모달이 표시용으로 components 끝에 append → 시각 편집 가능(ok).
    expect(doc.editability).toBe('ok');
    const ids = doc.components.map((n: any) => n.id);
    expect(ids).toEqual(['host-form', 'termsModal']);
    // 무관 모달(otherModal)은 append 하지 않는다.
    expect(ids).not.toContain('otherModal');
    // 표시용 사본은 isOpen=true 강제(캔버스 정적 시뮬레이션에서 모달 노출).
    const appended: any = doc.components.find((n: any) => n.id === 'termsModal');
    expect(appended.props.isOpen).toBe(true);
    expect(appended.props.title).toBe('이용약관');
    // 원본 hostRaw.modals 는 무오염(표시용 사본만 변형).
    const rawModals = (doc.hostRaw as any).modals;
    expect(rawModals[0].props.isOpen).toBeUndefined();
    // 주입 확장 노드가 append 트리 안에 보존 — 저장 추출(extractCurrentExtensionNodes) 대상.
    expect(appended.children[0].id).toBe('terms-ext');
  });

  it(' G3 — 호스트 data_sources 가 hostRaw 에 보존(캔버스 바인딩 샘플 해석)', async () => {
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('/layout-extensions/')) {
        return Promise.resolve(
          extResponse({
            id: 7,
            extension_type: 'extension_point',
            lock_version: 1,
            content: JSON.stringify({ extension_point: 'ep', components: [{ id: 'frag' }] }),
            host_layouts: ['admin_home'],
          }),
        );
      }
      return Promise.resolve(
        hostResponse(
          [{ id: 'frag-host', __source: { kind: 'extension', extensionId: 7 } }],
          [{ id: 'profile', endpoint: '/api/me' }],
        ),
      );
    });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterExtension(result, '7');
    await waitFor(() => expect(result.current.document.document).not.toBeNull());
    const hostRaw = result.current.document.document!.hostRaw as any;
    expect(Array.isArray(hostRaw.data_sources)).toBe(true);
    expect(hostRaw.data_sources[0].id).toBe('profile');
  });

  it(' 저장 — 호스트 병합 트리에서 확장 조각만 추출해 PUT', async () => {
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('/layout-extensions/')) {
        return Promise.resolve(
          extResponse({
            id: 7,
            extension_type: 'extension_point',
            lock_version: 2,
            content: JSON.stringify({
              extension_point: 'header_ext',
              mode: 'replace',
              priority: 50,
              components: [{ id: 'ext-frag', name: 'Span' }],
            }),
            host_layouts: ['admin_home'],
          }),
        );
      }
      return Promise.resolve(
        hostResponse([
          { id: 'host-header', name: 'Div', __source: { kind: 'base' } },
          { id: 'host-ext-old', name: 'Span', __source: { kind: 'extension', extensionId: 7 } },
        ]),
      );
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterExtension(result, '7');
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    // 호스트 병합 트리(헤더+편집조각)를 통째로 patch — 편집 조각 노드만 바꾼다.
    act(() => {
      const cur = result.current.document.document!.components as any[];
      const next = cur.map((n) =>
        n.__source?.kind === 'extension' && n.__source.extensionId === 7
          ? { ...n, id: 'ext-frag-edited' }
          : n,
      );
      result.current.document.setLayoutComponents(next as any);
    });

    fetchSpy.mockImplementationOnce(() =>
      Promise.resolve({ ok: true, status: 200, json: async () => ({ data: { lock_version: 3 } }) }),
    );

    let saveResult: any;
    await act(async () => {
      saveResult = await result.current.document.save();
    });

    expect(saveResult.kind).toBe('success');
    const putCall = fetchSpy.mock.calls.find((c) => c[1]?.method === 'PUT')!;
    expect(putCall[0]).toContain('/layout-extensions/7');
    const body = JSON.parse(putCall[1].body);
    // 저장 content = 확장 조각만(호스트 헤더 제외) + 비편집 키 보존 + 메타 제거.
    expect(body.content.components).toEqual([{ id: 'ext-frag-edited', name: 'Span' }]);
    expect(body.content.extension_point).toBe('header_ext');
    expect(body.content.priority).toBe(50);
    expect(body.expected_lock_version).toBe(2);
  });

  it('저장(조각 단독 디그레이드) → PUT layout-extensions/{id}, 200 시 lockVersion 갱신', async () => {
    fetchSpy.mockResolvedValue(
      extResponse({
        id: 1,
        extension_type: 'extension_point',
        lock_version: 3,
        content: JSON.stringify({
          extension_point: 'header_ext',
          mode: 'replace',
          priority: 100,
          components: [{ id: 'root' }],
        }),
      }),
    );

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterExtension(result, '1');
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    act(() => {
      result.current.document.setLayoutComponents([{ id: 'root-edited' } as any]);
    });
    expect(result.current.document.isDirty).toBe(true);

    fetchSpy.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ data: { lock_version: 4 } }),
    });

    let saveResult: any;
    await act(async () => {
      saveResult = await result.current.document.save();
    });

    expect(saveResult.kind).toBe('success');
    const putCall = fetchSpy.mock.calls.find((c) => c[1]?.method === 'PUT')!;
    expect(putCall[0]).toContain('/layout-extensions/1');
    const body = JSON.parse(putCall[1].body);
    expect(body.content.components[0].id).toBe('root-edited');
    expect(body.content.extension_point).toBe('header_ext');
    expect(body.content.priority).toBe(100);
    expect(body.expected_lock_version).toBe(3);
    expect(result.current.document.document?.lockVersion).toBe(4);
    expect(result.current.document.isDirty).toBe(false);
  });

  it('409 → concurrent_modification 반환', async () => {
    fetchSpy.mockResolvedValue(
      extResponse({
        id: 1,
        extension_type: 'extension_point',
        lock_version: 3,
        content: JSON.stringify({ components: [{ id: 'root' }] }),
      }),
    );
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterExtension(result, '1');
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    fetchSpy.mockResolvedValueOnce({
      ok: false,
      status: 409,
      json: async () => ({ current_version: 5, your_version: 3 }),
    });
    let saveResult: any;
    await act(async () => {
      saveResult = await result.current.document.save();
    });
    expect(saveResult.kind).toBe('concurrent_modification');
    expect(saveResult.currentVersion).toBe(5);
  });

  // 호스트 레이아웃 후보 + 대표 호스트 picker.
  it('overlay — host_layouts 1개 → hostLayoutName 즉시 확정, picker 불필요', async () => {
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('/layout-extensions/')) {
        return Promise.resolve(
          extResponse({
            id: 2,
            extension_type: 'overlay',
            lock_version: 1,
            content: JSON.stringify({ target_layout: 'admin_user_detail', injections: [] }),
            host_layouts: ['admin_user_detail'],
          }),
        );
      }
      return Promise.resolve(hostResponse([{ id: 'x', __source: { kind: 'route' } }]));
    });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterExtension(result, '2');
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    expect(result.current.document.document?.hostLayouts).toEqual(['admin_user_detail']);
    expect(result.current.document.document?.hostLayoutName).toBe('admin_user_detail');
    expect(result.current.document.needsHostPicker).toBe(false);
  });

  it('extension_point — host_layouts 복수 → needsHostPicker, selectHost 로 확정', async () => {
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('/layout-extensions/')) {
        return Promise.resolve(
          extResponse({
            id: 3,
            extension_type: 'extension_point',
            lock_version: 0,
            content: JSON.stringify({ extension_point: 'dash.widgets', components: [{ id: 'w' }] }),
            host_layouts: ['admin_dashboard', 'admin_home'],
          }),
        );
      }
      return Promise.resolve(
        hostResponse([{ id: 'w-host', __source: { kind: 'extension', extensionId: 3 } }]),
      );
    });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterExtension(result, '3');
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    // 복수 호스트 + 미선택 → picker 필요, hostLayoutName 미확정.
    expect(result.current.document.document?.hostLayouts).toEqual(['admin_dashboard', 'admin_home']);
    expect(result.current.document.document?.hostLayoutName).toBeNull();
    expect(result.current.document.needsHostPicker).toBe(true);

    // picker 선택 → 재로드 후 hostLayoutName 확정 + picker 불필요.
    act(() => {
      result.current.document.selectHost('admin_home');
    });
    await waitFor(() => {
      expect(result.current.document.document?.hostLayoutName).toBe('admin_home');
    });
    expect(result.current.document.needsHostPicker).toBe(false);
  });
});
