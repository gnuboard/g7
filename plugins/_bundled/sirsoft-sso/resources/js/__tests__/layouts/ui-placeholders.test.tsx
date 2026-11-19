// e2e:allow 화면 실측은 F1 Chrome MCP 계획서 §10 M1·M4·M10·M11(W40), 로그인 흐름은 Playwright tests/Playwright/specs/sso-*.spec.ts — P4 는 엔진 번역 경로로 덮음
/**
 * @file ui-placeholders.test.tsx
 * @description 화면 문구 JSON 의 자리표시자 계약 — 엔진(TranslationEngine)은 `{{key}}`·`{key}` 만 치환하고 서버식 `:key` 는 치환하지 않는다.
 * 그래서 화면 문구에 `:word` 가 있으면 「:idp(으)로 로그인」처럼 그대로 보인다(F1 M1 실측 결함).
 * 레이아웃·주입 조각이 인자를 넘기는 모든 `$t:sirsoft-sso.…|이름=값` 토큰을 실제 엔진으로 번역해 인자가 들어가는지 본다.
 */
import { describe, it, expect, beforeAll } from 'vitest';
import fs from 'fs';
import path from 'path';
import { TranslationEngine, type TranslationContext } from '@core/template-engine/TranslationEngine';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { ROOT, lang, flatten, load } from './p4-layout-helpers';

const T = 'sirsoft-admin_basic';
const LOCALES = ['ko', 'en'] as const;

/** 서버식 자리표시자 — 콜론 바로 뒤 영문 소문자(`https://`, `SSO: ` 는 걸리지 않는다) */
const COLON_PLACEHOLDER = /(^|[^a-zA-Z0-9]):[a-z_]+/;

/**
 * 문구 트리의 잎을 `[점 경로, 문장]` 으로 펼칩니다.
 *
 * @param tree - 문구 트리
 * @param prefix - 앞 경로
 * @returns 잎 목록
 */
function leaves(tree: any, prefix = ''): Array<[string, string]> {
  return Object.entries(tree).flatMap(([key, value]) => {
    const full = prefix ? `${prefix}.${key}` : key;
    return typeof value === 'string' ? [[full, value] as [string, string]] : leaves(value, full);
  });
}

/**
 * 디렉터리 아래의 구운 JSON 경로를 모읍니다.
 *
 * @param dir - 디렉터리
 * @returns 경로 목록
 */
function jsonFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return jsonFiles(full);
    return entry.name.endsWith('.json') ? [full] : [];
  });
}

