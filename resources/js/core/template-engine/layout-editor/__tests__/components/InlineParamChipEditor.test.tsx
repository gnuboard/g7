// e2e:allow 레이아웃 편집기 인라인 칩 편집 오버레이 — contentEditable/드래그 의존, Chrome MCP + 단위로 검증
/**
 * InlineParamChipEditor.test.tsx — param 키 인라인 칩 편집 오버레이 RTL
 *
 *  - 현재 로케일 키 값 fetch → 칩+평문 렌더(칩=드래그 뱃지)
 *  - 평문 변경 후 저장 → 단일 로케일 키 값 PUT(다른 로케일 보존)
 *  - 변경 없음 → PUT 미발생(onCommit)
 */

import React from 'react';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';
import { InlineParamChipEditor } from '../../components/InlineParamChipEditor';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { OverlayBox } from '../../utils/overlayGeometry';
import { getPendingValue, clearPending } from '../../hooks/pendingCustomTranslations';

const t = (k: string) => k;
const box: OverlayBox = { top: 0, left: 0, width: 100, height: 20, scale: 1 } as OverlayBox;
afterEach(() => { cleanup(); vi.restoreAllMocks(); clearPending(); });
beforeEach(() => { localStorage.setItem('auth_token', 'tok'); clearPending(); });

function stub(rowValues: Record<string, string>) {
  const calls: Array<{ url: string; method: string; body: any }> = [];
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET';
    const body = init?.body ? JSON.parse(init.body as string) : null;
    calls.push({ url, method, body });
    if (url.includes('/custom-translations') && method === 'GET') {
      return { ok: true, status: 200, json: async () => ({ data: [{ id: 7, translation_key: 'custom.home.5', values: rowValues, lock_version: 1 }] }) } as Response;
    }
    if (url.includes('/custom-translations/') && method === 'PUT') {
      return { ok: true, status: 200, json: async () => ({ data: { id: 7, lock_version: 2 } }) } as Response;
    }
    return { ok: true, status: 200, json: async () => ({ data: { cache_version: 1 } }) } as Response;
  }));
  return { calls };
}

const node: EditorNode = { name: 'Span', text: '$t:custom.home.5|p0={{user.name}}' };

