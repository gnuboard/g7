/**
 * @file languageCurrencyTab.test.tsx
 * @description 언어·통화 탭 기본언어 필드 제거 구조 회귀 테스트 (A1-⑤, D-LANG)
 *
 * 배경:
 * - 모듈 default_language 는 읽는 코드 0(orphan). 사이트 언어는 코어 일반설정으로 일원화.
 * - "언어·통화" 탭에서 기본 언어 필드(field_default_language)를 제거하고 기본 화폐만 남긴다.
 *
 * 회귀 차단:
 * - field_default_language 블록 부재 (Select name=language_currency.default_language 미사용).
 * - field_default_currency 유지(비회귀).
 * - 탭 식별자(language_currency)·query.tab 게이트 유지(라우팅 보존).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const tabPath = path.resolve(
  __dirname,
  '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_language_currency.json'
);
const tabText = fs.readFileSync(tabPath, 'utf8');
const tab = JSON.parse(tabText);

describe('A1-⑤ — 언어·통화 탭 기본언어 필드 제거', () => {
  it('field_default_language 블록이 제거되었다', () => {
    expect(tabText).not.toContain('field_default_language');
    expect(tabText).not.toContain('language_currency.default_language');
  });

  it('field_default_currency 는 유지된다(비회귀)', () => {
    expect(tabText).toContain('field_default_currency');
    expect(tabText).toContain('language_currency.default_currency');
  });

  it('탭 식별자/게이트(language_currency)는 유지된다(라우팅 보존)', () => {
    expect(tab.id).toBe('tab_content_language_currency');
    expect(JSON.stringify(tab.if)).toContain("'language_currency'");
  });

  it('환율 설정 카드(exchange_settings_card)는 유지된다', () => {
    expect(tabText).toContain('exchange_settings_card');
  });
});
