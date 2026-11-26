/**
 * @file mypageCurrencySettings.test.tsx
 * @description 마이페이지 결제 통화, 배송 국가 = 회원정보 저장 한 번에 함께 가는 확장 항목
 *
 * 설계: 통화는 별도 저장 단추가 아니라 회원정보 수정 때 함께 저장한다(결정 2026-09-28).
 * 종전에는 그 통화 선택칸(name=ecommerce_preferred_currency)과 배송 국가 선택칸이 코어 쪽 사용자 템플릿에 박혀 있었고,
 * 선택지도 화면이 이커머스 모듈의 전역 설정(_global.modules['sirsoft-ecommerce'])에서 직접 읽었다.
 *
 * 지금 계약:
 * - 사용자 템플릿에는 박힌 통화 칸, 배송 국가 칸과 모듈 전역 참조가 없다.
 * - 이커머스가 두 값을 회원 항목으로 선언하면 서버가 항목 계약(GET /api/me/fields 의 fields[])에 실어 주고,
 *   화면은 계산값 profileExtensionGroups 로 확장마다 묶어 확장별 카드에서 공용 조각으로 그린다.
 *   선택지는 계약의 options, 보기용 값은 display_value 다. 이커머스가 없거나 꺼져 있으면 계약에 항목이 없어 카드도 없다.
 * - 값은 폼 상태(profileEditForm)에 실려 PUT /api/me 한 번에 간다. 통화 전용 호출(user/currency)은 없다.
 *   검증 오류는 그 요청의 필드 오류로 칸 아래에 뜨고 위쪽 오류 상자에는 다시 나오지 않는다.
 * - 저장 성공 처리의 전역 선호 통화, 배송 국가 맞추기는 아직 이 화면에 남아 있다(이커머스 쪽으로 옮겨 가면 지워질 예정).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { evaluateComputedDefinitions } from '@core/template-engine/helpers/ComputedEvaluator';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

const baseDir = path.resolve(__dirname, '../..');
const read = (file: string) => fs.readFileSync(path.resolve(baseDir, file), 'utf8');

const viewText = read('layouts/partials/mypage/profile/_view.json');
const editText = read('layouts/partials/mypage/profile/_edit.json');
const viewLayoutText = read('layouts/mypage/profile.json');
const editLayoutText = read('layouts/mypage/profile-edit.json');

interface Node {
  comment?: string;
  if?: string;
  children?: Node[];
  [k: string]: any;
}

const view: Node = JSON.parse(viewText);
const edit: Node = JSON.parse(editText);
const viewLayout: Node = JSON.parse(viewLayoutText);
const editLayout: Node = JSON.parse(editLayoutText);
const fieldPartial: Node = JSON.parse(read('layouts/partials/member/_member_fields.json'));
const fieldViewPartial: Node = JSON.parse(read('layouts/partials/member/_member_fields_view.json'));

function flatten(n: Node | undefined, acc: Node[] = []): Node[] {
  if (!n) return acc;
  acc.push(n);
  (n.children ?? []).forEach((c) => flatten(c, acc));
  return acc;
}

/** 값 어디에든 들어 있는 액션(handler 를 가진 객체)을 모두 모은다 */
function actionsIn(value: unknown, acc: Node[] = []): Node[] {
  if (Array.isArray(value)) {
    value.forEach((item) => actionsIn(item, acc));
  } else if (value && typeof value === 'object') {
    const item = value as Node;
    if (typeof item.handler === 'string') acc.push(item);
    Object.values(item).forEach((child) => actionsIn(child, acc));
  }
  return acc;
}

/** 레이아웃의 계산값을 실제 엔진의 평가기로 계산한다 (풀지 못한 식이 있으면 실패) */
function engineComputed(definitions: Record<string, string>, context: Record<string, unknown>): Record<string, any> {
  const failures: string[] = [];
  const values = evaluateComputedDefinitions(definitions, context, new DataBindingEngine(), {
    previous: {},
    onError: 'undefined',
    failureLog: (name: string, error: unknown) => failures.push(`${name}: ${String(error)}`),
  } as any);
  expect(failures).toEqual([]);
  return values;
}

/** 표현식({{ }}) 하나를 엔진의 식 평가기로 계산한다 */
function engineExpression(expression: string, context: Record<string, unknown>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  return new DataBindingEngine().evaluateExpression(body, context as any, { skipCache: true });
}

