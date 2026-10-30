/**
 * @file admin-schedule-form-modal-untouched-fields.test.tsx
 * @description 스케줄 편집 창 — 손대지 않은 칸은 저장값 그대로 보낸다(제한 시간·실행 시각)
 *
 * 실측: 코어·확장이 등록한 작업(제한 시간이 비어 있음)을 편집 창에서 주기만 바꿔 저장하면 제한 시간이 60초로 저장되고
 * 「운영자가 바꾼 칸」에 제한 시간이 들어갔다. 창의 제한 시간 칸이 빈 값을 60 으로 채워 보였고 저장 본문은 그 값을
 * 늘 실었기 때문이다. 비어 있는 제한 시간은 실행기가 유형별 기본(Shell 60초·URL 30초·큐 실행 600초 등)을 쓰라는
 * 뜻인데 그 의미가 저장 한 번으로 사라지고, 이후 확장 업데이트도 그 칸을 덮지 않는다.
 *
 * 같은 결함군: 주기 「매일」인데 실행 시각이 04:10 처럼 선언된 작업은, 주기를 그대로 두고 다른 칸만 바꿔 저장해도
 * 실행 시각이 「매일」의 대표 식(00:00)으로 바뀌었다(식이 주기에서 다시 만들어졌다).
 *
 * 실제 배포 JSON(`_modal_form.json`)의 창 노드를 엔진 하네스로 그리고 폼 제출을 실제로 태워 저장 요청 본문을 잰다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const formModal = require('../../layouts/partials/admin_schedule_list/_modal_form.json');
const adminKo = require('../../lang/partial/ko/admin.json');

type Json = Record<string, any>;

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children ?? text}</span>;
const TestP: React.FC<any> = ({ className, children, text }) => <p className={className}>{children ?? text}</p>;
const TestLabel: React.FC<any> = ({ className, children, text }) => <label className={className}>{children ?? text}</label>;
const TestUl: React.FC<any> = ({ children }) => <ul>{children}</ul>;
const TestLi: React.FC<any> = ({ children, text }) => <li>{children ?? text}</li>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestBadge: React.FC<any> = ({ text }) => <span data-badge="">{text}</span>;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;
const TestModal: React.FC<any> = ({ children }) => <div role="dialog">{children}</div>;
const TestForm: React.FC<any> = ({ id, className, children, onSubmit }) => (
  <form id={id} className={className} noValidate onSubmit={onSubmit}>
    {children}
  </form>
);
const TestInput: React.FC<any> = ({ type, name, defaultValue, value, placeholder, min, max, disabled, onChange }) => (
  <input
    type={type}
    name={name}
    defaultValue={value === undefined ? defaultValue : undefined}
    value={value}
    readOnly={value !== undefined && !onChange}
    placeholder={placeholder}
    min={min}
    max={max}
    disabled={disabled}
    onChange={onChange}
  />
);
const TestTextarea: React.FC<any> = ({ name, defaultValue, disabled }) => (
  <textarea name={name} defaultValue={defaultValue} disabled={disabled} />
);
// 선택 상자·스위치·다국어 입력은 저장 본문이 폼 값이 아니라 전역 상태에서 읽는다 — 값만 보이게 그린다
const TestSelect: React.FC<any> = ({ name, value }) => <span data-select={name}>{String(value ?? '')}</span>;
const TestToggle: React.FC<any> = ({ name, checked }) => <span data-toggle={name}>{String(checked)}</span>;
const TestMultilingual: React.FC<any> = ({ name }) => <span data-multilingual={name} />;
const TestButton: React.FC<any> = ({ type, form, disabled, children }) => (
  <button type={type ?? 'button'} form={form} disabled={disabled}>
    {children}
  </button>
);

/** 창이 쓰는 컴포넌트만 대역으로 등록한다. */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (component: React.FC<any>, name: string) => ({ component, metadata: { name, type: 'basic' } });
  const composite = (component: React.FC<any>, name: string) => ({ component, metadata: { name, type: 'composite' } });

  (registry as any).registry = {
    Div: basic(TestDiv, 'Div'),
    Span: basic(TestSpan, 'Span'),
    P: basic(TestP, 'P'),
    Label: basic(TestLabel, 'Label'),
    Ul: basic(TestUl, 'Ul'),
    Li: basic(TestLi, 'Li'),
    Icon: basic(TestIcon, 'Icon'),
    Form: basic(TestForm, 'Form'),
    Input: basic(TestInput, 'Input'),
    Textarea: basic(TestTextarea, 'Textarea'),
    Select: basic(TestSelect, 'Select'),
    Button: basic(TestButton, 'Button'),
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Modal: composite(TestModal, 'Modal'),
    Badge: composite(TestBadge, 'Badge'),
    Toggle: composite(TestToggle, 'Toggle'),
    MultilingualInput: composite(TestMultilingual, 'MultilingualInput'),
  };

  return registry;
}

