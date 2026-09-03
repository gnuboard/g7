/**
 * CustomAssetsModal.tsx — 사용자 추가 에셋(`custom/`) 관리 모달
 *
 * 운영자가 확장의 `custom/` 디렉토리에 자기 CSS·JS 를 넣고 고치고, 그 CSS 가 참조할
 * 폰트·이미지를 올린다. 종전에는 FTP 나 서버 셸이 유일한 경로였다 — 그 접근이 없는
 * 운영자에게는 기능 자체가 없는 것과 같았다.
 *
 * 권한(`core.extensions.custom_assets.manage`)은 서버가 판정한다. 403 은 오류 문구가
 * 아니라 **안내**로 바꿔 보여 준다 — "HTTP 403" 만 띄우면 운영자는 무엇이 부족한지
 * 알 수 없다.
 *
 * 대상은 편집 중인 템플릿뿐 아니라 **활성 모듈·플러그인**도 고를 수 있다. 같은 기능이
 * 확장 타입에 따라 화면에서 되고 안 되면 운영자는 그 이유를 알 수 없고, 모듈·플러그인
 * `custom/` 은 그동안 FTP 말고 경로가 없었다.
 *
 * 편집기 코어 위젯이므로 `g7le-*` + 인라인 스타일만 쓴다(CSS 라이브러리 비종속).
 * 자산이 깨진 상황에서도 이 화면만은 떠야 하기 때문이다.
 *
 * @since engine-v1.63.0
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  useCustomAssets,
  type CustomAssetFile,
  type CustomAssetTarget,
} from '../hooks/useCustomAssets';

export interface CustomAssetsModalProps {
  /** 편집 대상 템플릿 식별자 — 대상 목록의 기본 선택 */
  templateIdentifier: string;
  /** 다국어 해석 함수 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 모달 닫기 */
  onClose: () => void;
}

const wrap: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  height: '100%',
  minHeight: 0,
};

const header: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 8,
  padding: '12px 16px',
  borderBottom: '1px solid #e2e8f0',
  background: '#f8fafc',
  fontWeight: 600,
  color: '#0f172a',
};

const closeBtn: React.CSSProperties = {
  marginLeft: 'auto',
  border: 'none',
  background: 'transparent',
  fontSize: 16,
  color: '#64748b',
  cursor: 'pointer',
  outline: 'none',
};

const body: React.CSSProperties = {
  flex: 1,
  minHeight: 0,
  display: 'flex',
};

const listPane: React.CSSProperties = {
  width: 260,
  flexShrink: 0,
  borderRight: '1px solid #e2e8f0',
  overflowY: 'auto',
  padding: 8,
};

const editPane: React.CSSProperties = {
  flex: 1,
  minWidth: 0,
  display: 'flex',
  flexDirection: 'column',
  padding: 12,
  gap: 8,
};

const rowBase: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 6,
  width: '100%',
  padding: '6px 8px',
  borderRadius: 6,
  borderWidth: 1,
  borderStyle: 'solid',
  borderColor: 'transparent',
  backgroundColor: 'transparent',
  fontSize: 12,
  color: '#334155',
  cursor: 'pointer',
  textAlign: 'left',
};

const rowSelected: React.CSSProperties = {
  ...rowBase,
  backgroundColor: '#eff6ff',
  borderColor: '#bfdbfe',
  color: '#1d4ed8',
  fontWeight: 600,
};

const noticeBox: React.CSSProperties = {
  margin: 16,
  padding: 16,
  borderRadius: 8,
  border: '1px solid #fcd34d',
  background: '#fffbeb',
  color: '#92400e',
  fontSize: 13,
  lineHeight: 1.7,
};

const errorBar: React.CSSProperties = {
  padding: '8px 12px',
  borderRadius: 6,
  border: '1px solid #fecaca',
  background: '#fef2f2',
  color: '#b91c1c',
  fontSize: 12,
};

const textarea: React.CSSProperties = {
  flex: 1,
  minHeight: 220,
  width: '100%',
  boxSizing: 'border-box',
  padding: 10,
  borderRadius: 6,
  borderWidth: 1,
  borderStyle: 'solid',
  borderColor: '#cbd5e1',
  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
  fontSize: 12,
  lineHeight: 1.6,
  resize: 'vertical',
};

const actionRow: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 8,
  flexWrap: 'wrap',
};

const button: React.CSSProperties = {
  padding: '6px 12px',
  borderRadius: 6,
  borderWidth: 1,
  borderStyle: 'solid',
  borderColor: '#cbd5e1',
  backgroundColor: '#ffffff',
  color: '#334155',
  fontSize: 12,
  cursor: 'pointer',
};

const primary: React.CSSProperties = {
  ...button,
  backgroundColor: '#2563eb',
  borderColor: '#2563eb',
  color: '#ffffff',
  fontWeight: 600,
};

const danger: React.CSSProperties = {
  ...button,
  borderColor: '#fca5a5',
  color: '#b91c1c',
};

const inputStyle: React.CSSProperties = {
  ...button,
  cursor: 'text',
  minWidth: 200,
};

