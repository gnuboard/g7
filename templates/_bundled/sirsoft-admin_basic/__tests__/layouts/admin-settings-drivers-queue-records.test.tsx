/**
 * @file admin-settings-drivers-queue-records.test.tsx
 * @description 드라이버 탭 큐 카드 「처리 기록」 구역 (7.2.0, #17 D7·D8·K9)
 *
 * 처리 기록 켜기(`drivers.queue_job_log_enabled`)와 기록·실패 작업 공통 보관 기간(`drivers.queue_record_retention_days`,
 * 기본 7일, 1~90)을 고친다. 한계값은 설정 응답의 limits 메타(`drivers_queue_record_retention_days_min|max`)가 주고,
 * 드라이버와 무관한 값이라 sync 여도 보인다. 기록 자체는 스케줄 관리 > 큐 > 처리 기록 링크로 본다.
 *
 * 시나리오 매니페스트: settings-schedule-links (tests/scenarios — test_files 귀속)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import driversPartial from '../../layouts/partials/admin_settings/_tab_drivers.json';

const ko = require('../../lang/partial/ko/admin.json');
const en = require('../../lang/partial/en/admin.json');

type Json = Record<string, any>;

/** 객체 트리의 모든 노드를 모읍니다. */
function nodes(value: any): Json[] {
  if (!value || typeof value !== 'object') return [];
  if (Array.isArray(value)) return value.flatMap(nodes);
  return [value, ...Object.values(value).flatMap(nodes)];
}

const section = nodes(driversPartial).find((n) => n.id === 'queue_record_section') as Json;

