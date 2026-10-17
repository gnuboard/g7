/**
 * @file admin-login-redirect-fallback.test.ts
 * @description 관리자 로그인의 거부된 복귀 경로 → 관리자 첫 화면(`/admin`) (sirsoft-admin_basic)
 *
 * `?redirect=` 에 외부 주소·`javascript:`·`//host` 를 넣으면 엔진 이동 sink 가 막아 사이트 첫 화면 `/`
 * 로 보냈다 — 보안은 지켰지만 관리자가 사용자 홈에 내렸다(7.1.0 관측 R-16·N O-N1). 2026-10-07 결정:
 * 관리자 로그인에서는 `/admin` 으로.
 *
 * #757 추가 11(결정 2026-10-15): 엔진이 navigate `fallbackPath` 를 받는다 — `path` 가 비었거나 거부되면
 * `fallbackPath` 로 간다. 레이아웃 식으로 주소를 직접 거르던 람다는 걷고 `path="{{query.redirect}}"
 * fallbackPath="/admin"` 로 단순화했다(우회 판정을 레이아웃마다 다시 짜지 않게 — 판정은 엔진 한 곳).
 *
 * 세 가지를 잠근다(레이아웃 식 + 엔진의 실제 판정 `resolveNavigatePathWithFallback` 으로 잰다).
 * 1. 거부되는 값은 모두 `/admin` 이고, 정상 사이트 안 경로는 그대로다.
 * 2. 대체 경로 `/admin` 자체가 엔진 판정을 통과한다 — 그렇지 않으면 이동하지 않아 로그인 화면에 머문다.
 * 3. 로그인이 끝나는 네 갈래가 모두 같은 path·fallbackPath 이고 레이아웃이 주소를 직접 거르지 않는다.
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { resolveNavigatePathWithFallback, sanitizeNavPath } from '@core/support/navPolicy';

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

/** 복귀 경로를 쓰는 navigate(네 갈래). */
const redirectNavigates: Json[] = collectNavigates((adminLogin as Json).components)
  .filter((n) => String(n.params?.path ?? '').includes('redirect'));
const redirectPaths: string[] = redirectNavigates.map((n) => String(n.params?.path ?? ''));

const redirectExpr: string = redirectPaths[0] ?? '';
const fallbackPath: unknown = redirectNavigates[0]?.params?.fallbackPath;

/** 레이아웃 식을 주어진 `query.redirect` 로 평가하고 엔진의 대체 경로 판정까지 거친 이동 주소. */
function resolveRedirect(redirect: unknown): unknown {
  const query: Json = redirect === undefined ? {} : { redirect };
  const path = dataBindingEngine.evaluateExpression(redirectExpr.trim().replace(/^\{\{|\}\}$/g, ''), { query });

  return resolveNavigatePathWithFallback(path, fallbackPath);
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
  it('대체 경로 /admin 자체가 엔진 판정을 통과한다 (거부돼 로그인 화면에 머물지 않는다)', () => {
    expect(fallbackPath).toBe('/admin');
    expect(sanitizeNavPath(fallbackPath)).toBe('/admin');

    for (const value of [...REJECTED, ...ACCEPTED.map(([input]) => input)]) {
      const resolved = resolveRedirect(value) as string;

      expect(sanitizeNavPath(resolved), String(value)).toBe(resolved);
    }
  });

  // @scenario redirect=same_origin
  // @effects all_login_success_paths_use_the_fallback
  it('로그인이 끝나는 네 갈래(자격증명·2단계·비밀번호 변경·변경 연기)가 모두 이 값으로 이동한다', () => {
    // 「비밀번호 찾기」 같은 고정 경로를 빼면, 복귀 경로를 쓰는 이동은 네 갈래이고 모두 같은 path·fallbackPath 다
    // (한 곳만 고치면 그 갈래만 로그인 화면에 머문다).
    expect(redirectPaths.length).toBe(4);
    expect(new Set(redirectPaths).size).toBe(1);
    expect(redirectNavigates.every((n) => n.params?.fallbackPath === '/admin')).toBe(true);
    // 거르기는 엔진 한 곳 — 레이아웃 식이 주소를 직접 거르지 않는다
    expect(redirectExpr).not.toContain('charCodeAt');
    expect(redirectExpr).not.toContain("?? '/admin'");
  });
});
