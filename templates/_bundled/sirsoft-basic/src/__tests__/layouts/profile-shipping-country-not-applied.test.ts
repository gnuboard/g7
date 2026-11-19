// e2e:allow 내 정보 저장 국가 미적용 안내 — 운영자가 배송 국가를 내리는 전역 설정 변경(배타 창)이 필요해 E2E 스위트로 재현하지 않는다. 대체 검증: 이 Vitest(실제 엔진 평가기로 확장 카드 묶음과 안내 줄 계산) + 전용 Chrome 실측 ko·en·ja(이커머스 전수 실측 S4-D15·D20)
/**
 * @file profile-shipping-country-not-applied.test.ts
 * @description 내 정보, 내 정보 수정의 배송국가 칸이 저장해 둔 국가가 지금 적용되지 않는다는 사실을 알리는지.
 *
 * 결함: 운영자가 회원이 저장해 둔 배송국가(예: 미국)를 배송 가능 목록에서 내리면 헤더, 주문서, 서버는 기본 국가(대한민국)로
 *   계산하는데, 내 정보 화면은 「미국」 을 그대로 보이고 내 정보 수정 콤보는 빈칸이 되어 무엇이 적용되는지 알 수 없었다.
 *   이커머스 1.3.0 은 「저장해 둔 국가가 지금 적용되고 있는지 함께 확인할 수 있다」 고 적는다. (이커머스 전수 실측 S4 — S4-D15)
 *
 * 후속 결함: 첫 수정은 문장을 국가명 칸과 번역 조각 3개로 이어 붙여, 영어 화면에 「United Statesis not available …」 처럼
 *   국가명과 문장이 붙어 나왔다. 문장 전체를 한 번역 키에 두고 국가명은 파라미터로 넘긴다. (S4-D20)
 *
 * 7.2.0 계약 변경: 배송국가 칸은 이 템플릿에 박혀 있지 않다. 이커머스 모듈이 회원 항목으로 선언하고, 화면은 서버가 준 항목
 *   계약(GET /api/me/fields)을 확장별 카드에서 공용 항목 조각으로 그린다. 미적용 안내도 화면이 전역 설정을 뒤져 만들지 않는다.
 *   서버가 저장 국가와 지금 적용 중인 국가를 넣은 한 문장을 요청 로케일로 만들어 항목 안내(`help`)로 주고
 *   (이커머스 `UserPreferenceFieldDefinitionListener`, 문장은 모듈 언어 파일 `fields.user.ecommerce_preferred_shipping_country.saved_not_applied`),
 *   공용 입력 조각(partials/member/_member_fields)이 칸 아래에 그대로 그린다. 저장 국가가 선택지에서 빠져 콤보가 빈칸이 되는
 *   문제도 서버가 저장 국가를 선택지에 다시 넣어 막는다.
 *   따라서 종전의 문장 조립 단언(국가명 폴백, 영어 띄어쓰기, 목록에서 빠진 국가는 코드로 표시)은 서버의 일이 되었고,
 *   이 테스트는 화면 쪽 계약만 잠근다: 받은 문장을 조각으로 다시 잇지 않고 그대로 그린다, 안내가 없으면 줄이 없다.
 *   「수정 화면에서 배송 가능한 다른 국가를 고르면 안내가 사라진다」는 화면이 조건을 계산하던 때의 동작이라 대상이 없어졌다
 *   (안내는 저장된 값에 대한 서버의 판정이고, 고른 값은 저장 전이다).
 *
 * @scenario-manifest tests/scenarios/profile-shipping-country-not-applied.yaml
 */

import { describe, it, expect } from 'vitest';
import { evaluateComputedDefinitions } from '@core/template-engine/helpers/ComputedEvaluator';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

import profileLayout from '../../../layouts/mypage/profile.json';
import profileEditLayout from '../../../layouts/mypage/profile-edit.json';
import profileView from '../../../layouts/partials/mypage/profile/_view.json';
import profileEdit from '../../../layouts/partials/mypage/profile/_edit.json';
import memberFields from '../../../layouts/partials/member/_member_fields.json';
import memberFieldsView from '../../../layouts/partials/member/_member_fields_view.json';

