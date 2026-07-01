// e2e:allow 격리 영역 그룹 컨트롤 단위(RTL) — toggle/검색드롭다운/자유입력 위젯 합성, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * IsolatedScopeControl.test.tsx — "격리 영역" 그룹 RTL
 *
 * 검증(SZ1~SZ14):
 *  ① toggle OFF→ON: node.isolatedState={} + isolatedScopeId 자동 시드(노드 최상위 patch)
 *  ② OFF: 두 키 삭제
 *  ③ scopeId 검색 드롭다운(후보 필터) + 자유 텍스트 병행
 *  ④ 드롭다운↔입력 양방향(단일 키 수렴)
 *  ⑤ 빈 scopeId → 자동 시드 유지(빈 문자열 저장 안 함)
 *  ⑥ 중복 scopeId ⓘ 안내(허용)
 *  ⑦ node.props.isolated* 미생성 가드(노드 최상위만)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { IsolatedScopeControl } from '../../../components/property-controls/IsolatedScopeControl';
import type { EditorNode } from '../../../utils/layoutTreeUtils';

const t = (k: string) => k;

beforeEach(() => cleanup());

describe('IsolatedScopeControl', () => {
  it('OFF→ON: 노드 최상위 isolatedState={} + scopeId 자동 시드', () => {
    const onPatch = vi.fn();
    const node: EditorNode = { name: 'Div', props: { id: 'slider' } };
    render(<IsolatedScopeControl node={node} onPatchNode={onPatch} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-isolated-toggle'));
    const patched = onPatch.mock.calls[0][0] as Record<string, unknown>;
    expect(patched.isolatedState).toEqual({});
    expect(patched.isolatedScopeId).toBe('slider-scope');
    // props 오염 없음(노드 최상위만).
    expect((patched.props as Record<string, unknown>).isolatedState).toBeUndefined();
  });

  it('ON→OFF: 두 키 삭제', () => {
    const onPatch = vi.fn();
    const node: EditorNode = { name: 'Div', isolatedState: { step: 1 }, isolatedScopeId: 'wizard' };
    render(<IsolatedScopeControl node={node} onPatchNode={onPatch} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-isolated-toggle'));
    const patched = onPatch.mock.calls[0][0] as Record<string, unknown>;
    expect('isolatedState' in patched).toBe(false);
    expect('isolatedScopeId' in patched).toBe(false);
  });

  it('scopeId 검색 드롭다운 후보 필터 + 선택', () => {
    const onPatch = vi.fn();
    const node: EditorNode = { name: 'Div', isolatedState: {}, isolatedScopeId: 'a' };
    render(
      <IsolatedScopeControl
        node={node}
        onPatchNode={onPatch}
        t={t}
        scopeIdCandidates={['category-selector', 'product-slider', 'wizard']}
      />,
    );
    fireEvent.change(screen.getByTestId('g7le-isolated-scopeid-search'), { target: { value: 'slider' } });
    expect(screen.getByTestId('g7le-isolated-scopeid-candidate-product-slider')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-isolated-scopeid-candidate-wizard')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-isolated-scopeid-candidate-product-slider'));
    expect((onPatch.mock.calls.at(-1)![0] as Record<string, unknown>).isolatedScopeId).toBe('product-slider');
  });

  it('자유 텍스트 입력 병행 — 목록 밖 새 이름 허용', () => {
    const onPatch = vi.fn();
    const node: EditorNode = { name: 'Div', isolatedState: {}, isolatedScopeId: 'a' };
    render(<IsolatedScopeControl node={node} onPatchNode={onPatch} t={t} scopeIdCandidates={['x']} />);
    fireEvent.change(screen.getByTestId('g7le-isolated-scopeid-input'), { target: { value: 'brand-new-scope' } });
    expect((onPatch.mock.calls.at(-1)![0] as Record<string, unknown>).isolatedScopeId).toBe('brand-new-scope');
  });

  it('빈 scopeId → 자동 시드 유지(빈 문자열 저장 안 함)', () => {
    const onPatch = vi.fn();
    const node: EditorNode = { name: 'Div', props: { id: 'box' }, isolatedState: {}, isolatedScopeId: 'a' };
    render(<IsolatedScopeControl node={node} onPatchNode={onPatch} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-isolated-scopeid-input'), { target: { value: '' } });
    expect((onPatch.mock.calls.at(-1)![0] as Record<string, unknown>).isolatedScopeId).toBe('box-scope');
  });

  it('중복 scopeId ⓘ 안내(허용)', () => {
    const node: EditorNode = { name: 'Div', isolatedState: {}, isolatedScopeId: 'shared' };
    render(<IsolatedScopeControl node={node} onPatchNode={vi.fn()} t={t} usedScopeIds={['shared']} />);
    expect(screen.getByTestId('g7le-isolated-scopeid-dup')).toBeInTheDocument();
  });

  it('OFF 상태에서는 scopeId 입력칸 미노출', () => {
    const node: EditorNode = { name: 'Div', props: {} };
    render(<IsolatedScopeControl node={node} onPatchNode={vi.fn()} t={t} />);
    expect(screen.queryByTestId('g7le-isolated-scopeid-input')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-isolated-off-hint')).toBeInTheDocument();
  });
});
