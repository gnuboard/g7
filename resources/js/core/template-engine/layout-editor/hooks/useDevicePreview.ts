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
  /** 사용자 줌 슬라이더 값 (0.5 ~ 1.0). 1 = 100%. 실제 배율의 상한이다. */
  zoom: number;
  /**
   * 캔버스 프레임에 적용할 시각적 scale — `computePreviewScale` 결과.
   * 줌을 상한으로 두고, 캔버스 가용 폭이 모자라면 `deviceWidth * scale` 이 가용 폭에 들어가게
   * 더 줄인다(줌 100% 도 같다). 프레임의 레이아웃 폭과 반응형 판정은 deviceWidth 그대로다.
   */
  scale: number;
  /** 축소 반영 뒤 미리보기가 캔버스에서 차지하는 가로 폭(px) = deviceWidth * scale. */
  scaledWidth: number;
  /** 실제 크기(1:1) 보기 여부 — 켜면 scale 은 1, 캔버스가 좁으면 가로 스크롤 */
  actualSize: boolean;
  /** 실제 크기 보기 전환 */
  setActualSize: (actualSize: boolean) => void;
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
  /**
   * 캔버스 가용폭(px) — 디바이스 폭이 더 크면 scale 을 줄인다.
   * 생략하면 편집기 상태의 `previewAvailableWidth`(PreviewCanvas 가 잰 값)를 쓴다.
   */
  availableWidth?: number;
}

/**
 * 미리보기 배율 계산 — 줌을 상한으로 두고 캔버스 가용 폭에 맞춘다(맞춤 줌).
 *
 * 종전에는 줌 100% 면 가용 폭과 무관하게 1 을 돌려줘(가로 스크롤 모드) 창 1280 에서 문서 폭이
 * 트리 280 + 여백 48 + 데스크톱 1280 = 1608px 로 늘었다. 줌을 내려도 프레임 래퍼가
 * `transform: scale` 로만 줄고 레이아웃 폭은 deviceWidth 로 남아 스크롤이 사라지지 않았다.
 * 이제 줌 100% 도 가용 폭에 맞춰 줄이고, 호출처(PreviewCanvas)는 축소 폭(`deviceWidth * scale`)을
 * 레이아웃 폭으로 잡는다. 미리보기 안의 반응형 판정(ResponsiveProvider overrideWidth)과 프레임
 * 레이아웃 폭은 deviceWidth 그대로라 기기 미리보기의 뜻은 바뀌지 않는다 — 화면에 그리는 크기만 준다.
 *
 * @param deviceWidth 미리보기 기기 폭(px)
 * @param zoom 사용자 줌(배율 상한, 0.5 ~ 1)
 * @param availableWidth 캔버스가 내줄 수 있는 폭(px). null·0 이하·비정상 값이면 아직 재지 않은
 *   것으로 보고 줌을 그대로 쓴다(측정 전 첫 렌더, jsdom).
 * @param actualSize 실제 크기(1:1) 보기 — true 면 줌·가용 폭과 무관하게 1(가로 스크롤 허용)
 * @returns 0 초과 zoom 이하의 배율(실제 크기면 1)
 */
export function computePreviewScale(
  deviceWidth: number,
  zoom: number,
  availableWidth: number | null | undefined,
  actualSize = false,
): number {
  if (actualSize) return 1;
  if (
    availableWidth === null ||
    availableWidth === undefined ||
    !Number.isFinite(availableWidth) ||
    availableWidth <= 0 ||
    !(deviceWidth > 0)
  ) {
    return zoom;
  }
  return Math.min(zoom, availableWidth / deviceWidth);
}

/**
 * 가용 폭이 늘어날 때 반영하는 최소 증가량(px). 클래식 세로 스크롤바 폭(15~17px)보다 크다.
 *
 * 맞춤 줌은 미리보기 높이를 바꾸고, 높이가 창 높이를 넘나들면 세로 스크롤바가 생기고 사라지며 캔버스
 * 폭이 스크롤바 폭만큼 흔들린다. 줄어드는 쪽과 늘어나는 쪽을 똑같이 반영하면 「줄임 → 스크롤바 사라짐
 * → 넓어짐 → 키움 → 스크롤바 생김」이 끝없이 되풀이된다. 줄어드는 쪽은 넘침을 막아야 하니 즉시
 * 반영하고, 늘어나는 쪽은 이 값 이상일 때만 반영한다.
 */
export const PREVIEW_AVAILABLE_WIDTH_GROW_THRESHOLD = 24;

/**
 * 새로 잰 가용 폭을 상태에 반영할 값으로 고른다(늘어나는 쪽만 문턱을 둔다).
 *
 * @param prev 지금 상태의 가용 폭(px). 아직 없으면 null
 * @param measured 새로 잰 가용 폭(px). 잴 수 없으면 null
 * @returns 상태에 넣을 가용 폭. prev 를 그대로 돌려주면 반영하지 않는다는 뜻이다
 */
export function resolveNextAvailableWidth(
  prev: number | null | undefined,
  measured: number | null,
): number | null {
  const current = prev ?? null;
  if (measured === null || current === null) return measured;
  if (measured <= current) return measured;
  return measured - current >= PREVIEW_AVAILABLE_WIDTH_GROW_THRESHOLD ? measured : current;
}

export function useDevicePreview(options: UseDevicePreviewOptions = {}): DevicePreviewResult {
  const { state, dispatch } = useLayoutEditor();
  const {
    previewDevice: device,
    previewZoom: zoom,
    previewAvailableWidth,
    previewActualSize,
    previewCustomWidth: customWidth,
    previewColorScheme: colorScheme,
  } = state;

  // custom 선택 시 사용자 지정 폭, 그 외엔 키별 산출 폭(프리셋/portable/커스텀 범위 모두 지원).
  const deviceWidth =
    device === 'custom' ? clampCustomWidth(customWidth) : resolveDeviceWidth(device);

  const available = options.availableWidth ?? previewAvailableWidth ?? null;
  const actualSize = previewActualSize ?? false;
  const scale = useMemo(
    () => computePreviewScale(deviceWidth, zoom, available, actualSize),
    [deviceWidth, zoom, available, actualSize],
  );

  const setActualSize = useCallback(
    (next: boolean) => {
      dispatch({ type: 'SET_PREVIEW_ACTUAL_SIZE', actualSize: next });
    },
    [dispatch],
  );

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
    // 소수 둘째 자리에서 내린다 — 부동소수 곱(1280 × 952/1280 = 951.9999…)이 스타일 값에 그대로
    // 실리지 않게 하고, 올림으로 가용 폭을 넘지 않게 한다.
    scaledWidth: Math.floor(deviceWidth * scale * 100 + 1e-6) / 100,
    actualSize,
    setActualSize,
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
