/**
 * @file authModalOverrides.ts
 * @description 소셜 가입 창, SSO 동의 창 오버라이드(extensions/sirsoft-social_login, extensions/sirsoft-sso) 테스트의 공용 도구
 *
 * 두 오버라이드는 플러그인 원본 조각을 통째로 대체하면서 회원 항목 구획만 더한다. 테스트 세 벌
 * (창별 항목 동작, 원본과의 대조, 공용 조각과의 구획 대조)이 같은 순회와 식 계산을 쓴다.
 */

import { expect } from 'vitest';
import { evaluateComputedDefinitions } from '@core/template-engine/helpers/ComputedEvaluator';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

export type Node = Record<string, any>;

/** 노드 트리를 깊이 우선으로 펼친다 (children 만 따라간다) */
export function collect(input: Node | Node[] | undefined, out: Node[] = []): Node[] {
  if (!input) return out;
  for (const node of Array.isArray(input) ? input : [input]) {
    out.push(node);
    collect(node.children, out);
  }
  return out;
}

/** 조각의 모달 안 노드까지 전부 펼친다 */
export function collectFragment(fragment: Node): Node[] {
  return collect(fragment.modals as Node[]);
}

/** id 로 노드 하나를 찾는다 (없으면 실패) */
export function byId(nodes: Node[], id: string): Node {
  const found = nodes.filter((node) => node.id === id);
  expect(found.map((node) => node.id)).toEqual([id]);
  return found[0];
}

/** 표현식({{ }})을 주어진 변수로 계산한다 (자바스크립트 전체 문법) */
export function evaluate(expression: string, scope: Record<string, unknown>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), 'return (' + body + ');')(...Object.values(scope));
}

/**
 * 표현식을 엔진의 평가기로 계산한다.
 *
 * 식 엔진은 자바스크립트 전체를 받지 않는다(함수 본문의 `var` 선언, `if` 문 등). `new Function` 으로만 재면 엔진이 못 푸는
 * 식도 통과하므로, 창이 실제로 기대는 식(요청 본문, 초기값 옮기기, 반복 원본)은 앱과 같은 평가기로도 푼다.
 * 풀지 못하면 실패다.
 */
export function engineEvaluate(expression: string, context: Record<string, unknown>): any {
  const failures: string[] = [];
  const values = evaluateComputedDefinitions({ value: expression }, context, new DataBindingEngine(), {
    previous: {},
    onError: 'undefined',
    failureLog: (name: string, error: unknown) => failures.push(`${name}: ${String(error)}`),
  } as any);
  expect(failures).toEqual([]);
  return values.value;
}

/** 두 평가기의 결과가 같은지 확인하고 그 값을 돌려준다 */
export function evaluateBoth(expression: string, scope: Record<string, unknown>): any {
  const expected = evaluate(expression, scope);
  expect(engineEvaluate(expression, scope)).toEqual(expected);
  return expected;
}

/** 객체에서 comment 키를 재귀로 걷어낸다 (주석은 구조 대조의 대상이 아니다) */
export function withoutComments<T>(value: T): T {
  if (Array.isArray(value)) {
    return value.map((item) => withoutComments(item)) as unknown as T;
  }
  if (value && typeof value === 'object') {
    const out: Record<string, unknown> = {};
    for (const [key, item] of Object.entries(value as Record<string, unknown>)) {
      if (key !== 'comment') out[key] = withoutComments(item);
    }
    return out as T;
  }
  return value;
}

/** 문자열 값 전부에 변환을 적용한 사본을 만든다 */
export function mapStrings<T>(value: T, transform: (text: string, key: string | null) => string, key: string | null = null): T {
  if (typeof value === 'string') {
    return transform(value, key) as unknown as T;
  }
  if (Array.isArray(value)) {
    return value.map((item) => mapStrings(item, transform, key)) as unknown as T;
  }
  if (value && typeof value === 'object') {
    const out: Record<string, unknown> = {};
    for (const [childKey, item] of Object.entries(value as Record<string, unknown>)) {
      out[childKey] = mapStrings(item, transform, childKey);
    }
    return out as T;
  }
  return value;
}

/** 가입 화면과 같은 항목 계약의 표본 — 이름(필수), 닉네임, 언어(선택 칸), 운영자 추가 항목, 약관 묶음 항목, 파일(가입에서 받지 않음) */
export function sampleFields(overrides: Record<string, Record<string, unknown>> = {}): Node[] {
  const base: Node[] = [
    { key: 'name', name: 'name', type: 'text', renderer: 'builtin', label: '이름', required: true, readonly: false, deferred: false, default: null, constraints: {}, ui: {}, parts: null },
    { key: 'nickname', name: 'nickname', type: 'text', renderer: 'builtin', label: '닉네임', required: false, readonly: false, deferred: false, default: null, constraints: {}, ui: {}, parts: null },
    { key: 'language', name: 'language', type: 'choice', renderer: 'builtin', label: '언어', required: false, readonly: false, deferred: false, default: 'ko', constraints: {}, ui: {}, parts: null },
    { key: 'job', name: 'custom_fields.job', type: 'text', renderer: 'builtin', label: '직업', required: true, readonly: false, deferred: false, default: null, constraints: {}, ui: {}, parts: null },
    { key: 'marketing', name: 'agree_marketing_consent', type: 'boolean', renderer: 'builtin', label: '마케팅 수신 동의', required: false, readonly: false, deferred: false, default: null, constraints: {}, ui: { group: 'agreements' }, parts: null },
    { key: 'photo', name: 'custom_fields.photo', type: 'image', renderer: 'file', label: '사진', required: false, readonly: false, deferred: true, default: null, constraints: {}, ui: {}, parts: null },
  ];
  return base.map((field) => ({ ...field, ...(overrides[field.key] ?? {}) }));
}
