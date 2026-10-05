/**
 * @file mypage-change-password.test.tsx
 * @description 마이페이지 비밀번호 변경 화면 회귀 테스트 (#736, sirsoft-basic)
 *
 * - 머리 아래에 마지막 변경·만료 예정(D-n)을 `/api/me` 로 보여 준다(만료가 적용되지 않으면 만료 예정은 없다).
 * - 422 는 토스트로 뭉개지 않고 칸 아래(`error` prop)에 싣는다 — 현재 비밀번호 오류와 새 비밀번호 규칙 위반은 고칠 칸이 다르다.
 *   칸 오류가 없는 실패만 토스트로 알린다.
 * - 새 비밀번호 칸 규칙은 보안 환경설정 값에 바인딩한다(8 하드코딩 없음).
 * - 성공하면 다른 기기 로그아웃 사실을 알리고 내 정보를 다시 받는다.
 *
 * @vitest-environment jsdom
 * @since engine-v1.77.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import changePassword from '../../layouts/mypage/change-password.json';

function flatten(node: any): any[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  return [node, ...children];
}

function flattenActions(actions: any): any[] {
  if (!actions) return [];
  return asArray<any>(actions).flatMap((a) => [a, ...flattenActions(a.actions), ...flattenActions(a.onSuccess), ...flattenActions(a.onError)]);
}

const layout = changePassword as any;
const nodes = flatten(layout.slots?.content ?? layout.components);
const inputs = nodes.filter((n) => n.name === 'PasswordInput');
const form = nodes.find((n) => n.name === 'Form');
const api = flattenActions(form?.actions).find((a) => a.handler === 'apiCall');

describe('마이페이지 비밀번호 변경 (#736)', () => {
  /**
   * @scenario status=expiring, response=ok
   * @effects mypage_shows_password_status
   */
  it('/api/me 를 받아 마지막 변경·만료 예정·D-n 을 보여 준다', () => {
    const source = asArray<any>(layout.data_sources).find((s) => s.endpoint === '/api/me');
    expect(source?.id).toBe('user');
    const json = JSON.stringify(nodes);
    expect(json).toContain('mypage.profile.password_status.changed_at');
    expect(json).toContain('mypage.profile.password_status.expires_at');
    expect(json).toContain('mypage.profile.password_status.days_left|days={{user?.data?.password_expiry_days_left ?? 0}}');
  });

  /**
   * @scenario status=expiring, response=422
   * @effects mypage_inline_field_errors
   */
  it.each(['current_password', 'password', 'password_confirmation'])('%s 칸 아래에 422 문구를 싣는다', (name) => {
    const input = inputs.find((n) => n.props?.name === name);
    expect(input?.props?.error).toBe(`{{_local.errors?.${name}?.[0] ?? ''}}`);
  });

  /**
   * @scenario status=expiring, response=422
   * @effects mypage_inline_field_errors
   */
  it('실패는 칸 오류를 담고, 화면 세 칸에 오류가 없으면(다른 키의 422 포함) 토스트로 알린다', () => {
    const fail = flattenActions(api?.onError);
    expect(fail.find((a) => a.handler === 'setState')?.params?.errors).toBe('{{error.errors ?? null}}');
    const toastIf = String(fail.find((a) => a.handler === 'toast')?.if);
    expect(toastIf).not.toBe('{{!error.errors}}');
    for (const key of ['current_password', 'password', 'password_confirmation']) {
      expect(toastIf).toContain(`error.errors?.${key}`);
    }
  });

  /**
   * @scenario status=expiring, response=ok
   * @effects rules_hint_reflects_settings
   */
  it('새 비밀번호 규칙이 보안 환경설정 값에 바인딩된다', () => {
    const input = inputs.find((n) => n.props?.name === 'password');
    expect(String(input?.props?.enableRules)).toContain('_global.settings?.security?.password_min_length');
    expect(JSON.stringify(layout)).not.toContain('minLength:8');
  });

  /**
   * @scenario status=expiring, response=ok
   * @effects mypage_success_announces_other_devices_logout
   */
  it('성공하면 다른 기기 로그아웃을 알리고 내 정보를 다시 받는다', () => {
    const success = flattenActions(api?.onSuccess);
    expect(success.some((a) => a.handler === 'toast' && String(a.params?.message).includes('password_status.changed_toast'))).toBe(true);
    expect(success.some((a) => a.handler === 'refetchDataSource' && a.params?.dataSourceId === 'user')).toBe(true);
    expect(JSON.stringify(nodes)).toContain('password_status.other_devices_logged_out');
  });
});
