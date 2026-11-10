// e2e:allow 편집기 속성 위젯(썸네일 규격 select) — 단위(ThumbnailPresetControl.test·ControlRenderer 결선)+Chrome MCP 편집기 강화 표준 7요소로 검증
/**
 * ThumbnailPresetControl.tsx — 「썸네일 규격」 선택 위젯 (`widget: "thumbnail-preset"`)
 *
 * 이미지(Img) 의 `thumbPreset` prop 을 고른다. 목록은 **서버가 정한 규격**(환경설정 > 업로드 > 썸네일
 * 규격 표 — 고정 슬롯·확장 규격·운영자 규격)이라 editor-spec 의 정적 `options` 로는 줄 수 없다.
 * 정적 options 만 읽는 `SelectWidget` 으로 두면 운영자가 추가한 규격이 목록에 나오지 않는다.
 *
 * 목록 출처(앞이 우선):
 *  1. 캔버스 store `_global.settings.upload.thumbnail_presets_public` — 편집기가 마운트한 동안
 *     `window.__templateApp` 은 격리 façade(`previewCanvasStore`)이고 `getGlobalState()` 가 캔버스 전역을
 *     준다. 템플릿 sampleGlobal 이 이 키를 시드하면 그 값이 쓰인다.
 *  2. `window.G7Config.settings.upload.thumbnail_presets_public` — 서버가 페이지에 실어 준 실제 규격.
 *
 * 첫 항목은 「원본」(빈 값) — 고르면 prop 을 지운다(`onChange(undefined)`). 현재 값이 목록에 없는 규격
 * (삭제·사용 중지된 규격)이면 그 이름을 「현재 사용 안 함」 항목으로 남겨 값이 조용히 사라진 것처럼 보이지
 * 않게 한다. 데이터 연결 값(`{{…}}`) 보호는 ControlRenderer 의 공용 게이트(`useBoundValueGuard`)가 맡는다.
 *
 * @since engine-v1.94.0
 */

import React from 'react';
import type { WidgetProps } from '../../spec/widgetRegistry';

/** 서버 규격 한 줄 (`ThumbnailPresetRegistry::forFrontend()`) — 위젯이 쓰는 필드만 */
export interface ThumbnailPresetOption {
  name: string;
  width: number | null;
  height: number | null;
  fit: string;
}

/**
 * 규격 행 배열을 위젯 옵션으로 거른다 (이름 없는 행·중복 이름 제외).
 *
 * @param raw 설정 값
 * @return 옵션 목록, 배열이 아니면 null (다음 출처로 넘어간다)
 */
function normalizePresets(raw: unknown): ThumbnailPresetOption[] | null {
  if (!Array.isArray(raw)) return null;
  const seen = new Set<string>();
  const rows: ThumbnailPresetOption[] = [];
  for (const row of raw) {
    if (!row || typeof row !== 'object') continue;
    const name = (row as { name?: unknown }).name;
    if (typeof name !== 'string' || name === '' || seen.has(name)) continue;
    seen.add(name);
    const width = (row as { width?: unknown }).width;
    const height = (row as { height?: unknown }).height;
    const fit = (row as { fit?: unknown }).fit;
    rows.push({
      name,
      width: typeof width === 'number' ? width : null,
      height: typeof height === 'number' ? height : null,
      fit: typeof fit === 'string' ? fit : '',
    });
  }
  return rows;
}

/**
 * 규격 목록을 읽는다 — 캔버스 store 우선, 없으면 G7Config.
 *
 * @return 규격 목록 (설정이 없으면 빈 배열)
 */
export function readThumbnailPresetOptions(): ThumbnailPresetOption[] {
  const w = (typeof window !== 'undefined' ? window : undefined) as any;
  let fromCanvas: unknown;
  try {
    fromCanvas = w?.__templateApp?.getGlobalState?.()?.settings?.upload?.thumbnail_presets_public;
  } catch {
    fromCanvas = undefined;
  }
  return (
    normalizePresets(fromCanvas)
    ?? normalizePresets(w?.G7Config?.settings?.upload?.thumbnail_presets_public)
    ?? []
  );
}

/**
 * 옵션 라벨 — `sm (160×160 · 자르기)`.
 *
 * @param preset 규격
 * @param t 다국어 해석
 * @return 라벨
 */
function presetLabel(preset: ThumbnailPresetOption, t: WidgetProps['t']): string {
  const size = preset.width && preset.height
    ? `${preset.width}×${preset.height}`
    : preset.width
      ? `${preset.width}px`
      : '';
  const fitKey = `layout_editor.control.thumbnail_preset.fit_${preset.fit}`;
  const fitText = preset.fit ? t(fitKey) : '';
  const fit = fitText && fitText !== fitKey ? fitText : preset.fit;
  if (!size && !fit) return preset.name;
  return t('layout_editor.control.thumbnail_preset.option_label', {
    name: preset.name,
    size: size || '-',
    fit: fit || '-',
  });
}

/**
 * 「썸네일 규격」 select 위젯.
 */
export function ThumbnailPresetControl({ value, onChange, t }: WidgetProps): React.ReactElement {
  // 모달이 열릴 때마다 다시 읽는다(규격 저장 직후 편집기로 돌아온 경우 포함) — 렌더 비용은 배열 한 번 순회다.
  const presets = readThumbnailPresetOptions();
  const current = typeof value === 'string' ? value : '';
  const missing = current !== '' && !presets.some((p) => p.name === current);

  return (
    <select
      className="g7le-widget g7le-widget--select g7le-widget--thumbnail-preset"
      data-testid="g7le-widget-thumbnail-preset"
      value={current}
      onChange={(e) => {
        const raw = e.target.value;
        onChange(raw === '' ? undefined : raw);
      }}
      style={selectStyle}
    >
      <option value="">{t('layout_editor.control.thumbnail_preset.original')}</option>
      {presets.map((preset) => (
        <option key={preset.name} value={preset.name}>
          {presetLabel(preset, t)}
        </option>
      ))}
      {missing && (
        <option value={current} data-testid="g7le-widget-thumbnail-preset-missing">
          {t('layout_editor.control.thumbnail_preset.unknown', { name: current })}
        </option>
      )}
    </select>
  );
}

// StyleControlWidgets.SelectWidget 과 같은 모양
const selectStyle: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', minWidth: 120 };
