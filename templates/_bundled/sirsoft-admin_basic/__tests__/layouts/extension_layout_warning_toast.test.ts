/**
 * @file extension_layout_warning_toast.test.ts
 * @description 7.0 JSON 레이아웃의 깨진 조각 참조 경고 토스트 (#712)
 *
 * 코어는 7.0 JSON 확장의 깨진 조각 참조를 설치·활성화를 막지 않고 빈 자리로 등록하며, 응답 `data.layout_warnings`
 * 에 그 목록을 싣는다. 관리자 화면은 모듈·플러그인 활성 토글, 의존성 경고를 거친 강제 활성 창(같은 활성화 API),
 * 템플릿 설치(목록·수동 설치) 성공 시 그 건수를 경고 토스트로 알린다. 굽힌 레이아웃 JSON(설치본과 같은 산출)에서
 * 토스트 액션을 찾아 조건·문구 키를 확인한다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');

const LAYOUTS = [
  'layouts/admin_module_list.json',
  'layouts/admin_plugin_list.json',
  'layouts/partials/admin_module_list/_modal_force_activate.json',
  'layouts/partials/admin_plugin_list/_modal_force_activate.json',
  'layouts/partials/admin_template_list/_modal_manual_install.json',
  'layouts/partials/admin_template_list/_modal_install.json',
];

function loadJson(relative: string): any {
  return JSON.parse(fs.readFileSync(path.join(ROOT, relative), 'utf-8'));
}

/** 레이아웃에서 경고 토스트 액션을 모두 찾는다 */
function findWarningToasts(layout: any): any[] {
  const found: any[] = [];
  const walk = (value: any): void => {
    if (Array.isArray(value)) {
      value.forEach(walk);
      return;
    }
    if (value && typeof value === 'object') {
      if (value.handler === 'toast' && String(value.params?.message ?? '').startsWith('$t:extensions.alerts.layout_include_skipped')) {
        found.push(value);
      }
      Object.values(value).forEach(walk);
    }
  };
  walk(layout);
  return found;
}

/** `{{ … }}` 식을 응답 문맥으로 평가한다 (엔진 문맥의 `response` 와 같은 모양) */
function evaluate(expression: string, response: any): unknown {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function('response', `return (${body});`)(response);
}

describe('깨진 조각 참조 경고 토스트 (layout_warnings)', () => {
  for (const relative of LAYOUTS) {
    // @scenario case=toast_condition
    // @effects layout_warning_toast_shown_only_when_warnings_exist
    it(`${relative} — 경고가 있을 때만 warning 토스트 1개`, () => {
      const toasts = findWarningToasts(loadJson(relative));

      expect(toasts).toHaveLength(1);
      const [toast] = toasts;
      expect(toast.params.type).toBe('warning');

      expect(evaluate(toast.if, { data: { layout_warnings: [] } })).toBe(false);
      expect(evaluate(toast.if, { data: {} })).toBe(false);
      expect(evaluate(toast.if, undefined)).toBe(false);
      expect(evaluate(toast.if, { data: { layout_warnings: [{}, {}] } })).toBe(true);

      const count = toast.params.message.split('|count=')[1];
      expect(evaluate(count, { data: { layout_warnings: [{}, {}] } })).toBe(2);
    });
  }

  for (const locale of ['ko', 'en']) {
    // @scenario case=toast_message_key
    // @effects layout_warning_toast_message_key_defined
    it(`partial/${locale}/extensions.json 에 alerts.layout_include_skipped 문구와 {{count}} 자리`, () => {
      const data = loadJson(`lang/partial/${locale}/extensions.json`);

      expect(data.alerts?.layout_include_skipped).toBeTypeOf('string');
      expect(data.alerts.layout_include_skipped).toContain('{{count}}');
    });
  }
});
