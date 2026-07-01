/**
 * ImagePickerControl.tsx — `image` 위젯
 *
 * 배경 이미지 컨트롤. 이미지 업로드(→ `template_layout_attachments` + 코어
 * `StorageInterface`, 부록 C) 또는 URL 직접 입력 + 표시 방식(채움/맞춤/타일)을
 * 선택한다. 업로드는 `POST /api/admin/templates/{identifier}/layout-attachments`
 * (multipart: file + layout_name) 를 호출하고 응답 `data.url` 을 값으로 쓴다.
 *
 * 값은 `{ url, size, repeat, position }` 객체 또는 undefined(`기본`). ControlRenderer
 * 가 이 객체를 recipeEngine 의 styleProp 다중 속성(values 묶음)으로 변환한다.
 *
 * 편집기 코어 위젯 — `g7le-*` + 인라인 스타일만. StorageInterface 경유 업로드는
 * 백엔드 책임(Storage::disk() 직접 호출 금지 규칙 준수).
 *
 * 항목3: 업로드 fetch 가 `Authorization: Bearer` 를 누락해 401 을
 * 받던 결함을 공용 `layoutAttachments` 클라이언트로 교체해 근본 차단. 현재 레이아웃
 * 첨부 썸네일 가로 스트립(인라인 미니 갤러리) + "이미지 관리" 링크 추가.
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useRef, useState } from 'react';
import type { WidgetProps } from '../../spec/widgetRegistry';
import { useLayoutEditor } from '../../LayoutEditorContext';
import {
  listLayoutAttachments,
  uploadLayoutAttachment,
  deleteLayoutAttachment,
  type LayoutAttachment,
} from '../../utils/layoutAttachments';
import { useEditorModal } from '../../EditorModalContext';
import { LayoutAttachmentManager } from './LayoutAttachmentManager';

interface ImageValue {
  url?: string;
  /** CSS backgroundSize 후보 — cover(채움)/contain(맞춤)/auto(타일) */
  size?: string;
  repeat?: string;
  position?: string;
}

const DISPLAY_MODES: Array<{ value: string; labelKey: string; size: string; repeat: string }> = [
  { value: 'fill', labelKey: 'layout_editor.control.background_image.mode_fill', size: 'cover', repeat: 'no-repeat' },
  { value: 'fit', labelKey: 'layout_editor.control.background_image.mode_fit', size: 'contain', repeat: 'no-repeat' },
  { value: 'tile', labelKey: 'layout_editor.control.background_image.mode_tile', size: 'auto', repeat: 'repeat' },
];

function readValue(value: unknown): ImageValue {
  return value && typeof value === 'object' && !Array.isArray(value) ? (value as ImageValue) : {};
}

function modeOf(v: ImageValue): string {
  const m = DISPLAY_MODES.find((d) => d.size === v.size && d.repeat === v.repeat);
  return m?.value ?? 'fill';
}