const selectStyle: React.CSSProperties = {
  ...button,
  cursor: 'pointer',
  minWidth: 220,
};

/**
 * 확장 표시 이름을 뽑습니다 (다국어 객체 또는 문자열).
 *
 * @param value display_name 값
 * @param fallback 이름이 없을 때 쓸 식별자
 * @returns 표시 이름
 */
function displayNameOf(value: unknown, fallback: string): string {
  if (typeof value === 'string' && value !== '') return value;

  if (value && typeof value === 'object') {
    const map = value as Record<string, unknown>;
    const locale = (window as any)?.G7Config?.locale ?? 'ko';
    const picked = map[locale] ?? map.ko ?? map.en ?? Object.values(map)[0];

    if (typeof picked === 'string' && picked !== '') return picked;
  }

  return fallback;
}

/**
 * 관리 가능한 대상 목록을 만듭니다 — 편집 중인 템플릿 + 활성 모듈·플러그인.
 *
 * **활성** 확장만 싣는다. 자산 서빙이 활성 확장에만 응답하므로, 비활성 확장의 파일을
 * 넣어 봐야 저장은 되는데 화면에는 영영 나타나지 않는다.
 *
 * @param templateIdentifier 편집 중인 템플릿 식별자
 * @returns 대상 목록 (템플릿 → 모듈 → 플러그인)
 */
function buildTargets(
  templateIdentifier: string,
): Array<CustomAssetTarget & { label: string }> {
  const config = (typeof window !== 'undefined' ? (window as any).G7Config : null) ?? {};
  const list: Array<CustomAssetTarget & { label: string }> = [
    { type: 'template', identifier: templateIdentifier, label: templateIdentifier },
  ];

  for (const [type, rows] of [
    ['module', config.activeModules],
    ['plugin', config.activePlugins],
  ] as const) {
    if (!Array.isArray(rows)) continue;

    for (const row of rows) {
      const identifier = typeof row?.identifier === 'string' ? row.identifier : '';

      if (!identifier) continue;

      list.push({
        type,
        identifier,
        label: displayNameOf(row?.display_name, identifier),
      });
    }
  }

  return list;
}

/**
 * 바이트를 읽기 쉬운 크기로 바꿉니다.
 *
 * @param bytes 바이트
 * @returns 표시 문자열
 */
