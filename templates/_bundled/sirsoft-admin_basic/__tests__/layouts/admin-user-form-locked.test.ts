/**
 * @file admin-user-form-locked.test.ts
 * @description 회원 수정 화면의 회사 계정 관리 칸 잠금 회귀 테스트 (sirsoft-admin_basic, 7.2.0 A2t·K8)
 *
 * 수정 모드에서 회사 계정이 관리하는 칸은 비활성이고 칸 아래 「회사 계정에서 관리하는 항목입니다」
 * (`admin.users.form.fields.locked_by_company`)를 보인다. 잠글 수 있는 칸 목록은 서버
 * `UserLockedFieldsService::LOCKABLE` 을 읽어 대조한다 — 서버가 칸을 늘렸는데 화면이 따라오지 않으면 실패한다.
 *
 * 잠금이 오는 길은 둘이다(#490 6단계부터).
 * - 이메일은 화면의 고정 칸이라 종전대로 회원 응답 `locked_fields` 를 읽는다(수정 모드에서는 계정 식별자라 늘 잠긴다).
 * - 그 밖의 칸(이름, 닉네임, 휴대폰, 전화번호, 언어)은 회원 항목이라 공용 조각(partials/member/_member_fields)이 그린다.
 *   서버가 항목 계약에 `readonly: true`, `locked_reason: 'sso'` 를 실어 주면 조각이 항목을 통째로 입력 컴포넌트에 넘겨 잠그고
 *   (컴포넌트의 잠금은 `MemberFieldInput.test.tsx` 가 잰다) 칸 아래에 같은 안내를 보인다.
 * 추가 모드는 잠금이 없다 — 항목 계약을 회원 없는 주소에서 받으므로 잠금이 실려 오지 않고, 이메일 칸은 `route.id` 로 막는다
 * (수정 → 추가 SPA 이동 뒤에도 `user` 데이터소스에 직전 회원 응답이 남아 있다).
 *
 * @since 1.2.0
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, expect } from 'vitest';

import userFormLayout from '../../layouts/admin_user_form.json';
import memberFieldsPartial from '../../layouts/partials/member/_member_fields.json';
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
 * 서버가 잠글 수 있는 칸 목록(`UserLockedFieldsService::LOCKABLE`)을 읽습니다.
 *
 * @returns 칸 이름 목록
 */
function lockableFields(): string[] {
  const source = fs.readFileSync(path.join(repositoryRoot(), 'app/Services/UserLockedFieldsService.php'), 'utf-8');
  const matched = /const\s+LOCKABLE\s*=\s*\[([^\]]*)\]/.exec(source);
  expect(matched, 'UserLockedFieldsService::LOCKABLE 를 찾지 못했습니다.').not.toBeNull();
  return [...matched![1].matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
}

/**
 * 코어가 선언한 회원 항목의 키와 묶음을 읽습니다 (`config/core.php` 의 `fields`).
 *
 * @returns 항목 키 => 묶음(ui.group)
 */
function coreMemberFieldGroups(): Record<string, string> {
  const source = fs.readFileSync(path.join(repositoryRoot(), 'config/core.php'), 'utf-8');
  const groups: Record<string, string> = {};
  for (const match of source.matchAll(/'field_set' => 'core\.user', 'key' => '([a-z_]+)'[\s\S]*?'ui' => \['group' => '([a-z_]+)'\]/g)) {
    groups[match[1]] = match[2];
  }
  return groups;
}

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
  const slots = node.slots ? Object.values(node.slots).flatMap(flatten) : [];
  const modals = Array.isArray(node.modals) ? node.modals.flatMap(flatten) : [];
  return [node, ...children, ...slots, ...modals];
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

const NOTICE = '$t:admin.users.form.fields.locked_by_company';
const nodes = flatten(userFormLayout);
const partialNodes = flatten(memberFieldsPartial);
const LOCKABLE = lockableFields();
/** 회원 항목으로 그리는 잠금 대상 칸 — 이메일만 화면의 고정 칸이다 */
const FIELD_LOCKABLE = LOCKABLE.filter((field) => field !== 'email');
const CORE_GROUPS = coreMemberFieldGroups();

/**
 * 칸 이름으로 입력 노드와 그 칸의 잠금 안내 노드를 찾습니다(같은 부모 아래 형제).
 *
 * @param field 칸 이름
 * @returns 입력 노드와 안내 노드
 */
function control(field: string): { input?: Json; notice?: Json } {
  const parent = nodes.find((n) => Array.isArray(n.children) && n.children.some((c: Json) => c.props?.name === field && ['Input', 'Select'].includes(c.name)));
  const input = parent?.children.find((c: Json) => c.props?.name === field);
  const notice = parent?.children.find((c: Json) => c.text === NOTICE);
  return { input, notice };
}

const scope = (route: Json, locked: string[]) => ({ route, user: { data: { locked_fields: locked } } });
const EDIT = { id: '0199-uuid' };

const partialInput = partialNodes.find((n) => n.name === 'MemberFieldInput')!;
const partialNotice = partialNodes.find((n) => n.text === NOTICE)!;

