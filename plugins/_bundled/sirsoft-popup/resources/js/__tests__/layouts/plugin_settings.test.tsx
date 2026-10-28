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

  /** @effects translation_keys_present */
  it('설정 화면이 쓰는 번역 키는 ko·en 모두에 있다', () => {
    for (const key of translationKeys(settings)) {
      expect(pick(ko, key), `ko ${key}`).toBeTruthy();
      expect(pick(en, key), `en ${key}`).toBeTruthy();
    }
  });
});