/** 목록 행(ScheduleResource 모양)을 만든다 — 확장이 등록한 작업, 제한 시간 비어 있음. */
function makeRow(overrides: Json = {}): Json {
  return {
    id: 103,
    name: 'prune-temp-attachments',
    label: { ko: '임시 페이지 첨부 삭제', en: 'Prune temporary attachments' },
    description: null,
    type: 'artisan',
    command: 'sirsoft-page:prune-temp-attachments',
    frequency: 'daily',
    expression: '0 0 * * *',
    timeout: null,
    without_overlapping: 0,
    on_one_server: false,
    dispatch_mode: 'sync',
    queue_connection: null,
    queue_name: null,
    history_mode: 'all',
    notify_on_failure: true,
    run_in_maintenance: false,
    is_active: true,
    is_owned: true,
    owner: { name: 'sirsoft-page' },
    locked_fields: ['command', 'type', 'name', 'enabled_condition', 'timezone'],
    overridden_fields: [],
    enabled_condition: [],
    unmet_conditions: [],
    ...overrides,
  };
}

/** 목록 「수정」과 같은 모양으로 창의 전역 상태를 채운다(_partial_schedule_datagrid 의 edit 갈래). */
function editState(row: Json, formOverrides: Json = {}): Json {
  return {
    selectedSchedule: row,
    isEditMode: true,
    scheduleFormErrors: null,
    scheduleFormErrorMessage: '',
    scheduleForm: {
      label: row.label ?? {},
      description: row.description ?? {},
      type: row.type ?? 'artisan',
      frequency: row.frequency ?? 'daily',
      overlapEnabled: (row.without_overlapping ?? 0) > 0,
      without_overlapping: (row.without_overlapping ?? 0) > 0 ? row.without_overlapping : 1440,
      on_one_server: !!row.on_one_server,
      dispatch_mode: row.dispatch_mode ?? 'sync',
      queue_connection: row.queue_connection ?? '',
      queue_name: row.queue_name ?? '',
      history_mode: row.history_mode ?? 'all',
      notify_on_failure: row.notify_on_failure !== false,
      run_in_maintenance: !!row.run_in_maintenance,
      is_active: !!row.is_active,
      ...formOverrides,
    },
  };
}

/** 안내 문구 키(언어 파일 반영 전에도 렌더 단언이 가능하도록 시험이 채운다). */
const TIMEOUT_DEFAULT_TEXT = {
  artisan_sync: '[기본] 제한 없음',
  artisan_queue: '[기본] 600초',
  shell: '[기본] 60초',
  url: '[기본] 30초',
  job: '[기본] Job 설정',
};

function translations(): Json {
  const admin = JSON.parse(JSON.stringify(adminKo));
  admin.schedules.form.timeout_default = { ...(admin.schedules.form.timeout_default ?? {}), ...TIMEOUT_DEFAULT_TEXT };
  return { admin, common: {} };
}

/** 테스트 하네스의 fetch spy 에 기록된 저장 요청 본문 */
function savedBodies(id: number): Json[] {
  const spy = globalThis.fetch as unknown as { mock?: { calls: any[][] } };
  return (spy.mock?.calls ?? [])
    .filter((call) => String(call[0]).includes(`/api/admin/schedules/${id}`))
    .map((call) => JSON.parse(String(call[1]?.body ?? '{}')));
}

function timeoutInput(): HTMLInputElement {
  return document.querySelector('input[name="timeout"]') as HTMLInputElement;
}

