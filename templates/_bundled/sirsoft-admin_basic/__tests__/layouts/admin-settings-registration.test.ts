/**
 * @file admin-settings-registration.test.ts
 * @description 환경설정 > 보안 「회원가입」 카드 회귀 테스트 (sirsoft-admin_basic, 7.2.0 A3t·K9)
 *
 * 보안 탭 첫 카드가 「회원가입」이고, 「일반 회원가입 받기」 토글이 설정 `security.registration_enabled` 를 저장한다.
 * 토글은 자동 바인딩으로 checked(boolean)를 싣는다 — 화면이 값을 문자열로 바꾸는 식을 끼우지 않는지, 서버 기본값·노출 스키마·저장 규칙이
 * 모두 boolean 인지 세 지점을 대조한다(어긋나면 빈 문자열이 실려 null 로 굳거나 부분 저장이 필수 검사에 걸린다).
 *
 * @since 1.2.0
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';

import tabSecurity from '../../layouts/partials/admin_settings/_tab_security.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

type Json = Record<string, any>;

/**
 * 저장소 루트를 위로 훑어 찾는다 (`_bundled` 여부로 깊이가 달라지므로 상대경로 고정 금지).
 *
 * @returns 저장소 루트 절대경로
 */
function repositoryRoot(): string {
  let current = path.dirname(fileURLToPath(import.meta.url));
  for (let depth = 0; depth < 10; depth++) {
    if (fs.existsSync(path.join(current, 'config/core.php'))) return current;
    current = path.dirname(current);
  }
  throw new Error('config/core.php 를 가진 저장소 루트를 찾지 못했습니다.');
}

/**
 * 트리를 평탄화합니다.
 *
 * @param node 노드
 * @returns 노드 목록
 */
function flatten(node: any): Json[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  return [node, ...children];
}

/**
 * dot 경로로 lang 값을 읽습니다.
 *
 * @param lang lang JSON
 * @param path 경로
 * @returns 값
 */
function langAt(lang: Json, path: string): unknown {
  return path.split('.').reduce<any>((acc, key) => (acc == null ? acc : acc[key]), lang);
}

const root = repositoryRoot();
const tab = tabSecurity as Json;
const card = flatten(tab).find((n) => n.id === 'card_registration');
const toggle = flatten(card).find((n) => n.name === 'Toggle');

describe('sirsoft-admin_basic 보안 탭 「회원가입」 카드 (A3t)', () => {
  /**
   * @scenario surface=security_tab
   * @effects registration_card_is_first
   */
  it('보안 탭의 첫 카드가 「회원가입」 이다', () => {
    expect(tab.id).toBe('tab_content_security');
    expect(tab.children?.[0]?.id).toBe('card_registration');
    expect(flatten(card).map((n) => n.text)).toContain('$t:admin.settings.security.registration.title');
  });

  /**
   * @scenario surface=security_tab, value=true|false
   * @effects registration_toggle_binds_boolean_setting
   */
  it('「일반 회원가입 받기」 토글이 security.registration_enabled 를 boolean 으로 저장한다', () => {
    expect(toggle?.type).toBe('composite');
    expect(toggle?.props?.name).toBe('security.registration_enabled');
    // 자동 바인딩(checked boolean)을 그대로 쓴다 — 값을 바꾸는 식·바인딩 끄기가 없다
    expect(toggle?.props?.autoBinding).toBeUndefined();
    expect(toggle?.props?.value).toBeUndefined();
    expect(toggle?.props?.checked).toBeUndefined();
    expect(toggle?.props?.disabled).toBe('{{_computed.isReadOnly}}');

    const texts = flatten(card).map((n) => n.text);
    expect(texts).toContain('$t:admin.settings.security.registration.enabled');
    expect(texts).toContain('$t:admin.settings.security.registration.enabled_desc');
    const error = flatten(card).find((n) => typeof n.if === 'string' && n.if.includes("'security.registration_enabled'"));
    expect(error?.text).toContain("_local.errors?.['security.registration_enabled']");

    const defaults = JSON.parse(fs.readFileSync(path.join(root, 'config/settings/defaults.json'), 'utf-8'));
    expect(defaults.defaults?.security?.registration_enabled).toBe(true);
    expect(defaults.frontend_schema?.security?.fields?.registration_enabled).toEqual({ type: 'boolean', sensitive: false });

    const request = fs.readFileSync(path.join(root, 'app/Http/Requests/Settings/SaveSettingsRequest.php'), 'utf-8');
    expect(request).toMatch(/'security\.registration_enabled'\s*=>\s*\[\s*'nullable'\s*,\s*'boolean'\s*\]/);
  });

  /**
   * @scenario locale=ko|en
   * @effects registration_lang_keys_present
   */
  it.each([
    ['ko', adminKo],
    ['en', adminEn],
  ])('%s lang 에 admin.settings.security.registration.* 문구가 있다', (_locale, lang) => {
    for (const key of ['title', 'enabled', 'enabled_desc']) {
      expect(typeof langAt(lang as Json, `settings.security.registration.${key}`), key).toBe('string');
    }
  });
});
