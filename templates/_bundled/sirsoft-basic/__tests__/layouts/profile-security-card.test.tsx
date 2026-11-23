/**
 * @file profile-security-card.test.tsx
 * @description 내 정보 보안 카드 (#952 E) — 모든 기기 로그아웃 단추와 확인 창, 2단계 인증 플러그인이 끼워 넣을 앵커.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';

const layoutsDir = path.resolve(__dirname, '../../layouts');
const load = (relative: string): any => JSON.parse(fs.readFileSync(path.join(layoutsDir, relative), 'utf-8'));

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

const evaluate = (expression: string, context: Record<string, unknown>) =>
  dataBindingEngine.evaluateExpression(String(expression).replace(/^\{\{/, '').replace(/\}\}$/, ''), context as any);
const refOf = (n: any) => String(n?.props?.['data-ref'] ?? n?.id ?? '');

const view = load('partials/mypage/profile/_view.json');
const modal = load('partials/mypage/profile/_modal_logout_all_devices.json');

describe('내 정보 보안 카드 (#952)', () => {
  // @scenario case=mypage_profile_security_card
  // @effects profile_security_card_visible
  it('보안 카드는 탈퇴 회원이 아니면 보이고, 2단계 인증 플러그인이 끼울 앵커(profile_security_card)를 둔다', () => {
    const card = collect(view, (n) => refOf(n) === 'profile_security_card')[0];
    expect(card).toBeTruthy();
    expect(evaluate(card.if, { user: { data: { status: 'active' } } })).toBe(true);
    expect(evaluate(card.if, { user: { data: { status: 'withdrawn' } } })).toBe(false);
    expect(collect(card, (n) => refOf(n) === 'logout_all_devices_button')[0]).toBeTruthy();
  });

  // @scenario case=mypage_profile_logout_all
  // @effects logout_all_devices_logs_out
  it('확인 창은 모든 기기 로그아웃 API 를 부르고, 성공하면 창을 닫고 이 기기도 로그아웃한다', () => {
    const call = collect(modal, (n) => n.handler === 'apiCall')[0];
    expect(call.target).toBe('/api/user/auth/logout-all-devices');
    expect(call.params.method).toBe('POST');
    const success = (Array.isArray(call.onSuccess) ? call.onSuccess : [call.onSuccess]).map((a: any) => a.handler);
    expect(success).toEqual(expect.arrayContaining(['closeModal', 'toast', 'logout']));
    expect(success.indexOf('logout')).toBeGreaterThan(success.indexOf('closeModal'));
    // 실패해도 진행 표시를 푼다
    expect(JSON.stringify(call.onError)).toContain('logoutAllDevicesModal');
  });
});
