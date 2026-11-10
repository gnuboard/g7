/**
 * @file admin-user-form-passwordless.test.ts
 * @description 회원 추가 「비밀번호 없이 만들기」 회귀 테스트 (sirsoft-admin_basic, 7.2.0 A2t·K11)
 *
 * - 추가 모드에만 체크박스 `create_without_password` 가 있다. 자동 바인딩을 끄고 change 로 boolean 을 직접 담는다
 *   (빈 문자열이 실리면 서버에서 null 로 굳는다). 켜면 이미 적은 `form.password`·`form.password_confirmation` 을 비운다 —
 *   서버는 둘을 함께 받으면 422 로 거절한다.
 * - 켜져 있으면 비밀번호 두 칸과 규칙 안내가 숨는다. 수정 모드에는 체크박스가 없고 비밀번호 칸은 늘 보인다.
 * - 저장 본문은 `_local.form` 그대로라 플래그가 실려 나간다.
 *
 * @since 1.2.0
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import userFormLayout from '../../layouts/admin_user_form.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

type Json = Record<string, any>;

/**
 * 트리를 평탄화합니다 (자식·슬롯·모달 포함).
 *
 * @param node 노드
 * @returns 노드 목록
 */
function flatten(node: any): Json[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  // Modal 고정 하단(props.footer)의 단추도 걷는다(#869 P1 — 바닥 단추를 footer 로 옮김)
  const footer = Array.isArray(node.props?.footer) ? node.props.footer.flatMap(flatten) : [];
  const slots = node.slots ? Object.values(node.slots).flatMap(flatten) : [];
  const modals = Array.isArray(node.modals) ? node.modals.flatMap(flatten) : [];
  return [node, ...children, ...footer, ...slots, ...modals];
}

/**
 * 액션을 평탄화합니다 — 원소 하나짜리 배열은 객체로 접혀 굽히므로(D67) asArray 로 정규화한다.
 *
 * @param actions 액션(들)
 * @returns 액션 목록
 */
function flattenActions(actions: any): Json[] {
  if (!actions) return [];
  return asArray<Json>(actions).flatMap((a) => [
    a,
    ...flattenActions(a.actions),
    ...flattenActions(a.onSuccess),
    ...flattenActions(a.onError),
  ]);
}

/**
 * `{{식}}` 을 주어진 값으로 계산합니다.
 *
 * @param expr 식 문자열
 * @param scope 계산에 쓸 값
 * @returns 계산 결과
 */
function evaluate(expr: string, scope: Json): any {
  const body = String(expr).replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope));
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

const nodes = flatten(userFormLayout);
const byId = (id: string): Json | undefined => nodes.find((n) => n.id === id);
const field = byId('field_create_without_password');
const checkbox = flatten(field).find((n) => n.name === 'Checkbox');
const change = asArray<Json>(checkbox?.actions).find((a) => a.type === 'change');
const steps = asArray<Json>(change?.actions);

