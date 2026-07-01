/**
 * LayoutAttachmentManager.tsx — 레이아웃 이미지 관리 모달
 *
 * 현재 레이아웃의 첨부 이미지(`template_layout_attachments`)를 그리드로 보여 주고
 * 업로드/삭제/배경 적용을 제공한다. 두 진입점이 본 컴포넌트를 공유한다:
 *  1. 배경이미지 컨트롤(ImagePickerControl)의 "이미지 관리" 링크 — `onSelect` 보유 →
 *     각 항목에 "배경으로 사용" 버튼 표시(클릭 시 현재 노드 배경 설정 후 닫기).
 *  2. 상단 툴바 `🖼 이미지` 버튼 — `onSelect` 미전달 → "배경으로 사용" 숨김
 *     (선택 노드 무관, 삭제/업로드/조회만).
 *
 * 모든 요청은 공용 `layoutAttachments` 클라이언트(Authorization: Bearer 첨부)로 한다.
 * 편집기 코어 위젯이므로 `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  listLayoutAttachments,
  uploadLayoutAttachment,
  deleteLayoutAttachment,
  type LayoutAttachment,
} from '../../utils/layoutAttachments';

export interface LayoutAttachmentManagerProps {
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /** 현재 레이아웃 이름 (목록 필터 + 업로드 대상) */
  layoutName: string;
  /** 다국어 해석 함수 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * "배경으로 사용" 콜백 — 배경이미지 컨트롤 진입 시 전달. 미전달(툴바 진입) 시
   * "배경으로 사용" 버튼을 숨긴다.
   */
  onSelect?: (url: string) => void;
  /** 목록 변경(업로드/삭제) 시 호출 — 호출자(인라인 갤러리)가 자기 목록 갱신 */
  onChanged?: () => void;
  /** 모달 닫기 */
  onClose: () => void;
}

