/**
 * @file hero_section_banner_form.test.tsx
 * @description 배너 등록·수정 화면의 계약 — 등록은 쿼리 위치와 새 임시 키로 시작, 수정은 원본을 한 번만 심는다, 기간 입력은
 * 「기간 지정」일 때만, 문구는 사이트 언어 탭, 업로드는 등록·수정 모두 임시 키로 보내고 이미지는 저장 때 바뀐다, 업로드 한도는
 * 실제 한도(effective_mb), 권장 크기와 다르면 안내만, 422 는 칸마다·위쪽 요약, 미리보기는 관리자 템플릿에 있는 기본 컴포넌트로 그린다.
 * (매니페스트 admin-screens.yaml)
 *
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import form from '../../../layouts/admin/hero_section_banner_form.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { actionsOf, collect, findById, pick, translationKeys } from './helpers';

const json = (node: unknown) => JSON.stringify(node);
const layout = form as any;

/** 저장 단추의 API 호출. */
const saveCall = () => actionsOf(findById(form, 'footer_save_button'), 'apiCall')[0];

describe('배너 등록·수정 화면', () => {
  // @scenario surface=form_create_uses_query_position_and_temp_key
  it('등록 진입은 폼을 기본값으로 되돌리고 위치를 쿼리에서, 임시 키를 새로 만든다', () => {
    const init = asArray<any>(layout.init_actions);
    const reset = init.find((a) => a.if === '{{!route.id}}' && a.params?.form && typeof a.params.form === 'object');
    expect(reset.params.form.position).toBe("{{query.position === 'shop_hero' ? 'shop_hero' : 'home_hero'}}");
    expect(reset.params.form.images).toEqual({ pc: null, mobile: null });
    // 임시 키는 등록·수정 모두 들어올 때마다 새로 만든다
    const temp = init.find((a) => a.params?.target === 'global' && a.params?.tempKey === '{{$uuid()}}');
    expect(temp.if).toBeUndefined();
    expect(json(saveCall())).toContain('temp_key: _global.tempKey');
  });

  // @scenario surface=form_edit_initializes_once
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
  it('제목·설명·버튼 문구·대체 텍스트는 언어 탭 입력이고(사이트 활성 언어), 제목만 필수다', () => {
    const inputs = collect(form, (n) => n.name === 'MultilingualInput');
    expect(inputs.map((n) => n.props.name)).toEqual(['title', 'description', 'button_text', 'image_alt']);
    expect(inputs.every((n) => n.props.layout === 'tabs')).toBe(true);
    // 언어 목록을 화면에서 고정하지 않는다 — 컴포넌트가 사이트 활성 언어를 쓴다
    expect(inputs.every((n) => n.props.availableLocales === undefined)).toBe(true);
    expect(inputs.filter((n) => n.props.required === true).map((n) => n.props.name)).toEqual(['title']);
    expect(inputs.map((n) => n.props.maxLength)).toEqual([100, 300, 30, 200]);
  });

  // @scenario surface=form_images_apply_on_save
  it('실제 업로드 상태 식이 대기·압축·진행·실패를 막고 완료·제거 뒤 저장을 연다', () => {
    const disabled = findById(form, 'footer_save_button')!.props.disabled.slice(2, -2);
    const isDisabled = (state: any) => Function('_local', `return (${disabled})`)(state);
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

  describe('[case:components-misc-basic-component-missing-in-template] 템플릿에 없는 기본 컴포넌트', () => {
    // @scenario surface=form_preview_renders_admin_component
    it('미리보기 칸은 관리자 템플릿에 있는 기본 컴포넌트(Section)로 그린다 — 없는 이름(Aside)은 화면에서 조용히 빠진다', () => {
      const card = findById(form, 'preview_card')!;
      expect(card.name).toBe('Section');
      expect(collect(form, (n) => n.name === 'Aside')).toHaveLength(0);
      // 미리보기 슬라이더는 그 칸 안에 있다
      expect(collect(card, (n) => n.id === 'form_preview')).toHaveLength(1);
    });
  });

  // @scenario surface=form_upload_limit_uses_effective_mb
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
  it('편집 미리보기는 활성 사용자 템플릿 구좌 틀과 폼의 위치를 넘긴다', () => {
    const preview = findById(form, 'form_preview')!;
    expect(preview.props.frame).toBe('{{position_settings?.data?.editor?.preview_frame ?? null}}');
    expect(preview.props.position).toBe("{{_local.form?.position ?? 'home_hero'}}");
  });

  // @scenario surface=form_section_order_image_first
  it('입력 순서는 기본 정보 → 미디어 → 이미지 → 이미지 표시 → 문구 → 링크 → 기간이다 — 미디어 종류를 고르고 사진·영상을 먼저 올려 미리보기를 보며 문구를 쓴다', () => {
    const sections = asArray<any>(findById(form, 'form_sections')!.children).map((n) => n.id).filter((id) => String(id).endsWith('_card'));
    expect(sections).toEqual(['basic_card', 'media_card', 'image_card', 'presentation_card', 'text_card', 'link_card', 'period_card']);
  });

  // @scenario surface=form_preview_first_on_mobile
  it('좁은 화면에서는 미리보기가 입력 위에 오고, 넓은 화면에서는 오른쪽 열에 고정된다', () => {
    const preview = findById(form, 'preview_card')!;
    const classes = String(preview.props.className).split(/\s+/);
    expect(classes).toEqual(expect.arrayContaining(['order-first', 'lg:order-last', 'hs-editor-preview']));
  });

  // @scenario surface=form_save_blocked_reason
  // @effects accessible_names_present
  it('저장 단추가 잠기면 그 옆에 이유를 보인다 — 저장 중이 아닐 때 잠긴 경우와 이유가 늘 짝이다', () => {
    const disabledExpr = findById(form, 'footer_save_button')!.props.disabled.slice(2, -2);
    // 레이아웃 계산값(_computed)을 선언 순서대로 구해 식에 넘긴다
    const computedOf = (state: any) => Object.entries((form as any).computed as Record<string, string>).reduce((acc: Record<string, unknown>, [name, expr]) => {
      acc[name] = Function('_local', '_computed', 'position_settings', 'query', `return (${expr.slice(2, -2)})`)(state, acc, null, {});
      return acc;
    }, {});
    const evaluate = (expr: string, state: any) => Function('_local', '_computed', `return (${expr})`)(state, computedOf(state));
    const isDisabled = (state: any) => evaluate(disabledExpr, state);
    const reasonIds = ['save_reason_uploading', 'save_reason_pc_image', 'save_reason_pc_video', 'save_reason_youtube'];
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
      { form: { images: { pc: { id: 1 } }, media_type: 'youtube', youtube_url: '  ' } },
      { form: { images: { pc: { id: 1 } }, media_type: 'youtube', youtube_url: 'https://youtu.be/abcdefghijk' } },
      // 종류를 이미지로 돌리면 화면에 남은 영상·주소 유무는 저장을 막지 않는다
      { form: { images: { pc: { id: 1 } }, media_type: 'image', videos: { pc: null }, youtube_url: '' }, pcVideoUploadBlocked: true },
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
    expect(shown(states[8])).toEqual(['save_reason_youtube']);
    expect(isDisabled(states[9])).toBe(false);
    expect(isDisabled(states[10])).toBe(false);
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

  // @scenario surface=form_button_needs_link_warning
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
});
