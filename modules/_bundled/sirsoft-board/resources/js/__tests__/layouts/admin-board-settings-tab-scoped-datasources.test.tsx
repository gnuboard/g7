/**
 * @file admin-board-settings-tab-scoped-datasources.test.tsx
 * @description 게시판 설정 데이터소스 탭 스코프 게이팅 회귀 가드
 *
 * 이커머스 환경설정과 동일 결함: 진입 시 전 탭의 데이터소스를 한꺼번에 fetch 했다.
 * 탭 본문은 `if` 로 게이트되어 비활성 탭은 렌더되지 않으므로 그 데이터는 아무도 읽지 않는다.
 * 엔진(DataSourceManager.filterByCondition + TemplateApp.updateQueryParams)이
 * 데이터소스 `if` 로 탭별 로딩을 지원하므로, 탭 전용 소스는 `if` 게이트를 가져야 한다.
 */

import { describe, it, expect } from 'vitest';

const layout = require('../../../layouts/admin/admin_board_settings.json');

interface AnyJson { [k: string]: any }

const SHARED_SOURCE_IDS = ['settings'];

const TAB_SCOPED: Record<string, string[]> = {
  boards_list: ['basic_defaults'],
  roles: ['basic_defaults', 'report_policy'],
  board_types: ['basic_defaults'],
  boardNotificationDefinitions: ['notification_definitions', 'report_policy'],
  availableChannels: ['notification_definitions'],
  boardIdentityPolicies: ['identity_policies'],
  boardIdentityPurposes: ['identity_policies'],
  identityProviders: ['identity_policies'],
};

const sources: AnyJson[] = layout.data_sources;
const byId = (id: string) => sources.find(s => s.id === id);

describe('admin_board_settings — 데이터소스 탭 스코프 게이팅', () => {
  it('공유 소스(settings)는 if 게이트 없이 항상 fetch 된다', () => {
    for (const id of SHARED_SOURCE_IDS) {
      const s = byId(id);
      expect(s).toBeTruthy();
      expect(s!.if).toBeUndefined();
      expect(s!.auto_fetch).not.toBe(false);
    }
  });

  it.each(Object.keys(TAB_SCOPED))('탭 전용 소스 %s 는 if 게이트를 가진다', (id) => {
    const s = byId(id);
    expect(s).toBeTruthy();
    expect(typeof s!.if).toBe('string');
  });

  it.each(Object.entries(TAB_SCOPED))('%s 의 if 가 소유 탭(%s)을 참조한다', (id, tabs) => {
    const cond: string = byId(id)!.if;
    for (const tab of tabs as string[]) {
      expect(cond).toContain(`'${tab}'`);
    }
    expect(cond).toContain('query.tab');
    expect(cond).toContain('activeBoardSettingsTab');
  });

  it('탭 전용 소스를 auto_fetch:false 로 죽여두지 않는다', () => {
    for (const id of Object.keys(TAB_SCOPED)) {
      expect(byId(id)!.auto_fetch).not.toBe(false);
    }
  });

  it('탭 전환은 navigate + replace:true 로 수행한다 (if 재평가 필요)', () => {
    const seqs: AnyJson[] = [];
    const walk = (n: any) => {
      if (!n || typeof n !== 'object') return;
      if (Array.isArray(n)) { n.forEach(walk); return; }
      if (n.handler === 'sequence' && Array.isArray(n.params?.actions)) seqs.push(n);
      Object.values(n).forEach(walk);
    };
    walk(layout);

    const tabSwitches = seqs.filter(s =>
      (s.params.actions as AnyJson[]).some(
        a => a?.handler === 'setState' && 'activeBoardSettingsTab' in (a.params ?? {})
      )
    );
    expect(tabSwitches.length).toBeGreaterThan(0);

    for (const seq of tabSwitches) {
      const acts = seq.params.actions as AnyJson[];
      expect(acts.some(a => a.handler === 'replaceUrl')).toBe(false);
      const nav = acts.find(a => a.handler === 'navigate');
      expect(nav).toBeTruthy();
      expect(nav!.params.replace).toBe(true);
    }
  });
});
