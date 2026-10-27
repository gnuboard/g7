/**
 * @file hero_section_banner_list.test.tsx
 * @description 히어로섹션 관리 목록의 화면 계약 — 위치 탭 링크, 받는 중 빈 문구 숨김, 행 이름·컨트롤 이름, 수정은 링크,
 * abilities 로 단추 노출, 상태 필터·정렬은 주소 쿼리, 일괄 줄, 탭·필터를 바꾸면 선택 비움, 슬라이더 설정 저장·오류,
 * 미리보기 0건 문구, 순서 변경 창(불러오기·↑↓·끝 행 잠금·바뀐 것 없으면 저장 잠금). (매니페스트 admin-screens.yaml)
 *
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import list from '../../../layouts/admin/hero_section_banner_list.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { actionsOf, collect, findById, pick, strings, translationKeys } from './helpers';

const json = (node: unknown) => JSON.stringify(node);

describe('히어로섹션 목록 화면', () => {
  // @scenario surface=list_position_tabs_are_links
  // @effects navigation_uses_links
  it('위치 탭은 주소가 있는 링크이고 누르면 선택 묶음을 비운 뒤 그 주소로 간다', () => {
    for (const [id, position] of [['position_tab_home', 'home_hero'], ['position_tab_shop', 'shop_hero']]) {
      const tab = findById(list, id)!;
      expect(tab.name).toBe('A');
      expect(tab.props.href).toBe(`/admin/plugins/sirsoft-hero_section/banners?position=${position}`);
      expect(json(tab.props['aria-current'] ?? tab.props.ariaCurrent)).toContain("'page'");
      const sets = actionsOf(tab, 'setState');
      expect(json(sets)).toContain('"bulkSelectedIds":[]');
      expect(actionsOf(tab, 'navigate')).toHaveLength(1);
    }
  });

  // @scenario surface=list_empty_message_hidden_while_loading
  it('표는 목록을 받는 중에는 빈 문구를 비워 두고, 받은 뒤 0건일 때만 「등록된 배너가 없습니다」를 보인다', () => {
    const grid = findById(list, 'hero_banner_datagrid')!;
    expect(grid.props.emptyMessage).toBe("{{banners === undefined ? '' : $t('sirsoft-hero_section.admin.list.empty')}}");
  });

  // @scenario surface=list_row_label_and_control_names
  // @effects accessible_names_present
  it('표 행 이름은 제목이고, 선택칸·쪽 이동이 행 이름을 쓰는 표 계약을 따른다', () => {
    const grid = findById(list, 'hero_banner_datagrid')!;
    expect(grid.props.rowLabelField).toBe('title_localized');
    expect(grid.props.selectable).toBe(true);
    expect(grid.props.selectionScope).toBe('page');
    expect(grid.props.idField).toBe('id');
    // 위치 탭 묶음·미리보기 기기 전환에 이름이 있다
    expect(findById(list, 'position_tabs')!.props['aria-label']).toContain('admin.position.tabs_label');
    expect(json(findById(list, 'preview_card'))).toContain('admin.preview.device_label');
    // 정렬 선택에도 이름이 있다
    const sort = collect(list, (n) => n.name === 'Select' && n.props?.name === 'sortBy')[0];
    expect(sort.props['aria-label']).toBe('$t:common.sort_order');
  });

  // @scenario surface=list_row_actions_edit_is_link
  // @effects navigation_uses_links
  it('행 메뉴의 [수정]은 링크(href)이고 보던 쿼리를 이고 가며, 쪽 이동도 링크 주소를 쓴다', () => {
    const grid = findById(list, 'hero_banner_datagrid')!;
    const edit = (grid.props.rowActions as Array<Record<string, unknown>>).find((a) => a.id === 'edit')!;
    expect(edit.href).toBe('/admin/plugins/sirsoft-hero_section/banners/{{row.id}}/edit');
    expect(edit.mergeQuery).toBe(true);
    expect(grid.props.pageHref).toBe('/admin/plugins/sirsoft-hero_section/banners?page={page}');
    expect(grid.props.pageHrefMergeQuery).toBe(true);
    // [배너 등록]도 링크이고 보던 목록 조건을 이고 간다
    const create = findById(list, 'create_button')!;
    expect(create.name).toBe('A');
    expect(create.props.href).toContain('/admin/plugins/sirsoft-hero_section/banners/create?position=');
    expect(create.props.mergeQuery).toBe(true);
  });

  // @scenario surface=list_buttons_follow_abilities
  // @effects abilities_gate_actions
  it('등록·순서 변경·일괄 노출/비노출·일괄 삭제·설정 저장 단추는 응답 abilities 로 보인다', () => {
    expect(findById(list, 'create_button')!.if).toContain('abilities?.can_create === true');
    expect(findById(list, 'reorder_button')!.if).toContain('abilities?.can_update === true');
    expect(findById(list, 'bulk_show_button')!.if).toContain('can_update === true');
    expect(findById(list, 'bulk_hide_button')!.if).toContain('can_update === true');
    expect(findById(list, 'bulk_delete_button')!.if).toContain('can_delete === true');
    expect(findById(list, 'slider_save_button')!.if).toContain('can_update === true');
    const grid = findById(list, 'hero_banner_datagrid')!;
    const actions = grid.props.rowActions as Array<Record<string, unknown>>;
    expect(actions.find((a) => a.id === 'edit')!.disabledField).toBe('abilities.can_update');
    expect(json(actions)).toContain('abilities.can_delete');
  });

  // @scenario surface=list_status_filter_and_sort_go_to_query
  it('상태 필터·검색·정렬은 화면에서 거르지 않고 주소 쿼리를 바꿔 서버가 다시 거르게 한다', () => {
    const named = (list as any).named_actions.searchBanners;
    expect(json(named)).toContain('"page":1');
    expect(json(named)).toContain('_local.filter.status || undefined');
    expect(json(named)).toContain('_local.filter.search || undefined');
    const source = (list as any).data_sources.find((s: any) => s.id === 'banners');
    expect(source.params.status).toBe('{{query.status}}');
    expect(source.params.search).toBe('{{query.search}}');
    expect(source.params.sort_by).toContain('query.sort_by');
    const sort = collect(list, (n) => n.name === 'Select' && n.props?.name === 'sortBy')[0];
    const nav = actionsOf(sort, 'navigate')[0];
    expect(nav.params.mergeQuery).toBe(true);
    expect(json(nav.params.query)).toContain('sort_by');
  });

  // @scenario surface=list_bulk_bar_shows_selection_count
  it('일괄 줄은 선택이 있을 때만 보이고 「N개 선택됨」을 센다', () => {
    const bar = findById(list, 'bulk_action_bar')!;
    expect(bar.if).toContain('bulkSelectedIds');
    expect(json(bar)).toContain('admin.list.selected|count={{');
    // 노출·비노출은 선택 묶음을 보내고 끝나면 비운다
    for (const id of ['bulk_show_button', 'bulk_hide_button']) {
      const call = actionsOf(findById(list, id), 'apiCall')[0];
      expect(json(call)).toContain('_global.bulkSelectedIds');
      expect(json(call)).toContain('"bulkSelectedIds":[]');
    }
  });

  // @scenario surface=list_selection_cleared_on_tab_or_filter_change
  it('위치 탭·필터·정렬이 바뀌면 선택 묶음을 비운다(다른 위치 배너가 일괄 처리에 섞이지 않게)', () => {
    const named = (list as any).named_actions.searchBanners;
    expect(json(named)).toContain('"bulkSelectedIds":[]');
    const sort = collect(list, (n) => n.name === 'Select' && n.props?.name === 'sortBy')[0];
    expect(json(actionsOf(sort, 'setState'))).toContain('"bulkSelectedIds":[]');
    expect(json(asArray((list as any).init_actions))).toContain('"bulkSelectedIds":[]');
  });

  // @scenario surface=list_slider_settings_save_and_error
  it('슬라이더 설정 저장은 그 위치 주소로 PUT 하고, 성공·실패 양쪽에서 진행 깃발을 끈다', () => {
    const button = findById(list, 'slider_save_button')!;
    const call = actionsOf(button, 'apiCall')[0];
    expect(call.target ?? call.params?.target).toContain('/admin/position-settings/{{_computed.currentPosition}}');
    const flags = json(call).match(/"sliderSaving":false/g) ?? [];
    expect(flags.length).toBe(2);
    expect(json(call)).toContain('{{error.message}}');
    expect(json(call)).toContain('position_settings');
    // 바뀐 것이 없으면 잠긴다
    expect(button.props.disabled).toContain('_computed.currentSettings.effect');
  });

  // @scenario surface=list_preview_empty_message
  it('미리보기는 받는 중에는 빈 문구를 비우고, 노출 중인 배너가 0건이면 안내를 보인다', () => {
    const preview = findById(list, 'hero_preview')!;
    expect(preview.name).toBe('sirsoft-hero_section.HeroBannerPreview');
    expect(preview.props.emptyText).toBe("{{preview === undefined ? '' : $t('sirsoft-hero_section.admin.preview.empty')}}");
    expect((ko as any).admin.preview.empty).toBeTruthy();
    expect((en as any).admin.preview.empty).toBeTruthy();
  });

  // @scenario surface=list_preview_empty_message
  it('미리보기에 넘기는 배너는 다국어 문구(제목·설명·버튼·대체 텍스트)를 모두 글자로 바꾼다 — 객체를 그리면 화면이 깨진다', () => {
    const banners = findById(list, 'hero_preview')!.props.banners as string;
    expect(banners).toContain('title: b.title_localized');
    for (const field of ['description', 'button_text', 'image_alt']) {
      expect(banners).toContain(`${field}: $localized(b.${field})`);
    }
  });

  // @scenario surface=preview_frame_slot_inside_device_viewport
  it('목록 미리보기도 활성 사용자 템플릿 구좌 틀과 보고 있는 위치를 넘긴다', () => {
    const preview = findById(list, 'hero_preview')!;
    expect(preview.props.frame).toBe('{{position_settings?.data?.editor?.preview_frame ?? null}}');
    expect(preview.props.position).toBe('{{_computed.currentPosition}}');
  });

  // @scenario surface=reorder_modal_loads_position_items
  it('[순서 변경]은 그 위치 전체를 받아 작업본·원본으로 담고 창을 연다(받는 중 스피너)', () => {
    const button = findById(list, 'reorder_button')!;
    expect(button.if).toContain('> 1');
    const call = actionsOf(button, 'apiCall')[0];
    expect(call.target ?? call.params?.target).toContain('/admin/banners/order?position={{_computed.currentPosition}}');
    expect(json(call)).toContain('reorderItems');
    expect(json(call)).toContain('reorderOriginal');
    expect(json(actionsOf(call, 'openModal'))).toContain('hero_reorder_modal');
    expect(json(button)).toContain('_global.reorderLoading');
  });

  // @scenario surface=reorder_up_down_buttons_and_edges
  // @effects accessible_names_present
  it('순서 창의 ↑↓ 단추는 행 이름으로 불리고 첫 행 ↑·끝 행 ↓ 는 잠긴다', () => {
    const modal = findById(list, 'hero_reorder_modal')!;
    const text = strings(modal).join('\n');
    expect(text).toContain("admin.reorder.move_up', { name: row.title }");
    expect(text).toContain("admin.reorder.move_down', { name: row.title }");
    const buttons = collect(modal, (n) => n.name === 'Button' && typeof n.props?.disabled === 'string');
    const disabled = buttons.map((b) => b.props.disabled as string).join('\n');
    expect(disabled).toMatch(/=== 0/);
    expect(disabled).toMatch(/length - 1/);
  });

  // @scenario surface=reorder_save_disabled_when_unchanged
  it('순서 창 [저장]은 원본과 순서가 같으면 잠기고, 실패하면 창 안에 사유를 남긴다', () => {
    const modal = findById(list, 'hero_reorder_modal')!;
    const save = collect(modal, (n) => n.name === 'Button' && json(n).includes('/admin/banners/order') && json(n).includes('PUT'))[0];
    expect(save.props.disabled).toContain('reorderOriginal');
    expect(json(save)).toContain('reorderError');
    expect(findById(list, 'reorder_error_box')!.if).toContain('reorderError');
  });

  // @scenario surface=list_row_label_and_control_names
  // @effects accessible_names_present
  it('목록이 쓰는 문구가 한국어·영어 모두 있다', () => {
    for (const key of translationKeys(list)) {
      if (key === 'admin.position' || key === 'admin.status') continue;
      expect(pick(ko, key), `ko ${key}`).toBeTruthy();
      expect(pick(en, key), `en ${key}`).toBeTruthy();
    }
  });
});
