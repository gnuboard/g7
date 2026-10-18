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
 * **편집 버퍼는 파일마다 따로다.** 종전에는 `draft` 문자열 한 벌이라 파일을 바꾸면 앞 파일의
 * 저장하지 않은 편집이 경고 없이 사라졌고(D2), 대상 확장을 바꿀 때도 묻지 않고 버렸다(D3).
 * 지금은 `Map<path, buffer>` 가 편집 값을 소유하고, 서버 본문은 **버퍼가 깨끗할 때만** 덮는다.
 *
 * @since engine-v1.63.0
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  useCustomAssets,
  type CustomAssetFile,
  type CustomAssetSearchHit,
  type CustomAssetTarget,
} from '../hooks/useCustomAssets';
import {
  buildPathTree,
  filterPathTree,
  highlightMatch,
  type PathTreeNode,
} from '../shared/treeSearch';
import { useEditorDialog } from './EditorDialog';

/** 열린 파일을 클라이언트에서 훑을 때의 상한 — 서버 검색(`CustomAssetService::search`)과 같은 값. */
const MAX_LOCAL_MATCHES_PER_FILE = 20;
const MAX_LOCAL_PREVIEW_CHARS = 200;

/**
 * 열린 파일의 **현재 본문**을 검색어로 훑는다 (계획 §4.7 — 이미 열려 있는 파일은 클라이언트가 즉시 찾는다).
 *
 * 서버 검색은 저장된 본문만 보므로, 저장하지 않은 편집분은 여기서만 찾을 수 있다. 좌표(1-base)·
 * 상한·preview 절단은 서버 응답과 같은 규약이라 결과 행이 같은 모양으로 트리에 얹힌다.
 *
 * @since engine-v1.71.0
 */
function scanBufferForHits(path: string, value: string, needle: string): CustomAssetSearchHit {
  const lines: CustomAssetSearchHit['lines'] = [];
  const rows = value.split('\n');

  for (let i = 0; i < rows.length && lines.length < MAX_LOCAL_MATCHES_PER_FILE; i += 1) {
    const column = rows[i].toLocaleLowerCase().indexOf(needle);

    if (column === -1) {
      continue;
    }

    const preview = rows[i].trim();

    lines.push({
      line: i + 1,
      column: column + 1,
      preview:
        preview.length > MAX_LOCAL_PREVIEW_CHARS ? `${preview.slice(0, MAX_LOCAL_PREVIEW_CHARS)}…` : preview,
    });
  }

  return { path, name_matched: path.toLocaleLowerCase().includes(needle), match_count: lines.length, lines };
}

