/**
 * @file mypageTotpCard.test.ts
 * @description 마이페이지 내 정보 보안 카드에 끼우는 2단계 인증 칸 (1.1.1, #952)
 *
 * - 기본 템플릿 보안 카드(`profile_security_card`) 끝에 끼운다 — 템플릿을 고치지 않는다
 * - 내 등록 상태를 받고, 실패해도 미등록으로 그린다
 * - 비밀번호가 없는 미등록 회원은 등록 대신 비밀번호 설정 안내·링크만 본다
 * - 등록·해제 흐름은 관리자 「내 보안」 화면과 같은 API 를 쓴다
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const here = path.dirname(fileURLToPath(import.meta.url));
const card: any = JSON.parse(fs.readFileSync(path.resolve(here, '../../../extensions/mypage_totp_card.json'), 'utf-8'));

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

const refOf = (n: any) => String(n?.props?.['data-ref'] ?? n?.id ?? '');

function evaluate(expression: string, scope: Record<string, any>): any {
  const body = String(expression).replace(/^\{\{/, '').replace(/\}\}$/, '');
  const names = Object.keys(scope);
  return new Function(...names, `return (${body});`)(...names.map((n) => scope[n]));
}

describe('마이페이지 2단계 인증 칸 (#952)', () => {
  // @scenario case=mypage_totp_card_injection
  // @effects totp_card_injected_into_security_card
  it('내 정보 화면의 보안 카드 끝에 끼우고, 내 등록 상태를 받는다(실패하면 미등록)', () => {
    expect(card.target_layout).toBe('mypage/profile');
    expect(card.injections[0].target_id).toBe('profile_security_card');
    expect(card.injections[0].position).toBe('append_child');
    const source = card.data_sources.find((s: any) => s.id === 'totpEnrollment');
    expect(source.endpoint).toBe('/api/plugins/sirsoft-totp/enrollment');
    expect(source.fallback.data.enrolled).toBe(false);
  });

  // @scenario case=mypage_totp_card_no_password
  // @effects totp_card_requires_password
  it('비밀번호가 없는 미등록 회원은 안내와 비밀번호 설정 링크만 보고, 등록한 회원·비밀번호가 있는 회원은 본문을 본다', () => {
    const hint = collect(card, (n) => refOf(n) === 'mypage_totp_no_password')[0];
    const body = collect(card, (n) => refOf(n) === 'mypage_totp_body')[0];
    const ctx = (hasPassword: boolean | undefined, enrolled: boolean) => ({ user: { data: { has_password: hasPassword } }, totpEnrollment: { data: { enrolled } } });

    expect(evaluate(hint.if, ctx(false, false))).toBe(true);
    expect(evaluate(body.if, ctx(false, false))).toBe(false);
    expect(evaluate(hint.if, ctx(true, false))).toBe(false);
    expect(evaluate(body.if, ctx(true, false))).toBe(true);
    // 이미 등록했으면 비밀번호가 없어도 해제·재발급을 할 수 있어야 한다
    expect(evaluate(body.if, ctx(false, true))).toBe(true);
    expect(JSON.stringify(hint)).toContain('/mypage/change-password');
  });

  // @scenario case=mypage_totp_card_same_api
  // @effects totp_card_uses_enrollment_api
  it('등록 흐름은 플러그인 등록 API 를 쓴다', () => {
    const calls = collect(card, (n) => n.handler === 'apiCall').map((n) => String(n.target));
    expect(calls).toEqual(expect.arrayContaining([
      '/api/plugins/sirsoft-totp/enrollment',
      '/api/plugins/sirsoft-totp/enrollment/confirm',
      '/api/plugins/sirsoft-totp/backup-codes/regenerate',
    ]));
    // 호스트 화면과 다른 주입 조각의 화면 상태와 겹치지 않게 totp 접두 키만 쓴다
    const text = JSON.stringify(card);
    for (const key of ['code', 'error', 'busy', 'secret', 'backupCodes', 'currentPassword']) {
      expect(text).not.toMatch(new RegExp(`_local\\.${key}\\b`));
    }
    expect(text).toContain('_local.totpBackupCodes');
  });
});
