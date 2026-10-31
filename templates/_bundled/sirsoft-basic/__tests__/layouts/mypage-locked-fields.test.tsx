/**
 * @file mypage-locked-fields.test.tsx
 * @description 마이페이지 정보 수정 「회사 계정이 관리하는 칸」 잠금 회귀 테스트 (sirsoft-basic, 7.2.0 B4)
 *
 * 회사 IdP 등 외부가 관리하는 칸은 `user` 응답의 `locked_fields`(이름 목록)로 내려온다. 정보 수정 폼(`partials/mypage/profile/_edit`)은
 * 그 칸을 `readOnly`(언어 선택은 `disabled`)로 그리고 칸 아래에 「회사 계정에서 관리하는 항목입니다」(`mypage.profile.locked_by_company`)를
 * 보인다. 서버가 잠긴 칸 변경을 422 `user.field_locked`(칸별 `errors.{field}`)로 거절하면 그 칸 아래에 오류를 싣는다.
 *
 * @vitest-environment jsdom
 * @since 1.3.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { fireEvent, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

import editPartial from '../../layouts/partials/mypage/profile/_edit.json';
import koMypage from '../../lang/partial/ko/mypage.json';

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
    Fragment: { component: ({ children }: { children?: React.ReactNode }) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const LOCKED_NOTICE = koMypage.profile.locked_by_company;
const FIELD_LOCKED = '회사 계정에서 관리하는 항목이라 여기서 바꿀 수 없습니다.';

/** 회원 응답 기본값 */
const USER = {
  uuid: 'u-1', name: '김회사', nickname: 'kim', email: 'kim@corp.test', mobile: '010-1111-2222', phone: '02-123-4567',
  language: 'ko', status: 'active', has_password: false, email_deliverable: true,
};

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
 * @param lockedFields 잠긴 칸 (undefined 면 키 없음 — 7.2.0 이전 응답)
 * @returns 레이아웃 시험 하네스
 */
async function renderEdit(lockedFields: string[] | undefined) {
  const user = lockedFields === undefined ? USER : { ...USER, locked_fields: lockedFields };
  const t = createLayoutTest(
    { version: '1.0.0', layout_name: 'mypage/profile-edit', components: [editPartial as any] } as any,
    {
      componentRegistry: setupRegistry(),
      translations: { mypage: koMypage },
      initialState: { _local: { isPasswordVerified: true }, _global: { appConfig: { supportedLocales: ['ko', 'en'] } } },
      initialData: { user: { data: user }, $locale: 'ko', $templateId: 'test-template' },
    },
  );
  await t.render();
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  return t;
}

/**
 * 칸을 감싼 상자(라벨·입력·안내를 담은 `space-y-1` 칸)를 찾습니다.
 *
 * @param selector 입력 요소 선택자
 * @returns 상자 요소
 */
function fieldBox(selector: string): HTMLElement {
  const control = document.querySelector(selector);
  expect(control, `${selector} 를 찾지 못했다`).not.toBeNull();
  return control!.parentElement as HTMLElement;
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
  it('locked_fields 에 든 칸은 readOnly(언어는 disabled)이고 칸 아래에 회사 계정 안내가 붙는다', async () => {
    const t = await renderEdit(['name', 'mobile', 'language']);

    expect((document.querySelector('input[name="name"]') as HTMLInputElement).readOnly).toBe(true);
    expect((document.querySelector('input[name="mobile"]') as HTMLInputElement).readOnly).toBe(true);
    expect((document.querySelector('select[name="language"]') as HTMLSelectElement).disabled).toBe(true);
    expect((document.querySelector('input[name="nickname"]') as HTMLInputElement).readOnly).toBe(false);
    expect((document.querySelector('input[name="phone"]') as HTMLInputElement).readOnly).toBe(false);

    for (const selector of ['input[name="name"]', 'input[name="mobile"]', 'select[name="language"]']) {
      expect(fieldBox(selector).textContent, selector).toContain(LOCKED_NOTICE);
    }
    for (const selector of ['input[name="nickname"]', 'input[name="phone"]']) {
      expect(fieldBox(selector).textContent, selector).not.toContain(LOCKED_NOTICE);
    }
    expect((document.body.textContent ?? '').split(LOCKED_NOTICE).length - 1).toBe(3);

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
  it.each([[[]], [undefined]])('locked_fields=%j 면 모든 칸을 고칠 수 있고 안내가 없다', async (lockedFields) => {
    const t = await renderEdit(lockedFields as string[] | undefined);

    for (const name of ['name', 'nickname', 'mobile', 'phone']) {
      expect((document.querySelector(`input[name="${name}"]`) as HTMLInputElement).readOnly, name).toBe(false);
    }
    expect((document.querySelector('select[name="language"]') as HTMLSelectElement).disabled).toBe(false);
    expect(document.body.textContent).not.toContain(LOCKED_NOTICE);

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

    await waitFor(() => expect(fieldBox('input[name="name"]').textContent).toContain(FIELD_LOCKED));
    const [url, init] = fetchMock.mock.calls.find(([u]) => u === '/api/me')!;
    expect(url).toBe('/api/me');
    expect((init as RequestInit).method).toBe('PUT');
    expect(fieldBox('input[name="nickname"]').textContent).not.toContain(FIELD_LOCKED);

    t.cleanup();
  });
});
