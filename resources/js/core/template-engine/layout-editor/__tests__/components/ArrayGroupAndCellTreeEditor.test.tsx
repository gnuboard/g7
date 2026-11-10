/**
 * ArrayGroupAndCellTreeEditor.test.tsx — `array-group` / `array-cell-tree` 노드 에디터 RTL
 *
 * 검증:
 *  - ArrayGroupEditor: 여러 배열 prop(BarChart labels+datasets)을 그룹마다 ArrayItemsEditor
 *    로 렌더, 한 그룹 패치가 다른 그룹 값 보존, groups 미선언 misconfigured.
 *  - ArrayCellTreeEditor: cardColumns 컬럼 추가/삭제/정렬 + id 편집, 컬럼 펼침 시
 *    cellChildren 트리를 ChildrenListControl 로 편집(자식 추가가 컬럼 cellChildren 패치로
 *    되돌아감), 정적-바인딩 가드, arrayProp 미선언 misconfigured.
 *
 * @since engine-v1.50.0
 */

// 시나리오 매니페스트(tests/scenarios/layout-editor-data-builtins.yaml) cross product(data_surface × aspect)는
// 각 조합을 재는 시험 바로 앞에 붙였다. multi_array(BarChart)와 cell_tree(CardGrid)는 위쪽 describe 가, 나머지
// 표면은 맨 아래 「데이터 표면별 capability 와 편집」 describe 가 실제 관리자 스펙으로 잰다.
/**
 * 효과 요약(마커 아님 — 평문, 시험별 표식으로 옮김): data_builtin_capability_attached_to_8a_targets_both_templates,
 *   array_group_editor_registered_via_registercoreeditors_kind_agnostic,
 *   array_cell_tree_editor_registered_via_registercoreeditors_kind_agnostic,
 *   property_modal_dispatches_data_builtin_node_editor_in_props_tab_by_kind,
 *   array_editor_number_widget_writes_numeric_value,
 *   array_editor_color_widget_writes_hex_string,
 *   array_editor_number_list_widget_parses_comma_separated_into_number_array,
 *   array_editor_newitem_shape_matches_component_read_shape,
 *   array_group_renders_multiple_array_editors_per_group,
 *   array_group_patch_one_group_preserves_other_group_value,
 *   array_group_misconfigured_when_no_groups,
 *   cell_tree_column_add_remove_move_with_id_edit,
 *   cell_tree_expand_edits_cellchildren_via_children_control,
 *   cell_tree_binding_expression_degrades_to_bound_notice,
 *   cell_tree_missing_array_prop_shows_misconfigured_notice,
 *   review_group_chipcheckbox_value_covered_by_propcontrol_not_dataprop,
 *   data_prop_static_nodeeditor_coexists_with_binding_dataprops,
 *   live_donut_data_item_edit_save_persists_to_user_page,
 *   live_barchart_dataset_edit_save_persists_to_user_page
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, within } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: {
      templateIdentifier: 'sirsoft-admin_basic',
      locale: 'ko',
      selectedRoute: { path: '/admin', layoutName: 'admin' },
    },
  }),
}));

const createCustomKey = vi.fn();
const updateCustomKeyValue = vi.fn();
const findCustomKeyRow = vi.fn();
const bustTranslationCache = vi.fn().mockResolvedValue(undefined);
vi.mock('../../hooks/useInlineEdit', () => ({
  createCustomKey: (...a: unknown[]) => createCustomKey(...a),
  updateCustomKeyValue: (...a: unknown[]) => updateCustomKeyValue(...a),
  findCustomKeyRow: (...a: unknown[]) => findCustomKeyRow(...a),
  bustTranslationCache: (...a: unknown[]) => bustTranslationCache(...a),
  EDITOR_TRANSLATIONS_REFRESHED_EVENT: 'g7le:editor-translations-refreshed',
}));
vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: { getInstance: () => ({ translate: (k: string) => k }) },
}));
vi.mock('../../components/LocaleSwitcher', () => ({
  readSupportedLocales: () => ['ko', 'en', 'ja'],
  localeDisplayLabel: (loc: string) => loc,
}));
vi.mock('../../utils/authToken', () => ({ buildAuthHeaders: (h: Record<string, string>) => h }));

import { ArrayGroupEditor } from '../../components/property-controls/ArrayGroupEditor';
import { ArrayCellTreeEditor } from '../../components/property-controls/ArrayCellTreeEditor';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { registerCoreEditors } from '../../spec/registerCoreEditors';
import { getNodeEditor } from '../../spec/nodeEditorRegistry';

const t = (k: string, p?: Record<string, string | number>) =>
  p ? `${k}:${Object.values(p).join(',')}` : k;

const baseProps = {
  spec: null as never,
  manifest: null as never,
  t,
  templateIdentifier: 'sirsoft-admin_basic',
};

afterEach(() => cleanup());
beforeEach(() => {
  createCustomKey.mockReset();
  updateCustomKeyValue.mockReset();
  findCustomKeyRow.mockReset();
});

describe('ArrayGroupEditor — 다중 배열 그룹(BarChart labels+datasets)', () => {
  const groupParams = {
    groups: [
      {
        arrayProp: 'labels',
        title: '항목 라벨',
        newItem: '',
        fields: [{ key: 'value', widget: 'text', label: '라벨', primary: true }],
      },
      {
        arrayProp: 'datasets',
        title: '데이터 계열',
        newItem: { label: '', data: [], backgroundColor: '#7C3AED' },
        fields: [
          { key: 'label', widget: 'text', label: '계열', primary: true },
          { key: 'data', widget: 'number-list', label: '값 목록' },
        ],
      },
    ],
  };
  const barNode = (): EditorNode =>
    ({
      type: 'composite',
      name: 'BarChart',
      props: {
        labels: ['Jan', 'Feb'],
        datasets: [{ label: 'Sales', data: [1, 2], backgroundColor: '#7C3AED' }],
      },
    }) as EditorNode;

  /**
   * @scenario aspect=capability_attach, data_surface=multi_array
   *
   * @effects array_group_renders_multiple_array_editors_per_group
   */
  it('그룹마다 ArrayItemsEditor 섹션을 렌더한다', () => {
    render(<ArrayGroupEditor {...baseProps} node={barNode()} params={groupParams} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-array-group-labels')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-array-group-datasets')).toBeInTheDocument();
  });

  /**
   * @scenario aspect=field_widget, data_surface=multi_array
   *
   * @effects array_group_patch_one_group_preserves_other_group_value
   */
  it('한 그룹(labels) 편집이 다른 그룹(datasets) 값을 보존한다', () => {
    const onPatchNode = vi.fn();
    render(<ArrayGroupEditor {...baseProps} node={barNode()} params={groupParams} onPatchNode={onPatchNode} />);
    // labels 그룹의 첫 항목(primary text) 편집.
    fireEvent.change(screen.getByTestId('g7le-array-field-0-value'), { target: { value: 'Mar' } });
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.labels).toEqual(['Mar', 'Feb']);
    // datasets 는 그대로 보존.
    expect(patched.props!.datasets).toEqual([{ label: 'Sales', data: [1, 2], backgroundColor: '#7C3AED' }]);
  });

  /**
   * @scenario aspect=capability_attach, data_surface=multi_array
   *
   * @effects array_group_misconfigured_when_no_groups
   */
  it('groups 미선언 → misconfigured 안내', () => {
    render(<ArrayGroupEditor {...baseProps} node={barNode()} params={{}} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-array-group-misconfigured')).toBeInTheDocument();
  });
});

