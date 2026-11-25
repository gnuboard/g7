/**
 * @file auth-register-after-create-challenge.test.ts
 * @description 가입 후 본인인증 — 가입 직후 코드 입력 화면으로 (7.2.1)
 *
 * 운영자가 「가입 후 본인인증」 정책을 켜면 가입 응답이 발급된 인증 건(`identity_challenge`)을 돌려준다. 가입 폼은 그때
 * 로그인 화면 대신 코드 입력 화면(`/identity/challenge`)으로 가고, 확인을 마치면 로그인 화면으로 돌아온다. 그 화면의
 * [코드 다시 받기] 가 가입 이메일을 싣도록 이동 전에 대상을 세션 보관함에 남긴다. 인증 건이 없으면 종전처럼 로그인 화면이다.
 */

import { describe, it, expect } from 'vitest';
import registerForm from '../../layouts/partials/auth/_register_form.json';
import challengePage from '../../layouts/auth/identity_challenge.json';

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

const evaluate = (expression: string, scope: Record<string, any>): any => {
  const body = String(expression).replace(/^\{\{/, '').replace(/\}\}$/, '');
  const names = Object.keys(scope);
  return new Function(...names, `return (${body});`)(...names.map((n) => scope[n]));
};

const registerCall = collect(registerForm, (n) => n.handler === 'apiCall' && String(n.target ?? n.params?.target ?? '').includes('/api/auth/register'))[0];
const successActions: any[] = registerCall?.onSuccess ?? registerCall?.actions?.onSuccess ?? [];

describe('가입 직후 본인인증 화면', () => {
  // @scenario case=register_after_create_challenge
  // @effects register_opens_code_screen_when_challenge_returned
  it('인증 건이 오면 대상을 보관하고 코드 입력 화면으로, 없으면 로그인 화면으로 간다', () => {
    expect(registerCall, '가입 요청 액션이 없습니다').toBeDefined();
    const flat = collect(successActions, (n) => typeof n?.handler === 'string');
    const navs = flat.filter((n) => n.handler === 'navigate');
    const toChallenge = navs.find((n) => n.params?.path === '/identity/challenge');
    const toLogin = navs.find((n) => n.params?.path === '/login');
    const stash = flat.find((n) => n.handler === 'sirsoft-basic.stashIdentityTarget');
    expect(toChallenge && toLogin && stash).toBeTruthy();

    const pending = { response: { data: { identity_challenge: { id: 'c1', render_hint: 'text_code', expires_at: '2026-11-25T06:00:00+09:00', max_attempts: 5 } } }, _local: { registerForm: { email: 'a@b.c' } } };
    const active = { response: { data: { identity_challenge: null } }, _local: { registerForm: { email: 'a@b.c' } } };
    expect(evaluate(toChallenge.if, pending)).toBe(true);
    expect(evaluate(toChallenge.if, active)).toBe(false);
    expect(evaluate(toLogin.if, pending)).toBe(false);
    expect(evaluate(toLogin.if, active)).toBe(true);
    expect(evaluate(stash.if, pending)).toBe(true);

    const query = toChallenge.params?.query ?? {};
    expect(String(query.challenge_id)).toContain('identity_challenge');
    expect(query.purpose).toBe('signup');
    expect(query.return_url).toBe('/login');
    expect(JSON.stringify(stash.params)).toContain('_local.registerForm');
  });
});

describe('[case:components-form-standalone-input-not-bound-to-state] 전체화면 코드 입력 (7.2.0 부터의 결함)', () => {
  // @scenario case=challenge_page_code_input
  // @effects challenge_page_code_enables_confirm
  it('코드 칸 입력이 _local.code 에 담기고 값이 그 상태로 묶여 [확인] 이 켜진다', () => {
    const input = collect(challengePage, (n) => n.name === 'Input' && n.props?.name === 'code')[0];
    expect(input, '코드 칸이 없습니다').toBeDefined();
    const setters = collect(input.actions ?? [], (a) => a.handler === 'setState' && a.params?.target === 'local' && 'code' in (a.params ?? {}));
    expect(setters.length, '코드 입력이 _local.code 에 담기지 않습니다').toBeGreaterThan(0);
    expect(String(setters[0].params.code)).toContain('$event.target.value');
    expect(String(input.props?.value ?? '')).toContain('_local.code');

    const button = collect(challengePage, (n) => n.name === 'Button' && String(n.props?.disabled ?? '').includes('_local.code'))[0];
    expect(button).toBeDefined();
    const disabledWith = (code: string) => evaluate(button.props.disabled, { _local: { code, remainingSeconds: 600, attempts: 0, maxAttempts: 5 } });
    expect(disabledWith('')).toBe(true);
    expect(disabledWith('123456')).toBe(false);
  });
});