/** 이커머스가 회원 항목으로 선언한 두 값 — 서버가 항목 계약에 실어 주는 모양 */
const currencyField = {
  key: 'ecommerce_preferred_currency',
  name: 'ecommerce_preferred_currency',
  type: 'select',
  label: '결제 통화',
  owner: 'sirsoft-ecommerce',
  owner_type: 'module',
  source_label: '이커머스',
  options: [{ value: 'KRW', label: '원 (KRW)' }, { value: 'USD', label: '달러 (USD)' }],
  ui: {},
  display_value: '원 (KRW)',
};
const shippingCountryField = {
  key: 'ecommerce_preferred_shipping_country',
  name: 'ecommerce_preferred_shipping_country',
  type: 'select',
  label: '배송 국가',
  owner: 'sirsoft-ecommerce',
  owner_type: 'module',
  source_label: '이커머스',
  options: [{ value: 'KR', label: '대한민국' }, { value: 'US', label: '미국' }],
  ui: {},
  display_value: '대한민국',
};
const coreFields = [
  { key: 'name', name: 'name', type: 'text', owner: 'core', owner_type: 'core', ui: { group: 'basic' } },
  { key: 'mobile', name: 'mobile', type: 'text', owner: 'core', owner_type: 'core', ui: { group: 'contact' } },
];
/** 이커머스가 켜져 있을 때와 없을 때(또는 꺼져 있을 때)의 항목 계약 */
const withEcommerce = { data: { fields: [...coreFields, currencyField, shippingCountryField] } };
const withoutEcommerce = { data: { fields: coreFields } };

const extensionGroups = (layout: Node, profileFields: unknown) =>
  engineComputed({ profileExtensionGroups: layout.computed.profileExtensionGroups }, { _local: {}, profileFields }).profileExtensionGroups as Array<{
    key: string;
    title: string;
    fields: Node[];
  }>;

const extensionCardIn = (root: Node) =>
  flatten(root).filter((n) => n.iteration?.item_var === 'memberFieldGroup' && n.iteration.source === '{{$computed.profileExtensionGroups ?? []}}');

