/**
 * @file plugin_settings.test.tsx
 * @description 팝업 환경설정 화면 — 좌표 칸은 좌표일 때만, 자동 넘김을 끄면 간격 잠금, N일 칸은 N일 모드일 때만, 저장 실패 안내.
 * (매니페스트 admin-screens.yaml)
 *
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import settings from '../../../layouts/admin/plugin_settings.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { actionsOf, findById, pick, translationKeys } from './helpers';

const json = (node: unknown) => JSON.stringify(node);

describe('팝업 환경설정 화면', () => {
  // @scenario surface=settings_coords_inputs_only_for_coords
  it('왼쪽·위 좌표 칸은 「좌상단 기준 좌표」일 때만', () => {
    const coords = findById(settings, 'pc_coords')!;
    expect(coords.if).toContain("pc_position === 'coords'");
    expect(json(coords)).toContain('"name":"pc_left"');
    expect(json(coords)).toContain('"name":"pc_top"');
  });

  // @scenario surface=settings_interval_locked_without_autoplay
  it('자동 넘김을 끄면 넘김 간격을 잠그고, 간격은 4·6·8초만', () => {
    const select = findById(settings, 'autoplay_interval_select')!;
    expect(select.props.disabled).toContain('_local.form?.autoplay === false');
    expect(json(select)).toContain('"value":"4"');
    expect(json(select)).toContain('"value":"8"');
  });

  // @scenario surface=settings_days_input_only_for_days_mode
  it('N일 칸은 「N일간 보지 않기」일 때만, 안내는 D3(보이던 팝업만·새 팝업은 바로)', () => {
    expect(findById(settings, 'dismiss_days_field')!.if).toContain("dismiss_mode === 'days'");
    expect(pick(ko, 'settings.dismiss.hint')).toContain('새로 등록한 팝업은 바로');
  });

  // @scenario surface=settings_save_has_on_error
  it('저장은 코어 설정 API 로 보내고 실패하면 칸별 오류와 알림을 보인다', () => {
    const save = actionsOf(findById(settings, 'save_button'), 'apiCall')[0];
    expect(save.target).toBe('/api/admin/plugins/{{route.identifier}}/settings');
    expect(save.onError).toBeTruthy();
    expect(json(save.onError)).toContain('error.errors');
    expect(json(findById(settings, 'settings_preview'))).toContain('sirsoft-popup.PopupPreview');
  });

  // @scenario surface=settings_back_link_to_list
  it('머리 위 「팝업 관리로」 는 링크(href + navigate)이고, PageHeader 에는 없는 돌아가기 props 를 넘기지 않는다', () => {
    const link = findById(settings, 'back_to_list_link')!;
    expect(link.name).toBe('A');
    expect(link.props.href).toBe('/admin/plugins/sirsoft-popup/popups');
    expect(actionsOf(link, 'navigate')).toHaveLength(1);
    expect(json(link)).toContain('$t:sirsoft-popup.settings.back_to_list');

    expect(findById(settings, 'settings_breadcrumb')!.text).toBe('$t:sirsoft-popup.settings.breadcrumb');

    const header = findById(settings, 'page_header')!;
    expect(header.props.backPath).toBeUndefined();
    expect(header.props.backLabel).toBeUndefined();
  });

  // @scenario surface=settings_mobile_style_description_shown
  it('모바일 표시 방식 설명은 RadioGroup 선택지가 아니라 아래 안내 문단으로 보인다(선택지는 설명을 그리지 않는다)', () => {
    expect(json(findById(settings, 'mobile_card'))).not.toContain('"description"');
    const desc = findById(settings, 'mobile_style_desc')!;
    expect(desc.text).toContain("sirsoft-popup.settings.mobile_style.");
    expect(desc.text).toContain('_desc');
  });

  /** @effects translation_keys_present */
  it('설정 화면이 쓰는 번역 키는 ko·en 모두에 있다', () => {
    for (const key of translationKeys(settings)) {
      expect(pick(ko, key), `ko ${key}`).toBeTruthy();
      expect(pick(en, key), `en ${key}`).toBeTruthy();
    }
  });
  // @scenario surface=settings_preview_actual_size_modal
  it('표시 방식 미리보기도 「실제 크기로 보기」로 지금 입력한 설정을 배율 1 큰 창에 띄운다', () => {
    const button = findById(settings, 'preview_actual_button')!;
    expect(actionsOf(button, 'openModal')[0].target).toBe('popup_actual_preview_modal');
    const modal = ((settings as any).modals ?? []).find((m: any) => m.id === 'popup_actual_preview_modal');
    const preview = findById(modal, 'actual_preview')!;
    expect(preview.props.actualSize).toBe(true);
    expect(preview.props.device).toBe('{{$parent._local.previewDevice}}');
    // 입력 중인 값을 그대로 넘긴다 — 문자열 숫자·빈 칸은 미리보기 컴포넌트가 수·기본값으로 맞춘다
    expect(preview.props.settings).toBe('{{$parent._local.form}}');
  });
});
