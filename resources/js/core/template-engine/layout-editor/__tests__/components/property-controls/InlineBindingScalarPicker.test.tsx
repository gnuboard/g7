// e2e:allow scalar 데이터 검색 피커 RTL — 부유/인라인 분기·기본값·후보 선택을 검증. 부유 위치
// 자동 보정(flip/clamp) 수치는 jsdom 좌표 0 이라 라이브(Chrome MCP)로 검증.
/**
 * InlineBindingScalarPicker.test.tsx — scalar 데이터 검색 피커 RTL
 *
 *
 * 검증:
 *  ① floating 기본값 = true — prop 미지정 호출처는 토글 펼침 시 부유(FloatingDropdown, role=dialog)
 *  ② floating={false} — 인라인 펼침(role=dialog 아님, 문서 흐름 pickerBox)
 *  ③ defaultOpen — 마운트 즉시 검색창/후보 노출(외부 토글 패턴)
 *  ④ 후보 선택 → onSelect 호출(부유/인라인 양쪽 동일)
 *
 * 회귀 잠금: 종전 기본값(false=인라인) 시 좁은 폼 행에서 펼침이 행을 밀어내 기존 UI 를 깨뜨렸다.
 * 기본값을 부유로 뒤집어 어느 진입점이든 토글 기준으로 떠서 행/패널을 밀어내지 않게 한다.
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup, within } from '@testing-library/react';
import { InlineBindingScalarPicker } from '../../../components/property-controls/InlineBindingScalarPicker';
import type { BindingCandidate } from '../../../spec/bindingCandidates';

beforeEach(() => cleanup());

const t = (key: string): string => key; // 식별 패스스루(키 그대로) — 라벨 해석은 fallback 경로 검증.

const CANDIDATES: BindingCandidate[] = [
  { expression: '{{user.name}}', sourceId: 'user', path: 'name', shape: 'scalar', preview: '홍길동' },
  { expression: '{{user.email}}', sourceId: 'user', path: 'email', shape: 'scalar', preview: 'a@b.c' },
  // array shape — scalar 필터로 노출 제외되어야(피커는 scalar 만).
  { expression: '{{products.data}}', sourceId: 'products', path: 'data', shape: 'array', preview: '[3]' },
];

describe('InlineBindingScalarPicker', () => {
  it('① floating 기본값(미지정) → 토글 펼침 시 부유(FloatingDropdown, role=dialog)', () => {
    render(
      <InlineBindingScalarPicker candidates={CANDIDATES} t={t} onSelect={vi.fn()} testIdSuffix="default" />,
    );
    // 펼치기 전에는 패널 없음.
    expect(screen.queryByTestId('g7le-inline-binding-picker-default')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-inline-binding-search-toggle-default'));
    const panel = screen.getByTestId('g7le-inline-binding-picker-default');
    // 부유 패널은 FloatingDropdown(role=dialog).
    expect(panel).toHaveAttribute('role', 'dialog');
    expect(within(panel).getByTestId('g7le-inline-binding-search-input-default')).toBeInTheDocument();
  });

  it('② floating={false} → 인라인 펼침(role=dialog 아님)', () => {
    render(
      <InlineBindingScalarPicker candidates={CANDIDATES} t={t} onSelect={vi.fn()} testIdSuffix="inline" floating={false} />,
    );
    fireEvent.click(screen.getByTestId('g7le-inline-binding-search-toggle-inline'));
    const panel = screen.getByTestId('g7le-inline-binding-picker-inline');
    // 인라인 pickerBox 는 dialog 아님(문서 흐름).
    expect(panel).not.toHaveAttribute('role', 'dialog');
    expect(within(panel).getByTestId('g7le-inline-binding-search-input-inline')).toBeInTheDocument();
  });

  it('③ defaultOpen → 마운트 즉시 검색창/후보 노출(외부 토글 패턴)', () => {
    render(
      <InlineBindingScalarPicker candidates={CANDIDATES} t={t} onSelect={vi.fn()} testIdSuffix="open" defaultOpen floating={false} />,
    );
    // 토글 클릭 없이 검색창과 scalar 후보가 보인다.
    expect(screen.getByTestId('g7le-inline-binding-search-input-open')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-inline-binding-candidate-{{user.name}}')).toBeInTheDocument();
    // array shape 후보는 scalar 필터로 제외.
    expect(screen.queryByTestId('g7le-inline-binding-candidate-{{products.data}}')).toBeNull();
  });

  it('④ 부유 모드 후보 선택 → onSelect 호출', () => {
    const onSelect = vi.fn();
    render(
      <InlineBindingScalarPicker candidates={CANDIDATES} t={t} onSelect={onSelect} testIdSuffix="pick" />,
    );
    fireEvent.click(screen.getByTestId('g7le-inline-binding-search-toggle-pick'));
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{user.email}}'));
    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(onSelect.mock.calls[0][0]).toMatchObject({ expression: '{{user.email}}' });
    // 선택 후 패널 닫힘(setOpen(false)).
    expect(screen.queryByTestId('g7le-inline-binding-picker-pick')).toBeNull();
  });

  it('④ 인라인 모드 후보 선택 → onSelect 호출', () => {
    const onSelect = vi.fn();
    render(
      <InlineBindingScalarPicker candidates={CANDIDATES} t={t} onSelect={onSelect} testIdSuffix="pick2" floating={false} />,
    );
    fireEvent.click(screen.getByTestId('g7le-inline-binding-search-toggle-pick2'));
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{user.name}}'));
    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(onSelect.mock.calls[0][0]).toMatchObject({ expression: '{{user.name}}' });
  });

  it('검색어 필터 — 키워드로 후보 좁힘', () => {
    render(
      <InlineBindingScalarPicker candidates={CANDIDATES} t={t} onSelect={vi.fn()} testIdSuffix="search" defaultOpen floating={false} />,
    );
    fireEvent.change(screen.getByTestId('g7le-inline-binding-search-input-search'), { target: { value: 'email' } });
    expect(screen.getByTestId('g7le-inline-binding-candidate-{{user.email}}')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-inline-binding-candidate-{{user.name}}')).toBeNull();
  });
});
