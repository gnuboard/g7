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
   * 회귀(#736 실측 2026-10-06): 수정 → 등록 SPA 이동에서 form 은 비워졌지만 `user` 데이터소스는
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
   * 회원 항목(#490 6단계)도 같은 위험이 있다 — 항목 응답(`memberFields`)의 `member`(닉네임 변경 시각, 비어 있는 필수 항목)는
   * 수정 화면의 회원 것이라, 수정 → 등록 이동 직후 다시 받기 전까지 직전 회원의 값이 남는다. 항목 정의(`fields`)는 등록 화면도
   * 쓰므로 읽어도 되지만, `member` 는 수정 모드 가드 안에서만 읽어야 한다. 소스는 들어올 때마다 다시 받는다.
   *
   * @scenario form=user
   * @effects create_mode_clears_previous_form
   */
  it('회원 항목 상태(memberFields 의 member)는 수정 모드 가드 안에서만 읽고, 항목 소스는 화면에 들어올 때마다 다시 받는다', () => {
    const source = userForm.data_sources.find((d: any) => d.id === 'memberFields');
    expect(source.refetchOnMount).toBe(true);
    // 등록과 수정이 한 소스를 쓴다 — 등록은 회원 없는 주소
    expect(source.endpoint).toBe("/api/admin/users/{{route?.id ? route.id + '/form-fields' : 'form-fields'}}");
    expect(source.if).toBeUndefined();

    const leaks: string[] = [];
    const scan = (owner: string, key: string, v: unknown) => {
      if (typeof v === 'string') {
        if (/memberFields\?\.data\?\.member/.test(v) && !/!!route\?\.id/.test(v)) leaks.push(`${owner}.${key} = ${v}`);
        return;
      }
      if (v && typeof v === 'object') {
        for (const [k, sub] of Object.entries(v)) scan(owner, `${key}.${k}`, sub);
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
      const g = guarded || (typeof n.if === 'string' && /!!route\?\.id/.test(n.if));
      if (!g) {
        for (const [k, v] of Object.entries(n)) {
          if (k !== 'children' && k !== 'slots') scan(n.id ?? n.name, k, v);
        }
      }
      if (n.children) walk(n.children, g);
      if (n.slots) walk(n.slots, g);
    };
    walk(userForm.slots, false);
    expect(leaks).toEqual([]);
    // 가드가 실제로 걸려 있다(읽는 자리가 하나도 없어서 통과한 것이 아니다)
    expect(JSON.stringify(userForm.slots)).toContain('memberFields?.data?.member?.nickname_changed_at');
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
