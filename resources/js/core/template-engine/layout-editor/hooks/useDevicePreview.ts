/**
 * useDevicePreview.ts — 디바이스 미리보기 상태 + 폭/scale 계산
 *
 * `LayoutEditorContext` 의 `previewDevice` / `previewZoom` 을 읽어 PreviewCanvas
 * 가 사용할 디바이스 폭, scale, 가용폭 등을 계산해 반환한다.
 *
 * 디바이스 프리셋 폭:
 * - 데스크톱 1280px (1024+ desktop breakpoint 안)
 * - 태블릿 820px (768–1023 tablet 안)
 * - 모바일 390px (0–767 mobile 안)
 * - 커스텀 — 외부에서 직접 지정 (Phase 2 범위 외)
 *
 * @since engine-v1.50.0
 */

import { useCallback, useMemo } from 'react';
import {
  useLayoutEditor,
  type PreviewDevice,
  type PreviewColorScheme,
} from '../LayoutEditorContext';
import { resolveDeviceWidth } from '../spec/deviceList';

export interface DevicePreviewResult {
  /** 현재 선택 디바이스 (`previewDevice`) */
  device: PreviewDevice;
  /** 디바이스 폭 (px). overrideWidth 로 ResponsiveProvider 에 전달. */
  deviceWidth: number;
  /** 사용자 줌 슬라이더 값 (0.5 ~ 1.0). 1 = 100%. */
  zoom: number;
  /**
   * 캔버스 프레임에 적용할 시각적 scale.
   * `deviceWidth * scale` 이 가용폭에 맞도록 자동 축소하되, zoom 100% 시
   * 가로 스크롤이 발생하도록 허용.
   */
  scale: number;
  /** custom 디바이스의 현재 폭(px) — 입력 UI 가 controlled value 로 사용. */
  customWidth: number;
  /** 디바이스 전환 dispatch */
  setDevice: (device: PreviewDevice) => void;
  /** 줌 dispatch (0.5 ~ 1.0) */
  setZoom: (zoom: number) => void;
  /** custom width dispatch — 320~1920 클램프 후 저장. */
  setCustomWidth: (width: number) => void;
  /** 프리뷰 색상 테마 */
  colorScheme: PreviewColorScheme;
  /** 색상 테마 전환 dispatch */
  setColorScheme: (scheme: PreviewColorScheme) => void;
}

/**
 * 단일폭 프리셋 폭(px) — 표시/테스트용 매핑. 실제 폭 산출은 `resolveDeviceWidth`
 * (deviceList.ts 상한값 규칙)에 위임한다(portable·커스텀 범위 키 지원).
 */
const DEVICE_WIDTH_PX: Record<'desktop' | 'tablet' | 'mobile' | 'custom', number> = {
  desktop: 1280,
  tablet: 820,
  mobile: 390,
  custom: 1024, // custom 선택 + previewCustomWidth 미설정 시 기본값
};

/** custom width 안전 범위 — 320px(소형 모바일) ~ 1920px(대형 데스크톱). */
export const CUSTOM_WIDTH_MIN = 320;
export const CUSTOM_WIDTH_MAX = 1920;
/** custom 미설정 시 초기 입력값. */
export const CUSTOM_WIDTH_DEFAULT = 1024;

/**
 * custom width 입력값을 안전 범위로 클램프.
 *
 * @param value 사용자 입력 px
 * @returns 320~1920 범위로 보정된 정수. 비정상 입력(NaN 등)은 기본값.
 */
export function clampCustomWidth(value: number): number {
  if (!Number.isFinite(value)) return CUSTOM_WIDTH_DEFAULT;
  return Math.round(Math.max(CUSTOM_WIDTH_MIN, Math.min(CUSTOM_WIDTH_MAX, value)));
}

export interface UseDevicePreviewOptions {
  /** 캔버스 가용폭(px) — 디바이스 폭이 더 크면 scale 적용 */
  availableWidth?: number;
}

export function useDevicePreview(options: UseDevicePreviewOptions = {}): DevicePreviewResult {
  const { state, dispatch } = useLayoutEditor();
  const {
    previewDevice: device,
    previewZoom: zoom,
    previewCustomWidth: customWidth,
    previewColorScheme: colorScheme,
  } = state;

  // custom 선택 시 사용자 지정 폭, 그 외엔 키별 산출 폭(프리셋/portable/커스텀 범위 모두 지원).
  const deviceWidth =
    device === 'custom' ? clampCustomWidth(customWidth) : resolveDeviceWidth(device);

  const scale = useMemo(() => {
    const available = options.availableWidth ?? deviceWidth;
    const targetWidth = deviceWidth * zoom;
    // 가용폭이 부족하면 자동 축소(zoom 100% 시 가로 스크롤 허용 — scale=zoom 유지)
    if (zoom >= 1) {
      return zoom; // 가로 스크롤 모드 — 시각적 축소 없음
    }
    if (targetWidth <= available) {
      return zoom;
    }
    return Math.min(zoom, available / deviceWidth);
  }, [deviceWidth, zoom, options.availableWidth]);

  const setDevice = useCallback(
    (next: PreviewDevice) => {
      dispatch({ type: 'SET_PREVIEW_DEVICE', device: next });
    },
    [dispatch],
  );

  const setZoom = useCallback(
    (next: number) => {
      // 안전 범위 클램프 — 0.5 ~ 1.0
      const clamped = Math.max(0.5, Math.min(1, next));
      dispatch({ type: 'SET_PREVIEW_ZOOM', zoom: clamped });
    },
    [dispatch],
  );

  const setCustomWidth = useCallback(
    (next: number) => {
      dispatch({ type: 'SET_PREVIEW_CUSTOM_WIDTH', width: clampCustomWidth(next) });
    },
    [dispatch],
  );

  const setColorScheme = useCallback(
    (next: PreviewColorScheme) => {
      dispatch({ type: 'SET_PREVIEW_COLOR_SCHEME', scheme: next });
    },
    [dispatch],
  );

  return {
    device,
    deviceWidth,
    zoom,
    scale,
    customWidth: clampCustomWidth(customWidth),
    setDevice,
    setZoom,
    setCustomWidth,
    colorScheme,
    setColorScheme,
  };
}

/**
 * 디바이스 폭 매핑 export — 테스트 및 외부 사용자가 검증할 수 있도록.
 */
export const DEVICE_PRESET_WIDTHS = DEVICE_WIDTH_PX;
