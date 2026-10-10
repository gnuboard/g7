/**
 * @file analyticsRealtime.test.tsx
 * @description 실시간 화면 런타임 계약 — 방송 소스가 실시간 소스를 갈아 끼우고 폴링이 폴백으로 도는 것 (이슈 #682 S3)
 */

import fs from 'fs';
import path from 'path';
import React from 'react';
import { describe, it, expect } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { TEMPLATE_DIR, loadLayout, loadLang, dataSource, nodesNamed, nodeById, actionsWithHandler, stringify } from './analyticsLayoutTestUtils';

const layout = loadLayout('admin_analytics_realtime.json');

const Simple = (tag: string): React.FC<any> => ({ id, className, children, text }) =>
  React.createElement(tag, { id, className }, children || text);

function barRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: Simple('div'), metadata: { name: 'Div', type: 'basic' } },
    Span: { component: Simple('span'), metadata: { name: 'Span', type: 'basic' } },
    Button: { component: Simple('button'), metadata: { name: 'Button', type: 'basic' } },
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

describe('개요 실시간 한 줄 — 실제 렌더', () => {
  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects realtime_bar_counts_top_pages, translation_params_are_substituted
  it('접속자 수는 번역 인자로 치환되고 페이지 순위는 접속자의 마지막 페이지를 센다', async () => {
    // 구조 대조만으로는 못 잡는 두 결함을 실제 엔진으로 렌더해 잠근다 — 식 안 `$t()` 의 인자는 버려져
    // 「지금 {{count}}명」이 그대로 보였고, 대입문이 든 reduce 는 평가기가 읽지 못해 순위가 비었다.
    const bar = loadLayout('partials/admin_analytics/_partial_realtime_bar.json');
    const probe = {
      version: '1.0.0',
      layout_name: 'analytics_realtime_bar_probe',
      data_sources: [{ id: 'analytics_realtime', type: 'api', endpoint: '/api/admin/analytics/realtime', method: 'GET', auto_fetch: true }],
      components: [bar],
    };
    const t = createLayoutTest(probe, {
      componentRegistry: barRegistry(),
      translations: { admin: loadLang('ko', 'admin') },
      locale: 'ko',
    });
    t.mockApi('analytics_realtime', {
      response: { data: { count: 1234, visitors: [{ page: '/a' }, { page: '/b' }, { page: '/a' }, { page: null }] } },
    });
    await t.render();

    expect(document.querySelector('#realtime_count')?.textContent).toBe('지금 1,234명 보는 중');
    expect(document.querySelector('#realtime_pages')?.textContent?.trim()).toBe('/a (2) · /b (1)');
    t.cleanup();
  });
});

