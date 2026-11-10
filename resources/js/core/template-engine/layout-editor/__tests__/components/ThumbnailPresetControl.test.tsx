/**
 * ThumbnailPresetControl.test.tsx — Img 「썸네일 규격」 위젯 (`widget: "thumbnail-preset"`)
 *
 * 가드:
 *  (1) 목록 출처 우선순위 — 캔버스 store(`__templateApp.getGlobalState().settings.upload`) → G7Config
 *  (2) 첫 항목 「원본」 = 빈 값 → onChange(undefined) (prop 삭제)
 *  (3) 현재 값 표시 — 목록에 있으면 선택, 없으면(삭제된 규격) 「현재 사용 안 함」 항목으로 보존
 *  (4) 데이터 연결 값(`{{…}}`)은 ControlRenderer 공용 게이트가 배지로 디그레이드
 *  (5) propValue 적용 — 고르면 `thumbPreset` prop 에 이름이 기록된다
 *
 * @since engine-v1.94.0
 */

import { describe, it, expect, vi, afterEach, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import {
  ThumbnailPresetControl,
  readThumbnailPresetOptions,
} from '../../components/property-controls/ThumbnailPresetControl';
import { ControlRenderer } from '../../components/property-controls/ControlRenderer';
import { registerCoreWidgets } from '../../spec/registerCoreWidgets';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

beforeAll(() => registerCoreWidgets());

const t = (key: string, params?: Record<string, string | number>): string =>
  params ? `${key}|${Object.entries(params).map(([k, v]) => `${k}=${v}`).join(',')}` : key;

const control: EditorControlSpec = {
  widget: 'thumbnail-preset',
  apply: { type: 'propValue', propKey: 'thumbPreset' },
} as never;

const CONFIG_PRESETS = [
  { name: 'xs', width: 64, height: 64, fit: 'cover', numeric: false, format: 'original' },
  { name: 'sm', width: 160, height: 160, fit: 'cover', numeric: false, format: 'original' },
  { name: 'md', width: 640, height: null, fit: 'width', numeric: true, format: 'original' },
];

const CANVAS_PRESETS = [
  { name: 'banner_wide', width: 1200, height: 400, fit: 'cover', numeric: false, format: 'webp' },
];

const w = window as any;
let savedConfig: unknown;
let savedApp: unknown;

beforeEach(() => {
  savedConfig = w.G7Config;
  savedApp = w.__templateApp;
  w.G7Config = { settings: { upload: { thumbnail_presets_public: CONFIG_PRESETS } } };
  delete w.__templateApp;
});

afterEach(() => {
  cleanup();
  w.G7Config = savedConfig;
  if (savedApp === undefined) delete w.__templateApp;
  else w.__templateApp = savedApp;
});

function options(): Array<{ value: string; text: string }> {
  const select = screen.getByTestId('g7le-widget-thumbnail-preset') as HTMLSelectElement;
  return Array.from(select.options).map((o) => ({ value: o.value, text: o.textContent ?? '' }));
}

describe('readThumbnailPresetOptions — 목록 출처', () => {
  // @scenario surface=editor_control
  // @effects preset_options_prefer_canvas_store_then_g7config
  it('캔버스 store 가 없으면 G7Config 의 규격을 쓴다', () => {
    expect(readThumbnailPresetOptions().map((p) => p.name)).toEqual(['xs', 'sm', 'md']);
  });

  // @scenario surface=editor_control
  // @effects preset_options_prefer_canvas_store_then_g7config
  it('캔버스 store(격리 façade) 에 규격이 있으면 그것이 우선이다', () => {
    w.__templateApp = {
      getGlobalState: () => ({ settings: { upload: { thumbnail_presets_public: CANVAS_PRESETS } } }),
    };
    expect(readThumbnailPresetOptions().map((p) => p.name)).toEqual(['banner_wide']);
  });

  // @scenario surface=editor_control
  // @effects preset_options_prefer_canvas_store_then_g7config
  it('캔버스 store 에 settings.upload 가 없으면 G7Config 로 넘어간다 (sampleGlobal 미시드)', () => {
    w.__templateApp = { getGlobalState: () => ({ settings: { general: { site_name: 'x' } } }) };
    expect(readThumbnailPresetOptions().map((p) => p.name)).toEqual(['xs', 'sm', 'md']);
  });

  // @scenario surface=editor_control
  // @effects preset_options_drop_nameless_and_duplicate_rows
  it('둘 다 없으면 빈 목록 — 이름 없는 행·중복 이름은 거른다', () => {
    w.G7Config = {
      settings: { upload: { thumbnail_presets_public: [{ name: '' }, { width: 1 }, { name: 'a', fit: 'cover' }, { name: 'a' }] } },
    };
    expect(readThumbnailPresetOptions().map((p) => p.name)).toEqual(['a']);
    w.G7Config = {};
    expect(readThumbnailPresetOptions()).toEqual([]);
  });
});

describe('ThumbnailPresetControl — 위젯', () => {
  // @scenario surface=editor_control
  // @effects preset_control_lists_original_then_presets_with_size_fit_labels
  it('첫 항목은 「원본」(빈 값), 이어서 규격이 크기·맞춤 라벨로 나온다', () => {
    render(<ThumbnailPresetControl control={control} value={undefined} onChange={vi.fn()} t={t} />);
    const opts = options();
    expect(opts[0]).toEqual({ value: '', text: 'layout_editor.control.thumbnail_preset.original' });
    expect(opts.slice(1).map((o) => o.value)).toEqual(['xs', 'sm', 'md']);
    // 맞춤 라벨 키가 해석되지 않으면(언어팩 누락) 키를 노출하지 않고 맞춤 원문으로 대신한다
    expect(opts[1].text).toBe('layout_editor.control.thumbnail_preset.option_label|name=xs,size=64×64,fit=cover');
    expect(opts[3].text).toContain('size=640px');
  });

  // @scenario surface=editor_control
  // @effects preset_control_lists_original_then_presets_with_size_fit_labels
  it('맞춤 라벨 키가 해석되면 그 번역을 쓴다', () => {
    const translated = (key: string, params?: Record<string, string | number>): string =>
      key === 'layout_editor.control.thumbnail_preset.fit_cover' ? '자르기' : t(key, params);
    render(<ThumbnailPresetControl control={control} value={undefined} onChange={vi.fn()} t={translated} />);
    expect(options()[2].text).toBe('layout_editor.control.thumbnail_preset.option_label|name=sm,size=160×160,fit=자르기');
  });

  // @scenario surface=editor_control
  // @effects preset_control_keeps_unknown_current_value_visible
  it('현재 값이 목록에 있으면 그 항목이 선택돼 보인다', () => {
    render(<ThumbnailPresetControl control={control} value="sm" onChange={vi.fn()} t={t} />);
    expect((screen.getByTestId('g7le-widget-thumbnail-preset') as HTMLSelectElement).value).toBe('sm');
  });

  // @scenario surface=editor_control
  // @effects preset_control_keeps_unknown_current_value_visible
  it('목록에 없는 현재 값(삭제된 규격)은 「현재 사용 안 함」 항목으로 보존된다', () => {
    render(<ThumbnailPresetControl control={control} value="old_banner" onChange={vi.fn()} t={t} />);
    expect((screen.getByTestId('g7le-widget-thumbnail-preset') as HTMLSelectElement).value).toBe('old_banner');
    expect(screen.getByTestId('g7le-widget-thumbnail-preset-missing').textContent).toBe(
      'layout_editor.control.thumbnail_preset.unknown|name=old_banner',
    );
  });

  // @scenario surface=editor_control
  // @effects preset_control_emits_name_or_undefined
  it('규격을 고르면 이름을, 「원본」을 고르면 undefined 를 낸다', () => {
    const onChange = vi.fn();
    render(<ThumbnailPresetControl control={control} value="sm" onChange={onChange} t={t} />);
    const select = screen.getByTestId('g7le-widget-thumbnail-preset');
    fireEvent.change(select, { target: { value: 'md' } });
    expect(onChange).toHaveBeenLastCalledWith('md');
    fireEvent.change(select, { target: { value: '' } });
    expect(onChange).toHaveBeenLastCalledWith(undefined);
  });
});

describe('ControlRenderer 결선 — Img thumbPreset (propValue)', () => {
  // @scenario surface=editor_control
  // @effects img_thumb_preset_written_to_node_props
  it('고르면 노드 props.thumbPreset 에 기록된다', () => {
    const onPatch = vi.fn();
    const node: EditorNode = { type: 'basic', name: 'Img', props: { src: '/api/attachment/abc' } };
    render(<ControlRenderer controlKey="imgThumbPreset" control={control} node={node} t={t} onPatch={onPatch} />);
    fireEvent.change(screen.getByTestId('g7le-widget-thumbnail-preset'), { target: { value: 'xs' } });
    const patched = onPatch.mock.calls[onPatch.mock.calls.length - 1][0] as EditorNode;
    expect(patched.props?.thumbPreset).toBe('xs');
    expect(patched.props?.src).toBe('/api/attachment/abc');
  });

  // @scenario surface=editor_control
  // @effects img_thumb_preset_written_to_node_props
  it('「원본」을 고르면 thumbPreset prop 이 지워진다', () => {
    const onPatch = vi.fn();
    const node: EditorNode = { type: 'basic', name: 'Img', props: { src: '/x', thumbPreset: 'sm' } };
    render(<ControlRenderer controlKey="imgThumbPreset" control={control} node={node} t={t} onPatch={onPatch} />);
    fireEvent.change(screen.getByTestId('g7le-widget-thumbnail-preset'), { target: { value: '' } });
    const patched = onPatch.mock.calls[onPatch.mock.calls.length - 1][0] as EditorNode;
    expect(patched.props && 'thumbPreset' in patched.props).toBe(false);
  });

  // @scenario surface=editor_control
  // @effects img_thumb_preset_binding_value_degrades_to_badge
  it('데이터 연결 값이면 원문 배지로 디그레이드되고 위젯은 그리지 않는다', () => {
    const expr = "{{_local.preset ?? 'sm'}}";
    const node: EditorNode = { type: 'basic', name: 'Img', props: { thumbPreset: expr } };
    render(<ControlRenderer controlKey="imgThumbPreset" control={control} node={node} t={t} onPatch={vi.fn()} />);
    expect(screen.getByTestId('g7le-control-imgThumbPreset-expression').textContent).toBe(expr);
    expect(screen.queryByTestId('g7le-widget-thumbnail-preset')).toBeNull();
  });
});
