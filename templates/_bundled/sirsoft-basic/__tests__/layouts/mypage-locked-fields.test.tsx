/**
 * @file mypage-locked-fields.test.tsx
 * @description 마이페이지 정보 수정 「회사 계정이 관리하는 칸」 잠금 회귀 테스트 (sirsoft-basic, 7.2.0 B4)
 *
 * 회사 IdP 등 외부가 관리하는 칸은 내 정보 항목 계약(`profileFields`)에 `readonly: true`, `locked_reason: 'sso'` 로 내려온다.
 * 정보 수정 폼(`partials/mypage/profile/_edit`)은 카드마다 공용 항목 조각(`partials/member/_member_fields`)으로 칸을 그리고,
 * 그 칸을 `readOnly`(선택 목록은 `disabled`)로 잠근 뒤 칸 아래에 「회사 계정에서 관리하는 항목입니다」(`mypage.profile.locked_by_company`)를
 * 보인다. 서버가 잠긴 칸 변경을 422 `user.field_locked`(칸별 `errors.{field}`)로 거절하면 그 칸 아래에 오류를 싣는다.
 *
 * 실제 화면과 같은 경로를 탄다: 화면의 computed(묶음 계산, 조각 문맥, 위쪽 오류 상자)를 그대로 쓰고,
 * 설치가 하는 것처럼 include 자리에 조각을 끼워 넣어 엔진으로 그린다. 입력칸 컴포넌트는 잠금 계약만 옮긴 대역이다
 * (엔진과 템플릿이 React 를 따로 들고 있어 훅을 쓰는 실제 컴포넌트는 이 하네스에서 그릴 수 없다. 실제 컴포넌트의 잠금은 `MemberFieldInput.test.tsx` 가 본다).
 *
 * @vitest-environment jsdom
 * @since 1.4.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { fireEvent, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import editLayout from '../../layouts/mypage/profile-edit.json';
import editPartial from '../../layouts/partials/mypage/profile/_edit.json';
import memberFields from '../../layouts/partials/member/_member_fields.json';
import koMypage from '../../lang/partial/ko/mypage.json';
import koMember from '../../lang/partial/ko/member.json';

/** DOM 에 넘길 속성 */
const PASS_PROPS = ['id', 'className', 'role', 'type', 'name', 'href', 'disabled', 'readOnly', 'aria-label', 'onClick', 'onChange'];

/**
 * 태그 하나를 그리는 시험용 컴포넌트를 만듭니다.
 *
 * @param tag HTML 태그
 * @returns 컴포넌트
 */
function element(tag: string): React.FC<any> {
  return (props: any) => {
    const dom: Record<string, unknown> = {};
    for (const key of PASS_PROPS) {
      if (props[key] !== undefined) dom[key] = props[key];
    }
    if (tag === 'input' || tag === 'textarea') {
      return React.createElement(tag, { ...dom, defaultValue: props.value ?? '' });
    }
    if (tag === 'select') {
      return <select {...dom} />;
    }
    if (tag === 'form') {
      return (
        <form {...dom} onSubmit={(e) => { e.preventDefault(); props.onSubmit?.(e); }}>{props.children}</form>
      );
    }
    return React.createElement(tag, dom, props.children ?? props.text);
  };
}

/**
 * 회원 항목 입력칸의 대역 — 실제 컴포넌트의 잠금 계약만 옮긴다: 글 칸은 `field.readonly` 면 readOnly,
 * 하나 선택(목록)은 disabled. 값은 폼 자동 바인딩이 준 `value` 다.
 *
 * @param props 엔진이 넘긴 속성 (field, name, value, disabled)
 * @returns 입력 요소
 */
function memberFieldInputStandIn(props: any): React.ReactElement {
  const field = props.field ?? {};
  const locked = props.disabled === true || field.readonly === true;
  if (field.type === 'choice') {
    return (
      <select name={props.name} defaultValue={props.value ?? ''} disabled={locked} aria-label={field.label}>
        {(field.options ?? []).map((option: any) => (
          <option key={String(option.value)} value={option.value}>{option.label}</option>
        ))}
      </select>
    );
  }
  return <input type="text" name={props.name} defaultValue={props.value ?? ''} readOnly={field.readonly === true} disabled={props.disabled === true} aria-label={field.label} />;
}

