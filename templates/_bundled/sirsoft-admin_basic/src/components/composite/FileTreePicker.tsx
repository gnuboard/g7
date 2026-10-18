import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Input } from '../basic/Input';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { FileTree, FileTreeContentMatch, FileTreeNode, FileTreeReveal } from './FileTree';
import type { EditorAttrs } from '../../types';
import { useRenderWidth } from '../../hooks/useRenderWidth';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/** 트리를 어떤 그릇에 담을지. */
export type FileTreePickerMode = 'inline' | 'drawer' | 'sheet' | 'auto';

export interface FileTreePickerProps {
  nodes?: FileTreeNode[];
  selectedKey?: string;
  dirtyKeys?: string[];
  searchKeyword?: string;
  contentMatches?: FileTreeContentMatch[];
  pendingFolders?: string[];
  /**
   * `auto` 면 창 폭으로 고른다 — ≥1024 인라인 / 768~1023 서랍 / <768 시트.
   */
  mode?: FileTreePickerMode;
  /** 펼침 상태를 적어 둘 localStorage 키 */
  storageKey?: string;
  /** 파일 조작 가능 여부 (권한) */
  canManage?: boolean;
  isLoading?: boolean;
  /**
   * 내용 검색이 걸린 파일 수 — 0 이면 요약을 보이지 않는다.
   *
   * 문구가 아니라 **수치**를 받는 이유: 엔진의 `$t:key|p={{...}}` 파이프 치환은 속성이 리터럴
   * 템플릿일 때만 동작한다. 레이아웃에서 문자열을 이어 붙여 만들면 `$t:` 가 이미 계산된 값으로
   * 넘어와 파라미터가 `:matches` 원문 그대로 화면에 나온다(브라우저 실측 2026-09-12).
   */
  searchFileCount?: number;
  /** 내용 검색이 찾은 매치 총수 */
  searchMatchCount?: number;
  /**
   * 값이 바뀔 때마다 검색창에 포커스를 준다 — 단축키 «파일 빠른 이동»(mod+k)·«내용 검색»
   * (mod+shift+f)이 쓰는 자리다(계획서 §4.4). 서랍·시트 형태면 먼저 연다(열리면서 포커스된다).
   * 레이아웃은 전역 카운터를 1씩 올려 넘긴다 — 같은 값이 두 번 오면 아무 일도 없다.
   */
  focusSearchNonce?: number;
  onSelect?: (key: string, revealAt?: FileTreeReveal) => void;
  onSearch?: (keyword: string) => void;
  onCreateFile?: (parentPath: string) => void;
  onCreateFolder?: (parentPath: string) => void;
  onRename?: (key: string) => void;
  onDelete?: (key: string) => void;
  className?: string;
  id?: string;
  editorAttrs?: EditorAttrs;
}

/** 데스크톱 경계 — `matchMedia` 는 dPR 이 정수가 아닌 기기에서 경계 1px 이 어긋난다 */
const DESKTOP_MIN_WIDTH = 1024;

/** 태블릿 경계 */
const TABLET_MIN_WIDTH = 768;

/** 트리 폭 — 기본 / 최소 / 최대 */
const TREE_WIDTH_DEFAULT = 320;
const TREE_WIDTH_MIN = 240;
const TREE_WIDTH_MAX = 480;

/**
 * 렌더 폭으로 표시 형태를 고릅니다.
 *
 * 폭은 Provider 폭(`G7Core.useResponsive().width`)이 우선이다 — 레이아웃 편집기 미리보기는 같은
 * 문서의 div 라서 창 폭이 미리보기 기기 폭과 다르다(#141). 훅이 없을 때만 `window.innerWidth`
 * 로 폴백한다. `matchMedia` 를 쓰지 않는 이유는 저장소 문서(`docs/frontend/responsive-layout.md`)에
 * 적힌 실측 때문이다 — devicePixelRatio 가 정수가 아닌 환경에서 경계 1px 이 어긋나 1023/1024 에서
 * 형태가 뒤집힌다.
 *
 * @param width 렌더 폭
 * @returns 표시 형태
 */
function resolveMode(width: number): Exclude<FileTreePickerMode, 'auto'> {
  if (width >= DESKTOP_MIN_WIDTH) {
    return 'inline';
  }

  return width >= TABLET_MIN_WIDTH ? 'drawer' : 'sheet';
}

