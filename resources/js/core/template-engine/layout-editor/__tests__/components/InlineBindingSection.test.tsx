// e2e:allow 레이아웃 편집기 텍스트 데이터 연결 속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(§공통 검증) + 단위/레이아웃 렌더링 테스트로 검증 (DataBindingSection.test.tsx L1 과 동일 정책)
/**
 * InlineBindingSection.test.tsx — 텍스트 데이터 연결 영역 RTL
 *
 * 재설계:
 *  - 비키화 노드(평문/레거시 raw 보간) → "+데이터" 삽입 시 **즉시 param 키화**(POST 후 node.text=$t:K|pN=).
 *    → 번역 탭 즉시 활성. 레거시 raw 보간 행은 읽기 표시(교체/해제는 키화 후).
 *  - param 키 노드 → param 값 행(교체) + [해제](전 로케일 자리표시 제거) + 신규 추가(끝).
 *
 * 컴포넌트가 useLayoutEditor()(편집 컨텍스트)를 사용하므로 LayoutEditorProvider 로 감싼다.
 * 키화·해제·추가는 custom-translations API(fetch)를 호출하므로 fetch 를 mock 한다.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';
import { LayoutEditorProvider } from '../../LayoutEditorContext';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import { InlineBindingSection } from '../../components/property-controls/InlineBindingSection';
import type { BindingCandidate } from '../../spec/bindingCandidates';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { UseLayoutDocumentResult, LoadedLayoutDocument } from '../../hooks/useLayoutDocument';
import { clearPending } from '../../hooks/pendingCustomTranslations';

const t = (k: string) => k;
afterEach(() => { cleanup(); vi.restoreAllMocks(); clearPending(); });
beforeEach(() => { localStorage.setItem('auth_token', 'test-token'); clearPending(); });

const candidates: BindingCandidate[] = [
  { expression: '{{user.name}}', source: 'data_source', sourceId: 'user', path: 'name', shape: 'scalar', groupLabelKey: 'editor.ds.user', preview: '홍길동' },
  { expression: '{{_local.keyword}}', source: '_local', sourceId: '_local', path: 'keyword', shape: 'scalar', preview: '노트북' },
  { expression: '{{products.data}}', source: 'data_source', sourceId: 'products', path: 'data', shape: 'array', preview: '[3]' },
];

/** 최소 docCtx — 키화 후 node.text 패치 검증용은 onPatchNode 로 받는다. */
function buildDocCtx(): UseLayoutDocumentResult {
  let document: LoadedLayoutDocument = { layoutName: 'home', raw: { components: [] }, lockVersion: 1 };
  const ctx: UseLayoutDocumentResult = {
    document, isLoading: false, error: null, isDirty: false, saveSuccessCounter: 0,
    reload: async () => {},
    patchLayout: (p) => { document = { ...document, raw: { ...document.raw, components: p((document.raw.components as EditorNode[]) ?? []) } }; (ctx as unknown as { document: LoadedLayoutDocument }).document = document; },
    setLayoutComponents: (n) => { document = { ...document, raw: { ...document.raw, components: n } }; (ctx as unknown as { document: LoadedLayoutDocument }).document = document; },
    save: async () => ({ kind: 'success', newLockVersion: 2 }),
  };
  return ctx;
}

function renderSection(node: EditorNode, onPatchNode = vi.fn()) {
  const ctx = buildDocCtx();
  const utils = render(
    <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
      <LayoutDocumentProvider value={ctx}>
        <InlineBindingSection node={node} candidates={candidates} t={t} onPatchNode={onPatchNode} templateIdentifier="test-tpl" />
      </LayoutDocumentProvider>
    </LayoutEditorProvider>,
  );
  return { utils, onPatchNode };
}

