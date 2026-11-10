/**
 * 화면 제목 규칙 판정 사례 시험 (엔진 쪽)
 *
 * `tests/fixtures/seo/title-rule-cases.json` 을 서버 시험(`tests/Unit/Seo/SeoTitleRuleParityTest.php`)과 함께 읽는다 —
 * 사람 브라우저 탭 제목과 검색엔진 `<title>` 이 같은 규칙으로 같은 답을 내는지 잠근다.
 *
 * @scenario-manifest tests/scenarios/browser-tab-title-rule.yaml
 *
 * @since engine-v1.86.0
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { computeTitleInput, hasMissingRequiredSeoVar, resolveDocumentTitle, substituteSeoVars } from '../DocumentTitle';

const fixture = JSON.parse(
  readFileSync(resolve(__dirname, '../../../../../tests/fixtures/seo/title-rule-cases.json'), 'utf8'),
);

afterEach(() => {
  delete (window as any).G7Config;
});

describe('DocumentTitle 제목 규칙 — 서버와 같은 판정 사례', () => {
  // @scenario case=rule_fixture_engine
  // @effects engine_title_rule_matches_fixture
  it.each(fixture.rule.map((c: any) => [c.name, c]))('%s', (_name, c: any) => {
    (window as any).G7Config = { appConfig: c.input.site_locale ? { siteLocale: c.input.site_locale } : {} };

    expect(resolveDocumentTitle(c.input)).toEqual(c.expected);
  });

  // @scenario case=substitute_fixture_engine
  // @effects engine_substitute_matches_fixture
  it.each(fixture.substitute.map((c: any) => [c.name, c]))('틀 치환 — %s', (_name, c: any) => {
    expect(substituteSeoVars(c.template, c.vars)).toBe(c.expected);
  });

  // @scenario case=required_vars_engine
  // @effects required_var_empty_template_skipped
  it.each(fixture.required_vars.map((c: any) => [c.name, c]))('필수 변수 — %s', (_name, c: any) => {
    expect(hasMissingRequiredSeoVar(c.definitions, c.vars)).toBe(c.expected);
  });
});

describe('DocumentTitle 후보 — 레이아웃 편집기 「검색 제목」(meta.seo.title) (서버 SeoMetaResolver 와 같은 순서)', () => {
  /** `{{a.b}}` 만 바꾸는 얇은 평가기 — 후보 순서만 본다(식 평가 자체는 엔진 평가기 시험이 맡는다) */
  const evaluate = (expression: string, context: Record<string, any>): string =>
    expression.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_m, path: string) =>
      String(path.split('.').reduce((o: any, k) => (o == null ? undefined : o[k]), context) ?? ''),
    );
  const deps = { evaluate, locale: 'ko', payload: { site_name: '캠핑상회', suffix: '' } as any };
  const route = { path: '/shop/products/1', meta: { title: '상품' } } as any;
  const layout = (seo: Record<string, unknown>) => ({
    data_sources: [{ id: 'product' }],
    meta: { seo: { enabled: true, data_sources: ['product'], ...seo } },
  });

  // @scenario case=layout_seo_title_engine
  // @effects layout_seo_title_before_extension_template_and_og
  it('편집기 제목이 있으면 소셜 공유 제목(og)·라우트 제목보다 앞선다 — 식도 평가한다', () => {
    const input = computeTitleInput(
      layout({ title: '{{product.data.name}} 전문점', og: { title: 'OG 제목' } }),
      { product: { data: { name: '등산화' } } },
      route,
      deps,
    );

    expect(resolveDocumentTitle(input).title).toBe('등산화 전문점');
  });

  // @scenario case=layout_seo_title_engine
  // @effects layout_seo_title_before_extension_template_and_og
  it('상품·페이지 자체 제목(meta_title·seo_meta.title)은 편집기 제목보다 앞선다', () => {
    const own = computeTitleInput(
      layout({ title: '레이아웃 제목' }),
      { product: { data: { name: '등산화', meta_title: '상품 자체 제목' } } },
      route,
      deps,
    );
    expect(resolveDocumentTitle(own).title).toBe('상품 자체 제목');

    const page = computeTitleInput(
      layout({ title: '레이아웃 제목' }),
      { product: { data: { seo_meta: { title: '페이지 자체 제목' } } } },
      route,
      deps,
    );
    expect(resolveDocumentTitle(page).title).toBe('페이지 자체 제목');
  });

  // @scenario case=layout_seo_title_engine
  // @effects layout_seo_title_before_extension_template_and_og
  it('편집기 제목이 비면 종전 순서(og → 라우트 제목)를 그대로 따르고, 다국어 값은 현재 언어로 읽는다', () => {
    const empty = computeTitleInput(layout({ title: '', og: { title: 'OG 제목' } }), {}, route, deps);
    expect(resolveDocumentTitle(empty).title).toBe('OG 제목');

    const localized = computeTitleInput(layout({ title: { ko: '아웃도어', en: 'Outdoor' } }), {}, route, deps);
    expect(resolveDocumentTitle(localized).title).toBe('아웃도어');
  });
});
