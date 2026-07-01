// e2e:allow 표현식 분해 트리 UI 단위(RTL) — I18nTextField 리프 위임은 mock(자체 테스트 보유). 트리
// 렌더·분기 편집·조건 빌더·직렬화 onChange·원본식 토글을 검증. 라이브는 Chrome MCP 매트릭스.
/**
 * ConditionalValueEditor.test.tsx — 표현식 분해 트리 UI RTL
 *
 * 검증:
 *  ① conditional 트리 렌더 — 조건 빌더 + 참/거짓 분기(리프=I18nTextField mock)
 *  ② 조건 편집(left/op/right) → 직렬화된 새 node.text onChange
 *  ③ 분기 리프 편집 → 직렬화 onChange(round-trip 의미 보존)
 *  ④ fallback / concat 노드 렌더
 *  ⑤ raw 조건/raw 노드 → readonly 코드 표시(입력칸 부재)
 *  ⑥ [</> 원본 식 보기] 토글 → 직렬화 식 표시
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

// I18nTextField(리프) — 자체 테스트 보유. 경량 input mock 으로 트리 라우팅에 집중.
vi.mock('../../../components/property-controls/I18nTextField', () => ({
  I18nTextField: ({
    value,
    onChange,
    testidPrefix,
  }: {
    value: string;
    onChange: (v: string | undefined) => void;
    testidPrefix: string;
  }) => (
    <input
      data-testid={`${testidPrefix}-mock`}
      value={value ?? ''}
      onChange={(e) => onChange(e.target.value)}
    />
  ),
}));

import { ConditionalValueEditor } from '../../../components/page-settings/ConditionalValueEditor';
import { parseExpressionValue, type ValueNode } from '../../../spec/expressionValueTree';
import type { BindingCandidate } from '../../../spec/bindingCandidates';

const t = (k: string, p?: Record<string, string | number>) =>
  p ? `${k}:${JSON.stringify(p)}` : k;

// 조건 입력칸 데이터 검색 자동완성 검증용 scalar 후보(2건).
const CANDS: BindingCandidate[] = [
  { expression: '{{form_meta.data.board.name}}', source: 'data_source', sourceId: 'form_meta', path: 'data.board.name', shape: 'scalar', preview: '공지' },
  { expression: '{{post.type}}', source: 'data_source', sourceId: 'post', path: 'type', shape: 'scalar', preview: 'comment' },
];

beforeEach(() => cleanup());

/** 식에서 트리를 만들어 렌더 헬퍼 */
function renderExpr(expr: string, candidates?: BindingCandidate[]) {
  const parsed = parseExpressionValue(expr);
  const onChange = vi.fn();
  render(<ConditionalValueEditor node={parsed.node} onChange={onChange} t={t} candidates={candidates} />);
  return { onChange, parsed };
}

