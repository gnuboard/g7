/**
 * @file mypage-profile-dynamic-fields.test.ts
 * @description 내 정보 수정·보기 화면이 운영자 설정(내 정보 항목 계약)대로 카드별 칸을 그리는지
 *
 * 종전 화면은 이름, 닉네임, 연락처, 국가, 언어, 시간대, 주소, 서명, 자기소개 칸을 레이아웃에 박아 두었다.
 * 이제 그 칸은 공용 항목 조각이 서버가 준 계약(profileFields)으로 카드마다 그리고, 운영자가 추가한 항목은
 * 「추가 정보」 카드에 선다. 아바타, 이메일과 저장 요청은 그대로다.
 *
 * 게시판 알림, 결제 통화, 배송 국가처럼 모듈과 플러그인이 가진 칸도 레이아웃에 박혀 있었는데(7단계에서 걷어 냈다),
 * 지금은 그 확장이 회원 항목으로 선언한 것을 계산값 profileExtensionGroups 가 확장마다 한 묶음으로 만들고,
 * 확장별 카드가 그 묶음을 돌며 같은 공용 조각으로 그린다. 선택지와 보기용 값도 항목 계약에서 온다.
 *
 * @scenario-manifest tests/scenarios/mypage-profile-dynamic-fields.yaml
 */

import { describe, it, expect } from 'vitest';
import { evaluateComputedDefinitions } from '@core/template-engine/helpers/ComputedEvaluator';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import editLayout from '../../layouts/mypage/profile-edit.json';
import editPartial from '../../layouts/partials/mypage/profile/_edit.json';
import viewLayout from '../../layouts/mypage/profile.json';
import viewPartial from '../../layouts/partials/mypage/profile/_view.json';
import memberFields from '../../layouts/partials/member/_member_fields.json';
import memberFieldsView from '../../layouts/partials/member/_member_fields_view.json';

type Node = Record<string, any>;

/**
 * 레이아웃의 계산값 전부를 엔진의 평가기로 계산한다.
 *
 * 식 엔진은 자바스크립트 전체를 받지 않는다(함수 본문의 `var` 선언, `if` 문 등). `new Function` 으로 계산하는 검증은
 * 엔진이 못 푸는 식도 통과시키므로, 계산값은 실제 앱과 같은 평가기로도 풀어 본다. 풀지 못한 식이 있으면 실패다.
 */
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

/** 표현식({{ }}) 하나를 엔진의 식 평가기로 계산한다 (반복 원천처럼 계산값이 아닌 식을 엔진으로 잴 때) */
function engineExpression(expression: string, context: Record<string, unknown>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  return new DataBindingEngine().evaluateExpression(body, context as any, { skipCache: true });
}

function collect(input: Node | Node[] | undefined, out: Node[] = []): Node[] {
  if (!input) return out;
  for (const node of Array.isArray(input) ? input : [input]) {
    out.push(node);
    collect(node.children, out);
  }
  return out;
}

/** 표현식({{ }})을 주어진 변수로 계산한다 */
function evaluate(expression: string, scope: Record<string, unknown>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), 'return (' + body + ');')(...Object.values(scope));
}

const edit = editLayout as Node;
const view = viewLayout as Node;
const editNodes = collect(editPartial as Node);
const viewNodes = collect(viewPartial as Node);
const source = (layout: Node, id: string) => (layout.data_sources as Node[]).find((item) => item.id === id)!;

const FIELD_PARTIAL = 'partials/member/_member_fields.json';
const VIEW_PARTIAL = 'partials/member/_member_fields_view.json';

/** 코어 기본 항목 11개 + 운영자 추가 항목 2개 + 화면이 모르는 묶음 1개 */
const sampleFields = [
  { key: 'name', name: 'name', owner: 'core', ui: { group: 'basic' } },
  { key: 'nickname', name: 'nickname', owner: 'core', ui: { group: 'basic' } },
  { key: 'mobile', name: 'mobile', owner: 'core', ui: { group: 'contact' } },
  { key: 'phone', name: 'phone', owner: 'core', ui: { group: 'contact' } },
  { key: 'homepage', name: 'homepage', owner: 'core', ui: { group: 'contact' } },
  { key: 'language', name: 'language', owner: 'core', ui: { group: 'location' } },
  { key: 'country', name: 'country', owner: 'core', ui: { group: 'location' } },
  { key: 'timezone', name: 'timezone', owner: 'core', ui: { group: 'location' } },
  { key: 'address', name: 'address', owner: 'core', ui: { group: 'location' }, parts: { zipcode: 'zipcode', address: 'address', address_detail: 'address_detail' } },
  { key: 'signature', name: 'signature', owner: 'core', ui: { group: 'profile' } },
  { key: 'bio', name: 'bio', owner: 'core', ui: { group: 'profile' } },
  { key: 'job', name: 'custom_fields.job', owner: 'custom', ui: {} },
  { key: 'period', name: 'custom_fields.period', owner: 'custom' },
  { key: 'marketing', name: 'marketing', owner: 'sirsoft-marketing', ui: { group: 'agreements' } },
];

/**
 * 확장이 선언한 회원 항목 표본 — 게시판 모듈 둘, 이커머스 모듈 둘, 마케팅 플러그인 하나(약관 묶음)를 일부러 섞어 놓았다.
 * 마케팅 플러그인은 source_label 이 없어 제목이 owner 로 떨어지는 경우다.
 */
const extensionFields = [
  { key: 'notify_comment', name: 'notify_comment', type: 'boolean', owner: 'sirsoft-board', owner_type: 'module', source_label: '게시판', ui: {} },
  { key: 'ecommerce_preferred_currency', name: 'ecommerce_preferred_currency', type: 'select', owner: 'sirsoft-ecommerce', owner_type: 'module', source_label: '이커머스', options: [{ value: 'KRW', label: '원' }, { value: 'USD', label: '달러' }], ui: {} },
  { key: 'notify_post_reply', name: 'notify_post_reply', type: 'boolean', owner: 'sirsoft-board', owner_type: 'module', source_label: '게시판', ui: {} },
  { key: 'marketing_email', name: 'marketing_email', type: 'boolean', owner: 'sirsoft-marketing', owner_type: 'plugin', ui: { group: 'agreements' } },
  { key: 'ecommerce_preferred_shipping_country', name: 'ecommerce_preferred_shipping_country', type: 'select', owner: 'sirsoft-ecommerce', owner_type: 'module', source_label: '이커머스', ui: {} },
];

/** 코어 항목과 운영자 추가 항목 사이사이에 확장 항목을 끼워 넣은 표본 (코어와 운영자 추가 항목에는 owner_type 을 붙인다) */
const mixedFields: Node[] = [
  ...sampleFields.filter((field) => field.key !== 'marketing').map((field) => ({ ...field, owner_type: field.owner === 'custom' ? 'custom' : 'core' })),
  ...extensionFields,
].sort((a, b) => a.key.localeCompare(b.key));

/** 묶음 카드의 반복(계산값 profileFieldGroups 를 도는 것)과 확장별 카드의 반복(profileExtensionGroups 를 도는 것)을 가른다 */
const groupLoopsIn = (nodes: Node[]) => nodes.filter((node) => node.iteration?.item_var === 'memberFieldGroup' && String(node.iteration.source).includes('profileFieldGroups'));
const extensionLoopsIn = (nodes: Node[]) => nodes.filter((node) => node.iteration?.item_var === 'memberFieldGroup' && String(node.iteration.source).includes('profileExtensionGroups'));

