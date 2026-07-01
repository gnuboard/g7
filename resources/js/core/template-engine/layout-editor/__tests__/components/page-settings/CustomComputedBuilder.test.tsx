// e2e:allow 직접 만들기 3단계 빌더 단위(RTL) — select/조건행 위젯 합성, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * CustomComputedBuilder.test.tsx — 3단계 고정 틀 빌더 RTL
 *
 * 검증:
 *  ① 데이터(①) → 연산(②) 동사 전환 → 동사별 입력 노출
 *  ② 조건(count/sum/filter) AND 추가/삭제
 *  ③ 결과 이름(③) 중복 거부 경고
 *  ④ modelToExpr — 동사별 식 생성(buildCustomComputedExpr SSoT)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { CustomComputedBuilder, modelToExpr } from '../../../components/page-settings/CustomComputedBuilder';
import { clearWidgetRegistry } from '../../../spec/widgetRegistry';
import type { CustomComputedModel } from '../../../spec/computedRecipeEngine';

const t = (k: string) => k;

beforeEach(() => {
  cleanup();
  clearWidgetRegistry(); // datasource-picker 미등록 → 자유 입력 폴백.
});

function base(): CustomComputedModel {
  return { key: '', op: 'count', source: 'products.data.data', conditions: [] };
}

describe('CustomComputedBuilder', () => {
  it('연산 select 가 7동사를 노출하고 전환 시 동사별 입력을 보인다', () => {
    let model = base();
    const onChange = (m: CustomComputedModel) => { model = m; };
    const { rerender } = render(<CustomComputedBuilder model={model} onChange={onChange} t={t} />);

    fireEvent.change(screen.getByTestId('g7le-computed-custom-op'), { target: { value: 'toOptions' } });
    rerender(<CustomComputedBuilder model={model} onChange={onChange} t={t} />);
    expect(screen.getByTestId('g7le-computed-custom-valuefield')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-computed-custom-labelfield')).toBeInTheDocument();
  });

  it('count 동사는 조건 AND 행을 추가/삭제한다', () => {
    let model = base();
    const onChange = (m: CustomComputedModel) => { model = m; };
    const { rerender } = render(<CustomComputedBuilder model={model} onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-computed-custom-cond-add'));
    rerender(<CustomComputedBuilder model={model} onChange={onChange} t={t} />);
    expect(screen.getByTestId('g7le-computed-custom-cond-0')).toBeInTheDocument();
    expect(model.conditions).toHaveLength(1);

    fireEvent.click(screen.getByTestId('g7le-computed-custom-cond-remove-0'));
    expect(model.conditions).toHaveLength(0);
  });

  it('결과 이름이 기존 키와 겹치면 경고', () => {
    const model: CustomComputedModel = { ...base(), key: 'productCount' };
    render(<CustomComputedBuilder model={model} onChange={vi.fn()} t={t} existingKeys={['productCount']} />);
    expect(screen.getByTestId('g7le-computed-custom-key-dup')).toBeInTheDocument();
  });

  it('modelToExpr — count 동사 식 생성(buildCustomComputedExpr)', () => {
    const model: CustomComputedModel = {
      key: 'cnt',
      op: 'count',
      source: 'products.data.data',
      conditions: [{ field: 'is_active', cmp: '=', value: 'true' }],
    };
    const expr = modelToExpr(model);
    expect(expr).toContain('.filter(');
    expect(expr).toContain('.length');
    expect(expr).toMatch(/^\{\{[\s\S]*\}\}$/); // {{ }} 한 쌍.
  });

  it('③ 결과 이름 입력이 모델 key 를 갱신한다', () => {
    let model = base();
    const onChange = (m: CustomComputedModel) => { model = m; };
    render(<CustomComputedBuilder model={model} onChange={onChange} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-computed-custom-key'), { target: { value: 'myCount' } });
    expect(model.key).toBe('myCount');
  });

  // 자동 계산 값/경로 칸도 데이터 칩(DataChipValueInput)으로 단순
  // 데이터 연동 가능. 평문/경로는 종전처럼 그대로 입력(키화 0), `{{...}}` 데이터는 검색 칩/표현식.
  it('sum 합산 필드 = DataChipValueInput(데이터 칩 입력기)로 렌더 + 평문 경로 입력이 model.sumField 반영', () => {
    let model: CustomComputedModel = { ...base(), op: 'sum' };
    const onChange = (m: CustomComputedModel) => { model = m; };
    render(<CustomComputedBuilder model={model} onChange={onChange} t={t} />);
    // bare input 이 아니라 DataChipValueInput 의 평문 input(`-chip-input`).
    const input = screen.getByTestId('g7le-computed-custom-sumfield-chip-input');
    expect(input).toBeInTheDocument();
    fireEvent.change(input, { target: { value: 'price' } });
    expect(model.sumField).toBe('price');
  });

  it('조건 필드/값 = 데이터 칩 입력기로 렌더(단순 데이터 연동 진입)', () => {
    let model: CustomComputedModel = { ...base(), op: 'count', conditions: [{ field: '', cmp: '=', value: '' }] };
    const onChange = (m: CustomComputedModel) => { model = m; };
    render(<CustomComputedBuilder model={model} onChange={onChange} t={t} />);
    expect(screen.getByTestId('g7le-computed-custom-cond-field-0-chip-input')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-computed-custom-cond-value-0-chip-input')).toBeInTheDocument();
  });

  it('bindingCandidates 전달 시 값 칸 데이터 검색 피커 노출(단순 데이터 연동)', () => {
    const model: CustomComputedModel = { ...base(), op: 'literal' };
    const candidates = [
      { expression: '{{products.data.total}}', source: 'data_source' as const, sourceId: 'products', path: 'data.total', shape: 'scalar' as const, preview: '12' },
    ];
    render(<CustomComputedBuilder model={model} onChange={vi.fn()} t={t} bindingCandidates={candidates} />);
    // DataChipValueInput 평문 분기 — 후보 풀 보유 시 검색 피커(InlineBindingScalarPicker) 노출.
    expect(screen.getByTestId('g7le-computed-custom-literalvalue-chip-input')).toBeInTheDocument();
  });

  // nth 인덱스(숫자)·firstOf 후보(쉼표 다중 목록)는 의도적 평문 유지 — 데이터 칩 비대상.
  it('nth 인덱스는 평문 input 유지(배열 인덱스 — 데이터 칩 비대상)', () => {
    const model: CustomComputedModel = { ...base(), op: 'nth' };
    render(<CustomComputedBuilder model={model} onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-computed-custom-index')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-computed-custom-index-chip-input')).not.toBeInTheDocument();
    // 단, nth 속성(경로)은 데이터 칩.
    expect(screen.getByTestId('g7le-computed-custom-prop-chip-input')).toBeInTheDocument();
  });
});
