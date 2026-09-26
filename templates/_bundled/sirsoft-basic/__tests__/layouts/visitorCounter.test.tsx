/**
 * @file visitorCounter.test.tsx
 * @description 홈 방문자 카운터 조각·데이터소스의 런타임 계약 (이슈 #682 S3 C4)
 *
 * 잠그는 것: 공개 요약 소스가 설정이 켜졌을 때만 받고 404 를 삼키는 것, 조각이 설정 켬 + 응답 도착일 때만 그려지는 것
 * (꺼졌거나 실패하면 0 대신 숨김), VisitorCounter 가 네 숫자와 번역 라벨을 받는 것, 라벨 키가 ko/en 에 있는 것.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { scanBindings } from '@core/template-engine/BindingShape';

const here = path.dirname(fileURLToPath(import.meta.url));
const T = path.resolve(here, '../..');
const read = (rel: string): any => JSON.parse(fs.readFileSync(path.join(T, rel), 'utf8'));

const home = read('layouts/home.json');
const partial = read('layouts/partials/_visitor_counter.json');

function collect(root: unknown): Record<string, any>[] {
  const out: Record<string, any>[] = [];
  const walk = (cur: unknown): void => {
    if (Array.isArray(cur)) { cur.forEach(walk); return; }
    if (cur && typeof cur === 'object') { out.push(cur as any); Object.values(cur as any).forEach(walk); }
  };
  walk(root);
  return out;
}

describe('홈 방문자 카운터', () => {
  // @scenario screen=home_widget, state=normal, viewport=1440, locale=ko
  // @effects home_counter_source_is_gated_by_setting
  it('공개 요약 소스는 설정이 켜졌을 때만 background 로 받고 404 를 삼킨다', () => {
    const ds = (home.data_sources ?? []).find((d: any) => d.id === 'visitor_summary');
    expect(ds?.endpoint).toBe('/api/analytics/summary');
    // [case:components-misc-global-settings-prefix]
    // 공개 설정은 `_global.settings` 로 읽는다 — 접두사가 빠지면 조건이 늘 거짓이라
    // 요청도 오류도 없이 카운터가 통째로 사라진다(브라우저 실측 M10-T1).
    expect(ds?.if).toBe('{{_global.settings?.analytics?.public_summary_enabled === true}}');
    expect(ds?.loading_strategy).toBe('background');
    expect(ds?.auth_required).toBeUndefined();
    expect(JSON.stringify(ds?.errorHandling)).toContain('"404"');
    expect(JSON.stringify(ds?.errorHandling)).toContain('"handler":"suppress"');
    // fallback 을 두지 않는다 — 0 으로 채우면 요청이 실패한 방문자에게 「오늘 0명」이 그려진다.
    // 응답이 없을 때는 조각의 `if` 가 카운터를 숨긴다(아래 진리표).
    expect(ds?.fallback).toBeUndefined();
    expect(JSON.stringify(home)).toContain('partials/_visitor_counter.json');
  });

  // @scenario screen=home_widget, state=collection_off, viewport=1440, locale=ko
  // @effects home_counter_hides_when_disabled_or_failed
  it('조각은 설정 켬 + 응답 도착 + 오류 없음일 때만 그려진다 (0 을 그리지 않는다)', () => {
    const root = collect(partial).find((o) => o.id === 'visitor_counter_section');
    // 조건 식을 실제로 평가해 진리표로 잰다 — 부분 문자열로 재면 `!` 하나가 뒤집혀도 초록이다(#682 S3 R16-D3).
    const [expr] = scanBindings(String(root?.if ?? '')).map((b) => b.expr);
    expect(expr, '카운터 조각의 표시 조건').toBeTruthy();
    const shown = (enabled: unknown, summary: unknown): boolean =>
      !!evaluateSafeExpression(expr, { _global: { settings: { analytics: { public_summary_enabled: enabled } } }, visitor_summary: summary });
    const data = { data: { today: 1, yesterday: 2, max: 3, total: 4 } };

    expect(shown(true, data), '켜짐 + 응답 도착').toBe(true);
    expect(shown(false, data), '설정 꺼짐 — 0 대신 숨김').toBe(false);
    expect(shown(undefined, data), '설정 값을 모름(공개 설정에 키 없음)').toBe(false);
    expect(shown('1', data), '참 비슷한 값이 아니라 정확히 true 만').toBe(false);
    expect(shown(true, undefined), '응답 전 — 0 을 그리지 않는다').toBe(false);
    expect(shown(true, { data: null }), '빈 응답').toBe(false);
    expect(shown(true, { ...data, error: { status: 404 } }), '실패(404 를 삼킨 뒤)').toBe(false);
  });

  // @scenario screen=home_widget, state=normal, viewport=390, locale=en
  // @effects home_counter_labels_exist_in_ko_and_en
  it('VisitorCounter 는 네 숫자와 번역 라벨을 받고 라벨 키는 ko/en 에 있다', () => {
    const node = collect(partial).find((o) => o.name === 'VisitorCounter');
    expect(node?.props?.today).toBe('{{visitor_summary?.data?.today ?? 0}}');
    expect(node?.props?.yesterday).toBe('{{visitor_summary?.data?.yesterday ?? 0}}');
    expect(node?.props?.max).toBe('{{visitor_summary?.data?.max ?? 0}}');
    expect(node?.props?.total).toBe('{{visitor_summary?.data?.total ?? 0}}');
    expect(node?.props?.layout).toBe('row');
    const labels = node?.props?.labels ?? {};
    const ko = read('lang/partial/ko/user.json');
    const en = read('lang/partial/en/user.json');
    for (const k of ['today', 'yesterday', 'max', 'total']) {
      expect(labels[k], k).toBe(`$t:user.visitor_counter.${k}`);
      expect(typeof ko.visitor_counter?.[k], k).toBe('string');
      expect(typeof en.visitor_counter?.[k], k).toBe('string');
    }
  });
});