/** createCustomKey POST → 응답 키 mock + 호출 캡처. */
function stubKeyifyFetch(): { calls: Array<{ url: string; method?: string; body: any }> } {
  const calls: Array<{ url: string; method?: string; body: any }> = [];
  let seq = 0;
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const body = init?.body ? JSON.parse(init.body as string) : null;
    calls.push({ url, method: init?.method, body });
    if (typeof url === 'string' && url.includes('/custom-translations') && init?.method === 'POST') {
      seq += 1;
      return { ok: true, status: 201, json: async () => ({ data: { id: seq, translation_key: `custom.home.${seq}`, values: { ko: body?.value ?? '' }, lock_version: 0 } }) } as unknown as Response;
    }
    if (typeof url === 'string' && url.includes('/custom-translations') && (!init?.method || init?.method === 'GET')) {
      // findCustomKeyRow index
      return { ok: true, status: 200, json: async () => ({ data: [{ id: 7, translation_key: 'custom.home.5', values: { ko: '{p0} 작성', en: '' }, lock_version: 1 }] }) } as unknown as Response;
    }
    if (typeof url === 'string' && url.includes('/custom-translations/') && init?.method === 'PUT') {
      return { ok: true, status: 200, json: async () => ({ data: { id: 7, lock_version: 2 } }) } as unknown as Response;
    }
    return { ok: true, status: 200, json: async () => ({ data: { cache_version: 1 } }) } as unknown as Response;
  }));
  return { calls };
}

describe('InlineBindingSection — 비키화 노드: 데이터 삽입 = 즉시 키화', () => {
  it('보간 없는 평문 → none 안내 + 삽입 피커', () => {
    renderSection({ name: 'Span', text: '안녕하세요' });
    expect(screen.getByTestId('g7le-inline-binding-section')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-inline-binding-none')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-inline-binding-append')).toBeInTheDocument();
  });

  it('+데이터 삽입 → 키화(POST=평문, 자리표시는 버퍼) → node.text=param 키', async () => {
    const { calls } = stubKeyifyFetch();
    const onPatch = vi.fn();
    renderSection({ name: 'Span', text: '안녕' }, onPatch);
    fireEvent.click(screen.getByTestId('g7le-inline-binding-search-toggle-append'));
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{user.name}}'));
    // POST 초기값은 자리표시 없는 평문(desync 차단 — 미저장 시 raw {p0} 없음). 자리표시는 버퍼.
    await waitFor(() => expect(calls.some((c) => c.method === 'POST')).toBe(true));
    const post = calls.find((c) => c.method === 'POST');
    expect(post!.body.value).toBe('안녕');
    // node.text = $t:custom.home.1|p0={{user?.name ?? ''}} (param 정의 — 레이아웃 저장 시 영속)
    await waitFor(() => expect(onPatch).toHaveBeenCalled());
    const patched = onPatch.mock.calls.at(-1)![0];
    expect(patched.text).toBe("$t:custom.home.1|p0={{user?.name ?? ''}}");
  });

  it('삽입 피커는 scalar 후보만(array 미노출)', () => {
    renderSection({ name: 'Span', text: '' });
    fireEvent.click(screen.getByTestId('g7le-inline-binding-search-toggle-append'));
    expect(screen.getByTestId('g7le-inline-binding-candidate-{{user.name}}')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-inline-binding-candidate-{{products.data}}')).toBeNull();
  });

  it('레거시 raw 보간 노드 → 보간 읽기 표시(교체/해제 버튼 없음 — 키화 후 가능)', () => {
    renderSection({ name: 'P', text: '작성자 {{user.name}}' });
    expect(screen.getByTestId('g7le-inline-binding-expr-0')).toHaveTextContent('{{user.name}}');
    // 재설계: 비키화 노드는 읽기 표시만 — 교체/해제 미노출.
    expect(screen.queryByTestId('g7le-inline-binding-clear-0')).toBeNull();
    expect(screen.queryByTestId('g7le-inline-binding-search-toggle-replace-0')).toBeNull();
    // 삽입 입구는 항상 제공.
    expect(screen.getByTestId('g7le-inline-binding-append')).toBeInTheDocument();
  });
});