type Node = Record<string, any>;

const COUNTRY_FIELD = 'ecommerce_preferred_shipping_country';
const NOTICE_KO = '저장된 배송국가 미국: 지금 배송할 수 없는 국가라 적용되지 않습니다. 지금 적용 중인 배송국가: 대한민국';
const NOTICE_EN = 'Your saved shipping country United States is not available for shipping now, so it is not applied. Shipping country in use: South Korea';

/** 조건에 맞는 노드를 트리에서 모두 모은다 */
function collect(node: unknown, match: (value: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, out));

    return out;
  }

  if (node === null || typeof node !== 'object') {
    return out;
  }

  const value = node as Node;

  if (match(value)) {
    out.push(value);
  }

  Object.values(value).forEach((child) => collect(child, match, out));

  return out;
}

/** 표현식({{ }}) 하나를 엔진의 식 평가기로 계산한다 */
function evaluate(expression: string, context: Record<string, unknown>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');

  return new DataBindingEngine().evaluateExpression(body, context as any, { skipCache: true });
}

/** 레이아웃의 계산값을 실제 엔진의 평가기로 계산한다 (풀지 못한 식이 있으면 실패) */
function engineComputed(definitions: Record<string, unknown>, context: Record<string, unknown>): Record<string, any> {
  const failures: string[] = [];
  const values = evaluateComputedDefinitions(definitions, context, new DataBindingEngine(), {
    previous: {},
    onError: 'undefined',
    failureLog: (name: string, error: unknown) => failures.push(`${name}: ${String(error)}`),
  } as any);
  expect(failures).toEqual([]);

  return values;
}

/** 서버가 주는 배송국가 항목 계약 표본 — help 는 서버가 요청 로케일로 만든 문장이다 */
function countryField(help: string | null): Node {
  return {
    key: COUNTRY_FIELD,
    name: COUNTRY_FIELD,
    type: 'choice',
    label: '배송국가',
    owner: 'sirsoft-ecommerce',
    owner_type: 'module',
    source_label: '이커머스',
    options: [{ value: 'KR', label: '대한민국' }, { value: 'JP', label: '일본' }, { value: 'US', label: '미국' }],
    display_value: '미국',
    help,
    // 보기 화면용 알림 — 서버가 같은 문장을 ui.notice 에도 싣는다(안내가 없으면 키가 없다)
    ui: help ? { notice: help } : {},
  };
}

/** 화면 컨텍스트 — 항목 계약 응답과 폼 상태 */
function context(help: string | null): Record<string, unknown> {
  return {
    profileFields: { data: { fields: [{ key: 'name', name: 'name', type: 'text', label: '이름', owner: 'core', owner_type: 'core', ui: { group: 'basic' } }, countryField(help)] } },
    _local: { profileEditForm: { [COUNTRY_FIELD]: 'US' }, profileErrors: {} },
    _global: {},
  };
}

/** 확장별 카드와 그 안의 공용 조각 include 를 찾는다 */
function extensionCard(layout: unknown, idPrefix: string): Node | undefined {
  return collect(layout, (value) => typeof value.id === 'string' && value.id.startsWith(idPrefix))[0];
}

/** 공용 조각에서 항목 안내 줄(서버가 준 help 를 그리는 노드)을 찾는다 */
function findHelpLine(partial: unknown): Node | undefined {
  return collect(partial, (value) => typeof value.text === 'string' && value.text.includes('field.help'))[0];
}