describe('InlineParamChipEditor', () => {
  it('현재 로케일 키 값 fetch → 칩(드래그 뱃지) + 평문 렌더', async () => {
    stub({ ko: '{p0} 님 환영', en: '' });
    render(
      <InlineParamChipEditor box={box} node={node} customKey="custom.home.5" templateIdentifier="tpl" locale="ko" t={t} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-inline-param-chip-editor')).toBeTruthy());
    // 칩(드래그 가능 원자) 렌더.
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    const chip = screen.getByTestId('g7le-chip-inline-p0');
    expect(chip).toHaveAttribute('contenteditable', 'false');
    // 포인터 기반 드래그 — grab 커서로 드래그 어포던스.
    expect(chip).toHaveStyle({ cursor: 'grab' });
  });

  it('평문 변경 후 저장 → 단일 로케일 버퍼 기록(즉시 PUT 아님, 다른 로케일 미기록)', async () => {
    const { calls } = stub({ ko: '{p0} 님 환영', en: 'Welcome {p0}' });
    const onCommit = vi.fn();
    render(
      <InlineParamChipEditor box={box} node={node} customKey="custom.home.5" templateIdentifier="tpl" locale="ko" t={t} onCommit={onCommit} onCancel={vi.fn()} />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    // 슬롯 [text(""), chip, text(" 님 환영")] — 칩 뒤 평문 = 인덱스 2.
    const span = document.querySelector('[data-testid="g7le-chip-text-inline-2"]') as HTMLElement;
    span.textContent = ' 님 안녕';
    fireEvent.input(span);
    fireEvent.click(screen.getByTestId('g7le-inline-param-chip-save'));
    // 즉시 PUT 없음 — 버퍼 기록(레이아웃 저장 시 flush). 자리표시 보존.
    await waitFor(() => expect(getPendingValue('custom.home.5', 'ko')).toContain('{p0}'));
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);
    expect(getPendingValue('custom.home.5', 'en')).toBeUndefined(); // 다른 로케일 미기록
    await waitFor(() => expect(onCommit).toHaveBeenCalled());
  });

  it('외부 클릭 시 닫힘(commit) — 편집 중 다른 엘리먼트 클릭', async () => {
    stub({ ko: '{p0} 님 환영', en: '' });
    const onCommit = vi.fn();
    render(
      <div>
        <InlineParamChipEditor box={box} node={node} customKey="custom.home.5" templateIdentifier="tpl" locale="ko" t={t} onCommit={onCommit} onCancel={vi.fn()} />
        <button data-testid="outside-el">바깥</button>
      </div>,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    // 편집기 바깥 엘리먼트 pointerdown(캡처) → 닫힘(commit) 발화.
    const outside = screen.getByTestId('outside-el');
    outside.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
    await waitFor(() => expect(onCommit).toHaveBeenCalled());
  });

  it('변경 없음 → PUT 미발생 + onCommit', async () => {
    const { calls } = stub({ ko: '{p0} 님 환영', en: '' });
    const onCommit = vi.fn();
    render(
      <InlineParamChipEditor box={box} node={node} customKey="custom.home.5" templateIdentifier="tpl" locale="ko" t={t} onCommit={onCommit} onCancel={vi.fn()} />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    fireEvent.click(screen.getByTestId('g7le-inline-param-chip-save'));
    await waitFor(() => expect(onCommit).toHaveBeenCalled());
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);
  });

  // === '+데이터' 커서 위치 삽입 =========================================
  // PlaceholderChipInput 의 '+데이터' 버튼은 onInsertBinding 이 주입됐을 때만 노출되고, 클릭하면
  // 커서 위치 피커가 열린다(InlineBindingScalarPicker, defaultOpen). 후보 선택 시 onInsertBinding 이
  // 커서 charIndex + 후보로 호출되고, 반환된 키 값으로 칩 문장이 즉시 갱신된다.
  const candidates = [
    { expression: '{{user.name}}', source: 'data_source', sourceId: 'user', path: 'name', shape: 'scalar', preview: '샘플', labelKey: undefined, groupLabelKey: undefined } as any,
  ];

  it('onInsertBinding 미전달 → +데이터 버튼 숨김', async () => {
    stub({ ko: '{p0} 님 환영', en: '' });
    render(
      <InlineParamChipEditor box={box} node={node} customKey="custom.home.5" templateIdentifier="tpl" locale="ko" t={t} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    expect(screen.queryByTestId('g7le-chip-insert-inline')).toBeNull();
  });

  it('onInsertBinding 전달 → +데이터 버튼 노출 + 클릭 시 피커 열림', async () => {
    stub({ ko: '{p0} 님 환영', en: '' });
    render(
      <InlineParamChipEditor box={box} node={node} customKey="custom.home.5" templateIdentifier="tpl" locale="ko" t={t}
        candidates={candidates} onInsertBinding={vi.fn(async () => null)} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    const insertBtn = screen.getByTestId('g7le-chip-insert-inline');
    expect(insertBtn).toBeTruthy();
    fireEvent.click(insertBtn);
    // 피커가 defaultOpen 으로 즉시 결과 목록을 보여준다.
    await waitFor(() => expect(screen.getByTestId('g7le-inline-param-chip-insert-picker')).toBeTruthy());
    await waitFor(() => expect(screen.getByTestId('g7le-inline-binding-picker-inline-insert')).toBeTruthy());
  });

  it('후보 선택 → onInsertBinding(커서 charIndex, 후보) 호출 + 반환 키 값으로 칩 문장 갱신', async () => {
    stub({ ko: '{p0} 님 환영', en: '' });
    // 끝(charIndex=키값 길이)에 새 칩 삽입 → 키 값 `{p0} 님 환영{p1}` 반환 시뮬레이션.
    const onInsertBinding = vi.fn(async (_charIndex: number, _c: any) => '{p0} 님 환영{p1}');
    render(
      <InlineParamChipEditor box={box} node={node} customKey="custom.home.5" templateIdentifier="tpl" locale="ko" t={t}
        candidates={candidates} onInsertBinding={onInsertBinding} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    fireEvent.click(screen.getByTestId('g7le-chip-insert-inline'));
    await waitFor(() => expect(screen.getByTestId('g7le-inline-binding-candidate-{{user.name}}')).toBeTruthy());
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{user.name}}'));
    // onInsertBinding 이 커서 charIndex(selection 없으면 키 값 길이=끝) + 후보로 호출됨.
    await waitFor(() => expect(onInsertBinding).toHaveBeenCalled());
    // 피커가 resolvedLabel 을 덧붙여 넘기므로 객체 동일성이 아닌 식별 필드로 대조.
    expect(onInsertBinding.mock.calls[0][1].expression).toBe('{{user.name}}');
    expect(onInsertBinding.mock.calls[0][1].path).toBe('name');
    // charIndex 는 selection 부재 시 키 값 길이(끝). 끝 삽입 → number.
    expect(typeof onInsertBinding.mock.calls[0][0]).toBe('number');
    // 반환 키 값으로 두 번째 칩(p1) 이 추가 렌더 — 칩 문장 즉시 갱신(라이브).
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p1')).toBeTruthy());
    // 삽입 후 피커는 닫힌다(insertAt=null).
    await waitFor(() => expect(screen.queryByTestId('g7le-inline-param-chip-insert-picker')).toBeNull());
  });

  it('S9-N2 회귀: 미커밋 로컬 변경(칩 드래그/평문) 후 +데이터 → 삽입 전 선커밋(이동 소실 방지)', async () => {
    // 결함 — 칩 드래그 이동은 위젯 로컬 state(onChange)에만 있고 commit 시점에 버퍼 기록되는데,
    // '+데이터' 삽입(insertBindingIntoParamKey)은 pending/서버 값 기준으로 키 값을 재구성한다.
    // 선커밋 없이는 드래그 직후 '+데이터' 시 그 이동이 통째로 소실된다(라이브 .70 4칩 실측 —
    // ③-C/④-A 이동이 ④-B 추가 시 DB 저장값 순서로 되돌아감). 삽입 전 로컬 변경을 버퍼에
    // 먼저 커밋해야 onInsertBinding 이 최신 문장 기반으로 삽입한다.
    stub({ ko: '{p0} 님 환영', en: '' });
    let pendingAtInsert: string | undefined;
    const onInsertBinding = vi.fn(async (_charIndex: number, _c: any) => {
      pendingAtInsert = getPendingValue('custom.home.5', 'ko'); // 호출 시점의 버퍼 상태 캡처.
      return '{p0} 님 환영함{p1}';
    });
    render(
      <InlineParamChipEditor box={box} node={node} customKey="custom.home.5" templateIdentifier="tpl" locale="ko" t={t}
        candidates={candidates} onInsertBinding={onInsertBinding} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    // 로컬 변경(커밋 전) — 평문 수정. 칩 드래그도 동일 onChange 경로라 등가.
    const span = document.querySelector('[data-testid="g7le-chip-text-inline-2"]') as HTMLElement;
    span.textContent = ' 님 환영함';
    fireEvent.input(span);
    // 커밋 없이 곧바로 '+데이터' → 후보 선택.
    fireEvent.click(screen.getByTestId('g7le-chip-insert-inline'));
    await waitFor(() => expect(screen.getByTestId('g7le-inline-binding-candidate-{{user.name}}')).toBeTruthy());
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{user.name}}'));
    await waitFor(() => expect(onInsertBinding).toHaveBeenCalled());
    // 핵심: onInsertBinding 호출 **시점**에 로컬 변경이 이미 버퍼에 선커밋되어 있어야 한다.
    expect(pendingAtInsert).toBe('{p0} 님 환영함');
  });

  // === 미키화 데이터 노드 — 칩 온 엔트리 ============================
  // 데이터 든 미키화 노드(plain_with_binding)는 customKey 가 없다. initialChipValue(파생 자리표시
  // 문장) + chipParamLabels 로 칩을 첫 진입부터 렌더하고, 내용 변경 시 onKeyify 로 키화한다.
  const unkeyedNode: EditorNode = { name: 'Span', text: '$t:policy.published_at: {{termsContent?.data?.published_at | date}}' };

  it('미키화 노드: customKey=null + initialChipValue → fetch 없이 즉시 칩 렌더(데이터 칩)', async () => {
    const { calls } = stub({});
    render(
      <InlineParamChipEditor box={box} node={unkeyedNode} customKey={null}
        initialChipValue="발행일: {p0}" chipParamLabels={{ p0: 'published_at' }}
        onKeyify={vi.fn(async () => 'custom.home.9')}
        templateIdentifier="tpl" locale="ko" t={t} onCommit={vi.fn()} onCancel={vi.fn()} />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-inline-param-chip-editor')).toBeTruthy());
    // 데이터 칩 즉시 렌더(키 없이 파생 값에서).
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    // 키가 없으므로 custom-translations GET fetch 미발생(파생 값 사용).
    expect(calls.some((c) => c.url.includes('/custom-translations') && c.method === 'GET')).toBe(false);
    // 칩 라벨 = chipParamLabels 의 친화명.
    expect(screen.getByTestId('g7le-chip-inline-p0').textContent).toContain('published_at');
  });

  it('미키화 노드: 평문 변경 후 저장 → onKeyify(편집된 키 값) 호출 (내용 변경 시 키 생성)', async () => {
    stub({});
    const onKeyify = vi.fn(async () => 'custom.home.9');
    const onCommit = vi.fn();
    render(
      <InlineParamChipEditor box={box} node={unkeyedNode} customKey={null}
        initialChipValue="발행일: {p0}" chipParamLabels={{ p0: 'published_at' }}
        onKeyify={onKeyify} templateIdentifier="tpl" locale="ko" t={t} onCommit={onCommit} onCancel={vi.fn()} />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    // 슬롯 [text("발행일: "), chip, text("")] — 앞 평문(인덱스 0) 변경.
    const span = document.querySelector('[data-testid="g7le-chip-text-inline-0"]') as HTMLElement;
    span.textContent = '발행일자: ';
    fireEvent.input(span);
    fireEvent.click(screen.getByTestId('g7le-inline-param-chip-save'));
    // 내용 변경됨 → onKeyify 호출(키 생성). 편집된 키 값 전달.
    await waitFor(() => expect(onKeyify).toHaveBeenCalled());
    expect(onKeyify.mock.calls[0][0]).toContain('{p0}'); // 자리표시 보존된 키 값
    await waitFor(() => expect(onCommit).toHaveBeenCalled());
  });

  it('미키화 노드: 변경 없이 저장 → onKeyify 미호출 (내용 변경 시에만 키 생성)', async () => {
    stub({});
    const onKeyify = vi.fn(async () => 'custom.home.9');
    const onCommit = vi.fn();
    render(
      <InlineParamChipEditor box={box} node={unkeyedNode} customKey={null}
        initialChipValue="발행일: {p0}" chipParamLabels={{ p0: 'published_at' }}
        onKeyify={onKeyify} templateIdentifier="tpl" locale="ko" t={t} onCommit={onCommit} onCancel={vi.fn()} />,
    );
    await waitFor(() => expect(screen.getByTestId('g7le-chip-inline-p0')).toBeTruthy());
    // 변경 없이 바로 저장.
    fireEvent.click(screen.getByTestId('g7le-inline-param-chip-save'));
    await waitFor(() => expect(onCommit).toHaveBeenCalled());
    expect(onKeyify).not.toHaveBeenCalled(); // 키 생성 안 함.
  });
});