/**
 * 파일 트리를 기기 폭에 맞는 그릇에 담습니다.
 *
 * - **인라인**(≥1024) — 좌측에 상시 노출. 폭은 드래그로 240~480px 사이에서 조절하고 기억한다.
 * - **서랍**(768~1023) — 좌측 오버레이. 파일을 고르면 스스로 닫힌다.
 * - **시트**(<768) — 전체 화면. 트리 행이 44px 터치 타깃이 된다.
 *
 * 세 형태가 같은 {@link FileTree} 를 쓰므로 검색·접기·미저장 표시의 동작이 기기별로 갈리지 않는다.
 */
export const FileTreePicker: React.FC<FileTreePickerProps> = ({
  nodes = [],
  selectedKey,
  dirtyKeys = [],
  searchKeyword = '',
  contentMatches = [],
  pendingFolders = [],
  mode = 'auto',
  storageKey,
  canManage = true,
  isLoading = false,
  searchFileCount = 0,
  searchMatchCount = 0,
  focusSearchNonce = 0,
  onSelect,
  onSearch,
  onCreateFile,
  onCreateFolder,
  onRename,
  onDelete,
  className = '',
  id,
  editorAttrs,
}) => {
  const renderWidth = useRenderWidth();
  const [resolved, setResolved] = useState<Exclude<FileTreePickerMode, 'auto'>>(() =>
    mode === 'auto' ? resolveMode(renderWidth) : mode
  );
  const [open, setOpen] = useState(false);
  const [width, setWidth] = useState<number>(() => readWidth(storageKey));
  const panelRef = useRef<HTMLDivElement | null>(null);
  const openerRef = useRef<HTMLElement | null>(null);
  const searchRef = useRef<HTMLInputElement | null>(null);

  // 렌더 폭이 바뀌면 형태를 다시 고른다(창 resize 는 useRenderWidth 의 폴백이 처리한다)
  useEffect(() => {
    setResolved(mode === 'auto' ? resolveMode(renderWidth) : mode);
  }, [mode, renderWidth]);

  // 인라인으로 넓어지면 열려 있던 오버레이는 의미가 없다 — 백드롭만 남아 화면을 덮는 것을 막는다.
  useEffect(() => {
    if (resolved === 'inline') {
      setOpen(false);
    }
  }, [resolved]);

  // 단축키가 검색창을 부른다 — 인라인이면 바로 포커스, 서랍·시트면 열고(열리면서 포커스된다).
  const lastFocusNonceRef = useRef(0);

  useEffect(() => {
    if (!focusSearchNonce || focusSearchNonce === lastFocusNonceRef.current) {
      return;
    }

    lastFocusNonceRef.current = focusSearchNonce;

    if (resolved === 'inline') {
      searchRef.current?.focus();
      searchRef.current?.select();
    } else {
      setOpen(true);
    }
  }, [focusSearchNonce, resolved]);

  const close = useCallback(() => {
    setOpen(false);
    // 포커스를 연 버튼으로 돌려준다 — 오버레이가 사라진 자리에 포커스를 남기면
    // 키보드 사용자는 문서 맨 앞으로 튕긴다.
    openerRef.current?.focus();
  }, []);

  // 오버레이는 Esc 로 닫히고 포커스를 가둔다.
  useEffect(() => {
    if (!open || resolved === 'inline' || typeof document === 'undefined') {
      return;
    }

    searchRef.current?.focus();

    const onKeyDown = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        close();

        return;
      }

      if (event.key !== 'Tab' || !panelRef.current) {
        return;
      }

      const focusable = panelRef.current.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
      );

      if (focusable.length === 0) {
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      }
    };

    document.addEventListener('keydown', onKeyDown, true);

    return () => document.removeEventListener('keydown', onKeyDown, true);
  }, [open, resolved, close]);

  /** 트리 폭 드래그 — 포인터를 잡고 놓을 때까지 따라간다. */
  const startResize = useCallback(
    (event: React.PointerEvent) => {
      event.preventDefault();

      const startX = event.clientX;
      const startWidth = width;

      const onMove = (moveEvent: PointerEvent): void => {
        const next = Math.max(TREE_WIDTH_MIN, Math.min(TREE_WIDTH_MAX, startWidth + moveEvent.clientX - startX));
        setWidth(next);
      };

      const onUp = (): void => {
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);

        setWidth((current) => {
          writeWidth(storageKey, current);

          return current;
        });
      };

      window.addEventListener('pointermove', onMove);
      window.addEventListener('pointerup', onUp);
    },
    [width, storageKey]
  );

  const dirtyCount = dirtyKeys.length;

  const treePanel = (
    <>
      <Div className="p-3 border-b border-gray-200 dark:border-gray-700 flex flex-col gap-2 shrink-0">
        <Div className="flex items-center gap-2">
          <Icon name="fa-regular fa-folder-open" className="text-base text-amber-500 dark:text-amber-400" />
          <Span className="flex-1 font-medium text-sm text-gray-900 dark:text-gray-100">
            {t('admin.templates.layout_edit.layout_files')}
          </Span>
          {canManage && (
            <>
              <Button
                type="button"
                data-testid="file-tree-new-file"
                aria-label={t('admin.templates.layout_edit.file.new_file')}
                title={t('admin.templates.layout_edit.file.new_file')}
                className="px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                onClick={() => onCreateFile?.('')}
              >
                <Icon name="fa-solid fa-plus" className="text-xs" />
              </Button>
              <Button
                type="button"
                data-testid="file-tree-new-folder"
                aria-label={t('admin.templates.layout_edit.file.new_folder')}
                title={t('admin.templates.layout_edit.file.new_folder')}
                className="px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                onClick={() => onCreateFolder?.('')}
              >
                <Icon name="fa-solid fa-folder-plus" className="text-xs" />
              </Button>
            </>
          )}
          {resolved !== 'inline' && (
            <Button
              type="button"
              aria-label={t('common.close')}
              className="px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300"
              onClick={close}
            >
              <Icon name="fa-solid fa-xmark" className="text-xs" />
            </Button>
          )}
        </Div>

        <Div className="relative">
          <Icon
            name="fa-solid fa-magnifying-glass"
            className="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 dark:text-gray-500 pointer-events-none"
          />
          <Input
            ref={searchRef}
            id={id ? `${id}-search` : 'file-tree-search'}
            type="search"
            data-testid="file-tree-search"
            value={searchKeyword}
            placeholder={t('admin.templates.layout_edit.search.placeholder')}
            aria-label={t('admin.templates.layout_edit.search.placeholder')}
            className="w-full pl-8 pr-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
            onChange={(event: React.ChangeEvent<HTMLInputElement>) => onSearch?.(event.target.value)}
          />
        </Div>

        {searchFileCount > 0 && (
          <Span className="text-xs text-gray-500 dark:text-gray-400" data-testid="file-tree-search-summary">
            {t('admin.templates.layout_edit.search.result_summary', {
              files: searchFileCount,
              matches: searchMatchCount,
            })}
          </Span>
        )}
      </Div>

      <FileTree
        nodes={nodes}
        selectedKey={selectedKey}
        dirtyKeys={dirtyKeys}
        searchKeyword={searchKeyword}
        contentMatches={contentMatches}
        pendingFolders={pendingFolders}
        rowSize={resolved === 'inline' ? 'compact' : 'touch'}
        storageKey={storageKey ? `${storageKey}.expanded` : undefined}
        canManage={canManage}
        isLoading={isLoading}
        className="flex-1 min-h-0 py-1"
        onSelect={(key, revealAt) => {
          onSelect?.(key, revealAt);

          // 오버레이는 파일을 고른 순간 할 일을 마쳤다 — 닫지 않으면 고른 파일이 자기 뒤에 가린다.
          if (resolved !== 'inline') {
            close();
          }
        }}
        onCreateFile={onCreateFile}
        onRename={onRename}
        onDelete={onDelete}
        onClearSearch={() => onSearch?.('')}
      />
    </>
  );

  if (resolved === 'inline') {
    return (
      <Div id={id} className={`flex shrink-0 ${className}`} {...(editorAttrs ?? {})}>
        <Div
          className="flex flex-col min-h-0 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden"
          style={{ width: `${width}px` }}
        >
          {treePanel}
        </Div>
        <Div
          role="separator"
          aria-orientation="vertical"
          aria-label={t('admin.templates.layout_edit.tree.resize')}
          className="w-1.5 cursor-col-resize shrink-0 hover:bg-blue-500/40 dark:hover:bg-blue-400/40"
          onPointerDown={startResize}
        />
      </Div>
    );
  }

  return (
    <Div id={id} className={className} {...(editorAttrs ?? {})}>
      <Button
        type="button"
        ref={openerRef as React.Ref<HTMLButtonElement>}
        data-testid="file-tree-picker-open"
        aria-expanded={open}
        className="flex items-center gap-2 h-11 px-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-sm text-gray-900 dark:text-gray-100 max-w-full"
        onClick={() => setOpen(true)}
      >
        <Icon name="fa-regular fa-folder-open" className="text-sm text-amber-500 dark:text-amber-400" />
        <Span className="truncate font-medium">
          {selectedKeyLabel(nodes, selectedKey) ?? t('admin.templates.layout_edit.layout_files')}
        </Span>
        {dirtyCount > 0 && (
          <Span
            className="px-1.5 py-0.5 text-[10px] font-mono rounded-full border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400"
            aria-label={t('admin.templates.layout_edit.tree.unsaved_count', { count: dirtyCount })}
          >
            {dirtyCount}
          </Span>
        )}
        <Icon name="fa-solid fa-chevron-down" className="text-[10px] text-gray-400 dark:text-gray-500" />
      </Button>

      {open && (
        <>
          <Div
            data-testid="file-tree-scrim"
            className="fixed inset-0 z-40 bg-gray-900/50 dark:bg-gray-950/60"
            onClick={close}
          />
          <Div
            ref={panelRef}
            role="dialog"
            aria-modal="true"
            aria-label={t('admin.templates.layout_edit.layout_files')}
            data-testid="file-tree-overlay"
            // 서랍은 왼쪽에서 200ms 슬라이드, 시트는 아래에서 올라온다(§7.4·§7.5). 키프레임은
            // `main.css` 의 `g7le-drawer-enter`/`g7le-sheet-enter` — 움직임 축소 설정이면 즉시 뜬다.
            // 둘 다 관리자 모바일 헤더(`.mobile_header_portable` h-16·z-50) **아래**에서 시작한다 — top:0 이면
            // 머리(제목·검색칸)가 헤더에 가려 검색칸을 누를 수 없다. 사이드바 드로어(`_admin_base.css`)와 같은 처리다.
            className={
              resolved === 'drawer'
                ? 'fixed top-16 bottom-0 left-0 z-40 w-[380px] max-w-[85vw] flex flex-col bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 shadow-2xl g7le-drawer-enter'
                : 'fixed inset-x-0 top-16 bottom-0 z-40 flex flex-col bg-white dark:bg-gray-800 g7le-sheet-enter'
            }
          >
            {treePanel}
            {canManage && resolved === 'sheet' && (
              <Div className="flex gap-2 p-3 pb-5 border-t border-gray-200 dark:border-gray-700 shrink-0">
                <Button
                  type="button"
                  className="flex-1 h-11 rounded-lg border border-gray-300 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-300"
                  onClick={() => onCreateFile?.('')}
                >
                  {t('admin.templates.layout_edit.file.new_file')}
                </Button>
                <Button
                  type="button"
                  className="flex-1 h-11 rounded-lg border border-gray-300 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-300"
                  onClick={() => onCreateFolder?.('')}
                >
                  {t('admin.templates.layout_edit.file.new_folder')}
                </Button>
              </Div>
            )}
          </Div>
        </>
      )}
    </Div>
  );
};

