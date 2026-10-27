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
