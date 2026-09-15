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
import type { EditorControlSpec } from '../../spec/specTypes';
import { useBoundValueGuard, BoundValueNotice, BoundValueRestore } from './boundValueGuard';
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

/**
 * 이 컨트롤의 `apply` 가 **값 슬롯을 하나만** 가지는가.
 *
 * 그렇다면 `size`/`repeat`/`position` 을 저장할 곳이 없으므로 표시모드(채움/맞춤/타일)
 * 버튼은 죽은 컨트롤이다 — 눌러도 저장되지 않는데 눌리는 표면이 남으면 "고칠 수 있는
 * 것처럼 보이지만 저장되지 않는" 상태가 된다.
 *
 * 판정 대상은 **control-level apply + 모든 option-level apply 의 합집합**이다.
 * `control.apply` 만 보면 옵션-only 컨트롤에서 `undefined` → 보류 → 표시로 조용히
 * 잘못된 결론에 도달한다.
 *
 * **화이트리스트가 아니라 블랙리스트다** — `apply` 미선언(합집합이 공집합)이면 판정을
 * 보류하고 `false`(현행대로 표시)를 돌려준다. 화이트리스트("bundle 일 때만 표시")로
 * 짜면 apply 없이 렌더되는 기존 계약이 깨진다.
 */
function hasSingleValueSlot(control: EditorControlSpec | undefined): boolean {
  if (!control) return false;
  const applies: Array<{ type?: unknown; props?: unknown }> = [];
  const own = control.apply as unknown;
  if (own && typeof own === 'object') applies.push(own as { type?: unknown; props?: unknown });
  for (const opt of (control.options ?? []) as Array<{ apply?: unknown }>) {
    if (opt && typeof opt === 'object' && opt.apply && typeof opt.apply === 'object') {
      applies.push(opt.apply as { type?: unknown; props?: unknown });
    }
  }
  if (applies.length === 0) return false; // 판정 보류 — 현행 동작 보존
  return applies.every(
    (a) =>
      a.type === 'propValue' ||
      a.type === 'cssVar' ||
      (a.type === 'styleProp' && !Array.isArray(a.props)),
  );
}

