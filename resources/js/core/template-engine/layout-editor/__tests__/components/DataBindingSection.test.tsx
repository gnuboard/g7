// e2e:allow 레이아웃 편집기 데이터 연결 속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(6-b 캔버스 라이브 §공통 검증) + 단위/레이아웃 렌더링 테스트로 검증 (specTypes.ts L1 과 동일 정책)
/**
 * DataBindingSection.test.tsx — 데이터 연결 영역 RTL
 *
 * 검증:
 *  - dataProps 행 렌더(scalar/array shape 배지 + 필수 배지)
 *  - 미연결 → 검색 피커 → 후보 선택 → `{{...}}` 를 props[propKey] 기입(round-trip)
 *  - 연결됨 → 표현식 표시 + [해제] → prop 삭제
 *  - 복합 바인딩(`{{a ? b : c}}` 삼항 등) → 디그레이드(코드 편집) 읽기전용, 검색 피커 미노출
 *  (단 `{{a?.b ?? []}}` 같은 G7 표준 안전 바인딩은에서 파싱·변경 가능)
 *  - shape 필터: array 행은 배열 후보만 노출
 *  - 회귀 가드(1차 결함): 구조/수치 prop(dataProps 미선언)은 데이터 연결 행 0
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { DataBindingSection } from '../../components/property-controls/DataBindingSection';
import type { DataPropSpec } from '../../spec/specTypes';
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
    expression: '{{_local.keyword}}',
    source: '_local',
    sourceId: '_local',
    path: 'keyword',
    shape: 'scalar',
    preview: '노트북',
  },
];

describe('DataBindingSection — 렌더', () => {
  it('dataProps 행을 shape 배지 + 필수 배지와 렌더한다', () => {
    const node: EditorNode = { name: 'CardGrid', props: {} };
    const dataProps: DataPropSpec[] = [
      { propKey: 'data', shape: 'array', label: '데이터', itemFields: ['name'], required: true },
    ];
    render(
      <DataBindingSection node={node} dataProps={dataProps} candidates={candidates} t={t} onPatchNode={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-data-binding-section')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-binding-row-data')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-binding-required-data')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-binding-empty-data')).toBeInTheDocument();
  });

  it('dataProps 가 비면 null 렌더(영역 비노출)', () => {
    const node: EditorNode = { name: 'Div', props: {} };
    const { container } = render(
      <DataBindingSection node={node} dataProps={[]} candidates={candidates} t={t} onPatchNode={vi.fn()} />,
    );
    expect(container.firstChild).toBeNull();
  });
});

describe('DataBindingSection — 연결 round-trip', () => {
  it('검색 → array 후보 선택 → {{...}} 를 props[propKey] 기입', () => {
    const node: EditorNode = { name: 'CardGrid', props: {} };
    const dataProps: DataPropSpec[] = [{ propKey: 'data', shape: 'array' }];
    const onPatchNode = vi.fn();
    render(
      <DataBindingSection node={node} dataProps={dataProps} candidates={candidates} t={t} onPatchNode={onPatchNode} />,
    );
    fireEvent.click(screen.getByTestId('g7le-binding-search-toggle-data'));
    // array 행이라 배열 후보만 — products.data.data 노출, _local.keyword(scalar) 미노출
    expect(screen.getByTestId('g7le-binding-candidate-{{products.data.data}}')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-binding-candidate-{{_local.keyword}}')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-binding-candidate-{{products.data.data}}'));
    // 선택 시 G7 표준 안전 형태(`?.` 체이닝 + shape 별 폴백)로 기입.
    expect(onPatchNode).toHaveBeenCalledWith(
      expect.objectContaining({ props: { data: '{{products?.data?.data ?? []}}' } }),
    );
  });

  it('연결됨 → 표현식 표시 + [해제] → prop 삭제', () => {
    const node: EditorNode = { name: 'CardGrid', props: { data: '{{products.data.data}}' } };
    const dataProps: DataPropSpec[] = [{ propKey: 'data', shape: 'array' }];
    const onPatchNode = vi.fn();
    render(
      <DataBindingSection node={node} dataProps={dataProps} candidates={candidates} t={t} onPatchNode={onPatchNode} />,
    );
    expect(screen.getByTestId('g7le-binding-expr-data').textContent).toBe('{{products.data.data}}');
    fireEvent.click(screen.getByTestId('g7le-binding-clear-data'));
    expect(onPatchNode).toHaveBeenCalledWith(expect.objectContaining({ props: {} }));
  });
});

describe('DataBindingSection — 복합 바인딩 디그레이드', () => {
  it('단일 경로가 아닌 복합식은 코드편집 디그레이드 + 검색 피커 미노출', () => {
    const node: EditorNode = { name: 'Input', props: { value: '{{a ? b : c}}' } };
    const dataProps: DataPropSpec[] = [{ propKey: 'value', shape: 'scalar' }];
    render(
      <DataBindingSection node={node} dataProps={dataProps} candidates={candidates} t={t} onPatchNode={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-binding-complex-value')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-binding-search-toggle-value')).toBeNull();
  });
});

describe('DataBindingSection — scalar 행 shape 필터', () => {
  it('scalar 행은 스칼라 후보만 노출', () => {
    const node: EditorNode = { name: 'Input', props: {} };
    const dataProps: DataPropSpec[] = [{ propKey: 'value', shape: 'scalar' }];
    render(
      <DataBindingSection node={node} dataProps={dataProps} candidates={candidates} t={t} onPatchNode={vi.fn()} />,
    );
    fireEvent.click(screen.getByTestId('g7le-binding-search-toggle-value'));
    expect(screen.getByTestId('g7le-binding-candidate-{{_local.keyword}}')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-binding-candidate-{{products.data.data}}')).toBeNull();
  });
});