/**
 * 시험용 컴포넌트 레지스트리를 만듭니다.
 *
 * @returns 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (tag: string, name: string) => ({ component: element(tag), metadata: { name, type: 'basic' } });
  const none = (name: string) => ({ component: () => null, metadata: { name, type: 'basic' } });
  (registry as any).registry = {
    Div: basic('div', 'Div'),
    Span: basic('span', 'Span'),
    P: basic('p', 'P'),
    H2: basic('h2', 'H2'),
    H3: basic('h3', 'H3'),
    H4: basic('h4', 'H4'),
    Ul: basic('ul', 'Ul'),
    Li: basic('li', 'Li'),
    A: basic('a', 'A'),
    Label: basic('label', 'Label'),
    Form: basic('form', 'Form'),
    Button: basic('button', 'Button'),
    Input: basic('input', 'Input'),
    Textarea: basic('textarea', 'Textarea'),
    Select: basic('select', 'Select'),
    Checkbox: { component: (p: any) => <input type="checkbox" name={p.name} />, metadata: { name: 'Checkbox', type: 'basic' } },
    Icon: none('Icon'),
    AvatarUploader: none('AvatarUploader'),
    MemberFieldInput: { component: memberFieldInputStandIn, metadata: { name: 'MemberFieldInput', type: 'composite' } },
    Fragment: { component: ({ children }: { children?: React.ReactNode }) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/**
 * 설치가 하는 것처럼 include 자리(`partial` 노드)에 공용 항목 조각을 끼워 넣습니다.
 *
 * @param node 레이아웃 노드
 * @returns 조각을 끼워 넣은 노드
 */
function inlinePartials(node: any): any {
  if (Array.isArray(node)) return node.map(inlinePartials);
  if (!node || typeof node !== 'object') return node;
  if (node.partial === 'partials/member/_member_fields.json') {
    const { meta, comment, ...fragment } = memberFields as any;
    return JSON.parse(JSON.stringify(fragment));
  }
  return Object.fromEntries(Object.entries(node).map(([key, value]) => [key, key === 'children' ? inlinePartials(value) : value]));
}

const LOCKED_NOTICE = koMypage.profile.locked_by_company;
const FIELD_LOCKED = '회사 계정에서 관리하는 항목이라 여기서 바꿀 수 없습니다.';

/** 회원 응답 기본값 */
const USER = {
  uuid: 'u-1', name: '김회사', nickname: 'kim', email: 'kim@corp.test', mobile: '010-1111-2222', phone: '02-123-4567',
  language: 'ko', status: 'active', has_password: false, email_deliverable: true, custom_fields: {},
};

/**
 * 내 정보 항목 계약을 만듭니다.
 *
 * @param locked 회사 계정이 잠근 칸 (undefined 면 잠금 키 자체가 없는 응답)
 * @returns 항목 계약 배열
 */
function fieldsFor(locked: string[] | undefined) {
  const base = [
    { key: 'name', type: 'text', label: '이름', required: true, ui: { group: 'basic' } },
    { key: 'nickname', type: 'text', label: '닉네임', ui: { group: 'basic' } },
    { key: 'mobile', type: 'phone', label: '휴대폰', ui: { group: 'contact' } },
    { key: 'phone', type: 'phone', label: '전화번호', ui: { group: 'contact' } },
    { key: 'language', type: 'choice', label: '언어', ui: { group: 'location' }, options: [{ value: 'ko', label: '한국어' }, { value: 'en', label: 'English' }] },
  ];
  return base.map((field) => ({
    name: field.key,
    renderer: 'builtin',
    owner: 'core',
    deferred: false,
    constraints: {},
    ...field,
    ...(locked === undefined ? {} : { readonly: locked.includes(field.key), locked_reason: locked.includes(field.key) ? 'sso' : null }),
  }));
}

const fetchMock = vi.fn();

/**
 * fetch 응답 객체를 만듭니다.
 *
 * @param status HTTP 상태
 * @param body 응답 본문
 * @returns 응답
 */
function jsonResponse(status: number, body: unknown): Response {
  return { ok: status < 400, status, statusText: '', json: async () => body, headers: new Headers() } as unknown as Response;
}

/**
 * 정보 수정 폼을 그립니다 (재확인 통과 상태).
 *
 * @param locked 회사 계정이 잠근 칸 (undefined 면 잠금 키 없음)
 * @returns 레이아웃 시험 하네스
 */
async function renderEdit(locked: string[] | undefined) {
  const t = createLayoutTest(
    { version: '1.0.0', layout_name: 'mypage/profile-edit', computed: (editLayout as any).computed, components: [inlinePartials(editPartial)] } as any,
    {
      componentRegistry: setupRegistry(),
      translations: { mypage: koMypage, member: koMember },
      initialState: { _local: { isPasswordVerified: true, profileEditForm: { ...USER } }, _global: { appConfig: { supportedLocales: ['ko', 'en'] } } },
      initialData: { user: { data: USER }, profileFields: { data: { fields: fieldsFor(locked), fields_version: 'test' } }, $locale: 'ko', $templateId: 'test-template' },
    },
  );
  await t.render();
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  return t;
}

/**
 * 항목 한 칸(라벨, 입력칸, 잠금 안내, 오류 줄을 담은 행)을 찾습니다.
 *
 * @param key 항목 키
 * @returns 행 요소
 */
function fieldBox(key: string): HTMLElement {
  const row = document.getElementById(`field_${key}`);
  expect(row, `field_${key} 를 찾지 못했다`).not.toBeNull();
  return row as HTMLElement;
}

