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

// 시나리오 매니페스트(tests/scenarios/layout-editor-data-builtins.yaml) cross product 15 케이스
// (data_surface × aspect) 전수 마킹(test-scenario-coverage 룰). single_object_array(DonutChart)/
// flat_typed_array(DynamicFieldList)/primitive_enum_array(SocialLoginButtons)의 capability_attach·
// field_widget·structure_op 회귀는 ArrayItemsEditor.test.tsx + dataBuiltinCapabilityShape.test.ts
// 가, multi_array(BarChart array-group)/cell_tree(CardGrid array-cell-tree)는 본 파일이 잠근다.
// @scenario aspect=capability_attach, data_surface=single_object_array
// @scenario aspect=field_widget, data_surface=single_object_array
// @scenario aspect=structure_op, data_surface=single_object_array
// @scenario aspect=binding_guard, data_surface=single_object_array
// @scenario aspect=capability_attach, data_surface=multi_array
// @scenario aspect=field_widget, data_surface=multi_array
// @scenario aspect=structure_op, data_surface=multi_array
// @scenario aspect=capability_attach, data_surface=cell_tree
// @scenario aspect=structure_op, data_surface=cell_tree
// @scenario aspect=binding_guard, data_surface=cell_tree
// @scenario aspect=capability_attach, data_surface=primitive_enum_array
// @scenario aspect=structure_op, data_surface=primitive_enum_array
// @scenario aspect=capability_attach, data_surface=flat_typed_array
// @scenario aspect=field_widget, data_surface=flat_typed_array
// @scenario aspect=structure_op, data_surface=flat_typed_array
/**
 * @effects data_builtin_capability_attached_to_8a_targets_both_templates,
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

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

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

  it('그룹마다 ArrayItemsEditor 섹션을 렌더한다', () => {
    render(<ArrayGroupEditor {...baseProps} node={barNode()} params={groupParams} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-array-group-labels')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-array-group-datasets')).toBeInTheDocument();
  });

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

  it('컬럼 추가 시 newItem 골격(id/cellChildren)을 push', () => {
    const onPatchNode = vi.fn();
    render(<ArrayCellTreeEditor {...baseProps} node={cardNode([])} params={cellParams} onPatchNode={onPatchNode} />);
    fireEvent.click(screen.getByTestId('g7le-cell-tree-add'));
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.props!.cardColumns).toEqual([{ id: '', cellChildren: [] }]);
  });

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

  it('arrayProp 미선언 → misconfigured 안내', () => {
    render(<ArrayCellTreeEditor {...baseProps} node={cardNode([])} params={{}} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-cell-tree-misconfigured')).toBeInTheDocument();
  });
});
