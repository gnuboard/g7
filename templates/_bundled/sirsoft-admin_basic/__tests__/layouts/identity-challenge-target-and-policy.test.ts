import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { resolve, dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * 본인인증 시작 요청이 대상·정책을 잃지 않는다 (#699 브라우저 실측 중 발견)
 *
 * 1) 비밀번호 찾기·재설정 화면은 비로그인 화면이라 로그인 세션에서 인증 대상을 얻을 수 없다. 이 화면의 API 호출이
 *    `identity_target` 을 선언하지 않아, 비밀번호 재설정 정책을 켜면 428 뒤 challenge 요청이 대상 없이 나가
 *    422 로 거절되고 인증 창도 메일도 없이 멈췄다.
 * 2) 인증 창의 [코드 재전송] 은 challenge 를 다시 여는데, 정책 키를 싣지 않으면 재전송 메일만 정책별 문구가
 *    아니라 목적 공통 문구로 나간다.
 *
 * 화면을 열거하지 않고 레이아웃 디렉터리를 훑어 모집단을 도출한다 — 화면이 늘어날 때 조용히 검사 밖에 남지 않게.
 */
const layoutsDir = resolve(dirname(fileURLToPath(import.meta.url)), '../../layouts');

/**
 * 레이아웃 디렉터리 아래 JSON 레이아웃 파일 경로를 모두 모읍니다.
 *
 * @param dir 시작 디렉터리
 * @return JSON 파일 절대 경로 목록
 */
function layoutFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const full = join(dir, name);
    return statSync(full).isDirectory() ? layoutFiles(full) : name.endsWith('.json') ? [full] : [];
  });
}

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

const files = layoutFiles(layoutsDir).map((f) => ({ path: relative(layoutsDir, f), json: JSON.parse(readFileSync(f, 'utf8')) }));

describe('본인인증 시작 요청 — 대상·정책 보존', () => {
  /**
   * @effects guest_password_flows_declare_identity_target
   */
  it('비밀번호 찾기·재설정 API 호출은 모두 인증 대상 이메일을 선언한다', () => {
    // @scenario surface=guest_password_flow_layout
    const calls = files.flatMap(({ path, json }) =>
      collect(json, (o) => o.handler === 'apiCall' && /\/api\/auth\/(forgot|reset)-password$/.test(String(o.target ?? ''))).map((c) => ({ path, c })),
    );

    expect(calls.length).toBeGreaterThanOrEqual(2);
    for (const { path, c } of calls) {
      expect(c.identity_target?.email, `${path} ${c.target}`).toMatch(/\{\{.*email.*\}\}/);
    }
  });

  /**
   * @effects challenge_resend_keeps_policy_key, resend_replaces_claim_secret
   */
  it('인증 창의 재전송(전역 인증 상태로 challenge 를 다시 여는 호출)은 정책 키를 싣고 새 건의 비밀값으로 갈아 끼운다', () => {
    // @scenario surface=challenge_resend_layout
    const resends = files.flatMap(({ path, json }) =>
      collect(json, (o) => o.handler === 'apiCall' && o.target === '/api/identity/challenges' && JSON.stringify(o.params?.body ?? '').includes('_global.identityChallenge')).map((c) => ({ path, c })),
    );

    expect(resends.length).toBeGreaterThanOrEqual(1);
    for (const { path, c } of resends) {
      expect(JSON.stringify(c.params.body), path).toContain('_global.identityChallenge?.policy_key');
      // 링크 방식이면 새 건의 일회용 비밀값을 처리기에 넘겨야 원래 화면이 새 건의 확인 결과를 받아 간다.
      // 비밀값은 전역 상태에 쓰지 않는다 — 개발자 도구로 누구나 볼 수 있다(docs/frontend/security.md).
      const success = JSON.stringify(c.onSuccess ?? '');
      expect(success, path).toMatch(/rememberIdentityClaim/);
      expect(success, path).toContain('response.data.claim_secret');
      expect(success, path).not.toContain('identityChallenge.claim_secret');
    }
  });
});
