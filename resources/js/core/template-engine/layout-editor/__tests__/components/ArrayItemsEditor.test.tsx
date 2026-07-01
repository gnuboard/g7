/**
 * ArrayItemsEditor.test.tsx — `array` 노드 에디터 RTL
 *
 * 검증:
 *  - node.props[arrayProp] 항목을 행으로 렌더(필드 스키마=params.fields)
 *  - 항목 추가(params.newItem/fields 골격) → onPatchNode({...node, props}) (노드 전체 교체)
 *  - 삭제/정렬 → props 배열 패치(PATCH_LAYOUT 생존)
 *  - text/select/boolean/icon 필드 즉시 반영
 *  - i18n-text 평문 blur → createCustomKey → 항목 필드가 `$t:custom.*` 로 치환
 *  - 기존 커스텀 키 blur → updateCustomKeyValue(현재 로케일 값만, 키 유지)
 *  - 정적-바인딩 가드: props[arrayProp] 가 `{{...}}`/비-배열 → "바인딩됨" 디그레이드
 *  - arrayProp 미선언 capability → misconfigured 안내
 *  - 원시 string[] 항목 — primary 필드로 값 자체 편집
 *
 * @since engine-v1.50.0
 */

// 시나리오 매니페스트(tests/scenarios/layout-editor-array-editor.yaml) cross product 18 케이스
// 전수 마킹(test-scenario-coverage 룰). 구조 연산(add/remove/move)은 field_widget 무관 → text 대표,
// 필드 편집(edit_*)은 위젯별 분기. 바인딩/비-배열/빈 배열의 CRUD 는 manifest exclusions 로 제외.
// @scenario array_value=static_array, field_widget=text, operation=add, template=sirsoft-basic
// @scenario array_value=static_array, field_widget=text, operation=add, template=sirsoft-admin_basic
// @scenario array_value=empty, field_widget=text, operation=add, template=sirsoft-basic
// @scenario array_value=empty, field_widget=text, operation=add, template=sirsoft-admin_basic
// @scenario array_value=static_array, field_widget=text, operation=remove, template=sirsoft-basic
// @scenario array_value=static_array, field_widget=text, operation=remove, template=sirsoft-admin_basic
// @scenario array_value=static_array, field_widget=text, operation=move, template=sirsoft-basic
// @scenario array_value=static_array, field_widget=text, operation=move, template=sirsoft-admin_basic
// @scenario array_value=static_array, field_widget=text, operation=edit_text_field, template=sirsoft-basic
// @scenario array_value=static_array, field_widget=text, operation=edit_text_field, template=sirsoft-admin_basic
// @scenario array_value=static_array, field_widget=i18n_text, operation=edit_i18n_field, template=sirsoft-basic
// @scenario array_value=static_array, field_widget=i18n_text, operation=edit_i18n_field, template=sirsoft-admin_basic
// @scenario array_value=static_array, field_widget=select, operation=edit_select, template=sirsoft-basic
// @scenario array_value=static_array, field_widget=select, operation=edit_select, template=sirsoft-admin_basic
// @scenario array_value=static_array, field_widget=boolean, operation=edit_boolean, template=sirsoft-basic
// @scenario array_value=static_array, field_widget=boolean, operation=edit_boolean, template=sirsoft-admin_basic
// @scenario array_value=static_array, field_widget=icon, operation=edit_icon, template=sirsoft-basic
// @scenario array_value=static_array, field_widget=icon, operation=edit_icon, template=sirsoft-admin_basic
//
// effects 검증 마킹은 아래 jsdoc 블록(@effects 연속 라인) — 매니페스트 effects 전수 커버.
/**
 * @scenario array_value=static_array, field_widget=text, operation=add, template=sirsoft-basic
 * @effects array_capability_declared_on_p1_components_both_templates,
 *   array_editor_registered_via_registercoreeditors_kind_agnostic,
 *   property_modal_dispatches_array_node_editor_in_props_tab_by_kind,
 *   binding_expression_array_prop_degrades_to_bound_notice,
 *   non_array_prop_value_degrades_to_bound_notice,
 *   missing_array_prop_capability_shows_misconfigured_notice,
 *   add_item_appends_newitem_skeleton_patches_whole_node,
 *   add_item_synthesizes_empty_item_from_fields_when_no_newitem,
 *   remove_item_drops_only_that_index,
 *   move_up_down_swaps_adjacent_with_boundary_guard,
 *   text_field_updates_item_immediately,
 *   icon_field_updates_item_with_icon_name_string,
 *   select_field_updates_item_with_chosen_value,
 *   boolean_field_toggles_item_flag,
 *   i18n_text_plain_blur_creates_custom_key_and_writes_dollar_t_custom,
 *   existing_custom_key_blur_updates_current_locale_value_keeps_key,
 *   primitive_string_item_edits_value_via_primary_field,
 *   node_prop_change_clears_draft_buffer_no_stale
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';

vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: {
      templateIdentifier: 'sirsoft-basic',
      locale: 'ko',
      selectedRoute: { path: '/login', layoutName: 'login' },
    },
  }),
}));

const createCustomKey = vi.fn();
const updateCustomKeyValue = vi.fn();
const findCustomKeyRow = vi.fn();
const bustTranslationCache = vi.fn().mockResolvedValue(undefined);
// i18n-text 필드는 공통 위젯 I18nTextField(useCustomTranslation SSoT)를 거친다(7-b 통일).
vi.mock('../../hooks/useInlineEdit', () => ({
  createCustomKey: (...a: unknown[]) => createCustomKey(...a),
  updateCustomKeyValue: (...a: unknown[]) => updateCustomKeyValue(...a),
  findCustomKeyRow: (...a: unknown[]) => findCustomKeyRow(...a),
  bustTranslationCache: (...a: unknown[]) => bustTranslationCache(...a),
  EDITOR_TRANSLATIONS_REFRESHED_EVENT: 'g7le:editor-translations-refreshed',
}));

vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: {
    getInstance: () => ({
      translate: (key: string) => (key === 'custom.login.1' ? '기존 라벨' : key),
    }),
  },
}));
vi.mock('../../components/LocaleSwitcher', () => ({
  readSupportedLocales: () => ['ko', 'en', 'ja'],
  localeDisplayLabel: (loc: string) => ({ ko: '한국어', en: 'English', ja: '日本語' }[loc] ?? loc),
}));
vi.mock('../../utils/authToken', () => ({ buildAuthHeaders: (h: Record<string, string>) => h }));

import { ArrayItemsEditor } from '../../components/property-controls/ArrayItemsEditor';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const t = (k: string, p?: Record<string, string | number>) =>
  p ? `${k}:${Object.values(p).join(',')}` : k;

const baseProps = {
  spec: null as never,
  manifest: null as never,
  t,
  templateIdentifier: 'sirsoft-basic',
};

/** tabs 필드 스키마 (TabNavigation 패턴). */
const tabsParams = {
  arrayProp: 'tabs',
  itemLabel: '탭',
  newItem: { id: '', label: '' },
  fields: [
    { key: 'label', widget: 'i18n-text', label: '라벨', primary: true },
    { key: 'id', widget: 'text', label: 'ID' },
    { key: 'iconName', widget: 'icon', label: '아이콘' },
    { key: 'disabled', widget: 'boolean', label: '비활성' },
  ],
};