describe('sirsoft-admin_basic 회원 수정 잠금 칸 (A2t)', () => {
  /**
   * @scenario mode=edit
   * @effects locked_fields_disabled_in_edit_mode
   */
  it('서버 잠금 대상 목록을 읽었다', () => {
    expect(LOCKABLE).toEqual(expect.arrayContaining(['name', 'nickname', 'mobile', 'phone', 'language', 'email']));
  });

  /**
   * @scenario mode=edit, locked=yes|no
   * @effects locked_fields_disabled_in_edit_mode
   */
  it('수정 모드 — 이메일(고정 칸)은 locked_fields 와 무관하게 늘 disabled 다', () => {
    const { input } = control('email');
    expect(input, 'email 칸').toBeDefined();

    expect(Boolean(evaluate(input?.props?.disabled, scope(EDIT, ['email'])))).toBe(true);
    // 이메일은 계정 식별자라 수정 모드에서 늘 잠겨 있다(종전 그대로)
    expect(Boolean(evaluate(input?.props?.disabled, scope(EDIT, [])))).toBe(true);
    // 응답에 키가 없어도(구버전 응답) 깨지지 않는다
    expect(() => evaluate(input?.props?.disabled, { route: EDIT, user: { data: {} } })).not.toThrow();
  });

  /**
   * @scenario mode=edit, locked=yes|no
   * @effects locked_fields_disabled_in_edit_mode
   */
  it.each(FIELD_LOCKABLE)('수정 모드 — %s 는 회원 항목 조각이 그리고, 서버가 준 잠금(field.readonly)을 입력 컴포넌트에 그대로 넘긴다', (field) => {
    // 고정 칸으로 남아 있지 않다 — 남아 있으면 조각의 칸과 겹쳐 값이 두 곳에서 오간다
    expect(control(field).input, `${field} 고정 칸`).toBeUndefined();
    // 폼이 그리는 묶음(basic, contact, location, profile)에 속한 코어 항목이다
    expect(['basic', 'contact', 'location', 'profile'], `${field} 의 묶음`).toContain(CORE_GROUPS[field]);
    const groupSource = nodes.find((n) => typeof n.iteration?.source === 'string' && n.iteration.source.includes(`g.key === '${CORE_GROUPS[field]}'`));
    expect(groupSource?.iteration?.item_var, `${CORE_GROUPS[field]} 묶음 반복`).toBe('memberFieldGroup');

    // 조각은 항목 계약을 통째로 넘긴다 — 잠금(readonly)을 컴포넌트가 읽어 비활성으로 그린다
    expect(partialInput.props.field).toBe('{{field}}');
    expect(partialInput.props.name).toBe('{{field.name}}');
    expect(evaluate(partialInput.if, { field: { key: field, renderer: 'builtin', readonly: true, locked_reason: 'sso' } })).toBe(true);
  });

  /**
   * @scenario mode=edit, locked=yes|no
   * @effects locked_field_shows_company_notice
   */
  it('수정 모드 — 이메일이 잠겼을 때만 칸 아래 회사 계정 관리 안내가 보인다', () => {
    const { notice } = control('email');
    expect(notice, 'email 안내').toBeDefined();

    expect(Boolean(evaluate(notice?.if, scope(EDIT, ['email'])))).toBe(true);
    expect(Boolean(evaluate(notice?.if, scope(EDIT, LOCKABLE.filter((f) => f !== 'email'))))).toBe(false);
  });

  /**
   * @scenario mode=edit, locked=yes|no
   * @effects locked_field_shows_company_notice
   */
  it.each(FIELD_LOCKABLE)('수정 모드 — %s 가 회사 계정 사유로 잠겼을 때만 칸 아래 회사 계정 관리 안내가 보인다', (field) => {
    expect(partialNotice, '조각의 회사 계정 안내').toBeDefined();
    expect(partialNotice.id).toBe('mf_locked_sso_{{field.key}}');

    expect(evaluate(partialNotice.if, { field: { key: field, readonly: true, locked_reason: 'sso' } })).toBe(true);
    expect(evaluate(partialNotice.if, { field: { key: field, readonly: false, locked_reason: null } })).toBe(false);
    // 다른 사유의 잠금에는 이 문구를 쓰지 않는다
    expect(evaluate(partialNotice.if, { field: { key: field, readonly: true, locked_reason: 'identity' } })).toBe(false);
  });

  /**
   * @scenario mode=create, locked=yes
   * @effects create_mode_never_locked
   */
  it('추가 모드 — 직전 회원 응답이 남아 있어도 이메일 칸은 잠기지 않고, 항목 계약은 회원 없는 주소에서 받아 잠금이 실려 오지 않는다', () => {
    const { input, notice } = control('email');
    expect(Boolean(evaluate(input?.props?.disabled, scope({}, LOCKABLE)))).toBe(false);
    expect(Boolean(evaluate(notice?.if, scope({}, LOCKABLE)))).toBe(false);

    const source = (userFormLayout as Json).data_sources.find((d: Json) => d.id === 'memberFields');
    const endpoint = (route: Json) => String(source.endpoint).replace(/\{\{([\s\S]+?)\}\}/g, (_m: string, body: string) => String(evaluate(`{{${body}}}`, { route })));
    expect(endpoint({})).toBe('/api/admin/users/form-fields');
    expect(endpoint(EDIT)).toBe('/api/admin/users/0199-uuid/form-fields');
    // 화면에 들어올 때마다 다시 받는다 — 수정 → 추가 이동에서 직전 회원 기준 응답으로 그리지 않는다
    expect(source.refetchOnMount).toBe(true);
    // 조각 밖에서 회원 응답의 locked_fields 를 읽는 것은 이메일 칸의 안내 하나뿐이다
    const readers = nodes.filter((n) => [n.if, n.props?.disabled].some((v) => typeof v === 'string' && v.includes('locked_fields')));
    expect(readers).toHaveLength(1);
    expect(readers[0].text).toBe(NOTICE);
    expect(readers[0].if).toContain("includes('email')");
  });

  /**
   * @scenario locale=ko|en
   * @effects locked_notice_lang_key_present
   */
  it.each([
    ['ko', adminKo],
    ['en', adminEn],
  ])('%s lang 에 잠금 안내 문구 키가 있다', (_locale, lang) => {
    expect(typeof langAt(lang as Json, 'users.form.fields.locked_by_company')).toBe('string');
  });
});
