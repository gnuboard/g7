/**
 * @file commonInfoToggleEventValue.test.ts
 * @description S18.5 축 C5 — 공통정보 폼 토글이 이벤트 객체를 boolean 필드에 저장하던 회귀
 *
 * 결함: `<toggle>` 의 `change` 콜백이 `{{$event}}` 를 그대로 `form.is_default` ·
 * `form.is_active` 에 넣고 있었다. Toggle 은 boolean 이 아니라 **이벤트 객체**를 넘긴다 —
 * `handleChange` 는 `onChange(e)`(DOM ChangeEvent), `handleToggleClick` 은
 * `createChangeEvent({ checked, name })` 로 합성한 이벤트를 넘긴다. 따라서 boolean 을 기대하는
 * 필드에 객체가 저장됐다.
 *
 * 분기 조건 `{{$event && !_local.form.is_default}}` 도 같은 결함이다 — 객체는 언제나 truthy 라
 * 조건이 사실상 `!is_default` 로 축약돼 있었다(UI 경로상 우연히 같은 결과를 냈지만, 표현이
 * 의도를 담고 있지 않다).
 *
 * @vitest-environment node
 *
 * @scenario compile_unit=layout,source_health=valid
 * @effects toggle_change_stores_boolean_not_the_event_object
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const FORM = path.resolve(
  __dirname,
  '../../../layouts/admin/partials/admin_ecommerce_product_common_info_index/_panel_form.json',
);

/** 레이아웃 트리 전체를 문자열로 훑어 `{{$event}}` 단독 전달을 센다. */
function bareEventBindings(node: unknown): string[] {
  const hits: string[] = [];
  const walk = (n: unknown, trail: string): void => {
    if (typeof n === 'string') {
      // `{{$event}}` 단독 — `{{$event.target.checked}}` 는 걸리지 않는다.
      if (/\{\{\s*\$event\s*\}\}/.test(n)) hits.push(`${trail} = ${n}`);
      // 표현식 안에서 `$event` 를 값처럼 쓰는 형태(`$event &&` 등)도 같은 결함이다.
      if (/\$event\s*(&&|\|\||\?)/.test(n)) hits.push(`${trail} = ${n}`);
      return;
    }
    if (Array.isArray(n)) { n.forEach((c, i) => walk(c, `${trail}[${i}]`)); return; }
    if (n && typeof n === 'object') {
      for (const [k, v] of Object.entries(n as Record<string, unknown>)) walk(v, `${trail}.${k}`);
    }
  };
  walk(node, '$');

  return hits;
}

describe('S18.5 축 C5 — 공통정보 폼 토글의 이벤트 값 바인딩', () => {
  const layout = JSON.parse(fs.readFileSync(FORM, 'utf8'));

  it('레이아웃이 비어 있지 않다 (모집단 하한)', () => {
    expect(JSON.stringify(layout).length).toBeGreaterThan(1000);
  });

  it('`{{$event}}` 를 값으로 쓰는 자리가 없다', () => {
    expect(bareEventBindings(layout)).toEqual([]);
  });

  it('토글 저장은 `$event.target.checked` 로 boolean 을 꺼낸다', () => {
    const flat = JSON.stringify(layout);

    for (const field of ['form.is_default', 'form.is_active']) {
      expect(flat).toContain(`"${field}":"{{$event.target.checked}}"`);
    }
  });

  it('판정식이 살아 있다 (합성 표본을 넣으면 검출된다)', () => {
    // 프로덕션 판정과 같은 함수로 오염 표본을 통과시켜, 위 두 단언이 공허하지 않음을 보인다.
    expect(bareEventBindings({ actions: [{ params: { value: '{{$event}}' } }] })).toHaveLength(1);
    expect(bareEventBindings({ on: '{{$event && !x}}' })).toHaveLength(1);
    expect(bareEventBindings({ ok: '{{$event.target.checked}}' })).toEqual([]);
  });
});
