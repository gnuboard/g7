/**
 * @file admin-settings-member-field-modal.test.tsx
 * @description 환경설정 「회원」 탭의 항목 추가·수정 창과 삭제 확인 창(#490 U2, U3, U7, U8, U9)
 *
 * 가입 항목 표와 창을 한 화면으로 렌더하고(창은 실물 Modal), 단추와 입력을 실제로 눌러 창 상태와 나간 요청을 본다.
 * - U2: 추가는 유형 타일을 고르고 「다음」으로 넘어가는 두 단계, 저장 전에는 「바꾸기」로 유형 변경
 * - U3: 선택지별 사용 인원, 저장된 선택지의 값 잠금, 지우면 경고만 보이고 저장할 때 적용
 * - U7: 작성 중 닫기는 한 번 묻고, 조회 권한만 있으면 읽기 전용으로 연다
 * - U8: 「기타(직접 입력)」 허용
 * - U9: 여러 줄 붙여넣기로 선택지 만들기
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { Modal } from '../../src/components/composite/Modal';
import componentsManifest from '../../components.json';
import {
  buildLayout,
  collect,
  fieldDefs,
  koTranslations,
  loadLayout,
  setupRegistry,
  settle,
  shownToasts,
  stubFetch,
  stubRefetch,
  watch,
  type FetchRoute,
  type Json,
} from './support/memberTabHarness';

// 엔진(코어)은 저장소 루트의 react 를, Modal 은 이 템플릿 node_modules 의 react 를 읽어 사본이 둘이 된다 — 루트 사본으로 맞춘다
vi.mock('react', async () => {
  const p = await import('path');
  const u = await import('url');
  const dir = p.dirname(u.fileURLToPath(import.meta.url));

  return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../node_modules/react/index.js')).href);
});

const modalMetadata: Json = (componentsManifest as any).components.composite.find((c: any) => c.name === 'Modal');

/** 하네스 G7Core 에 엔진 렌더러 접근자를 잇는 실물 Modal(고정 하단을 엔진으로 그린다). */
const RealModal: React.FC<any> = (props) => {
  const g7 = (window as any).G7Core;
  if (g7 && typeof g7.getDynamicRenderer !== 'function') {
    g7.getDynamicRenderer = () => (window as any).__DynamicRenderer ?? null;
  }

  return <Modal {...props} onClose={props.onClose ?? (() => {})} />;
};

const formModal = loadLayout('partials/admin_settings/_modal_member_field_form.json');
const deleteModal = loadLayout('partials/admin_settings/_modal_member_field_delete.json');
const requiredModal = loadLayout('partials/admin_settings/_modal_member_field_required.json');

const FIELDS = '/api/admin/field-sets/core.user/fields';
const CHANGED = '다른 관리자가 항목을 바꿨습니다. 다시 불러왔습니다.';

/** 직업 항목 상세 응답(선택지별 사용 인원 포함). */
function jobDetail(): FetchRoute {
  const job = fieldDefs().data.fields[4];

  return {
    method: 'GET',
    endsWith: '/fields/5',
    body: {
      success: true,
      data: {
        field: {
          ...job,
          values_count: 128,
          other_usage_count: 0,
          options: [
            { value: 'student', label: { ko: '학생' }, usage_count: 3 },
            { value: 'worker', label: { ko: '직장인' }, usage_count: 0 },
          ],
        },
        fields_version: 'v1',
      },
    },
  };
}

interface Rendered {
  t: ReturnType<typeof createLayoutTest>;
  requests: ReturnType<typeof stubFetch>;
  refetched: string[];
  /** 화면이 읽는 항목 정의 응답(값을 바꾸면 다시 받은 것처럼 읽힌다) */
  defs: Json;
  /** 창 상태 */
  modal: () => Json | null | undefined;
  /** 요소를 누르고(또는 값을 바꾸고) 액션이 끝난 뒤 다시 그린다 */
  click: (testId: string) => Promise<void>;
  change: (testId: string, value: string) => Promise<void>;
  check: (testId: string) => Promise<void>;
}

let active: ReturnType<typeof createLayoutTest> | null = null;

/** 가입 항목 표와 창 셋을 렌더한다. */
async function renderScreen(opts: { routes?: FetchRoute[]; defs?: Json } = {}): Promise<Rendered> {
  const layout = buildLayout('_tab_member_fields.json', { modals: [formModal, deleteModal, requiredModal] });
  const initialData: Json = { memberFieldDefs: opts.defs ?? fieldDefs() };
  active = createLayoutTest(layout as any, {
    componentRegistry: setupRegistry({ Modal: { component: RealModal, metadata: { ...modalMetadata, name: 'Modal', type: 'composite' } } }),
    translations: koTranslations(),
    locale: 'ko',
    includeModals: true,
    queryParams: { tab: 'member' },
    initialState: { _local: { form: {} }, _global: { settingsAbilities: { can_update: true } } },
    initialData,
  } as any);
  const t = active;
  await t.render();
  watch(active);
  const requests = stubFetch(opts.routes ?? []);
  const refetched = stubRefetch();
  const after = async () => {
    await settle();
    await t.rerender();
  };

  return {
    t,
    requests,
    refetched,
    defs: initialData.memberFieldDefs,
    modal: () => t.getState()._global.member_field_form_modal,
    click: async (testId) => {
      fireEvent.click(screen.getByTestId(testId));
      await after();
    },
    change: async (testId, value) => {
      fireEvent.change(screen.getByTestId(testId), { target: { value } });
      await after();
    },
    check: async (testId) => {
      fireEvent.click(screen.getByTestId(testId));
      await after();
    },
  };
}

/** 요청을 `METHOD 주소` 로 줄인다. */
const lines = (requests: Array<{ method: string; url: string }>) => requests.map((r) => `${r.method} ${r.url}`);

// 단추를 여러 번 누르고 그때마다 액션 사슬이 끝나기를 기다린다 — 다른 테스트 파일과 함께 돌 때 기본 5초로는 모자란다
vi.setConfig({ testTimeout: 30_000 });