export interface CustomAssetsModalProps {
  /** 편집 대상 템플릿 식별자 — 대상 목록의 기본 선택 */
  templateIdentifier: string;
  /** 다국어 해석 함수 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 모달 닫기 */
  onClose: () => void;
  /**
   * 창 바깥(백드롭) 클릭에 쓸 닫기 요청을 등록한다 — 등록한 함수는 저장하지 않은 편집을 확인한 뒤 닫는다.
   * 창이 사라지면 null 로 풀어 준다.
   */
  onRegisterCloseRequest?: (handler: (() => void) | null) => void;
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

const treeRowBase: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: 6,
  width: '100%',
  padding: '5px 8px',
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

const folderRow: React.CSSProperties = {
  ...treeRowBase,
  color: '#475569',
  fontWeight: 600,
  cursor: 'pointer',
};

const dirtyDot: React.CSSProperties = {
  width: 7,
  height: 7,
  borderRadius: '50%',
  background: '#f59e0b',
  flexShrink: 0,
};

const searchInput: React.CSSProperties = {
  width: '100%',
  boxSizing: 'border-box',
  padding: '6px 8px',
  marginBottom: 6,
  borderRadius: 6,
  borderWidth: 1,
  borderStyle: 'solid',
  borderColor: '#cbd5e1',
  fontSize: 12,
  color: '#334155',
};

const gutterStyle: React.CSSProperties = {
  padding: '10px 8px',
  textAlign: 'right',
  color: '#94a3b8',
  background: '#f8fafc',
  borderRight: '1px solid #e2e8f0',
  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
  fontSize: 12,
  lineHeight: 1.6,
  userSelect: 'none',
  overflow: 'hidden',
  minWidth: 34,
};

const editorFrame: React.CSSProperties = {
  flex: 1,
  minHeight: 220,
  display: 'flex',
  borderRadius: 6,
  borderWidth: 1,
  borderStyle: 'solid',
  borderColor: '#cbd5e1',
  overflow: 'hidden',
};

const hitRow: React.CSSProperties = {
  ...treeRowBase,
  paddingLeft: 22,
  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
  fontSize: 11,
  color: '#64748b',
};

/**
 * 한 파일의 편집 버퍼 — 값과 그 기준선(서버에서 읽은 본문).
 *
 * @since engine-v1.71.0
 */
interface AssetBuffer {
  value: string;
  base: string;
}

/**
 * 아직 저장하지 않은 새 파일의 버퍼 키 — 경로에는 NUL 이 올 수 없어 실제 파일 경로와 겹치지 않는다.
 *
 * 새 파일 본문을 «입력 중인 경로» 에 매달면 경로 칸을 고치는 순간 본문이 옛 경로 버퍼에 숨고, 경로보다 본문을
 * 먼저 쓰면 입력이 버려진다. 본문은 경로와 따로 두고 저장에 성공했을 때 그 경로로 옮긴다.
 */
const NEW_FILE_BUFFER_KEY = '\u0000new-file';

export function CustomAssetsModal(props: CustomAssetsModalProps): React.ReactElement {
  const { templateIdentifier, t, onClose, onRegisterCloseRequest } = props;

  const targets = useMemo(() => buildTargets(templateIdentifier), [templateIdentifier]);
  const [targetKey, setTargetKey] = useState(`template:${templateIdentifier}`);
  const target = useMemo(
    () => targets.find((x) => `${x.type}:${x.identifier}` === targetKey) ?? targets[0],
    [targets, targetKey],
  );

  const assets = useCustomAssets({ type: target.type, identifier: target.identifier });
  // 확인·입력 대화상자 — 브라우저 기본 confirm/prompt 대신 편집기 모달 위에 띄운다(engine-v1.78.7).
  const dialog = useEditorDialog(t);
  const { reload } = assets;

  const [selected, setSelected] = useState<CustomAssetFile | null>(null);
  const [newPath, setNewPath] = useState('');
  const [isBusy, setIsBusy] = useState(false);
  const [query, setQuery] = useState('');
  const [hits, setHits] = useState<CustomAssetSearchHit[]>([]);
  const [collapsed, setCollapsed] = useState<string[]>([]);
  const [wordWrap, setWordWrap] = useState(false);
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const textareaRef = useRef<HTMLTextAreaElement | null>(null);
  const gutterRef = useRef<HTMLDivElement | null>(null);

  /**
   * 파일별 편집 버퍼.
   *
   * 종전에는 `draft` 문자열 **한 벌**이라 파일을 바꾸면 앞 파일의 저장하지 않은 편집이
   * 경고 없이 사라졌다(D2 — 레이아웃 편집기와 같은 결함 클래스). 여기서도 방향을 뒤집어,
   * 서버 본문은 읽어 오기만 하고 편집 중인 값은 이 맵이 소유한다.
   */
  const [buffers, setBuffers] = useState<Record<string, AssetBuffer>>({});

  // 새 파일은 전용 버퍼를 그린다 — 빈 값으로 그리면 키마다 빈 값에 한 글자가 붙어 버퍼에 마지막 글자만
  // 남는다(실측 2026-10-03: 한 줄을 쳐서 저장하니 「}」 1바이트).
  const bufferKey = selected ? selected.path : NEW_FILE_BUFFER_KEY;
  const draft = buffers[bufferKey]?.value ?? '';
  const dirtyPaths = useMemo(
    () => Object.entries(buffers).filter(([, b]) => b.value !== b.base).map(([path]) => path),
    [buffers],
  );
  const dirtySet = useMemo(() => new Set(dirtyPaths), [dirtyPaths]);

  useEffect(() => {
    void reload();
  }, [reload]);

  /** 미저장이 있으면 되묻는다 — 없으면 그냥 진행한다. */
  const confirmDiscard = useCallback(async (): Promise<boolean> => {
    if (dirtyPaths.length === 0) {
      return true;
    }

    return dialog.confirm({
      title: t('layout_editor.chrome.dirty_guard.title'),
      message: t('layout_editor.custom_assets.discard_confirm', { count: dirtyPaths.length }),
      confirmLabel: t('layout_editor.chrome.dirty_guard.discard'),
      cancelLabel: t('layout_editor.chrome.dirty_guard.stay'),
      danger: true,
    });
  }, [dirtyPaths.length, dialog, t]);

  /**
   * 대상(확장)을 바꿉니다 — 미저장 편집이 있으면 확인을 받는다(D3).
   *
   * 종전에는 아무 말 없이 버렸다. 대상이 바뀌면 버퍼를 비워야 하는 것은 맞지만(A 확장의 본문을
   * B 확장에 저장하면 경로만 맞으면 서버는 200 으로 받아들인다), **버릴지 말지는 사용자가 정한다**.
   */
  const changeTarget = useCallback(
    async (nextKey: string) => {
      if (nextKey === targetKey) {
        return;
      }

      if (!(await confirmDiscard())) {
        return;
      }

      setTargetKey(nextKey);
      setSelected(null);
      setNewPath('');
      setBuffers({});
      setQuery('');
      setHits([]);
    },
    [targetKey, confirmDiscard],
  );

  /**
   * 파일을 엽니다 — **미저장 버퍼가 있으면 서버 본문을 덮지 않는다**(D2 의 정면 해결).
   */
  const openFile = useCallback(
    async (file: CustomAssetFile) => {
      setSelected(file);

      if (!file.editable) {
        return;
      }

      const existing = buffers[file.path];

      if (existing && existing.value !== existing.base) {
        return;
      }

      setIsBusy(true);
      const content = await assets.read(file.path);
      setIsBusy(false);

      const value = content ?? '';
      setBuffers((current) => ({ ...current, [file.path]: { value, base: value } }));
    },
    [assets, buffers],
  );

  const handleSave = useCallback(async () => {
    const path = selected?.path ?? newPath.trim();

    if (!path) {
      return;
    }

    const value = draft;

    setIsBusy(true);
    const ok = await assets.save(path, value);
    setIsBusy(false);

    if (!ok) {
      return;
    }

    // 저장에 성공하면 기준선을 옮긴다 — 그래야 dirty 표시가 사라지고 다시 열 때 서버 본문을 받는다.
    // 새 파일이었으면 전용 버퍼를 그 경로로 옮긴다.
    setBuffers((current) => {
      const next = { ...current, [path]: { value, base: value } };

      if (!selected) {
        delete next[NEW_FILE_BUFFER_KEY];
      }

      return next;
    });

    if (!selected) {
      setNewPath('');
      setSelected({
        path,
        name: path.split('/').pop() ?? path,
        extension: (path.split('.').pop() ?? '').toLowerCase(),
        size: value.length,
        modified_at: new Date().toISOString(),
        editable: true,
        loaded: true,
      });
    }
  }, [assets, selected, newPath, draft]);

  const handleDelete = useCallback(async () => {
    if (!selected) return;

    // 되돌릴 수 없는 조작이라 확인을 받는다 — 편집기 대화상자(위험 확인, 처음 초점 [취소]).
    const proceed = await dialog.confirm({
      title: selected.path,
      message: t('layout_editor.custom_assets.delete_confirm'),
      confirmLabel: t('layout_editor.custom_assets.delete'),
      danger: true,
    });
    if (!proceed) {
      return;
    }

    setIsBusy(true);
    const ok = await assets.remove(selected.path);
    setIsBusy(false);

    if (ok) {
      setBuffers((current) => {
        const next = { ...current };
        delete next[selected.path];

        return next;
      });
      setSelected(null);
    }
  }, [assets, selected, dialog, t]);

  /** 이름변경(= 이동) — 확장자를 바꾸는 이름은 서버가 막는다(서빙 규약이 함께 바뀐다). */
  const handleRename = useCallback(async () => {
    if (!selected) return;

    const next = await dialog.prompt({
      title: t('layout_editor.custom_assets.rename'),
      message: t('layout_editor.custom_assets.rename_prompt'),
      defaultValue: selected.path,
      confirmLabel: t('layout_editor.custom_assets.rename'),
    });

    if (!next || next.trim() === '' || next.trim() === selected.path) {
      return;
    }

    setIsBusy(true);
    const ok = await assets.rename(selected.path, next.trim());
    setIsBusy(false);

    if (!ok) {
      return;
    }

    const from = selected.path;
    setBuffers((current) => {
      const moved = { ...current };
      const buffer = moved[from];
      delete moved[from];

      if (buffer) {
        moved[next.trim()] = buffer;
      }

      return moved;
    });
    setSelected({ ...selected, path: next.trim(), name: next.trim().split('/').pop() ?? next.trim() });
  }, [assets, selected, dialog, t]);

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

  const handleClose = useCallback(async () => {
    if (!(await confirmDiscard())) {
      return;
    }

    onClose();
  }, [confirmDiscard, onClose]);

  // 백드롭 클릭도 [✕]·Esc 와 같은 확인을 거치게 닫기 요청을 모달 루트에 넘긴다.
  useEffect(() => {
    onRegisterCloseRequest?.(handleClose);

    return () => onRegisterCloseRequest?.(null);
  }, [onRegisterCloseRequest, handleClose]);

  // 내용 검색 — 파일명 거르기와 **열린 파일의 본문**은 클라이언트가 즉시 하고(아래 `localHits`),
  // 열리지 않은 파일의 본문 검색만 서버에 맡긴다.
  useEffect(() => {
    const keyword = query.trim();

    if (keyword.length < 2) {
      setHits([]);

      return;
    }

    let cancelled = false;
    const timer = setTimeout(() => {
      void assets.search(keyword).then((rows) => {
        if (!cancelled) {
          setHits(rows);
        }
      });
    }, 500);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
    // `assets.search` 는 대상이 바뀔 때만 새로 만들어진다 — 검색어·대상이 기준이다.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, target.type, target.identifier]);

  // `mod+s` 저장 / `escape` 닫기 — 편집 중에도 잡아야 하므로 모달 루트에서 받는다.
  useEffect(() => {
    if (typeof window === 'undefined') return;

    const onKeyDown = (event: KeyboardEvent): void => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        void handleSave();

        return;
      }

      if (event.key === 'Escape') {
        event.preventDefault();
        void handleClose();
      }
    };

    window.addEventListener('keydown', onKeyDown);

    return () => window.removeEventListener('keydown', onKeyDown);
  }, [handleSave, handleClose]);

  const normalizedQuery = query.trim().toLocaleLowerCase();

  // 열린 파일(버퍼가 있는 파일)은 서버를 기다리지 않고 현재 본문에서 바로 찾는다 — 저장하지 않은
  // 편집분까지 검색되고, 같은 경로의 서버 결과(저장본 기준)는 이 결과로 대체한다 (§4.7).
  const localHits = useMemo(
    () =>
      normalizedQuery.length < 2
        ? []
        : Object.entries(buffers)
            .filter(([path]) => path !== NEW_FILE_BUFFER_KEY)
            .map(([path, buffer]) => scanBufferForHits(path, buffer.value, normalizedQuery)),
    [buffers, normalizedQuery],
  );
  const mergedHits = useMemo(() => {
    const openPaths = new Set(localHits.map((h) => h.path));

    return [
      ...hits.filter((h) => !openPaths.has(h.path)),
      ...localHits.filter((h) => h.match_count > 0 || h.name_matched),
    ];
  }, [hits, localHits]);
  const hitPaths = useMemo(() => new Set(mergedHits.map((h) => h.path)), [mergedHits]);
  const hitsByPath = useMemo(() => new Map(mergedHits.map((h) => [h.path, h])), [mergedHits]);

  const tree = useMemo(() => {
    const built = buildPathTree(assets.files, (file) => file.path);

    return normalizedQuery ? filterPathTree(built, normalizedQuery, hitPaths) : built;
  }, [assets.files, normalizedQuery, hitPaths]);

  const lineNumbers = useMemo(() => {
    const count = draft === '' ? 1 : draft.split('\n').length;

    return Array.from({ length: count }, (_, i) => i + 1).join('\n');
  }, [draft]);

  /** 트리 한 갈래를 그린다 (폴더는 접힘 토글, 파일은 열기). */
  const renderNodes = (nodes: Array<PathTreeNode<CustomAssetFile>>): React.ReactNode =>
    nodes.map((node) => {
      if (node.item === undefined) {
        const isOpen = normalizedQuery !== '' || !collapsed.includes(node.path);

        return (
          <div key={`d:${node.path}`}>
            <button
              type="button"
              style={{ ...folderRow, paddingLeft: 8 + node.depth * 12 }}
              aria-expanded={isOpen}
              onClick={() =>
                setCollapsed((current) =>
                  current.includes(node.path)
                    ? current.filter((p) => p !== node.path)
                    : [...current, node.path],
                )
              }
              data-testid={`g7le-custom-asset-folder-${node.path}`}
            >
              <span aria-hidden="true">{isOpen ? '▾' : '▸'}</span>
              <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {highlightMatch(node.label, normalizedQuery)}
              </span>
            </button>
            {isOpen ? renderNodes(node.children) : null}
          </div>
        );
      }

      const file = node.item;
      const isDirty = dirtySet.has(file.path);
      const hit = hitsByPath.get(file.path);

      return (
        <div key={file.path}>
          <button
            type="button"
            style={{
              ...(selected?.path === file.path ? rowSelected : treeRowBase),
              paddingLeft: 8 + node.depth * 12,
              ...(isDirty ? { color: '#b45309', fontWeight: 600 } : null),
            }}
            onClick={() => void openFile(file)}
            data-testid={`g7le-custom-asset-row-${file.path}`}
            data-loaded={file.loaded ? 'true' : 'false'}
            data-dirty={isDirty ? 'true' : 'false'}
          >
            <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>
              {highlightMatch(node.label, normalizedQuery)}
            </span>
            {hit && hit.match_count > 0 ? (
              <span style={{ color: '#64748b', fontSize: 11 }}>{hit.match_count}</span>
            ) : null}
            {isDirty ? (
              <span style={dirtyDot} role="img" aria-label={t('layout_editor.custom_assets.unsaved')} />
            ) : null}
            <span style={{ color: '#94a3b8', fontSize: 11 }}>{formatSize(file.size)}</span>
          </button>

          {hit?.lines?.slice(0, 3).map((line) => (
            <button
              key={`${file.path}@${line.line}`}
              type="button"
              style={{ ...hitRow, paddingLeft: 20 + node.depth * 12 }}
              onClick={() => void openFile(file)}
              data-testid={`g7le-custom-asset-hit-${file.path}-${line.line}`}
            >
              <span style={{ minWidth: 26, textAlign: 'right', color: '#94a3b8' }}>{line.line}</span>
              <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {highlightMatch(line.preview, normalizedQuery)}
              </span>
            </button>
          ))}
        </div>
      );
    });

  return (
    <div className="g7le-custom-assets" style={wrap} data-testid="g7le-custom-assets-modal">
      <div style={header}>
        <span>
          🎨 {t('layout_editor.custom_assets.title')}
          {dirtyPaths.length > 0 ? (
            <span style={{ marginLeft: 6, color: '#b45309', fontSize: 12 }} data-testid="g7le-custom-assets-dirty-count">
              ● {dirtyPaths.length}
            </span>
          ) : null}
        </span>
        <select
          value={targetKey}
          onChange={(event) => changeTarget(event.target.value)}
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
          onClick={handleClose}
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
            <input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder={t('layout_editor.custom_assets.search_placeholder')}
              aria-label={t('layout_editor.custom_assets.search_placeholder')}
              style={searchInput}
              data-testid="g7le-custom-assets-search"
            />

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

            {!assets.isLoading && assets.files.length > 0 && tree.length === 0 ? (
              <div style={{ padding: 12, color: '#94a3b8', fontSize: 12 }} data-testid="g7le-custom-assets-no-match">
                {t('layout_editor.custom_assets.no_match', { keyword: query })}
              </div>
            ) : null}

            {renderNodes(tree)}
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
                    setNewPath('');
                  }}
                  data-testid="g7le-custom-assets-new"
                >
                  {t('layout_editor.custom_assets.new_file')}
                </button>
              ) : null}
              {selected ? (
                <button
                  type="button"
                  style={button}
                  disabled={isBusy}
                  onClick={() => void handleRename()}
                  data-testid="g7le-custom-assets-rename"
                >
                  {t('layout_editor.custom_assets.rename')}
                </button>
              ) : null}
              <button
                type="button"
                style={wordWrap ? primary : button}
                aria-pressed={wordWrap}
                onClick={() => setWordWrap((current) => !current)}
                data-testid="g7le-custom-assets-wrap"
              >
                {t('layout_editor.custom_assets.word_wrap')}
              </button>
            </div>

            {selected && !selected.editable ? (
              <div style={{ color: '#64748b', fontSize: 12, lineHeight: 1.7 }}>
                {t('layout_editor.custom_assets.binary_hint', { extension: selected.extension })}
              </div>
            ) : (
              <div style={editorFrame}>
                <div style={gutterStyle} ref={gutterRef} aria-hidden="true">
                  {lineNumbers}
                </div>
                <textarea
                  ref={textareaRef}
                  value={draft}
                  onChange={(event) => {
                    const key = bufferKey;

                    setBuffers((current) => ({
                      ...current,
                      [key]: { value: event.target.value, base: current[key]?.base ?? '' },
                    }));
                  }}
                  onKeyDown={(event) => {
                    // Tab 은 포커스 이동이 아니라 들여쓰기다(계획 §4.7) — 코드 입력칸에서 Tab 이 포커스를
                    // 옮기면 쓸 수 없다. Shift+Tab 은 그 줄 머리의 공백 2칸을 거둔다.
                    if (event.key !== 'Tab') return;

                    const key = bufferKey;

                    event.preventDefault();

                    const target = event.currentTarget;
                    const { selectionStart, selectionEnd } = target;
                    const apply = (next: string, cursor: number): void => {
                      setBuffers((current) => ({
                        ...current,
                        [key]: { value: next, base: current[key]?.base ?? '' },
                      }));
                      requestAnimationFrame(() => {
                        target.selectionStart = target.selectionEnd = cursor;
                      });
                    };

                    if (event.shiftKey) {
                      const lineStart = draft.lastIndexOf('\n', selectionStart - 1) + 1;

                      if (draft.slice(lineStart, lineStart + 2) !== '  ') return;

                      apply(`${draft.slice(0, lineStart)}${draft.slice(lineStart + 2)}`, Math.max(lineStart, selectionStart - 2));

                      return;
                    }

                    apply(`${draft.slice(0, selectionStart)}  ${draft.slice(selectionEnd)}`, selectionStart + 2);
                  }}
                  onScroll={(event) => {
                    // 줄번호가 본문과 함께 흐르게 — 따로 두면 긴 파일에서 번호가 어긋난다.
                    if (gutterRef.current) {
                      gutterRef.current.scrollTop = event.currentTarget.scrollTop;
                    }
                  }}
                  style={{ ...textarea, border: 'none', borderRadius: 0, flex: 1 }}
                  spellCheck={false}
                  wrap={wordWrap ? 'soft' : 'off'}
                  aria-label={t('layout_editor.custom_assets.content_label')}
                  data-testid="g7le-custom-assets-content"
                />
              </div>
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
