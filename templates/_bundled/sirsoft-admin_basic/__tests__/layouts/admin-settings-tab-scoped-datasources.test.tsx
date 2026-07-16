/**
 * @file admin-settings-tab-scoped-datasources.test.tsx
 * @description 코어 환경설정 데이터소스 탭 스코프 게이팅 회귀 가드
 *
 * 이커머스/게시판 환경설정과 동일 결함: 진입 시 전 탭의 데이터소스를 한꺼번에 fetch 했다.
 * 이 레이아웃은 이미 language_packs / policies 두 소스에만 `if` 게이트를 적용하고 있었다
 * (엔진의 기본 패턴). 나머지 탭 전용 소스에도 동일 게이트를 적용한다.
 */

import { describe, it, expect } from 'vitest';

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
  identityPurposes: ['identity'],
  identityMessages: ['identity'],
  adminIdentityPolicies: ['identity'],
  policies: ['identity'],
};

const sources: AnyJson[] = layout.data_sources;
const byId = (id: string) => sources.find(s => s.id === id);

describe('admin_settings — 데이터소스 탭 스코프 게이팅', () => {
  it('공유 소스(settings)는 if 게이트 없이 항상 fetch 된다', () => {
    for (const id of SHARED_SOURCE_IDS) {
      const s = byId(id);
      expect(s).toBeTruthy();
      expect(s!.if).toBeUndefined();
      expect(s!.auto_fetch).not.toBe(false);
    }
  });

  it('모달 상세 소스는 auto_fetch:false 지연 로딩을 유지한다', () => {
    for (const id of LAZY_DETAIL_IDS) {
      expect(byId(id)!.auto_fetch).toBe(false);
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
    expect(cond).toContain('activeSettingsTab');
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
        a => a?.handler === 'setState' && 'activeSettingsTab' in (a.params ?? {})
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