describe('D9 — 마이페이지 결제 통화: 회원정보 통합저장 (확장 항목)', () => {
  describe('코어 쪽 사용자 템플릿에 박힌 통화 칸이 없다', () => {
    /**
     * @scenario entry_point=profile_update, field=currency
     * @effects core_template_has_no_hardcoded_ecommerce_preference
     */
    it('보기 화면에 박힌 결제 통화, 배송 국가 표시와 이커머스 모듈 전역 참조가 없다', () => {
      // 종전의 설치 게이트(user.data.ecommerce_preferred_currency 가 있을 때만 그리는 카드)는 사라졌다.
      // 설치 여부는 이제 항목 계약에 그 항목이 있느냐로 갈린다(아래 「확장별 카드」).
      expect(flatten(view).filter((n) => typeof n.if === 'string' && n.if.includes('ecommerce_'))).toEqual([]);
      for (const text of [viewText, viewLayoutText]) {
        expect(text).not.toMatch(/ecommerce_preferred_/);
        expect(text).not.toContain('_global.modules');
        expect(text).not.toContain('sirsoft-ecommerce');
        expect(text).not.toContain('currency_settings');
        expect(text).not.toContain('shipping_country_settings');
      }
    });

    /**
     * @scenario entry_point=profile_update, field=currency
     * @effects core_template_has_no_hardcoded_ecommerce_preference
     */
    it('수정 화면에 박힌 통화 선택칸, 배송 국가 선택칸과 이커머스 모듈 전역 참조가 없다', () => {
      // 이름이 박힌 입력은 표시 이름 종류 선택 칸 하나뿐이다(회원의 선택이라 이 조각이 직접 그린다) — 값이 실리는 나머지 칸은 공용 조각이 항목 계약의 name 으로 그린다
      const named = flatten(edit).filter((n) => n.name !== 'Icon' && typeof n.props?.name === 'string');
      expect(named.map((n) => n.props.name)).toEqual(['display_name_kind']);
      // 선택지를 모듈이 내놓는 전역 설정(language_currency.currencies 등)에서 읽지 않는다
      for (const text of [editText, editLayoutText]) {
        expect(text).not.toContain('_global.modules');
        expect(text).not.toContain('sirsoft-ecommerce');
        expect(text).not.toContain('language_currency');
        expect(text).not.toContain('currency_settings');
        expect(text).not.toContain('shipping_country_settings');
      }
      // 칸마다 박아 두던 오류 줄도 없다(오류는 공용 조각이 칸 아래에 그린다)
      expect(editText).not.toMatch(/profileErrors\?\.ecommerce_/);
    });

    /** @effects form_calls_only_core_save_api */
    it('별도 통화 저장 액션(user/currency)이 없다 (D8/D9 제거)', () => {
      expect(viewText).not.toContain('modules/sirsoft-ecommerce/user/currency');
      expect(actionsIn(view).filter((action) => action.handler === 'apiCall')).toEqual([]);
    });

    /** @effects form_calls_only_core_save_api */
    it('view 에 통화 저장 버튼 라벨(currency_settings.save_button)이 없다', () => {
      expect(viewText).not.toContain('currency_settings.save_button');
    });
  });

  describe('확장별 카드 — 이커머스가 선언한 항목을 항목 계약으로 그린다', () => {
    /**
     * @scenario entry_point=profile_update, field=currency
     * @effects profile_currency_ui_renders_selector
     */
    it('보기 화면: 이커머스가 선언한 통화, 배송 국가가 이커머스 카드에 보기용 값으로 서고, 이커머스가 없으면 카드가 없다', () => {
      const cards = extensionCardIn(view);
      expect(cards).toHaveLength(1);
      expect(flatten(cards[0]).map((n) => n.partial).filter(Boolean)).toEqual(['partials/member/_member_fields_view.json']);

      const groups = extensionGroups(viewLayout, withEcommerce);
      expect(groups.map((group) => [group.key, group.title])).toEqual([['ext_sirsoft-ecommerce', '이커머스']]);
      expect(groups[0].fields.map((field) => field.key)).toEqual(['ecommerce_preferred_currency', 'ecommerce_preferred_shipping_country']);

      // 보기 조각은 항목마다 계약의 보기용 값(display_value)을 그린다
      const row = flatten(fieldViewPartial).find((n) => n.iteration?.item_var === 'field')!;
      const drawn = engineExpression(row.iteration.source, { memberFieldGroup: groups[0] }) as Node[];
      expect(drawn.map((field) => field.display_value)).toEqual(['원 (KRW)', '대한민국']);
      expect(flatten(row).some((n) => n.text === "{{field.display_value || '-'}}")).toBe(true);

      // 설치 게이트: 이커머스가 없거나 꺼져 있으면 계약에 항목이 없어 묶음이 없고, 카드도 돌지 않는다
      expect(extensionGroups(viewLayout, withoutEcommerce)).toEqual([]);
      expect(extensionGroups(viewLayout, undefined)).toEqual([]);
      expect(engineExpression(cards[0].iteration.source, { $computed: { profileExtensionGroups: [] } })).toEqual([]);
    });

    /**
     * @scenario entry_point=profile_update, field=currency
     * @effects profile_edit_has_currency_field, form_field_rides_core_save_body
     */
    it('수정 화면: 통화 칸은 회원정보 폼 안의 이커머스 카드에 서고, 값은 항목 이름 그대로 폼 상태에 실린다', () => {
      const form = flatten(edit).find((n) => n.name === 'Form')!;
      expect(form.dataKey).toBe('profileEditForm');
      const cards = extensionCardIn(form);
      expect(cards).toHaveLength(1);
      expect(flatten(cards[0]).map((n) => n.partial).filter(Boolean)).toEqual(['partials/member/_member_fields.json']);

      const groups = extensionGroups(editLayout, withEcommerce);
      expect(groups.map((group) => group.key)).toEqual(['ext_sirsoft-ecommerce']);
      const row = flatten(fieldPartial).find((n) => n.iteration?.item_var === 'field')!;
      const drawn = engineExpression(row.iteration.source, { memberFieldGroup: groups[0] }) as Node[];
      expect(drawn.map((field) => field.name)).toEqual(['ecommerce_preferred_currency', 'ecommerce_preferred_shipping_country']);

      // 입력 컴포넌트의 name 이 항목의 name 이라 폼 자동 바인딩이 profileEditForm.ecommerce_preferred_currency 에 값을 둔다
      const input = flatten(row).find((n) => n.name === 'MemberFieldInput')!;
      expect(input.props.name).toBe('{{field.name}}');
      expect(engineExpression(input.if!, { field: currencyField })).toBe(true);
      // 저장 본문은 폼 상태 전체다(표시 이름 종류만 가려 싣는다) — 통화 값이 항목 이름 그대로 나간다
      const call = actionsIn(form.actions).find((action) => action.handler === 'apiCall')!;
      const sent = { name: '홍길동', ecommerce_preferred_currency: 'USD' };
      expect(engineExpression(call.params.body, { _local: { profileEditForm: sent }, profileFields: withEcommerce })).toEqual(sent);

      // 이커머스가 없으면 칸도 카드도 없다
      expect(extensionGroups(editLayout, withoutEcommerce)).toEqual([]);
    });

    /**
     * @scenario entry_point=profile_update, field=currency
     * @effects field_options_follow_selectable_currencies
     */
    it('통화 선택지는 서버가 준 항목 계약의 options 를 그대로 쓴다 (모듈 전역 설정을 읽지 않는다)', () => {
      const [group] = extensionGroups(editLayout, withEcommerce);
      // 묶는 계산이 계약을 손대지 않고 넘긴다
      expect(group.fields[0].options).toEqual(currencyField.options);
      expect(group.fields[1].options).toEqual(shippingCountryField.options);
      // 입력 컴포넌트는 항목 계약을 통째로 받아 그 안의 options 로 선택지를 그린다
      const input = flatten(fieldPartial).find((n) => n.name === 'MemberFieldInput')!;
      expect(input.props.field).toBe('{{field}}');
      expect(input.props.options).toBeUndefined();
      expect(editText).not.toContain('language_currency');
      expect(editText).not.toContain('currencies');
    });
  });

  describe('저장 — 회원정보 저장 한 번', () => {
    /**
     * @scenario entry_point=profile_update, field=currency
     * @effects form_calls_only_core_save_api, field_error_shown_under_field
     */
    it('저장은 PUT /api/me 한 번이고 통화 전용 호출이 없다 — 통화 오류는 그 칸 아래에 뜨고 위쪽 상자에는 다시 나오지 않는다', () => {
      const form = flatten(edit).find((n) => n.name === 'Form')!;
      const calls = actionsIn(form.actions).filter((action) => action.handler === 'apiCall');
      expect(calls.map((action) => [action.params.method, action.target])).toEqual([['PUT', '/api/me']]);
      // 이 조각 전체로 보아도 쓰는 API 호출은 그 하나뿐이다(표시 이름 미리보기를 다시 받는 조회 하나가 더 있다)
      const allCalls = actionsIn(edit).filter((action) => action.handler === 'apiCall');
      expect(allCalls.filter((action) => action.params.method !== 'GET')).toHaveLength(1);
      expect(allCalls.filter((action) => action.params.method === 'GET').map((action) => String(action.target).split('?')[0])).toEqual(['/api/me/fields']);
      expect(editText).not.toContain('sirsoft-ecommerce/user/currency');

      // 실패 응답의 필드 오류가 폼 오류 상태로 가고, 공용 조각에 넘기는 문맥이 그것을 싣는다
      const errors = { ecommerce_preferred_currency: ['고를 수 없는 통화입니다'] };
      const values = engineComputed(editLayout.computed, { _local: { profileErrors: errors }, profileFields: withEcommerce });
      expect(values.memberFieldCtx.errors).toEqual(errors);
      // 공용 조각의 오류 줄이 통화 칸에서 열리고 그 문구를 보인다. 배송 국가 칸에서는 열리지 않는다
      const line = flatten(fieldPartial).find((n) => n.id === 'mf_error_{{field.key}}')!;
      const scope = (field: unknown) => ({ field, $computed: { memberFieldCtx: values.memberFieldCtx } });
      expect(engineExpression(line.if!, scope(currencyField))).toBe(true);
      expect(engineExpression(line.text, scope(currencyField))).toBe('고를 수 없는 통화입니다');
      expect(engineExpression(line.if!, scope(shippingCountryField))).toBe(false);
      // 칸에 속한 오류라 위쪽 상자 목록에는 없다
      expect(values.profileOrphanErrors).toEqual([]);
    });

    /**
     * @scenario entry_point=profile_update, field=currency
     * @effects profile_save_syncs_global_preference
     */
    it('저장 성공 처리는 전역 선호 통화와 배송 국가를 직접 맞추지 않고 current_user 를 다시 받는다 (이커머스 조각이 그 재조회를 지켜보다 맞춘다)', () => {
      const form = flatten(edit).find((n) => n.name === 'Form')!;
      const call = actionsIn(form.actions).find((action) => action.handler === 'apiCall')!;
      const saved = call.onSuccess as Node[];
      expect(saved.filter((action) => action.handler === 'setState' && action.params?.target === 'global')).toEqual([]);
      expect(saved.some((action) => action.handler === 'refetchDataSource' && action.params?.dataSourceId === 'current_user')).toBe(true);
      expect(JSON.stringify(edit)).not.toContain('preferredCurrency');
      expect(JSON.stringify(edit)).not.toContain('preferredShippingCountry');
    });
  });
});
