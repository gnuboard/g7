// e2e:allow 엔진 식 방언 단위 잠금 — 화면 검증은 재실측(레이아웃 주입 `$t:attachment.attached_count|count={{1}}|max={{3}}`)이 한다.
/**
 * 리터럴만 있는 바인딩 식 `{{1}}`·`{{'abc'}}`·`{{true}}`·`{{null}}` (7.1.1 출시 전 실측)
 *
 * 출시 전 실측에서 `$t:키|count={{1}}|max={{3}}` 가 치환되지 않는다고 관찰했다. 이전 HEAD 와 현재를 같은 시험으로
 * 재면 결과가 **같다**(prop·text 「{{count}}/{{max}}개」, 반복 렌더 「/개」) — 회귀가 아니라 종전부터, 문자열 보간과
 * `$t:` 인자가 리터럴만 있는 식을 경로(`context['1']`)로 찾아 빈 글자를 내던 결함이다. 봇(SEO) 평가기는 `1/3개` 를 낸다.
 * 식 모양 판정(BindingShape)이 숫자·따옴표 문자열·`true/false/null/undefined` 를 값으로 평가하게 해 두 렌더러를 맞춘다.
 *
 * @effects literal_only_binding_evaluates_to_its_value, non_literal_paths_unchanged
 */
// @scenario render_path=prop, value_chars=plain
// @scenario render_path=iteration_leading, value_chars=plain
// @scenario render_path=engine_direct, value_chars=plain
import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, type TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { renderItemChildren } from '../helpers/RenderHelpers';
import { isLiteralExpression } from '../BindingShape';

const ctx: TranslationContext = { templateId: 't', locale: 'ko' };
const Box: React.FC<any> = (p) => <div data-testid="b" aria-label={p['aria-label']}>{p.children}</div>;

// [템플릿, 기대(보간·번역 결과)] — 봇(SEO) 평가기 ExpressionEvaluator 와 같은 값
const CASES: Array<[string, string]> = [
  ['$t:a|count={{1}}|max={{3}}', '1/3개'],
  ['$t:s|name={{0}}', '0 선택'],
  ["$t:s|name={{'abc'}}", 'abc 선택'],
  ['$t:s|name={{"x y"}}', 'x y 선택'],
  ['$t:s|name={{true}}', 'true 선택'],
  ['$t:s|name={{-1.5}}', '-1.5 선택'],
  ['$t:s|name={{1+1}}', '2 선택'],
  ['$t:a|count={{1}}|max={{v}}', '1/V개'],
  ['X $t:s|name={{1}}', 'X 1 선택'],
  ['$t:s|name={{v}}', 'V 선택'],
];

describe('리터럴만 있는 바인딩 식', () => {
  let te: TranslationEngine;

  beforeEach(() => {
    const reg = ComponentRegistry.getInstance();
    (reg as any).registry = { Box: { component: Box, metadata: { name: 'Box', type: 'basic' } } };
    te = new TranslationEngine();
    (te as any).translations.set('t:ko', { a: '{{count}}/{{max}}개', s: '{{name}} 선택' });
  });

  // @scenario render_path=prop, value_chars=plain
  // @effects literal_only_binding_evaluates_to_its_value
  it.each(CASES)('prop·text 보간: %s → %s', (tpl, expected) => {
    render(
      <DynamicRenderer
        componentDef={{ id: 'x', type: 'basic', name: 'Box', props: { 'aria-label': tpl }, text: tpl } as any}
        dataContext={{ v: 'V' }}
        translationContext={ctx}
        registry={ComponentRegistry.getInstance()}
        bindingEngine={new DataBindingEngine()}
        translationEngine={te}
        actionDispatcher={new ActionDispatcher({})}
      />,
    );
    const el = screen.getByTestId('b');
    expect(el.getAttribute('aria-label')).toBe(expected);
    expect(el.textContent).toBe(expected);
  });

  // @scenario render_path=iteration_leading, value_chars=plain
  // @effects literal_only_binding_evaluates_to_its_value
  it.each(CASES)('반복 렌더: %s → %s', (tpl, expected) => {
    const nodes = renderItemChildren(
      [{ type: 'basic', name: 'Box', props: { 'aria-label': tpl } } as any],
      { v: 'V' },
      { Box },
      'k',
      { bindingEngine: new DataBindingEngine(), translationEngine: te, translationContext: ctx },
    ) as any[];
    expect(nodes[0].props['aria-label']).toBe(expected);
  });

  // @scenario render_path=engine_direct, value_chars=plain
  // @effects literal_only_binding_evaluates_to_its_value
  it('번역 없는 보간과 null·undefined 리터럴', () => {
    const b = new DataBindingEngine();
    expect(b.resolveBindings("{{1}}/{{'abc'}}/{{true}}/{{false}}", {}, { skipCache: true })).toBe('1/abc/true/false');
    expect(b.resolveBindings('[{{null}}][{{undefined}}]', {}, { skipCache: true })).toBe('[][]');
  });

  // @scenario render_path=engine_direct, value_chars=plain
  // @effects non_literal_paths_unchanged
  it('리터럴 판정은 좁다 — 경로·식·따옴표 키 인덱싱은 리터럴이 아니다', () => {
    for (const lit of ['1', '-2', '1.5', "'a'", '"a b"', 'true', 'false', 'null', 'undefined', ' 3 ']) {
      expect(isLiteralExpression(lit)).toBe(true);
    }
    for (const notLit of ['007', 'v', 'user.name', "'a' + b", "query['x']", "'a'.length", '1.2.3', "'a''b'", '"a" == "b"', '']) {
      expect(isLiteralExpression(notLit)).toBe(false);
    }
  });
});
