// e2e:allow 내 정보 저장 국가 미적용 안내 — 운영자가 배송 국가를 내리는 전역 설정 변경(배타 창)이 필요해 E2E 스위트로 재현하지 않는다. 대체 검증: 이 Vitest(실제 번역 엔진 ko·en 문장) + 전용 Chrome 실측 ko·en·ja(이커머스 전수 실측 S4-D15·D20)
/**
 * @file profile-shipping-country-not-applied.test.ts
 * @description 내 정보·내 정보 수정의 배송국가 칸이 저장해 둔 국가가 지금 적용되지 않는다는 사실을 알리는지 (레이아웃 구조 + 실제 번역 엔진 렌더).
 *
 * 결함: 운영자가 회원이 저장해 둔 배송국가(예: 미국)를 배송 가능 목록에서 내리면 헤더·주문서·서버는 기본 국가(대한민국)로
 *   계산하는데, 내 정보 화면은 「미국」 을 그대로 보이고 내 정보 수정 콤보는 빈칸이 되어 무엇이 적용되는지 알 수 없었다.
 *   이커머스 1.3.0 은 「저장해 둔 국가가 지금 적용되고 있는지 함께 확인할 수 있다」 고 적는다. (이커머스 전수 실측 S4 — S4-D15)
 *
 * 후속 결함: 첫 수정은 문장을 국가명 칸과 번역 조각 3개(저장된 배송국가 / 은(는) … 적용되지 않습니다 / 지금 적용 중인 배송국가:)로
 *   이어 붙여, 영어 화면에 「United Statesis not available …」 처럼 국가명과 문장이 붙어 나왔다(한국어·일본어는 조사가 붙는
 *   어순이라 우연히 맞음). 문장 전체를 한 번역 키에 두고 국가명은 파라미터로 넘긴다. 저장 국가명은 헤더 국가 목록과 같은
 *   폴백 키(sirsoft-ecommerce::settings.countries.{code}.name)로 푼다. (S4-D20)
 */

import { describe, it, expect, beforeAll } from 'vitest';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';

import profileView from '../../../layouts/partials/mypage/profile/_view.json';
import profileEdit from '../../../layouts/partials/mypage/profile/_edit.json';
import koMypage from '../../../lang/partial/ko/mypage.json';
import enMypage from '../../../lang/partial/en/mypage.json';

const NOTICE_KEY = 'mypage.shipping_country_settings.saved_not_applied';
const TEMPLATE_ID = 'sirsoft-basic';

/** 조건에 맞는 노드를 트리에서 모두 모은다 */
function collect(node: unknown, match: (value: Record<string, unknown>) => boolean, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, out));

    return out;
  }

  if (node === null || typeof node !== 'object') {
    return out;
  }

  const value = node as Record<string, unknown>;

  if (match(value)) {
    out.push(value);
  }

  Object.values(value).forEach((child) => collect(child, match, out));

  return out;
}

/** 안내 문장 노드(문장 전체를 한 번역 키로 그리는 노드)를 찾는다 */
function findNotice(layout: unknown): Record<string, unknown> | undefined {
  return collect(layout, (value) => typeof value.text === 'string' && (value.text as string).startsWith(`$t:${NOTICE_KEY}|`))[0];
}

/** 안내 문장의 표시 조건을 노드에서 찾는다(문장 노드 또는 그 부모의 if) */
function findNoticeCondition(layout: unknown): string | undefined {
  const notice = findNotice(layout);
  if (notice?.if) {
    return String(notice.if);
  }
  const parent = collect(layout, (value) => Array.isArray(value.children) && (value.children as unknown[]).includes(notice))[0];

  return parent?.if ? String(parent.if) : undefined;
}

/** `{{ … }}` 조건을 화면 컨텍스트로 평가한다 */
function evaluate(text: string, ctx: Record<string, unknown>): unknown {
  const expr = text.replace(/^\{\{([\s\S]+)\}\}$/, '$1');
  const names = Object.keys(ctx);
  // eslint-disable-next-line no-new-func
  return new Function(...names, `return (${expr});`)(...names.map((name) => ctx[name]));
}

const countries = [
  { code: 'KR', name: { ko: '대한민국', en: 'South Korea' }, is_active: true },
  { code: 'US', name: { ko: '미국', en: 'United States' }, is_active: false },
  { code: 'JP', name: { ko: '일본', en: 'Japan' }, is_active: true },
];

