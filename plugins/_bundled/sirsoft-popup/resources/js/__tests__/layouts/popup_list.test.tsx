/**
 * @file popup_list.test.tsx
 * @description 팝업 관리 목록의 화면 계약 — 머리 링크·abilities, 지금 노출 중 카드, 받는 중 빈 문구 숨김, 행 이름, 제목 링크,
 * 필터는 주소 쿼리, 필터 때 선택 비움, 일괄 줄, 복사·삭제 확인 창, 순서 변경 창. (매니페스트 admin-screens.yaml)
 *
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import list from '../../../layouts/admin/popup_list.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { actionsOf as nodeActions } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { actionsOf, collect, findById, pick, translationKeys } from './helpers';

const json = (node: unknown) => JSON.stringify(node);
const doc = list as any;

describe('팝업 관리 목록 화면', () => {
  // @scenario surface=list_header_links_and_abilities
  // @effects links_used_for_navigation, abilities_gate_controls
  it('[팝업 등록]·[표시 방식 설정]은 링크이고, 각각 등록 능력·설정 권한이 있을 때만 보인다', () => {
    const create = findById(list, 'create_button')!;
    expect(create.name).toBe('A');
    expect(create.props.href).toBe('/admin/plugins/sirsoft-popup/popups/create');
    expect(create.props.mergeQuery).toBe(true);
    expect(create.if).toContain('abilities?.can_create === true');
    const settings = findById(list, 'settings_link')!;
    expect(settings.name).toBe('A');
    expect(settings.props.href).toBe('/admin/plugins/sirsoft-popup/settings');
    expect(settings.if).toContain('can_update_settings === true');
  });

  // @scenario surface=list_summary_card_order_and_device_tags
  it('「지금 노출 중」은 요약 API 순서대로 번호와 기기 한정 표시(「모바일만」)를 단다', () => {
    const sources = doc.data_sources.map((s: any) => s.endpoint);
    expect(sources).toContain('/api/plugins/sirsoft-popup/admin/popups/summary');
    const items = json(findById(list, 'summary_items'));
    expect(items).toContain('summary?.data?.data');
    expect(items).toContain('idx + 1');
    expect(items).toContain("sirsoft-popup.admin.device.only_");
    expect(json(findById(list, 'summary_card'))).toContain('admin.summary.title|count=');
  });

  // @scenario surface=list_summary_card_settings_lines
  it('요약 카드 오른쪽에 PC·모바일·닫기 표시 방식 세 줄과 설정 링크가 있다', () => {
    const lines = json(findById(list, 'summary_settings'));
    for (const key of ['admin.summary.pc', 'admin.summary.mobile', 'admin.summary.close', 'admin.summary.change_settings']) {
      expect(lines).toContain(key);
    }
    expect(lines).toContain('/admin/plugins/sirsoft-popup/settings');
  });

  // @scenario surface=list_empty_message_hidden_while_loading
  it('표는 받는 중에는 빈 문구를 비워 두고, 받은 뒤 0건일 때만 「등록된 팝업이 없습니다」', () => {
    const grid = findById(list, 'popup_datagrid')!;
    expect(grid.props.emptyMessage).toBe("{{popups === undefined ? '' : $t('sirsoft-popup.admin.list.empty')}}");
    expect(json(findById(list, 'summary_card'))).toContain('summary !== undefined');
  });

  // @scenario surface=list_row_label_and_control_names
  // @effects accessible_names_present
  it('행 이름은 관리용 제목이고, 정렬 선택·필터에 이름이 있다', () => {
    const grid = findById(list, 'popup_datagrid')!;
    expect(grid.props.rowLabelField).toBe('title');
    expect(grid.props.selectable).toBe(true);
    expect(grid.props.selectionScope).toBe('page');
    const sort = collect(list, (n) => n.name === 'Select' && n.props?.name === 'sortBy')[0];
    expect(sort.props['aria-label']).toBe('$t:common.sort_order');
    for (const id of ['popup_filter_status', 'popup_filter_device', 'popup_filter_search']) {
      const labels = collect(list, (n) => n.name === 'Label' && n.props?.htmlFor === id);
      expect(labels, id).toHaveLength(1);
    }
  });

  // @scenario surface=list_title_links_to_edit
  // @effects links_used_for_navigation
  it('제목 셀과 행 메뉴 [수정]은 수정 화면 링크이고 보던 쿼리를 이고 간다', () => {
    const grid = findById(list, 'popup_datagrid')!;
    expect(json(grid)).toContain('"href":"/admin/plugins/sirsoft-popup/popups/{{row.id}}/edit"');
    const edit = (grid.props.rowActions as Array<Record<string, unknown>>).find((a) => a.id === 'edit')!;
    expect(edit.href).toBe('/admin/plugins/sirsoft-popup/popups/{{row.id}}/edit');
    expect(edit.mergeQuery).toBe(true);
    expect(grid.props.pageHref).toBe('/admin/plugins/sirsoft-popup/popups?page={page}');
  });

  // @scenario surface=list_filters_write_url_query
  it('상태·기기·검색은 화면에서 거르지 않고 주소 쿼리(1쪽)로 보내 서버가 거른다', () => {
    const named = json(doc.named_actions.searchPopups);
    expect(named).toContain('"page":1');
    for (const field of ['status', 'device', 'search']) {
      expect(named).toContain(`_local.filter.${field} || undefined`);
    }
    const popups = doc.data_sources.find((s: any) => s.id === 'popups');
    expect(popups.params.device).toBe('{{query.device}}');
    expect(popups.params.status).toBe('{{query.status}}');
  });

  // @scenario surface=list_selection_cleared_on_filter_change
  it('필터·정렬·쪽을 바꾸면 선택 묶음을 비운다', () => {
    expect(json(doc.named_actions.searchPopups)).toContain('"bulkSelectedIds":[]');
    const grid = findById(list, 'popup_datagrid')!;
    const pageChange = json(nodeActions(grid).find((a: any) => a.event === 'onPageChange'));
    expect(pageChange).toContain('"bulkSelectedIds":[]');
    const sort = collect(list, (n) => n.name === 'Select' && n.props?.name === 'sortBy')[0];
    expect(json(sort)).toContain('"bulkSelectedIds":[]');
  });

  // @scenario surface=list_bulk_bar_buttons_by_ability
  // @effects abilities_gate_controls
  it('일괄 줄은 선택이 있을 때만, 노출·비노출·복사는 수정 능력, 삭제는 삭제 능력으로 보인다', () => {
    expect(findById(list, 'bulk_action_bar')!.if).toContain('length &gt; 0'.replace('&gt;', '>'));
    for (const id of ['bulk_show_button', 'bulk_hide_button', 'bulk_copy_button']) {
      expect(findById(list, id)!.if, id).toContain('can_update === true');
    }
    expect(findById(list, 'bulk_delete_button')!.if).toContain('can_delete === true');
    const show = actionsOf(findById(list, 'bulk_show_button'), 'apiCall')[0];
    expect(show.target).toBe('/api/plugins/sirsoft-popup/admin/popups/bulk-status');
    expect(json(show)).toContain('"is_active":true');
  });

  // @scenario surface=list_copy_and_delete_confirm_dialogs
  it('복사·삭제는 확인 창을 거친다 — 복사 창은 비활성·맨 뒤 안내, 삭제 창은 되돌릴 수 없음 안내', () => {
    const modals = json(doc.modals);
    expect(modals).toContain('popup_bulk_copy_modal');
    expect(modals).toContain('admin.copy.message|count=');
    expect(modals).toContain('/api/plugins/sirsoft-popup/admin/popups/bulk-copy');
    expect(modals).toContain('popup_delete_modal');
    expect(modals).toContain('popup_bulk_delete_modal');
    expect(modals).toContain('admin.delete.warning');
    expect(pick(ko, 'admin.copy.message')).toContain('비활성');
    expect(json(actionsOf(findById(list, 'bulk_copy_button'), 'openModal'))).toContain('popup_bulk_copy_modal');
  });

  // @scenario surface=reorder_modal_loads_and_moves_items
  it('[순서 변경]은 전체 순서를 받아 창을 열고, 끌기·↑↓ 로 작업본을 바꾸며 저장은 전체 id 를 보낸다', () => {
    const button = findById(list, 'reorder_button')!;
    const load = actionsOf(button, 'apiCall')[0];
    expect(load.target).toBe('/api/plugins/sirsoft-popup/admin/popups/order');
    expect(json(button)).toContain('popup_reorder_modal');
    const modal = json(doc.modals);
    expect(modal).toContain('"handle":"[data-drag-handle]"');
    expect(modal).toContain('onSortEnd');
    expect(modal).toContain('/api/plugins/sirsoft-popup/admin/popups/order');
    expect(modal).toContain('admin.reorder.move_up');
  });

  // @scenario surface=reorder_modal_first_last_buttons_locked
  it('첫 행 ↑·끝 행 ↓ 는 잠긴다', () => {
    const modal = json(doc.modals);
    expect(modal).toContain('row._idx === 0');
    expect(modal).toContain('row._idx === (_global.reorderItems ?? []).length - 1');
  });

  // @scenario surface=reorder_save_locked_without_change
  it('바뀐 것이 없으면 [저장]이 잠긴다', () => {
    const save = collect(doc.modals, (n) => n.props?.['data-testid'] === 'popup-reorder-save')[0];
    expect(save.props.disabled).toContain('JSON.stringify((_global.reorderItems ?? []).map(i => i.id)) === JSON.stringify(_global.reorderOriginal ?? [])');
  });

  // @scenario surface=reorder_close_clears_state_before_closing
  it('순서 변경 창을 닫을 때(닫기·취소·저장 성공) 상태를 먼저 비우고 창을 닫는다', () => {
    const sequences = collect(doc.modals, (n) => n.handler === 'sequence' || Array.isArray(n.actions))
      .map((n) => (n.actions ?? []) as any[])
      .filter((acts) => acts.some((a) => a.handler === 'closeModal' && a.target === 'popup_reorder_modal'));
    const success = collect(doc.modals, (n) => Array.isArray(n.onSuccess))
      .map((n) => n.onSuccess as any[])
      .filter((acts) => acts.some((a) => a.handler === 'closeModal' && a.target === 'popup_reorder_modal'));

    expect(sequences.length + success.length).toBeGreaterThanOrEqual(3);
    for (const acts of [...sequences, ...success]) {
      const set = acts.findIndex((a) => a.handler === 'setState');
      const close = acts.findIndex((a) => a.handler === 'closeModal');
      expect(set, json(acts)).toBeGreaterThanOrEqual(0);
      expect(set, json(acts)).toBeLessThan(close);
    }
  });

  // @scenario surface=row_menu_toggles_active
  it('행 메뉴 「노출/비노출 전환」 은 그 행의 반대 상태를 일괄 상태 API 로 보낸다(수정 권한 없으면 잠금)', () => {
    const grid = findById(list, 'popup_datagrid')!;
    const item = (grid.props.rowActions as any[]).find((a) => a.id === 'toggle_active');
    expect(item.disabledField).toBe('abilities.can_update');
    expect(item.label).toBe('$t:sirsoft-popup.admin.list.action.toggle_active');

    const rowAction = (grid.actions as any[]).find((a) => a.event === 'onRowAction');
    const call = actionsOf(rowAction.cases.toggle_active, 'apiCall')[0];
    expect(call.target).toBe('/api/plugins/sirsoft-popup/admin/popups/bulk-status');
    expect(call.params.method).toBe('PATCH');
    expect(call.params.body).toEqual({ ids: '{{[$args[1].id]}}', is_active: '{{!$args[1].is_active}}' });
    expect(call.onSuccess.map((a: any) => a.handler)).toContain('toast');
    expect(call.onError.map((a: any) => a.handler)).toContain('toast');
  });

  /** @effects translation_keys_present */
  it('목록이 쓰는 번역 키는 ko·en 모두에 있다', () => {
    const dynamic = ['admin.device.only_', 'admin.status', 'admin.content_type', 'admin.device', 'settings.pc_position', 'settings.mobile_style'];
    for (const key of translationKeys(list).filter((k) => !dynamic.includes(k))) {
      expect(pick(ko, key), `ko ${key}`).toBeTruthy();
      expect(pick(en, key), `en ${key}`).toBeTruthy();
    }
  });
});
