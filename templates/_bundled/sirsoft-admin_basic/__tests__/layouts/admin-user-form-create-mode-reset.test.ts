/**
 * @file admin-user-form-create-mode-reset.test.ts
 * @description 회원 폼 — 같은 레이아웃 재사용(수정 → 등록) 시 form 잔존 제거 (레이아웃 JSON 구조 검증)
 *
 * 배경(브라우저 실측 2026-09-14, #599): 회원 수정 → 회원 등록은 둘 다 admin_user_form 이라 엔진이
 * `_local` 을 비우지 않고(layout_name 불변), 등록 폼에 직전 회원의 이메일·이름이 채워진 채 보였다
 * (form 76키 잔존, 직접 진입은 form 없음). 역할 폼과 같은 방식으로 생성 모드 init_actions 에서
 * `form: null` 로 비운다. 이 레이아웃은 `state.form` 기본값이 없어 다시 쓸 기본값도 없다.
 *
 * 시나리오: tests/scenarios/admin-role-form-create-mode-reset.yaml (form=user)
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const LAYOUTS_BASE = path.resolve(__dirname, '../../layouts');

function loadLayout(relativePath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(LAYOUTS_BASE, relativePath), 'utf-8'));
}

type InitAction = { handler?: string; if?: string; params?: Record<string, any> };

const userForm = loadLayout('admin_user_form.json');
const actions: InitAction[] = userForm.init_actions ?? userForm.initActions ?? [];
const CREATE = '{{!route?.id}}';

function findFormNullReset(): number {
  return actions.findIndex(
    (a) =>
      a.handler === 'setState' &&
      a.if === CREATE &&
      a.params?.target === 'local' &&
      Object.prototype.hasOwnProperty.call(a.params ?? {}, 'form') &&
      a.params?.form === null
  );
}

describe('admin_user_form.json — 생성 모드 form 잔존 제거', () => {
  /**
   * @scenario form=user
   * @effects create_mode_clears_previous_form
   */
  it('생성 모드(!route?.id)에서 form 을 null 로 비우고 hasChanges 를 내리는 init 액션이 있다', () => {
    const idx = findFormNullReset();
    expect(idx).toBeGreaterThanOrEqual(0);
    expect(actions[idx].params?.hasChanges).toBe(false);
  });

  /**
   * @scenario form=user
   * @effects edit_datasource_untouched
   */
  it('수정 모드의 user 데이터소스는 그대로(initLocal: form, if: route?.id) 유지된다', () => {
    const user = userForm.data_sources.find((d: any) => d.id === 'user');
    expect(user.initLocal).toBe('form');
    expect(user.if).toBe('{{route?.id}}');
    expect(user.refetchOnMount).toBe(true);
  });

  /**
   * 회귀(#736 영역 C 실측 2026-10-06): 수정 → 등록 SPA 이동에서 form 은 비워졌지만 `user` 데이터소스는
   * 직전 회원 응답이 남아, 등록 폼의 「이메일 인증」 칸이 직전 회원의 「인증됨」 을 보였다(직접 진입은 「미인증」).
   * 역할 칸 잠금도 직전 회원의 `can_assign_roles` 를 읽었다. 수정 모드 가드(`route?.id`) 밖에서 `user` 데이터소스를
   * 읽는 표현식이 없어야 한다.
   *
   * @scenario form=user
   * @effects create_mode_clears_previous_form
   */
  it('수정 모드 가드(route?.id) 밖의 표현식은 직전 회원(user 데이터소스)을 읽지 않는다', () => {
    const isEditGuard = (s: unknown) =>
      typeof s === 'string' && /route\?\.id/.test(s) && !/(^|[^!])!route\?\.id/.test(s);
    const leaks: string[] = [];
    const scanValue = (owner: string, key: string, v: unknown) => {
      if (typeof v === 'string') {
        if (/\buser\?\.data|\buser\.data/.test(v) && !/route\?\.id/.test(v)) leaks.push(`${owner}.${key} = ${v}`);
        return;
      }
      if (v && typeof v === 'object') {
        for (const [k, sub] of Object.entries(v)) scanValue(owner, `${key}.${k}`, sub);
      }
    };
    const walk = (n: any, guarded: boolean): void => {
      if (!n || typeof n !== 'object') return;
      if (Array.isArray(n)) {
        n.forEach((c) => walk(c, guarded));
        return;
      }
      if (!n.type && !n.name) {
        Object.values(n).forEach((c) => walk(c, guarded));
        return;
      }
      const g = guarded || isEditGuard(n.if);
      if (!g) {
        for (const [k, v] of Object.entries(n)) {
          if (k !== 'children' && k !== 'slots') scanValue(n.id ?? n.name, k, v);
        }
      }
      if (n.children) walk(n.children, g);
      if (n.slots) walk(n.slots, g);
    };
    walk(userForm.slots, false);
    expect(leaks).toEqual([]);
  });

  /**
   * @scenario form=user
   * @effects create_mode_clears_previous_form
   */
  it('레이아웃 state 에 form 기본값이 없으므로 form=null 뒤에 기본값을 다시 쓰는 액션은 두지 않는다', () => {
    expect(userForm.state?.form).toBeUndefined();
    const defaultsAfterReset = actions
      .slice(findFormNullReset() + 1)
      .find((a) => a.handler === 'setState' && a.params?.target === 'local' && a.params?.form);
    expect(defaultsAfterReset).toBeUndefined();
  });
});
