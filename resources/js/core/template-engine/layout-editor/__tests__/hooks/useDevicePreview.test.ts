/**
 * useDevicePreview 회귀 테스트
 *
 * 회귀 원인: 초기 구현에서 `const { device } = state` 로 destructure 하여
 * 항상 undefined → 디바이스 토글 클릭이 dispatch 됨에도 폭/active 표시 미반영.
 * 본 테스트는 reducer 의 SET_PREVIEW_DEVICE 가 상태로 진입한 뒤 hook 이
 * 그 값을 정확히 노출하고, 폭 매핑이 디바이스 프리셋 명세 와
 * 일치함을 가드한다.
  *
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): preview_custom_width_clamped_320_to_1920, preview_non_custom_ignores_custom_width
*/

import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import React from 'react';
import { useDevicePreview, DEVICE_PRESET_WIDTHS } from '../../hooks/useDevicePreview';
import { LayoutEditorProvider } from '../../LayoutEditorContext';

function wrap(): React.FC<{ children: React.ReactNode }> {
  return ({ children }) =>
    // children 은 필수 prop — 세 번째 인자 대신 props 로 넘긴다(타입 일치, 실행 동작 동일).
    React.createElement(LayoutEditorProvider, { templateIdentifier: 'sirsoft-basic', initialLocale: 'ko', children });
}

describe('useDevicePreview', () => {
  /** @effects preview_device_toggle_reflects_preset_width */
  it('초기 디바이스는 desktop, 폭은 DEVICE_PRESET_WIDTHS.desktop 와 일치', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    expect(result.current.device).toBe('desktop');
    expect(result.current.deviceWidth).toBe(DEVICE_PRESET_WIDTHS.desktop);
    expect(result.current.deviceWidth).toBe(1280);
  });

  /** @effects preview_device_toggle_reflects_preset_width */
  it('setDevice("mobile") 호출 시 state.previewDevice 가 반영되어 폭이 모바일 프리셋(390) 으로 전환', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    act(() => {
      result.current.setDevice('mobile');
    });

    expect(result.current.device).toBe('mobile');
    expect(result.current.deviceWidth).toBe(390);
  });

  /** @effects preview_device_toggle_reflects_preset_width */
  it('setDevice("tablet") → 820, 다시 setDevice("desktop") → 1280', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    act(() => {
      result.current.setDevice('tablet');
    });
    expect(result.current.deviceWidth).toBe(820);

    act(() => {
      result.current.setDevice('desktop');
    });
    expect(result.current.deviceWidth).toBe(1280);
  });

  /** @effects preview_zoom_clamped_0_5_to_1 */
  it('setZoom 은 0.5 ~ 1.0 범위로 클램프 (안전 범위 가드)', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    act(() => {
      result.current.setZoom(2);
    });
    expect(result.current.zoom).toBe(1);

    act(() => {
      result.current.setZoom(0.1);
    });
    expect(result.current.zoom).toBe(0.5);

    act(() => {
      result.current.setZoom(0.75);
    });
    expect(result.current.zoom).toBe(0.75);
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('availableWidth 부족 시 scale 이 자동 축소 (가용폭 / deviceWidth) — 줌 100% 포함', () => {
    const { result } = renderHook(() => useDevicePreview({ availableWidth: 600 }), {
      wrapper: wrap(),
    });

    // desktop 1280px 인데 availableWidth=600 + zoom 1 → 맞춤 줌(600/1280). 종전에는 줌 100% 를
    // 가로 스크롤 모드로 보고 1 을 돌려줘 창 1280 에서 문서 폭이 1608px 이 됐다.
    expect(result.current.scale).toBeCloseTo(600 / 1280, 10);

    act(() => {
      result.current.setZoom(0.9);
    });
    // 1280 * 0.9 = 1152 > 600 → scale 자동 축소 (600/1280 ≈ 0.46875 와 zoom=0.9 중 작은 값)
    expect(result.current.scale).toBeLessThanOrEqual(0.9);
    expect(result.current.scale).toBeCloseTo(Math.min(0.9, 600 / 1280), 5);
  });

  // 결함 2 — custom width breakpoint
  /** @effects preview_custom_device_uses_clamped_input_width_as_override */
  it('setDevice("custom") 후 deviceWidth 는 기본 customWidth(1024) 를 사용', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    act(() => {
      result.current.setDevice('custom');
    });

    expect(result.current.device).toBe('custom');
    expect(result.current.deviceWidth).toBe(1024);
    expect(result.current.customWidth).toBe(1024);
  });

  /**
   * @scenario preview_device=custom, custom_width_input=in_range
   * @effects preview_custom_device_uses_clamped_input_width_as_override
   */
  it('setCustomWidth 입력값이 custom 디바이스의 deviceWidth 로 반영', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    act(() => {
      result.current.setDevice('custom');
      result.current.setCustomWidth(500);
    });

    expect(result.current.deviceWidth).toBe(500);
    expect(result.current.customWidth).toBe(500);
  });

  /**
   * @scenario preview_device=custom, custom_width_input=below_min_320
   * @effects preview_custom_width_clamped_320_to_1920
   */
  it('setCustomWidth 하한 미만(100) 은 320 으로 클램프', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    act(() => {
      result.current.setDevice('custom');
      result.current.setCustomWidth(100); // 하한 미만
    });
    expect(result.current.customWidth).toBe(320);
    expect(result.current.deviceWidth).toBe(320);
  });

  /**
   * @scenario preview_device=custom, custom_width_input=above_max_1920
   * @effects preview_custom_width_clamped_320_to_1920
   */
  it('setCustomWidth 상한 초과(5000) 는 1920 으로 클램프', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    act(() => {
      result.current.setDevice('custom');
      result.current.setCustomWidth(5000); // 상한 초과
    });
    expect(result.current.customWidth).toBe(1920);
    expect(result.current.deviceWidth).toBe(1920);
  });

  /**
   * @scenario preview_device=desktop, custom_width_input=in_range
   * @effects preview_non_custom_ignores_custom_width
   */
  it('custom 미선택(desktop) 상태에서는 customWidth 변경이 deviceWidth 에 영향 없음', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    act(() => {
      result.current.setCustomWidth(500);
    });

    // 여전히 desktop → 1280
    expect(result.current.device).toBe('desktop');
    expect(result.current.deviceWidth).toBe(1280);
  });

  /**
   * @scenario preview_device=tablet, custom_width_input=in_range
   * @effects preview_non_custom_ignores_custom_width
   */
  it('tablet 선택 상태에서는 customWidth 변경이 deviceWidth 에 영향 없음', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    act(() => {
      result.current.setDevice('tablet');
      result.current.setCustomWidth(500);
    });

    expect(result.current.device).toBe('tablet');
    expect(result.current.deviceWidth).toBe(820);
  });

  /**
   * @scenario preview_device=mobile, custom_width_input=in_range
   * @effects preview_non_custom_ignores_custom_width
   */
  it('mobile 선택 상태에서는 customWidth 변경이 deviceWidth 에 영향 없음', () => {
    const { result } = renderHook(() => useDevicePreview(), { wrapper: wrap() });

    act(() => {
      result.current.setDevice('mobile');
      result.current.setCustomWidth(500);
    });

    expect(result.current.device).toBe('mobile');
    expect(result.current.deviceWidth).toBe(390);
  });
});
