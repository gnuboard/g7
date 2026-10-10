/**
 * @file admin-login-redirect-fallback.test.ts
 * @description 관리자 로그인의 거부된 복귀 경로 → 관리자 첫 화면(`/admin`) (sirsoft-admin_basic)
 *
 * `?redirect=` 에 외부 주소·`javascript:`·`//host` 를 넣으면 엔진 이동 sink 가 막아 사이트 첫 화면 `/`
 * 로 보냈다 — 보안은 지켰지만 관리자가 사용자 홈에 내렸다(7.1.0 관측 R-16·N O-N1). 2026-10-07 결정:
 * 관리자 로그인에서는 `/admin` 으로. 엔진 공용 sink 는 바꾸지 않고, 레이아웃이 엔진보다 **좁게** 거른
 * 대체 경로를 넘긴다(로그인이 끝나는 네 갈래의 `navigate` 가 같은 식).
 *
 * 두 가지를 잠근다.
 * 1. 거부되는 값은 모두 `/admin` 이고, 정상 사이트 안 경로는 그대로다.
 * 2. 레이아웃이 통과시키는 값은 엔진 판정(`sanitizeNavPath`)도 반드시 통과한다 — 그렇지 않으면 그 값은
 *    엔진에서 다시 `/` 로 떨어져 이 결정이 조용히 깨진다.
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { sanitizeNavPath } from '@core/support/navPolicy';

import adminLogin from '../../layouts/admin_login.json';

type Json = Record<string, any>;



/** 트리에서 navigate 액션을 모두 모은다. */
function collectNavigates(node: unknown, acc: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((n) => collectNavigates(n, acc));
    return acc;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (obj.handler === 'navigate') acc.push(obj);
    Object.values(obj).forEach((v) => collectNavigates(v, acc));
  }
  return acc;
}

/** 복귀 경로를 쓰는 navigate 의 path 식(네 갈래). */
const redirectPaths: string[] = collectNavigates((adminLogin as Json).components)
  .map((n) => String(n.params?.path ?? ''))
  .filter((p) => p.includes('redirect'));

const redirectExpr: string = redirectPaths[0] ?? '';

/** 레이아웃 식을 주어진 `query.redirect` 로 평가한다. */
function resolveRedirect(redirect: unknown): unknown {
  const query: Json = redirect === undefined ? {} : { redirect };

  return dataBindingEngine.evaluateExpression(redirectExpr.trim().replace(/^\{\{|\}\}$/g, ''), { query });
}

const REJECTED = [
  undefined,
  '',
  'https://example.com',
  'http://example.com/admin',
  '//evil.example/x',
  '/\\evil.example',
  '/\t/evil.example',
  'javascript:alert(1)',
  'admin/users',
  ' //evil.example',
];

const ACCEPTED: Array<[string, string]> = [
  ['/admin/users', '/admin/users'],
  ['/admin/users?page=2&sort=name', '/admin/users?page=2&sort=name'],
  ['  /admin/settings  ', '/admin/settings'],
  ['/', '/'],
];

describe('관리자 로그인 복귀 경로 대체값', () => {
  // @scenario redirect=rejected
  // @effects rejected_admin_login_redirect_lands_on_admin_home
  it('허용되지 않는 복귀 경로는 모두 /admin 이다', () => {
    expect(redirectExpr).not.toBe('');

    for (const value of REJECTED) {
      expect(resolveRedirect(value), String(value)).toBe('/admin');
    }
  });

  // @scenario redirect=same_origin
  // @effects same_origin_admin_login_redirect_is_kept
  it('사이트 안 경로는 그대로 돌아간다', () => {
    for (const [input, expected] of ACCEPTED) {
      expect(resolveRedirect(input), input).toBe(expected);
    }
  });

  // @scenario redirect=rejected
  // @effects layout_redirect_never_wider_than_engine_sink
  it('레이아웃이 통과시킨 값은 엔진 판정도 통과한다 (엔진보다 넓지 않다)', () => {
    for (const value of [...REJECTED, ...ACCEPTED.map(([input]) => input)]) {
      const resolved = resolveRedirect(value) as string;

      expect(sanitizeNavPath(resolved), String(value)).toBe(resolved);
    }
  });

  // @scenario redirect=same_origin
  // @effects all_login_success_paths_use_the_fallback
  it('로그인이 끝나는 네 갈래(자격증명·2단계·비밀번호 변경·변경 연기)가 모두 이 값으로 이동한다', () => {
    // 「비밀번호 찾기」 같은 고정 경로를 빼면, 복귀 경로를 쓰는 이동은 네 갈래이고 모두 같은 식이다
    // (한 곳만 고치면 그 갈래만 `/` 로 떨어진다).
    expect(redirectPaths.length).toBe(4);
    expect(new Set(redirectPaths).size).toBe(1);
    expect(redirectExpr).not.toContain("?? '/admin'");
  });
});
