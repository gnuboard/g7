import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * 본인인증 확인 링크의 착지 화면 (#699)
 *
 * 메일 프로바이더는 링크 방식(비밀번호 재설정 목적) 메일에 `/identity/verify?challenge=…&token=…` 를 싣는데
 * 이 주소를 받는 화면이 없어 링크가 404 였고, 비밀번호 재설정 정책을 켜면 회원이 비밀번호를 바꿀 수 없었다.
 * 착지 화면은 질의값의 토큰으로 확인만 하고 결과를 알린다 — 원래 화면은 일회용 비밀값으로 결과를 받아 간다.
 */
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const routes = JSON.parse(readFileSync(resolve(root, 'routes.json'), 'utf8'));
const layout = JSON.parse(readFileSync(resolve(root, 'layouts/auth/identity_verify.json'), 'utf8'));

/**
 * JSON 트리에서 조건을 만족하는 객체를 모두 모읍니다.
 *
 * @param node 탐색 대상
 * @param pred 판정 함수
 * @param out 결과 누적
 * @return 일치한 객체 목록
 */
function collect(node: unknown, pred: (o: Record<string, any>) => boolean, out: Record<string, any>[] = []): Record<string, any>[] {
  if (Array.isArray(node)) node.forEach((n) => collect(n, pred, out));
  else if (node && typeof node === 'object') {
    if (pred(node as Record<string, any>)) out.push(node as Record<string, any>);
    Object.values(node).forEach((v) => collect(v, pred, out));
  }
  return out;
}

describe('본인인증 확인 링크 착지 화면', () => {
  /**
   * @effects verify_link_landing_route_exists
   */
  it('메일 링크 주소(/identity/verify)가 비로그인으로 열리는 착지 화면에 연결된다', () => {
    // @scenario surface=verify_link_landing
    const route = collect(routes, (o) => o.path === '/identity/verify')[0];

    expect(route?.layout).toBe('auth/identity_verify');
    expect(route?.auth_required).toBe(false);
  });

  /**
   * @effects verify_link_landing_verifies_with_query_token
   */
  it('질의값의 건 번호·토큰으로 확인 요청을 보내고 성공·실패를 화면 상태로 나눈다', () => {
    // @scenario surface=verify_link_landing
    const calls = collect(layout, (o) => o.handler === 'apiCall' && String(o.target).startsWith('/api/identity/challenges/'));

    expect(calls).toHaveLength(1);
    expect(calls[0].target).toContain('query.challenge');
    expect(calls[0].target).toMatch(/\/verify$/);
    expect(JSON.stringify(calls[0].params?.body)).toContain('query.token');
    expect(JSON.stringify(calls[0].onSuccess)).toContain('verified');
    expect(JSON.stringify(calls[0].onError)).toContain('failed');
  });
});
