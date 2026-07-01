// e2e:allow [초기 상태] 격리 섹션 단위(RTL) — orphan 경고/재귀편집, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * InitialStateForm.isolated.test.tsx — 격리 섹션 RTL
 *
 * 검증(SS13):
 *  ① 격리 섹션 렌더(라벨 + 설명문)
 *  ② initIsolated 전타입·중첩 재귀 편집(로컬과 동일 위젯)
 *  ③ orphan 경고(isolatedState 노드 0개 → ⚠ + 키별)
 *  ④ isolatedState 노드 존재 시 경고 해소
 *  ⑤ 값 삭제 강제 안 함(경고만)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import { InitialStateForm } from '../../../components/page-settings/InitialStateForm';

const t = (k: string) => k;

beforeEach(() => cleanup());

describe('InitialStateForm — 격리 섹션', () => {
  it('격리 섹션 렌더 + initIsolated 재귀 편집', () => {
    render(<InitialStateForm raw={{ initIsolated: { scrollIdx: 0 } }} patch={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-initstate-section-isolated')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-isolated-item-scrollIdx')).toBeInTheDocument();
    // 숫자 위젯(로컬과 동일 재귀 편집기).
    expect((screen.getByTestId('g7le-initstate-type-scrollIdx') as HTMLSelectElement).value).toBe('number');
  });

  it('orphan 경고 — isolatedState 노드 0개 → ⚠ 키별', () => {
    render(
      <InitialStateForm
        raw={{ initIsolated: { scrollIdx: 0, step: 1 } }}
        patch={vi.fn()}
        t={t}
        isolatedOrphan={{ scrollIdx: true, step: true }}
      />,
    );
    expect(screen.getByTestId('g7le-initstate-isolated-orphan-scrollIdx')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-isolated-orphan-step')).toBeInTheDocument();
  });

  it('isolatedState 노드 존재 시 경고 해소(orphan=false)', () => {
    render(
      <InitialStateForm
        raw={{ initIsolated: { scrollIdx: 0 } }}
        patch={vi.fn()}
        t={t}
        isolatedOrphan={{ scrollIdx: false }}
      />,
    );
    expect(screen.queryByTestId('g7le-initstate-isolated-orphan-scrollIdx')).not.toBeInTheDocument();
    // 값 자체는 보존(경고만 — 삭제 강제 안 함).
    expect(screen.getByTestId('g7le-initstate-isolated-item-scrollIdx')).toBeInTheDocument();
  });

  it('중첩 격리 시작값 재귀 편집', () => {
    render(<InitialStateForm raw={{ initIsolated: { state: { step: 1, selectedId: null } } }} patch={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-initstate-type-state.step')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-type-state.selectedId')).toBeInTheDocument();
  });
});
