/**
 * @file admin-settings-language-pack-scope-leak.test.tsx
 * @description 환경설정 통합 화면 — 정책/언어팩 데이터소스가 같은 query.scope 를 공유해 양방향으로
 *              422 토스트를 유발하던 회귀 (#415)
 *
 * 버그 (#415):
 *   admin_settings.json 에 정책(policies)·언어팩(language_packs) 데이터소스가 공존하며 둘 다
 *   query.scope 를 읽는다. 두 데이터소스의 scope 값 도메인이 다르다:
 *     - policies      : route / hook / custom
 *     - language_packs: core / module / plugin / template
 *   두 데이터소스가 if 없이 항상 fetch 되므로, 한 탭에서 건 필터의 scope 가 반대쪽 데이터소스로
 *   실려 422 ("선택한 scope이(가) 올바르지 않습니다.") 토스트가 양방향으로 발생했다.
 *     - 본인인증 탭 scope=hook  → 언어팩 API 422
 *     - 언어팩 탭   scope=module → 정책 API 422
 *
 * 수정 (3안 — 데이터소스 활성 탭 if):
 *   각 데이터소스에 활성 탭 if 를 부여해 같은 탭에서 동시에 fetch 되지 않게 한다.
 *     - policies      : if = 활성 탭 === 'identity'
 *     - language_packs: if = 활성 탭 === 'language_packs'
 *   탭 전환은 navigate(?tab=X) 를 동반하므로 if 가 재평가되어 해당 탭 데이터소스만 fetch 된다.
 *
 * 검증 방식:
 *   admin_settings.json 의 data_sources 를 실제 DataSourceManager.filterByCondition 에 통과시켜
 *   탭별 선택 결과를 단언한다. policies 와 language_packs 가 어떤 탭에서도 동시에 선택되지 않음을 확인.
 */

import { describe, it, expect } from 'vitest';
import { DataSourceManager } from '@core/template-engine/DataSourceManager';

const adminSettings = require('../../layouts/admin_settings.json');

/**
 * 활성 탭 컨텍스트로 filterByCondition 을 수행해 선택된 데이터소스 id 집합 반환.
 *
 * @param tab        URL query.tab (navigate 가 갱신 — 항상 정확)
 * @param extraQuery 추가 query 파라미터
 * @param globalTab  _global.activeSettingsTab (탭 클릭 전환 시 navigate 시점에 stale 할 수 있음).
 *                   미지정 시 tab 과 동일(새로고침/직접진입 경로).
 */
function selectedSourceIds(
  tab: string,
  extraQuery: Record<string, any> = {},
  globalTab: string = tab,
): Set<string> {
  const mgr = new DataSourceManager();
  const sources = adminSettings.data_sources ?? [];
  const ctx = {
    query: { tab, ...extraQuery },
    route: {},
    _global: { activeSettingsTab: globalTab },
  };
  const selected = mgr.filterByCondition(sources as any, ctx as any);
  return new Set(selected.map((s: any) => s.id));
}

describe('환경설정 — 정책/언어팩 데이터소스 탭 격리 (#415)', () => {
  it('본인인증 탭에서는 policies 만 선택되고 language_packs 는 제외된다 (scope=hook 누출 차단)', () => {
    const ids = selectedSourceIds('identity', { scope: 'hook' });
    expect(ids.has('policies'), 'policies 는 본인인증 탭에서 fetch 되어야 함').toBe(true);
    expect(ids.has('language_packs'), 'language_packs 는 본인인증 탭에서 fetch 되면 안 됨 (scope=hook 누출)').toBe(false);
  });

  it('언어팩 탭에서는 language_packs 만 선택되고 policies 는 제외된다 (scope=module 누출 차단)', () => {
    const ids = selectedSourceIds('language_packs', { scope: 'module' });
    expect(ids.has('language_packs'), 'language_packs 는 언어팩 탭에서 fetch 되어야 함').toBe(true);
    expect(ids.has('policies'), 'policies 는 언어팩 탭에서 fetch 되면 안 됨 (scope=module 누출)').toBe(false);
  });

  it('어떤 탭에서도 policies 와 language_packs 가 동시에 선택되지 않는다 (양방향 누출 원천 차단)', () => {
    const tabs = ['general', 'mail', 'identity', 'language_packs', 'info', 'security'];
    for (const tab of tabs) {
      const ids = selectedSourceIds(tab);
      const both = ids.has('policies') && ids.has('language_packs');
      expect(both, `탭 "${tab}" 에서 policies 와 language_packs 가 동시에 fetch 되면 안 됨`).toBe(false);
    }
  });

  // 탭 클릭 전환: navigate 는 query.tab 을 갱신하지만, 그 시점의 _global.activeSettingsTab 은
  // 직전 탭 값으로 stale 할 수 있다. 데이터소스 if 는 query.tab 을 우선해야 올바른 탭으로 평가된다.
  // (회귀: _global 우선 시 본인인증→언어팩 클릭 전환에서 언어팩이 fetch 되지 않고 정책이 잘못 fetch 됨)
  it('본인인증→언어팩 탭 클릭 전환(query.tab=language_packs, _global=identity stale)에서 language_packs 만 선택', () => {
    const ids = selectedSourceIds('language_packs', { scope: 'module' }, 'identity');
    expect(ids.has('language_packs'), 'query.tab=language_packs 면 stale _global 과 무관하게 언어팩이 fetch 되어야 함').toBe(true);
    expect(ids.has('policies'), 'stale _global=identity 때문에 정책이 fetch 되면 안 됨').toBe(false);
  });

  it('언어팩→본인인증 탭 클릭 전환(query.tab=identity, _global=language_packs stale)에서 policies 만 선택', () => {
    const ids = selectedSourceIds('identity', { scope: 'hook' }, 'language_packs');
    expect(ids.has('policies'), 'query.tab=identity 면 stale _global 과 무관하게 정책이 fetch 되어야 함').toBe(true);
    expect(ids.has('language_packs'), 'stale _global=language_packs 때문에 언어팩이 fetch 되면 안 됨').toBe(false);
  });

  it('일반 탭(general)에서는 policies/language_packs 둘 다 제외된다 (불필요 fetch 방지)', () => {
    const ids = selectedSourceIds('general');
    expect(ids.has('policies')).toBe(false);
    expect(ids.has('language_packs')).toBe(false);
  });

  it('policies 데이터소스 정의에 활성 탭 if(identity) 가 존재한다', () => {
    const policies = (adminSettings.data_sources ?? []).find((s: any) => s.id === 'policies');
    expect(policies).toBeTruthy();
    expect(typeof policies.if).toBe('string');
    expect(policies.if).toMatch(/identity/);
  });

  it('language_packs 데이터소스 정의에 활성 탭 if(language_packs) 가 존재한다', () => {
    const lp = (adminSettings.data_sources ?? []).find((s: any) => s.id === 'language_packs');
    expect(lp).toBeTruthy();
    expect(typeof lp.if).toBe('string');
    expect(lp.if).toMatch(/language_packs/);
  });
});
