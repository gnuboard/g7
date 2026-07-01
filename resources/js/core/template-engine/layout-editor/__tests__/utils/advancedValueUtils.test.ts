/**
 * advancedValueUtils.test.ts — 고급 값(코드 편집기 작성) 판정
 */

import { describe, it, expect } from 'vitest';
import {
  collectAdvancedValues,
  hasAdvancedValues,
  containsComplexExpression,
} from '../../utils/advancedValueUtils';
import type { EditorNode } from '../../utils/layoutTreeUtils';

describe('containsComplexExpression', () => {
  it('바인딩/파이프/$switch/$get 을 복잡 표현식으로 본다', () => {
    expect(containsComplexExpression('{{user.name}}')).toBe(true);
    expect(containsComplexExpression('{{date | date}}')).toBe(true);
    expect(containsComplexExpression('$switch(...)')).toBe(true);
    expect(containsComplexExpression('$get(a)')).toBe(true);
  });
  it('평문/숫자/불리언은 복잡 표현식이 아니다', () => {
    expect(containsComplexExpression('hello')).toBe(false);
    expect(containsComplexExpression(42)).toBe(false);
    expect(containsComplexExpression(true)).toBe(false);
  });
  it('중첩 객체/배열을 재귀 검사한다', () => {
    expect(containsComplexExpression({ a: { b: ['x', '{{y}}'] } })).toBe(true);
    expect(containsComplexExpression({ a: { b: ['x', 'y'] } })).toBe(false);
  });
});

describe('collectAdvancedValues', () => {
  it('순수 개발자 속성(노드 직속/ props)을 수집한다', () => {
    const node: EditorNode = {
      name: 'Div',
      sortable: { source: 'x' },
      props: { isolatedScopeId: 's1', className: 'p-2' },
    };
    const result = collectAdvancedValues(node);
    expect(result.map((r) => r.key)).toEqual(
      expect.arrayContaining(['sortable', 'props.isolatedScopeId']),
    );
    expect(result.every((r) => r.kind === 'developer_prop' || r.kind === 'complex_expression')).toBe(true);
  });

  it('복잡 표현식을 담은 text/prop 을 수집한다 (style/className 제외)', () => {
    const node: EditorNode = {
      name: 'Span',
      text: '{{count | number}}',
      props: { title: '{{user.name}}', className: 'text-{{x}}', style: { color: '{{c}}' } },
    };
    const result = collectAdvancedValues(node);
    const keys = result.map((r) => r.key);
    expect(keys).toContain('text');
    expect(keys).toContain('props.title');
    // style/className 은 스타일 탭이 다루므로 고급값으로 분류하지 않는다
    expect(keys).not.toContain('props.style');
    expect(keys).not.toContain('props.className');
  });

  it('고급 값이 없으면 빈 배열 + hasAdvancedValues=false', () => {
    const node: EditorNode = { name: 'H1', text: '제목', props: { className: 'text-xl' } };
    expect(collectAdvancedValues(node)).toEqual([]);
    expect(hasAdvancedValues(node)).toBe(false);
  });

  it('고급 값이 있으면 hasAdvancedValues=true', () => {
    const node: EditorNode = { name: 'Div', props: { data_binding: { source: 'x' } } };
    expect(hasAdvancedValues(node)).toBe(true);
  });

  // 결함 C 회귀 — 사용자가 기성 다국어키를 인라인 편집해 데이터를 칩으로 연결하면 node.text 가
  // `$t:custom...|pN={{expr}}` 형태가 된다. expr 에 파이프 필터(`| date`)가 있어도 이는 편집기
  // [번역] 탭 칩이 직접 제어하는 정당한 데이터 연결이라 "고급(코드 편집)"이 아니다(고급으로 보면
  // 번역 탭이 "코드 편집기 고급 설정 포함"으로 차단됨). param 부착 키는 고급 제외.
  it('named-param 키 텍스트(| date 파이프 포함)는 고급값 아님 — 번역 탭 허용', () => {
    const node: EditorNode = {
      name: 'Span',
      text: '$t:custom.auth_register.38|p0={{privacyContent?.data?.published_at | date}}',
    };
    expect(hasAdvancedValues(node)).toBe(false);
    expect(collectAdvancedValues(node).find((e) => e.key === 'text')).toBeUndefined();
  });

  it('param 부착 아닌 순수 복합 보간 text 는 여전히 고급값', () => {
    const node: EditorNode = { name: 'Span', text: '합계 {{a ? b : c}}' };
    expect(hasAdvancedValues(node)).toBe(true);
  });
});