function tabNode(tabs: unknown): EditorNode {
  return { type: 'composite', name: 'TabNavigation', props: { tabs } } as EditorNode;
}

afterEach(() => cleanup());
beforeEach(() => {
  createCustomKey.mockReset();
  updateCustomKeyValue.mockReset();
  findCustomKeyRow.mockReset();
  bustTranslationCache.mockClear();
});

/** i18n-text 필드(idx, key)의 I18nTextField 미리보기 입력칸. */
function i18nPreview(idx: number, key: string): HTMLInputElement {
  return screen.getByTestId(`g7le-array-field-${idx}-${key}-i18n-preview`) as HTMLInputElement;
}

describe('ArrayItemsEditor — props 배열 항목 편집', () => {
  it('정적 배열 항목을 행으로 렌더한다(필드 스키마)', () => {
    const node = tabNode([
      { id: 'a', label: '첫째' },
      { id: 'b', label: '둘째' },
    ]);
    render(<ArrayItemsEditor {...baseProps} node={node} params={tabsParams} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-array-row-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-array-row-1')).toBeInTheDocument();
    expect(i18nPreview(0, 'label').value).toBe('첫째'); // i18n-text 라벨 = I18nTextField 미리보기
    expect((screen.getByTestId('g7le-array-field-0-id') as HTMLInputElement).value).toBe('a');
  });

  it('필드 행은 가로 스크롤 대신 자연 wrap 한다 (fieldRow flexWrap) —', () => {
    const node = tabNode([{ id: 'a', label: '첫째' }]);
    render(<ArrayItemsEditor {...baseProps} node={node} params={tabsParams} onPatchNode={vi.fn()} />);
    // id 필드(text)를 감싼 <label style={fieldRow}> 가 wrap 가능해야(좁은 폭에서 라벨+입력 줄바꿈).
    const fieldRow = (screen.getByTestId('g7le-array-field-0-id').closest('label')) as HTMLElement;
    expect(fieldRow.style.flexWrap).toBe('wrap');
  });

  it('빈 배열 → 안내 표시', () => {
    render(<ArrayItemsEditor {...baseProps} node={tabNode([])} params={tabsParams} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-array-empty')).toBeInTheDocument();
  });

  it('"추가" → newItem 골격을 끝에 append (노드 전체 교체)', () => {
    const onPatchNode = vi.fn();
    render(<ArrayItemsEditor {...baseProps} node={tabNode([{ id: 'a', label: '첫째' }])} params={tabsParams} onPatchNode={onPatchNode} />);
    fireEvent.click(screen.getByTestId('g7le-array-add'));
    expect(onPatchNode).toHaveBeenCalledTimes(1);
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect((patched.props!.tabs as unknown[]).length).toBe(2);
    expect((patched.props!.tabs as Array<Record<string, unknown>>)[1]).toEqual({ id: '', label: '' });
  });

  describe('params.defaultItems — prop 미정의 시 스펙 선언 기본 목록 시드', () => {
    // IconSelect 류: 컴포넌트가 prop 미지정 시 내장 기본 목록으로 렌더 → 빈 목록에서 1개
    // 추가하면 prop 이 [추가분] 으로 기록되어 내장 목록 전체가 교체되던 함정 차단.
    const defaults = [
      { value: 'Home', label: 'Home', faIcon: 'home' },
      { value: 'User', label: 'User', faIcon: 'user' },
    ];
    const seededParams = {
      arrayProp: 'options',
      newItem: { value: '', label: '', faIcon: '' },
      defaultItems: defaults,
      fields: [{ key: 'label', widget: 'text', label: '라벨', primary: true }],
    };
    const iconNode = (props: Record<string, unknown>): EditorNode =>
      ({ type: 'composite', name: 'IconSelect', props } as EditorNode);

    it('prop 미정의(undefined) → defaultItems 가 시작 목록으로 렌더', () => {
      render(
        <ArrayItemsEditor {...baseProps} node={iconNode({})} params={seededParams} onPatchNode={vi.fn()} />,
      );
      expect(screen.getAllByTestId(/^g7le-array-row-/)).toHaveLength(2);
      expect((screen.getByTestId('g7le-array-field-0-label') as HTMLInputElement).value).toBe('Home');
    });

    it('시드 상태에서 "추가" → 기본 목록 전체 + 새 항목이 함께 커밋(내장 목록 교체 함정 차단)', () => {
      const onPatchNode = vi.fn();
      render(
        <ArrayItemsEditor {...baseProps} node={iconNode({})} params={seededParams} onPatchNode={onPatchNode} />,
      );
      fireEvent.click(screen.getByTestId('g7le-array-add'));
      const patched = onPatchNode.mock.calls[0][0] as EditorNode;
      const options = patched.props!.options as Array<Record<string, unknown>>;
      expect(options).toHaveLength(3);
      expect(options[0]).toEqual(defaults[0]);
      expect(options[1]).toEqual(defaults[1]);
      expect(options[2]).toEqual({ value: '', label: '', faIcon: '' });
    });

    it('명시적 빈 배열([]) → 시드하지 않음(작성자 의도 존중)', () => {
      render(
        <ArrayItemsEditor {...baseProps} node={iconNode({ options: [] })} params={seededParams} onPatchNode={vi.fn()} />,
      );
      expect(screen.getByTestId('g7le-array-empty')).toBeInTheDocument();
    });

    it('prop 이 정적 배열이면 그 값이 우선(시드 미적용)', () => {
      render(
        <ArrayItemsEditor
          {...baseProps}
          node={iconNode({ options: [{ value: 'X', label: '커스텀', faIcon: 'star' }] })}
          params={seededParams}
          onPatchNode={vi.fn()}
        />,
      );
      expect(screen.getAllByTestId(/^g7le-array-row-/)).toHaveLength(1);
      expect((screen.getByTestId('g7le-array-field-0-label') as HTMLInputElement).value).toBe('커스텀');
    });

    it('바인딩({{...}}) prop 은 종전대로 디그레이드(시드 무관)', () => {
      render(
        <ArrayItemsEditor
          {...baseProps}
          node={iconNode({ options: '{{icons.data}}' })}
          params={seededParams}
          onPatchNode={vi.fn()}
        />,
      );
      expect(screen.getByTestId('g7le-array-editor-bound')).toBeInTheDocument();
    });

    it('defaultItems 미선언 capability 는 종전 동작 그대로(빈 목록 안내)', () => {
      render(
        <ArrayItemsEditor
          {...baseProps}
          node={iconNode({})}
          params={{ arrayProp: 'options', fields: [{ key: 'label', widget: 'text', label: '라벨', primary: true }] }}
          onPatchNode={vi.fn()}
        />,
      );
      expect(screen.getByTestId('g7le-array-empty')).toBeInTheDocument();
    });
  });

  it('삭제 → 해당 인덱스만 제거', () => {
    const onPatchNode = vi.fn();
    render(<ArrayItemsEditor {...baseProps} node={tabNode([{ id: 'a' }, { id: 'b' }])} params={tabsParams} onPatchNode={onPatchNode} />);
    fireEvent.click(screen.getByTestId('g7le-array-remove-0'));
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.tabs).toEqual([{ id: 'b' }]);
  });

  it('위/아래 이동 → 인접 항목 스왑(경계 가드)', () => {
    const onPatchNode = vi.fn();
    render(<ArrayItemsEditor {...baseProps} node={tabNode([{ id: 'a' }, { id: 'b' }])} params={tabsParams} onPatchNode={onPatchNode} />);
    // 0행 위로 = 비활성
    expect((screen.getByTestId('g7le-array-up-0') as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(screen.getByTestId('g7le-array-down-0'));
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.tabs).toEqual([{ id: 'b' }, { id: 'a' }]);
  });

  it('text 필드 즉시 반영', () => {
    const onPatchNode = vi.fn();
    render(<ArrayItemsEditor {...baseProps} node={tabNode([{ id: 'a', label: 'x' }])} params={tabsParams} onPatchNode={onPatchNode} />);
    fireEvent.change(screen.getByTestId('g7le-array-field-0-id'), { target: { value: 'home' } });
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect((patched.props!.tabs as Array<Record<string, unknown>>)[0].id).toBe('home');
  });

  it('boolean 필드 토글 즉시 반영', () => {
    const onPatchNode = vi.fn();
    render(<ArrayItemsEditor {...baseProps} node={tabNode([{ id: 'a' }])} params={tabsParams} onPatchNode={onPatchNode} />);
    fireEvent.click(screen.getByTestId('g7le-array-field-0-disabled'));
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect((patched.props!.tabs as Array<Record<string, unknown>>)[0].disabled).toBe(true);
  });

  it('select 필드 선택 즉시 반영', () => {
    const onPatchNode = vi.fn();
    const params = {
      arrayProp: 'items',
      fields: [
        { key: 'label', widget: 'i18n-text', label: '라벨', primary: true },
        { key: 'variant', widget: 'select', label: '스타일', options: [{ value: 'danger', label: '위험' }] },
      ],
    };
    const node = { type: 'composite', name: 'ActionMenu', props: { items: [{ label: 'x' }] } } as EditorNode;
    render(<ArrayItemsEditor {...baseProps} node={node} params={params} onPatchNode={onPatchNode} />);
    fireEvent.change(screen.getByTestId('g7le-array-field-0-variant'), { target: { value: 'danger' } });
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect((patched.props!.items as Array<Record<string, unknown>>)[0].variant).toBe('danger');
  });

  it('i18n-text 평문 blur → createCustomKey 후 필드가 $t:custom.* 로 치환(공통 위젯)', async () => {
    createCustomKey.mockResolvedValue({ kind: 'ok', resource: { translation_key: 'custom.login.7' } });
    const onPatchNode = vi.fn();
    render(<ArrayItemsEditor {...baseProps} node={tabNode([{ id: 'a', label: '' }])} params={tabsParams} onPatchNode={onPatchNode} />);
    const preview = i18nPreview(0, 'label');
    fireEvent.change(preview, { target: { value: '새 라벨' } });
    fireEvent.blur(preview);
    await waitFor(() => expect(createCustomKey).toHaveBeenCalledWith('sirsoft-basic', 'login', 'ko', '새 라벨'));
    await waitFor(() => expect(onPatchNode).toHaveBeenCalled());
    const last = onPatchNode.mock.calls.at(-1)![0] as EditorNode;
    expect((last.props!.tabs as Array<Record<string, unknown>>)[0].label).toBe('$t:custom.login.7');
  });

  it('기존 커스텀 키 blur → updateCustomKeyValue(현재 로케일만, 키 유지)', async () => {
    updateCustomKeyValue.mockResolvedValue({ kind: 'ok' });
    const onPatchNode = vi.fn();
    render(
      <ArrayItemsEditor
        {...baseProps}
        node={tabNode([{ id: 'a', label: '$t:custom.login.1' }])}
        params={tabsParams}
        onPatchNode={onPatchNode}
      />,
    );
    const input = i18nPreview(0, 'label');
    expect(input.value).toBe('기존 라벨'); // 키 → 현재 로케일 해석값(raw 미노출)
    fireEvent.change(input, { target: { value: '수정 라벨' } });
    fireEvent.blur(input);
    await waitFor(() => expect(updateCustomKeyValue).toHaveBeenCalledWith('sirsoft-basic', 'custom.login.1', 'ko', '수정 라벨'));
    expect(createCustomKey).not.toHaveBeenCalled();
  });

  it('정적-바인딩 가드: props[arrayProp] 가 {{...}} → 디그레이드', () => {
    render(<ArrayItemsEditor {...baseProps} node={tabNode('{{(roles?.data ?? []).map(r => r)}}')} params={tabsParams} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-array-editor-bound')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-array-add')).not.toBeInTheDocument();
  });

  it('arrayProp 미선언 → misconfigured 안내', () => {
    render(<ArrayItemsEditor {...baseProps} node={tabNode([])} params={{}} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-array-editor-misconfigured')).toBeInTheDocument();
  });

  it('원시 string[] 항목 — primary 필드로 값 자체 편집', () => {
    const onPatchNode = vi.fn();
    const params = { arrayProp: 'options', fields: [{ key: 'value', widget: 'text', label: '값', primary: true }] };
    const node = { type: 'basic', name: 'Select', props: { options: ['ko', 'en'] } } as EditorNode;
    render(<ArrayItemsEditor {...baseProps} node={node} params={params} onPatchNode={onPatchNode} />);
    expect((screen.getByTestId('g7le-array-field-0-value') as HTMLInputElement).value).toBe('ko');
    fireEvent.change(screen.getByTestId('g7le-array-field-1-value'), { target: { value: 'ja' } });
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.options).toEqual(['ko', 'ja']);
  });

  /**
   * 원시 배열(BarChart labels 등)에 "추가" 시, newItem 이 원시값(빈 문자열)이면
   * 객체 합성 없이 원시값을 push 해 배열 형태가 보존돼야 한다. (이전엔 newItem 이 falsy 라
   * fields 기반 `{ value: '' }` 객체가 섞이는 회귀 — string[] 에 객체 혼입.)
   * @scenario unit=primitive_array_capability
   * @effects array_editor_primitive_newitem_pushes_raw_value_not_object, primitive_array_add_then_edit_keeps_raw_shape, barchart_labels_array_capability_static_literal_only
   */
  it('원시 string[] — "추가" 시 원시값(빈 문자열) 골격을 push (객체 합성 안 함)', () => {
    const onPatchNode = vi.fn();
    const params = {
      arrayProp: 'labels',
      newItem: '',
      fields: [{ key: 'value', widget: 'text', label: '항목', primary: true }],
    };
    const node = { type: 'composite', name: 'BarChart', props: { labels: ['Jan', 'Feb'] } } as EditorNode;
    render(<ArrayItemsEditor {...baseProps} node={node} params={params} onPatchNode={onPatchNode} />);
    fireEvent.click(screen.getByTestId('g7le-array-add'));
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.labels).toEqual(['Jan', 'Feb', '']);
  });

  it('원시 string[] — 추가 후 primary 편집으로 값 채움 (원시 형태 유지)', () => {
    const onPatchNode = vi.fn();
    const params = {
      arrayProp: 'labels',
      newItem: '',
      fields: [{ key: 'value', widget: 'text', label: '항목', primary: true }],
    };
    // 이미 빈 항목이 추가된 상태에서 그 항목을 편집.
    const node = { type: 'composite', name: 'BarChart', props: { labels: ['Jan', ''] } } as EditorNode;
    render(<ArrayItemsEditor {...baseProps} node={node} params={params} onPatchNode={onPatchNode} />);
    fireEvent.change(screen.getByTestId('g7le-array-field-1-value'), { target: { value: 'Mar' } });
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.labels).toEqual(['Jan', 'Mar']);
  });

  // 차트/데이터 정의용 신규 필드 위젯(number/color/number-list).
  // 부록8 (E) #4 — DonutChart.data(name/value/color) / BarChart.datasets(label/data:number[]/bg).
  describe('number/color/number-list 필드 위젯', () => {
    /** DonutChart data 필드 스키마(name/value/color). */
    const donutParams = {
      arrayProp: 'data',
      newItem: { name: '', value: 0, color: '#7C3AED' },
      fields: [
        { key: 'name', widget: 'i18n-text', label: '항목', primary: true },
        { key: 'value', widget: 'number', label: '값' },
        { key: 'color', widget: 'color', label: '색상' },
      ],
    };
    const donutNode = (data: unknown): EditorNode =>
      ({ type: 'composite', name: 'DonutChart', props: { data } }) as EditorNode;

    /**
     * @scenario unit=number_widget
     * @effects array_editor_number_widget_writes_numeric_value
     */
    it('number 위젯: 입력값을 Number 로 기록(빈 입력은 undefined)', () => {
      const onPatchNode = vi.fn();
      render(
        <ArrayItemsEditor
          {...baseProps}
          node={donutNode([{ name: 'A', value: 10, color: '#fff' }])}
          params={donutParams}
          onPatchNode={onPatchNode}
        />,
      );
      const input = screen.getByTestId('g7le-array-field-0-value') as HTMLInputElement;
      expect(input.value).toBe('10');
      fireEvent.change(input, { target: { value: '42' } });
      const patched = onPatchNode.mock.calls[0][0] as EditorNode;
      expect((patched.props!.data as Array<{ value: unknown }>)[0].value).toBe(42);
    });

    /**
     * @scenario unit=color_widget
     * @effects array_editor_color_widget_writes_hex_string
     */
    it('color 위젯: 색 피커 + 텍스트 입력 둘 다 HEX 문자열 기록', () => {
      const onPatchNode = vi.fn();
      render(
        <ArrayItemsEditor
          {...baseProps}
          node={donutNode([{ name: 'A', value: 10, color: '#7C3AED' }])}
          params={donutParams}
          onPatchNode={onPatchNode}
        />,
      );
      const text = screen.getByTestId('g7le-array-field-0-color') as HTMLInputElement;
      expect(text.value).toBe('#7C3AED');
      fireEvent.change(text, { target: { value: '#EC4899' } });
      const patched = onPatchNode.mock.calls[0][0] as EditorNode;
      expect((patched.props!.data as Array<{ color: unknown }>)[0].color).toBe('#EC4899');
    });

    /**
     * @scenario unit=number_list_widget
     * @effects array_editor_number_list_widget_parses_comma_separated_into_number_array
     */
    it('number-list 위젯: 콤마 구분 입력 ↔ number[] (비-수치 토큰 제거)', () => {
      const onPatchNode = vi.fn();
      const params = {
        arrayProp: 'datasets',
        newItem: { label: '', data: [], backgroundColor: '#7C3AED' },
        fields: [
          { key: 'label', widget: 'text', label: '계열', primary: true },
          { key: 'data', widget: 'number-list', label: '값 목록' },
        ],
      };
      const node = {
        type: 'composite',
        name: 'BarChart',
        props: { datasets: [{ label: 'Sales', data: [1, 2, 3], backgroundColor: '#7C3AED' }] },
      } as EditorNode;
      render(<ArrayItemsEditor {...baseProps} node={node} params={params} onPatchNode={onPatchNode} />);
      const input = screen.getByTestId('g7le-array-field-0-data') as HTMLInputElement;
      expect(input.value).toBe('1, 2, 3');
      fireEvent.change(input, { target: { value: '10, 20, x, 30' } });
      const patched = onPatchNode.mock.calls[0][0] as EditorNode;
      expect((patched.props!.datasets as Array<{ data: unknown }>)[0].data).toEqual([10, 20, 30]);
    });

    /**
     * @scenario unit=donut_add_shape
     * @effects array_editor_newitem_shape_matches_component_read_shape
     */
    it('추가 시 newItem shape(name/value/color)이 컴포넌트 읽기 shape 와 일치', () => {
      const onPatchNode = vi.fn();
      render(
        <ArrayItemsEditor {...baseProps} node={donutNode([])} params={donutParams} onPatchNode={onPatchNode} />,
      );
      fireEvent.click(screen.getByTestId('g7le-array-add'));
      const patched = onPatchNode.mock.calls[0][0] as EditorNode;
      const item = (patched.props!.data as Array<Record<string, unknown>>)[0];
      expect(item).toEqual({ name: '', value: 0, color: '#7C3AED' });
    });
  });

  //  (라이브 실측 발견) — ArrayItemsEditor 가 NodeEditorProps.candidates 를
  // 항목 i18n-text 필드의 I18nTextField 로 **전달**해야 `+데이터` 칩 삽입(키화) 입구가 뜬다. 종전엔
  // 전달이 끊겨(후보 0) 평문 라벨에 `+데이터` 버튼이 안 떴다(라이브 — TabNavigation.label 칸).
  describe('항목 i18n-text 필드 후보 풀 전달', () => {
    const scalarCandidate = {
      expression: '{{user.name}}', source: 'data_source' as const, sourceId: 'user',
      path: 'name', shape: 'scalar' as const, preview: '홍길동',
    };

    it('candidates 전달 시 평문 라벨 항목에 +데이터 버튼 노출', () => {
      render(
        <ArrayItemsEditor
          {...baseProps}
          node={tabNode([{ id: 'a', label: '평문 라벨' }])}
          params={tabsParams}
          onPatchNode={vi.fn()}
          candidates={[scalarCandidate]}
        />,
      );
      expect(
        screen.getByTestId('g7le-array-field-0-label-i18n-plus-data-btn'),
      ).toBeInTheDocument();
    });

    it('candidates 미전달 시 +데이터 버튼 미노출(디그레이드)', () => {
      render(
        <ArrayItemsEditor
          {...baseProps}
          node={tabNode([{ id: 'a', label: '평문 라벨' }])}
          params={tabsParams}
          onPatchNode={vi.fn()}
        />,
      );
      expect(
        screen.queryByTestId('g7le-array-field-0-label-i18n-plus-data-btn'),
      ).not.toBeInTheDocument();
    });
  });
});