describe('ConditionalValueEditor', () => {
  it('① conditional 트리 — 조건 빌더 + 참/거짓 분기 리프', () => {
    renderExpr("{{route.id ? '$t:edit' : '$t:create'}}");
    expect(screen.getByTestId('g7le-value-tree-conditional')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-value-tree-cond')).toBeInTheDocument();
    // 존재여부(truthy) 조건 — 비교값 입력칸 부재.
    expect(screen.getByTestId('g7le-value-tree-cond-left')).toHaveValue('route.id');
    expect(screen.queryByTestId('g7le-value-tree-cond-right')).toBeNull();
    // 참/거짓 분기 리프 — 이제 SegmentedValueEditor(모든 분기에서 조각 추가 가능).
    // 분기 값($t:edit)은 SegmentedValueEditor 의 1조각(I18nTextField mock)으로 분해된다.
    expect(screen.getByTestId('g7le-value-tree-then-leaf-field-0-mock')).toHaveValue('$t:edit');
    expect(screen.getByTestId('g7le-value-tree-else-leaf-field-0-mock')).toHaveValue('$t:create');
    // 분기 안에서도 조각 추가 버튼이 노출된다(모든 조합 정의 가능).
    expect(screen.getByTestId('g7le-value-tree-then-leaf-add-expression')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-value-tree-then-leaf-add-fallback')).toBeInTheDocument();
  });

  it('② 조건 left 편집 → blur 커밋 시 직렬화 새 식 onChange', () => {
    const { onChange } = renderExpr("{{route.id ? '$t:edit' : '$t:create'}}");
    fireEvent.change(screen.getByTestId('g7le-value-tree-cond-left'), {
      target: { value: 'route.mode' },
    });
    fireEvent.blur(screen.getByTestId('g7le-value-tree-cond-left'));
    expect(onChange).toHaveBeenCalled();
    const next = onChange.mock.calls.at(-1)![0] as ValueNode;
    // 새 트리를 재직렬화하면 조건이 바뀐 식이어야.
    const reparse = parseExpressionValue("{{route.mode ? '$t:edit' : '$t:create'}}");
    expect(JSON.stringify(next)).toBe(JSON.stringify(reparse.node));
  });

  // B5 회귀 — 조건 left/right 는 **로컬 버퍼링 + blur 커밋**. 타이핑 중에는
  // 상위 onChange 를 호출하지 않는다(매 글자 직렬화→재파싱→리마운트로 불완전 경로 `route.` 가 잠기고
  // 커서가 끊기던 결함). 입력칸은 타이핑 값을 그대로 보여주되 onChange 는 blur 까지 미발화.
  it('② 조건 left 타이핑 중에는 onChange 미발화(blur 전까지 — B5 잠금 회귀 가드)', () => {
    const { onChange } = renderExpr("{{route.id ? '$t:edit' : '$t:create'}}");
    const left = screen.getByTestId('g7le-value-tree-cond-left');
    // 한 글자씩 타이핑(불완전 경로 포함) — onChange 안 함.
    fireEvent.change(left, { target: { value: 'route.' } });
    expect(onChange).not.toHaveBeenCalled();
    // 입력칸은 타이핑 값(불완전이라도) 그대로 표시(리마운트로 끊기지 않음).
    expect(left).toHaveValue('route.');
    fireEvent.change(left, { target: { value: 'route.mode' } });
    expect(onChange).not.toHaveBeenCalled();
    expect(left).toHaveValue('route.mode');
    // blur 에서 1회만 커밋.
    fireEvent.blur(left);
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it('② 조건 left Enter 키 → 커밋(blur 와 동일)', () => {
    const { onChange } = renderExpr("{{route.id ? '$t:edit' : '$t:create'}}");
    const left = screen.getByTestId('g7le-value-tree-cond-left');
    fireEvent.change(left, { target: { value: 'route.mode' } });
    fireEvent.keyDown(left, { key: 'Enter' });
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it('② 비교 조건(===) → left/op/right 입력칸 + value 표시', () => {
    renderExpr("{{type === 'comment' ? '$t:a' : '$t:b'}}");
    expect(screen.getByTestId('g7le-value-tree-cond-left')).toHaveValue('type');
    expect(screen.getByTestId('g7le-value-tree-cond-op')).toHaveValue('===');
    expect(screen.getByTestId('g7le-value-tree-cond-right')).toHaveValue("'comment'");
  });

  it('③ 분기 리프 편집(SegmentedValueEditor 조각) → onChange(분기 값 변경)', () => {
    const { onChange } = renderExpr("{{route.id ? '$t:edit' : '$t:create'}}");
    // then 분기 SegmentedValueEditor 의 1조각 I18nTextField(mock) 편집.
    fireEvent.change(screen.getByTestId('g7le-value-tree-then-leaf-field-0-mock'), {
      target: { value: '$t:modify' },
    });
    expect(onChange).toHaveBeenCalled();
    const next = onChange.mock.calls.at(-1)![0] as ValueNode;
    if (next.kind === 'conditional') {
      // SegmentedValueEditor 가 조각을 재결합한 leaf 값을 흘린다($t:modify 1조각).
      expect(next.then).toEqual({ kind: 'leaf', text: '$t:modify' });
    } else {
      throw new Error('expected conditional');
    }
  });

  it('④ fallback 노드 — 기본값/비었을 때 분기(각 분기 = SegmentedValueEditor 조각)', () => {
    renderExpr("{{product.data?.name ?? '$t:shop.untitled'}}");
    expect(screen.getByTestId('g7le-value-tree-fallback')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-value-tree-primary-leaf-field-0-mock')).toHaveValue('{{product.data?.name}}');
    expect(screen.getByTestId('g7le-value-tree-fallbackb-leaf-field-0-mock')).toHaveValue('$t:shop.untitled');
  });

  it('④ concat 노드 — 순서대로 조각(각 조각 = SegmentedValueEditor)', () => {
    renderExpr("{{'[' + coupon.name + ']'}}");
    expect(screen.getByTestId('g7le-value-tree-concat')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-value-tree-part-0-leaf-field-0-mock')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-value-tree-part-1-leaf-field-0-mock')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-value-tree-part-2-leaf-field-0-mock')).toBeInTheDocument();
  });

  // 이어붙이기 조각은 "손잡이로 순서를 바꾸세요" 안내가 있는데 실제
  // 손잡이(드래그 핸들)·재배치가 없어 순서를 못 바꿨다. 각 조각에 드래그 핸들 + 삭제, 조각 추가 버튼이
  // 있어야 한다(SegmentedValueEditor 와 동일 CRUD).
  it('④ [회귀] concat 조각 — 드래그 손잡이 + 삭제 + 추가 버튼 제공', () => {
    renderExpr("{{'/api/' + route.id}}");
    // 각 조각에 드래그 손잡이.
    expect(screen.getByTestId('g7le-value-tree-part-handle-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-value-tree-part-handle-1')).toBeInTheDocument();
    // 각 조각 삭제 버튼.
    expect(screen.getByTestId('g7le-value-tree-part-remove-0')).toBeInTheDocument();
    // 조각 추가 행(고정 글자/데이터 등).
    expect(screen.getByTestId('g7le-value-tree-part-add-text')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-value-tree-part-add-data')).toBeInTheDocument();
  });

  it('④ [회귀] concat 조각 순서 변경 → 재배치된 식 onChange', () => {
    const { onChange } = renderExpr("{{'/api/' + route.id}}");
    // 0↔1 순서 변경 — 조각0 핸들 dragStart → 조각1 카드 하단 절반 dragOver(clientY 양수) → drop.
    fireEvent.dragStart(screen.getByTestId('g7le-value-tree-part-handle-0'));
    fireEvent.dragOver(screen.getByTestId('g7le-value-tree-part-card-1'), { clientY: 1 }); // 하단 → index 2.
    fireEvent.drop(screen.getByTestId('g7le-value-tree-part-card-1'));
    fireEvent.dragEnd(screen.getByTestId('g7le-value-tree-part-handle-0'));
    const last = onChange.mock.calls.at(-1)?.[0] as ValueNode | undefined;
    expect(last).toBeTruthy();
    // 순서가 바뀌어 route.id 가 먼저, '/api/' 가 뒤로.
    expect(last?.kind).toBe('concat');
    if (last?.kind === 'concat') {
      expect(last.parts[0]).toEqual({ kind: 'leaf', text: '{{route.id}}' });
      expect(last.parts[1]).toEqual({ kind: 'leaf', text: '/api/' });
    }
  });

  it('④ [회귀] concat 조각 삭제 → 그 조각 제거된 식 onChange', () => {
    const { onChange } = renderExpr("{{'/api/' + route.id}}");
    fireEvent.click(screen.getByTestId('g7le-value-tree-part-remove-1'));
    const last = onChange.mock.calls.at(-1)?.[0] as ValueNode | undefined;
    expect(last).toBeTruthy();
    // route.id 조각 삭제 → '/api/' 단일 리프로 환원(concat 1개 → leaf).
    expect(last).toEqual({ kind: 'leaf', text: '/api/' });
  });

  it('⑤ 복잡 조건 → readonly 코드(입력칸 부재)', () => {
    // 논리 연산 조건은 raw — 조건 빌더 대신 코드 표시.
    const node: ValueNode = {
      kind: 'conditional',
      condition: { kind: 'raw', source: 'a && b' },
      then: { kind: 'leaf', text: '$t:x' },
      else: { kind: 'leaf', text: '$t:y' },
    };
    render(<ConditionalValueEditor node={node} onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-value-tree-cond-raw')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-value-tree-cond-left')).toBeNull();
  });

  it('⑤ raw 노드 → readonly 코드', () => {
    const node: ValueNode = { kind: 'raw', source: 'items.reduce((a,b)=>a+b,0)' };
    render(<ConditionalValueEditor node={node} onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-value-tree-raw')).toBeInTheDocument();
    expect(screen.getByText('items.reduce((a,b)=>a+b,0)')).toBeInTheDocument();
  });

  it('⑥ [원본 식 보기] 토글 → 직렬화 식 표시', () => {
    renderExpr("{{route.id ? '$t:edit' : '$t:create'}}");
    expect(screen.queryByTestId('g7le-value-tree-source-code')).toBeNull();
    fireEvent.click(screen.getByTestId('g7le-value-tree-source-toggle'));
    const code = screen.getByTestId('g7le-value-tree-source-code');
    expect(code.textContent).toContain("route.id ?");
    expect(code.textContent).toContain('$t:edit');
  });

  // "원본 식 보기는 조각 편집기당 하나"(2026-06-13) — 세그먼트 조각으로 쓰이면 토글 미표시.
  it('⑥ showSourceToggle=false → [원본 식 보기] 토글 미렌더(세그먼트 조각)', () => {
    const parsed = parseExpressionValue("{{route.id ? '$t:edit' : '$t:create'}}");
    render(<ConditionalValueEditor node={parsed.node} onChange={vi.fn()} t={t} showSourceToggle={false} />);
    expect(screen.getByTestId('g7le-value-tree-conditional')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-value-tree-source-toggle')).toBeNull();
  });

  // 조건 기준값/비교값은 직접 입력 + 데이터 검색 둘 다. candidates 가 있으면
  // 입력칸 옆에 🔍 데이터 검색 피커(InlineBindingScalarPicker)가 붙고, 후보 선택 시 `{{}}` 래핑 없는
  // 순수 경로가 조건 식에 들어간다(조건은 보간 래핑 없는 경로/리터럴).
  it('⑦ 조건 기준값/비교값에 candidates 전달 → 🔍 데이터 검색 토글 노출', () => {
    renderExpr("{{type === 'comment' ? '$t:a' : '$t:b'}}", CANDS);
    // 기준값(left) 옆 데이터 검색 토글.
    expect(
      screen.getByTestId('g7le-inline-binding-search-toggle-g7le-value-tree-cond-left-data'),
    ).toBeInTheDocument();
    // 비교값(right) 옆 데이터 검색 토글(=== 라 right 입력칸 존재).
    expect(
      screen.getByTestId('g7le-inline-binding-search-toggle-g7le-value-tree-cond-right-data'),
    ).toBeInTheDocument();
  });

  it('⑦ 기준값 데이터 후보 선택 → 순수 경로로 조건 left 갱신(`{{}}` 래핑 없음)', () => {
    const { onChange } = renderExpr("{{type === 'comment' ? '$t:a' : '$t:b'}}", CANDS);
    // 기준값 데이터 검색 토글 → 펼침 → 후보 선택.
    fireEvent.click(screen.getByTestId('g7le-inline-binding-search-toggle-g7le-value-tree-cond-left-data'));
    fireEvent.click(screen.getByTestId('g7le-inline-binding-candidate-{{form_meta.data.board.name}}'));
    expect(onChange).toHaveBeenCalled();
    const next = onChange.mock.calls.at(-1)![0] as ValueNode;
    if (next.kind !== 'conditional' || next.condition.kind !== 'simple') throw new Error('expected simple conditional');
    // 순수 경로(옵셔널 체이닝 포함, `{{}}`/폴백 없음).
    expect(next.condition.left).toBe('form_meta?.data?.board?.name');
    expect(next.condition.left).not.toContain('{{');
    expect(next.condition.left).not.toContain('??');
  });

  it('⑦ truthy/falsy 조건은 비교값 없음 → 비교값 데이터 검색 토글도 없음', () => {
    renderExpr("{{route.id ? '$t:a' : '$t:b'}}", CANDS);
    // 기준값 데이터 검색은 있고.
    expect(
      screen.getByTestId('g7le-inline-binding-search-toggle-g7le-value-tree-cond-left-data'),
    ).toBeInTheDocument();
    // 비교값 입력칸 자체가 없으므로 그 데이터 검색 토글도 없다.
    expect(screen.queryByTestId('g7le-value-tree-cond-right')).toBeNull();
    expect(
      screen.queryByTestId('g7le-inline-binding-search-toggle-g7le-value-tree-cond-right-data'),
    ).toBeNull();
  });
});
