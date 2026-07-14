/**
 * @file admin-ecommerce-settings-tab-scoped-datasources.test.tsx
 * @description 환경설정 데이터소스 탭 스코프 게이팅 회귀 가드
 *
 * 배경: 환경설정 진입 시 모든 탭의 데이터소스를 한꺼번에 fetch 했다(18건). 탭 본문은
 * `if` 로 게이트되어 비활성 탭은 렌더조차 되지 않으므로, 그 데이터는 아무도 읽지 않는
 * 순수 낭비다. 게다가 탭 전환(navigate replace:true → updateQueryParams)마다 전수 재fetch 되어
 * 요청이 2배(36건)로 불어나고 탭 클릭이 1.7초 blocking 됐다.
 *
 * 엔진의 기본 메커니즘: 데이터소스의 `if` 를 DataSourceManager.filterByCondition 이 평가해
 * fetch 대상에서 제외하고, TemplateApp.updateQueryParams 가 탭 전환 시 `if` 를 재평가해
 * 새 탭의 소스만 fetch 한다. 따라서 탭 전용 소스는 반드시 `if` 게이트를 가져야 하며,
 * 탭 전환은 navigate + replace:true 로 updateQueryParams 를 태워야 한다(replaceUrl 은
 * history 만 교체하므로 `if` 재평가가 일어나지 않음).
 */

import { describe, it, expect } from 'vitest';

const layout = require('../../../layouts/admin/admin_ecommerce_settings.json');

interface AnyJson { [k: string]: any }

/** 모든 탭이 공유하는 소스 (폼 본체) — 항상 fetch */
const SHARED_SOURCE_IDS = ['settings'];

/** 탭 전용 소스 → 소유 탭 */
const TAB_SCOPED: Record<string, string[]> = {
  seoStats: ['seo'],
  carriers: ['shipping'],
  ecommerceNotificationDefinitions: ['notification_definitions', 'mileage'],
  availableChannels: ['notification_definitions'],
  ecommerceIdentityPolicies: ['identity_policies'],
  ecommerceIdentityPurposes: ['identity_policies'],
  identityProviders: ['identity_policies'],
};

const sources: AnyJson[] = layout.data_sources;
const byId = (id: string) => sources.find(s => s.id === id);

describe('admin_ecommerce_settings — 데이터소스 탭 스코프 게이팅', () => {
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
    expect(s!.if.length).toBeGreaterThan(0);
  });

  it.each(Object.entries(TAB_SCOPED))('%s 의 if 가 소유 탭(%s)을 참조한다', (id, tabs) => {
    const cond: string = byId(id)!.if;
    for (const tab of tabs as string[]) {
      expect(cond).toContain(`'${tab}'`);
    }
    // 활성 탭 판정에 query.tab 과 _global 을 모두 사용해야 딥링크/클릭 양쪽에서 동작
    expect(cond).toContain('query.tab');
    expect(cond).toContain('activeEcommerceSettingsTab');
  });

  it('탭 전용 소스는 auto_fetch:false 로 죽여두지 않는다 (if 선택 시 fetch 되어야 함)', () => {
    for (const id of Object.keys(TAB_SCOPED)) {
      expect(byId(id)!.auto_fetch).not.toBe(false);
    }
  });

  // 탭 전환은 updateQueryParams 를 태워 `if` 를 재평가해야 한다.
  // replaceUrl 은 history.replaceState 만 하므로 새 탭의 소스가 영원히 로드되지 않는다.
  it('탭 전환은 navigate + replace:true 로 수행한다 (replaceUrl 금지)', () => {
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
        a => a?.handler === 'setState' && 'activeEcommerceSettingsTab' in (a.params ?? {})
      )
    );
    expect(tabSwitches.length).toBeGreaterThan(0);

    for (const seq of tabSwitches) {
      const acts = seq.params.actions as AnyJson[];
      expect(acts.some(a => a.handler === 'replaceUrl')).toBe(false);
      const nav = acts.find(a => a.handler === 'navigate');
      expect(nav).toBeTruthy();
      expect(nav!.params.replace).toBe(true);
      expect(nav!.params.query?.tab).toBeTruthy();
    }
  });
});