describe('내 정보 — 저장한 배송국가가 배송 불가로 내려간 뒤 미적용 안내', () => {
  /**
   * @scenario case=edit_inactive
   * @effects not_applied_notice_with_applied_country
   */
  it('내 정보 수정 화면은 이커머스 카드의 배송국가 칸 아래에 서버가 준 미적용 안내 문장을 그린다', () => {
    const groups = engineComputed((profileEditLayout as Node).computed, context(NOTICE_KO)).profileExtensionGroups;
    expect(groups.map((group: Node) => group.key)).toEqual(['ext_sirsoft-ecommerce']);
    expect(groups[0].fields.map((field: Node) => field.name)).toEqual([COUNTRY_FIELD]);

    const card = extensionCard(profileEdit, 'profile_extension_card_');
    expect(card?.iteration?.source).toBe('{{$computed.profileExtensionGroups ?? []}}');
    expect(collect(card, (value) => value.partial === 'partials/member/_member_fields.json')).toHaveLength(1);

    const line = findHelpLine(memberFields);
    expect(line).toBeDefined();
    expect(evaluate(String(line!.if), { field: groups[0].fields[0] })).toBeTruthy();
    expect(evaluate(String(line!.text), { field: groups[0].fields[0] })).toBe(NOTICE_KO);
  });

  /**
   * 문장은 서버가 요청 로케일로 완성해 준다. 화면이 국가명 칸과 번역 조각을 이어 붙이면 영어에서 국가명과 문장이 붙는다(S4-D20).
   *
   * @scenario case=edit_inactive_en
   * @effects notice_sentence_spaced_in_every_locale
   */
  it('안내 줄은 받은 문장을 그대로 그린다 — 번역 조각이나 국가명 칸을 이어 붙이지 않는다', () => {
    const line = findHelpLine(memberFields);
    expect(line!.text).toBe('{{field.help}}');
    expect(line!.children ?? []).toEqual([]);
    expect(evaluate(String(line!.text), { field: countryField(NOTICE_EN) })).toBe(NOTICE_EN);

    // 템플릿은 미적용 문장을 스스로 만들지 않는다 — 수정, 보기 조각과 공용 조각 어디에도 종전 번역 키와 전역 배송 설정 참조가 없다
    for (const partial of [profileEdit, profileView, memberFields, memberFieldsView]) {
      const raw = JSON.stringify(partial, (key, value) => (key === 'comment' ? undefined : value));
      expect(raw).not.toContain('shipping_country_settings.saved_not_applied');
      expect(raw).not.toContain('available_countries');
    }
  });

  /**
   * @scenario case=edit_active
   * @effects notice_hidden_when_saved_country_is_shippable
   */
  it('저장 국가가 배송 가능해 서버가 안내를 주지 않으면 안내 줄이 없다', () => {
    const line = findHelpLine(memberFields);
    expect(evaluate(String(line!.if), { field: countryField(null) })).toBeFalsy();
    expect(evaluate(String(line!.if), { field: countryField('') })).toBeFalsy();
  });

  /**
   * 보기 화면도 같은 항목 계약(GET /api/me/fields)을 받으므로 서버 문장은 와 있다. 보기 조각이 그것을 그려야
   * 「미국」 만 보이고 무엇이 적용되는지 알 수 없던 S4-D15 가 되살아나지 않는다.
   *
   * @scenario case=view_inactive
   * @effects not_applied_notice_with_applied_country
   */
  it('내 정보 화면도 이커머스 카드의 배송국가 줄에 서버가 준 미적용 안내 문장을 그린다', () => {
    const groups = engineComputed((profileLayout as Node).computed, context(NOTICE_KO)).profileExtensionGroups;
    expect(groups[0].fields.map((field: Node) => field.name)).toEqual([COUNTRY_FIELD]);

    const card = extensionCard(profileView, 'profile_view_extension_card_');
    expect(collect(card, (value) => value.partial === 'partials/member/_member_fields_view.json')).toHaveLength(1);

    // 보기 조각은 입력 안내(help)를 그리지 않고 값에 붙는 알림(ui.notice)만 그린다
    const line = collect(memberFieldsView, (value) => typeof value.text === 'string' && value.text.includes('field.ui?.notice'))[0];
    expect(line, '보기 조각(_member_fields_view)에 값에 붙는 알림(ui.notice)을 그리는 줄이 없다 — 저장 국가가 적용되지 않는다는 안내가 내 정보 화면에서 빠진다').toBeDefined();
    expect(evaluate(String(line!.if), { field: groups[0].fields[0] })).toBeTruthy();
    expect(evaluate(String(line!.text), { field: groups[0].fields[0] })).toBe(NOTICE_KO);
    expect(evaluate(String(line!.if), { field: countryField(null) })).toBeFalsy();
    expect(findHelpLine(memberFieldsView)).toBeUndefined();
  });
});