describe('실시간 화면', () => {
  // @scenario screen=realtime, state=normal, viewport=1440, locale=ko
  // @effects realtime_broadcast_refetches_with_own_window, realtime_window_is_query
  it('실시간 소스는 창(분)을 질의에서 읽고 방송은 그 소스를 다시 받는 신호로만 쓴다', () => {
    const rt = dataSource(layout, 'analytics_realtime');
    expect(rt?.endpoint).toBe('/api/admin/analytics/realtime');
    expect(rt?.params?.minutes).toBe('{{query.minutes}}');

    // 방송은 채널의 모든 연결에 같은 값(기본 창·주소 없음)이라, 그 값으로 소스를 갈아 끼우면 창을 30분으로
    // 고른 화면이 방송마다 「최근 5분」으로 튀고 주소 열람 권한자의 IP 칸이 비었다(#682 S3 라운드 14, 실측).
    const ws = dataSource(layout, 'analytics_realtime_ws');
    expect(ws).toMatchObject({ type: 'websocket', channel: 'core.admin.analytics', event: 'analytics.realtime.updated', channel_type: 'private' });
    expect(ws?.target_source, '방송 값으로 실시간 소스를 갈아 끼우면 창·권한이 사라진다').toBeUndefined();
    expect(
      (ws?.onReceive ?? []).some((a: any) => a.handler === 'refetchDataSource' && a.params?.dataSourceId === 'analytics_realtime'),
      '방송을 받으면 이 화면의 창·권한으로 다시 받아야 한다',
    ).toBe(true);
  });

  // @scenario screen=realtime, state=normal, viewport=1440, locale=ko
  // @effects realtime_broadcast_refetches_with_own_window
  it('통계 화면의 어떤 방송도 화면 소스를 방송 값으로 갈아 끼우지 않는다', () => {
    // 모집단은 통계 화면 전부에서 파생한다 — 방송은 보는 사람의 질의·권한을 모르므로, 화면 소스를
    // 방송 값으로 덮는 순간 그 화면의 창·기간·권한별 값이 사라진다.
    const layoutsDir = path.join(TEMPLATE_DIR, 'layouts');
    const sockets = fs
      .readdirSync(layoutsDir)
      .filter((f) => f.startsWith('admin_analytics_') && f.endsWith('.json'))
      .flatMap((f) => (loadLayout(f).data_sources ?? []).filter((d: any) => d.type === 'websocket').map((d: any) => ({ f, d })));

    expect(sockets.length, '방송 소스가 비면 아무것도 재지 않은 채 초록이 된다').toBeGreaterThanOrEqual(2);
    for (const { f, d } of sockets) {
      expect(d.target_source, `${f} 의 ${d.id} 가 ${d.target_source} 를 방송 값으로 덮는다`).toBeUndefined();
    }
  });

  // @scenario screen=realtime, state=ws_polling, viewport=1440, locale=ko
  // @effects realtime_interval_id_is_fixed_and_cleared_on_unmount
  it('폴링은 이 화면만의 고정 id 로 돌고 화면을 떠나면 멈춘다', () => {
    const start = (layout.init_actions ?? []).find((a: any) => a.handler === 'startInterval');
    expect(start?.params?.id).toBe('analytics_realtime_live');
    // [case:components-misc-global-settings-prefix] 접두사 없는 `settings?.` 는 늘 undefined 라 기본 30초로만 돈다
    expect(start?.params?.intervalMs).toContain('_global.settings?.analytics?.realtime_poll_seconds');
    expect(actionsWithHandler(layout, 'stopInterval').some((a) => a.params?.id === 'analytics_realtime_live')).toBe(true);
  });

  // @scenario screen=realtime, state=ws_polling, viewport=390, locale=ko
  // @effects realtime_window_is_query, realtime_transport_badge_follows_socket_config
  it('창 Select 는 질의로 이동하고 배지는 소켓 설정 여부로 갈린다', () => {
    const nav = actionsWithHandler(layout, 'navigate').find((n) => typeof n.params?.query?.minutes === 'string');
    expect(nav?.params).toMatchObject({ path: '/admin/analytics/realtime', mergeQuery: true, replace: true });
    expect(nodeById(layout, 'transport_badge_ws')?.if).toContain('broadcasting?.configured === true');
    expect(nodeById(layout, 'transport_badge_polling')?.if).toContain('broadcasting?.configured !== true');
  });

  // @scenario screen=realtime, state=normal, viewport=1440, locale=ko
  // @effects realtime_ip_column_only_from_server
  it('최근 방문 표는 주소를 서버가 채운 경우에만 보이고 열은 여섯이다', () => {
    const dg = nodesNamed(layout, 'DataGrid')[0];
    expect((dg.props.columns ?? []).map((c: any) => c.field)).toEqual(['last_action_at', 'page', 'country_code', 'device_type', 'visitor_type', 'visitor_key']);
    expect(stringify(dg)).toContain('!!row.ip');
    expect(dg.props.selectable).toBe(false);
  });
});

describe('창 선택지', () => {
  // @scenario screen=realtime, state=normal, viewport=1440, locale=ko
  // @effects realtime_window_choices_fit_the_server_range
  it('기본 5분과 10분을 고를 수 있고, 모든 선택지가 서버가 받는 범위 안이다', () => {
    // 현재 접속자는 10분 창이 흔히 쓰는 기준이다 — 선택지에 없으면 운영자가 주소를 손으로 고쳐야 한다.
    const php = fs.readFileSync(path.resolve(TEMPLATE_DIR, '../../../app/Http/Requests/Admin/Analytics/RealtimeRequest.php'), 'utf8');
    const max = Number(/'minutes'\s*=>\s*\[[^\]]*'max:(\d+)'/.exec(php)?.[1]);
    expect(max, 'RealtimeRequest 의 minutes 상한').toBeGreaterThan(0);

    const values = ((nodeById(layout, 'window_select')?.props?.options ?? []) as Array<{ value: string }>).map((o) => Number(o.value));
    expect(values.length, '창 선택지').toBeGreaterThanOrEqual(3);
    expect(values).toEqual(expect.arrayContaining([5, 10]));
    expect(values.filter((v: number) => v < 1 || v > max), '서버 범위 밖 선택지').toEqual([]);
  });
});