beforeEach(() => {
  (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
});

afterEach(() => {
  active?.cleanup();
  active = null;
  watch(null);
  vi.unstubAllGlobals();
  delete (window as any).__templateApp;
});

describe('항목 추가 — 유형 타일 두 단계(U2)', () => {
  // @scenario case=add_type_step
  // @effects add_opens_type_tiles_first
  it('「항목 추가」는 유형 타일 단계로 열리고, 유형을 고르기 전에는 「다음」이 잠긴다', async () => {
    const r = await renderScreen();

    await r.click('member-field-add');

    expect(r.t.getModalStack()).toEqual(['member_field_form_modal']);
    expect(r.modal()).toMatchObject({ step: 'type', isNew: true, readOnly: false, dirty: false, fieldsVersion: 'v1' });
    expect(r.modal()!.form).toMatchObject({ id: null, key: '', type: '', is_custom: true, enabled: true, required: false, show_on_signup: true, edit_scope: 'self' });
    // 타일은 유형마다 하나, 내용 입력 단계는 아직 없다
    expect(document.querySelectorAll('[data-testid^="member-field-type-tile-"]')).toHaveLength(6);
    expect(document.getElementById('member_field_form_body')).toBeNull();
    expect(screen.getByTestId('member-field-form-next')).toBeDisabled();
    expect(screen.queryByTestId('member-field-form-save')).toBeNull();
    expect(screen.getByText('항목 추가', { selector: '.modal-title' })).toBeInTheDocument();
    // 창을 여는 것만으로 요청은 나가지 않는다(유형 목록은 항목 정의 응답에 실려 온다)
    expect(r.requests).toHaveLength(0);
  });

  // @scenario case=add_unavailable_type
  // @effects unavailable_type_tile_is_dimmed_and_locked
  it('제공한 확장이 꺼진 유형은 흐리게 잠기고 「확장 꺼짐」을 붙인다', async () => {
    const r = await renderScreen();
    await r.click('member-field-add');

    const tile = screen.getByTestId('member-field-type-tile-acme.color');
    expect(tile).toBeDisabled();
    expect(tile.className).toContain('disabled:opacity-50');
    expect(tile.className).toContain('disabled:cursor-not-allowed');
    expect(tile.textContent).toContain('확장 꺼짐');
    expect(screen.getByTestId('member-field-type-tile-text')).not.toBeDisabled();
  });

  // @scenario case=add_pick_and_next
  // @effects type_pick_then_next_shows_form_step
  it('타일을 고르고 「다음」을 누르면 내용 입력 단계로 넘어가 고른 유형의 옵션을 보인다', async () => {
    const r = await renderScreen();
    await r.click('member-field-add');

    await r.click('member-field-type-tile-choice');
    expect(r.modal()!.form.type).toBe('choice');
    expect(screen.getByTestId('member-field-type-tile-choice').getAttribute('aria-checked')).toBe('true');
    expect(screen.getByTestId('member-field-form-next')).not.toBeDisabled();

    await r.click('member-field-form-next');
    expect(r.modal()!.step).toBe('form');
    expect(document.getElementById('member_field_form_step_type')).toBeNull();
    expect(screen.getByTestId('member-field-form-type-label').textContent).toBe('하나 선택');
    // 하나 선택의 옵션(표시 방식, 기타 허용, 선택지)만 보이고 다른 유형 옵션은 없다
    expect(document.getElementById('member_field_type_choice')).not.toBeNull();
    expect(document.getElementById('member_field_options_editor')).not.toBeNull();
    expect(document.getElementById('member_field_type_text')).toBeNull();
    expect(document.getElementById('member_field_type_number')).toBeNull();
    // 추가에서는 키를 적을 수 있다
    expect(screen.getByTestId('member-field-form-key')).not.toBeDisabled();
    expect(screen.getByTestId('member-field-form-save')).toBeInTheDocument();
  });

  // @scenario case=add_change_type
  // @effects change_type_resets_type_options_only
  it('「바꾸기」로 유형을 다시 고르면 유형별 옵션만 비우고 이름은 남긴다', async () => {
    const r = await renderScreen();
    await r.click('member-field-add');
    await r.click('member-field-type-tile-text');
    await r.click('member-field-form-next');
    await r.change('member-field-form-name', '활동명');
    await r.change('member-field-form-constraints-max_length', '20');
    expect(r.modal()!.form.constraints).toEqual({ max_length: 20 });

    await r.click('member-field-form-change-type');
    expect(r.modal()!.step).toBe('type');
    // 같은 유형을 다시 고르면 적어 둔 옵션이 남는다
    await r.click('member-field-type-tile-text');
    expect(r.modal()!.form.constraints).toEqual({ max_length: 20 });
    // 다른 유형으로 바꾸면 유형별 옵션만 비운다
    await r.click('member-field-type-tile-number');
    expect(r.modal()!.form.type).toBe('number');
    expect(r.modal()!.form.constraints).toBeNull();
    expect(r.modal()!.form.name).toEqual({ ko: '활동명' });

    await r.click('member-field-form-next');
    expect(screen.getByTestId('member-field-form-type-label').textContent).toBe('숫자');
    expect(document.getElementById('member_field_type_number')).not.toBeNull();
    expect(document.getElementById('member_field_type_text')).toBeNull();
  });

  // @scenario case=add_save
  // @effects create_posts_key_type_and_owned_attributes
  it('저장하면 키, 유형, 이름과 유형별 옵션을 POST 로 보내고 창을 닫은 뒤 표를 다시 받는다', async () => {
    const r = await renderScreen({ routes: [{ method: 'POST', endsWith: '/fields', status: 201, body: { success: true, data: { field: { id: 9 }, fields_version: 'v2' } } }] });
    await r.click('member-field-add');
    await r.click('member-field-type-tile-text');
    await r.click('member-field-form-next');
    await r.change('member-field-form-key', ' Activity_Name ');
    await r.change('member-field-form-name', '활동명');
    await r.change('member-field-form-constraints-max_length', '20');
    await r.check('member-field-form-required');
    await r.check('member-field-form-display_name_usable');

    await r.click('member-field-form-save');

    expect(lines(r.requests)).toEqual([`POST ${FIELDS}`]);
    expect(r.requests[0].body).toEqual({
      key: 'activity_name',
      type: 'text',
      name: { ko: '활동명' },
      description: {},
      placeholder: {},
      constraints: { max_length: 20 },
      enabled: true,
      required: true,
      show_on_signup: true,
      edit_scope: 'self',
      require_existing_members: false,
      public_profile: false,
      admin_list_column: false,
      admin_searchable: false,
      display_name_usable: true,
      idv_claim: null,
      idv_lock: false,
      fields_version: 'v1',
    });
    expect(r.t.getModalStack()).toEqual([]);
    expect(r.modal()).toBeNull();
    expect(r.refetched).toEqual(['memberFieldDefs']);
  });

  // @scenario case=add_validation_error
  // @effects save_validation_error_stays_open_with_messages
  it('검증 오류면 창을 닫지 않고 상단 상자와 칸 아래에 서버 문구를 보인다', async () => {
    const r = await renderScreen({
      routes: [{ method: 'POST', endsWith: '/fields', status: 422, body: { success: false, message: '입력값을 확인해 주세요.', errors: { key: ['이미 있는 키입니다.'], options: ['선택지를 1개 이상 넣어 주세요.'] } } }],
    });
    await r.click('member-field-add');
    await r.click('member-field-type-tile-choice');
    await r.click('member-field-form-next');
    await r.change('member-field-form-key', 'job');

    await r.click('member-field-form-save');

    expect(r.t.getModalStack()).toEqual(['member_field_form_modal']);
    expect(r.modal()!.isSaving).toBe(false);
    const box = document.getElementById('member_field_form_error')!;
    expect(box.textContent).toContain('입력값을 확인해 주세요.');
    expect(box.textContent).toContain('선택지를 1개 이상 넣어 주세요.');
    expect(screen.getAllByText('이미 있는 키입니다.').length).toBe(2);
    expect(r.refetched).toEqual([]);
  });

  // @scenario case=save_conflict
  // @effects save_conflict_keeps_input_and_next_save_uses_refetched_version
  it('다른 관리자가 먼저 바꿔 409 가 오면 저장하지 않고 알린 뒤 표를 다시 받고, 적은 내용을 남긴 채 다음 저장은 새 버전을 싣는다', async () => {
    const r = await renderScreen({
      routes: [{ method: 'POST', endsWith: '/fields', status: 409, body: { success: false, message: 'conflict', error_code: 'fields_changed', data: { fields_version: 'v2' } } }],
    });
    await r.click('member-field-add');
    await r.click('member-field-type-tile-text');
    await r.click('member-field-form-next');
    await r.change('member-field-form-key', 'memo');
    await r.change('member-field-form-name', '메모');

    await r.click('member-field-form-save');

    expect(shownToasts(r.t.getToasts())).toEqual([{ type: 'error', message: CHANGED }]);
    expect(r.refetched).toEqual(['memberFieldDefs']);
    expect(r.t.getModalStack()).toEqual(['member_field_form_modal']);
    // 오류 본문의 버전을 읽지 않는다 — 창이 쥔 버전을 비워 다음 저장이 다시 받은 항목 정의의 버전을 싣게 한다
    expect(r.modal()).toMatchObject({ isSaving: false, fieldsVersion: null, errors: null });
    expect(r.modal()!.form).toMatchObject({ key: 'memo', name: { ko: '메모' } });
    expect(r.requests[0].body.fields_version).toBe('v1');

    // 표를 다시 받아 새 버전이 도착한 뒤 다시 누르면 그 버전으로 나간다
    r.defs.data.fields_version = 'v2';
    await r.t.rerender();
    await r.click('member-field-form-save');
    expect(r.requests).toHaveLength(2);
    expect(r.requests[1].body).toMatchObject({ key: 'memo', fields_version: 'v2' });
  });
});

describe('항목 수정 — 잠금과 저장 범위', () => {
  // @scenario case=edit_custom
  // @effects edit_locks_key_and_type
  it('수정 창은 타일 없이 열리고 키와 유형이 잠긴다', async () => {
    const r = await renderScreen({ routes: [jobDetail()] });

    await r.click('member-field-table-open-job');

    expect(lines(r.requests)).toEqual([`GET ${FIELDS}/5`]);
    expect(r.modal()).toMatchObject({ step: 'form', isNew: false, loading: false, readOnly: false, dirty: false });
    expect(document.getElementById('member_field_form_step_type')).toBeNull();
    expect(screen.getByTestId('member-field-form-key')).toBeDisabled();
    expect((screen.getByTestId('member-field-form-key') as HTMLInputElement).value).toBe('job');
    expect(screen.queryByTestId('member-field-form-change-type')).toBeNull();
    expect(screen.getByText('유형은 만든 뒤 바꿀 수 없습니다.')).toBeInTheDocument();
    expect(screen.getByText('항목 수정', { selector: '.modal-title' })).toBeInTheDocument();
  });

  // @scenario case=edit_declared
  // @effects declared_field_saves_only_common_attributes
  it('선언 항목은 유형별 옵션이 없고, 저장 본문에 이름과 공통 옵션만 싣는다', async () => {
    const mobile = fieldDefs().data.fields[2];
    const r = await renderScreen({
      routes: [
        { method: 'GET', endsWith: '/fields/3', body: { success: true, data: { field: { ...mobile, values_count: 0 }, fields_version: 'v1' } } },
        { method: 'PUT', endsWith: '/fields/3', body: { success: true, data: { field: mobile, fields_version: 'v2' } } },
      ],
    });
    await r.click('member-field-table-open-mobile');

    expect(screen.getByText('항목 설정', { selector: '.modal-title' })).toBeInTheDocument();
    expect(document.getElementById('member_field_form_declared_notice')).not.toBeNull();
    for (const id of ['member_field_type_text', 'member_field_type_choice', 'member_field_options_editor', 'member_field_type_extension']) {
      expect(document.getElementById(id), id).toBeNull();
    }
    // 운영자 항목 전용 칸은 잠긴다
    expect(screen.getByTestId('member-field-form-constraints-unique')).toBeDisabled();
    expect(screen.getByTestId('member-field-form-display_name_usable')).toBeDisabled();
    expect(screen.getByTestId('member-field-form-idv_claim')).toBeDisabled();

    await r.check('member-field-form-public_profile');
    r.requests.length = 0;
    await r.click('member-field-form-save');

    expect(lines(r.requests)).toEqual([`PUT ${FIELDS}/3`]);
    expect(Object.keys(r.requests[0].body).sort()).toEqual(
      ['admin_list_column', 'admin_searchable', 'description', 'edit_scope', 'enabled', 'fields_version', 'idv_lock', 'name', 'placeholder', 'public_profile', 'require_existing_members', 'required', 'show_on_signup'].sort(),
    );
    expect(r.requests[0].body).toMatchObject({ public_profile: true, name: { ko: '휴대폰' }, fields_version: 'v1' });
    expect(r.t.getModalStack()).toEqual([]);
    expect(r.refetched).toEqual(['memberFieldDefs']);
  });

  // @scenario case=edit_scope_locks_existing_members
  // @effects admin_scope_turns_off_and_locks_require_existing_members
  it('범위를 관리자만 수정이나 열람으로 고르면 「기존 회원에게도 입력 요구」가 꺼진 채 잠긴다', async () => {
    const r = await renderScreen({ routes: [jobDetail(), { method: 'PUT', endsWith: '/fields/5', body: { success: true, data: {} } }] });
    await r.click('member-field-table-open-job');
    await r.check('member-field-form-require_existing_members');
    expect(r.modal()!.form.require_existing_members).toBe(true);

    for (const scope of ['admin_edit', 'admin_only']) {
      fireEvent.click(document.querySelector(`[data-testid="member-field-form-edit_scope"] input[value="${scope}"]`)!);
      await settle();
      await r.t.rerender();

      expect(r.modal()!.form.edit_scope).toBe(scope);
      expect(r.modal()!.form.require_existing_members).toBe(false);
      const box = screen.getByTestId('member-field-form-require_existing_members') as HTMLInputElement;
      expect(box).toBeDisabled();
      expect(box.checked).toBe(false);
    }
    expect(screen.getByText('「관리자만 수정」「관리자만 열람」 범위에서는 기존 회원에게 입력을 요구할 수 없습니다.')).toBeInTheDocument();

    r.requests.length = 0;
    await r.click('member-field-form-save');
    expect(r.requests[0].body).toMatchObject({ edit_scope: 'admin_only', require_existing_members: false });

    // 본인 수정 범위로 연 창에서는 칸이 열려 있다
    r.t.cleanup();
    const again = await renderScreen({ routes: [jobDetail()] });
    await again.click('member-field-table-open-job');
    expect(screen.getByTestId('member-field-form-require_existing_members')).not.toBeDisabled();
  });

  // @scenario case=extension_type_slot
  // @effects extension_type_options_slot_declared
  it('확장 유형의 옵션 자리는 확장점 member_field_type_options 다', () => {
    const slot = collect(formModal, (n) => n.type === 'extension_point' && n.name === 'member_field_type_options');

    expect(slot).toHaveLength(1);
    expect(slot[0].props.field).toBe('{{_global.member_field_form_modal?.form}}');
    expect(Object.keys(slot[0].props)).toContain('disabled');
    // 코어 유형에서는 그리지 않는다
    const host = collect(formModal, (n) => n.id === 'member_field_type_extension')[0];
    expect(String(host.if)).toContain("!['text', 'textarea', 'number', 'boolean', 'date', 'datetime', 'date_range', 'choice', 'multi_choice', 'phone', 'email', 'url', 'address', 'file', 'image'].includes(");
  });
});

describe('선택지 — 사용 인원과 삭제 경고(U3)', () => {
  // @scenario case=option_usage
  // @effects saved_options_show_usage_and_lock_value
  it('수정 창은 선택지마다 사용 인원을 보이고 저장된 선택지의 값은 못 바꾼다', async () => {
    const r = await renderScreen({ routes: [jobDetail()] });
    await r.click('member-field-table-open-job');

    expect(screen.getByTestId('member-field-form-option-0').textContent).toContain('사용 3명');
    expect(screen.getByTestId('member-field-form-option-1').textContent).toContain('사용 0명');
    expect(screen.getByTestId('member-field-form-option-value-0')).toBeDisabled();
    expect((screen.getByTestId('member-field-form-option-value-0') as HTMLInputElement).value).toBe('student');
    // 이름은 고칠 수 있다
    expect(screen.getByTestId('member-field-form-option-label-0')).not.toBeDisabled();

    // 새로 더한 줄은 값도 고친다
    await r.click('member-field-form-options-add');
    expect(r.modal()!.form.options).toHaveLength(3);
    expect(screen.getByTestId('member-field-form-option-value-2')).not.toBeDisabled();
    expect(screen.getByTestId('member-field-form-option-2').textContent).not.toContain('사용');
  });

  // @scenario case=option_remove_warning
  // @effects removing_saved_option_warns_and_applies_on_save
  it('저장된 선택지를 지우면 경고만 보이고 요청은 나가지 않으며, 되돌릴 수 있다', async () => {
    const r = await renderScreen({ routes: [jobDetail()] });
    await r.click('member-field-table-open-job');
    r.requests.length = 0;

    await r.click('member-field-form-option-remove-0');

    expect(r.requests).toHaveLength(0);
    expect(r.modal()!.form.options.map((o: Json) => [o.value, o._removed === true])).toEqual([['student', true], ['worker', false]]);
    const row = screen.getByTestId('member-field-form-option-0');
    expect(row.textContent).toContain('이 값을 고른 회원 3명의 값이 비워집니다. 저장해야 적용됩니다.');
    expect(row.querySelector('[role="alert"]')).not.toBeNull();
    expect(r.modal()!.dirty).toBe(true);

    // 고른 회원이 없는 선택지는 인원 없이 삭제 예정만 알린다
    await r.click('member-field-form-option-remove-1');
    expect(screen.getByTestId('member-field-form-option-1').textContent).toContain('저장하면 이 선택지가 삭제됩니다.');

    await r.click('member-field-form-option-restore-0');
    expect(r.modal()!.form.options[0]._removed).toBe(false);
    expect(screen.getByTestId('member-field-form-option-0').textContent).toContain('사용 3명');
  });

  // @scenario case=option_remove_save
  // @effects removing_saved_option_warns_and_applies_on_save
  it('저장하면 지우기로 표시한 선택지를 뺀 목록을 보낸다(화면 전용 표식은 싣지 않는다)', async () => {
    const r = await renderScreen({ routes: [jobDetail(), { method: 'PUT', endsWith: '/fields/5', body: { success: true, data: {} } }] });
    await r.click('member-field-table-open-job');
    await r.click('member-field-form-option-remove-1');
    await r.click('member-field-form-options-add');
    await r.change('member-field-form-option-value-2', ' Freelancer ');
    await r.change('member-field-form-option-label-2', '프리랜서');
    r.requests.length = 0;

    await r.click('member-field-form-save');

    expect(lines(r.requests)).toEqual([`PUT ${FIELDS}/5`]);
    expect(r.requests[0].body.options).toEqual([
      { value: 'student', label: { ko: '학생' } },
      { value: 'freelancer', label: { ko: '프리랜서' } },
    ]);
    expect(r.requests[0].body.key).toBeUndefined();
    expect(r.requests[0].body.type).toBeUndefined();
  });

  // @scenario case=option_remove_new
  // @effects removing_unsaved_option_drops_row
  it('새로 더한 줄은 지우면 바로 빠진다', async () => {
    const r = await renderScreen({ routes: [jobDetail()] });
    await r.click('member-field-table-open-job');
    await r.click('member-field-form-options-add');
    expect(r.modal()!.form.options).toHaveLength(3);

    await r.click('member-field-form-option-remove-2');

    expect(r.modal()!.form.options.map((o: Json) => o.value)).toEqual(['student', 'worker']);
  });
});

describe('선택지 — 기타 허용(U8)과 여러 줄 붙여넣기(U9)', () => {
  // @scenario case=allow_other
  // @effects allow_other_saved_in_constraints
  it('「기타(직접 입력)」 허용을 켜면 constraints.allow_other 로 저장하고, 선택지 없이도 보낸다', async () => {
    const r = await renderScreen({ routes: [{ method: 'POST', endsWith: '/fields', status: 201, body: { success: true, data: {} } }] });
    await r.click('member-field-add');
    await r.click('member-field-type-tile-choice');
    await r.click('member-field-form-next');
    await r.change('member-field-form-key', 'route');
    await r.change('member-field-form-name', '가입 경로');

    await r.check('member-field-form-constraints-allow_other');
    expect(r.modal()!.form.constraints).toEqual({ allow_other: true });
    expect(screen.getByText('회원이 「기타」를 고르면 바로 아래 칸에 직접 적을 수 있습니다.')).toBeInTheDocument();

    await r.click('member-field-form-save');
    expect(r.requests[0].body).toMatchObject({ key: 'route', type: 'choice', constraints: { allow_other: true }, options: [] });
  });

  // @scenario case=allow_other_only_choice
  // @effects allow_other_only_for_single_choice
  it('여러 개 선택에는 기타 허용이 없고 최소·최대 선택 개수가 있다', async () => {
    const r = await renderScreen();
    await r.click('member-field-add');
    await r.click('member-field-type-tile-multi_choice');
    await r.click('member-field-form-next');

    expect(screen.queryByTestId('member-field-form-constraints-allow_other')).toBeNull();
    expect(screen.getByTestId('member-field-form-constraints-min_select')).toBeInTheDocument();
    expect(screen.getByTestId('member-field-form-constraints-max_select')).toBeInTheDocument();
    expect(document.getElementById('member_field_options_editor')).not.toBeNull();
  });

  // @scenario case=paste_lines
  // @effects pasted_lines_become_options_with_generated_values
  it('여러 줄을 붙여넣으면 줄마다 선택지가 되고 값은 자동으로 만들어 겹치면 번호를 붙인다', async () => {
    const r = await renderScreen();
    await r.click('member-field-add');
    await r.click('member-field-type-tile-choice');
    await r.click('member-field-form-next');
    await r.click('member-field-form-options-add');
    await r.change('member-field-form-option-value-0', 'student-life');
    await r.change('member-field-form-option-value-0', 'student_life');

    expect(screen.queryByTestId('member-field-form-options-paste-text')).toBeNull();
    await r.click('member-field-form-options-paste-open');
    expect(screen.getByTestId('member-field-form-options-paste-apply')).toBeDisabled();
    await r.change('member-field-form-options-paste-text', '학생\n  Student Life  \n\n직장인\nstudent life\nVIP 2024!');

    await r.click('member-field-form-options-paste-apply');

    const options = r.modal()!.form.options;
    // 한글만 있는 줄은 option1 부터, 영문 줄은 영문과 숫자로, 이미 있는 값과 겹치면 번호를 붙인다. 빈 줄은 건너뛴다
    expect(options.map((o: Json) => o.value)).toEqual(['student_life', 'option1', 'student_life_2', 'option2', 'student_life_3', 'vip_2024']);
    expect(options.slice(1).map((o: Json) => o.label)).toEqual([{ ko: '학생' }, { ko: 'Student Life' }, { ko: '직장인' }, { ko: 'student life' }, { ko: 'VIP 2024!' }]);
    expect(options.slice(1).every((o: Json) => o._new === true)).toBe(true);
    // 붙여넣기 칸은 닫히고 창은 고친 상태가 된다
    expect(r.modal()).toMatchObject({ pasteOpen: false, pasteText: '', dirty: true });
    expect(screen.queryByTestId('member-field-form-options-paste-text')).toBeNull();
    expect(document.querySelectorAll('[data-testid^="member-field-form-option-value-"]')).toHaveLength(6);
  });

  // @scenario case=paste_limit
  // @effects pasted_options_capped_at_fifty
  it('선택지는 50개까지만 받는다', async () => {
    const r = await renderScreen();
    await r.click('member-field-add');
    await r.click('member-field-type-tile-choice');
    await r.click('member-field-form-next');
    await r.click('member-field-form-options-paste-open');
    await r.change('member-field-form-options-paste-text', Array.from({ length: 60 }, (_, i) => String.fromCharCode(0xac00 + i)).join('\n'));

    await r.click('member-field-form-options-paste-apply');

    const options = r.modal()!.form.options;
    expect(options).toHaveLength(50);
    expect(new Set(options.map((o: Json) => o.value)).size).toBe(50);
    expect(options[49].value).toBe('option50');
    expect(screen.getByTestId('member-field-form-options-add')).toBeDisabled();
  });
});

describe('작성 중 닫기 확인과 읽기 전용 보기(U7)', () => {
  // @scenario case=close_guard_props
  // @effects modal_close_paths_go_through_layout
  it('창은 머리의 닫기, 바깥 클릭, 엔진 Esc 닫기를 끄고 본문의 Esc 를 직접 받는다', () => {
    expect(formModal.props).toMatchObject({ showCloseButton: false, closeOnEscape: false, closeOnOverlayClick: false });
    const root = collect(formModal, (n) => n.id === 'member_field_form_root')[0];
    const esc = actionsOf(root).find((a: Json) => a.type === 'keydown' && a.key === 'Escape') as Json;
    const cancel = collect(formModal, (n) => n.props?.['data-testid'] === 'member-field-form-cancel')[0];

    // Esc 와 [취소]는 같은 액션 셋을 탄다 — 고친 것이 있으면 확인으로, 아니면 닫고 비운다
    expect(esc.handler).toBe('sequence');
    const strip = (a: Json) => callbacksOf(a, 'actions').map((x: Json) => ({ handler: x.handler, if: x.if, target: x.target, params: x.params }));
    expect(strip(esc)).toEqual(strip(actionsOf(cancel)[0]));
    expect(strip(esc).map((x: Json) => x.handler)).toEqual(['setState', 'closeModal', 'setState']);
    const [ask, close, clear] = callbacksOf(esc, 'actions');
    expect(ask.params).toEqual({ target: 'global', 'member_field_form_modal.confirmClose': true });
    expect(close.if).toBe(`{{!(${String(ask.if).slice(2, -2)})}}`);
    expect(clear.if).toBe(close.if);
    expect(clear.params).toEqual({ target: 'global', member_field_form_modal: null });
  });

  // @scenario case=close_clean
  // @effects clean_modal_closes_without_confirm
  it('고친 것이 없으면 [취소]가 바로 닫고 창 상태를 비운다', async () => {
    const r = await renderScreen();
    await r.click('member-field-add');

    await r.click('member-field-form-cancel');

    expect(r.t.getModalStack()).toEqual([]);
    expect(r.modal()).toBeNull();
  });

  // @scenario case=close_dirty
  // @effects dirty_modal_asks_before_closing
  it('고친 뒤 [취소]는 닫지 않고 확인 단추로 바꾸며, 「계속 작성」은 내용을 그대로 둔다', async () => {
    const r = await renderScreen();
    await r.click('member-field-add');
    await r.click('member-field-type-tile-text');
    await r.click('member-field-form-next');
    await r.change('member-field-form-name', '활동명');
    expect(r.modal()!.dirty).toBe(true);

    await r.click('member-field-form-cancel');

    expect(r.t.getModalStack()).toEqual(['member_field_form_modal']);
    expect(r.modal()!.confirmClose).toBe(true);
    expect(screen.getByTestId('member-field-form-close-confirm').textContent).toBe('작성 중인 내용이 있습니다. 닫으면 사라집니다.');
    expect(screen.queryByTestId('member-field-form-cancel')).toBeNull();
    expect(screen.queryByTestId('member-field-form-save')).toBeNull();

    await r.click('member-field-form-close-keep');
    expect(r.modal()).toMatchObject({ confirmClose: false, dirty: true });
    expect(r.modal()!.form.name).toEqual({ ko: '활동명' });
    expect(screen.getByTestId('member-field-form-save')).toBeInTheDocument();
  });

  // @scenario case=close_dirty_discard
  // @effects dirty_modal_discard_closes_and_clears
  it('「저장하지 않고 닫기」는 창을 닫고 창 상태를 비우며 요청을 보내지 않는다', async () => {
    const r = await renderScreen();
    await r.click('member-field-add');
    await r.click('member-field-type-tile-text');
    await r.click('member-field-form-cancel');
    expect(r.modal()!.confirmClose).toBe(true);

    await r.click('member-field-form-close-discard');

    expect(r.t.getModalStack()).toEqual([]);
    expect(r.modal()).toBeNull();
    expect(r.requests).toHaveLength(0);
    expect(r.refetched).toEqual([]);
  });

  // @scenario case=close_escape
  // @effects escape_follows_cancel_path
  it('본문에서 Esc 를 누르면 [취소]와 같이 고친 것이 있을 때 한 번 묻는다', async () => {
    const r = await renderScreen();
    await r.click('member-field-add');
    await r.click('member-field-type-tile-text');
    await r.click('member-field-form-next');
    await r.change('member-field-form-name', '활동명');

    fireEvent.keyDown(screen.getByTestId('member-field-form-name'), { key: 'Escape' });
    await settle();
    await r.t.rerender();

    expect(r.t.getModalStack()).toEqual(['member_field_form_modal']);
    expect(r.modal()!.confirmClose).toBe(true);
    // 다른 키는 아무 일도 하지 않는다
    await r.click('member-field-form-close-keep');
    fireEvent.keyDown(screen.getByTestId('member-field-form-name'), { key: 'Enter' });
    await settle();
    expect(r.modal()!.confirmClose).toBe(false);
  });

  // @scenario case=view_readonly
  // @effects readonly_view_locks_inputs_and_hides_save
  it('조회 권한만 있으면 [보기]가 모든 칸을 잠근 창을 열고 하단에는 [닫기]만 있다', async () => {
    const r = await renderScreen({ defs: fieldDefs({ abilities: { can_update: false } }), routes: [jobDetail()] });

    await r.click('member-field-table-open-job');

    expect(r.modal()).toMatchObject({ readOnly: true, isNew: false });
    expect(screen.getByText('항목 보기', { selector: '.modal-title' })).toBeInTheDocument();
    expect(document.getElementById('member_field_form_readonly_notice')).not.toBeNull();
    const body = document.getElementById('member_field_form_body')!;
    const controls = body.querySelectorAll('input, select, textarea');
    expect(controls.length).toBeGreaterThan(15);
    controls.forEach((el) => expect(el, (el as HTMLElement).dataset.testid ?? el.outerHTML.slice(0, 80)).toBeDisabled());
    // 선택지 추가, 붙여넣기, 지우기 단추가 없다
    expect(screen.queryByTestId('member-field-form-options-add')).toBeNull();
    expect(screen.queryByTestId('member-field-form-options-paste-open')).toBeNull();
    expect(screen.queryByTestId('member-field-form-option-remove-0')).toBeNull();
    // 사용 인원은 읽을 수 있다
    expect(screen.getByTestId('member-field-form-option-0').textContent).toContain('사용 3명');

    expect(screen.queryByTestId('member-field-form-save')).toBeNull();
    expect(screen.getByTestId('member-field-form-cancel').textContent).toBe('닫기');
    await r.click('member-field-form-cancel');
    expect(r.t.getModalStack()).toEqual([]);
    expect(r.modal()).toBeNull();
  });
});

describe('항목 삭제 확인', () => {
  // @scenario case=delete_confirm
  // @effects delete_requires_confirm_check_and_shows_values_count
  it('삭제 창은 입력된 값 수를 알리고 확인 체크 전에는 삭제 단추가 잠긴다', async () => {
    const r = await renderScreen({ routes: [jobDetail()] });

    await r.click('member-field-table-delete-job');

    expect(r.t.getModalStack()).toEqual(['member_field_delete_modal']);
    expect(lines(r.requests)).toEqual([`GET ${FIELDS}/5`]);
    expect(screen.getByTestId('member-field-delete-message').textContent).toBe('「직업」 항목을 삭제합니다.');
    expect(screen.getByTestId('member-field-delete-values').textContent).toBe('회원 128명이 입력한 값이 함께 삭제되며 되돌릴 수 없습니다.');
    expect(screen.getByTestId('member-field-delete-submit')).toBeDisabled();

    await r.check('member-field-delete-confirm');
    expect(r.t.getState()._global.member_field_delete_modal.confirmed).toBe(true);
    expect(screen.getByTestId('member-field-delete-submit')).not.toBeDisabled();
    // 체크만으로는 지우지 않는다
    expect(lines(r.requests)).toEqual([`GET ${FIELDS}/5`]);
  });

  // @scenario case=delete_submit
  // @effects delete_sends_version_and_refetches
  it('확인한 뒤 누르면 정의 버전을 실어 DELETE 를 보내고 창을 닫은 뒤 표를 다시 받는다', async () => {
    const r = await renderScreen({ routes: [jobDetail(), { method: 'DELETE', endsWith: '/fields/5', body: { success: true, data: { removed_values: 128, fields_version: 'v2' } } }] });
    await r.click('member-field-table-delete-job');
    await r.check('member-field-delete-confirm');
    r.requests.length = 0;

    await r.click('member-field-delete-submit');

    expect(lines(r.requests)).toEqual([`DELETE ${FIELDS}/5?fields_version=v1`]);
    expect(r.t.getModalStack()).toEqual([]);
    expect(r.t.getState()._global.member_field_delete_modal).toBeNull();
    expect(r.refetched).toEqual(['memberFieldDefs']);
  });

  // @scenario case=delete_no_values
  // @effects delete_requires_confirm_check_and_shows_values_count
  it('입력된 값이 없으면 인원 없이 되돌릴 수 없음만 알린다', async () => {
    const detail = jobDetail();
    detail.body.data.field.values_count = 0;
    const r = await renderScreen({ routes: [detail] });

    await r.click('member-field-table-delete-job');

    expect(screen.getByTestId('member-field-delete-values').textContent).toBe('아직 입력된 값은 없습니다. 삭제한 항목은 되돌릴 수 없습니다.');
    expect(screen.getByTestId('member-field-delete-submit')).toBeDisabled();
  });

  // @scenario case=delete_in_use
  // @effects delete_in_use_shows_usages_and_link_to_display_name
  it('U4: 표시 이름에 쓰이는 항목이라 거부되면(409 field_in_use) 사용처 목록과 표시 이름 구획으로 가는 단추를 보이고 삭제를 잠근다', async () => {
    const usages = [
      { type: 'selectable_kind', label: '회원이 고를 수 있는 종류', area: null },
      { type: 'area', label: '자리 「사이트 기본」의 고정 종류', area: 'core:site' },
      { type: 'area_instance', label: '게시판 글과 댓글: 자유게시판', area: 'sirsoft-board:posts' },
    ];
    const r = await renderScreen({
      routes: [jobDetail(), { method: 'DELETE', endsWith: '/fields/5', status: 409, body: { success: false, message: '표시 이름에 쓰이고 있는 항목이라 삭제할 수 없습니다.', error_code: 'field_in_use', errors: { usages }, data: { usages } } }],
    });
    await r.click('member-field-table-delete-job');
    // 거부되기 전에는 사용처 상자가 없다
    expect(document.querySelector('#member_field_delete_usages')).toBeNull();
    await r.check('member-field-delete-confirm');

    await r.click('member-field-delete-submit');

    // 창은 그대로 두고 사용처를 창 안에 보인다(토스트로 흘려보내지 않는다)
    expect(r.t.getModalStack()).toEqual(['member_field_delete_modal']);
    expect(r.t.getToasts()).toEqual([]);
    expect(r.t.getState()._global.member_field_delete_modal.isDeleting).toBe(false);
    expect(r.t.getState()._global.member_field_delete_modal.usages).toEqual(usages);
    expect(r.refetched).toEqual([]);

    const box = document.querySelector('#member_field_delete_usages') as HTMLElement;
    expect(box.getAttribute('role')).toBe('alert');
    expect(screen.getByTestId('member-field-delete-usages-title').textContent).toBe('표시 이름에 쓰이고 있어 삭제할 수 없습니다.');
    expect([...document.querySelectorAll('#member_field_delete_usage_list li')].map((li) => li.textContent)).toEqual(usages.map((u) => u.label));
    expect(screen.getByTestId('member-field-delete-usages-link').textContent).toBe('표시 이름 설정으로 가기');
    // 확인 체크칸을 거두고 삭제 단추를 잠근다(설정에서 뺀 뒤 다시 열어야 지울 수 있다)
    expect(screen.queryByTestId('member-field-delete-confirm')).toBeNull();
    expect(screen.getByTestId('member-field-delete-submit')).toBeDisabled();

    // 단추는 창을 닫고 같은 화면의 표시 이름 구획으로 스크롤한다
    const link = collect(deleteModal, (n) => n.id === 'member_field_delete_usage_link')[0];
    const steps = collect(link.actions, (n) => typeof n.handler === 'string' && n.handler !== 'sequence').map((n) => n.handler);
    expect(steps).toEqual(['closeModal', 'setState', 'scrollIntoView']);
    expect(collect(link.actions, (n) => n.handler === 'scrollIntoView')[0].params.selector).toBe('#member_section_display_name');

    // 사용처를 읽는 식: 서버가 오류 묶음(errors.usages)에 실어 준 목록을 읽는다(응답 본문의 data 는 레이아웃에서 읽지 않는다)
    const store = collect(deleteModal, (n) => n.handler === 'setState' && typeof n.params?.['member_field_delete_modal.usages'] === 'string')[0];
    const read = (error: Json) => new Function('error', `return (${String(store.params['member_field_delete_modal.usages']).replace(/^\{\{/, '').replace(/\}\}$/, '')});`)(error);
    expect(String(store.params['member_field_delete_modal.usages'])).not.toContain('error.data');
    expect(read({ error_code: 'field_in_use', errors: { usages } })).toEqual(usages);
    expect(read({ error_code: 'field_in_use' })).toEqual([]);
    expect(read({ error_code: 'field_in_use' })).toEqual([]);

    await r.click('member-field-delete-usages-link');
    expect(r.t.getModalStack()).toEqual([]);
    expect(r.t.getState()._global.member_field_delete_modal).toBeNull();
  });

  // @scenario case=delete_failed
  // @effects delete_failure_keeps_modal_and_shows_server_message
  it('그 밖의 이유로 거부되면 서버 문구를 알리고 창을 그대로 둔다', async () => {
    const r = await renderScreen({
      routes: [jobDetail(), { method: 'DELETE', endsWith: '/fields/5', status: 422, body: { success: false, message: '지금은 삭제할 수 없습니다.' } }],
    });
    await r.click('member-field-table-delete-job');
    await r.check('member-field-delete-confirm');

    await r.click('member-field-delete-submit');

    expect(shownToasts(r.t.getToasts())).toEqual([{ type: 'error', message: '지금은 삭제할 수 없습니다.' }]);
    expect(r.t.getModalStack()).toEqual(['member_field_delete_modal']);
    expect(r.t.getState()._global.member_field_delete_modal.isDeleting).toBe(false);
    expect(document.querySelector('#member_field_delete_usages')).toBeNull();
    expect(r.refetched).toEqual([]);
  });

  // @scenario case=delete_conflict
  // @effects delete_conflict_closes_and_refetches
  it('다른 관리자가 먼저 바꿔 409 가 오면 지우지 않고 알린 뒤 창을 닫고 표를 다시 받는다', async () => {
    const r = await renderScreen({
      routes: [jobDetail(), { method: 'DELETE', endsWith: '/fields/5', status: 409, body: { success: false, message: 'conflict', error_code: 'fields_changed', data: { fields_version: 'v2' } } }],
    });
    await r.click('member-field-table-delete-job');
    await r.check('member-field-delete-confirm');

    await r.click('member-field-delete-submit');

    expect(shownToasts(r.t.getToasts())).toEqual([{ type: 'error', message: CHANGED }]);
    expect(r.t.getModalStack()).toEqual([]);
    expect(r.refetched).toEqual(['memberFieldDefs']);
  });

  // @scenario case=delete_cancel
  // @effects delete_cancel_sends_nothing
  it('취소하면 아무것도 지우지 않는다', async () => {
    const r = await renderScreen({ routes: [jobDetail()] });
    await r.click('member-field-table-delete-job');
    r.requests.length = 0;

    await r.click('member-field-delete-cancel');

    expect(r.requests).toHaveLength(0);
    expect(r.t.getModalStack()).toEqual([]);
  });
});
