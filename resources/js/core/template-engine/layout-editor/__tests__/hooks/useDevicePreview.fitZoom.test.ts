/**
 * useDevicePreview — 맞춤 줌(캔버스 가용 폭에 미리보기를 맞춤)과 문서 폭 계산 회귀
 *
 * 회귀 원인: 창 1280 에서 편집기를 열면 문서 폭이 1608px(라우트 트리 280 + 캔버스 여백 48 +
 * 데스크톱 미리보기 1280)이라 가로 스크롤이 생겼다. 배율 계산이 줌 100% 면 가용 폭과 무관하게 1 을
 * 돌려줬고(가로 스크롤 모드), 캔버스는 가용 폭을 넘겨주지도 않았다. 줌을 내려도 래퍼가
 * `transform: scale` 로만 줄어 레이아웃 폭은 1280 으로 남았다.
 *
 * 이 파일은 배율·가용 폭·무대 높이 계산과, 그 결과로 정해지는 문서 폭이 셸 폭을 넘지 않는지를
 * 순수 계산으로 잠근다(jsdom 은 레이아웃이 없어 실제 문서 폭은 브라우저 실측으로 본다).
 *
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): editor_preview_scale_fits_available_width, editor_document_width_within_shell
 */

import { describe, it, expect } from 'vitest';
import React from 'react';
import { renderHook, act } from '@testing-library/react';
import {
  useDevicePreview,
  computePreviewScale,
  resolveNextAvailableWidth,
  PREVIEW_AVAILABLE_WIDTH_GROW_THRESHOLD,
} from '../../hooks/useDevicePreview';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import {
  resolveCanvasAvailableWidth,
  resolvePreviewStageHeight,
  PREVIEW_CANVAS_PADDING,
} from '../../components/PreviewCanvas';
import { EDITOR_MIN_WIDTH } from '../../LayoutEditorChrome';

function wrap(): React.FC<{ children: React.ReactNode }> {
  return ({ children }) =>
    // children 은 필수 prop — 세 번째 인자 대신 props 로 넘긴다(타입 일치, 실행 동작 동일).
    React.createElement(LayoutEditorProvider, { templateIdentifier: 'sirsoft-basic', initialLocale: 'ko', children });
}

/** 라우트 트리 폭(px) — RouteTreePanel 펼친 상태 고정 폭. */
const ROUTE_TREE_WIDTH = 280;
/** 클래식 세로 스크롤바 폭(px) — 창 폭에서 빠지는 값. */
const SCROLLBAR = 15;

/**
 * 창 폭이 주어졌을 때 편집기 문서 폭을 계산한다(셸 → 본문 grid → 캔버스 → 무대).
 *
 * - 셸 폭 = max(창 안쪽 폭, EDITOR_MIN_WIDTH)
 * - 캔버스 폭 = 셸 폭 − 트리 폭(grid 열 `auto minmax(0, 1fr)`)
 * - 가용 폭 = 캔버스 폭 − 좌우 여백(resolveCanvasAvailableWidth)
 * - 무대 폭 = deviceWidth × scale(computePreviewScale)
 * - 문서 폭 = max(셸 폭, 트리 폭 + 여백 + 무대 폭)
 */
function documentWidth(windowWidth: number, deviceWidth: number, zoom: number): {
  shell: number;
  doc: number;
  scale: number;
} {
  const inner = windowWidth - SCROLLBAR;
  const shell = Math.max(inner, EDITOR_MIN_WIDTH);
  const canvas = shell - ROUTE_TREE_WIDTH;
  const available = resolveCanvasAvailableWidth(canvas, PREVIEW_CANVAS_PADDING, PREVIEW_CANVAS_PADDING);
  const scale = computePreviewScale(deviceWidth, zoom, available);
  const content = ROUTE_TREE_WIDTH + PREVIEW_CANVAS_PADDING * 2 + deviceWidth * scale;
  return { shell, doc: Math.max(shell, content), scale };
}

