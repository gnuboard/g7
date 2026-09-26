/**
 * @file analyticsPatterns.test.tsx
 * @description 요일·시간대 패턴 화면의 런타임 계약 (이슈 #682 S3)
 *
 * 14면 중 이 화면만 전용 렌더링 테스트가 없었다(#682 S3 라운드 2). 리포트 5면과 달리 표가 아니라
 * 막대 두 개라 공통 조각의 가드가 닿지 않는 자리가 있다.
 *
 * 잠그는 것: 기간 바가 이 화면의 소스를 다시 받게 되어 있는 것, 지표 Select 가 두 차트의 데이터를
 * 동시에 바꾸는 것, 시간대 값이 보존기간 밖이면 안내가 뜨는 것, 그리고 CSV 가 없는 화면이라 버튼이
 * 숨는 것.
 */

import { describe, it, expect } from 'vitest';
import { loadLayout, dataSource, nodeById, nodesNamed, stringify } from './analyticsLayoutTestUtils';

const layout = loadLayout('admin_analytics_patterns.json');

describe('요일·시간대 패턴 화면', () => {
  // @scenario screen=patterns, state=normal, viewport=1440, locale=ko
  // @effects patterns_screen_reads_the_period_from_the_query
  it('기간은 질의가 출처이고 새로고침 버튼이 이 화면의 소스를 다시 받는다', () => {
    const ds = dataSource(layout, 'analytics_patterns');
    expect(ds?.endpoint).toBe('/api/admin/analytics/patterns');
    expect(ds?.params?.preset).toBe("{{query.from ? undefined : (query.preset || '7d')}}");
    expect(ds?.params?.from).toBe('{{query.from}}');
    expect(ds?.params?.to).toBe('{{query.to}}');
    expect(stringify(ds?.errorHandling)).toContain('showErrorPage');

    // 기간 바는 `periodSource` 를 다시 받고 `periodPath` 로 이동한다 — 두 값이 이 화면을 가리켜야 한다.
    expect(layout.initLocal?.periodSource).toBe('analytics_patterns');
    expect(layout.initLocal?.periodPath).toBe('/admin/analytics/patterns');
    expect(stringify(layout)).toContain('partials/admin_analytics/_partial_period_bar.json');
    expect(stringify(layout)).toContain('partials/admin_analytics/_partial_status_alerts.json');
  });

  // @scenario screen=patterns, state=normal, viewport=1440, locale=ko
  // @effects patterns_metric_select_drives_both_charts
  it('지표 Select 를 바꾸면 요일·시간대 두 차트가 같은 지표를 그린다', () => {
    const select = nodeById(layout, 'metric_select');
    const values = (select?.props?.options ?? []).map((option: any) => option.value);
    expect(values).toEqual(['visits', 'visitors', 'pageviews']);
    // 고른 지표는 화면 상태가 아니라 주소에 실린다 — 화면 상태로 두면 새로고침·주소 공유에서
    // 「방문」으로 되돌아간다(#682 라운드 4, 검수 지적 ①).
    expect(select?.actions?.[0]).toMatchObject({ type: 'change', handler: 'navigate' });
    expect(select?.actions?.[0]?.params?.query?.metric).toContain('$event.target.value');
    expect(select?.props?.value).toContain('query.metric');

    // 두 차트가 같은 `query.metric` 을 읽어야 Select 하나가 둘을 동시에 바꾼다.
    for (const id of ['weekday_chart', 'hour_chart']) {
      const chart = nodeById(layout, id);
      expect(stringify(chart?.props?.datasets), id).toContain("query.metric || 'visits'");
      expect(chart?.if, id).toContain('.length > 0');
    }

    // 막대는 두 개뿐이다 — 하나가 사라지면 화면의 절반이 빈다.
    expect(nodesNamed(layout, 'BarChart')).toHaveLength(2);
  });

  // @scenario screen=patterns, state=unit_downgraded, viewport=1440, locale=ko
  // @effects patterns_warns_when_the_hour_range_is_beyond_retention
  it('선택 기간이 시간단위 보존기간보다 앞서면 안내를 띄운다', () => {
    const alert = nodeById(layout, 'hourly_range_alert');
    expect(alert?.if).toContain('hourly_available_from');
    expect(alert?.if).toContain('_computed.periodStart');
    expect(alert?.props?.className).toContain('alert-info');
  });

  // @scenario screen=patterns, state=normal, viewport=390, locale=ko
  // @effects patterns_screen_has_no_csv_button
  it('내려받을 표가 없는 화면이라 CSV 주소를 비워 버튼을 감춘다', () => {
    // 기간 바는 `csvUrl` 이 있을 때만 버튼을 그린다 — 빈 문자열이 곧 「이 화면엔 CSV 없음」이다.
    expect(layout.computed?.csvUrl).toBe("{{''}}");
    expect(layout.initLocal?.showUnit).toBe(false);
  });
});
