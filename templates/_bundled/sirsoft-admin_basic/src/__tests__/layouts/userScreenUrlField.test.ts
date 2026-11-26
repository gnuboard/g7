/**
 * @file userScreenUrlField.test.ts
 * @description 환경설정 > 일반 「사용자 화면 URL」 칸 계약
 *
 * 잠그는 것: 칸이 `general.user_screen_url` 로 자동 바인딩되고 url 입력이며, 칸 오류가 서버 검증 오류를 그대로 보여 주고,
 * 도움말·라벨 키가 모든 로케일에 있는 것.
 */

import { describe, it, expect } from 'vitest';
import { loadLayout, localeNames, loadLocale, langValue, nodeById, collectObjects, translationKeys } from './analyticsLayoutTestUtils';

const tab = loadLayout('partials/admin_settings/_tab_general.json');

describe('환경설정 일반 탭 — 사용자 화면 URL', () => {
  /**
   * @scenario case=user_screen_url_field
   * @effects user_screen_url_bound_with_field_error
   */
  it('url 입력이 general.user_screen_url 에 바인딩되고 칸 오류를 보여 준다', () => {
    const field = nodeById(tab, 'field_user_screen_url');
    expect(field, '칸 노드').toBeTruthy();

    const input = collectObjects(field).find((o) => o.name === 'Input');
    expect(input?.props?.name).toBe('general.user_screen_url');
    expect(input?.props?.type).toBe('url');
    expect(String(input?.props?.className ?? '')).toContain("_local.errors?.['general.user_screen_url']");

    const error = collectObjects(field).find((o) => String(o.if ?? '').includes("_local.errors?.['general.user_screen_url']"));
    expect(error?.text).toContain("_local.errors?.['general.user_screen_url']?.[0]");

    // 사이트 URL 칸 바로 다음(사이트 정보 카드 안)에 있다
    const card = nodeById(tab, 'card_site_info');
    const ids = collectObjects(card).map((o) => o.id).filter(Boolean);
    expect(ids.indexOf('field_user_screen_url')).toBeGreaterThan(ids.indexOf('field_site_url'));
    expect(ids.indexOf('field_user_screen_url')).toBeLessThan(ids.indexOf('field_site_description'));
  });

  /**
   * @scenario case=user_screen_url_field
   * @effects user_screen_url_keys_all_locales
   */
  it('라벨·도움말 키가 모든 로케일에 있다', () => {
    const keys = translationKeys(nodeById(tab, 'field_user_screen_url'));
    expect(keys).toEqual(expect.arrayContaining(['admin.settings.general.user_screen_url', 'admin.settings.general.user_screen_url_help']));
    for (const locale of localeNames()) {
      const tree = loadLocale(locale, 'admin');
      expect(keys.filter((k) => typeof langValue(tree, k) !== 'string'), `${locale} 누락 키`).toEqual([]);
    }
  });
});
