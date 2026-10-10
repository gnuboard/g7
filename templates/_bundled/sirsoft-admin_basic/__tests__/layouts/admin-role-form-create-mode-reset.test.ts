/**
 * @file admin-role-form-create-mode-reset.test.ts
 * @description 역할 폼 — 같은 레이아웃 재사용(수정 → 등록) 시 form 잔존 제거 (레이아웃 JSON 구조 검증)
 *
 * 배경(브라우저 실측 2026-09-14, #599): 엔진은 layout_name 이 바뀔 때만 `_local` 을 비운다.
 * 역할 수정 → 역할 등록은 둘 다 admin_role_form 이라 `_local.form` 이 남고, 레이아웃 `state.form`
 * 기본값은 기존 키가 있으면 다시 적용되지 않아 등록 화면의 식별자 칸에 직전 역할(admin)이 그대로
 * 보였다. sirsoft-page admin_page_form 선례처럼 생성 모드 init_actions 에서 `form: null` 로 비운
 * 뒤 `state.form` 과 같은 기본값을 다시 쓴다. 복제 진입(clone_from)은 clone_source 데이터소스의
 * initLocal 이 init_actions 뒤에 병합되므로 영향을 받지 않는다.
 *
 * 시나리오: tests/scenarios/admin-role-form-create-mode-reset.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';
import { initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const LAYOUTS_BASE = path.resolve(__dirname, '../../layouts');

function loadLayout(relativePath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(LAYOUTS_BASE, relativePath), 'utf-8'));
}

type InitAction = { handler?: string; if?: string; params?: Record<string, any> };

const roleForm = loadLayout('admin_role_form.json');
const actions: InitAction[] = roleForm.init_actions ?? roleForm.initActions ?? [];
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

describe('admin_role_form.json — 생성 모드 form 잔존 제거', () => {
  /**
   * @scenario form=role
   * @effects create_mode_clears_previous_form
   */
  it('생성 모드(!route?.id)에서 form 을 null 로 비우는 init 액션이 있다', () => {
    expect(findFormNullReset()).toBeGreaterThanOrEqual(0);
  });

  /**
   * @scenario form=role
   * @effects state_form_defaults_reapplied
   */
  it('form=null 뒤에 레이아웃 state.form 과 같은 기본값을 같은 조건으로 다시 쓴다', () => {
    const resetIdx = findFormNullReset();
    const defaults = actions
      .slice(resetIdx + 1)
      .find((a) => a.handler === 'setState' && a.if === CREATE && a.params?.target === 'local' && a.params?.form);
    expect(defaults).toBeDefined();
    expect(defaults!.params!.form).toEqual(initStateOf(roleForm).form);
  });

  /**
   * @scenario form=role
   * @effects clone_source_untouched
   */
  it('복제 진입용 clone_source 데이터소스는 그대로(initLocal: form) 유지된다', () => {
    const clone = roleForm.data_sources.find((d: any) => d.id === 'clone_source');
    expect(clone.initLocal).toBe('form');
    expect(clone.if).toContain('clone_from');
  });
});