/**
 * 선택된 키의 표시 이름을 찾습니다 (모바일 버튼 라벨).
 *
 * @param nodes 파일 목록
 * @param selectedKey 선택 키
 * @returns 표시 이름 (없으면 undefined)
 */
function selectedKeyLabel(nodes: FileTreeNode[], selectedKey?: string): string | undefined {
  if (!selectedKey) {
    return undefined;
  }

  const node = nodes.find((candidate) => candidate.key === selectedKey);

  if (!node) {
    return undefined;
  }

  const segments = node.path.split('/');

  return node.label ?? segments[segments.length - 1];
}

/**
 * 저장해 둔 트리 폭을 읽습니다.
 *
 * @param storageKey 저장 키 접두
 * @returns 픽셀 폭 (없거나 범위를 벗어나면 기본값)
 */
function readWidth(storageKey?: string): number {
  if (!storageKey || typeof window === 'undefined') {
    return TREE_WIDTH_DEFAULT;
  }

  try {
    const raw = Number(window.localStorage.getItem(`${storageKey}.treeWidth`));

    if (!Number.isFinite(raw) || raw < TREE_WIDTH_MIN || raw > TREE_WIDTH_MAX) {
      return TREE_WIDTH_DEFAULT;
    }

    return raw;
  } catch {
    return TREE_WIDTH_DEFAULT;
  }
}

/**
 * 트리 폭을 적어 둡니다.
 *
 * @param storageKey 저장 키 접두
 * @param width 픽셀 폭
 * @returns 없음
 */
function writeWidth(storageKey: string | undefined, width: number): void {
  if (!storageKey || typeof window === 'undefined') {
    return;
  }

  try {
    window.localStorage.setItem(`${storageKey}.treeWidth`, String(width));
  } catch {
    // 폭 기억은 편의 장치다 — 적어 두지 못해도 트리는 그대로 동작한다.
  }
}
