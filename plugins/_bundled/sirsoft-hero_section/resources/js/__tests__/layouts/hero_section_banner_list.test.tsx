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
  // @scenario surface=list_empty_states_distinguished
  it('표 빈 문구는 받는 중엔 비우고, 조건으로 걸러 0건이면 「조건에 맞는 배너 없음」, 위치에 배너가 아예 없으면 첫 등록 안내 카드에 맡긴다', () => {
    const grid = findById(list, 'hero_banner_datagrid')!;
    const message = (banners: any, query: any) =>
      Function('banners', 'query', '_computed', '$t', `return (${grid.props.emptyMessage.slice(2, -2)})`)(banners, query, { currentPosition: query.position || 'home_hero' }, (key: string) => key);
    const stats = (n: number) => ({ data: { statistics: { positions: { home_hero: n, shop_hero: 0 } } } });
    expect(message(undefined, {})).toBe('');
    expect(message(stats(3), { status: 'scheduled' })).toBe('sirsoft-hero_section.admin.list.empty_filtered');
    expect(message(stats(3), { search: '가을' })).toBe('sirsoft-hero_section.admin.list.empty_filtered');
    expect(message(stats(0), {})).toBe('');
    // 위치에 배너가 없을 때만 첫 등록 카드 — 권장 크기와 등록 링크(등록 권한이 있을 때)
    const card = findById(list, 'empty_position_card')!;
    const cardShown = (banners: any) => Function('banners', '_computed', `return (${card.if.slice(2, -2)})`)(banners, { currentPosition: 'home_hero' });
    expect(cardShown(undefined)).toBe(false);
    expect(cardShown(stats(0))).toBe(true);
    expect(cardShown(stats(2))).toBe(false);
    expect(json(card)).toContain('admin.list.empty_position.sizes');
    const create = findById(card, 'empty_create_link')!;
    expect(create.name).toBe('A');
    expect(create.if).toBe('{{banners?.data?.abilities?.can_create === true}}');
    expect(create.props.href).toBe('/admin/plugins/sirsoft-hero_section/banners/create?position={{_computed.currentPosition}}');
  });

  // @scenario surface=list_row_readiness_flags
  it('제목 칸에 제작 상태를 보인다 — 모바일 이미지 없음(PC 이미지 사용), 버튼 문구만 있고 주소 없음, 제목이 빈 사이트 언어', () => {
    const grid = findById(list, 'hero_banner_datagrid')!;
    // 표 행마다 반복되는 배지는 id 가 없어야 한다(행마다 같은 HTML id 가 생긴다) — 문구 키로 찾는다
    const badge = (key: string) => collect(grid, (n) => typeof n.text === 'string' && n.text.includes(`admin.list.badge.${key}`))[0];
    const mobile = badge('mobile_fallback');
    expect(mobile.if).toBe('{{row.has_mobile_image === false}}');
    expect(mobile.text).toBe('$t:sirsoft-hero_section.admin.list.badge.mobile_fallback');
    const button = badge('button_without_link');
    expect(button.if).toBe('{{row.button_without_link === true}}');
    expect(button.text).toBe('$t:sirsoft-hero_section.admin.list.badge.button_without_link');
    // 빈 언어는 사이트 활성 언어 중 제목이 없는 것 — 화면에서 언어를 고정하지 않는다
    const missing = badge('locale_missing');
    for (const node of [mobile, button, missing]) expect(node.id).toBeUndefined();
    // 표 칸은 행 문맥 없이 먼저 번역되므로 반복 변수를 넘기는 번역은 지연 번역이어야 한다(아니면 언어 이름이 비어 보인다)
    expect(missing.text).toBe('$t:defer:sirsoft-hero_section.admin.list.badge.locale_missing|lang={{locale.toUpperCase()}}');
    expect((ko as any).admin.list.badge.locale_missing).toContain('{{lang}}');
    expect((en as any).admin.list.badge.locale_missing).toContain('{{lang}}');
    const source = missing.iteration.source;
    const evaluate = (row: any, locales: string[]) => Function('row', 'position_settings', `return (${source})`)(row, { data: { editor: { locales } } });
    expect(evaluate({ title_locales: ['ko'] }, ['ko', 'en', 'ja'])).toEqual(['en', 'ja']);
    expect(evaluate({ title_locales: ['ko', 'en'] }, ['ko', 'en'])).toEqual([]);
    expect(evaluate({}, [])).toEqual([]);
  });

  // @scenario surface=list_site_link_and_missing_slot
  it('미리보기 옆 「사이트에서 보기」는 그 위치 자리가 있는 사이트 주소를 새 창으로 열고, 자리가 없으면 안내만 보인다', () => {
    const link = findById(list, 'site_link')!;
    expect(link.name).toBe('A');
    expect(link.props.target).toBe('_blank');
    expect(link.props.rel).toBe('noopener noreferrer');
    expect(link.if).toBe('{{!!_computed.sitePath}}');
    expect(link.props.href).toBe('{{_computed.sitePath}}');
    const computed = (list as any).computed.sitePath.slice(2, -2);
    const path = (positions: any, position?: string) =>
      Function('position_settings', 'query', `return (${computed})`)({ data: { editor: { preview_frame: { positions } } } }, { position });
    expect(path({ home_hero: { path: '/' }, shop_hero: { path: '/shop/products' } })).toBe('/');
    expect(path({ home_hero: { path: '/' }, shop_hero: { path: '/shop/products' } }, 'shop_hero')).toBe('/shop/products');
    expect(path({ home_hero: null })).toBe('');
    const notice = findById(list, 'slot_missing_notice')!;
    const noticeShown = (settings: any) => Function('position_settings', '_computed', `return (${notice.if.slice(2, -2)})`)(settings, { currentPosition: 'home_hero' });
    expect(noticeShown(undefined)).toBe(false);
    expect(noticeShown({ data: { editor: { preview_frame: { positions: { home_hero: null } } } } })).toBe(true);
    expect(noticeShown({ data: { editor: { preview_frame: { positions: { home_hero: { layout: 'home', path: '/' } } } } } })).toBe(false);
    expect(notice.props.role).toBe('status');
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

  // @scenario surface=admin_header_overlay_setting
  // @effects position_setting_overlay_persisted
  it('메뉴 겹치기는 작업본에 담겨 저장 본문에 실리고, 바뀐 것이 없으면 저장이 잠기며, 본문 폭이면 「화면 전체 폭일 때만」 안내가 보인다', () => {
    const toggle = collect(list, (n) => n.props?.id === 'hero_slider_header_overlay')[0];
    expect(toggle.props.checked).toContain('.header_overlay === true');
    expect(json(actionsOf(toggle, 'setState'))).toContain('header_overlay: $event.target.checked');
    const button = findById(list, 'slider_save_button')!;
    expect(json(actionsOf(button, 'apiCall')[0])).toContain('"header_overlay":"{{_local.sliderForm.header_overlay === true}}"');
    expect(button.props.disabled).toContain('(_local.sliderForm.header_overlay === true) === (_computed.currentSettings.header_overlay === true)');
    const expr = findById(list, 'header_overlay_needs_full')!.if.slice(2, -2);
    const shown = (sliderForm: any) => Function('_local', '_computed', `return (${expr})`)({ sliderForm: { position: 'home_hero', ...sliderForm } }, { currentPosition: 'home_hero', currentSettings: {} });
    expect(shown({ header_overlay: true, width: 'content' })).toBe(true);
    expect(shown({ header_overlay: true })).toBe(true);
    expect(shown({ header_overlay: true, width: 'full' })).toBe(false);
    expect(shown({ header_overlay: false, width: 'content' })).toBe(false);
    for (const lang of [ko, en] as any[]) {
      for (const key of ['header_overlay', 'header_overlay_hint', 'header_overlay_needs_full']) expect(lang.admin.slider[key]).toBeTruthy();
    }
  });

  // @scenario surface=admin_width_setting
  // @effects preview_follows_width_before_save, width_saved_and_restored
  it('표시 폭(본문 폭·화면 전체)을 고르면 작업본에 담겨 미리보기가 저장 전에 따라가고, 저장 본문에 실리며 바뀐 것이 없으면 잠긴다', () => {
    // id 는 노드 id(data-ref)가 아니라 HTML 속성이다
    const select = collect(list, (n) => n.props?.id === 'hero_slider_width')[0];
    expect(select.name).toBe('Select');
    expect(select.props.options.map((o: { value: string }) => o.value)).toEqual(['content', 'full']);
    // 전환 효과 선택지는 서버 Enum(SlideEffect)과 같은 7개다
    const effect = collect(list, (n) => n.props?.id === 'hero_slider_effect')[0];
    expect(effect.props.options.map((o: { value: string }) => o.value)).toEqual(['slide', 'fade', 'cube', 'flip', 'coverflow', 'creative', 'none']);
    for (const lang of [ko, en] as any[]) {
      for (const key of ['cube', 'flip', 'coverflow', 'creative']) expect(lang.admin.slider.effects[key]).toBeTruthy();
      expect(lang.slider.go_to).toContain('{{n}}');
    }
    expect(select.props.value).toContain("width ?? 'content'");
    expect(json(actionsOf(select, 'setState'))).toContain('width: $event.target.value');
    // 미리보기는 같은 위치의 작업본을 먼저 본다
    expect(findById(list, 'hero_preview')!.props.settings).toBe('{{_local.sliderForm?.position === _computed.currentPosition ? _local.sliderForm : _computed.currentSettings}}');
    const button = findById(list, 'slider_save_button')!;
    expect(json(actionsOf(button, 'apiCall')[0])).toContain("\"width\":\"{{_local.sliderForm.width ?? 'content'}}\"");
    expect(button.props.disabled).toContain("(_computed.currentSettings.width ?? 'content')");
    for (const lang of [ko, en] as any[]) {
      expect(lang.admin.slider.width).toBeTruthy();
      expect(lang.admin.slider.widths.content).toBeTruthy();
      expect(lang.admin.slider.widths.full).toBeTruthy();
      expect(lang.admin.slider.width_hint).toBeTruthy();
    }
  });

  // @scenario surface=list_preview_empty_message
  // @scenario surface=list_empty_states_distinguished
  it('미리보기는 받는 중에는 빈 문구를 비우고, 노출 중인 배너가 0건이면 안내를 보인다 — 배너는 있는데 지금 노출이 없으면 예약·비노출·종료 건수를 함께', () => {
    const preview = findById(list, 'hero_preview')!;
    expect(preview.name).toBe('sirsoft-hero_section.HeroBannerPreview');
    const text = (previewData: any, banners: any) =>
      Function('preview', 'banners', '_computed', '$t', `return (${preview.props.emptyText.slice(2, -2)})`)(previewData, banners, { currentPosition: 'home_hero' }, (key: string, params?: any) => (params ? `${key}:${JSON.stringify(params)}` : key));
    const banners = (total: number, statuses: Record<string, number>) => ({ data: { statistics: { positions: { home_hero: total }, statuses } } });
    expect(text(undefined, banners(0, {}))).toBe('');
    expect(text({ data: { data: [] } }, banners(0, {}))).toBe('sirsoft-hero_section.admin.preview.empty');
    expect(text({ data: { data: [] } }, banners(3, { scheduled: 2, inactive: 1, ended: 0 }))).toBe(
      'sirsoft-hero_section.admin.preview.empty_with_counts:{"scheduled":2,"inactive":1,"ended":0}',
    );
    expect((ko as any).admin.preview.empty).toBeTruthy();
    expect((en as any).admin.preview.empty).toBeTruthy();
    expect((ko as any).admin.preview.empty_with_counts).toContain('{{scheduled}}');
    expect((en as any).admin.preview.empty_with_counts).toContain('{{scheduled}}');
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