export function ImagePickerControl({ value, onChange, t }: WidgetProps): React.ReactElement {
  const current = readValue(value);
  const { state } = useLayoutEditor();
  const modal = useEditorModal();
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [urlDraft, setUrlDraft] = useState<string>(current.url ?? '');
  const [attachments, setAttachments] = useState<LayoutAttachment[]>([]);
  const fileRef = useRef<HTMLInputElement | null>(null);

  const templateId = state.templateIdentifier;
  const layoutName = state.selectedRoute?.layoutName ?? '';

  React.useEffect(() => {
    setUrlDraft(current.url ?? '');
  }, [current.url]);

  // 인라인 미니 갤러리 — 마운트/업로드 성공 시 현재 레이아웃 첨부 목록 로드.
  const loadAttachments = useCallback(async (): Promise<void> => {
    if (!templateId || !layoutName) {
      setAttachments([]);
      return;
    }
    const res = await listLayoutAttachments(templateId, layoutName);
    if (res.ok) setAttachments(res.data);
  }, [templateId, layoutName]);

  useEffect(() => {
    void loadAttachments();
  }, [loadAttachments]);

  const setUrl = (url: string | undefined): void => {
    if (!url) {
      onChange(undefined);
      return;
    }
    const mode = DISPLAY_MODES.find((d) => d.value === modeOf(current)) ?? DISPLAY_MODES[0];
    onChange({
      url,
      size: current.size ?? mode.size,
      repeat: current.repeat ?? mode.repeat,
      position: current.position ?? 'center',
    });
  };

  const setMode = (modeValue: string): void => {
    const mode = DISPLAY_MODES.find((d) => d.value === modeValue);
    if (!mode || !current.url) return;
    onChange({ url: current.url, size: mode.size, repeat: mode.repeat, position: current.position ?? 'center' });
  };

  const onFileChange = async (e: React.ChangeEvent<HTMLInputElement>): Promise<void> => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    setUploadError(null);
    // 공용 클라이언트(Authorization: Bearer 첨부) 사용 — 종전 raw fetch 의 401 결함 차단.
    const res = await uploadLayoutAttachment(templateId, layoutName, file);
    if (res.ok) {
      setUrl(res.data.url);
      void loadAttachments();
    } else {
      setUploadError(t('layout_editor.control.background_image.upload_failed'));
    }
    setUploading(false);
    if (fileRef.current) fileRef.current.value = '';
  };

  // 인라인 썸네일 hover ✕ 삭제 — confirm 없이 즉시(관리 모달은 confirm 보유).
  const onThumbDelete = async (att: LayoutAttachment): Promise<void> => {
    const res = await deleteLayoutAttachment(att.id);
    if (res.ok) {
      setAttachments((prev) => prev.filter((a) => a.id !== att.id));
    }
  };

  // "이미지 관리" 링크 → 관리 모달. 모달 안에서 "배경으로 사용" 클릭 시 setUrl.
  const openManager = (): void => {
    const id = modal.open({
      ariaLabel: t('layout_editor.attachment_manager.title'),
      width: 720,
      maxHeightRatio: 0.82,
      content: React.createElement(LayoutAttachmentManager, {
        templateIdentifier: templateId,
        layoutName,
        t,
        onSelect: (url: string) => {
          setUrl(url);
          modal.close(id);
        },
        onChanged: () => {
          void loadAttachments();
        },
        onClose: () => modal.close(id),
      }),
    });
  };

  return (
    <div className="g7le-widget g7le-widget--image" data-testid="g7le-widget-image" style={wrap}>
      {current.url && (
        <div
          data-testid="g7le-image-preview"
          style={{
            ...preview,
            backgroundImage: `url(${current.url})`,
            backgroundSize: current.size ?? 'cover',
            backgroundRepeat: current.repeat ?? 'no-repeat',
            backgroundPosition: current.position ?? 'center',
          }}
        />
      )}

      <div style={row}>
        <label style={uploadBtn}>
          {uploading ? '…' : t('layout_editor.control.background_image.upload')}
          <input
            ref={fileRef}
            type="file"
            accept="image/*"
            data-testid="g7le-image-file"
            onChange={onFileChange}
            disabled={uploading}
            style={{ display: 'none' }}
          />
        </label>
        <button type="button" data-testid="g7le-image-clear" onClick={() => onChange(undefined)} style={clearBtn}>
          {t('layout_editor.control.background_image.clear')}
        </button>
      </div>

      <input
        type="text"
        data-testid="g7le-image-url"
        placeholder="https://… / URL"
        value={urlDraft}
        onChange={(e) => setUrlDraft(e.target.value)}
        onBlur={(e) => setUrl(e.target.value.trim() || undefined)}
        style={urlInput}
      />

      <div style={modeRow} data-testid="g7le-image-modes">
        {DISPLAY_MODES.map((m) => {
          const active = modeOf(current) === m.value;
          return (
            <button
              key={m.value}
              type="button"
              data-testid={`g7le-image-mode-${m.value}`}
              data-active={active ? 'true' : 'false'}
              disabled={!current.url}
              onClick={() => setMode(m.value)}
              style={{
                ...modeBtn,
                background: active && current.url ? '#2563eb' : '#fff',
                color: active && current.url ? '#fff' : '#0f172a',
              }}
            >
              {t(m.labelKey)}
            </button>
          );
        })}
      </div>

      {/* 인라인 미니 갤러리 — 현재 레이아웃 첨부 썸네일 가로 스트립 + 관리 링크 */}
      <div style={galleryWrap} data-testid="g7le-image-gallery">
        <div style={galleryHeader}>
          <span style={{ fontSize: 11, color: '#64748b' }}>
            {t('layout_editor.attachment_manager.recent')}
          </span>
          <button
            type="button"
            data-testid="g7le-image-manage"
            onClick={openManager}
            style={manageLink}
          >
            🖼 {t('layout_editor.attachment_manager.manage_link')}
          </button>
        </div>
        {attachments.length > 0 ? (
          <div style={thumbStrip} data-testid="g7le-image-thumbs">
            {attachments.slice(0, 8).map((att) => (
              <div key={att.id} style={thumbWrap} data-testid={`g7le-image-thumb-${att.id}`}>
                <button
                  type="button"
                  title={att.original_name}
                  onClick={() => setUrl(att.url)}
                  style={{ ...thumbBtn, backgroundImage: `url(${att.url})` }}
                  data-testid={`g7le-image-thumb-use-${att.id}`}
                />
                <button
                  type="button"
                  title={t('layout_editor.attachment_manager.delete')}
                  onClick={() => onThumbDelete(att)}
                  style={thumbDel}
                  data-testid={`g7le-image-thumb-del-${att.id}`}
                >
                  ✕
                </button>
              </div>
            ))}
          </div>
        ) : (
          <span style={{ fontSize: 11, color: '#94a3b8' }}>
            {t('layout_editor.attachment_manager.empty')}
          </span>
        )}
      </div>

      {uploadError && (
        <span data-testid="g7le-image-error" style={{ fontSize: 11, color: '#dc2626' }}>
          {uploadError}
        </span>
      )}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6 };