describe('InlineBindingSection — param 키 노드', () => {
  it('param 값 행 + [해제] + 신규 추가 입구 노출', () => {
    const node: EditorNode = { name: 'Span', text: '$t:custom.home.5|p0={{user.name}}|p1={{_local.keyword}}' };
    renderSection(node);
    expect(screen.getByTestId('g7le-inline-binding-param-p0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-inline-binding-param-p1')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-inline-binding-param-expr-p0')).toHaveTextContent('{{user.name}}');
    expect(screen.getByTestId('g7le-inline-binding-param-clear-p0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-inline-binding-param-append')).toBeInTheDocument();
  });

  it('param 값 교체 → 해당 |pN= 만 새 소스로(키·다른 param 보존)', () => {
    const onPatch = vi.fn();
    const node: EditorNode = { name: 'Span', text: '$t:custom.home.5|p0={{user.name}}|p1={{old.x}}' };
    renderSection(node, onPatch);
    fireEvent.click(screen.getByTestId('g7le-inline-binding-search-toggle-param-p1'));
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{_local.keyword}}'));
    expect(onPatch).toHaveBeenCalledWith(
      expect.objectContaining({ text: "$t:custom.home.5|p0={{user.name}}|p1={{_local?.keyword ?? ''}}" }),
    );
  });

  it('데이터 연결 해제 → node.text 의 |pN= 제거', () => {
    stubKeyifyFetch();
    const onPatch = vi.fn();
    const node: EditorNode = { name: 'Span', text: '$t:custom.home.5|p0={{user.name}}|p1={{old.x}}' };
    renderSection(node, onPatch);
    fireEvent.click(screen.getByTestId('g7le-inline-binding-param-clear-p0'));
    expect(onPatch).toHaveBeenCalledWith(
      expect.objectContaining({ text: '$t:custom.home.5|p1={{old.x}}' }),
    );
  });

  it('복합 param 은 읽기전용(교체 피커 미노출)', () => {
    const node: EditorNode = { name: 'Span', text: '$t:custom.home.5|p0={{a ? b : c}}' };
    renderSection(node);
    expect(screen.getByTestId('g7le-inline-binding-param-complex-p0')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-inline-binding-search-toggle-param-p0')).toBeNull();
  });

  it('S9-N4: 미키화 lang **named-param** 노드(`$t:user.*|count={{}}`)는 param 키로 오판 금지 → 비키화 취급(데이터 추가=신규 키화)', () => {
    // 결함: `isParamized = isParamizedKeyText(text) ||...` 가
    // lang named-param(`$t:user.identity.challenge.remaining_attempts|count={{Math.max(...)}}`)도
    // true 로 판정해(PARAMIZED_KEY_RE 가 custom/lang 키 미구분), 속성 탭이 그 노드를 "이미 키화된
    // param 키"로 취급한다. 그러면 데이터 추가 시 appendParam(=insertBindingIntoParamKey)이 lang
    // 키(`user.identity...`)에 `|pN=` 을 붙이려 해 키화가 안 되고 lang 키가 오염된다. 수정: lang
    // named-param 은 **비키화 노드**로 취급 → 데이터 추가 시 insertDataKeyify(신규 custom 키화).
    const node: EditorNode = {
      name: 'Span',
      text: '$t:user.identity.challenge.remaining_attempts|count={{Math.max(0, (_local.maxAttempts ?? 0) - (_local.attempts ?? 0))}}',
    };
    renderSection(node);
    // param 키 행(`g7le-inline-binding-param-*`)이 **노출되지 않아야** 한다(키화 안 된 노드).
    expect(screen.queryByTestId('g7le-inline-binding-param-count')).toBeNull();
    expect(screen.queryByTestId('g7le-inline-binding-param-p0')).toBeNull();
    // 대신 비키화 노드 표면(읽기 표시 + 신규 삽입 입구 = 키화 경로)이 노출된다.
    expect(screen.getByTestId('g7le-inline-binding-append')).toBeInTheDocument();
  });

  it('S9-N4: lang named-param 노드 데이터 추가 → insertDataKeyify(신규 custom 키 POST) — lang 키 미오염', async () => {
    const { calls } = stubKeyifyFetch();
    const onPatch = vi.fn();
    const node: EditorNode = {
      name: 'Span',
      text: '$t:user.identity.challenge.remaining_attempts|count={{Math.max(0, (_local.maxAttempts ?? 0) - (_local.attempts ?? 0))}}',
    };
    renderSection(node, onPatch);
    fireEvent.click(screen.getByTestId('g7le-inline-binding-search-toggle-append'));
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{user.name}}'));
    // 신규 custom 키 생성(POST) — appendParam(PUT to lang key)이 아니라 키화 경로.
    await waitFor(() => expect(calls.some((c) => c.method === 'POST')).toBe(true));
    // node.text 가 새 custom 키로 치환(lang 키를 키 토큰으로 쓰지 않음).
    await waitFor(() => expect(onPatch).toHaveBeenCalled());
    const patched = onPatch.mock.calls.at(-1)![0];
    expect(patched.text).toContain('$t:custom.home.');
    expect(patched.text).not.toContain('$t:user.identity');
  });
});