/** 인자를 넘기는 플러그인 문구 토큰 — 키와 `|이름=값` 묶음 */
const TOKEN = /\$t:(?:defer:)?sirsoft-sso\.([a-z0-9_.]*[a-z0-9_])((?:\|[a-z_]+=(?:\{\{.*?\}\}|[^|"<]*))+)/g;

/**
 * 레이아웃과 주입 조각에서 인자 있는 토큰을 모읍니다.
 *
 * @returns 키, 인자 이름 목록
 */
function parameterizedTokens(): Array<{ key: string; params: string[]; file: string }> {
  const files = [...jsonFiles(path.join(ROOT, 'resources/layouts')), ...jsonFiles(path.join(ROOT, 'resources/extensions'))];
  const found: Array<{ key: string; params: string[]; file: string }> = [];
  for (const file of files) {
    const strings = flatten(JSON.parse(fs.readFileSync(file, 'utf8'))).flatMap((node) =>
      Object.values(node).filter((v): v is string => typeof v === 'string'),
    );
    for (const value of strings) {
      for (const m of value.matchAll(TOKEN)) {
        const params = [...m[2].matchAll(/\|([a-z_]+)=/g)].map((p) => p[1]);
        found.push({ key: m[1], params, file: path.relative(ROOT, file) });
      }
    }
  }

  return found;
}

describe('화면 문구 자리표시자', () => {
  let engine: TranslationEngine;

  beforeAll(() => {
    engine = new TranslationEngine({ defaultLocale: 'ko', fallbackLocale: 'en' });
    for (const locale of LOCALES) {
      for (const [key, value] of leaves(lang(locale))) {
        engine.setTranslationValue(T, locale, `sirsoft-sso.${key}`, value);
      }
    }
  });

  /**
   * @scenario locale=ko|en, text=present|empty
   * @effects no_colon_placeholder
   */
  it('ko·en 문구에 서버식 `:word` 자리표시자가 없다', () => {
    for (const locale of LOCALES) {
      const bad = leaves(lang(locale)).filter(([, value]) => COLON_PLACEHOLDER.test(value)).map(([key]) => key);
      expect(bad, locale).toEqual([]);
    }
  });

  /**
   * @scenario surface=user|admin, locale=ko|en
   * @effects login_button_shows_idp_label
   */
  it('로그인 버튼 문구에 IdP 이름이 들어간다', () => {
    const ko: TranslationContext = { templateId: T, locale: 'ko' };
    const en: TranslationContext = { templateId: T, locale: 'en' };

    expect(engine.resolveTranslations('$t:sirsoft-sso.buttons.login_with|idp={{idp.label}}', ko, { idp: { label: '모의 회사' } })).toBe(
      '모의 회사 계정으로 로그인',
    );
    expect(engine.resolveTranslations('$t:sirsoft-sso.buttons.login_with|idp={{idp.label}}', en, { idp: { label: 'Mock SSO' } })).toBe(
      'Sign in with Mock SSO',
    );
  });

  /**
   * @scenario screen=plugin_settings|accounts|logs, locale=ko|en
   * @effects cell_labels_deferred
   */
  it('표(DataGrid) 셀 안에서 행 값(row, value)을 번역 인자로 쓰는 토큰은 $t:defer: 로 셀 문맥이 생긴 뒤 번역한다', () => {
    // `$t:` 는 셀 문맥 전에 번역돼 행 인자가 빈 값이 된다 — 접근 이름이 「 연결 해제」「 편집」으로 비었다(F1 M10, M11 실측)
    const files = [...jsonFiles(path.join(ROOT, 'resources/layouts')), ...jsonFiles(path.join(ROOT, 'resources/extensions'))];
    const early: string[] = [];
    for (const file of files) {
      for (const grid of flatten(JSON.parse(fs.readFileSync(file, 'utf8'))).filter((n) => n?.name === 'DataGrid')) {
        const strings = flatten(grid.props?.columns ?? []).flatMap((n) => Object.values(n)).filter((v): v is string => typeof v === 'string');
        for (const value of strings) {
          if (/\$t:(?!defer:)[a-z0-9_.-]+\|[^"]*\{\{[^}]*\b(row|value)\b/.test(value)) early.push(`${path.relative(ROOT, file)} ${grid.id}: ${value}`);
        }
      }
    }

    expect(early).toEqual([]);
  });

  /**
   * @scenario extension=all, text=present|empty
   * @effects dates_formatted
   */
  it('화면에 보이는 시각 값(`*_at`)은 date·datetime 파이프로 포맷한다 — ISO 원문(UTC)이 그대로 나오지 않는다', () => {
    // F1 M10, M11 실측: 「회원 연결」 최근 로그인이 `2026-10-25T04:02:12+00:00` 로 보였다(마이페이지 카드는 `| datetime`)
    const files = [...jsonFiles(path.join(ROOT, 'resources/layouts')), ...jsonFiles(path.join(ROOT, 'resources/extensions'))];
    const raw: string[] = [];
    for (const file of files) {
      for (const node of flatten(JSON.parse(fs.readFileSync(file, 'utf8')))) {
        for (const [key, value] of Object.entries(node)) {
          if (typeof value !== 'string' || ['if', 'className', 'condition', 'comment'].includes(key)) continue;
          for (const m of value.matchAll(/\{\{(.*?)\}\}/g)) {
            // 문자열 리터럴(번역 키 이름)과 조건으로만 쓰는 자리(`x_at &&`, `x_at ?`)는 표시가 아니다
            const shownExpr = m[1].replace(/'[^']*'/g, "''").replace(/\b[a-z_]+_at\b(?=\s*(&&|\?(?![.?])|\|\|))/g, '');
            if (/\b[a-z_]+_at\b/.test(shownExpr) && !/\|\s*(date|datetime|relativeTime)\b/.test(m[1])) raw.push(`${path.relative(ROOT, file)} ${key}: ${m[0]}`);
          }
        }
      }
    }

    expect(raw).toEqual([]);

    // 실제 바인딩 엔진으로 — 값이 있으면 포맷한 시각, 없으면 「-」, 오류 코드는 시각 뒤에 붙는다.
    // 셀과 반복 렌더 경로처럼 값 캐시를 건너뛴다(같은 식 문자열을 다른 문맥으로 다시 평가하므로)
    const engine = new DataBindingEngine();
    const binding = { resolveBindings: (text: string, context: any) => engine.resolveBindings(text, context, { skipCache: true }) };
    const accountsText = flatten(load('resources/layouts/admin/sso_accounts')).find((n) => n?.text?.includes('row.last_login_at'))?.text;
    expect(binding.resolveBindings(accountsText, { row: { last_login_at: null } })).toBe('-');
    const shown = binding.resolveBindings(accountsText, { row: { last_login_at: '2026-10-25T04:02:12+00:00' } });
    expect(shown).not.toContain('T04:02');
    expect(shown).toContain('2026');

    const errorText = flatten(load('resources/layouts/admin/partials/plugin_settings/_idp_edit')).find((n) => n?.text?.includes('last_error_at'))?.text;
    expect(binding.resolveBindings(errorText, { _local: { idpForm: {} } })).toBe('-');
    const failed = binding.resolveBindings(errorText, { _local: { idpForm: { last_error_at: '2026-10-25T04:02:12+00:00', last_error_code: 'invalid_grant' } } });
    expect(failed).toContain('(invalid_grant)');
    expect(failed).not.toContain('T04:02');
  });

  /**
   * @scenario extension=all, locale=ko|en
   * @effects every_param_substituted
   */
  it('레이아웃이 인자를 넘기는 모든 토큰은 엔진 번역 뒤 인자 값이 들어가고 자리표시자가 남지 않는다', () => {
    const tokens = parameterizedTokens();
    expect(tokens.length).toBeGreaterThanOrEqual(17);

    for (const locale of LOCALES) {
      const context: TranslationContext = { templateId: T, locale };
      for (const { key, params, file } of tokens) {
        const paramString = params.map((name) => `|${name}=VAL${name.toUpperCase()}`).join('');
        const out = engine.translate(`sirsoft-sso.${key}`, context, paramString);
        const where = `${locale} ${key} (${file})`;

        expect(out, where).not.toBe(`sirsoft-sso.${key}`);
        for (const name of params) {
          expect(out, where).toContain(`VAL${name.toUpperCase()}`);
        }
        expect(COLON_PLACEHOLDER.test(out), where).toBe(false);
        expect(out, where).not.toMatch(/\{\{?[a-z_]+\}\}?/);
      }
    }
  });
});
