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
  // @effects summary_card_lists_live_popups_in_order
  it('「지금 노출 중」은 요약 API 순서대로 번호와 기기 한정 표시(「모바일만」)를 단다', () => {
    const sources = doc.data_sources.map((s: any) => s.endpoint);
    expect(sources).toContain('/api/plugins/sirsoft-popup/admin/popups/summary');
    const items = json(findById(list, 'summary_items'));
    expect(items).toContain('summary?.data?.data');
    expect(items).toContain('idx + 1');
    expect(items).toContain("sirsoft-popup.admin.device.only_");
    expect(json(findById(list, 'summary_card'))).toContain('admin.summary.title|count=');
  });

  // @scenario surface=list_summary_shows_first_items_and_expands
  // @effects summary_card_collapses_extra_items
  it('「지금 노출 중」은 처음 8개(휴대폰은 한 줄 3개)만 보이고 나머지는 「더 보기」로 펼치며, 펼침 상태를 단추에 알린다', () => {
    const li = findById(list, 'summary_items')!.children[0];
    const run = (expr: string, scope: Record<string, unknown>) => new Function(...Object.keys(scope), `return (${expr.replace(/^\{\{|\}\}$/g, '')});`)(...Object.values(scope));
    const summary = (count: number) => ({ data: { data: Array.from({ length: count }, (_, i) => ({ id: i + 1 })) } });
    const visible = (count: number, expanded: boolean) => run(li.iteration.source, { summary: summary(count), _local: { summaryExpanded: expanded } }).length;
    expect(visible(26, false)).toBe(8);
    expect(visible(26, true)).toBe(26);
    expect(visible(5, false)).toBe(5);
    // 휴대폰 폭(768 미만)에서는 4번째부터 접힌 동안 숨긴다 — 검색칸이 첫 화면에 오게. 768 이상은 엔진 responsive 로 다시 보인다
    const classOf = (value: string, idx: number, expanded: boolean) => String(run(value.replace(/^[^{]*\{\{|\}\}$/g, ''), { idx, _local: { summaryExpanded: expanded } }));
    const hidden = (idx: number, expanded: boolean) => classOf(li.props.className, idx, expanded);
    expect(li.props.className.startsWith('gpp:w-20 ')).toBe(true);
    expect(hidden(2, false)).toBe('');
    expect(hidden(3, false)).toBe('gpp:hidden');
    expect(hidden(3, true)).toBe('');
    const wide = li.responsive?.['768-']?.props?.className as string;
    expect(wide.startsWith('gpp:w-20 ')).toBe(true);
    expect(classOf(wide, 2, false)).toBe('');
    expect(classOf(wide, 3, false)).toBe('gpp:block');
    expect(classOf(wide, 3, true)).toBe('');

    const toggle = findById(list, 'summary_toggle')!;
    expect(toggle.name).toBe('Button');
    expect(toggle.props.type).toBe('button');
    // 단추를 담은 항목은 3개를 넘으면 그려지고, 768 이상(엔진 responsive)에서는 8개를 넘을 때만 보인다
    const holder = collect(findById(list, 'summary_items')!, (n) => (n.children ?? []).includes(toggle))[0];
    expect(holder.if).toBe('{{(summary?.data?.data ?? []).length > 3}}');
    expect(holder.props.className).toBe('gpp:self-center');
    const holderWide = holder.responsive?.['768-']?.props?.className as string;
    const holderClass = (count: number) => String(run(holderWide.replace(/^[^{]*\{\{|\}\}$/g, ''), { summary: summary(count) }));
    expect(holderClass(5)).toBe('gpp:hidden');
    expect(holderClass(9)).toBe('');
    expect(toggle.props['aria-expanded']).toBe('{{_local.summaryExpanded === true}}');
    expect(toggle.props['aria-controls']).toBe('popup_summary_items');
    expect(findById(list, 'summary_items')!.props.id).toBe('popup_summary_items');
    expect(json(actionsOf(toggle, 'setState'))).toContain('summaryExpanded');
    // 남은 개수는 폭마다 다르다 — 휴대폰은 전체-3, md 이상은 전체-8
    const labels = json(toggle);
    expect(labels).toContain('show_more|count={{(summary?.data?.data ?? []).length - 3}}');
    expect(labels).toContain('show_more|count={{(summary?.data?.data ?? []).length - 8}}');
    for (const key of ['admin.summary.show_more', 'admin.summary.show_less']) {
      expect(pick(ko, key), `ko ${key}`).toBeTruthy();
      expect(pick(en, key), `en ${key}`).toBeTruthy();
    }
    expect(pick(ko, 'admin.summary.show_more')).toContain('{{count}}');
  });

  // @scenario surface=list_summary_item_opens_edit
  // @effects links_used_for_navigation, abilities_gate_controls
  it('「지금 노출 중」 항목을 누르면 그 팝업 수정 화면으로 가고(보던 목록 조건 유지), 조회 전용이면 링크가 없다', () => {
    // 반복 안이라 고정 id 를 두지 않는다 — 요약 목록 안의 수정 링크로 찾는다
    const links = collect(findById(list, 'summary_items')!, (n) => n.props?.href === '/admin/plugins/sirsoft-popup/popups/{{item.id}}/edit');
    expect(links).toHaveLength(1);
    const link = links[0];
    expect(link.name).toBe('A');
    expect(link.props.href).toBe('/admin/plugins/sirsoft-popup/popups/{{item.id}}/edit');
    expect(link.if).toBe('{{item.abilities?.can_update !== false}}');
    expect(link.props['aria-label']).toBe('$t:sirsoft-popup.admin.summary.edit_item|n={{idx + 1}}|title={{item.title}}');
    expect(link.props.title).toBe('{{item.title}}');
    const nav = actionsOf(link, 'navigate')[0];
    expect(nav.params.path).toBe('/admin/plugins/sirsoft-popup/popups/{{item.id}}/edit');
    expect(nav.params.mergeQuery).toBe(true);
    // 수정 권한이 없으면 같은 썸네일·번호를 링크 없이 보인다
    const items = findById(list, 'summary_items')!;
    const plain = collect(items, (n) => n.if === '{{item.abilities?.can_update === false}}');
    expect(plain).toHaveLength(1);
    expect(json(plain[0])).not.toContain('navigate');
    expect(pick(ko, 'admin.summary.edit_item')).toContain('{{title}}');
    expect(pick(en, 'admin.summary.edit_item')).toContain('{{title}}');
  });

  // @scenario surface=list_summary_card_settings_lines
  // @effects summary_card_shows_display_settings
  it('요약 카드 오른쪽에 PC·모바일·닫기 표시 방식 세 줄과 설정 링크가 있다', () => {
    const lines = json(findById(list, 'summary_settings'));
    for (const key of ['admin.summary.pc', 'admin.summary.mobile', 'admin.summary.close', 'admin.summary.change_settings']) {
      expect(lines).toContain(key);
    }
    expect(lines).toContain('/admin/plugins/sirsoft-popup/settings');
  });

  // @scenario surface=list_empty_message_hidden_while_loading
  // @effects empty_message_waits_for_response
  it('표는 받는 중에는 빈 문구를 비워 두고, 받은 뒤 0건일 때만 「등록된 팝업이 없습니다」', () => {
    const grid = findById(list, 'popup_datagrid')!;
    expect(grid.props.emptyMessage).toBe("{{popups === undefined ? '' : $t('sirsoft-popup.admin.list.empty')}}");
    expect(json(findById(list, 'summary_card'))).toContain('summary !== undefined');
  });

  // @scenario surface=list_pagination_shown
  // @effects list_pages_through_server_pagination
  it('표는 페이지 이동 줄을 켠다 — DataGrid 의 pagination 기본값이 false 라 빠지면 20건 넘는 목록의 2페이지로 갈 수 없다', () => {
    const grid = findById(list, 'popup_datagrid')!;
    expect(grid.props.pagination).toBe(true);
    expect(grid.props.serverSidePagination).toBe(true);
    expect(grid.props.serverTotalPages).toBe('{{popups?.data?.pagination?.last_page ?? null}}');
    expect(grid.props.pageHref).toBe('/admin/plugins/sirsoft-popup/popups?page={page}');
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
  // @effects list_filters_sent_as_url_query
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
  // @effects bulk_selection_cleared_on_query_change
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
  // @effects destructive_actions_confirmed
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
  // @effects reorder_saves_full_order
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
  // @effects reorder_edge_buttons_locked
  it('첫 행 ↑·끝 행 ↓ 는 잠긴다', () => {
    const modal = json(doc.modals);
    expect(modal).toContain('row._idx === 0');
    expect(modal).toContain('row._idx === (_global.reorderItems ?? []).length - 1');
  });

  // @scenario surface=reorder_save_locked_without_change
  // @effects reorder_save_locked_until_changed
  it('바뀐 것이 없으면 [저장]이 잠긴다', () => {
    const save = collect(doc.modals, (n) => n.props?.['data-testid'] === 'popup-reorder-save')[0];
    expect(save.props.disabled).toContain('JSON.stringify((_global.reorderItems ?? []).map(i => i.id)) === JSON.stringify(_global.reorderOriginal ?? [])');
  });

  // @scenario surface=reorder_close_clears_state_before_closing
  // @effects reorder_state_cleared_before_close
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
  // @effects row_menu_toggles_active_via_bulk_status, abilities_gate_controls
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

describe('관리 화면 팝업 이미지의 썸네일 규격 (#869)', () => {
  // 팝업 이미지 서빙 경로는 썸네일 서빙 지점이다 — 노출 요약(64×80)·목록(48×56)·순서 변경 창(32×40)이 원본 대신 썸네일을 받는다.
  // 칸이 정사각에 가까운 세로형이라 정사각 자르기 sm(160) — 2배 화면에서도 칸 폭의 2배 이상이다.
  // @scenario surface=list_image_thumbnail_preset
  // @effects list_image_uses_sm_thumbnail
  it('노출 요약·목록·순서 변경 창의 팝업 <img> 는 thumbPreset="sm" 이고 면제 주석이 남지 않는다', () => {
    const images = collect(list, (n) => n.name === 'Img' && typeof (n.props as Record<string, unknown> | undefined)?.src === 'string'
      && /(row|item)\.thumbnail/.test(String((n.props as Record<string, unknown>).src)));
    expect(images.length).toBe(4);
    for (const image of images) {
      const props = image.props as Record<string, unknown>;
      expect(props.thumbPreset).toBe('sm');
      expect(props.loading).toBe('lazy');
      expect(props.decoding).toBe('async');
      expect(String(image.comment ?? '')).not.toContain('thumb-exempt');
    }
  });

  // @scenario surface=list_modal_footer_fixed
  // @effects modal_buttons_in_fixed_footer
  it('확인 창과 순서 변경 창의 바닥 단추는 Modal 직속 고정 하단(props.footer)에 두고 본문에는 바닥 줄을 남기지 않는다 — 본문이 길어도 단추가 함께 스크롤되지 않고 640px 이하에서 세로로 쌓인다', () => {
    for (const id of ['popup_delete_modal', 'popup_bulk_delete_modal', 'popup_bulk_copy_modal', 'popup_reorder_modal']) {
      const modal = findById(list, id)!;
      expect(modal.name, id).toBe('Modal');
      const footer = modal.props.footer as Array<Record<string, any>>;
      expect(Array.isArray(footer) && footer.length >= 2, `${id} 고정 하단 단추`).toBe(true);
      expect(footer.every((node) => node.name === 'Button'), `${id} 고정 하단은 단추만`).toBe(true);
      expect(collect(modal.children ?? [], (node) => String(node.props?.className ?? '').includes('g7pp-modal-footer-buttons')), `${id} 본문 바닥 줄`).toHaveLength(0);
    }
    // 저장 단추도 고정 하단에 있다(본문 상자 안이 아니다)
    const reorder = findById(list, 'popup_reorder_modal')!;
    expect((reorder.props.footer as Array<Record<string, any>>).some((node) => node.props?.['data-testid'] === 'popup-reorder-save')).toBe(true);
    expect(collect(reorder.children ?? [], (node) => node.props?.['data-testid'] === 'popup-reorder-save')).toHaveLength(0);
  });
});