describe('sirsoft-admin_basic 회원 추가 「비밀번호 없이 만들기」 (A2t)', () => {
  /**
   * @scenario mode=create|edit
   * @effects create_without_password_only_in_create_mode
   */
  it('체크박스는 추가 모드에만 있다', () => {
    expect(field).toBeDefined();
    expect(Boolean(evaluate(field?.if, { route: {} }))).toBe(true);
    expect(Boolean(evaluate(field?.if, { route: { id: '0199-uuid' } }))).toBe(false);

    const texts = flatten(field).map((n) => n.text).filter(Boolean);
    expect(texts).toContain('$t:admin.users.form.fields.create_without_password');
    expect(texts).toContain('$t:admin.users.form.fields.create_without_password_hint');
  });

  /**
   * @scenario mode=create, checked=true|false
   * @effects checkbox_stores_boolean_and_clears_password
   */
  it.each([true, false])('체크=%s — 자동 바인딩 없이 boolean 을 담고, 켤 때만 비밀번호 두 칸을 비운다', (checked) => {
    expect(checkbox?.props?.name).toBe('create_without_password');
    expect(checkbox?.props?.autoBinding).toBe(false);
    expect(evaluate(checkbox?.props?.checked, { _local: { form: { create_without_password: checked } } })).toBe(checked);
    // 빈 문자열 같은 값은 켠 것으로 보지 않는다
    expect(evaluate(checkbox?.props?.checked, { _local: { form: { create_without_password: '' } } })).toBe(false);

    expect(change?.handler).toBe('sequence');
    const $event = { target: { checked } };
    const store = steps.find((a) => a.params?.['form.create_without_password'] !== undefined);
    const value = evaluate(store?.params?.['form.create_without_password'], { $event });
    expect(typeof value).toBe('boolean');
    expect(value).toBe(checked);
    expect(store?.params?.hasChanges).toBe(true);

    const clear = steps.find((a) => a.params?.['form.password'] !== undefined);
    expect(clear?.params).toMatchObject({ target: 'local', 'form.password': '', 'form.password_confirmation': '' });
    expect(Boolean(evaluate(clear?.if, { $event }))).toBe(checked);
  });

  /**
   * @scenario mode=create|edit, checked=true|false
   * @effects password_fields_hidden_when_passwordless
   */
  it.each([
    ['create', {}, true, false],
    ['create', {}, false, true],
    ['edit', { id: '0199-uuid' }, true, true],
    ['edit', { id: '0199-uuid' }, false, true],
  ])('%s 모드, 체크=%s — 비밀번호 두 칸·규칙 안내 보임=%s', (_mode, route, checked, visible) => {
    const group = byId('field_password_group');
    const rules = byId('password_rules_hint');
    // 수정 모드는 회원 상세가 비밀번호를 만들 수 있다고 할 때(판정 없음 포함) 칸을 보인다 — 막힌 회원은 admin-user-form-password-unsettable 이 잰다
    const scope = { route, user: { data: { password_status: {} } }, _local: { form: { create_without_password: checked } } };

    const inGroup = flatten(group).map((n) => n.id);
    expect(inGroup).toContain('field_password');
    expect(inGroup).toContain('field_password_confirmation');
    expect(Boolean(evaluate(group?.if, scope))).toBe(visible);
    expect(Boolean(evaluate(rules?.if, scope))).toBe(visible);
  });

  /**
   * @scenario mode=create, checked=true
   * @effects save_body_carries_passwordless_flag
   */
  it('저장 요청 본문은 _local.form 그대로라 create_without_password 가 함께 나간다', () => {
    const saves = flattenActions(nodes.flatMap((n) => asArray(n.actions))).filter(
      (a) => a.handler === 'apiCall' && String(a.target).includes("'/api/admin/users'"),
    );
    expect(saves.length).toBeGreaterThan(0);
    for (const save of saves) {
      expect(save.params?.body).toBe('{{_local.form}}');
      expect(evaluate(save.params?.method, { route: {} })).toBe('POST');
    }
  });

  /**
   * @scenario locale=ko|en
   * @effects passwordless_create_lang_keys_present
   */
  it.each([
    ['ko', adminKo],
    ['en', adminEn],
  ])('%s lang 에 체크박스 문구 키가 있다', (_locale, lang) => {
    for (const key of ['users.form.fields.create_without_password', 'users.form.fields.create_without_password_hint']) {
      expect(typeof langAt(lang as Json, key), key).toBe('string');
    }
  });

  /**
   * @scenario mode=create|edit
   * @effects password_badge_follows_create_without_password
   */
  it('「비밀번호」 배지는 추가 모드에서 「비밀번호 없이 만들기」를 켜면 「미설정」, 끄면 「설정됨」이다(수정 모드는 서버 값)', () => {
    const badge = byId('field_has_password');
    const spans = flatten(badge).filter((n) => typeof n.text === 'string' && n.text.startsWith('$t:admin.users.detail.password.'));
    const shown = (scope: Json) => spans.filter((n) => Boolean(evaluate(n.if, scope))).map((n) => n.text);

    expect(shown({ route: {}, _local: { form: { create_without_password: true } } })).toEqual(['$t:admin.users.detail.password.not_set']);
    expect(shown({ route: {}, _local: { form: {} } })).toEqual(['$t:admin.users.detail.password.set']);
    expect(shown({ route: { id: 'u' }, _local: { form: {} }, user: { data: { has_password: false } } })).toEqual(['$t:admin.users.detail.password.not_set']);
    expect(shown({ route: { id: 'u' }, _local: { form: {} }, user: { data: { has_password: true } } })).toEqual(['$t:admin.users.detail.password.set']);
  });
});

