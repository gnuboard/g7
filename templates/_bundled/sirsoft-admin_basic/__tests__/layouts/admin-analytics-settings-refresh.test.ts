/**
 * @file admin-analytics-settings-refresh.test.ts
 * @description 분석 설정 「새로고침」 — 고친 값을 버리고 서버 값으로 다시 채운다 (레이아웃 JSON 구조 검증)
 *
 * 배경(브라우저 실측 2026-10-05, #736): 칸을 고친 뒤 새로고침을 누르면 같은 응답이 다시 와도 엔진이
 * 이미 적용한 데이터로 보고 건너뛰어(`_localInit` 해시 동일), 칸은 고친 값 그대로이고 저장 버튼도 켜진
 * 채였다. 데이터 소스에 `refetchOnMount` 를 달면 다시 받기마다 강제 재적용되지만, 탭을 오갈 때도 다시 받아
 * 「여러 탭을 고쳐 두고 한 번에 저장」하는 이 화면의 흐름이 깨진다(실측: 탭 왕복에 37 → 30). 그래서 새로고침
 * 시나리오: templates/_bundled/sirsoft-admin_basic/tests/scenarios/analytics-settings-refresh.yaml
 *
 * 버튼만 서버 설정을 받아 폼을 직접 채우고, 폼 감싸개를 다시 그려(remount) 감싸개가 들고 있던 고친 값을 버린다.
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const LAYOUTS_BASE = path.resolve(__dirname, '../../layouts');

const layout = JSON.parse(fs.readFileSync(path.resolve(LAYOUTS_BASE, 'admin_analytics_settings.json'), 'utf-8'));
const settingsSource = (layout.data_sources ?? []).find((d: any) => d.id === 'analytics_settings');

/**
 * 트리에서 id(마크업의 `data-ref`)가 같은 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 id
 * @returns 노드 또는 undefined
 */
function findById(node: any, id: string): any {
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = findById(child, id);
      if (hit) return hit;
    }
    return undefined;
  }
  if (!node || typeof node !== 'object') return undefined;
  if (node.id === id) return node;
  for (const value of Object.values(node)) {
    const hit = findById(value, id);
    if (hit) return hit;
  }
  return undefined;
}

describe('[case:state-global-refresh-same-payload-skip] admin_analytics_settings.json — 새로고침이 서버 값을 다시 채운다', () => {
  /**
   * @scenario action=tab_roundtrip
   *
   * @effects tab_roundtrip_keeps_edits
   */
  it('설정 데이터 소스는 폼 초기값(form)을 담되, 탭 전환에 다시 받지 않는다 (탭별 편집 보존)', () => {
    expect(settingsSource?.initLocal).toBe('form');
    expect(settingsSource?.refetchOnMount).toBeUndefined();
  });

  /**
   * @scenario action=refresh
   *
   * @effects refresh_disables_save
   */
  it('새로고침 버튼은 변경 표시를 걷고 같은 데이터 소스를 다시 받는다', () => {
    const button = findById(layout.components ?? layout, 'refresh_button');
    const json = JSON.stringify(button?.actions ?? []);

    expect(json).toContain('"hasChanges":false');
    expect(json).toContain('"dataSourceId":"analytics_settings"');
  });

  /**
   * @scenario action=refresh
   *
   * @effects refresh_restores_server_values
   */
  it('새로고침 버튼은 서버 설정을 받아 폼을 그 응답으로 직접 채운다', () => {
    const button = findById(layout.components ?? layout, 'refresh_button');
    const sequence = (button?.actions ?? [])[0]?.actions ?? [];
    const apiCall = sequence.find((a: any) => a.handler === 'apiCall');

    expect(apiCall?.target).toBe('/api/admin/analytics/settings');
    expect(apiCall?.params?.method).toBe('GET');
    const fill = (apiCall?.onSuccess ?? []).find((a: any) => a.handler === 'setState');
    expect(fill?.params?.target).toBe('local');
    expect(fill?.params?.form).toBe('{{response?.data}}');
    expect(fill?.params?.hasChanges).toBe(false);
    // 감싸개가 들고 있는 고친 값을 버려야 칸·저장 버튼이 새 값을 읽는다 (실측: remount 없으면 37·저장 켜짐 잔존)
    const remount = (apiCall?.onSuccess ?? []).find((a: any) => a.handler === 'remount');
    expect(remount?.params?.componentId).toBe('analytics_settings_content');
    expect(findById(layout.components ?? layout, 'analytics_settings_content')).toBeDefined();
  });
});
