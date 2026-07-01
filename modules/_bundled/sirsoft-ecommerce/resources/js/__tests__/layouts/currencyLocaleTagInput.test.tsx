/**
 * @file currencyLocaleTagInput.test.tsx
 * @description 통화별 언어 Tag 입력 구조 회귀 테스트 (A4)
 *
 * 회귀 차단:
 * - 환율 카드/테이블 각 통화 행에 locales TagInput 이 존재한다.
 * - TagInput value=currency.locales, options=supported_locales 매핑.
 * - name=language_currency.currencies.{idx}.locales (저장 경로).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(
  __dirname,
  '../../../layouts/admin/partials/admin_ecommerce_settings'
);

function load(name: string): string {
  return fs.readFileSync(path.resolve(baseDir, name), 'utf8');
}

describe.each([
  ['환율 카드(모바일)', '_currency_exchange_cards.json'],
  ['환율 테이블(데스크탑)', '_currency_exchange_table.json'],
])('A4 — 통화별 언어 TagInput — %s', (_label, file) => {
  const text = load(file);

  it('locales TagInput 컴포넌트가 존재한다', () => {
    expect(text).toContain('"name": "TagInput"');
    expect(text).toContain(".locales'");
  });

  it('TagInput value 는 currency.locales 를 바인딩한다', () => {
    expect(text).toContain('currency.locales ?? []');
  });

  it('options 는 supportedLocales 매핑이다', () => {
    expect(text).toContain('appConfig?.supportedLocales');
  });

  it('locales 라벨 i18n 키를 사용한다', () => {
    expect(text).toContain('exchange_settings.locales');
  });

  it('locale 검증 에러 시 input-error 로 강조한다', () => {
    // 해당 통화 인덱스의 locales 에러 키 존재 시 input-error 클래스 부여
    expect(text).toContain('input-error');
    expect(text).toContain(
      "Object.keys(_local.errors ?? {}).some(k => k.startsWith('language_currency.currencies.' + currency._idx + '.locales'))"
    );
  });

  it('locale 검증 에러 메시지를 필드 아래 form-error 로 표시한다', () => {
    expect(text).toContain('"className": "form-error"');
    expect(text).toContain(
      "_local.errors?.[Object.keys(_local.errors ?? {}).find(k => k.startsWith('language_currency.currencies.' + currency._idx + '.locales'))]?.[0]"
    );
  });
});