type ExtensionGroup = { key: string; title: string; fields: Array<{ key: string }>; includeAgreements: boolean };
/** 확장별 묶음은 실제 엔진의 평가기로 계산한다 */
const extensionGroupsOf = (layout: Node, fields: unknown): ExtensionGroup[] =>
  engineComputed({ profileExtensionGroups: layout.computed.profileExtensionGroups }, { _local: {}, profileFields: fields === undefined ? undefined : { data: { fields } } }).profileExtensionGroups;

const groupsOf = (layout: Node, fields: unknown) =>
  evaluate(layout.computed.profileFieldGroups, { profileFields: fields === undefined ? undefined : { data: { fields } } }) as Array<{ key: string; fields: Array<{ key: string }> }>;

describe('내 정보 수정 — 운영자 설정을 따르는 항목', () => {
  describe('데이터소스 profileFields', () => {
    /**
     * @scenario part=data_source
     * @effects profile_fields_fetched_with_auth_alongside_user
     */
    it('로그인한 본인의 항목 계약을 user 소스와 같은 수명으로 받고, 폼 상태에는 섞지 않는다', () => {
      const fields = source(edit, 'profileFields');
      expect(fields.endpoint).toBe('/api/me/fields');
      expect(fields.method).toBe('GET');
      expect(fields.auto_fetch).toBe(true);
      expect(fields.auth_required).toBe(true);
      expect(fields.loading_strategy).toBe('progressive');
      expect(fields.refetchOnMount).toBe(true);
      // 정의는 저장 본문으로 나가는 폼 상태에 들어가면 안 된다
      expect(fields.initLocal).toBeUndefined();

      // 값은 종전대로 user 소스가 폼 상태로 채운다(운영자 추가 항목 값 custom_fields 도 이 응답에 있다)
      const user = source(edit, 'user');
      expect(user.endpoint).toBe('/api/me');
      expect(user.initLocal).toBe('profileEditForm');
      expect(user.refetchOnMount).toBe(true);
    });
  });

  describe('카드별 묶음', () => {
    /**
     * @scenario part=groups
     * @effects fields_grouped_by_ui_group_and_custom_goes_to_extra
     */
    it('항목을 ui.group 으로 묶고, 묶음이 없는 운영자 추가 항목은 추가 정보(extra)로 모은다', () => {
      const groups = groupsOf(edit, sampleFields);
      expect(groups.map((group) => group.key)).toEqual(['basic', 'contact', 'location', 'profile', 'extra']);
      const keys = Object.fromEntries(groups.map((group) => [group.key, group.fields.map((field) => field.key)]));
      expect(keys.basic).toEqual(['name', 'nickname']);
      expect(keys.contact).toEqual(['mobile', 'phone', 'homepage']);
      expect(keys.location).toEqual(['language', 'country', 'timezone', 'address']);
      expect(keys.profile).toEqual(['signature', 'bio']);
      expect(keys.extra).toEqual(['job', 'period']);
      // 약관 묶음처럼 이 화면의 카드가 아닌 묶음은 어디에도 들어가지 않는다
      expect(groups.flatMap((group) => group.fields.map((field) => field.key))).not.toContain('marketing');
    });

    /**
     * @scenario part=groups
     * @effects empty_group_is_dropped
     */
    it('항목이 하나도 없는 묶음은 목록에서 빠진다 (아직 못 받았을 때는 빈 목록)', () => {
      const withoutContact = sampleFields.filter((field) => field.ui?.group !== 'contact' && field.owner !== 'custom');
      expect(groupsOf(edit, withoutContact).map((group) => group.key)).toEqual(['basic', 'location', 'profile']);
      expect(groupsOf(edit, [])).toEqual([]);
      expect(groupsOf(edit, undefined)).toEqual([]);
    });

    /**
     * @scenario part=cards
     * @effects each_card_draws_its_group_with_shared_partial
     */
    it('카드마다 자기 묶음만 반복 변수 memberFieldGroup 으로 넘겨 공용 조각을 그린다', () => {
      // 반복 변수 memberFieldGroup 을 쓰는 반복은 묶음 카드 다섯과 확장별 카드 하나뿐이다(확장별 카드는 아래 「확장별 카드」 에서 잰다)
      const loops = groupLoopsIn(editNodes);
      expect(loops).toHaveLength(5);
      expect(editNodes.filter((node) => node.iteration?.item_var === 'memberFieldGroup')).toHaveLength(loops.length + extensionLoopsIn(editNodes).length);
      const groups = groupsOf(edit, sampleFields);
      const drawn = loops.map((loop) => {
        expect((loop.children as Node[]).map((child) => child.partial)).toEqual([FIELD_PARTIAL]);
        // 반복 안의 노드는 고정 id 를 갖지 않는다(조각 루트가 묶음 이름으로 id 를 만든다)
        expect(loop.id).toBeUndefined();
        const result = evaluate(loop.iteration.source, { $computed: { profileFieldGroups: groups } }) as Array<{ key: string }>;
        expect(result).toHaveLength(1);
        return result[0].key;
      });
      expect(drawn).toEqual(['basic', 'contact', 'location', 'extra', 'profile']);
      // 아직 못 받았으면 아무 묶음도 돌지 않는다
      for (const loop of loops) {
        expect(evaluate(loop.iteration.source, { $computed: {} })).toEqual([]);
      }
    });

    /**
     * @scenario part=cards
     * @effects basic_group_lays_out_two_columns
     */
    it('기본 묶음(이름, 닉네임)은 넓은 화면 두 칸, 좁은 화면 한 칸으로 놓는다', () => {
      const basic = groupLoopsIn(editNodes)[0];
      const [group] = evaluate(basic.iteration.source, { $computed: { profileFieldGroups: groupsOf(edit, sampleFields) } });
      expect(group.key).toBe('basic');
      expect(group.fields.map((field: Node) => field.key)).toEqual(['name', 'nickname']);
      expect(group.className).toBe('grid grid-cols-2 gap-4');
      expect(group.portableClassName).toBe('grid grid-cols-1 gap-3');
    });

    /**
     * @scenario part=cards
     * @effects card_hidden_when_group_empty
     */
    it('묶음에 항목이 없으면 그 카드를 그리지 않는다 (추가 정보 카드는 운영자 추가 항목이 있을 때만)', () => {
      const cards: Record<string, string> = {
        contact: 'profile_contact_card',
        location: 'profile_location_card',
        extra: 'profile_extra_card',
        profile: 'profile_bio_card',
      };
      const coreOnly = sampleFields.filter((field) => field.owner === 'core');
      for (const [group, id] of Object.entries(cards)) {
        const card = editNodes.find((node) => node.id === id)!;
        expect(card, id).toBeDefined();
        expect(card.iteration).toBeUndefined();
        expect(evaluate(card.if, { $computed: { profileFieldGroups: groupsOf(edit, sampleFields) } })).toBe(true);
        const without = sampleFields.filter((field) => (group === 'extra' ? field.owner !== 'custom' : field.ui?.group !== group));
        expect(evaluate(card.if, { $computed: { profileFieldGroups: groupsOf(edit, without) } })).toBe(false);
        expect(evaluate(card.if, { $computed: {} })).toBe(false);
      }
      const extra = editNodes.find((node) => node.id === 'profile_extra_card')!;
      expect(evaluate(extra.if, { $computed: { profileFieldGroups: groupsOf(edit, coreOnly) } })).toBe(false);
      expect(collect(extra).some((node) => node.text === '$t:member.fields.section_extra')).toBe(true);

      // 연락처와 위치를 감싸는 줄: 둘 다 있으면 두 칸, 하나만 있으면 한 칸, 둘 다 없으면 그리지 않는다
      const row = editNodes.find((node) => (node.children ?? []).some((child: Node) => child.id === 'profile_contact_card'))!;
      const scope = (fields: unknown[]) => ({ $computed: { profileFieldGroups: groupsOf(edit, fields) } });
      const noContact = sampleFields.filter((field) => field.ui?.group !== 'contact');
      const noBoth = noContact.filter((field) => field.ui?.group !== 'location');
      expect(evaluate(row.props.className, scope(sampleFields))).toBe('grid grid-cols-2 gap-6');
      expect(evaluate(row.props.className, scope(noContact))).toBe('grid grid-cols-1 gap-6');
      expect(evaluate(row.if, scope(noContact))).toBe(true);
      expect(evaluate(row.if, scope(noBoth))).toBe(false);
    });
  });

  describe('고정 칸과 앵커', () => {
    /**
     * @scenario part=fixed
     * @effects no_hardcoded_member_inputs
     */
    it('이름, 닉네임, 연락처, 위치, 서명, 자기소개 칸이 레이아웃에 박혀 있지 않다', () => {
      const names = editNodes.map((node) => node.props?.name).filter((name) => typeof name === 'string');
      for (const name of ['name', 'nickname', 'mobile', 'phone', 'homepage', 'country', 'language', 'timezone', 'zipcode', 'address', 'address_detail', 'signature', 'bio']) {
        expect(names, name).not.toContain(name);
      }
      // 국가 목록 정의도 화면에서 걷어 냈다(입력 컴포넌트가 갖는다)
      expect(edit.defines?.countryList).toBeUndefined();
      expect(JSON.stringify(editPartial)).not.toContain('locked_fields');
    });

    /**
     * @scenario part=fixed
     * @effects avatar_email_and_anchors_kept
     */
    it('아바타 구획, 읽기 전용 이메일과 확장 앵커는 그대로다', () => {
      const avatar = editNodes.find((node) => node.id === 'profile_edit_avatar')!;
      expect(collect(avatar).some((node) => node.name === 'AvatarUploader')).toBe(true);

      const email = editNodes.find((node) => node.name === 'Input' && node.props?.type === 'email')!;
      expect(email.props.readOnly).toBe(true);
      expect(email.props.name).toBeUndefined();
      expect(email.props.value).toBe('{{user.data?.email}}');

      // 확장이 뒤에 자기 카드를 붙이는 앵커 — 반복 밖의 고정 노드다
      const bio = editNodes.find((node) => node.id === 'profile_bio_card')!;
      expect(bio.iteration).toBeUndefined();
      // 주소 검색 확장점은 공용 조각의 주소 갈래가 갖는다
      expect(collect(memberFields as Node).some((node) => node.type === 'extension_point' && node.name === 'address_search_slot')).toBe(true);
    });

    /**
     * @scenario part=fixed
     * @effects no_hardcoded_extension_inputs
     */
    it('게시판 알림 4칸, 결제 통화, 배송 국가 칸이 레이아웃에 박혀 있지 않고, 선택지를 모듈 전역에서 읽지도 않는다', () => {
      // 폼에 이름이 박힌 입력은 표시 이름 종류 선택 칸 하나뿐이다(항목이 아니라 회원의 선택이라 이 조각이 직접 그린다).
      // 값이 실리는 나머지 칸은 모두 공용 조각이 항목 계약의 name 으로 그린다 (아이콘의 name 은 그림 이름이라 뺀다)
      const names = editNodes.filter((node) => node.name !== 'Icon').map((node) => node.props?.name).filter((name) => typeof name === 'string');
      expect(names).toEqual(['display_name_kind']);
      const raw = JSON.stringify(editPartial);
      for (const name of ['notify_post_complete', 'notify_post_reply', 'notify_comment', 'notify_reply_comment', 'ecommerce_preferred_currency', 'ecommerce_preferred_shipping_country']) {
        expect(raw, name).not.toContain('"name":"' + name + '"');
        expect(raw, name).not.toContain('profileErrors?.' + name);
      }
      expect(raw).not.toMatch(/notify_/);
      // 통화와 배송 국가 선택지는 항목 계약(options)이 준다. 이커머스 모듈의 전역 설정을 화면이 직접 읽지 않는다
      for (const text of [raw, JSON.stringify(edit)]) {
        expect(text).not.toContain('_global.modules');
        expect(text).not.toContain('sirsoft-ecommerce');
        expect(text).not.toContain('sirsoft-board');
        expect(text).not.toContain('language_currency');
      }
    });

    /**
     * @scenario part=fixed
     * @effects form_opens_after_reauth_section
     */
    it('재확인 구획 뒤에 폼 조각을 두고, 폼은 재확인을 통과해야 열린다', () => {
      const partials = collect(edit.slots.content).map((node) => node.partial).filter(Boolean);
      expect(partials).toEqual(['partials/mypage/profile/_password_verify_section.json', 'partials/mypage/profile/_edit.json']);
      expect((editPartial as Node).if).toBe('{{_local?.isPasswordVerified}}');
    });
  });

  describe('확장별 카드', () => {
    /**
     * @scenario part=extension
     * @effects extension_fields_grouped_per_owner_titled_by_source_label
     */
    it('모듈과 플러그인이 선언한 항목을 확장마다 한 묶음으로 만들고, 제목은 source_label(없으면 owner)이다', () => {
      const groups = extensionGroupsOf(edit, mixedFields);
      // 묶음 순서는 그 확장의 항목이 계약에 처음 나온 순서, 묶음 안 순서는 계약 순서다
      expect(groups.map((group) => group.key)).toEqual(['ext_sirsoft-ecommerce', 'ext_sirsoft-marketing', 'ext_sirsoft-board']);
      const byKey = Object.fromEntries(groups.map((group) => [group.key, group]));
      expect(byKey['ext_sirsoft-ecommerce'].fields.map((field) => field.key)).toEqual(['ecommerce_preferred_currency', 'ecommerce_preferred_shipping_country']);
      expect(byKey['ext_sirsoft-board'].fields.map((field) => field.key)).toEqual(['notify_comment', 'notify_post_reply']);
      expect(byKey['ext_sirsoft-ecommerce'].title).toBe('이커머스');
      expect(byKey['ext_sirsoft-board'].title).toBe('게시판');
      // source_label 이 없는 확장은 식별자가 제목이 된다
      expect(byKey['ext_sirsoft-marketing'].title).toBe('sirsoft-marketing');
      // 항목 계약(선택지 포함)은 손대지 않고 그대로 넘긴다
      expect((byKey['ext_sirsoft-ecommerce'].fields[0] as Node).options).toEqual([{ value: 'KRW', label: '원' }, { value: 'USD', label: '달러' }]);
    });

    /**
     * @scenario part=extension
     * @effects core_and_custom_fields_stay_out_of_extension_groups
     */
    it('코어 항목과 운영자 추가 항목은 확장 묶음에 들어가지 않고, 확장 항목은 묶음 카드에 들어가지 않는다', () => {
      const inExtension = extensionGroupsOf(edit, mixedFields).flatMap((group) => group.fields.map((field) => field.key));
      expect([...inExtension].sort()).toEqual(extensionFields.map((field) => field.key).sort());
      for (const key of ['name', 'mobile', 'address', 'bio', 'job', 'period']) {
        expect(inExtension, key).not.toContain(key);
      }
      // 반대 방향: 묶음 카드(basic, contact, location, profile, extra)는 확장 항목을 그리지 않는다 — 한 항목이 두 번 서지 않는다
      const values = engineComputed(edit.computed, { _local: {}, profileFields: { data: { fields: mixedFields } } });
      const inCards = (values.profileFieldGroups as Array<{ fields: Array<{ key: string }> }>).flatMap((group) => group.fields.map((field) => field.key));
      expect(inCards.filter((key) => inExtension.includes(key))).toEqual([]);
      expect([...inCards, ...inExtension].sort()).toEqual(mixedFields.map((field) => field.key).sort());
    });

    /**
     * @scenario part=extension
     * @effects extension_group_includes_agreement_fields
     */
    it('약관 묶음(ui.group = agreements) 항목도 그 확장의 묶음에 들어가고, 묶음은 includeAgreements 를 켠다', () => {
      const groups = extensionGroupsOf(edit, mixedFields);
      const marketing = groups.find((group) => group.key === 'ext_sirsoft-marketing')!;
      expect(marketing.fields.map((field) => field.key)).toEqual(['marketing_email']);
      expect(groups.map((group) => group.includeAgreements)).toEqual([true, true, true]);
      // 공용 조각의 항목 반복이 이 묶음에서는 약관 항목을 걸러 내지 않는다
      const row = collect(memberFields as Node).find((node) => node.iteration?.item_var === 'field')!;
      expect((engineExpression(row.iteration.source, { memberFieldGroup: marketing }) as Node[]).map((field) => field.key)).toEqual(['marketing_email']);
    });

    /**
     * @scenario part=extension
     * @effects no_extension_fields_means_no_extension_card
     */
    it('확장 항목이 없으면(확장이 없거나 꺼져 있거나 아직 못 받았으면) 빈 목록이라 카드가 없다', () => {
      const withoutExtensions = mixedFields.filter((field) => field.owner_type !== 'module' && field.owner_type !== 'plugin');
      expect(extensionGroupsOf(edit, withoutExtensions)).toEqual([]);
      expect(extensionGroupsOf(edit, [])).toEqual([]);
      expect(extensionGroupsOf(edit, undefined)).toEqual([]);
      // owner_type 이 없는 항목은 확장 항목으로 치지 않는다
      expect(extensionGroupsOf(edit, sampleFields)).toEqual([]);

      const [card] = extensionLoopsIn(editNodes);
      expect(engineExpression(card.iteration.source, { $computed: { profileExtensionGroups: [] } })).toEqual([]);
      expect(engineExpression(card.iteration.source, { $computed: {} })).toEqual([]);
      // 카드 자신이 반복 노드라 묶음이 없으면 제목만 남은 빈 카드도 생기지 않는다
      expect(card.if).toBeUndefined();
    });

    /**
     * @scenario part=extension
     * @effects extension_card_iterates_extension_groups_with_shared_partial
     */
    it('확장별 카드는 계산값 profileExtensionGroups 를 돌며 묶음마다 제목과 공용 조각을 그린다', () => {
      const cards = extensionLoopsIn(editNodes);
      expect(cards).toHaveLength(1);
      const [card] = cards;
      expect(card.iteration.source).toBe('{{$computed.profileExtensionGroups ?? []}}');
      // 묶음마다 다른 id 를 갖는다(확장 식별자가 들어간다)
      expect(card.id).toBe('profile_extension_card_{{memberFieldGroup.key}}');
      expect(collect(card).some((node) => node.text === '{{memberFieldGroup.title}}')).toBe(true);
      expect(collect(card).map((node) => node.partial).filter(Boolean)).toEqual([FIELD_PARTIAL]);

      const groups = extensionGroupsOf(edit, mixedFields);
      expect((engineExpression(card.iteration.source, { $computed: { profileExtensionGroups: groups } }) as ExtensionGroup[]).map((group) => group.key)).toEqual(groups.map((group) => group.key));

      // 카드는 저장 폼 안에, 서명과 자기소개 카드 뒤에 선다 — 확장 칸의 값도 폼 상태에 실려 저장 요청 한 번에 간다
      const form = editNodes.find((node) => node.name === 'Form')!;
      const children = form.children as Node[];
      expect(children).toContain(card);
      expect(children.indexOf(card)).toBeGreaterThan(children.findIndex((node) => node.id === 'profile_bio_card'));
    });

    /**
     * @scenario part=extension
     * @effects shared_partials_skip_agreements_unless_group_opts_in
     */
    it('공용 조각 둘(입력, 보기)의 약관 제외는 묶음의 includeAgreements 에 따라 갈린다', () => {
      const fields = [
        { key: 'name', ui: { group: 'basic' } },
        { key: 'marketing_email', ui: { group: 'agreements' } },
        { key: 'job' },
      ];
      for (const partial of [memberFields, memberFieldsView]) {
        const row = collect(partial as Node).find((node) => node.iteration?.item_var === 'field')!;
        const keys = (group: unknown) => (engineExpression(row.iteration.source, { memberFieldGroup: group }) as Node[]).map((field) => field.key);
        // 켜지 않은 묶음(가입 화면, 묶음 카드)은 약관 항목을 뺀다
        expect(keys({ key: 'basic', fields })).toEqual(['name', 'job']);
        expect(keys({ key: 'basic', fields, includeAgreements: false })).toEqual(['name', 'job']);
        // 켠 묶음(확장별 카드)은 약관 항목도 그린다
        expect(keys({ key: 'ext_sirsoft-marketing', fields, includeAgreements: true })).toEqual(['name', 'marketing_email', 'job']);
        expect(engineExpression(row.iteration.source, { memberFieldGroup: undefined })).toEqual([]);
      }
    });
  });

  describe('문맥과 오류', () => {
    /**
     * @scenario part=context
     * @effects context_points_to_profile_form
     */
    it('공용 조각에 넘기는 문맥이 폼 이름 profileEditForm, 저장 오류, 저장 중 여부다', () => {
      const ctx = evaluate(edit.computed.memberFieldCtx, { _local: { profileErrors: { name: ['필수'] }, isSavingProfile: true } });
      expect(ctx).toEqual({ formKey: 'profileEditForm', errors: { name: ['필수'] }, disabled: true });
      expect(evaluate(edit.computed.memberFieldCtx, { _local: {} })).toEqual({ formKey: 'profileEditForm', errors: {}, disabled: false });
    });

    /**
     * @scenario part=orphan_errors
     * @effects field_errors_not_repeated_in_top_box
     */
    it('화면의 계산값 다섯은 실제 엔진의 평가기로도 풀리고, 칸 밖 오류만 위쪽 상자 목록이 된다', () => {
      const values = engineComputed(edit.computed, {
        _local: { profileErrors: { name: ['이름은 필수입니다'], verification_token: ['인증이 만료되었습니다'] } },
        profileFields: { data: { fields: sampleFields } },
      });
      expect(values.profileOrphanErrors).toEqual(['인증이 만료되었습니다']);
      expect(Array.isArray(values.profileFieldGroups)).toBe(true);
      expect(Array.isArray(values.profileExtensionGroups)).toBe(true);
      expect(values.memberFieldCtx.formKey).toBe('profileEditForm');
      expect(Object.keys(edit.computed).sort()).toEqual(['displayNameSelected', 'memberFieldCtx', 'profileExtensionGroups', 'profileFieldGroups', 'profileOrphanErrors']);
    });

    /**
     * @scenario part=orphan_errors
     * @effects extension_field_errors_not_repeated_in_top_box
     */
    it('확장 항목의 오류(약관 묶음 항목 포함)는 확장별 카드의 칸 아래에 뜨므로 위쪽 상자에 다시 넣지 않는다', () => {
      const orphan = (errors: Record<string, unknown>) =>
        engineComputed(edit.computed, { _local: { profileErrors: errors }, profileFields: { data: { fields: mixedFields } } }).profileOrphanErrors;
      expect(orphan({
        ecommerce_preferred_currency: ['고를 수 없는 통화입니다'],
        ecommerce_preferred_shipping_country: ['배송할 수 없는 국가입니다'],
        notify_comment: ['값이 올바르지 않습니다'],
        marketing_email: ['동의 값이 올바르지 않습니다'],
        name: ['이름은 필수입니다'],
      })).toEqual([]);
      // 확장 항목 오류와 칸 밖 오류가 섞여 오면 칸 밖 오류만 남는다
      expect(orphan({ ecommerce_preferred_currency: ['고를 수 없는 통화입니다'], verification_token: ['인증이 만료되었습니다'] })).toEqual(['인증이 만료되었습니다']);
      // 그 확장이 꺼져 항목 계약에 없으면 칸이 없으니 위쪽 상자가 받는다
      const withoutExtensions = mixedFields.filter((field) => field.owner_type !== 'module' && field.owner_type !== 'plugin');
      expect(engineComputed(edit.computed, { _local: { profileErrors: { ecommerce_preferred_currency: ['고를 수 없는 통화입니다'] } }, profileFields: { data: { fields: withoutExtensions } } }).profileOrphanErrors).toEqual(['고를 수 없는 통화입니다']);
    });

    /**
     * @scenario part=orphan_errors
     * @effects field_errors_not_repeated_in_top_box
     */
    it('칸에 속한 오류(묶음 유형의 하위 경로, 주소 부분 포함)는 위쪽 상자에 다시 나오지 않는다', () => {
      const orphan = (errors: Record<string, unknown>) => evaluate(edit.computed.profileOrphanErrors, { _local: { profileErrors: errors }, profileFields: { data: { fields: sampleFields } } });
      expect(orphan({ name: ['이름은 필수입니다'], zipcode: ['우편번호 형식'], 'custom_fields.job': ['직업을 고르세요'], 'custom_fields.period.end': ['끝나는 날'] })).toEqual([]);
      expect(orphan({})).toEqual([]);
      expect(evaluate(edit.computed.profileOrphanErrors, { _local: {}, profileFields: undefined })).toEqual([]);
    });

    /**
     * @scenario part=orphan_errors
     * @effects non_field_errors_listed_in_top_box
     */
    it('어느 칸에도 속하지 않는 오류만 위쪽 상자에 모으고, 그런 오류가 없으면 상자를 그리지 않는다', () => {
      const orphan = (errors: Record<string, unknown>) => evaluate(edit.computed.profileOrphanErrors, { _local: { profileErrors: errors }, profileFields: { data: { fields: sampleFields } } });
      // 일반 실패 문구(message), 화면이 그리지 않는 항목(marketing: 묶음 카드에도 확장 묶음에도 들지 않는다), 정의에 없는 키
      expect(orphan({ name: ['이름'], message: '저장하지 못했습니다', marketing: ['동의 값'], unknown_key: ['모르는 키', '둘째'] })).toEqual(['저장하지 못했습니다', '동의 값', '모르는 키', '둘째']);

      const box = editNodes.find((node) => node.id === 'profile_orphan_errors')!;
      expect(evaluate(box.if, { $computed: { profileOrphanErrors: ['a'] } })).toBe(true);
      expect(evaluate(box.if, { $computed: { profileOrphanErrors: [] } })).toBe(false);
      expect(evaluate(box.if, { $computed: {} })).toBe(false);
      const line = collect(box).find((node) => node.iteration)!;
      expect(line.name).toBe('Li');
      expect(line.iteration.source).toBe('{{$computed.profileOrphanErrors ?? []}}');
    });
  });

  describe('저장', () => {
    /**
     * @scenario part=submit
     * @effects save_request_unchanged
     */
    it('저장 요청은 그대로다 — PUT /api/me 에 폼 상태 전체를 보내고, 성공하면 본인 정보와 로그인 사용자를 다시 받는다', () => {
      const form = editNodes.find((node) => node.name === 'Form')!;
      expect(form.dataKey).toBe('profileEditForm');
      const sequence = actionsOf(form)[0];
      expect(sequence.type).toBe('submit');
      const call = callbacksOf(sequence, 'actions').find((action: Node) => action.handler === 'apiCall')!;
      expect(call.target).toBe('/api/me');
      expect(call.params.method).toBe('PUT');
      // 폼 상태의 모든 칸이 그대로 나간다(표시 이름 종류만 가려 싣는다 — 아래 「표시 이름 종류 선택」 묶음이 잠근다)
      const sent = { name: '홍길동', nickname: '길동이', custom_fields: { job: 'dev' }, zipcode: '12345' };
      expect(evaluate(call.params.body, { _local: { profileEditForm: sent }, profileFields: { data: { display_name: { can_choose: false, options: [] } } } })).toEqual(sent);
      expect(engineExpression(call.params.body, { _local: { profileEditForm: sent }, profileFields: { data: {} } })).toEqual(sent);
      // 받은 정의 버전(fields_version)을 얹는다 — 회원 항목을 그리는 화면이라는 표식이다(없으면 서버가 옛 화면으로 보고 운영자 추가 필수 항목을 건너뛴다).
      expect(engineExpression(call.params.body, { _local: { profileEditForm: sent }, profileFields: { data: { fields_version: 'v916' } } })).toEqual({ ...sent, fields_version: 'v916' });

      // 저장 성공 처리는 확장의 전역 값을 직접 맞추지 않는다 — 확장이 current_user 재조회를 지켜보다 자기 전역 값을 맞춘다.
      const saved = callbacksOf(call, 'onSuccess');
      expect(saved.filter((action: Node) => action.handler === 'setState' && action.params.target === 'global')).toEqual([]);
      expect(saved.filter((action: Node) => action.handler === 'refetchDataSource').map((action: Node) => action.params.dataSourceId)).toEqual(['user', 'current_user']);

      // 실패하면 오류를 폼 오류 상태에 담는다(칸 아래 줄과 위쪽 상자가 이것을 읽는다)
      const fail = callbacksOf(call, 'onError').find((action: Node) => action.handler === 'setState')!;
      expect(evaluate(fail.params.profileErrors, { error: { errors: { name: ['필수'] }, message: '검증 실패' } })).toEqual({ name: ['필수'] });
    });
  });

  describe('표시 이름 종류 선택', () => {
    const byId = (id: string) => editNodes.find((node) => node.id === id)!;
    const options = [
      { value: 'name', label: '이름', preview: '홍길동', requires_nickname: false, is_default: false },
      { value: 'nickname', label: '닉네임', preview: '길동이', requires_nickname: true, is_default: true },
    ];
    const bundle = (over: Node = {}) => ({
      can_choose: true, value: null, value_unavailable: false, unavailable_kind: null, default_kind: 'nickname', default_kind_label: '닉네임',
      effective_kind: 'nickname', current: '길동이', options, preview_is_sample: false, nickname_requirement: 'chosen', ...over,
    });
    const fieldsWith = (over: Node = {}) => ({ data: { fields: sampleFields, display_name: bundle(over) } });
    const selectedOf = (form: Node, over: Node = {}) =>
      engineComputed({ displayNameSelected: edit.computed.displayNameSelected }, { _local: { profileEditForm: form }, profileFields: fieldsWith(over) }).displayNameSelected;

    /**
     * @scenario part=display_name
     * @effects display_name_select_drawn_only_when_member_can_choose
     */
    it('선택 칸은 고를 수 있을 때만(can_choose) 닉네임 묶음 아래에 그리고, 권한이 없으면 칸이 없다', () => {
      const field = byId('profile_display_name_field');
      expect(evaluate(field.if, { profileFields: fieldsWith() })).toBe(true);
      expect(evaluate(field.if, { profileFields: fieldsWith({ can_choose: false, options: [] }) })).toBe(false);
      // 아직 못 받았거나 옛 서버(display_name 묶음 없음)면 그리지 않는다
      expect(evaluate(field.if, { profileFields: undefined })).toBe(false);
      expect(evaluate(field.if, { profileFields: { data: { fields: [] } } })).toBe(false);

      // 기본 묶음(이름, 닉네임) 반복 바로 뒤, 같은 카드 안이다
      const card = editNodes.find((node) => (node.children ?? []).some((child: Node) => child.id === 'profile_display_name_field'))!;
      const at = card.children.findIndex((child: Node) => child.id === 'profile_display_name_field');
      expect(String(card.children[at - 1].iteration?.source)).toContain("g.key === 'basic'");

      const select = byId('profile_display_name_select');
      expect(select.name).toBe('Select');
      expect(select.props.name).toBe('display_name_kind');
      expect(select.props.autoBinding).toBe(false);
      expect(select.props['aria-label']).toBe('$t:member.display_name.label');
    });

    /**
     * @scenario part=display_name
     * @effects display_name_options_start_with_default_and_list_allowed_kinds
     */
    it('선택지는 「기본값(회원 기본 종류)」 뒤에 고를 수 있는 종류가 서고, 지금 값이 고를 수 없는 종류면 빈 값으로 보인다', () => {
      const select = byId('profile_display_name_select');
      const $t = (key: string, params: Node) => key + ':' + params.label;
      const list = evaluate(select.props.options, { profileFields: fieldsWith(), $t });
      expect(list).toEqual([
        { value: '', label: 'member.display_name.option_default:닉네임' },
        { value: 'name', label: '이름' },
        { value: 'nickname', label: '닉네임' },
      ]);
      expect(evaluate(select.props.value, { profileFields: fieldsWith(), _local: { profileEditForm: { display_name_kind: 'name' } } })).toBe('name');
      expect(evaluate(select.props.value, { profileFields: fieldsWith(), _local: { profileEditForm: { display_name_kind: null } } })).toBe('');
      expect(evaluate(select.props.value, { profileFields: fieldsWith(), _local: { profileEditForm: { display_name_kind: 'masked_name' } } })).toBe('');
    });

    /**
     * @scenario part=display_name
     * @effects display_name_reselect_notice_names_previous_kind
     */
    it('U21: 전에 고른 종류가 허용에서 빠졌으면 다시 골라 달라는 안내와 전에 고른 종류 이름을 보이고, 다시 고르면 거둔다', () => {
      const notice = byId('profile_display_name_unavailable');
      const stale = { value_unavailable: true, unavailable_kind: { value: 'masked_name', label: '가린 이름' } };
      expect(notice.props.role).toBe('status');
      expect(evaluate(notice.if, { profileFields: fieldsWith(stale), _local: { profileEditForm: { display_name_kind: 'masked_name' } } })).toBe(true);
      expect(evaluate(notice.if, { profileFields: fieldsWith(stale), _local: { profileEditForm: { display_name_kind: 'name' } } })).toBe(false);
      expect(evaluate(notice.if, { profileFields: fieldsWith(), _local: { profileEditForm: { display_name_kind: null } } })).toBe(false);

      const $t = (key: string, params: Node) => key + ':' + params.kind + ':' + params.fallback;
      expect(evaluate(notice.text, { profileFields: fieldsWith(stale), $t })).toBe('member.display_name.reselect:가린 이름:닉네임');
      // 그동안은 회원 기본 종류로 보인다 — 미리보기도 기본 종류의 것이다
      expect(selectedOf({ display_name_kind: 'masked_name' }, stale).value).toBe('nickname');
    });

    /**
     * @scenario part=display_name
     * @effects display_name_preview_follows_selected_kind_and_recomputed_values
     */
    it('U15: 미리보기는 지금 적용될 종류의 것이고, 종류를 바꾸면 입력해 둔 이름과 닉네임으로 다시 계산한 값으로 바뀐다', () => {
      expect(selectedOf({ display_name_kind: 'name' }).preview).toBe('홍길동');
      expect(selectedOf({ display_name_kind: null }).preview).toBe('길동이');
      expect(selectedOf({}, { options: [], can_choose: false })).toBeNull();

      const preview = byId('profile_display_name_preview');
      const $t = (key: string, params: Node) => key + ':' + params.name;
      const scope = (previews: unknown, form: Node) => ({ _local: { displayNamePreviews: previews }, $computed: { displayNameSelected: selectedOf(form) }, $t });
      expect(evaluate(preview.text, scope(null, { display_name_kind: 'name' }))).toBe('member.display_name.preview:홍길동');
      expect(evaluate(preview.text, scope({ name: '김철수', nickname: '철수짱' }, { display_name_kind: 'name' }))).toBe('member.display_name.preview:김철수');
      expect(evaluate(preview.if, scope(null, { display_name_kind: 'name' }))).toBe(true);
      expect(evaluate(preview.if, { _local: {}, $computed: { displayNameSelected: null } })).toBe(false);

      // 종류를 바꿀 때만 한 번 묻는다(입력마다 묻지 않는다) — 폼의 이름과 닉네임을 조회 인자로 싣는다
      const select = byId('profile_display_name_select');
      const sequence = actionsOf(select)[0];
      expect(sequence.type).toBe('change');
      const steps = callbacksOf(sequence, 'actions');
      expect(steps[0].handler).toBe('setState');
      expect(steps[0].params['profileEditForm.display_name_kind']).toBe('{{$event.target.value}}');
      expect(steps[1].handler).toBe('apiCall');
      expect(steps[1].params.method).toBe('GET');
      expect(steps[1].auth_required).toBe(true);
      expect(steps[1].target).toBe("/api/me/fields?preview_name={{encodeURIComponent(_local.profileEditForm?.name ?? '')}}&preview_nickname={{encodeURIComponent(_local.profileEditForm?.nickname ?? '')}}");
      const store = callbacksOf(steps[1], 'onSuccess')[0];
      expect(evaluate(store.params.displayNamePreviews, { response: { data: { display_name: { options: [{ value: 'name', preview: '김철수' }, { value: 'masked_id', preview: null }] } } } })).toEqual({ name: '김철수', masked_id: '' });
      // 화면에 들어올 때 앞선 계산값을 지운다
      const reset = (edit.init_actions as Node[]).find((action) => action.handler === 'setState' && 'displayNamePreviews' in (action.params ?? {}))!;
      expect(reset.params.displayNamePreviews).toBeNull();
    });

    /**
     * @scenario part=display_name
     * @effects display_name_nickname_requirement_announced_and_error_under_nickname
     */
    it('닉네임이 필요한 종류면 닉네임을 입력하라고 알리고, 서버의 닉네임 오류는 닉네임 칸 아래에, 종류 오류는 선택 칸 아래에 뜬다', () => {
      const hint = byId('profile_display_name_nickname_required');
      expect(hint.text).toBe('$t:member.display_name.requires_nickname');
      expect(evaluate(hint.if, { $computed: { displayNameSelected: selectedOf({ display_name_kind: 'nickname' }) } })).toBe(true);
      expect(evaluate(hint.if, { $computed: { displayNameSelected: selectedOf({ display_name_kind: 'name' }) } })).toBe(false);
      // 고르지 않았어도 회원 기본 종류가 닉네임이 필요한 종류면 알린다
      expect(evaluate(hint.if, { $computed: { displayNameSelected: selectedOf({}) } })).toBe(true);

      // 422 의 errors.nickname 은 공용 조각이 닉네임 칸 아래(오류 줄 mf_error_{키})에 그린다 — 문맥의 errors 를 항목의 폼 경로로 찾는다
      const errorLine = collect(memberFields as Node).find((node) => String(node.id ?? '').startsWith('mf_error_'))!;
      expect(errorLine).toBeTruthy();
      expect(JSON.stringify(errorLine)).toContain('memberFieldCtx');
      // 종류 오류는 선택 칸 아래에 뜨고 위쪽 상자에 다시 나오지 않는다
      const error = byId('profile_display_name_error');
      expect(evaluate(error.text, { _local: { profileErrors: { display_name_kind: ['고를 수 없는 종류입니다'] } } })).toBe('고를 수 없는 종류입니다');
      const values = engineComputed(edit.computed, {
        _local: { profileErrors: { display_name_kind: ['고를 수 없는 종류입니다'], nickname: ['닉네임은 필수입니다'], verification_token: ['만료'] } },
        profileFields: fieldsWith(),
      });
      expect(values.profileOrphanErrors).toEqual(['만료']);
    });

    /**
     * @scenario part=display_name
     * @effects display_name_kind_sent_only_when_choosable_and_valid
     */
    it('저장 본문은 고를 수 있고 허용된 종류(또는 빈 값)일 때만 display_name_kind 를 싣는다', () => {
      const form = editNodes.find((node) => node.name === 'Form')!;
      const call = callbacksOf(actionsOf(form)[0], 'actions').find((action: Node) => action.handler === 'apiCall')!;
      const bodyOf = (profileEditForm: Node, over?: Node | null) =>
        engineExpression(call.params.body, { _local: { profileEditForm }, profileFields: over === null ? undefined : fieldsWith(over ?? {}) });

      // 고른 종류가 허용된 종류면 싣는다
      expect(bodyOf({ name: '홍길동', display_name_kind: 'name' })).toEqual({ name: '홍길동', display_name_kind: 'name' });
      // 고르지 않음(빈 값, null)도 싣는다 — 서버가 회원 기본 종류로 되돌린다
      expect(bodyOf({ name: '홍길동', display_name_kind: '' })).toEqual({ name: '홍길동', display_name_kind: '' });
      expect(bodyOf({ name: '홍길동', display_name_kind: null })).toEqual({ name: '홍길동', display_name_kind: null });
      // 권한이 없으면 싣지 않는다(서버가 422 로 저장 전체를 거절한다)
      expect(bodyOf({ name: '홍길동', display_name_kind: 'name' }, { can_choose: false, options: [] })).toEqual({ name: '홍길동' });
      // U21: 허용에서 빠진 옛 종류를 다시 고르지 않은 채 저장하면 싣지 않는다(지금 값을 그대로 둔다)
      expect(bodyOf({ name: '홍길동', display_name_kind: 'masked_name' }, { value_unavailable: true })).toEqual({ name: '홍길동' });
      // 항목 계약을 아직 못 받았으면 싣지 않는다
      expect(bodyOf({ name: '홍길동', display_name_kind: 'name' }, null)).toEqual({ name: '홍길동' });
    });

    /**
     * @scenario part=display_name
     * @effects profile_save_refreshes_header_user
     */
    it('T5-b: 저장에 성공하면 로그인 사용자(current_user)를 다시 받아 헤더의 이름이 새로고침 없이 바뀐다', () => {
      const form = editNodes.find((node) => node.name === 'Form')!;
      const call = callbacksOf(actionsOf(form)[0], 'actions').find((action: Node) => action.handler === 'apiCall')!;
      const refetched = callbacksOf(call, 'onSuccess').filter((action: Node) => action.handler === 'refetchDataSource').map((action: Node) => action.params.dataSourceId);
      expect(refetched).toContain('current_user');
    });
  });

  describe('공용 조각의 잠금과 파일 갈래가 이 화면에 닿는다', () => {
    /**
     * @scenario part=locked
     * @effects locked_notice_comes_from_field_contract
     */
    it('잠금 안내는 항목 계약의 readonly 와 locked_reason 으로 고른다 (종전의 user.data.locked_fields 대조는 없다)', () => {
      const nodes = collect(memberFields as Node);
      const byText = (text: string) => nodes.find((node) => node.text === text)!;
      const cases: Array<[string, Record<string, unknown>]> = [
        ['$t:mypage.profile.locked_by_company', { readonly: true, locked_reason: 'sso' }],
        ['$t:member.fields.locked_identity', { readonly: true, locked_reason: 'identity' }],
        ['$t:member.fields.locked_admin_only', { readonly: true, locked_reason: 'admin_only' }],
        ['$t:member.fields.locked_until|date={{field.locked_until}}', { readonly: true, locked_reason: 'nickname_interval', locked_until: '2026-12-11 17:23' }],
        ['$t:member.fields.locked_generic', { readonly: true, locked_reason: 'locked' }],
      ];
      for (const [text, field] of cases) {
        expect(byText(text), text).toBeDefined();
        expect(evaluate(byText(text).if, { field })).toBe(true);
        expect(evaluate(byText(text).if, { field: { ...field, readonly: false } })).toBe(false);
      }
      expect(JSON.stringify(memberFields)).not.toContain('locked_fields');
    });

    /**
     * @scenario part=file
     * @effects file_actions_refetch_this_screens_field_source
     */
    it('파일 올리기와 지우기가 다시 받는 데이터소스가 이 화면에 있다', () => {
      const actions: Node[] = [];
      const walk = (value: unknown) => {
        if (Array.isArray(value)) return value.forEach(walk);
        if (value && typeof value === 'object') {
          const item = value as Node;
          if (item.handler) actions.push(item);
          Object.values(item).forEach(walk);
        }
      };
      walk(memberFields);
      const calls = actions.filter((action) => action.handler === 'apiCall');
      expect(calls.map((action) => [action.params.method, action.target])).toEqual([
        ['DELETE', '/api/me/fields/{{field.key}}/file'],
        ['POST', '/api/me/fields/{{field.key}}/file'],
      ]);
      const refetched = [...new Set(actions.filter((action) => action.handler === 'refetchDataSource').map((action) => action.params.dataSourceId))];
      expect(refetched).toEqual(['profileFields']);
      expect(source(edit, 'profileFields')).toBeDefined();
    });
  });
});

