/**
 * @file admin-extension-activation-version-mismatch.test.ts
 * @description 활성화 경고 창이 의존 확장의 「버전 미달」을 사유와 함께 보인다 (코어 7.1.4, #819)
 *
 * 코어가 모듈·플러그인 활성화 때 의존 확장의 버전 제약까지 보게 되면서 미충족 목록에 `status: 'version_mismatch'`
 * (설치 버전·요구 버전 포함) 항목이 온다. 창이 이 상태를 몰라 배지 없이 이름만 보이면, 이미 설치·활성인 확장이 왜
 * 「필요한 확장」으로 떴는지 알 수 없다.
 */

import { describe, it, expect } from 'vitest';
import moduleForceActivate from '../../layouts/partials/admin_module_list/_modal_force_activate.json';
import pluginForceActivate from '../../layouts/partials/admin_plugin_list/_modal_force_activate.json';
import ko from '../../lang/partial/ko/admin.json';
import en from '../../lang/partial/en/admin.json';

/** 레이아웃 JSON 안의 모든 노드를 모은다. */
function nodes(tree: unknown): Array<Record<string, unknown>> {
  const out: Array<Record<string, unknown>> = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) n.forEach(walk);
    else if (n && typeof n === 'object') {
      out.push(n as Record<string, unknown>);
      Object.values(n).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

/** `if` 식에 주어진 조각이 들어 있는 노드의 글자 전부. */
function textUnder(tree: unknown, condition: string): string {
  const hit = nodes(tree).find((n) => typeof n.if === 'string' && (n.if as string).includes(condition));
  expect(hit, `조건 ${condition} 를 가진 노드가 있어야 합니다`).toBeDefined();
  return JSON.stringify(hit);
}

describe('활성화 경고 창의 버전 미달 배지', () => {
  /**
   * @effects activation_modal_shows_version_mismatch_badge
   */
  it('모듈 목록 창: 모듈·플러그인 의존 모두 버전 미달 배지에 설치·요구 버전을 보인다', () => {
    const moduleBadge = textUnder(moduleForceActivate, "module.status === 'version_mismatch'");
    expect(moduleBadge).toContain('$t:admin.modules.status.version_mismatch');
    expect(moduleBadge).toContain('module.installed_version');
    expect(moduleBadge).toContain('module.required_version');
    expect(textUnder(moduleForceActivate, "plugin.status === 'version_mismatch'")).toContain('$t:admin.plugins.status.version_mismatch');
  });

  /**
   * @effects activation_modal_shows_version_mismatch_badge
   */
  it('플러그인 목록 창: 같은 배지를 보인다', () => {
    expect(textUnder(pluginForceActivate, "module.status === 'version_mismatch'")).toContain('module.required_version');
    expect(textUnder(pluginForceActivate, "plugin.status === 'version_mismatch'")).toContain('plugin.installed_version');
  });

  /**
   * @effects activation_modal_version_mismatch_label_translated
   */
  it('배지 문구가 ko·en 에 있다', () => {
    for (const lang of [ko, en] as Array<Record<string, any>>) {
      expect(typeof lang.modules.status.version_mismatch).toBe('string');
      expect(typeof lang.plugins.status.version_mismatch).toBe('string');
    }
  });
});
