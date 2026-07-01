/**
 * @file identity-challenge-fullpage.test.tsx
 * @description user 풀페이지 IDV 레이아웃의 max_attempts 정합성 회귀 차단 — 이슈 #275
 *
 * 검증 대상:
 * 1. state.maxAttempts 초기값은 0 (무제한 기본 — 환경설정값을 응답에서 받아야 함)
 * 2. query.max_attempts fallback 도 ?? 0 (이전 회귀: ?? 5 하드코딩)
 * 3. init_actions 가 challenge_id 가용 시 GET /api/identity/challenges/{id} 호출
 * 4. apiCall onSuccess 가 response.data.max_attempts 로 _local.maxAttempts 를 갱신
 * 5. 카운트다운 표시식 + disabled 식이 ?? 0 + max>0 가드 패턴 사용 (무제한 케이스 정합)
 */

import { describe, expect, it } from 'vitest';

import fullPage from '../../layouts/auth/identity_challenge.json';

type Node = {
  type?: string;
  name?: string;
  if?: string;
  props?: Record<string, any>;
  children?: Node[] | string;
  default?: Node[];
  slots?: Record<string, Node[]>;
};

function walk(input: Node | Node[] | undefined, visit: (node: Node) => void): void {
  if (!input) return;
  const nodes = Array.isArray(input) ? input : [input];
  for (const node of nodes) {
    visit(node);
    if (node.default) walk(node.default, visit);
    if (node.children && Array.isArray(node.children)) walk(node.children as Node[], visit);
    if (node.slots) {
      for (const key of Object.keys(node.slots)) walk(node.slots[key], visit);
    }
  }
}

describe('User 본인인증 풀페이지 (sirsoft-basic) — max_attempts 정합 회귀 차단', () => {
  const fp = fullPage as any;

  it('state.maxAttempts 초기값이 0 (무제한 기본)', () => {
    expect(fp.state?.maxAttempts).toBe(0);
  });

  it('init_actions 의 query.max_attempts fallback 이 ?? 0 으로 정합화', () => {
    const initActions = fp.init_actions ?? [];
    const setStateInit = initActions.find((a: any) => a.handler === 'setState' && a.params?.maxAttempts);
    expect(setStateInit).toBeDefined();
    // ?? 5 잔여 검출 시 회귀
    expect(setStateInit.params.maxAttempts).toContain('?? 0');
    expect(setStateInit.params.maxAttempts).not.toContain('?? 5');
  });

  it('init_actions 가 challenge_id 가용 시 GET /api/identity/challenges/{id} 호출 (서버 정합 fetch)', () => {
    const initActions = fp.init_actions ?? [];
    const apiCall = initActions.find((a: any) => a.handler === 'apiCall');
    expect(apiCall).toBeDefined();
    expect(apiCall.target).toContain('/api/identity/challenges/');
    expect(apiCall.target).toContain('query.challenge_id');
    expect(apiCall.params?.method).toBe('GET');
    // if 가드: challenge_id 가 있을 때만 호출 (URL 직접 진입 시점 일부 케이스만)
    expect(apiCall.if).toContain('query.challenge_id');
  });

  it('apiCall.onSuccess 가 response.data.max_attempts 로 _local.maxAttempts 를 갱신', () => {
    const initActions = fp.init_actions ?? [];
    const apiCall = initActions.find((a: any) => a.handler === 'apiCall');
    const onSuccess = apiCall.onSuccess ?? [];
    const setStateOnSuccess = onSuccess.find((a: any) => a.handler === 'setState');
    expect(setStateOnSuccess).toBeDefined();
    expect(setStateOnSuccess.params.maxAttempts).toContain('response.data.max_attempts');
  });

  it('카운트다운 텍스트 + 확인 버튼 disabled 가 maxAttempts ?? 5 하드코딩을 사용하지 않는다', () => {
    const offenders: string[] = [];
    walk(fp as unknown as Node, (n) => {
      const collect = (s: unknown) => {
        if (typeof s !== 'string') return;
        if (s.includes('maxAttempts ?? 5')) offenders.push(s);
      };
      collect((n as any).text);
      collect(n.props?.disabled);
    });
    expect(offenders).toEqual([]);
  });

  it('확인 버튼 disabled 가 maxAttempts=0 (무제한) 케이스 가드를 포함', () => {
    // 회귀: max_attempts=0 응답 시 무제한임에도 attempts >= 0 비교가 무조건 disabled 됨
    let hasUnlimitedGuard = false;
    walk(fp as unknown as Node, (n) => {
      const disabled = n.props?.disabled;
      if (typeof disabled !== 'string') return;
      if (!disabled.includes('maxAttempts')) return;
      if (disabled.includes('(_local.maxAttempts ?? 0) > 0')) {
        hasUnlimitedGuard = true;
      }
    });
    expect(hasUnlimitedGuard).toBe(true);
  });
});
