/**
 * @file popup_layer_extension.test.tsx
 * @description 사용자 화면 주입 조각 — user_global_overlay 에 붙고, 받은 팝업이 있을 때만 레이어를 그리며, 현재 경로를 넘긴다.
 * (매니페스트 admin-screens.yaml — overlay 케이스)
 *
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import fragment from '../../../extensions/popup_layer.json';
import ko from '../../../lang/ko.json';
import { pick } from './helpers';

const doc = fragment as any;

describe('사용자 화면 주입 조각', () => {
  // @scenario surface=overlay_fragment_targets_user_global_overlay
  it('user_global_overlay 에 GDPR 배너(50) 뒤로 붙고, 공개 API 는 선택 인증으로 받는다', () => {
    expect(doc.extension_point).toBe('user_global_overlay');
    expect(doc.mode).toBe('append');
    expect(doc.priority).toBe(60);
    const source = doc.data_sources[0];
    expect(source.id).toBe('sirsoftPopup_public');
    expect(source.endpoint).toBe('/api/plugins/sirsoft-popup/popups');
    expect(source.auth_mode).toBe('optional');
    expect(pick(ko, 'editor.data_source.sirsoftPopup_public')).toBeTruthy();
  });

  // @scenario surface=overlay_fragment_hidden_when_empty
  it('받은 팝업이 0건이거나 받기에 실패하면 레이어를 그리지 않는다', () => {
    const layer = doc.components[0];
    expect(layer.name).toBe('sirsoft-popup.PopupLayer');
    expect(layer.if).toBeUndefined(); // 0건에도 예약·탭 복귀 구독 유지
    expect(layer.props.refreshAt).toBe('{{sirsoftPopup_public?.data?.refresh_at ?? null}}');
    expect(layer.props.serverTime).toBe('{{sirsoftPopup_public?.data?.server_time ?? null}}');
    expect(doc.data_sources[0].fallback).toEqual({ data: null });
  });

  // @scenario surface=overlay_fragment_passes_route_path
  it('현재 경로를 레이어에 넘기고 요청 매개변수에는 넣지 않는다(서버 캐시는 대상·언어 단위)', () => {
    expect(doc.components[0].props.path).toBe('{{route.path}}');
    expect(doc.data_sources[0].params).toBeUndefined();
  });
});