describe('내 정보 보기 — 수정 화면과 같은 항목', () => {
  /**
   * @scenario part=view
   * @effects view_uses_same_source_and_grouping
   */
  it('보기 화면도 profileFields 를 받고 수정 화면과 같은 식으로 묶는다', () => {
    const fields = source(view, 'profileFields');
    expect(fields.endpoint).toBe('/api/me/fields');
    expect(fields.auth_required).toBe(true);
    expect(fields.auto_fetch).toBe(true);
    expect(view.computed.profileFieldGroups).toBe(edit.computed.profileFieldGroups);
    // 확장별 묶음도 수정 화면과 같은 식이다
    expect(view.computed.profileExtensionGroups).toBe(edit.computed.profileExtensionGroups);
    expect(view.computed.currentTab).toBe('profile');
  });

  /**
   * @scenario part=view
   * @effects view_cards_draw_groups_with_view_partial
   */
  it('연락처, 위치, 추가 정보, 서명·자기소개 카드가 보기 조각으로 자기 묶음을 그리고, 빈 묶음 카드는 그리지 않는다', () => {
    const loops = groupLoopsIn(viewNodes);
    const groups = groupsOf(view, sampleFields);
    const drawn = loops.map((loop) => {
      expect((loop.children as Node[]).map((child) => child.partial)).toEqual([VIEW_PARTIAL]);
      return (evaluate(loop.iteration.source, { $computed: { profileFieldGroups: groups } }) as Array<{ key: string }>)[0].key;
    });
    expect(drawn).toEqual(['contact', 'location', 'extra', 'profile']);

    const coreOnly = sampleFields.filter((field) => field.owner === 'core');
    for (const [id, group] of [['profile_view_contact_card', 'contact'], ['profile_view_location_card', 'location'], ['profile_view_extra_card', 'extra'], ['profile_view_bio_card', 'profile']]) {
      const card = viewNodes.find((node) => node.id === id)!;
      expect(card, id).toBeDefined();
      expect(card.iteration).toBeUndefined();
      expect(evaluate(card.if, { $computed: { profileFieldGroups: groups } })).toBe(true);
      const without = sampleFields.filter((field) => (group === 'extra' ? field.owner !== 'custom' : field.ui?.group !== group));
      expect(evaluate(card.if, { $computed: { profileFieldGroups: groupsOf(view, without) } })).toBe(false);
    }
    expect(evaluate(viewNodes.find((node) => node.id === 'profile_view_extra_card')!.if, { $computed: { profileFieldGroups: groupsOf(view, coreOnly) } })).toBe(false);
  });

  /**
   * @scenario part=view
   * @effects view_has_no_hardcoded_member_values_and_keeps_anchors
   */
  it('연락처, 위치, 서명, 자기소개와 확장의 값(알림, 통화, 배송 국가)이 레이아웃에 박혀 있지 않고, 맨 위 프로필 카드와 탭 영역은 그대로다', () => {
    const raw = JSON.stringify(viewPartial);
    for (const key of ['mobile', 'phone', 'homepage', 'country_name', 'language_label', 'timezone', 'zipcode', 'address_detail', 'signature', 'bio']) {
      expect(raw, key).not.toContain('user.data?.' + key);
    }
    // 게시판 알림, 결제 통화, 배송 국가 표시도 걷어 냈다 — 확장별 카드가 항목 계약의 보기용 값으로 그린다
    expect(raw).not.toMatch(/notify_/);
    expect(raw).not.toMatch(/ecommerce_/);
    for (const text of [raw, JSON.stringify(view)]) {
      expect(text).not.toContain('_global.modules');
      expect(text).not.toContain('sirsoft-ecommerce');
      expect(text).not.toContain('sirsoft-board');
    }
    // 맨 위 프로필 카드(아바타, 이름, 닉네임, 이메일)는 이 조각이 직접 그린다
    expect(raw).toContain('user.data?.name');
    expect(raw).toContain('user.data?.email');
    expect(viewNodes.some((node) => node.name === 'AvatarUploader')).toBe(true);
    // 서명과 자기소개 카드는 반복 밖의 고정 노드로 남는다
    expect(viewNodes.find((node) => node.id === 'profile_view_bio_card')!.iteration).toBeUndefined();

    const tab = collect(view.slots.content).find((node) => node.id === 'mypage_tab_content')!;
    expect((tab.children as Node[]).map((child) => child.partial)).toEqual(['partials/mypage/profile/_view.json']);
  });

  /**
   * @scenario part=view
   * @effects view_extension_card_draws_extension_groups_with_view_partial
   */
  it('보기 화면도 확장별 카드가 같은 묶음을 돌며 제목과 보기 조각을 그리고, 확장 항목이 없으면 카드가 없다', () => {
    const cards = extensionLoopsIn(viewNodes);
    expect(cards).toHaveLength(1);
    const [card] = cards;
    expect(card.iteration.source).toBe('{{$computed.profileExtensionGroups ?? []}}');
    expect(card.id).toBe('profile_view_extension_card_{{memberFieldGroup.key}}');
    expect(card.if).toBeUndefined();
    expect(collect(card).some((node) => node.text === '{{memberFieldGroup.title}}')).toBe(true);
    expect(collect(card).map((node) => node.partial).filter(Boolean)).toEqual([VIEW_PARTIAL]);

    const groups = extensionGroupsOf(view, mixedFields);
    expect(groups.map((group) => [group.key, group.title])).toEqual([
      ['ext_sirsoft-ecommerce', '이커머스'],
      ['ext_sirsoft-marketing', 'sirsoft-marketing'],
      ['ext_sirsoft-board', '게시판'],
    ]);
    expect((engineExpression(card.iteration.source, { $computed: { profileExtensionGroups: groups } }) as ExtensionGroup[]).map((group) => group.key)).toEqual(groups.map((group) => group.key));
    expect(extensionGroupsOf(view, sampleFields)).toEqual([]);
    expect(engineExpression(card.iteration.source, { $computed: {} })).toEqual([]);

    // 서명과 자기소개 카드 뒤에 선다(수정 화면과 같은 자리)
    const siblings = viewNodes.find((node) => (node.children ?? []).includes(card))!.children as Node[];
    expect(siblings.indexOf(card)).toBeGreaterThan(siblings.findIndex((node) => node.id === 'profile_view_bio_card'));
  });
});
