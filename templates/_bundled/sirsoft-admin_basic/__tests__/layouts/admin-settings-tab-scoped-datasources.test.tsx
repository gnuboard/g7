/**
 * @file admin-settings-tab-scoped-datasources.test.tsx
 * @description 코어 환경설정 데이터소스 탭 스코프 게이팅 회귀 가드
 *
 * 이커머스/게시판 환경설정과 동일 결함: 진입 시 전 탭의 데이터소스를 한꺼번에 fetch 했다.
 * 이 레이아웃은 이미 language_packs / policies 두 소스에만 `if` 게이트를 적용하고 있었다
 * (엔진의 기본 패턴). 나머지 탭 전용 소스에도 동일 게이트를 적용한다.
 */

import { describe, it, expect } from 'vitest';
import { asArray, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../layouts/admin_settings.json');

interface AnyJson { [k: string]: any }

const SHARED_SOURCE_IDS = ['settings'];

/** 모달 상세용 지연 소스 — 탭이 아니라 _local.detailId 로 트리거 (auto_fetch:false 유지) */
const LAZY_DETAIL_IDS = ['language_pack_detail', 'language_pack_changelog'];

const TAB_SCOPED: Record<string, string[]> = {
  language_packs: ['language_packs'],
  systemInfo: ['info'],
  appKey: ['security'],
  notificationDefinitions: ['notification_definitions'],
  availableChannels: ['notification_definitions'],
  identityProviders: ['identity'],
  identityPurposes: ['identity', 'notification_definitions'],
  // 본인인증 정책별 문구(#699) — 알림 설정 탭의 「정책별 문구 추가」 창이 쓴다. 운영자 정책 목록은 두 탭이 함께 쓴다.
  identityPolicyDefinitions: ['notification_definitions'],
  adminIdentityPolicies: ['identity', 'notification_definitions'],
  policies: ['identity'],
  sitemap_status: ['seo'],
  staticCacheStatus: ['general'],
};

const sources: AnyJson[] = layout.data_sources;
const byId = (id: string) => sources.find(s => s.id === id);

describe('admin_settings — 데이터소스 탭 스코프 게이팅', () => {
  /**
   * @scenario source_kind=shared
   * @effects shared_settings_source_always_fetched
   */
  it('공유 소스(settings)는 if 게이트 없이 항상 fetch 된다', () => {
    for (const id of SHARED_SOURCE_IDS) {
      const s = byId(id);
      expect(s).toBeTruthy();
      expect(s!.if).toBeUndefined();
      expect(s!.auto_fetch).not.toBe(false);
    }
  });

  /**
   * @scenario source_kind=lazy_detail
   * @effects modal_detail_sources_stay_lazy
   */
  it('모달 상세 소스는 auto_fetch:false 지연 로딩을 유지한다', () => {
    for (const id of LAZY_DETAIL_IDS) {
      expect(byId(id)!.auto_fetch).toBe(false);
    }
  });

  /**
   * @scenario source_kind=tab_scoped
   * @effects tab_scoped_sources_have_if_gate
   */
  it.each(Object.keys(TAB_SCOPED))('탭 전용 소스 %s 는 if 게이트를 가진다', (id) => {
    const s = byId(id);
    expect(s).toBeTruthy();
    expect(typeof s!.if).toBe('string');
  });

  /**
   * @scenario source_kind=tab_scoped
   * @effects tab_scoped_source_gate_references_owner_tabs
   */
  it.each(Object.entries(TAB_SCOPED))('%s 의 if 가 소유 탭(%s)을 참조한다', (id, tabs) => {
    const cond: string = byId(id)!.if;
    for (const tab of tabs as string[]) {
      expect(cond).toContain(`'${tab}'`);
    }
    expect(cond).toContain('query.tab');
    expect(cond).toContain('activeSettingsTab');
  });

  /**
   * @scenario source_kind=tab_scoped
   * @effects tab_scoped_sources_not_disabled
   */
  it('탭 전용 소스를 auto_fetch:false 로 죽여두지 않는다', () => {
    for (const id of Object.keys(TAB_SCOPED)) {
      expect(byId(id)!.auto_fetch).not.toBe(false);
    }
  });

  /**
   * @scenario source_kind=tab_scoped
   * @effects tab_switch_navigates_with_replace
   */
  it('탭 전환은 navigate + replace:true 로 수행한다 (if 재평가 필요)', () => {
    const seqs: AnyJson[] = [];
    const walk = (n: any) => {
      if (!n || typeof n !== 'object') return;
      if (Array.isArray(n)) { n.forEach(walk); return; }
      if (n.handler === 'sequence' && callbacksOf(n, 'actions').length > 0) seqs.push(n);
      Object.values(n).forEach(walk);
    };
    walk(layout);

    const tabSwitches = seqs.filter(s =>
      (callbacksOf(s, 'actions') as AnyJson[]).some(
        a => a?.handler === 'setState' && 'activeSettingsTab' in (a.params ?? {})
      )
    );
    expect(tabSwitches.length).toBeGreaterThan(0);

    for (const seq of tabSwitches) {
      const acts = callbacksOf(seq, 'actions') as AnyJson[];
      expect(acts.some(a => a.handler === 'replaceUrl')).toBe(false);
      const nav = acts.find(a => a.handler === 'navigate');
      expect(nav).toBeTruthy();
      expect(nav!.params.replace).toBe(true);
    }
  });

  /**
   * 주소의 탭 키가 탭 목록에 없으면(`?tab=notifications` 처럼 저장 묶음 이름·오래된 즐겨찾기) 모든 탭 본문의 게이트가
   * 거짓이 되어 본문이 통째로 비고 선택된 탭도 없다(7.1.0 실측 O-6). 진입 때 기본 탭으로 바꿔 연다.
   *
   * @scenario source_kind=tab_scoped
   * @effects unknown_tab_key_falls_back_to_general
   */
  it('탭 목록에 없는 탭 키로 들어오면 기본 탭(general)으로 바꿔 연다', () => {
    const findById = (n: any, id: string): AnyJson | undefined => {
      if (!n || typeof n !== 'object') return undefined;
      if (Array.isArray(n)) { for (const c of n) { const f = findById(c, id); if (f) return f; } return undefined; }
      if (n.id === id) return n;
      for (const v of Object.values(n)) { const f = findById(v, id); if (f) return f; }
      return undefined;
    };
    const tabIds: string[] = (findById(layout.slots, 'tab_navigation')?.props?.tabs ?? []).map((t: AnyJson) => t.id);
    expect(tabIds).toContain('general');
    expect(tabIds).toContain('notification_definitions');

    // 초기화 액션은 sequence 를 쓸 수 없어(init_action_key_forbidden) 같은 게이트를 두 액션에 건다.
    const gated = (layout.init_actions as AnyJson[]).filter(a => String(a.if ?? '').includes('query.tab'));
    const setTab = gated.find(a => a.handler === 'setState');
    const nav = gated.find(a => a.handler === 'navigate');
    expect(setTab, '알 수 없는 탭 키 폴백 — 전역 탭 정정').toBeTruthy();
    expect(nav, '알 수 없는 탭 키 폴백 — 주소 정정').toBeTruthy();
    expect(setTab!.if).toBe(nav!.if);

    // 게이트의 「아는 탭」 목록이 탭 바와 정확히 같아야 한다 — 탭을 더하고 여기 빠뜨리면 그 탭으로 못 들어간다.
    const listed = [...String(nav!.if).matchAll(/'([a-z_]+)'/g)].map(m => m[1]);
    expect([...listed].sort()).toEqual([...tabIds].sort());

    expect(setTab!.params?.target).toBe('global');
    expect(setTab!.params?.activeSettingsTab).toBe('general');
    expect(nav!.params?.path).toBe('/admin/settings');
    expect(nav!.params?.replace).toBe(true);
    expect(nav!.params?.query?.tab).toBe('general');
  });
});
