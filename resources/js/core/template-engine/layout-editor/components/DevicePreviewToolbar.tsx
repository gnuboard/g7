/**
 * DevicePreviewToolbar.tsx — 디바이스 미리보기 전환 + 줌 컨트롤
 *
 * 캔버스 상단 툴바 영역에 디바이스 토글(데스크톱/태블릿/모바일) + 줌 슬라이더를
 * 노출. 코어 `layout_editor.device.*` / `layout_editor.zoom.*` 다국어 키 사용.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { useDevicePreview, CUSTOM_WIDTH_MIN, CUSTOM_WIDTH_MAX } from '../hooks/useDevicePreview';
import { useDeviceList } from '../hooks/useDeviceList';
import { useTranslation } from '../../TranslationContext';
import { LocaleSwitcher } from './LocaleSwitcher';

/** 프리셋 디바이스 키 → i18n 라벨 키. 동적 커스텀 범위 키는 raw 문자열로 표기. */
const PRESET_LABEL_KEYS: Record<string, string> = {
  desktop: 'layout_editor.device.desktop',
  tablet: 'layout_editor.device.tablet',
  mobile: 'layout_editor.device.mobile',
  portable: 'layout_editor.device.portable',
};

export function DevicePreviewToolbar(): React.ReactElement {
  const { device, zoom, customWidth, setDevice, setZoom, setCustomWidth, colorScheme, setColorScheme } =
    useDevicePreview();
  const { t } = useTranslation();
  // 캔버스 토글/스타일 세부탭 공유 디바이스 목록(프리셋 + 레이아웃 동적 커스텀 키) —
  const deviceKeys = useDeviceList();

  return (
    <div
      className="g7le-device-toolbar"
      data-testid="g7le-device-toolbar"
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 8,
        padding: '4px 8px',
        background: '#fff',
        border: '1px solid #cbd5e1',
        borderRadius: 6,
      }}
    >
      <div role="group" aria-label={t('layout_editor.device.group_label')}>
        {deviceKeys.map((key) => {
          const active = device === key;
          const labelKey = PRESET_LABEL_KEYS[key];
          const label = labelKey ? t(labelKey) : key; // 커스텀 범위 키는 raw 표기
          return (
            <button
              key={key}
              type="button"
              data-testid={`g7le-device-${key}`}
              aria-pressed={active}
              onClick={() => setDevice(key)}
              style={{
                padding: '2px 8px',
                marginRight: 2,
                fontSize: 12,
                cursor: 'pointer',
                background: active ? '#0f172a' : '#f8fafc',
                color: active ? '#fff' : '#0f172a',
                border: '1px solid #cbd5e1',
                borderRadius: 4,
              }}
            >
              {label}
            </button>
          );
        })}
        {/* custom — 사용자 지정 폭 토글(동적 목록과 별개의 특례 어포던스). */}
        {(() => {
          const active = device === 'custom';
          return (
            <button
              type="button"
              data-testid="g7le-device-custom"
              aria-pressed={active}
              onClick={() => setDevice('custom')}
              style={{
                padding: '2px 8px',
                marginRight: 2,
                fontSize: 12,
                cursor: 'pointer',
                background: active ? '#0f172a' : '#f8fafc',
                color: active ? '#fff' : '#0f172a',
                border: '1px solid #cbd5e1',
                borderRadius: 4,
              }}
            >
              {t('layout_editor.device.custom')}
            </button>
          );
        })()}
      </div>
      {device === 'custom' && (
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
          <label
            htmlFor="g7le-custom-width-input"
            style={{ fontSize: 11, color: '#475569' }}
          >
            {t('layout_editor.device.custom_width_label')}
          </label>
          <input
            id="g7le-custom-width-input"
            type="number"
            min={CUSTOM_WIDTH_MIN}
            max={CUSTOM_WIDTH_MAX}
            step={10}
            value={customWidth}
            data-testid="g7le-custom-width-input"
            aria-label={t('layout_editor.device.custom_width_label')}
            onChange={(e) => {
              const parsed = parseInt(e.currentTarget.value, 10);
              if (Number.isFinite(parsed)) setCustomWidth(parsed);
            }}
            style={{
              width: 72,
              padding: '2px 6px',
              fontSize: 12,
              border: '1px solid #cbd5e1',
              borderRadius: 4,
            }}
          />
          <span style={{ fontSize: 11, color: '#475569' }}>px</span>
        </div>
      )}
      <div role="group" aria-label={t('layout_editor.toolbar.color_scheme_group')} style={{ display: 'inline-flex', gap: 2 }}>
        {(['light', 'dark'] as const).map((scheme) => {
          const active = colorScheme === scheme;
          return (
            <button
              key={scheme}
              type="button"
              data-testid={`g7le-toolbar-scheme-${scheme}`}
              aria-pressed={active}
              onClick={() => setColorScheme(scheme)}
              style={{
                padding: '2px 8px',
                fontSize: 12,
                cursor: 'pointer',
                background: active ? '#0f172a' : '#f8fafc',
                color: active ? '#fff' : '#0f172a',
                border: '1px solid #cbd5e1',
                borderRadius: 4,
              }}
            >
              {t(
                scheme === 'light'
                  ? 'layout_editor.toolbar.color_scheme_light'
                  : 'layout_editor.toolbar.color_scheme_dark',
              )}
            </button>
          );
        })}
      </div>
      <div style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
        <span style={{ fontSize: 11, color: '#475569' }}>{t('layout_editor.zoom.label')}</span>
        <input
          type="range"
          min={0.5}
          max={1}
          step={0.05}
          value={zoom}
          data-testid="g7le-zoom-slider"
          aria-label={t('layout_editor.zoom.aria_label')}
          onChange={(e) => setZoom(parseFloat(e.currentTarget.value))}
          style={{ width: 100 }}
        />
        <span style={{ fontSize: 11, color: '#475569', minWidth: 36, textAlign: 'right' }}>
          {Math.round(zoom * 100)}%
        </span>
      </div>
      {/* 콘텐츠 로케일 전환 — 캔버스 프리뷰의 로케일만 바꾼다(chrome 로케일 불변).
          디바이스/색모드/줌과 동격의 미리보기 축이라 같은 툴바에 둔다. */}
      <LocaleSwitcher />
    </div>
  );
}