describe('sirsoft-basic 마이페이지 칸 잠금 (7.2.0 B4)', () => {
  beforeEach(() => {
    setupRegistry();
    fetchMock.mockReset();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  /**
   * @scenario locked_fields=some, response=none
   * @effects locked_fields_render_read_only, locked_fields_show_company_notice
   */
  it('회사 계정이 잠근 칸은 readOnly(언어는 disabled)이고 칸 아래에 회사 계정 안내가 붙는다', async () => {
    const t = await renderEdit(['name', 'mobile', 'language']);

    expect((document.querySelector('input[name="name"]') as HTMLInputElement).readOnly).toBe(true);
    expect((document.querySelector('input[name="mobile"]') as HTMLInputElement).readOnly).toBe(true);
    expect((document.querySelector('select[name="language"]') as HTMLSelectElement).disabled).toBe(true);
    expect((document.querySelector('input[name="nickname"]') as HTMLInputElement).readOnly).toBe(false);
    expect((document.querySelector('input[name="phone"]') as HTMLInputElement).readOnly).toBe(false);

    // 값은 폼 상태(user 응답으로 채운 profileEditForm)에서 온다
    expect((document.querySelector('input[name="name"]') as HTMLInputElement).value).toBe('김회사');
    expect((document.querySelector('input[name="phone"]') as HTMLInputElement).value).toBe('02-123-4567');

    for (const key of ['name', 'mobile', 'language']) {
      expect(fieldBox(key).textContent, key).toContain(LOCKED_NOTICE);
    }
    for (const key of ['nickname', 'phone']) {
      expect(fieldBox(key).textContent, key).not.toContain(LOCKED_NOTICE);
    }
    expect((document.body.textContent ?? '').split(LOCKED_NOTICE).length - 1).toBe(3);

    // 카드마다 자기 묶음만 그린다 — 이름과 닉네임은 기본 묶음, 휴대폰과 전화는 연락처 카드, 언어는 위치 카드
    expect(document.getElementById('member_fields_group_basic')!.contains(fieldBox('nickname'))).toBe(true);
    expect(document.getElementById('profile_contact_card')!.contains(fieldBox('phone'))).toBe(true);
    expect(document.getElementById('profile_location_card')!.contains(fieldBox('language'))).toBe(true);
    // 항목이 없는 묶음의 카드는 그리지 않는다
    expect(document.getElementById('profile_bio_card')).toBeNull();
    expect(document.getElementById('profile_extra_card')).toBeNull();

    t.cleanup();
  });

  /**
   * @scenario locked_fields=absent, response=none
   * @effects locked_fields_absent_keeps_editable
   */
  /**
   * @scenario locked_fields=empty, response=none
   * @effects locked_fields_absent_keeps_editable
   */
  it.each([[[]], [undefined]])('잠근 칸=%j 면 모든 칸을 고칠 수 있고 안내가 없다', async (locked) => {
    const t = await renderEdit(locked as string[] | undefined);

    for (const name of ['name', 'nickname', 'mobile', 'phone']) {
      expect((document.querySelector(`input[name="${name}"]`) as HTMLInputElement).readOnly, name).toBe(false);
    }
    expect((document.querySelector('select[name="language"]') as HTMLSelectElement).disabled).toBe(false);
    expect(document.body.textContent).not.toContain(LOCKED_NOTICE);
    expect(document.body.textContent).not.toContain(koMember.fields.locked_generic);

    t.cleanup();
  });

  /**
   * @scenario locked_fields=some, response=field_locked
   * @effects field_locked_error_shown_per_field
   */
  it('저장이 422 user.field_locked 로 거절되면 그 칸 아래에 서버 오류를 싣는다', async () => {
    fetchMock.mockImplementation(async (url: string) => (String(url).includes('csrf')
      ? jsonResponse(204, {})
      : jsonResponse(422, { success: false, message: FIELD_LOCKED, errors: { name: [FIELD_LOCKED] } })));
    const t = await renderEdit(['name']);

    fireEvent.submit(document.querySelector('form#profileEditForm, form')!);

    await waitFor(() => expect(fieldBox('name').textContent).toContain(FIELD_LOCKED));
    const [url, init] = fetchMock.mock.calls.find(([u]) => u === '/api/me')!;
    expect(url).toBe('/api/me');
    expect((init as RequestInit).method).toBe('PUT');
    // 저장 본문은 폼 상태 전체다(운영자 추가 항목 값 custom_fields 포함)
    expect(JSON.parse(String((init as RequestInit).body))).toMatchObject({ name: '김회사', nickname: 'kim', custom_fields: {} });
    expect(fieldBox('nickname').textContent).not.toContain(FIELD_LOCKED);
    // 칸에 속한 오류라 위쪽 상자에는 다시 나오지 않는다
    expect(document.getElementById('profile_orphan_errors')).toBeNull();
    expect(document.getElementById('mf_error_name')!.textContent).toBe(FIELD_LOCKED);

    t.cleanup();
  });
});