function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function CustomAssetsModal(props: CustomAssetsModalProps): React.ReactElement {
  const { templateIdentifier, t, onClose } = props;

  const targets = useMemo(() => buildTargets(templateIdentifier), [templateIdentifier]);
  const [targetKey, setTargetKey] = useState(`template:${templateIdentifier}`);
  const target = useMemo(
    () => targets.find((x) => `${x.type}:${x.identifier}` === targetKey) ?? targets[0],
    [targets, targetKey],
  );

  const assets = useCustomAssets({ type: target.type, identifier: target.identifier });
  const { reload } = assets;

  const [selected, setSelected] = useState<CustomAssetFile | null>(null);
  const [draft, setDraft] = useState('');
  const [newPath, setNewPath] = useState('');
  const [isBusy, setIsBusy] = useState(false);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    void reload();
  }, [reload]);

  // 대상이 바뀌면 이전 확장의 선택·초안을 버린다. 남겨 두면 A 확장에서 열어 둔 본문을
  // B 확장에 저장하게 되고, 경로가 유효하면 서버는 정상 200 으로 받아들인다.
  useEffect(() => {
    setSelected(null);
    setDraft('');
    setNewPath('');
  }, [targetKey]);

  const openFile = useCallback(
    async (file: CustomAssetFile) => {
      setSelected(file);

      if (!file.editable) {
        setDraft('');

        return;
      }

      setIsBusy(true);
      const content = await assets.read(file.path);
      setIsBusy(false);
      setDraft(content ?? '');
    },
    [assets],
  );

  const handleSave = useCallback(async () => {
    const path = selected?.path ?? newPath.trim();

    if (!path) return;

    setIsBusy(true);
    const ok = await assets.save(path, draft);
    setIsBusy(false);

    if (ok && !selected) {
      setNewPath('');
      setSelected({
        path,
        name: path.split('/').pop() ?? path,
        extension: (path.split('.').pop() ?? '').toLowerCase(),
        size: draft.length,
        modified_at: new Date().toISOString(),
        editable: true,
        loaded: true,
      });
    }
  }, [assets, selected, newPath, draft]);

  const handleDelete = useCallback(async () => {
    if (!selected) return;

    // 되돌릴 수 없는 조작이라 확인을 받는다. 편집기 코어 위젯이므로 모달 스택을
    // 더 쌓지 않고 브라우저 확인을 쓴다(나가기·템플릿 전환과 같은 방식).
    if (typeof window !== 'undefined' && !window.confirm(t('layout_editor.custom_assets.delete_confirm'))) {
      return;
    }

    setIsBusy(true);
    const ok = await assets.remove(selected.path);
    setIsBusy(false);

    if (ok) {
      setSelected(null);
      setDraft('');
    }
  }, [assets, selected, t]);

  const handleUpload = useCallback(
    async (event: React.ChangeEvent<HTMLInputElement>) => {
      const file = event.target.files?.[0];

      if (!file) return;

      setIsBusy(true);
      await assets.upload(file);
      setIsBusy(false);

      // 같은 파일을 다시 고를 수 있도록 입력을 비운다 (change 이벤트가 재발화되지 않는다)
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    },
    [assets],
  );

  return (
    <div className="g7le-custom-assets" style={wrap} data-testid="g7le-custom-assets-modal">
      <div style={header}>
        <span>🎨 {t('layout_editor.custom_assets.title')}</span>
        <select
          value={targetKey}
          onChange={(event) => setTargetKey(event.target.value)}
          style={selectStyle}
          aria-label={t('layout_editor.custom_assets.target_label')}
          data-testid="g7le-custom-assets-target"
        >
          {targets.map((option) => (
            <option key={`${option.type}:${option.identifier}`} value={`${option.type}:${option.identifier}`}>
              {t(`layout_editor.custom_assets.type_${option.type}`)} · {option.label}
            </option>
          ))}
        </select>
        <button
          type="button"
          style={closeBtn}
          onClick={onClose}
          aria-label={t('layout_editor.custom_assets.close')}
          data-testid="g7le-custom-assets-close"
        >
          ✕
        </button>
      </div>

      {assets.forbidden ? (
        <div style={noticeBox} data-testid="g7le-custom-assets-forbidden">
          {t('layout_editor.custom_assets.forbidden')}
        </div>
      ) : (
        <div style={body}>
          <div style={listPane} data-testid="g7le-custom-assets-list">
            {assets.isLoading && assets.files.length === 0 ? (
              <div style={{ padding: 12, color: '#94a3b8', fontSize: 12 }}>
                {t('layout_editor.custom_assets.loading')}
              </div>
            ) : null}

            {!assets.isLoading && assets.files.length === 0 ? (
              <div style={{ padding: 12, color: '#94a3b8', fontSize: 12 }}>
                {t('layout_editor.custom_assets.empty')}
              </div>
            ) : null}

            {assets.files.map((file) => (
              <button
                key={file.path}
                type="button"
                style={selected?.path === file.path ? rowSelected : rowBase}
                onClick={() => void openFile(file)}
                data-testid={`g7le-custom-asset-row-${file.path}`}
                data-loaded={file.loaded ? 'true' : 'false'}
              >
                <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {file.path}
                </span>
                <span style={{ color: '#94a3b8', fontSize: 11 }}>{formatSize(file.size)}</span>
              </button>
            ))}
          </div>

          <div style={editPane}>
            {assets.error ? (
              <div style={errorBar} data-testid="g7le-custom-assets-error">
                {assets.error}
              </div>
            ) : null}

            <div style={actionRow}>
              <input
                type="text"
                value={selected ? selected.path : newPath}
                readOnly={!!selected}
                onChange={(event) => setNewPath(event.target.value)}
                placeholder={t('layout_editor.custom_assets.new_path_placeholder')}
                style={inputStyle}
                aria-label={t('layout_editor.custom_assets.path_label')}
                data-testid="g7le-custom-assets-path"
              />
              {selected ? (
                <button
                  type="button"
                  style={button}
                  onClick={() => {
                    setSelected(null);
                    setDraft('');
                  }}
                  data-testid="g7le-custom-assets-new"
                >
                  {t('layout_editor.custom_assets.new_file')}
                </button>
              ) : null}
            </div>

            {selected && !selected.editable ? (
              <div style={{ color: '#64748b', fontSize: 12, lineHeight: 1.7 }}>
                {t('layout_editor.custom_assets.binary_hint', { extension: selected.extension })}
              </div>
            ) : (
              <textarea
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                style={textarea}
                spellCheck={false}
                aria-label={t('layout_editor.custom_assets.content_label')}
                data-testid="g7le-custom-assets-content"
              />
            )}

            <div style={actionRow}>
              <button
                type="button"
                style={primary}
                disabled={isBusy || (selected ? !selected.editable : newPath.trim() === '')}
                onClick={() => void handleSave()}
                data-testid="g7le-custom-assets-save"
              >
                {t('layout_editor.custom_assets.save')}
              </button>
              <button
                type="button"
                style={button}
                disabled={isBusy}
                onClick={() => fileInputRef.current?.click()}
                data-testid="g7le-custom-assets-upload"
              >
                {t('layout_editor.custom_assets.upload')}
              </button>
              <input
                ref={fileInputRef}
                type="file"
                style={{ display: 'none' }}
                onChange={(event) => void handleUpload(event)}
                data-testid="g7le-custom-assets-file-input"
              />
              <button
                type="button"
                style={danger}
                disabled={isBusy || !selected}
                onClick={() => void handleDelete()}
                data-testid="g7le-custom-assets-delete"
              >
                {t('layout_editor.custom_assets.delete')}
              </button>
              <span style={{ color: '#94a3b8', fontSize: 11 }}>
                {t('layout_editor.custom_assets.apply_hint')}
              </span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