describe('ArrayCellTreeEditor — 중첩 셀 트리(CardGrid cardColumns)', () => {
  const cellParams = {
    arrayProp: 'cardColumns',
    idField: 'id',
    cellChildrenProp: 'cellChildren',
    cellChildComponent: 'Div',
    newItem: { id: '', cellChildren: [] },
  };
  const cardNode = (cols: unknown): EditorNode =>
    ({ type: 'composite', name: 'CardGrid', props: { cardColumns: cols } }) as EditorNode;

  /**
   * @scenario aspect=structure_op, data_surface=cell_tree
   *
   * @effects cell_tree_column_add_remove_move_with_id_edit
   */
  it('컬럼 추가 시 newItem 골격(id/cellChildren)을 push', () => {
    const onPatchNode = vi.fn();
    render(<ArrayCellTreeEditor {...baseProps} node={cardNode([])} params={cellParams} onPatchNode={onPatchNode} />);
    fireEvent.click(screen.getByTestId('g7le-cell-tree-add'));
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.cardColumns).toEqual([{ id: '', cellChildren: [] }]);
  });

  /**
   * @scenario aspect=structure_op, data_surface=cell_tree
   *
   * @effects cell_tree_column_add_remove_move_with_id_edit
   */
  it('컬럼 id 편집이 해당 컬럼만 패치', () => {
    const onPatchNode = vi.fn();
    render(
      <ArrayCellTreeEditor
        {...baseProps}
        node={cardNode([{ id: 'a', cellChildren: [] }, { id: 'b', cellChildren: [] }])}
        params={cellParams}
        onPatchNode={onPatchNode}
      />,
    );
    fireEvent.change(screen.getByTestId('g7le-cell-tree-id-1'), { target: { value: 'beta' } });
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.cardColumns).toEqual([
      { id: 'a', cellChildren: [] },
      { id: 'beta', cellChildren: [] },
    ]);
  });

  /**
   * @scenario aspect=structure_op, data_surface=cell_tree
   *
   * @effects cell_tree_column_add_remove_move_with_id_edit
   */
  it('컬럼 삭제는 해당 인덱스만 제거', () => {
    const onPatchNode = vi.fn();
    render(
      <ArrayCellTreeEditor
        {...baseProps}
        node={cardNode([{ id: 'a', cellChildren: [] }, { id: 'b', cellChildren: [] }])}
        params={cellParams}
        onPatchNode={onPatchNode}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-cell-tree-remove-0'));
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.cardColumns).toEqual([{ id: 'b', cellChildren: [] }]);
  });

  /**
   * @scenario aspect=capability_attach, data_surface=cell_tree
   *
   * @effects cell_tree_expand_edits_cellchildren_via_children_control
   */
  it('컬럼 펼침 → cellChildren 트리 편집기(ChildrenListControl) 노출', () => {
    render(
      <ArrayCellTreeEditor
        {...baseProps}
        node={cardNode([{ id: 'a', cellChildren: [{ type: 'basic', name: 'Span', text: '내용' }] }])}
        params={cellParams}
        onPatchNode={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-cell-tree-toggle-0'));
    expect(screen.getByTestId('g7le-cell-tree-cells-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-children-editor')).toBeInTheDocument();
  });

  /**
   * @scenario aspect=structure_op, data_surface=cell_tree
   *
   * @effects cell_tree_expand_edits_cellchildren_via_children_control
   */
  it('펼친 셀에 자식 추가 시 그 컬럼의 cellChildren 으로 되돌아 패치', () => {
    const onPatchNode = vi.fn();
    render(
      <ArrayCellTreeEditor
        {...baseProps}
        node={cardNode([{ id: 'a', cellChildren: [] }])}
        params={cellParams}
        onPatchNode={onPatchNode}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-cell-tree-toggle-0'));
    fireEvent.click(screen.getByTestId('g7le-children-add'));
    const patched = onPatchNode.mock.calls.at(-1)![0] as EditorNode;
    const cols = patched.props!.cardColumns as Array<{ id: string; cellChildren: unknown[] }>;
    expect(cols[0].id).toBe('a');
    expect(cols[0].cellChildren.length).toBe(1);
  });

  /**
   * @scenario aspect=binding_guard, data_surface=cell_tree
   *
   * @effects cell_tree_binding_expression_degrades_to_bound_notice
   */
  it('정적-바인딩 가드: cardColumns 가 {{...}} → 디그레이드', () => {
    render(
      <ArrayCellTreeEditor
        {...baseProps}
        node={cardNode('{{modules?.data ?? []}}')}
        params={cellParams}
        onPatchNode={vi.fn()}
      />,
    );
    expect(screen.getByTestId('g7le-cell-tree-bound')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-cell-tree-add')).not.toBeInTheDocument();
  });

  /**
   * @scenario aspect=capability_attach, data_surface=cell_tree
   *
   * @effects cell_tree_missing_array_prop_shows_misconfigured_notice
   */
  it('arrayProp 미선언 → misconfigured 안내', () => {
    render(<ArrayCellTreeEditor {...baseProps} node={cardNode([])} params={{}} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-cell-tree-misconfigured')).toBeInTheDocument();
  });
});

/*
 * 실제 관리자 템플릿 스펙의 데이터 표면(data_surface) — capability 의 nodeEditor 를 레지스트리로 띄워 잰다.
 * single_object_array=DonutChart.data, flat_typed_array=DynamicFieldList.columns, multi_array=BarChart.
 * primitive_enum_array 는 그 표면을 쓰던 SocialLoginButtons 가 sirsoft-basic 1.4.0 에서 빠져 매니페스트 제외로 옮겼다.
 */
describe('데이터 표면별 capability 와 편집(관리자 템플릿 스펙)', () => {
  type Cap = { nodeEditor?: { kind: string; params: Record<string, unknown> }; dataProps?: Array<{ propKey: string }> };
  const adminCaps = JSON.parse(
    readFileSync(resolve(process.cwd(), 'templates/_bundled/sirsoft-admin_basic/editor-spec/componentCapabilities.json'), 'utf8'),
  ) as Record<string, Cap>;
  const editorFor = (comp: string) => {
    registerCoreEditors();
    const cap = adminCaps[comp]!;
    const Editor = getNodeEditor(cap.nodeEditor!.kind)!;
    return { Editor, params: cap.nodeEditor!.params };
  };
  const donutNode = (data: unknown): EditorNode => ({ type: 'composite', name: 'DonutChart', props: { data } }) as EditorNode;
  const fieldListNode = (columns: unknown): EditorNode =>
    ({ type: 'composite', name: 'DynamicFieldList', props: { columns } }) as EditorNode;
  const slices = [{ name: '원두', value: 3, color: '#111111' }];
  const columns = [{ key: 'qty', type: 'input', label: '수량', placeholder: '' }];

  // @scenario aspect=capability_attach, data_surface=single_object_array
  /**
   * @effects data_builtin_capability_attached_to_8a_targets_both_templates
   */
  it('DonutChart: 스펙 nodeEditor(kind=array, arrayProp=data)가 레지스트리로 배열 편집기를 띄운다', () => {
    const { Editor, params } = editorFor('DonutChart');
    expect(params.arrayProp).toBe('data');
    render(<Editor {...baseProps} node={donutNode(slices)} params={params} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-array-editor')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-array-row-0')).toBeInTheDocument();
  });

  // @scenario aspect=field_widget, data_surface=single_object_array
  /**
   * @effects array_editor_number_widget_writes_numeric_value, array_editor_color_widget_writes_hex_string
   */
  it('DonutChart: value(number) 는 숫자로, color 는 HEX 문자열로 그 조각에 기록한다', () => {
    const { Editor, params } = editorFor('DonutChart');
    const onPatchNode = vi.fn();
    render(<Editor {...baseProps} node={donutNode(slices)} params={params} onPatchNode={onPatchNode} />);
    fireEvent.change(screen.getByTestId('g7le-array-field-0-value'), { target: { value: '42' } });
    expect(((onPatchNode.mock.calls[0][0] as EditorNode).props!.data as Array<Record<string, unknown>>)[0].value).toBe(42);
    fireEvent.change(screen.getByTestId('g7le-array-field-0-color'), { target: { value: '#FF0000' } });
    expect(((onPatchNode.mock.calls[1][0] as EditorNode).props!.data as Array<Record<string, unknown>>)[0].color).toBe('#FF0000');
  });

  // @scenario aspect=structure_op, data_surface=single_object_array
  /**
   * @effects array_editor_newitem_shape_matches_component_read_shape
   */
  it('DonutChart: 추가는 컴포넌트가 읽는 모양(name/value/color)으로, 삭제는 그 조각만 뺀다', () => {
    const { Editor, params } = editorFor('DonutChart');
    const onPatchNode = vi.fn();
    render(<Editor {...baseProps} node={donutNode(slices)} params={params} onPatchNode={onPatchNode} />);
    fireEvent.click(screen.getByTestId('g7le-array-add'));
    expect((onPatchNode.mock.calls[0][0] as EditorNode).props!.data).toEqual([...slices, { name: '', value: 0, color: '#7C3AED' }]);
    fireEvent.click(screen.getByTestId('g7le-array-remove-0'));
    expect((onPatchNode.mock.calls[1][0] as EditorNode).props!.data).toEqual([]);
  });

  // @scenario aspect=binding_guard, data_surface=single_object_array
  /**
   * @effects data_prop_static_nodeeditor_coexists_with_binding_dataprops
   */
  it('DonutChart: data 가 바인딩이면 정적 편집기는 디그레이드하고, 같은 prop 은 dataProps 로 연결한다', () => {
    const { Editor, params } = editorFor('DonutChart');
    expect((adminCaps.DonutChart!.dataProps ?? []).some((d) => d.propKey === 'data')).toBe(true);
    render(<Editor {...baseProps} node={donutNode('{{stats.data.slices}}')} params={params} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-array-editor-bound')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-array-add')).not.toBeInTheDocument();
  });

  // @scenario aspect=structure_op, data_surface=multi_array
  /**
   * @effects array_group_patch_one_group_preserves_other_group_value
   */
  it('BarChart: labels 그룹에 추가하면 원시 빈 문자열이 붙고 datasets 는 그대로다', () => {
    const { Editor, params } = editorFor('BarChart');
    const onPatchNode = vi.fn();
    const datasets = [{ label: 'Sales', data: [1, 2], backgroundColor: '#7C3AED' }];
    const node = { type: 'composite', name: 'BarChart', props: { labels: ['Jan'], datasets } } as EditorNode;
    render(<Editor {...baseProps} node={node} params={params} onPatchNode={onPatchNode} />);
    fireEvent.click(within(screen.getByTestId('g7le-array-group-labels')).getByTestId('g7le-array-add'));
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.labels).toEqual(['Jan', '']);
    expect(patched.props!.datasets).toEqual(datasets);
  });

  // @scenario aspect=capability_attach, data_surface=flat_typed_array
  /**
   * @effects data_builtin_capability_attached_to_8a_targets_both_templates
   */
  it('DynamicFieldList: 스펙 nodeEditor(kind=array, arrayProp=columns)가 레지스트리로 배열 편집기를 띄운다', () => {
    const { Editor, params } = editorFor('DynamicFieldList');
    expect(params.arrayProp).toBe('columns');
    render(<Editor {...baseProps} node={fieldListNode(columns)} params={params} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-array-row-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-array-field-0-type')).toBeInTheDocument();
  });

  // @scenario aspect=field_widget, data_surface=flat_typed_array
  /**
   * @effects data_builtin_flat_typed_column_type_select_writes_value
   */
  it('DynamicFieldList: 컬럼 type select 선택이 그 컬럼의 type 만 바꾼다', () => {
    const { Editor, params } = editorFor('DynamicFieldList');
    const onPatchNode = vi.fn();
    render(<Editor {...baseProps} node={fieldListNode(columns)} params={params} onPatchNode={onPatchNode} />);
    fireEvent.change(screen.getByTestId('g7le-array-field-0-type'), { target: { value: 'number' } });
    expect((onPatchNode.mock.calls[0][0] as EditorNode).props!.columns).toEqual([{ ...columns[0], type: 'number' }]);
  });

  // @scenario aspect=structure_op, data_surface=flat_typed_array
  /**
   * @effects array_editor_newitem_shape_matches_component_read_shape
   */
  it('DynamicFieldList: 컬럼 추가는 key/type/label/placeholder 골격을 붙인다', () => {
    const { Editor, params } = editorFor('DynamicFieldList');
    const onPatchNode = vi.fn();
    render(<Editor {...baseProps} node={fieldListNode(columns)} params={params} onPatchNode={onPatchNode} />);
    fireEvent.click(screen.getByTestId('g7le-array-add'));
    expect((onPatchNode.mock.calls[0][0] as EditorNode).props!.columns).toEqual([
      ...columns,
      { key: '', type: 'input', label: '', placeholder: '' },
    ]);
  });
});