describe('드라이버 탭 — 처리 기록 구역', () => {
  /**
   * @scenario screen=drivers
   * @effects drivers_queue_record_settings_inputs
   */
  it('큐 카드 안에 있고 드라이버 조건(sync 여부)에 묶이지 않는다', () => {
    const card = nodes(driversPartial).find((n) => n.id === 'card_queue_settings');
    expect(nodes(card).some((n) => n.id === 'queue_record_section')).toBe(true);
    expect(section.if).toBeUndefined();
  });

  /**
   * @scenario screen=drivers
   * @effects drivers_queue_record_settings_inputs
   */
  it('처리 기록 토글과 보관 기간 입력이 K9 키 이름에 묶이고, 한계값은 limits 메타(기본 1~90)를 읽는다', () => {
    const toggle = nodes(section).find((n) => n.name === 'Toggle');
    expect(toggle?.props.name).toBe('drivers.queue_job_log_enabled');
    const input = nodes(section).find((n) => n.props?.name === 'drivers.queue_record_retention_days');
    expect(input?.props.type).toBe('number');
    expect(input?.props.min).toBe('{{_local?.form?._meta?.limits?.drivers_queue_record_retention_days_min ?? 1}}');
    expect(input?.props.max).toBe('{{_local?.form?._meta?.limits?.drivers_queue_record_retention_days_max ?? 90}}');
    expect(input?.props.disabled).toBe('{{_computed.isReadOnly}}');
    // 서버 422 오류는 그 칸 아래에만 단다
    const error = nodes(section).find((n) => n.if === "{{_local.errors?.['drivers.queue_record_retention_days']}}");
    expect(error?.props.className).toBe('form-error');
  });

  /**
   * @scenario screen=drivers
   * @effects drivers_queue_record_settings_inputs
   */
  it('처리 기록 화면 링크는 큐 탭의 처리 기록 하위 탭으로 가고, 정리 스케줄 안내 상자 안에 「스케줄 관리에서 변경」과 함께 있다', () => {
    const link = nodes(section).find((n) => n.id === 'queue_records_link');
    expect(link?.name).toBe('A');
    expect(link?.props.href).toBe('/admin/schedules/queue?view=logs');
    // 안내 상자(alert-info + 아이콘 + 문장 + 링크) 하나에 처리 기록 보기·정리 스케줄 링크를 함께 둔다
    const notice = nodes(section).find((n) => n.id === 'queue_records_schedule_notice');
    expect(notice?.props.className).toBe('alert-info');
    expect(nodes(notice).some((n) => n.id === 'queue_records_link')).toBe(true);
    const scheduleLink = nodes(notice).find((n) => n.id === 'queue_records_schedule_notice_link');
    expect(scheduleLink?.props.href).toBe('/admin/schedules?extension_type=core&search_field=identifier&search_keyword=queue-prune-records');
  });

  /**
   * @scenario screen=drivers
   * @effects drivers_queue_record_settings_inputs
   */
  it('구역이 쓰는 번역 키가 ko·en 양쪽에 있다', () => {
    const text = JSON.stringify(section);
    const keys = [...new Set([...text.matchAll(/\$t:admin\.([A-Za-z0-9_.]+)/g)].map((m) => m[1].replace(/\.$/, '')))];
    expect(keys.length).toBeGreaterThanOrEqual(6);
    const resolve = (dict: any, key: string) => key.split('.').reduce((o: any, k: string) => (o == null ? undefined : o[k]), dict);
    expect(keys.filter((k) => resolve(ko, k) === undefined || resolve(en, k) === undefined)).toEqual([]);
  });

  describe('렌더', () => {
    let utils: ReturnType<typeof createLayoutTest> | undefined;
    beforeEach(() => {
      const registry: any = ComponentRegistry.getInstance();
      const pass = (tag: string) => ({ text, children, ...props }: any) => React.createElement(tag, props, children ?? text);
      registry.registry = {
        Div: { component: pass('div'), metadata: { name: 'Div', type: 'basic' } },
        P: { component: pass('p'), metadata: { name: 'P', type: 'basic' } },
        Span: { component: pass('span'), metadata: { name: 'Span', type: 'basic' } },
        H4: { component: pass('h4'), metadata: { name: 'H4', type: 'basic' } },
        Label: { component: pass('label'), metadata: { name: 'Label', type: 'basic' } },
        A: { component: pass('a'), metadata: { name: 'A', type: 'basic' } },
        // 처리 기록 안내 상자(alert-info)의 아이콘
        Icon: { component: ({ name }: any) => <i data-icon={name} />, metadata: { name: 'Icon', type: 'basic' } },
        Input: { component: ({ name, min, max, disabled }: any) => <input name={name} data-min={min} data-max={max} disabled={!!disabled} />, metadata: { name: 'Input', type: 'basic' } },
        Toggle: { component: ({ name }: any) => <span data-toggle={name} />, metadata: { name: 'Toggle', type: 'composite' } },
        Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
      };
    });
    afterEach(() => {
      utils?.cleanup();
      (ComponentRegistry.getInstance() as any).registry = {};
    });

    /**
     * @scenario screen=drivers
     * @effects drivers_queue_record_settings_inputs
     */
    it('limits 메타 값으로 한계를 그리고, 서버 오류를 칸 아래에 보인다', async () => {
      utils = createLayoutTest({ version: '1.0.0', layout_name: 'queue_record_section', components: [section] } as any, {
        translations: {}, locale: 'ko',
        initialState: {
          _local: {
            form: { _meta: { limits: { drivers_queue_record_retention_days_min: 1, drivers_queue_record_retention_days_max: 90 } } },
            errors: { 'drivers.queue_record_retention_days': ['1~90 사이로 입력하세요.'] },
          },
        },
      } as any);
      const { container } = await utils.render();
      const input = container.querySelector('input[name="drivers.queue_record_retention_days"]');
      expect(input?.getAttribute('data-min')).toBe('1');
      expect(input?.getAttribute('data-max')).toBe('90');
      expect(container.querySelector('[data-toggle="drivers.queue_job_log_enabled"]')).not.toBeNull();
      expect(container.textContent).toContain('1~90 사이로 입력하세요.');
      expect(container.querySelector('a')?.getAttribute('href')).toBe('/admin/schedules/queue?view=logs');
    });
  });
});