const preview: React.CSSProperties = { width: '100%', height: 80, borderRadius: 6, border: '1px solid #cbd5e1' };
const row: React.CSSProperties = { display: 'flex', gap: 6 };
const uploadBtn: React.CSSProperties = { padding: '5px 10px', fontSize: 12, border: '1px solid #2563eb', borderRadius: 6, background: '#fff', color: '#2563eb', cursor: 'pointer', display: 'inline-flex', alignItems: 'center' };
const clearBtn: React.CSSProperties = { padding: '5px 10px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#64748b', cursor: 'pointer' };
const urlInput: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const modeRow: React.CSSProperties = { display: 'inline-flex', border: '1px solid #cbd5e1', borderRadius: 6, overflow: 'hidden' };
const modeBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 12, border: 'none', borderRight: '1px solid #e2e8f0', cursor: 'pointer' };
const galleryWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, border: '1px solid #e2e8f0', borderRadius: 6, padding: 6, marginTop: 2 };
const galleryHeader: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between' };
const manageLink: React.CSSProperties = { fontSize: 11, color: '#2563eb', background: 'transparent', border: 'none', cursor: 'pointer', padding: 0 };
const thumbStrip: React.CSSProperties = { display: 'flex', gap: 6, flexWrap: 'wrap' };
const thumbWrap: React.CSSProperties = { position: 'relative', width: 44, height: 44 };
const thumbBtn: React.CSSProperties = { width: 44, height: 44, borderRadius: 6, border: '1px solid #cbd5e1', backgroundSize: 'cover', backgroundPosition: 'center', cursor: 'pointer', padding: 0 };
const thumbDel: React.CSSProperties = { position: 'absolute', top: -6, right: -6, width: 16, height: 16, borderRadius: '50%', border: 'none', background: '#dc2626', color: '#fff', fontSize: 9, lineHeight: '16px', cursor: 'pointer', padding: 0 };