export function ImagePickerControl({ control, value, onChange, t }: WidgetProps): React.ReactElement {
  const current = readValue(value);
  /** 단일 값 슬롯 — size/repeat/position 을 저장할 자리가 없다 */
  const singleSlot = hasSingleValueSlot(control);
  /**
   * 데이터 연결 값 보호 — 판정·배지·해제·복구를 공용 프리미티브에 위임한다.
   * 위젯마다 복붙하면 한 곳이 빠져도 오류가 나지 않고 그 한 곳이 우회로가 된다.
   */
  const guard = useBoundValueGuard(
    value,
    (original) => onChange({ url: original }),
    (v) => readValue(v).url,
  );
  const bound = guard.bound;
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
    // 단일 값 슬롯 — size/repeat/position 은 저장될 자리가 없다. 만들지 않으면
    // 엔진 축약(scalarizeImageValue)이 버릴 것도 없어 라운드트립이 애초에 대칭이 된다.
    if (singleSlot) {
      onChange({ url });
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
      {/* 데이터 연결 값 — 미리보기는 깨지므로 원문 배지로 대체한다(거짓 미리보기 금지) */}
      {bound ? (
        <BoundValueNotice
          expression={current.url ?? ''}
          t={t}
          onReplace={guard.beginReplace}
          testIdPrefix="g7le-image"
        />
      ) : (
        current.url && (
          <div
            data-testid="g7le-image-preview"
            style={{
              ...preview,
              backgroundImage: `url(${current.url})`,
              // 단일 슬롯에서는 size/repeat/position 이 저장되지 않는다. 저장되지도 않는
              // current.size 를 미리보기에 반영하면 거짓 미리보기가 된다 — 실제 표시 방식은
              // 소비 컴포넌트(<Img>)의 클래스가 정하므로 편집기가 흉내낼 수 없다.
              backgroundSize: singleSlot ? 'contain' : current.size ?? 'cover',
              backgroundRepeat: singleSlot ? 'no-repeat' : current.repeat ?? 'no-repeat',
              backgroundPosition: singleSlot ? 'center' : current.position ?? 'center',
            }}
          />
        )
      )}

      <div style={row}>
        <label style={{ ...uploadBtn, ...(bound ? disabledBtn : null) }}>
          {uploading ? '…' : t('layout_editor.control.background_image.upload')}
          <input
            ref={fileRef}
            type="file"
            accept="image/*"
            data-testid="g7le-image-file"
            onChange={onFileChange}
            disabled={uploading || bound}
            style={{ display: 'none' }}
          />
        </label>
        <button
          type="button"
          data-testid="g7le-image-clear"
          disabled={bound}
          onClick={() => onChange(undefined)}
          style={{ ...clearBtn, ...(bound ? disabledBtn : null) }}
        >
          {t('layout_editor.control.background_image.clear')}
        </button>
      </div>

      <input
        type="text"
        data-testid="g7le-image-url"
        placeholder="https://… / URL"
        value={urlDraft}
        // 데이터 연결 값은 원문을 보이되 편집을 막는다 — 지워지지 않고 남는다.
        readOnly={bound}
        onChange={(e) => setUrlDraft(e.target.value)}
        onBlur={(e) => {
          if (bound) return;
          setUrl(e.target.value.trim() || undefined);
        }}
        style={urlInput}
      />

      {/* 보호를 해제한 동안의 편도 방지 — 아직 안 바꿨으면 취소로, 이미 값을 넣었으면
          원래 연결값 복구로 동작한다. 이 경로가 없으면 실수로 한 번 누른 운영자가
          원문을 되찾을 방법이 없다. */}
      {guard.replacing && guard.original !== null && (
        <BoundValueRestore t={t} onRestore={guard.restore} testIdPrefix="g7le-image" />
      )}

      {/* 단일 값 슬롯이면 표시모드는 저장될 자리가 없다 — 컨테이너째 미렌더.
          `disabled` 로 두면 "URL 을 넣으면 살아나겠지"라는 거짓 정보를 준다
          (기존 `disabled={!current.url}` 은 일시적 비활성이라 의미가 다르다). */}
      {!singleSlot && (
        <div style={modeRow} data-testid="g7le-image-modes">
          {DISPLAY_MODES.map((m) => {
            const active = modeOf(current) === m.value;
            return (
              <button
                key={m.value}
                type="button"
                data-testid={`g7le-image-mode-${m.value}`}
                data-active={active ? 'true' : 'false'}
                disabled={!current.url || bound}
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
      )}

      {/* 인라인 미니 갤러리 — 현재 레이아웃 첨부 썸네일 가로 스트립 + 관리 링크 */}
      <div style={galleryWrap} data-testid="g7le-image-gallery">
        <div style={galleryHeader}>
          <span style={{ fontSize: 11, color: '#64748b' }}>
            {t('layout_editor.attachment_manager.recent')}
          </span>
          {/* 관리 모달은 각 카드에 「배경」 버튼을 띄우고 그 클릭이 같은 setUrl 을 부른다 —
              인라인 썸네일 「사용」과 동일 동작의 다른 렌더 위치다. 하나만 잠그면
              나머지 하나가 조용한 우회로가 되므로 진입 자체를 함께 잠근다. */}
          <button
            type="button"
            data-testid="g7le-image-manage"
            disabled={bound}
            onClick={openManager}
            style={{ ...manageLink, ...(bound ? disabledBtn : null) }}
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
                  disabled={bound}
                  onClick={() => setUrl(att.url)}
                  style={{
                    ...thumbBtn,
                    backgroundImage: `url(${att.url})`,
                    ...(bound ? disabledBtn : null),
                  }}
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
const disabledBtn: React.CSSProperties = { opacity: 0.45, cursor: 'not-allowed' };
const modeRow: React.CSSProperties = { display: 'inline-flex', border: '1px solid #cbd5e1', borderRadius: 6, overflow: 'hidden' };
const modeBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 12, border: 'none', borderRight: '1px solid #e2e8f0', cursor: 'pointer' };
const galleryWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, border: '1px solid #e2e8f0', borderRadius: 6, padding: 6, marginTop: 2 };
const galleryHeader: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between' };
const manageLink: React.CSSProperties = { fontSize: 11, color: '#2563eb', background: 'transparent', border: 'none', cursor: 'pointer', padding: 0 };
const thumbStrip: React.CSSProperties = { display: 'flex', gap: 6, flexWrap: 'wrap' };
const thumbWrap: React.CSSProperties = { position: 'relative', width: 44, height: 44 };
const thumbBtn: React.CSSProperties = { width: 44, height: 44, borderRadius: 6, border: '1px solid #cbd5e1', backgroundSize: 'cover', backgroundPosition: 'center', cursor: 'pointer', padding: 0 };
const thumbDel: React.CSSProperties = { position: 'absolute', top: -6, right: -6, width: 16, height: 16, borderRadius: '50%', border: 'none', background: '#dc2626', color: '#fff', fontSize: 9, lineHeight: '16px', cursor: 'pointer', padding: 0 };