describe('computePreviewScale — 줌은 배율 상한, 가용 폭에 맞춤', () => {
  /** @effects editor_preview_scale_fits_available_width */
  it('줌 100% 여도 가용 폭이 기기 폭보다 좁으면 가용 폭에 맞춰 줄인다', () => {
    // 종전: zoom >= 1 이면 무조건 1(가로 스크롤 모드) → 1280 프레임이 952 캔버스를 넘쳤다.
    expect(computePreviewScale(1280, 1, 952)).toBeCloseTo(952 / 1280, 10);
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('가용 폭이 넉넉하면 줌 값을 그대로 쓴다(1 을 넘겨 키우지 않는다)', () => {
    expect(computePreviewScale(1280, 1, 2000)).toBe(1);
    expect(computePreviewScale(390, 0.8, 952)).toBe(0.8);
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('줌이 맞춤 배율보다 작으면 줌을 쓴다(줌은 상한)', () => {
    expect(computePreviewScale(1280, 0.5, 952)).toBe(0.5);
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('가용 폭을 아직 모르면(null·0·비정상) 줌을 그대로 쓴다', () => {
    expect(computePreviewScale(1280, 1, null)).toBe(1);
    expect(computePreviewScale(1280, 0.75, undefined)).toBe(0.75);
    expect(computePreviewScale(1280, 1, 0)).toBe(1);
    expect(computePreviewScale(1280, 1, Number.NaN)).toBe(1);
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('축소 폭(deviceWidth × scale)은 가용 폭을 넘지 않는다', () => {
    for (const device of [390, 820, 1024, 1280, 1920]) {
      for (const available of [300, 681, 952, 1137, 1500]) {
        const s = computePreviewScale(device, 1, available);
        expect(device * s).toBeLessThanOrEqual(available + 1e-9);
      }
    }
  });
});

describe('[case:wysiwyg-10] 편집기 문서 폭 — 창 1024~1920 에서 미리보기 때문에 셸보다 넓어지지 않는다', () => {
  /** @effects editor_preview_scale_fits_available_width */
  it('창 1280 데스크톱 미리보기: 종전 계산은 1608px, 이제 셸 폭을 넘지 않는다', () => {
    // 종전 배율(zoom 100% = 1) 로 계산하면 1608 이 나온다 — 결함 재현값.
    const legacy = ROUTE_TREE_WIDTH + PREVIEW_CANVAS_PADDING * 2 + 1280 * 1;
    expect(legacy).toBe(1608);

    const { shell, doc, scale } = documentWidth(1280, 1280, 1);
    expect(doc).toBe(shell);
    expect(scale).toBeLessThan(1);
  });

  /** @effects editor_document_width_within_shell */
  it.each([1024, 1280, 1366, 1440, 1600, 1920])('창 %ipx: 문서 폭 = 셸 폭(기기 전부, 줌 100%)', (w) => {
    for (const device of [390, 820, 1280, 1920]) {
      const { shell, doc } = documentWidth(w, device, 1);
      expect(doc).toBeLessThanOrEqual(shell);
    }
  });

  /** @effects editor_document_width_within_shell */
  it('창이 충분히 넓으면(트리 + 여백 + 1280 + 스크롤바 이상) 데스크톱 미리보기는 축소하지 않는다', () => {
    const { scale } = documentWidth(ROUTE_TREE_WIDTH + PREVIEW_CANVAS_PADDING * 2 + 1280 + SCROLLBAR, 1280, 1);
    expect(scale).toBe(1);
  });
});

describe('resolveCanvasAvailableWidth — 캔버스 가용 폭', () => {
  /** @effects editor_preview_scale_fits_available_width */
  it('clientWidth 에서 좌우 여백을 빼고 내림한다', () => {
    expect(resolveCanvasAvailableWidth(1000, 24, 24)).toBe(952);
    expect(resolveCanvasAvailableWidth(1000.7, 24, 24)).toBe(952);
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('레이아웃이 없는 환경(clientWidth 0)이나 여백보다 좁으면 null', () => {
    expect(resolveCanvasAvailableWidth(0, 24, 24)).toBeNull();
    expect(resolveCanvasAvailableWidth(40, 24, 24)).toBeNull();
    expect(resolveCanvasAvailableWidth(Number.NaN, 24, 24)).toBeNull();
  });
});

describe('resolveNextAvailableWidth — 세로 스크롤바 진동 방지', () => {
  /** @effects editor_preview_scale_fits_available_width */
  it('줄어드는 쪽은 즉시 반영한다(넘침 방지)', () => {
    expect(resolveNextAvailableWidth(952, 937)).toBe(937);
    expect(resolveNextAvailableWidth(952, 400)).toBe(400);
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('스크롤바 폭만큼(문턱 미만) 늘어나면 반영하지 않는다', () => {
    expect(resolveNextAvailableWidth(937, 952)).toBe(937);
    expect(resolveNextAvailableWidth(937, 937 + PREVIEW_AVAILABLE_WIDTH_GROW_THRESHOLD - 1)).toBe(937);
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('문턱 이상 늘어나면 반영한다(트리 접기 +280 등)', () => {
    expect(resolveNextAvailableWidth(937, 937 + PREVIEW_AVAILABLE_WIDTH_GROW_THRESHOLD)).toBe(
      937 + PREVIEW_AVAILABLE_WIDTH_GROW_THRESHOLD,
    );
    expect(resolveNextAvailableWidth(952, 1232)).toBe(1232);
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('처음 재거나(prev null) 잴 수 없으면(null) 그 값을 그대로 쓴다', () => {
    expect(resolveNextAvailableWidth(null, 952)).toBe(952);
    expect(resolveNextAvailableWidth(undefined, 952)).toBe(952);
    expect(resolveNextAvailableWidth(952, null)).toBeNull();
  });
});

describe('resolvePreviewStageHeight — 축소 시 무대 높이', () => {
  /** @effects editor_document_width_within_shell */
  it('축소(scale < 1)면 래퍼 레이아웃 높이 × 배율', () => {
    expect(resolvePreviewStageHeight(0.5, 2000)).toBe(1000);
  });

  /** @effects editor_document_width_within_shell */
  it('축소가 없거나 높이를 모르면 자동(undefined) — 종전 레이아웃 그대로', () => {
    expect(resolvePreviewStageHeight(1, 2000)).toBeUndefined();
    expect(resolvePreviewStageHeight(0.5, null)).toBeUndefined();
    expect(resolvePreviewStageHeight(0.5, 0)).toBeUndefined();
  });
});

describe('useDevicePreview — 편집기 상태의 가용 폭으로 배율을 맞춘다', () => {
  /** @effects editor_preview_scale_fits_available_width */
  it('SET_PREVIEW_AVAILABLE_WIDTH 를 넣으면 모든 호출처가 같은 맞춤 배율을 받는다', () => {
    const { result } = renderHook(
      () => ({ preview: useDevicePreview(), editor: useLayoutEditor() }),
      { wrapper: wrap() },
    );
    // 측정 전: 줌 그대로
    expect(result.current.preview.scale).toBe(1);
    expect(result.current.preview.scaledWidth).toBe(1280);

    act(() => {
      result.current.editor.dispatch({ type: 'SET_PREVIEW_AVAILABLE_WIDTH', width: 952 });
    });
    expect(result.current.preview.scale).toBeCloseTo(952 / 1280, 10);
    expect(result.current.preview.scaledWidth).toBe(952);
    // 기기 폭(반응형 판정 입력)은 그대로다.
    expect(result.current.preview.deviceWidth).toBe(1280);

    // 모바일은 가용 폭 안에 들어가므로 줄이지 않는다.
    act(() => {
      result.current.preview.setDevice('mobile');
    });
    expect(result.current.preview.scale).toBe(1);
    expect(result.current.preview.scaledWidth).toBe(390);
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('같은 가용 폭을 다시 넣으면 상태 객체가 바뀌지 않는다(미리보기 트리 리렌더 방지)', () => {
    const { result } = renderHook(() => useLayoutEditor(), { wrapper: wrap() });
    act(() => {
      result.current.dispatch({ type: 'SET_PREVIEW_AVAILABLE_WIDTH', width: 952 });
    });
    const before = result.current.state;
    act(() => {
      result.current.dispatch({ type: 'SET_PREVIEW_AVAILABLE_WIDTH', width: 952 });
    });
    expect(result.current.state).toBe(before);
  });
});
