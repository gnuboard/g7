// e2e:allow 레이아웃 편집기 반복 데이터 연결 속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스 + 단위/레이아웃 렌더링 테스트로 검증 (DataBindingSection.test.tsx L1 과 동일 정책)
/**
 * IterationBindingSection.test.tsx — 반복(iteration) 데이터 연결 영역 RTL
 *
 * 검증:
 *  - iteration 노드 → "반복 데이터 연결" 영역 렌더(array 배지 + 힌트)
 *  - iteration 없는 노드 → null(영역 비노출 — 노드 구조 기반 게이트)
 *  - 미연결 → 검색 피커 → array 후보 선택 → node.iteration.source 기입(round-trip),
 *    다른 iteration 키(item_var) 보존
 *  - 연결됨 → 표현식 + [해제] → source 키만 제거(iteration 객체는 유지)
 *  - 복합 바인딩(`{{x ?? []}}`) → 디그레이드(코드 편집) 읽기전용, 검색 피커 미노출
 *  - shape 필터: 배열 후보만 노출(scalar 후보 미노출)
 *  - item_var/index_var 읽기전용 힌트 표시
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { IterationBindingSection } from '../../components/property-controls/IterationBindingSection';
import type { BindingCandidate } from '../../spec/bindingCandidates';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const t = (k: string) => k;

afterEach(() => cleanup());

const candidates: BindingCandidate[] = [
  {
    expression: '{{products.data.data}}',
    source: 'data_source',
    sourceId: 'products',
    path: 'data.data',
    shape: 'array',
    groupLabelKey: 'editor.ds.products',
    preview: '[3]',
    itemFields: ['name', 'price'],
  },
  {
    expression: '{{categories.data}}',
    source: 'data_source',
    sourceId: 'categories',
    path: 'data',
    shape: 'array',
    preview: '[5]',
    itemFields: ['id', 'name'],
  },
  {
    expression: '{{_local.keyword}}',
    source: '_local',
    sourceId: '_local',
    path: 'keyword',
    shape: 'scalar',
    preview: '노트북',
  },
];

describe('IterationBindingSection — 게이트', () => {
  it('iteration 노드면 "반복 데이터 연결" 영역을 렌더한다', () => {
    const node: EditorNode = { name: 'Div', iteration: { source: '', item_var: 'item' } };
    render(<IterationBindingSection node={node} candidates={candidates} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-iteration-binding-section')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-iteration-binding-empty')).toBeInTheDocument();
  });

  it('iteration 없는 노드면 null(영역 비노출)', () => {
    const node: EditorNode = { name: 'Div', props: {} };
    const { container } = render(
      <IterationBindingSection node={node} candidates={candidates} t={t} onPatchNode={vi.fn()} />,
    );
    expect(container.firstChild).toBeNull();
  });
});

describe('IterationBindingSection — 연결 round-trip', () => {
  it('검색 → array 후보만 노출(scalar 미노출) → 선택 시 iteration.source 기입 + item_var 보존', () => {
    const node: EditorNode = { name: 'Div', iteration: { source: '', item_var: 'product' } };
    const onPatchNode = vi.fn();
    render(<IterationBindingSection node={node} candidates={candidates} t={t} onPatchNode={onPatchNode} />);
    fireEvent.click(screen.getByTestId('g7le-iteration-binding-search-toggle'));
    expect(screen.getByTestId('g7le-iteration-binding-candidate-{{products.data.data}}')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-iteration-binding-candidate-{{categories.data}}')).toBeInTheDocument();
    // scalar 후보는 반복 소스 대상 아님 — 미노출
    expect(screen.queryByTestId('g7le-iteration-binding-candidate-{{_local.keyword}}')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-iteration-binding-candidate-{{products.data.data}}'));
    // 반복 소스는 항상 배열 → G7 표준 안전 형태(`?.` + `?? []`)로 기입.
    expect(onPatchNode).toHaveBeenCalledWith(
      expect.objectContaining({
        iteration: { source: '{{products?.data?.data ?? []}}', item_var: 'product' },
      }),
    );
  });

  it('연결됨 → 표현식 표시 + [해제] → source 키만 제거(iteration 객체 유지)', () => {
    const node: EditorNode = {
      name: 'Div',
      iteration: { source: '{{products.data.data}}', item_var: 'product', index_var: 'i' },
    };
    const onPatchNode = vi.fn();
    render(<IterationBindingSection node={node} candidates={candidates} t={t} onPatchNode={onPatchNode} />);
    expect(screen.getByTestId('g7le-iteration-binding-expr')).toHaveTextContent('{{products.data.data}}');
    fireEvent.click(screen.getByTestId('g7le-iteration-binding-clear'));
    expect(onPatchNode).toHaveBeenCalledWith(
      expect.objectContaining({ iteration: { item_var: 'product', index_var: 'i' } }),
    );
    // source 키만 제거 — item_var/index_var 보존
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect((patched.iteration as Record<string, unknown>).source).toBeUndefined();
  });
});

describe('IterationBindingSection — G7 표준 안전 바인딩 인식', () => {
  // 실제 레이아웃의 iteration 소스는 전부 `?.` + `?? []` 표준 형태인데,
  // 이전 파서가 이를 복합식으로 묶어 "코드 편집" 디그레이드 → 검색 피커가 가려져 변경 불가.
  // 이제 안전 바인딩은 연결됨으로 인식하고 검색 피커로 변경 가능해야 한다.
  it('`{{products?.data?.data ?? []}}` 는 연결됨으로 인식 + 검색 피커로 변경 가능', () => {
    const node: EditorNode = {
      name: 'Div',
      iteration: { source: '{{products?.data?.data ?? []}}', item_var: 'product' },
    };
    const onPatchNode = vi.fn();
    render(<IterationBindingSection node={node} candidates={candidates} t={t} onPatchNode={onPatchNode} />);
    // 복합 디그레이드 아님 — 연결됨 표현식 + 검색 토글 노출.
    expect(screen.queryByTestId('g7le-iteration-binding-complex')).toBeNull();
    expect(screen.getByTestId('g7le-iteration-binding-expr')).toHaveTextContent('{{products?.data?.data ?? []}}');
    expect(screen.getByTestId('g7le-iteration-binding-search-toggle')).toBeInTheDocument();
    // 다른 소스로 변경 가능 — categories 선택 시 안전 형태로 재기입.
    fireEvent.click(screen.getByTestId('g7le-iteration-binding-search-toggle'));
    fireEvent.click(screen.getByTestId('g7le-iteration-binding-candidate-{{categories.data}}'));
    expect(onPatchNode).toHaveBeenCalledWith(
      expect.objectContaining({
        iteration: { source: '{{categories?.data ?? []}}', item_var: 'product' },
      }),
    );
  });

  it('진짜 복합식(삼항·파이프)은 코드 편집 디그레이드 + 검색 피커 미노출', () => {
    const node: EditorNode = {
      name: 'Div',
      iteration: { source: '{{cond ? listA : listB}}', item_var: 'x' },
    };
    render(<IterationBindingSection node={node} candidates={candidates} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-iteration-binding-complex')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-iteration-binding-search-toggle')).toBeNull();
  });
});

describe('IterationBindingSection — 반복 변수 힌트', () => {
  it('item_var/index_var 를 읽기전용 힌트로 표시', () => {
    const node: EditorNode = {
      name: 'Div',
      iteration: { source: '', item_var: 'product', index_var: 'idx' },
    };
    render(<IterationBindingSection node={node} candidates={candidates} t={t} onPatchNode={vi.fn()} />);
    const vars = screen.getByTestId('g7le-iteration-vars');
    expect(vars).toHaveTextContent('product');
    expect(vars).toHaveTextContent('idx');
  });
});
