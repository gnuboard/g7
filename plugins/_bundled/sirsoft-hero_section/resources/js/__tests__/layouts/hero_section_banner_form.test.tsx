/**
 * @file hero_section_banner_form.test.tsx
 * @description 배너 등록·수정 화면의 계약 — 등록은 쿼리 위치와 새 임시 키로 시작, 수정은 원본을 한 번만 심는다, 기간 입력은
 * 「기간 지정」일 때만, 문구는 사이트 언어 탭, 업로드는 등록·수정 모두 임시 키로 보내고 이미지는 저장 때 바뀐다, 업로드 한도는
 * 실제 한도(effective_mb), 권장 크기와 다르면 안내만, 422 는 칸마다·위쪽 요약, 미리보기는 관리자 템플릿에 있는 기본 컴포넌트로 그린다.
 * (매니페스트 admin-screens.yaml)
 *
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import form from '../../../layouts/admin/hero_section_banner_form.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { actionsOf, collect, evaluateBinding, evaluateComputed, findById, pick, translationKeys } from './helpers';

const json = (node: unknown) => JSON.stringify(node);
const layout = form as any;

/** 저장 단추의 API 호출. */
const saveCall = () => actionsOf(findById(form, 'footer_save_button'), 'apiCall')[0];

describe('배너 등록·수정 화면', () => {
  // @scenario surface=form_create_uses_query_position_and_temp_key
  // @effects banner_form_initializes_from_entry
  it('등록 진입은 폼을 기본값으로 되돌리고 위치를 쿼리에서, 임시 키를 새로 만든다', () => {
    const init = asArray<any>(layout.init_actions);
    const reset = init.find((a) => a.if === '{{!route.id}}' && a.params?.form && typeof a.params.form === 'object');
    // 위치·대상은 목록에서 보던 탭·대상 그대로 — 위치 목록은 레지스트리라 화면이 위치를 고정하지 않는다(서버가 등록 위치인지 본다)
    expect(reset.params.form.position).toBe("{{query.position || 'home_hero'}}");
    expect(reset.params.form.target_key).toBe('{{query.target || null}}');
    expect(reset.params.form.images).toEqual({ pc: null, mobile: null });
    // 임시 키는 등록·수정 모두 들어올 때마다 새로 만든다
    const temp = init.find((a) => a.params?.target === 'global' && a.params?.tempKey === '{{$uuid()}}');
    expect(temp.if).toBeUndefined();
    expect(json(saveCall())).toContain('temp_key: _global.tempKey');
  });

  // @scenario surface=form_edit_initializes_once
  // @effects banner_form_initializes_from_entry
  it('수정 진입은 원본을 initLocal 로 한 번만 심고, 받기 성공 콜백에서 폼을 다시 쓰지 않는다', () => {
    const source = layout.data_sources.find((s: any) => s.id === 'banner');
    expect(source.if).toBe('{{!!route.id}}');
    expect(source.initLocal).toBe('form');
    expect(source.onSuccess).toBeUndefined();
    expect(json(source)).not.toContain('"setState"');
    // 폼 묶음은 변경 추적을 켜고, 이탈 가드는 그 표시를 본다
    const sections = findById(form, 'form_sections')!;
    // [case:hero-form-nested-debounce] 초점·배경처럼 같은 중첩 객체의 다른 필드를 연속 입력해도 옛 객체로 덮지 않는다.
    expect(sections.debounce).toBe(0);
    expect(sections.dataKey ?? sections.props?.dataKey ?? sections['data-key']).toBeDefined();
    expect(json(layout.meta)).toContain('_local.hasChanges === true');
    // 들어올 때마다 변경 표시를 끈다
    expect(json(asArray<any>(layout.init_actions)[0])).toContain('"hasChanges":false');
  });

  // @scenario surface=form_period_inputs_toggle
  // @effects banner_period_sent_only_when_scheduled
  it('시작·종료 입력은 「기간 지정」일 때만 보이고, 저장은 그 값을 starts_at·ends_at 으로 보낸다', () => {
    const range = findById(form, 'period_range')!;
    expect(range.if).toBe("{{_local.form?.display_period === 'range'}}");
    const inputs = collect(range, (n) => n.props?.type === 'datetime-local');
    expect(inputs.map((n) => n.props.name)).toEqual(['starts_at_local', 'ends_at_local']);
    expect(inputs.every((n) => typeof n.props['aria-label'] === 'string')).toBe(true);
    const body = json(saveCall());
    expect(body).toContain('starts_at: _local.form?.starts_at_local || null');
    expect(body).toContain('ends_at: _local.form?.ends_at_local || null');
  });

  // @scenario surface=form_multilingual_tabs_active_locales
  // @effects multilingual_fields_follow_active_locales
  it('머리글·제목·설명·버튼 문구·대체 텍스트는 언어 탭 입력이고(사이트 활성 언어), 제목만 필수다', () => {
    const inputs = collect(form, (n) => n.name === 'MultilingualInput');
    expect(inputs.map((n) => n.props.name)).toEqual(['eyebrow', 'title', 'description', 'button_text', 'image_alt']);
    expect(inputs.every((n) => n.props.layout === 'tabs')).toBe(true);
    // 언어 목록을 화면에서 고정하지 않는다 — 컴포넌트가 사이트 활성 언어를 쓴다
    expect(inputs.every((n) => n.props.availableLocales === undefined)).toBe(true);
    expect(inputs.filter((n) => n.props.required === true).map((n) => n.props.name)).toEqual(['title']);
    expect(inputs.map((n) => n.props.maxLength)).toEqual([50, 100, 300, 30, 200]);
  });

  // @scenario surface=form_images_apply_on_save
  // @effects banner_images_apply_on_save
  it('실제 업로드 상태 식이 대기·압축·진행·실패를 막고 완료·제거 뒤 저장을 연다', () => {
    const disabled = findById(form, 'footer_save_button')!.props.disabled.slice(2, -2);
    const isDisabled = (state: any) => Function('_local', '_computed', `return (${disabled})`)(state, {});
    for (const device of ['pc', 'mobile']) {
      const uploader = findById(form, `${device}_image_uploader`)!;
      expect(uploader.props.disabled).toBe('{{_local.isSaving}}');
      const action = actionsOf(uploader, 'setState').find((a) => a.params[`${device}UploadBlocked`] !== undefined)!;
      const expression = action.params[`${device}UploadBlocked`].slice(2, -2);
      const blocked = (files: any[]) => Function('$args', `return (${expression})`)([files]);
      for (const status of ['pending', 'compressing', 'uploading', 'error']) {
        const state = { form: { images: { pc: { id: 1 } } }, [`${device}UploadBlocked`]: blocked([{ status }]) };
        expect(isDisabled(state), `${device}:${status}`).toBe(true);
      }
      expect(blocked([])).toBe(false);
      expect(blocked([{ status: 'done' }])).toBe(false);
    }
    expect(isDisabled({ isSaving: true, form: { images: { pc: { id: 1 } } } })).toBe(true);
  });

  // @scenario surface=form_images_apply_on_save
  // @effects banner_images_apply_on_save
  it('업로드는 등록·수정 모두 임시 키로만 보내고, 빼기는 화면에서만 — 이미지는 저장 요청의 이미지 ID 로 바뀐다', () => {
    for (const [id, device] of [['pc_image_uploader', 'pc'], ['mobile_image_uploader', 'mobile']]) {
      const uploader = findById(form, id)!;
      expect(uploader.name).toBe('FileUploader');
      expect(uploader.props.apiEndpoints.upload).toBe('/api/plugins/sirsoft-hero_section/admin/banner-images');
      expect(uploader.props.uploadParams).toContain(`device: '${device}', temp_key: _global.tempKey`);
      expect(uploader.props.uploadParams).not.toContain('banner_id');
      expect(uploader.props.maxFiles).toBe(1);
      expect(json(uploader.actions)).toContain(`form.images.${device}`);
    }
    // 빼기는 등록·수정 모두 화면에서만 — 서버 삭제 주소가 없다(저장이 남은 이미지 ID 만 붙인다)
    expect(findById(form, 'pc_image_uploader')!.props.apiEndpoints.delete).toBe('');
    expect(findById(form, 'mobile_image_uploader')!.props.apiEndpoints.delete).toBe('');
    // 저장은 등록·수정 모두 화면에 남은 이미지 ID 를 보낸다(모바일을 뺐으면 null)
    const body = json(saveCall());
    expect(body).toContain('pc_image_id: _local.form?.images?.pc?.id');
    expect(body).toContain('mobile_image_id: _local.form?.images?.mobile?.id ?? null');
    // PC 이미지가 없으면 저장 단추가 잠긴다
    expect(findById(form, 'footer_save_button')!.props.disabled).toContain('!_local.form?.images?.pc');
  });

  // @scenario surface=form_save_double_click_sends_once
  // @effects banner_save_sent_once
  it('저장을 빠르게 두 번 눌러도 한 번만 보낸다 — 잠금(isSaving)이 화면에 반영되기 전 같은 틱의 두 번째 클릭은 진입점 debounce 가 합친다', () => {
    const click = asArray<any>(findById(form, 'footer_save_button')!.actions).find((a) => a.type === 'click');
    expect(click.handler).toBe('sequence');
    expect(click.debounce).toBe(300);
    // 잠금은 그 한 번의 실행 맨 앞에서 건다
    expect(click.actions[0]).toMatchObject({ handler: 'setState', params: { isSaving: true } });
  });

  describe('[case:components-misc-basic-component-missing-in-template] 템플릿에 없는 기본 컴포넌트', () => {
    // @scenario surface=form_preview_renders_admin_component
    // @effects banner_form_preview_renders
    it('미리보기 칸은 관리자 템플릿에 있는 기본 컴포넌트(Section)로 그린다 — 없는 이름(Aside)은 화면에서 조용히 빠진다', () => {
      const card = findById(form, 'preview_card')!;
      expect(card.name).toBe('Section');
      expect(collect(form, (n) => n.name === 'Aside')).toHaveLength(0);
      // 미리보기 슬라이더는 그 칸 안에 있다
      expect(collect(card, (n) => n.id === 'form_preview')).toHaveLength(1);
    });
  });

  // @scenario surface=form_link_example_path_exists
  // @effects link_example_points_to_real_page
  it('링크 칸 예시의 사이트 안 주소는 번들 사용자 템플릿(sirsoft-basic)에 실제로 있는 화면이다 — 예시대로 넣으면 404 로 가지 않는다', () => {
    const routes = JSON.parse(readFileSync(resolve(__dirname, '../../../../../../../templates/_bundled/sirsoft-basic/routes.json'), 'utf8'));
    // 쇼핑몰 경로는 이커머스 기본 경로(shop)로 펼친다
    const paths = new Set<string>();
    const walk = (node: unknown): void => {
      if (Array.isArray(node)) return node.forEach(walk);
      if (!node || typeof node !== 'object') return;
      const path = (node as Record<string, unknown>).path;
      if (typeof path === 'string') paths.add(path.replace(/^\/\{\{.*\}\}\//, '/shop/'));
      Object.values(node).forEach(walk);
    };
    walk(routes);
    for (const [locale, messages] of [['ko', ko], ['en', en]] as const) {
      const example = ((messages as any).admin.form.link_url_placeholder as string).split(' ')[0];
      expect(example.startsWith('/'), locale).toBe(true);
      expect(paths.has(example), `${locale} ${example}`).toBe(true);
    }
  });

  // @scenario surface=form_preview_language_names
  // @effects languages_named_like_tabs
  it('미리보기 언어 선택과 미리보기(기본 언어 대체 안내)에 언어 탭과 같은 언어 이름 표를 넘긴다', () => {
    const select = collect(form, (n) => n.name === 'sirsoft-hero_section.HeroPreviewLanguage');
    expect(select).toHaveLength(1);
    expect(select[0].props.localeNames).toBe('{{_global.appConfig?.localeNames ?? null}}');
    expect(findById(form, 'form_preview')!.props.localeNames).toBe('{{_global.appConfig?.localeNames ?? null}}');
  });

  // @scenario surface=form_upload_limit_uses_effective_mb
  // @effects upload_limit_matches_server
  it('업로더 최대 용량과 안내 문구는 설정·서버 한도 중 작은 값(effective_mb)을 쓴다', () => {
    const upload = layout.computed.uploadMb as string;
    expect(upload).toBe('{{position_settings?.data?.upload?.effective_mb ?? 5}}');
    for (const id of ['pc_image_uploader', 'mobile_image_uploader']) {
      expect(findById(form, id)!.props.maxSize).toBe('{{_computed.uploadMb}}');
    }
    expect(json(findById(form, 'field_pc_image'))).toContain('admin.form.image.pc_hint|mb={{_computed.uploadMb}}');
    const source = layout.data_sources.find((s: any) => s.id === 'position_settings');
    expect(source.endpoint).toBe('/api/plugins/sirsoft-hero_section/admin/position-settings');
  });

  // @scenario surface=form_recommended_size_warning
  // @effects size_warning_does_not_block_save
  it('올린 이미지가 권장 크기와 다르면 노란 안내만 보이고 저장은 막지 않는다', () => {
    expect(layout.computed.pcSizeMismatch).toContain('1920');
    expect(layout.computed.pcSizeMismatch).toContain('640');
    expect(layout.computed.mobileSizeMismatch).toContain('750');
    expect(layout.computed.mobileSizeMismatch).toContain('1000');
    expect(findById(form, 'pc_size_warning')!.if).toBe('{{_computed.pcSizeMismatch}}');
    expect(findById(form, 'mobile_size_warning')!.if).toBe('{{_computed.mobileSizeMismatch}}');
    expect(findById(form, 'footer_save_button')!.props.disabled).not.toContain('SizeMismatch');
  });

  // @scenario surface=form_validation_errors_render
  // @effects validation_errors_shown_and_save_flag_cleared
  it('422 는 칸 아래 사유와 위쪽 요약으로 보이고, 저장 진행 깃발은 성공·실패 양쪽에서 꺼진다', () => {
    const call = saveCall();
    const text = json(call);
    expect(text).toContain('{{error.errors ?? null}}');
    expect((text.match(/"isSaving":false/g) ?? []).length).toBe(2);
    expect(text).toContain('#hero_form_errors');
    expect(findById(form, 'validation_error_banner')!.if).toBe('{{_local.errors}}');
    for (const field of ['position', 'link_url', 'starts_at', 'ends_at', 'title_color', 'temp_key']) {
      expect(json(form)).toContain(`_local.errors?.${field}?.[0]`);
    }
    // 성공하면 이탈 가드를 넘어 같은 위치 목록으로 돌아간다
    const nav = actionsOf(call, 'navigate')[0];
    expect(nav.params.bypassLeaveGuard).toBe(true);
    expect(nav.params.mergeQuery).toBe(true);
    expect(nav.params.query.position).toBe('{{_computed.listPosition}}');
  });

  // @scenario surface=form_create_uses_query_position_and_temp_key
  // @effects navigation_uses_links
  it('[취소]는 목록 링크이고 보던 쿼리를 이고 간다', () => {
    const cancel = findById(form, 'footer_cancel_button')!;
    expect(cancel.name).toBe('A');
    expect(cancel.props.href).toContain('/admin/plugins/sirsoft-hero_section/banners?position=');
    expect(actionsOf(cancel, 'navigate')[0].params.mergeQuery).toBe(true);
  });

  // @scenario surface=form_images_apply_on_save
  // @effects banner_images_apply_on_save
  it('업로드 값은 화면 갈래(등록/수정)를 가리지 않는다', () => {
    for (const id of ['pc_image_uploader', 'mobile_image_uploader']) {
      expect(findById(form, id)!.props.uploadParams).not.toContain('route.id');
    }
  });

  // @scenario surface=conflict_load_latest_and_retry
  // @effects my_temp_images_preserved
  it('충돌 안내의 [최신본 불러와 비교]는 폼을 건드리지 않고 최신본을 따로 받고, 실패하면 다시 시도할 수 있다', () => {
    const panel = findById(form, 'conflict_panel')!;
    expect(panel.if).toBe('{{_local.conflict}}');
    const button = findById(form, 'conflict_load_button')!;
    const call = actionsOf(button, 'apiCall')[0];
    expect(call.target ?? call.params?.target).toBe('/api/plugins/sirsoft-hero_section/admin/banners/{{route.id}}');
    expect((call.params?.method ?? call.method)).toBe('GET');
    const text = json(call);
    expect(text).toContain('"conflictLatest":"{{response.data ?? null}}"');
    expect(text).toContain('"conflictLoadError":true');
    expect(text).not.toMatch(/"form"\s*:/);
    expect((text.match(/"conflictLoading":false/g) ?? []).length).toBe(2);
    expect(button.props.disabled).toBe('{{_local.conflictLoading}}');
    expect(json(button)).toContain('conflict_merge.retry');
    expect(findById(form, 'conflict_load_error')!.if).toBe('{{_local.conflictLoadError}}');
  });

  // @scenario surface=conflict_reapply_and_conflict_again
  // @effects others_changes_not_overwritten
  it('비교 컴포넌트는 기준(재적용 뒤에는 그때의 최신본)·내 입력·최신본을 받고, 적용하면 폼·기준을 바꾸고 충돌을 닫는다', () => {
    const resolver = findById(form, 'conflict_resolver')!;
    expect(resolver.name).toBe('sirsoft-hero_section.HeroConflictResolver');
    expect(resolver.props.base).toBe('{{_local.conflictBase ?? banner?.data ?? null}}');
    expect(resolver.props.mine).toBe('{{_local.form}}');
    expect(resolver.props.latest).toBe('{{_local.conflictLatest}}');
    const apply = json(resolver.actions);
    expect(apply).toContain('"form":"{{$args[0]}}"');
    // 다음 비교 기준은 방금 적용한 최신본 — 같은 set-state 가 conflictLatest 를 비우기 전 값으로 풀린다
    // ($args[1] 은 코어 BindingShape 방언 대장에 새 지점을 더하므로 쓰지 않는다)
    expect(apply).toContain('"conflictBase":"{{_local.conflictLatest}}"');
    expect(apply).not.toContain('$args[1]');
    expect(apply).toContain('"conflict":false');
    expect(apply).toContain('"conflictLatest":null');
    // 적용한 값은 아직 저장 전이라 이탈 방지(meta 의 _local.hasChanges)가 다시 켜진다
    expect(apply).toContain('"hasChanges":true');
    // 저장은 그대로 폼의 revision 을 보낸다 — 적용 뒤에는 최신 revision 이다
    expect(json(saveCall())).toContain('revision: route.id ? _local.form?.revision : undefined');
    expect(json(asArray<any>(layout.init_actions)[0])).toContain('"conflictLatest":null');
  });

  // @scenario surface=preview_frame_slot_inside_device_viewport
  // @effects preview_matches_site_slot
  it('편집 미리보기는 활성 사용자 템플릿 구좌 틀과 폼의 위치를 넘긴다', () => {
    const preview = findById(form, 'form_preview')!;
    expect(preview.props.frame).toBe('{{position_settings?.data?.editor?.preview_frame ?? null}}');
    expect(preview.props.position).toBe("{{_local.form?.position ?? 'home_hero'}}");
  });

  // @scenario surface=admin_max_height_setting
  it('편집 미리보기는 고른 위치의 표시 폭과 기기별 최대 높이를 받아 사이트와 같은 높이에서 문구 자리·넘침을 본다', () => {
    const preview = findById(form, 'form_preview')!;
    const positionDefs = [
      { key: 'home_hero', settings: { width: 'full', max_height_pc: 400, max_height_mobile: null } },
      { key: 'shop_hero', settings: { width: 'content' } },
    ];
    const settings = (position: string) => evaluateBinding(preview.props.settings, { _local: { form: { position } } }, { positionDefs });
    expect(settings('home_hero')).toMatchObject({ width: 'full', max_height_pc: 400, max_height_mobile: null });
    // 최대 높이를 받은 적 없는 위치(옛 응답)도 비운 것(null)으로 — 비율대로
    expect(settings('shop_hero')).toMatchObject({ width: 'content', max_height_pc: null, max_height_mobile: null });
  });

  // @scenario surface=form_section_order_image_first
  // @effects form_guides_operator_before_save
  it('입력 순서는 기본 정보 → 미디어 → 이미지 → 이미지 표시 → 문구 → 문구 위치·효과 → 링크 → 기간이다 — 미디어 종류를 고르고 사진·영상을 먼저 올려 미리보기를 보며 문구를 쓴다', () => {
    const sections = asArray<any>(findById(form, 'form_sections')!.children).map((n) => n.id).filter((id) => String(id).endsWith('_card'));
    expect(sections).toEqual(['basic_card', 'media_card', 'image_card', 'presentation_card', 'text_card', 'text_layout_card', 'link_card', 'period_card']);
  });

  // @scenario surface=form_preview_first_on_mobile
  // @effects form_guides_operator_before_save
  it('좁은 화면에서는 미리보기가 입력 위에 오고, 넓은 화면에서는 오른쪽 열에 고정된다', () => {
    const preview = findById(form, 'preview_card')!;
    // 폭 분기는 엔진 responsive 로 한다 — 기본(좁은 화면)은 맨 앞에 고정 없이, desktop 은 맨 뒤에 고정(sticky).
    // 폭 미디어로 고정을 풀던 의미 클래스(hs-editor-preview)는 쓰지 않는다.
    const classes = String(preview.props.className).split(/\s+/);
    expect(classes).toEqual(expect.arrayContaining(['hs:order-first']));
    expect(classes).not.toEqual(expect.arrayContaining(['hs:order-last']));
    expect(classes).not.toContain('hs:sticky');
    expect(classes).not.toContain('hs-editor-preview');
    const wide = String(preview.responsive?.desktop?.props?.className).split(/\s+/);
    expect(wide).toEqual(expect.arrayContaining(['hs:order-last', 'hs:sticky', 'hs:top-[84px]']));
    expect(wide).not.toContain('hs:order-first');
    expect(wide).not.toContain('hs-editor-preview');
  });

  // @scenario surface=form_save_blocked_reason
  // @effects accessible_names_present
  it('저장 단추가 잠기면 그 옆에 이유를 보인다 — 저장 중이 아닐 때 잠긴 경우와 이유가 늘 짝이다', () => {
    const disabledExpr = findById(form, 'footer_save_button')!.props.disabled.slice(2, -2);
    // 레이아웃 계산값(_computed)을 선언 순서대로 구해 식에 넘긴다
    const settings = { data: { positions: [
      { key: 'home_hero', label_key: 'sirsoft-hero_section.admin.position.home_hero', label: null, registered: true, dynamic: false, target: null },
      { key: 'board_hero', label_key: 'sirsoft-hero_section.admin.position.board_hero', label: null, registered: true, dynamic: true, target: { type: 'board', label_key: 'sirsoft-hero_section.admin.target.board', label: null } },
    ] } };
    const computedOf = (state: any) => evaluateComputed(form as any, { _local: { ...state, form: { position: 'home_hero', ...state.form } }, query: {}, position_settings: settings, banner: undefined, form_target_info: undefined });
    const evaluate = (expr: string, state: any) => Function('_local', '_computed', `return (${expr})`)(state, computedOf(state));
    const isDisabled = (state: any) => evaluate(disabledExpr, state);
    const reasonIds = ['save_reason_settings', 'save_reason_uploading', 'save_reason_target', 'save_reason_pc_image', 'save_reason_pc_video'];
    const shown = (state: any) => reasonIds.filter((id) => evaluate(findById(form, id)!.if.slice(2, -2), state));
    const states = [
      { form: { images: { pc: null } } },
      { form: { images: { pc: { id: 1 } } }, pcUploadBlocked: true },
      { form: { images: { pc: null } }, mobileUploadBlocked: true },
      { form: { images: { pc: { id: 1 } } } },
      { form: { images: { pc: { id: 1 } } }, isSaving: true },
      { form: { images: { pc: { id: 1 } }, media_type: 'video', videos: { pc: null } } },
      { form: { images: { pc: { id: 1 } }, media_type: 'video', videos: { pc: null } }, pcVideoUploadBlocked: true },
      { form: { images: { pc: { id: 1 } }, media_type: 'video', videos: { pc: { id: 2 } } } },
      // 종류를 이미지로 돌리면 화면에 남은 영상 유무는 저장을 막지 않는다
      { form: { images: { pc: { id: 1 } }, media_type: 'image', videos: { pc: null } }, pcVideoUploadBlocked: true },
      // 더는 받지 않는 종류(옛 유튜브)는 이미지로 본다 — 저장을 막지 않는다
      { form: { images: { pc: { id: 1 } }, media_type: 'youtube' } },
      // 동적 위치는 대상을 골라야 저장된다 — 대상 이유가 PC 이미지·영상 이유보다 먼저, 올리는 중보다는 뒤다
      { form: { position: 'board_hero', images: { pc: null } } },
      { form: { position: 'board_hero', images: { pc: { id: 1 } } }, pcUploadBlocked: true },
      { form: { position: 'board_hero', target_key: '2', images: { pc: { id: 1 } } } },
    ];
    for (const state of states) {
      const reasons = shown(state);
      // 저장 중에는 단추 글자가 「저장 중」이라 이유를 따로 보이지 않는다
      expect(reasons.length > 0, JSON.stringify(state)).toBe(isDisabled(state) && !state.isSaving);
      expect(reasons.length).toBeLessThanOrEqual(1);
    }
    expect(shown(states[0])).toEqual(['save_reason_pc_image']);
    expect(shown(states[2])).toEqual(['save_reason_uploading']);
    expect(shown(states[5])).toEqual(['save_reason_pc_video']);
    expect(shown(states[6])).toEqual(['save_reason_uploading']);
    expect(isDisabled(states[7])).toBe(false);
    expect(isDisabled(states[8])).toBe(false);
    expect(isDisabled(states[9])).toBe(false);
    expect(shown(states[10])).toEqual(['save_reason_target']);
    expect(shown(states[11])).toEqual(['save_reason_uploading']);
    expect(isDisabled(states[12])).toBe(false);
    // 이유는 저장 단추의 설명으로 읽히고, PC 이미지 칸으로 가는 단추가 함께 있다
    const save = findById(form, 'footer_save_button')!;
    expect(save.props['aria-describedby']).toBe('hero_save_reason');
    const reasonBox = findById(form, 'save_reason')!;
    expect(reasonBox.props.id).toBe('hero_save_reason');
    expect(reasonBox.props.role).toBe('status');
    const jump = actionsOf(findById(form, 'save_reason_pc_image'), 'scrollIntoView')[0];
    expect(jump.params.selector).toBe('#hero_field_pc_image');
    expect(findById(form, 'field_pc_image')!.props.id).toBe('hero_field_pc_image');
    // 이유 문구는 한 곳에만 — 예전의 폼 아래 「올리는 중」 안내는 없다
    expect(collect(form, (n) => json(n.text ?? '').includes('admin.form.upload_pending'))).toHaveLength(1);
  });

  // @scenario surface=form_text_layout_card
  // @effects editor_position_persisted, eyebrow_persisted
  it('문구 위치·효과 — 9칸 고르기와 미리보기 끌기는 지금 기기 키를 표현값에 합치고 「고친 것 있음」을 켠다. 기기는 미리보기와 같은 값이다', () => {
    const pad = findById(form, 'text_position_pad')!;
    expect(pad.name).toBe('sirsoft-hero_section.HeroTextPositionPad');
    expect(pad.props.presentation).toBe('{{_local.form?.presentation}}');
    expect(pad.props.device).toBe('{{_local.previewDevice}}');
    const merge = 'form.presentation":"{{({...(_local.form?.presentation ?? {}), ...$args[0]})}}"';
    expect(json(pad.actions)).toContain(merge);
    expect(json(pad.actions)).toContain('"hasChanges":true');
    expect(json(pad.actions)).toContain('"previewDevice":"{{$args[0]}}"');
    const preview = findById(form, 'form_preview')!;
    const move = asArray<any>(preview.actions).find((a) => a.event === 'onTextPositionChange');
    expect(json(move)).toContain(merge);
    expect(json(move)).toContain('"hasChanges":true');
    // 효과·속도·지연·바탕은 이름 바인딩 — 선택지는 서버 Enum 과 같다
    const animation = collect(form, (n) => n.props?.id === 'hero_text_animation')[0];
    expect(animation.props.name).toBe('presentation.animation');
    expect(json(animation)).toMatch(/none.*rise.*fade.*left.*right.*zoom.*blur.*letters/);
    expect(collect(form, (n) => n.props?.id === 'hero_text_animation_speed')[0].props.name).toBe('presentation.animation_speed');
    expect(collect(form, (n) => n.props?.id === 'hero_text_animation_delay')[0].props.name).toBe('presentation.animation_delay');
    // 입력 범위는 서버가 내려준 저장 규칙 값(위치 설정 응답 editor.*_limits)
    expect(collect(form, (n) => n.props?.id === 'hero_text_width')[0].props).toMatchObject({ name: 'presentation.text_width', min: '{{position_settings?.data?.editor?.text_width_limits?.min ?? 30}}', max: '{{position_settings?.data?.editor?.text_width_limits?.max ?? 100}}' });
    expect(collect(form, (n) => n.props?.id === 'hero_text_animation_delay')[0].props.max).toBe('{{position_settings?.data?.editor?.animation_delay_limits?.max ?? 2}}');
    expect(collect(form, (n) => n.props?.name === 'presentation.tone')).toHaveLength(1);
  });

  // @scenario surface=form_text_layout_card
  // @effects eyebrow_persisted
  it('등록 기본 표현값은 서버 기본값과 같고(왼쪽 아래·전폭·효과 없음·어두움), 저장은 머리글·보이기·색을 보낸다', () => {
    const init = json(asArray<any>(layout.init_actions));
    for (const pair of ['"text_pc_x":0', '"text_pc_y":100', '"text_mobile_x":0', '"text_mobile_y":100', '"text_width":100', '"animation":"none"', '"animation_speed":"normal"', '"animation_delay":0', '"tone":"dark"', '"eyebrow_visible":true', '"eyebrow_color":"#FFFFFF"']) {
      expect(init, pair).toContain(pair);
    }
    const body = json(saveCall());
    expect(body).toContain('eyebrow: _local.form?.eyebrow ?? []');
    expect(body).toContain("eyebrow_visible: _local.form?.eyebrow_visible !== false");
    expect(body).toContain("eyebrow_color: _local.form?.eyebrow_color ?? '#FFFFFF'");
    expect(body).toContain('presentation: _local.form?.presentation ?? null');
  });

  // @scenario surface=form_button_needs_link_warning
  // @effects form_guides_operator_before_save
  it('버튼 문구가 어느 언어든 있는데 이동 주소가 비면 「주소를 넣어야 버튼이 보입니다」를 보인다', () => {
    const expr = findById(form, 'button_link_warning')!.if.slice(2, -2);
    const warn = (formState: any) => Function('_local', `return (${expr})`)({ form: formState });
    expect(warn({ button_text: { ko: '보러 가기' }, link_url: '' })).toBe(true);
    expect(warn({ button_text: { en: 'Shop' }, link_url: '   ' })).toBe(true);
    expect(warn({ button_text: { ko: '보러 가기' }, link_url: '/shop' })).toBe(false);
    expect(warn({ button_text: [], link_url: '' })).toBe(false);
    expect(warn({ button_text: { ko: '  ' } })).toBe(false);
    expect(warn({})).toBe(false);
  });

  // @scenario surface=form_link_behavior_summary
  // @effects form_guides_operator_before_save
  it('링크 칸 아래에 배너를 누르면 어떻게 되는지 한 줄로 요약한다(버튼만 / 배너 전체 / 이동 안 함)', () => {
    const ids = ['link_behavior_button', 'link_behavior_whole', 'link_behavior_none'];
    const shown = (formState: any) => ids.filter((id) => Function('_local', `return (${findById(form, id)!.if.slice(2, -2)})`)({ form: formState }));
    expect(shown({ button_text: { ko: '보기' }, link_url: '/shop' })).toEqual(['link_behavior_button']);
    expect(shown({ button_text: { ko: '' }, link_url: '/shop' })).toEqual(['link_behavior_whole']);
    expect(shown({ button_text: { ko: '보기' }, link_url: '' })).toEqual(['link_behavior_none']);
    expect(shown({})).toEqual(['link_behavior_none']);
  });

  // @scenario surface=form_multilingual_tabs_active_locales
  // @effects accessible_names_present
  it('폼이 쓰는 문구가 한국어·영어 모두 있다', () => {
    for (const key of translationKeys(form)) {
      if (key === 'admin.status') continue;
      expect(pick(ko, key), `ko ${key}`).toBeTruthy();
      expect(pick(en, key), `en ${key}`).toBeTruthy();
    }
  });

  // @scenario case=admin_form_position_target
  it('위치는 레지스트리 목록에서 고르고, 동적 위치면 대상을 고른다 — 위치를 바꾸면 대상을 비우고, 저장 본문·목록 복귀가 대상을 싣는다', () => {
    const radio = findById(form, 'position_radio')!;
    expect(radio.props.autoBinding).toBe(false);
    expect(radio.props.options).toContain('(_computed.formPositions ?? [])');
    const change = actionsOf(radio, 'setState')[0];
    expect(json(change)).toContain('"form.target_key":null');
    expect(json(change)).toContain('"form.position":"{{$event.target.value}}"');
    const field = findById(form, 'field_target')!;
    expect(field.if).toBe('{{_computed.formDynamic}}');
    const dropdown = findById(form, 'form_target_dropdown')!;
    expect(actionsOf(dropdown, 'apiCall')[0].target).toBe('/api/plugins/sirsoft-hero_section/admin/positions/{{_local.form?.position}}/targets');
    expect(json(actionsOf(dropdown, 'setState'))).toContain('form.target_key');
    expect(json(saveCall())).toContain('target_key: _computed.formDynamic ? (_local.form?.target_key ?? null) : null');

    const settings = { data: { positions: [
      { key: 'home_hero', label_key: 'k.home', label: null, registered: true, dynamic: false, target: null },
      { key: 'board_hero', label_key: 'k.board', label: null, registered: true, dynamic: true, target: { type: 'board', label_key: 'k.target.board', label: null } },
      { key: 'gone_hero', label_key: null, label: 'gone_hero', registered: false, dynamic: false, target: null },
    ] } };
    const c = (formState: any, extra: any = {}) => evaluateComputed(form as any, { _local: { form: formState }, query: {}, position_settings: settings, banner: undefined, form_target_info: undefined, ...extra });
    // 등록되지 않은 위치는 그 배너가 거기 있을 때만 고를 수 있게 남는다
    expect(c({ position: 'home_hero' }).formPositions.map((p: any) => p.key)).toEqual(['home_hero', 'board_hero']);
    expect(c({ position: 'gone_hero' }).formPositions.map((p: any) => p.key)).toEqual(['home_hero', 'board_hero', 'gone_hero']);
    expect(c({ position: 'board_hero' }).targetUnpicked).toBe(true);
    expect(c({ position: 'board_hero', target_key: '2' }).listTarget).toBe('2');
    expect(c({ position: 'home_hero', target_key: '2' }).listTarget).toBeUndefined();
    // 수정하는 배너의 대상이 지워졌으면(「없는 대상」) 그대로 저장은 되지만 알린다
    const missing = c({ position: 'board_hero', target_key: '9' }, { banner: { data: { position: 'board_hero', target_key: '9', target_label: null, target_exists: false } } });
    expect(missing.formTargetMissing).toBe(true);
    expect(missing.targetUnpicked).toBe(false);
    // 목록 복귀는 폼의 위치·대상으로, 자리가 바뀌었으면 쪽 번호를 버린다
    const back = actionsOf(findById(form, 'footer_cancel_button'), 'navigate')[0];
    expect(json(back)).toContain('"target":"{{_computed.listTarget}}"');
    expect(json(back)).toContain('_computed.samePlacement ? query.page : undefined');
  });

  // @scenario case=admin_form_position_target
  it('위치 설정을 받기 전에는 저장을 막고 이유를 보인다 — 동적 위치 배너가 대상 없이(target_key null) 나가 422 가 나지 않게, 받지 못하면 다시 받기를 준다', () => {
    const disabledExpr = findById(form, 'footer_save_button')!.props.disabled.slice(2, -2);
    const ready = { data: { positions: [{ key: 'board_hero', label_key: 'k.board', label: null, registered: true, dynamic: true, target: { type: 'board', label_key: 'k.target.board', label: null } }] } };
    const state = { form: { position: 'board_hero', target_key: 'event-a', images: { pc: { id: 1 } } } };
    const computedOf = (settings: any, global: any = {}) => evaluateComputed(form as any, { _local: state, _global: global, query: {}, position_settings: settings, banner: undefined, form_target_info: undefined });
    const evaluate = (expr: string, computed: any) => Function('_local', '_computed', `return (${expr})`)(state, computed);

    // 받기 전: 동적 판정을 못 하므로 저장하지 않는다
    const before = computedOf(undefined);
    expect(before.formReady).toBe(false);
    expect(evaluate(disabledExpr, before)).toBe(true);
    expect(evaluate(findById(form, 'save_reason_settings')!.if.slice(2, -2), before)).toBe(true);
    for (const id of ['save_reason_uploading', 'save_reason_target', 'save_reason_pc_image', 'save_reason_pc_video']) {
      expect(evaluate(findById(form, id)!.if.slice(2, -2), before), id).toBe(false);
    }
    expect(evaluate(findById(form, 'save_reason_settings_loading')!.if.slice(2, -2), before)).toBe(true);
    expect(evaluate(findById(form, 'save_reason_settings_failed')!.if.slice(2, -2), before)).toBe(false);
    // 받지 못함: 실패를 알리고 다시 받기
    const failed = computedOf(undefined, { heroPositionSettingsFailed: true });
    expect(evaluate(findById(form, 'save_reason_settings_failed')!.if.slice(2, -2), failed)).toBe(true);
    expect(evaluate(findById(form, 'save_reason_settings_loading')!.if.slice(2, -2), failed)).toBe(false);
    expect(actionsOf(findById(form, 'save_reason_settings_retry'), 'refetchDataSource')[0].params.dataSourceId).toBe('position_settings');
    // 받은 뒤: 대상과 함께 저장된다
    const after = computedOf(ready);
    expect(evaluate(disabledExpr, after)).toBe(false);
    expect(evaluate(findById(form, 'save_reason_settings')!.if.slice(2, -2), after)).toBe(false);

    const source = layout.data_sources.find((d: any) => d.id === 'position_settings');
    expect(actionsOf(source.errorHandling, 'setState')[0].params).toEqual({ target: 'global', heroPositionSettingsFailed: true });
    expect(actionsOf(source.onSuccess, 'setState')[0].params).toEqual({ target: 'global', heroPositionSettingsFailed: false });
  });

  // @scenario case=admin_form_position_target
  it('대상 찾기 후보는 찾은 위치를 달고, 고르기 칸은 폼의 지금 위치 후보만 보인다 — 위치를 바꾼 뒤 늦게 온 앞 위치 응답이 섞이지 않게', () => {
    const dropdown = findById(form, 'form_target_dropdown')!;
    const success = actionsOf(dropdown, 'setState').find((a: any) => typeof a.params?.formTargetResults === 'string');
    const found = evaluateBinding(success.params.formTargetResults, { response: { data: { position: 'board_hero', items: [{ key: '2', label: '자유게시판', hint: 'free' }] } } });
    expect(found).toEqual([{ value: '2', label: '자유게시판', description: 'free', position: 'board_hero' }]);
    // 검색어는 서버 한도(100자)로 잘라 보낸다 — 넘치면 422 로 「결과 없음」 대신 빈 실패가 된다
    const keyword = actionsOf(dropdown, 'apiCall')[0].params.query.keyword;
    expect(evaluateBinding(keyword, { $args: ['  ' + '가'.repeat(101)] })).toHaveLength(100);

    const settings = { data: { positions: [
      { key: 'board_hero', label_key: 'k.board', label: null, registered: true, dynamic: true, target: { type: 'board', label_key: 'k.target.board', label: null } },
      { key: 'product_hero', label_key: 'k.product', label: null, registered: true, dynamic: true, target: { type: 'product', label_key: 'k.target.product', label: null } },
    ] } };
    const c = (position: string) => evaluateComputed(form as any, { _local: { form: { position }, formTargetResults: found }, query: {}, position_settings: settings, banner: undefined, form_target_info: undefined });
    expect(c('board_hero').formTargetOptions.map((o: any) => o.value)).toEqual(['2']);
    expect(c('product_hero').formTargetOptions).toEqual([]);
  });
});

// @scenario case=admin_form_position_target
describe('[case:components-misc-hero-position-settings-invalid-response] 수정 폼은 위치 정보를 확인한 뒤 저장한다', () => {
  const invalid = [
    ['null 응답', null],
    ['data null', { data: null }],
    ['빈 위치 배열', { data: { positions: [] } }],
    ['위치 배열 형식 오류', { data: { positions: {} } }],
  ] as const;
  // @scenario case=admin_form_position_target
  it.each(invalid)('%s이면 대상 키를 잃는 저장을 막고 다시 받기를 보인다', (_name, settings) => {
    const scope = { _local: { form: { position: 'board_hero', target_key: '9', images: { pc: { id: 1 } } } }, position_settings: settings, banner: undefined, form_target_info: undefined };
    const computed = evaluateComputed(form as any, scope);
    expect(computed.formReady).toBe(false);
    expect(computed.settingsFailed).toBe(true);
    expect(evaluateBinding(findById(form, 'footer_save_button')!.props.disabled, scope, computed)).toBe(true);
    expect(evaluateBinding(findById(form, 'save_reason_settings_failed')!.if, scope, computed)).toBe(true);
    expect(evaluateBinding(findById(form, 'save_reason_settings_loading')!.if, scope, computed)).toBe(false);
    expect(actionsOf(findById(form, 'save_reason_settings_retry'), 'refetchDataSource')[0].params.dataSourceId).toBe('position_settings');
  });
});

// @scenario case=admin_form_position_target
describe('[case:components-misc-hero-position-settings-invalid-response] 고른 위치가 위치 목록에 없으면 다시 받기 대신 위치를 다시 고르게 한다', () => {
  const defs = { data: { positions: [{ key: 'home_hero', registered: true, dynamic: false }] } };
  // @scenario case=admin_form_position_target
  it('없는 위치로 연 등록 화면은 저장을 잠그고 「없는 위치」 이유를 보이며, 다른 위치를 고르면 풀린다', () => {
    const scope = (position: string) => ({ _local: { form: { position, images: { pc: { id: 1 } } } }, position_settings: defs, banner: undefined, form_target_info: undefined, _global: {} });
    const missing = scope('gone_hero');
    const computed = evaluateComputed(form as any, missing);
    const visible = (id: string) => evaluateBinding(findById(form, id)!.if, missing, computed);
    expect(computed.formMissing).toBe(true);
    expect(computed.settingsFailed).toBe(false);
    expect(evaluateBinding(findById(form, 'footer_save_button')!.props.disabled, missing, computed)).toBe(true);
    expect(visible('save_reason_settings_missing')).toBe(true);
    expect(visible('save_reason_settings_failed')).toBe(false);
    expect(visible('save_reason_settings_loading')).toBe(false);
    expect(visible('save_reason_settings_retry')).toBe(false);
    expect(computed.formPositions.map((p: any) => p.key)).toEqual(['home_hero']);
    const picked = scope('home_hero');
    const after = evaluateComputed(form as any, picked);
    expect(after.formMissing).toBe(false);
    expect(evaluateBinding(findById(form, 'footer_save_button')!.props.disabled, picked, after)).toBe(false);
  });

  // @scenario case=admin_form_position_target
  it('요청이 실패해 앞 응답이 남은 경우는 받지 못함이다 — 다시 받기를 보인다', () => {
    const scope = { _local: { form: { position: 'board_hero', target_key: '9' } }, position_settings: defs, banner: undefined, form_target_info: undefined, _global: { heroPositionSettingsFailed: true } };
    const computed = evaluateComputed(form as any, scope);
    expect(computed.formMissing).toBe(false);
    expect(computed.settingsFailed).toBe(true);
  });
});