type LoadState = 'idle' | 'loading' | 'error';

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatDate(iso?: string): string {
  if (!iso) return '';
  // ISO-8601 → `MM-DD` (로케일 무관 단순 표기 — 편집기 UI)
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[2]}-${m[3]}` : '';
}

export function LayoutAttachmentManager({
  templateIdentifier,
  layoutName,
  t,
  onSelect,
  onChanged,
  onClose,
}: LayoutAttachmentManagerProps): React.ReactElement {
  const [items, setItems] = useState<LayoutAttachment[]>([]);
  const [loadState, setLoadState] = useState<LoadState>('idle');
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement | null>(null);

  const reload = useCallback(async (): Promise<void> => {
    setLoadState('loading');
    setError(null);
    const res = await listLayoutAttachments(templateIdentifier, layoutName);
    if (res.ok) {
      setItems(res.data);
      setLoadState('idle');
    } else {
      setError(res.message);
      setLoadState('error');
    }
  }, [templateIdentifier, layoutName]);

  useEffect(() => {
    void reload();
  }, [reload]);

  const onUpload = async (e: React.ChangeEvent<HTMLInputElement>): Promise<void> => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    setError(null);
    const res = await uploadLayoutAttachment(templateIdentifier, layoutName, file);
    if (res.ok) {
      // 목록 prepend — 즉시 반영 후 서버 목록과 정합화
      setItems((prev) => [res.data, ...prev.filter((a) => a.id !== res.data.id)]);
      onChanged?.();
    } else {
      setError(res.message);
    }
    setUploading(false);
    if (fileRef.current) fileRef.current.value = '';
  };

  const onDelete = async (att: LayoutAttachment): Promise<void> => {
    // 편집기 코어 위젯 — 브라우저 confirm 사용(별도 confirm 모달 인프라 비종속).
    const ok =
      typeof window !== 'undefined' && typeof window.confirm === 'function'
        ? window.confirm(t('layout_editor.attachment_manager.delete_confirm'))
        : true;
    if (!ok) return;
    const res = await deleteLayoutAttachment(att.id);
    if (res.ok) {
      setItems((prev) => prev.filter((a) => a.id !== att.id));
      onChanged?.();
    } else {
      setError(res.message);
    }
  };

  return (
    <div className="g7le-attachment-manager" data-testid="g7le-attachment-manager" style={wrap}>
      <div data-modal-drag-handle style={header}>
        <span style={{ fontSize: 14, fontWeight: 700 }} data-testid="g7le-attachment-manager-title">
          {t('layout_editor.attachment_manager.title')}
        </span>
        <button type="button" aria-label="close" onClick={onClose} style={closeBtn} data-testid="g7le-attachment-manager-close">
          ✕
        </button>
      </div>

      <div style={toolbar}>
        <label style={uploadBtn} data-testid="g7le-attachment-upload">
          {uploading ? '…' : `⬆ ${t('layout_editor.attachment_manager.upload')}`}
          <input
            ref={fileRef}
            type="file"
            accept="image/*"
            onChange={onUpload}
            disabled={uploading}
            style={{ display: 'none' }}
            data-testid="g7le-attachment-upload-input"
          />
        </label>
      </div>

      {error && (
        <div style={errorBar} data-testid="g7le-attachment-manager-error">
          {error}
        </div>
      )}

      <div style={body}>
        {loadState === 'loading' ? (
          <div style={hint} data-testid="g7le-attachment-manager-loading">
            {t('layout_editor.attachment_manager.loading')}
          </div>
        ) : items.length === 0 && loadState !== 'error' ? (
          <div style={hint} data-testid="g7le-attachment-manager-empty">
            {t('layout_editor.attachment_manager.empty')}
          </div>
        ) : (
          <div style={grid} data-testid="g7le-attachment-grid">
            {items.map((att) => (
              <div key={att.id} style={card} data-testid={`g7le-attachment-card-${att.id}`}>
                <div style={{ ...cardThumb, backgroundImage: `url(${att.url})` }} />
                <div style={cardName} title={att.original_name}>
                  {att.original_name}
                </div>
                <div style={cardMeta}>
                  {formatSize(att.size)}
                  {att.created_at ? ` · ${formatDate(att.created_at)}` : ''}
                </div>
                <div style={cardActions}>
                  {onSelect && (
                    <button
                      type="button"
                      onClick={() => onSelect(att.url)}
                      style={useBgBtn}
                      data-testid={`g7le-attachment-use-${att.id}`}
                    >
                      {t('layout_editor.attachment_manager.use_as_bg')}
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={() => onDelete(att)}
                    style={delBtn}
                    data-testid={`g7le-attachment-delete-${att.id}`}
                  >
                    🗑
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <div style={footer}>
        <button type="button" onClick={onClose} style={footerCloseBtn} data-testid="g7le-attachment-manager-footer-close">
          {t('layout_editor.attachment_manager.close')}
        </button>
      </div>
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', minHeight: 0, flex: 1 };
const header: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', borderBottom: '1px solid #e2e8f0' };
const closeBtn: React.CSSProperties = { border: 'none', background: 'transparent', fontSize: 16, color: '#94a3b8', cursor: 'pointer' };
const toolbar: React.CSSProperties = { padding: '10px 16px', borderBottom: '1px solid #f1f5f9', display: 'flex', gap: 8 };
const uploadBtn: React.CSSProperties = { padding: '6px 12px', fontSize: 13, border: '1px solid #2563eb', borderRadius: 6, background: '#fff', color: '#2563eb', cursor: 'pointer', display: 'inline-flex', alignItems: 'center' };
const errorBar: React.CSSProperties = { padding: '8px 16px', fontSize: 12, color: '#b91c1c', background: '#fef2f2' };
const body: React.CSSProperties = { padding: 16, overflow: 'auto', flex: 1, minHeight: 120 };
const hint: React.CSSProperties = { fontSize: 13, color: '#94a3b8', textAlign: 'center', padding: '32px 0' };
const grid: React.CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 12 };
const card: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, border: '1px solid #e2e8f0', borderRadius: 8, padding: 8 };
const cardThumb: React.CSSProperties = { width: '100%', height: 88, borderRadius: 6, border: '1px solid #cbd5e1', backgroundSize: 'cover', backgroundPosition: 'center', backgroundColor: '#f8fafc' };
const cardName: React.CSSProperties = { fontSize: 12, fontWeight: 600, color: '#0f172a', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' };
const cardMeta: React.CSSProperties = { fontSize: 11, color: '#64748b' };
const cardActions: React.CSSProperties = { display: 'flex', gap: 6, marginTop: 2 };
const useBgBtn: React.CSSProperties = { flex: 1, padding: '4px 6px', fontSize: 11, border: '1px solid #2563eb', borderRadius: 6, background: '#2563eb', color: '#fff', cursor: 'pointer' };
const delBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px solid #fecaca', borderRadius: 6, background: '#fff', color: '#dc2626', cursor: 'pointer' };
const footer: React.CSSProperties = { padding: '10px 16px', borderTop: '1px solid #e2e8f0', display: 'flex', justifyContent: 'flex-end' };
const footerCloseBtn: React.CSSProperties = { padding: '6px 14px', fontSize: 13, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
