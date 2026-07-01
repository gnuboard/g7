// e2e:allow [자동 계산] 폼 단위(RTL) — 카드/프리셋/직접만들기/상속 위젯 합성, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * ComputedForm.test.tsx — [자동 계산] 탭 폼 RTL
 *
 * 검증:
 *  ① 프리셋 매칭 카드 + 결과값 미리보기
 *  ② 9종 보기 추가(common/more 그룹) → computed[key] 생성
 *  ③ 직접 만들기(3단계 틀) → computed[key] 생성
 *  ④ 미환원 식 = [고급] 보존(키명+평가값, 편집 부재)
 *  ⑤ 삭제 → computed 키 제거
 *  ⑥ 부모 상속(__computedSource=base) 〔공통〕 배지 + 편집 가능 + 덮어쓰기 안내
 *  ⑦ 디그레이드(recipes 없음) → 직접 만들기 잔존
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ComputedForm } from '../../../components/page-settings/ComputedForm';
import { clearWidgetRegistry } from '../../../spec/widgetRegistry';
import type { ComputedRecipeSpec } from '../../../spec/specTypes';

const t = (k: string) => k;

const RECIPES: Record<string, ComputedRecipeSpec> = {
  filterDefault: {
    label: '$t:필터 자동 채우기',
    group: 'common',
    params: [
      { key: 'localPath', label: '$t:로컬 경로', widget: 'text' },
      { key: 'queryKey', label: '$t:쿼리 키', widget: 'text' },
    ],
    expr: "_local.{localPath} ?? query.{queryKey} ?? ''",
  },
  groupItems: {
    label: '$t:묶음 만들기',
    group: 'more',
    params: [{ key: 'source', widget: 'text' }],
    expr: '({source} ?? []).reduce((m, x) => m, {})',
  },
};

const sampleContext = { products: { data: { data: [{ x: 1 }, { x: 2 }] } }, query: {}, _local: {} };

beforeEach(() => {
  cleanup();
  clearWidgetRegistry();
});