describe('스케줄 편집 창 — 손대지 않은 칸은 저장값 그대로', () => {
  let t: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    setupRegistry();
  });

  afterEach(() => {
    t?.cleanup();
  });

  async function renderWith(globalState: Json): Promise<void> {
    t = createLayoutTest(
      { version: '1.0.0', layout_name: 'test_schedule_form_modal', components: [formModal] } as any,
      {
        componentRegistry: ComponentRegistry.getInstance(),
        translations: translations(),
        initialState: { _global: globalState },
      }
    );
    await t.render();
  }

  async function submit(id: number): Promise<Json> {
    fireEvent.submit(document.getElementById('schedule_form_modal') as HTMLFormElement);
    await waitFor(() => expect(savedBodies(id).length).toBeGreaterThan(0));
    return savedBodies(id)[0];
  }

  /**
   * @scenario case=form_untouched
   * @effects schedule_form_edit_keeps_untouched_fields
   */
  it('제한 시간이 비어 있는 작업은 칸도 비어 보인다 — 60 으로 채우지 않는다', async () => {
    await renderWith(editState(makeRow()));

    expect(timeoutInput()).not.toBeNull();
    expect(timeoutInput().value, '비어 있는 제한 시간을 60 으로 채워 보인다').toBe('');
  });

  /**
   * @scenario case=form_untouched
   * @effects schedule_form_edit_keeps_untouched_fields
   */
  it('주기만 바꿔 저장하면 제한 시간은 빈 값(null)으로 간다', async () => {
    await renderWith(editState(makeRow(), { frequency: 'weekly' }));

    const body = await submit(103);
    expect(body.frequency).toBe('weekly');
    expect(body.expression).toBe('0 0 * * 0');
    expect(body.timeout, '손대지 않은 제한 시간이 60 으로 저장된다').toBeNull();
  });

  /**
   * @scenario case=form_untouched
   * @effects schedule_form_edit_keeps_untouched_fields
   */
  it('저장된 제한 시간은 그 값으로 보이고, 운영자가 지우면 빈 값(null)으로 간다', async () => {
    await renderWith(editState(makeRow({ timeout: 120 })));
    expect(timeoutInput().value).toBe('120');

    fireEvent.change(timeoutInput(), { target: { value: '' } });
    const body = await submit(103);
    expect(body.timeout).toBeNull();
  });

  /**
   * @scenario case=form_untouched
   * @effects schedule_form_edit_keeps_untouched_fields
   */
  it('주기를 그대로 두면 선언된 실행 시각(cron 식)을 그대로 보낸다 — 주기의 대표 식으로 바꾸지 않는다', async () => {
    // 확장이 「매일 04:10」으로 선언한 작업 — 주기 칸은 「매일」이다
    const row = makeRow({ id: 132, frequency: 'daily', expression: '10 4 * * *' });
    await renderWith(editState(row, { notify_on_failure: false }));

    const body = await submit(132);
    expect(body.frequency).toBe('daily');
    expect(body.expression, '손대지 않은 실행 시각이 00:00 으로 바뀐다').toBe('10 4 * * *');
  });

  /**
   * @scenario case=form_untouched
   * @effects schedule_form_edit_keeps_untouched_fields
   */
  it('바로 실행 작업은 저장된 큐 연결·큐를 비우지 않고 그대로 보낸다', async () => {
    const row = makeRow({ queue_connection: 'database-long', queue_name: 'long' });
    await renderWith(editState(row));

    const body = await submit(103);
    expect(body.dispatch_mode).toBe('sync');
    expect(body.queue_connection).toBe('database-long');
    expect(body.queue_name).toBe('long');
  });

  /**
   * @scenario case=form_untouched
   * @effects schedule_form_edit_keeps_untouched_fields
   */
  it.each([
    ['artisan', 'sync', TIMEOUT_DEFAULT_TEXT.artisan_sync],
    ['artisan', 'queue', TIMEOUT_DEFAULT_TEXT.artisan_queue],
    ['shell', 'sync', TIMEOUT_DEFAULT_TEXT.shell],
    ['url', 'queue', TIMEOUT_DEFAULT_TEXT.url],
    ['job', 'sync', TIMEOUT_DEFAULT_TEXT.job],
  ])('비워 둘 때의 실제 기본값을 유형(%s)·실행 방식(%s)별로 안내한다', async (type, mode, text) => {
    await renderWith(editState(makeRow({ type, dispatch_mode: mode })));

    expect(screen.getByText(text)).toBeTruthy();
  });

  /**
   * @scenario case=form_untouched
   * @effects schedule_overrides_only_for_owned_rows
   */
  it('「운영자 수정됨」 배지는 코어·확장 작업에만 붙는다 — 직접 등록 작업은 기록이 있어도 붙지 않는다', async () => {
    const badge = adminKo.schedules.form.overridden_badge as string;

    // 예전 판이 직접 등록 행에 남긴 기록이 응답에 실려 와도 배지를 그리지 않는다
    await renderWith(
      editState(makeRow({ is_owned: false, owner: null, locked_fields: [], overridden_fields: ['dispatch_mode', 'history_mode'] }))
    );
    expect(screen.queryByText(badge)).toBeNull();
    t.cleanup();

    await renderWith(editState(makeRow({ overridden_fields: ['frequency'] })));
    expect(screen.getByText(badge)).toBeTruthy();
  });

  /**
   * @scenario case=form_untouched
   * @effects schedule_form_edit_keeps_untouched_fields
   */
  it('새 등록 창도 제한 시간을 비워 두고 시작한다(유형별 기본을 따른다)', async () => {
    await renderWith({
      selectedSchedule: null,
      isEditMode: false,
      scheduleFormErrors: null,
      scheduleForm: { type: 'artisan', frequency: 'daily', dispatch_mode: 'sync' },
    });

    expect(timeoutInput().value).toBe('');
  });
});