/** 저장 국가 saved 인 회원 화면 컨텍스트 — 서버·헤더는 기본 국가 KR 을 적용 중 */
function context(locale: 'ko' | 'en', saved: string | undefined, formValue?: string, list = countries): Record<string, unknown> {
  return {
    $locale: locale,
    $templateId: TEMPLATE_ID,
    user: { data: { ecommerce_preferred_shipping_country: saved } },
    _local: { profileEditForm: formValue === undefined ? undefined : { ecommerce_preferred_shipping_country: formValue } },
    _global: {
      modules: { 'sirsoft-ecommerce': { shipping: { international_shipping_enabled: true, available_countries: list } } },
      preferredShippingCountry: 'KR',
      availableShippingCountries: list.filter((c) => c.is_active).map((c) => ({ code: c.code, name: c.name[locale] })),
    },
  };
}

let engine: TranslationEngine;

beforeAll(() => {
  engine = new TranslationEngine();
  const settings = (dict: Record<string, any>) => dict.shipping_country_settings as Record<string, string>;
  for (const [locale, dict] of [['ko', koMypage], ['en', enMypage]] as const) {
    for (const [key, value] of Object.entries(settings(dict as Record<string, any>))) {
      engine.setTranslationValue(TEMPLATE_ID, locale, `mypage.shipping_country_settings.${key}`, value);
    }
  }
});

/** 안내 문장을 실제 번역 엔진으로 그린다 */
function render(layout: unknown, ctx: Record<string, unknown>): string {
  const notice = findNotice(layout);

  return engine.resolveTranslations(String(notice!.text), { templateId: TEMPLATE_ID, locale: String(ctx.$locale) }, ctx);
}

describe('내 정보 — 저장한 배송국가가 배송 불가로 내려간 뒤 미적용 안내', () => {
  /**
   * @scenario case=view_inactive
   * @effects not_applied_notice_with_applied_country
   */
  it('내 정보 화면은 저장 국가(미국)가 적용되지 않는다는 것과 지금 적용 중인 국가(대한민국)를 한 문장으로 보인다', () => {
    expect(findNotice(profileView)).toBeDefined();
    expect(evaluate(String(findNoticeCondition(profileView)), context('ko', 'US'))).toBe(true);
    expect(render(profileView, context('ko', 'US'))).toBe('저장된 배송국가 미국: 지금 배송할 수 없는 국가라 적용되지 않습니다. 지금 적용 중인 배송국가: 대한민국');
  });

  /**
   * @scenario case=view_inactive_en
   * @effects notice_sentence_spaced_in_every_locale
   */
  it('영어 화면에서도 국가명과 문장이 띄어진 한 문장이다', () => {
    expect(render(profileView, context('en', 'US'))).toBe('Your saved shipping country United States is not available for shipping now, so it is not applied. Shipping country in use: South Korea');
    expect(render(profileEdit, context('en', 'US'))).toBe('Your saved shipping country United States is not available for shipping now, so it is not applied. Shipping country in use: South Korea');
  });

  /**
   * @scenario case=view_removed
   * @effects not_applied_notice_with_applied_country
   */
  it('저장 국가가 배송 국가 목록에서 아예 빠졌으면 국가 코드로 알린다', () => {
    const list = countries.filter((c) => c.code !== 'US');
    expect(evaluate(String(findNoticeCondition(profileView)), context('ko', 'US', undefined, list))).toBe(true);
    expect(render(profileView, context('ko', 'US', undefined, list))).toBe('저장된 배송국가 US: 지금 배송할 수 없는 국가라 적용되지 않습니다. 지금 적용 중인 배송국가: 대한민국');
  });

  /**
   * @scenario case=view_active
   * @effects notice_hidden_when_saved_country_is_shippable
   */
  it('저장 국가가 배송 가능(대한민국·일본)하면 안내를 보이지 않는다', () => {
    const condition = String(findNoticeCondition(profileView));
    expect(evaluate(condition, context('ko', 'KR'))).toBe(false);
    expect(evaluate(condition, context('ko', 'JP'))).toBe(false);
    expect(evaluate(condition, context('ko', undefined))).toBe(false);
  });

  /**
   * @scenario case=edit_inactive
   * @effects not_applied_notice_with_applied_country
   */
  it('내 정보 수정 화면은 콤보가 빈칸이 되는 대신 저장된 배송국가 미국이 적용되지 않음을 알린다', () => {
    expect(findNotice(profileEdit)).toBeDefined();
    expect(evaluate(String(findNoticeCondition(profileEdit)), context('ko', 'US'))).toBe(true);
    expect(render(profileEdit, context('ko', 'US'))).toBe('저장된 배송국가 미국: 지금 배송할 수 없는 국가라 적용되지 않습니다. 지금 적용 중인 배송국가: 대한민국');
  });

  /**
   * @scenario case=edit_inactive_then_picked
   * @effects notice_hidden_when_saved_country_is_shippable
   */
  it('수정 화면에서 배송 가능한 다른 국가(일본)를 고르면 안내가 사라진다', () => {
    expect(evaluate(String(findNoticeCondition(profileEdit)), context('ko', 'US', 'JP'))).toBe(false);
  });
});
