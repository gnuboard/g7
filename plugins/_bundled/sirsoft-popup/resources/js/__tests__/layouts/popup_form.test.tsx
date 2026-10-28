/**
 * @file popup_form.test.tsx
 * @description 팝업 등록·수정 화면 계약 — 한 번만 초기화·임시 키, 유형 전환, 언어 탭, 경로 칩, 에디터 확장점, 업로드 주소,
 * 권장 크기 안내, 기간 입력, 칸별 오류, 409 충돌 복구, 표시 방식대로 미리보기. (매니페스트 admin-screens.yaml)
 *
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import form from '../../../layouts/admin/popup_form.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { actionsOf, collect, findById, pick, translationKeys } from './helpers';

const json = (node: unknown) => JSON.stringify(node);
const doc = form as any;

describe('팝업 등록·수정 화면', () => {
  // @scenario surface=form_create_initializes_once_with_temp_key
  it('등록 진입은 폼을 기본값(이미지형·상시·전체 페이지·전체 대상·전체 기기)으로 되돌리고 새 임시 키를 만든다', () => {
    const init = json(doc.init_actions);
    expect(init).toContain('"if":"{{!route.id}}"');
    expect(init).toContain('"content_type":"image"');
    expect(init).toContain('"page_scope":"all"');
    expect(init).toContain('"audience":"all"');
    expect(init).toContain('tempKey');
    expect(init).toContain('$uuid()');
  });

  // @scenario surface=form_edit_initializes_from_show_once
  it('수정 진입은 상세를 한 번만 폼에 심는다(initLocal) — 재조회가 손댄 값을 덮지 않게', () => {
    const source = doc.data_sources.find((s: any) => s.id === 'popup');
    expect(source.initLocal).toBe('form');
    expect(source.if).toBe('{{!!route.id}}');
    expect(source.endpoint).toBe('/api/plugins/sirsoft-popup/admin/popups/{{route.id}}');
    expect(json(source)).not.toContain('onSuccess');
  });

  // @scenario surface=form_content_type_switch_shows_fields
  it('이미지형이면 이미지·alt·링크 칸, 직접 작성형이면 본문 칸만 보인다', () => {
    expect(doc.computed.isImage).toContain("(_local.form?.content_type ?? 'image') === 'image'");
    expect(findById(form, 'image_fields')!.if).toBe('{{_computed.isImage}}');
    expect(findById(form, 'html_fields')!.if).toBe('{{!_computed.isImage}}');
    const radios = collect(form, (n) => n.name === 'RadioGroup' && n.props?.name === 'content_type');
    expect(radios).toHaveLength(1);
  });

  // @scenario surface=form_language_tabs_mark_filled_locales
  it('alt 는 언어 탭 입력이고 본문은 다국어 편집기, 미리보기는 고른 언어로 그린다', () => {
    const alt = collect(form, (n) => n.name === 'MultilingualInput' && n.props?.name === 'image_alt')[0];
    expect(alt.props.layout).toBe('tabs');
    expect(json(findById(form, 'body_editor'))).toContain('"multilingual":true');
    expect(json(findById(form, 'form_preview'))).toContain('_local.previewLocale');
    expect(pick(ko, 'admin.form.locale_hint')).toContain('기본 언어');
  });

  // @scenario surface=form_route_chips_add_remove_and_hint
  it('경로는 칩으로 추가·삭제하고, 형식 오류는 서버 규칙과 같은 안내를 미리 보인다', () => {
    const editor = findById(form, 'routes_editor')!;
    expect(editor.if).toContain("page_scope === 'routes'");
    const add = findById(form, 'route_add_button')!;
    expect(add.props.disabled).toContain("_computed.routeInputError !== ''");
    expect(json(add)).toContain('form.routes');
    expect(json(findById(form, 'route_chips'))).toContain('admin.form.route_remove');
    expect(doc.computed.routeInputError).toContain("'must_start_with_slash'");
    expect(doc.computed.routeInputError).toContain("'duplicate'");
    expect(doc.computed.routeInputError).toContain("'too_many'");
    expect(pick(ko, 'admin.form.route_hint')).toContain('/shopping');
  });

  // @scenario surface=form_html_editor_extension_point_with_default
  it('직접 작성형 본문은 html_editor 확장점(에디터 플러그인 대체)과 기본 입력창 폴백', () => {
    const point = findById(form, 'body_editor')!;
    expect(point.type).toBe('extension_point');
    expect(point.name).toBe('html_editor');
    expect(json(point)).toContain('HtmlEditor');
    expect(json(point)).toContain('form.body_html');
  });

  // @scenario surface=form_upload_urls_use_temp_key
  it('PC·모바일 업로더는 임시 키로 올리고 삭제 주소를 비워 화면에서만 뺀다(저장 때만 반영)', () => {
    for (const id of ['pc_image_uploader', 'mobile_image_uploader']) {
      const uploader = findById(form, id)!;
      expect(uploader.props.apiEndpoints.upload).toBe('/api/plugins/sirsoft-popup/admin/popup-images');
      expect(uploader.props.apiEndpoints.delete).toBe('');
      expect(uploader.props.uploadParams).toContain('_global.tempKey');
      expect(uploader.props.accept).toContain('.gif');
    }
    const save = actionsOf(findById(form, 'footer_save_button'), 'apiCall')[0];
    expect(save.params.body).toContain('temp_key: _global.tempKey');
    expect(save.params.body).toContain('pc_image_id: _local.form?.images?.pc?.id');
  });

  // @scenario surface=form_recommended_size_warning
  it('PC 이미지가 환경설정 크기와 다르면 막지 않고 노란 안내를 보인다', () => {
    expect(doc.computed.pcSizeMismatch).toContain('pc_width ?? 480');
    const warning = findById(form, 'pc_size_warning')!;
    expect(warning.if).toBe('{{_computed.pcSizeMismatch}}');
    expect(json(warning)).toContain('size_mismatch');
  });

  // @scenario surface=form_period_inputs_only_for_range
  it('기간 지정일 때만 시작·종료를 보이고, 저장 후 상태를 미리 알린다', () => {
    expect(findById(form, 'period_range')!.if).toContain("display_period === 'range'");
    const hint = findById(form, 'period_hint')!;
    expect(hint.name).toBe('sirsoft-popup.PopupPeriodHint');
    expect(hint.props.timezone).toContain('editor?.timezone');
  });

  // @scenario surface=form_validation_errors_shown_per_field
  it('422 는 칸별 사유와 요약, 모든 실패는 알림', () => {
    const save = actionsOf(findById(form, 'footer_save_button'), 'apiCall')[0];
    expect(json(save.onError)).toContain('error.errors ?? null');
    expect(json(save.onError)).toContain('toast');
    expect(findById(form, 'validation_error_banner')!.if).toBe('{{_local.errors}}');
    expect(json(form)).toContain('_local.errors?.title');
    expect(json(form)).toContain('_local.errors?.link_url');
  });

  // @scenario surface=form_conflict_resolver_opens_on_409
  it('409 면 입력을 두고 충돌 안내를 열어 최신본과 비교·재적용한다', () => {
    const save = actionsOf(findById(form, 'footer_save_button'), 'apiCall')[0];
    expect(json(save.onError)).toContain('error.status === 409');
    expect(findById(form, 'conflict_panel')!.if).toBe('{{_local.conflict}}');
    const resolver = findById(form, 'conflict_resolver')!;
    expect(resolver.name).toBe('sirsoft-popup.PopupConflictResolver');
    expect(json(resolver)).toContain('conflictBase');
    expect(save.params.body).toContain('revision: route.id ? _local.form?.revision : undefined');
  });

  // @scenario surface=form_preview_follows_display_settings
  it('미리보기는 표시 방식 응답·기기 전환으로 사용자 레이어와 같은 컴포넌트를 그린다', () => {
    const preview = findById(form, 'form_preview')!;
    expect(preview.name).toBe('sirsoft-popup.PopupPreview');
    expect(preview.props.settings).toContain('display_settings?.data?.settings');
    expect(preview.props.device).toBe('{{_local.previewDevice}}');
    expect(findById(form, 'preview_card')!.props.className).toContain('order-first');
  });

  /** @effects translation_keys_present */
  it('폼이 쓰는 번역 키는 ko·en 모두에 있다', () => {
    const dynamic = ['admin.form.route_errors', 'settings.mobile_style', 'settings.pc_position'];
    for (const key of translationKeys(form).filter((k) => !dynamic.includes(k))) {
      expect(pick(ko, key), `ko ${key}`).toBeTruthy();
      expect(pick(en, key), `en ${key}`).toBeTruthy();
    }
  });
});