describe('ComputedForm', () => {
  it('프리셋 매칭 카드 + 미리보기를 표시한다', () => {
    const computed = { searchField: "{{ _local.search ?? query.q ?? '' }}" };
    render(<ComputedForm computed={computed} onChange={vi.fn()} recipes={RECIPES} t={t} sampleContext={sampleContext} />);
    expect(screen.getByTestId('g7le-computed-item-searchField')).toBeInTheDocument();
    // 매칭됐으므로 친화 라벨, 편집 버튼 존재.
    expect(screen.getByTestId('g7le-computed-edit-searchField')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-computed-preview-searchField')).toBeInTheDocument();
  });

  it('각 카드 [</>] 토글 → 원본 식(코드) 미리보기 노출', () => {
    const computed = { searchField: "{{ _local.search ?? query.q ?? '' }}" };
    render(<ComputedForm computed={computed} onChange={vi.fn()} recipes={RECIPES} t={t} sampleContext={sampleContext} />);
    // 토글 전 코드 블록 없음.
    expect(screen.queryByTestId('g7le-computed-code-block-searchField')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-computed-code-searchField'));
    const code = screen.getByTestId('g7le-computed-code-block-searchField');
    expect(code).toBeInTheDocument();
    expect(code.textContent).toBe("{{ _local.search ?? query.q ?? '' }}");
  });

  it('고급(미환원) 카드도 [</>] 코드 미리보기 제공 — 식 확인 경로 유일', () => {
    // 프리셋/틀로 환원 안 되는 식 → 고급 카드. 편집 버튼은 없지만 코드 보기는 있어야 한다.
    const computed = { weird: '{{ products.data.reduce((a, b) => a + b.qty, 0) * taxRate }}' };
    render(<ComputedForm computed={computed} onChange={vi.fn()} recipes={RECIPES} t={t} sampleContext={sampleContext} />);
    expect(screen.queryByTestId('g7le-computed-edit-weird')).toBeNull(); // 고급 = 편집 버튼 없음
    fireEvent.click(screen.getByTestId('g7le-computed-code-weird'));
    expect(screen.getByTestId('g7le-computed-code-block-weird').textContent).toContain('reduce');
  });

  it('9종 보기 추가(common/more 그룹) → computed 키 생성', () => {
    const onChange = vi.fn();
    render(<ComputedForm computed={{}} onChange={onChange} recipes={RECIPES} t={t} sampleContext={sampleContext} />);
    fireEvent.click(screen.getByTestId('g7le-computed-add'));
    expect(screen.getByTestId('g7le-computed-preset-filterDefault')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-computed-preset-filterDefault'));
    expect(onChange).toHaveBeenCalled();
    const next = onChange.mock.calls.at(-1)![0];
    expect(Object.keys(next)).toContain('filterDefault');
    expect(next.filterDefault).toMatch(/^\{\{/);
  });

  it('직접 만들기로 computed 키를 만든다', () => {
    const onChange = vi.fn();
    render(<ComputedForm computed={{}} onChange={onChange} recipes={RECIPES} t={t} sampleContext={sampleContext} />);
    fireEvent.click(screen.getByTestId('g7le-computed-add'));
    fireEvent.click(screen.getByTestId('g7le-computed-custom-open'));
    // datasource-picker 미등록 → 자유 입력 폴백(testid 는 래퍼에 있어 input 직접 조회).
    const sourceInput = screen.getByTestId('g7le-computed-custom-source').querySelector('input')!;
    fireEvent.change(sourceInput, { target: { value: 'products.data.data' } });
    fireEvent.change(screen.getByTestId('g7le-computed-custom-key'), { target: { value: 'cnt' } });
    fireEvent.click(screen.getByTestId('g7le-computed-custom-commit'));
    const next = onChange.mock.calls.at(-1)![0];
    expect(next.cnt).toContain('.length');
  });

  it('미환원 식은 [고급] 보존(편집 부재, 평가값 표시)', () => {
    const computed = { weird: '{{ custom.weird && tree(x) }}' };
    render(<ComputedForm computed={computed} onChange={vi.fn()} recipes={RECIPES} t={t} sampleContext={sampleContext} />);
    expect(screen.getByTestId('g7le-computed-advanced')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-computed-edit-weird')).not.toBeInTheDocument();
    // 평가 미리보기는 존재(키명+평가).
    expect(screen.getByTestId('g7le-computed-preview-weird')).toBeInTheDocument();
  });

  it('삭제 시 computed 키를 제거한다', () => {
    const onChange = vi.fn();
    const computed = { a: '{{ 1 }}', b: '{{ 2 }}' };
    render(<ComputedForm computed={computed} onChange={onChange} recipes={RECIPES} t={t} sampleContext={sampleContext} />);
    fireEvent.click(screen.getByTestId('g7le-computed-remove-a'));
    expect(onChange).toHaveBeenCalledWith({ b: '{{ 2 }}' });
  });

  it('부모 상속(__computedSource=base) 〔공통〕 배지 + 편집 시 덮어쓰기 안내', () => {
    const computed = { isReadOnly: "{{ _local.x ?? query.y ?? '' }}" };
    render(
      <ComputedForm
        computed={computed}
        onChange={vi.fn()}
        recipes={RECIPES}
        t={t}
        sampleContext={sampleContext}
        computedSource={{ isReadOnly: 'base' }}
      />,
    );
    expect(screen.getByTestId('g7le-computed-source-isReadOnly')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-computed-edit-isReadOnly'));
    expect(screen.getByTestId('g7le-computed-override-notice-isReadOnly')).toBeInTheDocument();
  });

  it('디그레이드(recipes 없음) — 직접 만들기는 잔존', () => {
    render(<ComputedForm computed={{}} onChange={vi.fn()} recipes={{}} t={t} sampleContext={sampleContext} />);
    fireEvent.click(screen.getByTestId('g7le-computed-add'));
    expect(screen.getByTestId('g7le-computed-custom-open')).toBeInTheDocument();
  });
});
