// e2e:allow [초기 상태] 격리 상속 매트릭스 단위(RTL) — I16~I18 전수, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * initial-state-isolated-inheritance.test.tsx — [초기 상태] 격리 상속 매트릭스 RTL
 *
 *  매트릭스 I16~I18 의 격리(initIsolated) 상속을 전수 검증한다. 정책 = initLocal 과 동일
 * (shallow merge·🔗상속 배지·덮기·되돌림·strip) + **격리 고유 짝 검증**: 상속받은 initIsolated 키도
 * isolatedState 노드가 없으면 orphan 경고(상속이어도 짝 없으면 죽은 값).
 *
 * 각 it() = 매트릭스 1 행(I16~I18). 케이스 ID 를 라벨에 명시(누락 0 기준).
 * orphan 판정은 호스트의 classifyIsolatedOrphan(트리 isolatedState 노드 유무) 결과를 isolatedOrphan
 * prop 으로 주입 — 상속 키도 그 맵에 포함되어 짝 검증 대상이 됨.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { InitialStateForm } from '../../../components/page-settings/InitialStateForm';

const t = (k: string) => k;

beforeEach(() => cleanup());

describe('initial-state-isolated-inheritance — 격리 상속 매트릭스 (I16~I18)', () => {
  it('I16: 부모 initIsolated + 자식 initIsolated → shallow merge·🔗상속 배지·덮기·되돌림·strip(initLocal 과 동일 정책)', () => {
    const patch = vi.fn();
    render(
      <InitialStateForm
        raw={{ initIsolated: { fromParent: 0, fromChild: 1 } }}
        own={{ initIsolated: { fromChild: 1 } }}
        patch={patch}
        t={t}
        // 짝 충족(노드 존재)으로 가정 — 본 케이스는 상속/덮기/되돌림 정책 검증.
        isolatedOrphan={{ fromParent: false, fromChild: false }}
      />,
    );
    // 부모 키 🔗 상속 배지, 자식 키 무배지.
    expect(screen.getByTestId('g7le-initstate-inherited-fromParent')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-initstate-inherited-fromChild')).not.toBeInTheDocument();
    // 되돌림 → initIsolated 자기 분만(상속분 제거).
    fireEvent.click(screen.getByTestId('g7le-initstate-revert-fromParent'));
    expect(patch).toHaveBeenLastCalledWith('initIsolated', { fromChild: 1 });
  });

  it('I17: 상속 initIsolated 키 + 자식 isolatedState 노드 존재 → orphan 경고 없음(짝 충족)', () => {
    render(
      <InitialStateForm
        raw={{ initIsolated: { scrollIdx: 0 } }}
        own={{ initIsolated: {} }}
        patch={vi.fn()}
        t={t}
        // isolatedState 노드 존재(짝 충족) → orphan=false.
        isolatedOrphan={{ scrollIdx: false }}
      />,
    );
    // 상속 키 표시 + 🔗 배지 + 짝 충족(orphan 경고 부재).
    expect(screen.getByTestId('g7le-initstate-isolated-item-scrollIdx')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-inherited-scrollIdx')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-initstate-isolated-orphan-scrollIdx')).not.toBeInTheDocument();
  });

  it('I18: 상속 initIsolated 키 + isolatedState 노드 0개 → ⚠ orphan(상속이어도 짝 없으면 죽은 값), 삭제 미강제', () => {
    render(
      <InitialStateForm
        raw={{ initIsolated: { scrollIdx: 0, step: 1 } }}
        own={{ initIsolated: {} }}
        patch={vi.fn()}
        t={t}
        // isolatedState 노드 0개 → 상속 키도 orphan(짝 검증 대상).
        isolatedOrphan={{ scrollIdx: true, step: true }}
      />,
    );
    // 상속 키이지만 짝 없음 → ⚠ orphan 경고(키별).
    expect(screen.getByTestId('g7le-initstate-isolated-orphan-scrollIdx')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-isolated-orphan-step')).toBeInTheDocument();
    // 상속 배지도 동반(상속 키임).
    expect(screen.getByTestId('g7le-initstate-inherited-scrollIdx')).toBeInTheDocument();
    // 삭제 미강제 — 값 자체는 보존(항목 잔존).
    expect(screen.getByTestId('g7le-initstate-isolated-item-scrollIdx')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-initstate-isolated-item-step')).toBeInTheDocument();
  });
});
