import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { DiffEditor } from '@monaco-editor/react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { CodeEditor, CodeEditorCommand, CodeEditorMarker, CodeEditorReveal } from './CodeEditor';
import { FileTabs, FileTabItem } from './FileTabs';
import { useCodeEditorShortcuts, CodeShortcutHandlers } from '../../hooks/useCodeEditorShortcuts';
import { useFileDrafts } from '../../hooks/useFileDrafts';
import { useRenderWidth } from '../../hooks/useRenderWidth';
import {
  CODE_EDITOR_SHORTCUTS,
  CODE_SHORTCUT_GROUP_LABEL,
  CODE_SHORTCUT_GROUP_ORDER,
  EDITOR_OWNED_SHORTCUTS,
  formatCodeCombo,
  isMacPlatform,
} from '../../spec/codeEditorShortcuts';
import type { EditorAttrs } from '../../types';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/** 한 파일의 편집 버퍼. */
interface Buffer {
  /** 편집 중인 값 */
  value: string;
  /** 이 버퍼를 만들 때 받은 서버 본문 — dirty 판정의 기준선 */
  base: string;
  /** 그때의 서버 판본 */
  baseRevision: number;
  /** 마지막으로 활성이었던 시각 — 탭 상한을 넘길 때 LRU 로 쓴다 */
  touchedAt: number;
  /**
   * 기준선(서버 본문)을 아직 모른다 — 열려 있지 않은 파일의 초안을 복원한 버퍼.
   *
   * 그 파일 본문이 도착하면 기준선을 채운다. 이 표시 없이 초안 자신을 기준선으로 잡으면 「바뀐 것
   * 없음」 이 되어, 본문이 도착하는 순간 「깨끗한 버퍼는 최신본을 받는다」 규칙이 초안을 덮어썼다.
   */
  baseUnknown?: boolean;
}

export interface CodeWorkspaceProps {
  /** 지금 서버가 준 파일의 키 */
  fileKey: string;
  /**
   * `serverValue` 가 **실제로 어느 파일 것인지**.
   *
   * `fileKey` 는 클릭 즉시 바뀌지만 `serverValue` 는 응답이 와야 바뀐다. 그 사이의 렌더에서
   * 둘은 서로 다른 파일을 가리키므로, 그대로 받아들이면 **앞 파일 본문이 새 파일 버퍼에 굳는다**
   * (실측 2026-09-12: 300ms 간격으로 10개를 연속 클릭하니 6개 버퍼가 직전 파일 본문을 담았고,
   * 그 상태로 저장하면 다른 레이아웃을 덮어쓴다). 값이 있고 `fileKey` 와 다르면 그 렌더의
   * `serverValue` 는 통째로 무시한다.
   *
   * 주지 않으면(빈 문자열 포함) 대조하지 않는다 — 단일 파일만 다루는 호출부의 종전 동작.
   */
  serverKey?: string;
  /** 그 파일의 서버 본문 */
  serverValue?: string;
  /** 그 파일의 서버 판본 (`lock_version`) */
  serverRevision?: number;
  /**
   * 방금 성공한 **내 저장** — 어느 파일(`key`)에 어떤 본문(`value`)을 보냈고 서버가 어떤 판본(`revision`)을
   * 매겼는지. 저장을 보낼 때 `revision` 없이 적고, 성공 응답의 `lock_version` 으로 채운다.
   *
   * 서버는 저장한 마크업을 다시 역변환해 돌려주므로 공백·속성 표기가 정규화된다. 그 본문은 내 버퍼와
   * 글자가 달라, 이 정보가 없으면 «판본이 오르고 내용이 다르다» 가 곧 «남이 고쳤다» 가 되어 내 저장
   * 직후마다 충돌 바가 섰다(실측 2026-10-04: 태그 안 공백 세 칸 저장 → 「yours v2 → server v3」).
   */
  lastSave?: { key?: string; value?: string; revision?: number | null } | null;
  /** 탭에 보일 이름 — 없으면 키에서 뽑는다 */
  fileLabel?: string;
  /** 본문을 불러오는 중 (편집기를 언마운트하지 않는다 — 모델이 날아간다) */
  isLoading?: boolean;

  language?: string;
  readOnly?: boolean;
  /**
   * 지금 서버에 **실제로 있는** 파일 키 전체.
   *
   * 초안은 파일보다 오래 산다 — 지우거나 이름을 바꾼 뒤에도, 남이 지운 뒤에도 남는다.
   * 그래서 복원이 «없는 파일» 의 탭을 세우고, 그 탭을 누르면 404 가 났다(실측 2026-09-12).
   * 이 목록으로 그런 키를 가려 **서버를 부르지 않고** 복원한 편집분을 읽기 전용으로
   * 보여 준 뒤, 새 이름으로 살리거나 버리게 한다.
   *
   * 비어 있으면(목록을 아직 못 받았으면) 아무것도 «없는 파일» 로 판정하지 않는다 — 로딩
   * 중에 열린 탭을 전부 없는 파일로 몰아 버리는 사고를 막는다.
   */
  knownKeys?: string[];
  /**
   * 부모가 «끝났다» 고 알린 키 — 새 이름으로 살린 없는 파일이다. 그 탭을 닫고 초안을 지운다.
   *
   * 살린 뒤에도 탭과 초안이 남으면 이미 살린 편집분이 다음 진입에서 또 «저장하지 않은 편집» 으로
   * 뜨고 같은 물음을 되풀이한다(실측 2026-10-03). 편집기는 생성이 성공했는지 모르므로 부모가 알린다.
   */
  retiredKey?: string;
  markers?: CodeEditorMarker[];
  /** 검색 결과·오류 패널에서 온 이동 좌표 */
  revealAt?: CodeEditorReveal;
  /** localStorage 초안 네임스페이스 */
  draftNamespace?: string;
  /** 열린 탭 상한 */
  maxTabs?: number;
  /** 줄바꿈 기본값 — 주지 않으면 기기 폭으로 정한다(모바일 ON) */
  defaultWordWrap?: boolean;

  /**
   * 활성 버퍼가 바뀔 때마다 — 레이아웃이 `_global.editorMarkup` 으로 받는다.
   *
   * 파일을 **열었을 때도** 한 번 부른다(저장이 읽는 자리를 비워 둘 수 없다). 그래서 서버 왕복을
   * 거는 쪽은 이 콜백이 아니라 {@link onUserEdit} 이어야 한다.
   */
  onChange?: (event: { target: { value: string } }) => void;
  /**
   * **사람이 글자를 고쳤을 때만** — 파일 전환·초안 복원·충돌 해소로 값이 바뀐 경우는 부르지 않는다.
   *
   * 이 구분이 없으면 파일을 열 때마다 서버 변환이 한 번씩 돌고, 본문이 도착하기 전의 빈 값으로
   * 먼저 나가 422 가 난다(브라우저 실측 2026-09-12 — `_admin_base` 진입에서 422 → 200 두 번).
   */
  onUserEdit?: (event: { target: { value: string } }) => void;
  /** 미저장 키 목록이 바뀔 때 — 트리가 읽는다 */
  onDirtyChange?: (event: { target: { value: string[] } }) => void;
  /** 탭 클릭 — 레이아웃이 그 파일을 다시 받아 온다 */
  onRequestSelect?: (key: string) => void;

  onSave?: () => void;
  /**
   * 없는 파일의 복원분을 **새 이름으로** 살릴 때 — `(본문)` 을 들고 생성 흐름으로 넘긴다.
   *
   * 조용히 버리면 사용자가 쓴 글이 사라지고, 그대로 두면 저장할 수 없는 탭이 남는다.
   * 그래서 원본이 없어졌다는 사실을 알리고 내용은 지키는 쪽으로 연다.
   */
  onSaveAs?: (event: { target: { value: string; key: string } }) => void;
  onSaveAll?: () => void;
  onPreview?: () => void;
  onNewFile?: () => void;
  onVersionHistory?: () => void;
  onToggleView?: () => void;
  onQuickOpen?: () => void;
  onSearchContent?: () => void;
  onExit?: () => void;

  className?: string;
  id?: string;
  editorAttrs?: EditorAttrs;
  children?: React.ReactNode;
}

/** 열린 탭 기본 상한 — Monaco 모델이 파일마다 메모리를 잡으므로 무한히 쌓지 않는다 */
const DEFAULT_MAX_TABS = 20;

/** 줄바꿈 설정을 적어 둘 키 접미 */
const WORD_WRAP_SUFFIX = '.wordWrap';

/** 글자 크기 설정을 적어 둘 키 접미 + 허용 범위 (§7.6 — `Ctrl+ +/-` 로 12~20px) */
const FONT_SIZE_SUFFIX = '.fontSize';
const FONT_SIZE_MIN = 12;
const FONT_SIZE_MAX = 20;

/** 모바일 경계 — 이 폭 미만이면 글자를 줄이고 줄바꿈을 기본으로 켠다 */
const MOBILE_MAX_WIDTH_EXCLUSIVE = 768;

/**
 * 기기 기본 글자 크기 — 데스크톱 14 / 모바일 13 (§7.6)
 *
 * @param width 렌더 폭(`useRenderWidth` — Provider 폭 우선, 편집기 미리보기에서는 기기 폭, #141)
 * @returns 글자 크기(px)
 */
const deviceDefaultFontSize = (width: number): number => (width < MOBILE_MAX_WIDTH_EXCLUSIVE ? 13 : 14);

/** 문서가 다크 테마인지 — 차이 보기 창은 여는 순간의 테마를 따른다. */
const isDarkDocument = (): boolean =>
  typeof document !== 'undefined' && document.documentElement.classList.contains('dark');

/**
 * 키에서 탭에 쓸 짧은 이름을 뽑습니다.
 *
 * @param key 노드 키 (`layout:partials/a/_b` · `ext:12`)
 * @returns 마지막 세그먼트
 */
function labelFromKey(key: string): string {
  const withoutKind = key.replace(/^(layout|ext):/, '');
  const segments = withoutKind.split('/');

  return segments[segments.length - 1] || withoutKind;
}

/**
 * 파일별 편집 버퍼를 소유하는 작업 공간.
 *
 * ## 무엇을 고치는가
 *
 * 종전에는 편집 버퍼가 전역 변수 **한 벌**(`_global.editorMarkup`)이었다. 파일을 고르면 그
 * 전역을 서버 응답으로 덮어썼으므로, 저장하지 않은 수정이 **아무 경고 없이 사라졌다**.
 *
 * 여기서는 방향을 뒤집는다 — 서버 본문은 `serverValue` prop 으로 **들어오기만** 하고, 편집 중인
 * 값은 이 컴포넌트 안의 `Map<key, Buffer>` 가 소유한다. 그래서 파일을 바꿔도 앞 파일의 버퍼가
 * 그대로 남고, 되돌아오면 편집하던 상태 그대로 이어서 쓴다.
 *
 * ## 버퍼 수명 규칙
 *
 * | 상황 | 동작 |
 * | --- | --- |
 * | 처음 들어온 키 | `serverValue` 로 버퍼를 만든다 (clean) |
 * | 재방문 · dirty | **`serverValue` 를 무시하고 버퍼를 보여준다** |
 * | 재방문 · clean | `serverValue` 로 갱신 (남이 고친 최신본 반영) |
 * | `serverRevision` 증가 + dirty | 충돌 바 — 자동 덮어쓰기 금지 |
 * | 그 판본이 `lastSave` (내 저장) + 서버 정규화로 글자만 다름 | 서버 본문으로 clean. 저장 뒤 더 친 글자가 있으면 기준선만 옮겨 dirty 유지 |
 * | 저장 성공 (판본 증가 + clean 전환) | 초안 삭제 |
 * | 탭 상한 초과 | 오래된 **clean** 탭부터 해제. dirty 탭은 절대 자동으로 닫지 않는다 |
 */
export const CodeWorkspace: React.FC<CodeWorkspaceProps> = ({
  fileKey,
  serverValue = '',
  serverKey,
  serverRevision = 0,
  lastSave,
  fileLabel,
  isLoading = false,
  language = 'html',
  readOnly = false,
  knownKeys,
  retiredKey,
  markers,
  revealAt,
  draftNamespace = 'g7le.codeEditor',
  maxTabs = DEFAULT_MAX_TABS,
  defaultWordWrap,
  onChange,
  onUserEdit,
  onDirtyChange,
  onRequestSelect,
  onSaveAs,
  onSave,
  onSaveAll,
  onPreview,
  onNewFile,
  onVersionHistory,
  onToggleView,
  onQuickOpen,
  onSearchContent,
  onExit,
  className = '',
  id,
  editorAttrs,
  children,
}) => {
  // 렌더 폭 — 기기 기본값(글자 크기·줄바꿈)을 고른다. 창 폭이 아니라 Provider 폭이 우선이다(#141).
  const renderWidth = useRenderWidth();
  const [buffers, setBuffers] = useState<Record<string, Buffer>>({});

  /**
   * 최신 버퍼 맵을 동기적으로 읽기 위한 참조.
   *
   * 편집기 change 핸들러는 «이 값이 사람이 친 것인가» 를 그 자리에서 가려야 하는데, 그 판정을
   * `setBuffers` 업데이터 안에서 하면 부수효과가 되고(StrictMode 이중 호출) 밖에서 `buffers` 를
   * 읽으면 의존성 때문에 핸들러가 매 편집마다 새로 만들어진다. 참조로 읽어 둘 다 피한다.
   */
  const buffersRef = useRef<Record<string, Buffer>>(buffers);
  buffersRef.current = buffers;
  const [openKeys, setOpenKeys] = useState<string[]>([]);
  const [labels, setLabels] = useState<Record<string, string>>({});
  const [conflictKeys, setConflictKeys] = useState<string[]>([]);
  const [helpOpen, setHelpOpen] = useState(false);
  // 모바일은 가로 스크롤 편집이 사실상 불가능하므로 줄바꿈이 기본 ON 이다. 레이아웃이 `defaultWordWrap`
  // 을 주지 않으면 기기 폭으로 정한다 — 엔진 표현식에는 `window` 가 없어 그 판정을 레이아웃에 둘 수 없다.
  // 사용자가 한 번이라도 토글했으면 그 값이 기기 기본값을 이긴다.
  const [wordWrap, setWordWrap] = useState<boolean>(() =>
    readWordWrap(draftNamespace, defaultWordWrap ?? renderWidth < MOBILE_MAX_WIDTH_EXCLUSIVE)
  );
  const [draftBannerOpen, setDraftBannerOpen] = useState(true);

  /**
   * 미저장 탭 닫기 요청 — 3지선다(저장 / 버림 / 취소) 대화상자의 상태 (계획서 §4.3 표 6행).
   * `saving` 이면 «저장하고 닫기» 를 골라 저장 완료를 기다리는 중이다.
   */
  const [closeRequest, setCloseRequest] = useState<{ key: string; saving: boolean; failed?: boolean } | null>(null);
  const closeSaveFiredRef = useRef(false);
  /** «저장하고 닫기» 로 부른 저장이 실제로 진행 중(isLoading)인 것을 한 번이라도 봤는지 — 끝났는데 dirty 면 실패다. */
  const closeSawSavingRef = useRef(false);

  /** 충돌 바의 [차이 보기] — 내 편집분과 서버 본문을 나란히 보여 주는 창 (계획서 §7.7 표 7행) */
  const [diffOpen, setDiffOpen] = useState(false);

  // 모바일은 글자를 한 단계 줄인다(§7.6 — 데스크톱 14 / 모바일 13). 렌더 폭이 바뀌면 따라간다.
  const deviceFontSize = deviceDefaultFontSize(renderWidth);
  // 사용자가 `Ctrl+ +/-` 로 고른 크기(§7.6 — 12~20px). 적혀 있으면 기기 기본값보다 우선한다.
  const [fontSizeOverride, setFontSizeOverride] = useState<number | null>(() => readFontSize(draftNamespace));
  const editorFontSize = fontSizeOverride ?? deviceFontSize;

  const adjustFontSize = useCallback(
    (delta: number) => {
      const next = Math.min(FONT_SIZE_MAX, Math.max(FONT_SIZE_MIN, editorFontSize + delta));

      setFontSizeOverride(next);
      writeFontSize(draftNamespace, next);
    },
    [editorFontSize, draftNamespace]
  );

  const { pendingDrafts, rememberDraft, forgetDraft, forgetAllDrafts, dismissPending } = useFileDrafts({
    namespace: draftNamespace,
  });

  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;
  const onUserEditRef = useRef(onUserEdit);
  onUserEditRef.current = onUserEdit;
  const onDirtyChangeRef = useRef(onDirtyChange);
  onDirtyChangeRef.current = onDirtyChange;
  // 서버 본문 반영 효과가 읽는다 — 의존성에 넣으면 저장 정보가 바뀔 때마다 반영이 다시 돈다.
  const lastSaveRef = useRef(lastSave);
  lastSaveRef.current = lastSave;

  const activeBuffer = buffers[fileKey];

  /**
   * 지금 들고 있는 `serverValue` 가 `fileKey` 의 것인지.
   *
   * 아니면 버퍼가 없을 때의 **폴백으로도 쓰면 안 된다**. 화면에 앞 파일 본문이 보이는 데서
   * 그치지 않고 `onChange` 로 `_global.editorMarkup` 에 실려 **저장 본문이 되기 때문**이다.
   */
  const serverValueBelongsToFile = !serverKey || serverKey === fileKey;
  const activeValue = activeBuffer?.value ?? (serverValueBelongsToFile ? serverValue : '');

  /**
   * 본문을 아직 받지 못한 상태 — 버퍼도 없고 손에 든 `serverValue` 도 다른 파일 것이다.
   *
   * 레이아웃의 데이터소스는 조회 중임을 표현식으로 드러내지 않으므로(진행형 로딩 전략) 이 판정은
   * 컴포넌트가 스스로 한다. 이때 편집기 위에 오버레이를 덮는다(계획서 §7.7 표 4행) — 편집기를
   * 언마운트하지 않는다(모델이 날아간다).
   */
  const isFetching = !activeBuffer && !serverValueBelongsToFile;

  const dirtyKeys = useMemo(
    () => Object.entries(buffers).filter(([, buffer]) => buffer.value !== buffer.base).map(([key]) => key),
    [buffers]
  );

  const isDirty = useCallback((key: string) => dirtyKeys.includes(key), [dirtyKeys]);

  /**
   * 서버가 준 본문을 버퍼에 반영합니다 — **dirty 버퍼는 건드리지 않는다**.
   *
   * 이 한 조건이 제보된 유실의 정면 해결이다. 여기서 무조건 대입하면 종전 `init-global` 과
   * 똑같이 동작한다.
   */
  useEffect(() => {
    if (!fileKey) {
      return;
    }

    // 아직 앞 파일 응답을 들고 있는 렌더다 — 이 값으로 새 파일 버퍼를 만들면 그 내용이 굳는다.
    if (serverKey && serverKey !== fileKey) {
      return;
    }

    setBuffers((current) => {
      const existing = current[fileKey];
      const now = Date.now();

      if (!existing) {
        return {
          ...current,
          [fileKey]: { value: serverValue, base: serverValue, baseRevision: serverRevision, touchedAt: now },
        };
      }

      if (existing.baseUnknown) {
        // 복원해 둔 초안에 이제야 기준선이 생긴다 — 초안은 그대로 두고 서버 본문을 기준선으로 삼는다.
        // 초안을 적은 뒤 누군가 저장했다면(판본이 올랐다) 조용히 고르지 않고 충돌로 세운다.
        if (existing.value === serverValue) {
          return {
            ...current,
            [fileKey]: { value: serverValue, base: serverValue, baseRevision: serverRevision, touchedAt: now },
          };
        }

        if (serverRevision > existing.baseRevision) {
          setConflictKeys((keys) => (keys.includes(fileKey) ? keys : [...keys, fileKey]));
        }

        return {
          ...current,
          [fileKey]: { value: existing.value, base: serverValue, baseRevision: existing.baseRevision, touchedAt: now },
        };
      }

      const dirtyNow = existing.value !== existing.base;

      if (dirtyNow) {
        // 서버 본문이 **내 편집분과 같아졌다** — 방금 내 저장이 반영된 경우다. 판본이 올랐다고
        // 충돌로 세우면 저장할 때마다 "서버가 더 새롭습니다" 가 뜨고 파일이 영영 dirty 로 남는다.
        // 기준선을 새 판본으로 옮겨 clean 으로 되돌린다.
        if (existing.value === serverValue) {
          return {
            ...current,
            [fileKey]: { value: serverValue, base: serverValue, baseRevision: serverRevision, touchedAt: now },
          };
        }

        // 이 판본은 **내 저장**이 만든 것이다 — 서버가 역변환하며 공백·속성 표기를 정규화해 글자가
        // 달라졌을 뿐이다. 보낸 본문 그대로면 서버 본문으로 clean 이 되고, 저장을 보낸 뒤 더 친
        // 글자가 있으면 그 편집분은 남긴 채 기준선만 이 판본으로 옮긴다(아직 저장되지 않았다).
        const saved = lastSaveRef.current;
        if (
          saved?.key === fileKey &&
          typeof saved.revision === 'number' &&
          saved.revision === serverRevision &&
          serverRevision > existing.baseRevision
        ) {
          const next =
            existing.value === saved.value
              ? { value: serverValue, base: serverValue, baseRevision: serverRevision, touchedAt: now }
              : { ...existing, base: serverValue, baseRevision: serverRevision, touchedAt: now };

          return { ...current, [fileKey]: next };
        }

        // 내용까지 달라졌는데 판본이 올랐다면 남이 고친 것이다 — 사용자가 고르게 한다.
        // 조용히 어느 한쪽을 버리지 않는다.
        if (serverRevision > existing.baseRevision) {
          setConflictKeys((keys) => (keys.includes(fileKey) ? keys : [...keys, fileKey]));
        }

        return { ...current, [fileKey]: { ...existing, touchedAt: now } };
      }

      if (existing.base === serverValue && existing.baseRevision === serverRevision) {
        return { ...current, [fileKey]: { ...existing, touchedAt: now } };
      }

      // clean 이면 최신본을 받아들인다 — 저장 직후·남이 고친 뒤가 여기로 온다.
      return {
        ...current,
        [fileKey]: { value: serverValue, base: serverValue, baseRevision: serverRevision, touchedAt: now },
      };
    });

    setOpenKeys((keys) => (keys.includes(fileKey) ? keys : [...keys, fileKey]));
    setLabels((current) => ({ ...current, [fileKey]: fileLabel ?? labelFromKey(fileKey) }));
  }, [fileKey, serverKey, serverValue, serverRevision, fileLabel]);

  /**
   * 이 세션에서 한 번이라도 dirty 였던 키.
   *
   * "clean 이면 초안을 지운다" 는 규칙을 **진입 시점에도** 적용하면, 지난 세션이 남긴 초안을
   * 사용자가 보기도 전에 지워 버린다(복원 배너가 영영 뜨지 않는다). 지울 대상은 *내가 고쳤다가
   * 저장해서* clean 이 된 파일뿐이다.
   */
  const everDirtyRef = useRef<Set<string>>(new Set());

  useEffect(() => {
    for (const key of dirtyKeys) {
      everDirtyRef.current.add(key);
    }
  }, [dirtyKeys]);

  // 저장이 성공해 clean 으로 돌아온 파일은 초안을 지운다 — 남겨 두면 다음 진입에서
  // "저장하지 않은 편집이 있습니다" 가 이미 저장된 내용을 두고 뜬다.
  useEffect(() => {
    if (!fileKey || !everDirtyRef.current.has(fileKey)) {
      return;
    }

    if (!isDirty(fileKey)) {
      everDirtyRef.current.delete(fileKey);
      forgetDraft(fileKey);
      setConflictKeys((keys) => keys.filter((key) => key !== fileKey));
    }
  }, [fileKey, dirtyKeys, isDirty, forgetDraft]);

  // 활성 버퍼를 레이아웃으로 올린다 — 저장·미리보기·내려받기·복사가 읽는 자리다.
  useEffect(() => {
    onChangeRef.current?.({ target: { value: activeValue } });
  }, [activeValue]);

  useEffect(() => {
    onDirtyChangeRef.current?.({ target: { value: dirtyKeys } });
  }, [dirtyKeys]);

  // 미저장이 있으면 새로고침·탭 닫기를 막아 세운다.
  useEffect(() => {
    if (typeof window === 'undefined' || dirtyKeys.length === 0) {
      return;
    }

    const onBeforeUnload = (event: BeforeUnloadEvent): string => {
      event.preventDefault();
      // 크롬은 문구를 무시하고 자기 대화상자를 띄우지만, 옛 브라우저는 반환값을 쓴다.
      event.returnValue = t('admin.templates.layout_edit.draft.leave_warning');

      return event.returnValue;
    };

    window.addEventListener('beforeunload', onBeforeUnload);

    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [dirtyKeys.length]);

  /** 편집 — 버퍼에 쓰고 초안에도 적어 둔다. */
  const handleEditorChange = useCallback(
    (event: { target: { value: string } }) => {
      const next = event.target.value;
      const existing = buffersRef.current[fileKey];

      /*
       * 편집기가 되돌려준 값이 **사람이 친 것이 아닐 때** 여기서 빠져나간다.
       *
       * 편집기는 제어 컴포넌트라 `value` prop 이 바뀌면 그대로 change 를 올려 준다. 파일을 열어
       * 서버 본문이 도착하기 전에는 이 파일의 버퍼가 아직 없어 빈 값이 들어오는데, 그것을 편집으로
       * 치면 **빈 본문으로 `markup-compile` 이 돌아** 탭을 열 때마다 «변환할 마크업(.g7.html)이
       * 필요합니다» 오류 배너가 떴다(2026-09-12 확인). 다른 탭에 갔다 오면 제대로 된 본문으로
       * 다시 돌아 사라지는 것도 같은 이유다.
       *
       * 버퍼가 없거나 값이 그대로면 편집이 아니다 — 초안도 적지 않고 `onUserEdit` 도 올리지 않는다.
       */
      if (!existing || existing.value === next) {
        return;
      }

      setBuffers((current) => {
        const stored = current[fileKey];

        if (!stored || stored.value === next) {
          return current;
        }

        return { ...current, [fileKey]: { ...stored, value: next, touchedAt: Date.now() } };
      });

      rememberDraft(fileKey, next, serverRevision);
      onUserEditRef.current?.({ target: { value: next } });
    },
    [fileKey, serverRevision, rememberDraft]
  );

  /** 탭을 실제로 닫는다 — 버퍼·초안·충돌 표시를 지우고 이웃 탭으로 옮겨 간다. */
  const performClose = useCallback(
    (key: string) => {
      forgetDraft(key);
      setConflictKeys((keys) => keys.filter((candidate) => candidate !== key));
      setBuffers((current) => {
        const next = { ...current };
        delete next[key];

        return next;
      });

      setOpenKeys((keys) => {
        const next = keys.filter((candidate) => candidate !== key);

        // 지금 보고 있던 탭을 닫았으면 이웃으로 옮겨 간다 — 빈 편집기를 남기지 않는다.
        if (key === fileKey && next.length > 0) {
          const index = keys.indexOf(key);
          onRequestSelect?.(next[Math.min(index, next.length - 1)]);
        }

        return next;
      });
    },
    [fileKey, forgetDraft, onRequestSelect]
  );

  // 부모가 살린 키를 알리면 그 탭과 초안을 거둔다 — 이미 열려 있을 때만.
  // 알림 하나는 한 번만 처리한다. performClose 는 지금 파일(fileKey)에 따라 새로 만들어지므로 이 효과는
  // 파일을 옮길 때마다 다시 돈다 — 처리한 키를 기억하지 않으면, 같은 이름의 파일을 새로 만들어 연 뒤
  // 다른 파일로 옮기는 순간 남아 있던 옛 알림이 그 새 탭과 초안까지 거둔다. 부모가 알림을 비우면(빈 값)
  // 기억도 비워, 같은 키를 다시 살리는 다음 알림은 처리한다.
  const handledRetiredKeyRef = useRef<string | null>(null);
  useEffect(() => {
    if (!retiredKey) {
      handledRetiredKeyRef.current = null;
      return;
    }
    if (handledRetiredKeyRef.current === retiredKey) return;
    handledRetiredKeyRef.current = retiredKey;
    if (openKeys.includes(retiredKey)) {
      performClose(retiredKey);
    }
    // openKeys 는 일부러 빼다 — 닫은 뒤 목록이 바뀔 때마다 다시 돌 이유가 없다.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [retiredKey, performClose]);

  /**
   * 탭 닫기 — dirty 면 3지선다(저장 / 버림 / 취소)를 묻는다 (계획서 §4.3 표 6행).
   *
   * 종전에는 `window.confirm` 2지선다라 «저장하고 닫기» 가 없었다 — 저장하려면 취소하고 따로
   * 저장한 뒤 다시 닫아야 했다.
   *
   * @param key 닫을 키
   * @param skipConfirm 확인을 건너뛴다. «없는 파일» 바의 [버리기] 처럼 사용자가 이미 그
   *   자리에서 버리겠다고 고른 경우다 — 같은 결정을 두 번 묻지 않는다.
   */
  const closeTab = useCallback(
    (key: string, skipConfirm = false) => {
      if (!skipConfirm && isDirty(key)) {
        closeSaveFiredRef.current = false;
        setCloseRequest({ key, saving: false });

        return;
      }

      performClose(key);
    },
    [isDirty, performClose]
  );

  /**
   * «저장하고 닫기» — 저장은 활성 버퍼만 하므로 그 탭을 먼저 활성으로 올리고, 저장을 **한 번**
   * 부른 뒤 버퍼가 clean 으로 돌아오면 닫는다. 저장이 실패해 dirty 로 남으면 대화상자가
   * «저장하는 중» 으로 남고 [취소] 로 빠져나온다 — 실패를 삼키고 닫으면 편집분이 사라진다.
   */
  useEffect(() => {
    if (!closeRequest?.saving) {
      return;
    }

    const { key } = closeRequest;

    if (fileKey !== key) {
      return;
    }

    if (isDirty(key)) {
      if (!closeSaveFiredRef.current) {
        closeSaveFiredRef.current = true;
        closeSawSavingRef.current = false;
        onSave?.();

        return;
      }

      if (isLoading) {
        closeSawSavingRef.current = true;

        return;
      }

      // 저장이 끝났는데(진행 → 끝) 여전히 dirty — 서버가 거절했다. 「저장하는 중」 으로 굳히지 않고
      // 실패를 알린 뒤 버튼을 풀어 다시 저장하거나 버리고 닫을 수 있게 한다(편집분은 그대로).
      if (closeSawSavingRef.current) {
        closeSaveFiredRef.current = false;
        closeSawSavingRef.current = false;
        setCloseRequest({ key, saving: false, failed: true });
      }

      return;
    }

    performClose(key);
    setCloseRequest(null);
    closeSaveFiredRef.current = false;
    closeSawSavingRef.current = false;
  }, [closeRequest, fileKey, isDirty, isLoading, onSave, performClose]);

  const requestSaveAndClose = useCallback(() => {
    if (!closeRequest) {
      return;
    }

    closeSaveFiredRef.current = false;
    setCloseRequest({ key: closeRequest.key, saving: true });

    if (closeRequest.key !== fileKey) {
      onRequestSelect?.(closeRequest.key);
    }
  }, [closeRequest, fileKey, onRequestSelect]);

  // 탭 상한 — clean 탭부터 오래된 순으로 해제한다. dirty 탭은 절대 자동으로 닫지 않는다.
  useEffect(() => {
    if (openKeys.length <= maxTabs) {
      return;
    }

    const candidates = openKeys
      .filter((key) => key !== fileKey && !dirtyKeys.includes(key))
      .sort((a, b) => (buffers[a]?.touchedAt ?? 0) - (buffers[b]?.touchedAt ?? 0));

    const victim = candidates[0];

    if (!victim) {
      return;
    }

    setOpenKeys((keys) => keys.filter((key) => key !== victim));
    setBuffers((current) => {
      const next = { ...current };
      delete next[victim];

      return next;
    });
  }, [openKeys, maxTabs, fileKey, dirtyKeys, buffers]);

  /** 충돌 해소 — 서버 본문을 받아들인다. */
  const acceptServer = useCallback(() => {
    setBuffers((current) => {
      const existing = current[fileKey];

      if (!existing) {
        return current;
      }

      return {
        ...current,
        [fileKey]: { value: serverValue, base: serverValue, baseRevision: serverRevision, touchedAt: Date.now() },
      };
    });

    forgetDraft(fileKey);
    setConflictKeys((keys) => keys.filter((key) => key !== fileKey));
  }, [fileKey, serverValue, serverRevision, forgetDraft]);

  /** 충돌 해소 — 내 편집분을 유지하고 기준선만 최신 판본으로 옮긴다. */
  const keepMine = useCallback(() => {
    setBuffers((current) => {
      const existing = current[fileKey];

      if (!existing) {
        return current;
      }

      return { ...current, [fileKey]: { ...existing, base: serverValue, baseRevision: serverRevision } };
    });

    setConflictKeys((keys) => keys.filter((key) => key !== fileKey));
  }, [fileKey, serverValue, serverRevision]);

  /** 초안 복원 — 사용자가 [복원] 을 눌렀을 때만 편집기로 들어간다. */
  const restoreDrafts = useCallback(() => {
    const now = Date.now();

    setBuffers((current) => {
      const next = { ...current };

      for (const [key, draft] of Object.entries(pendingDrafts)) {
        const existing = next[key];

        // 열려 있지 않은 파일은 서버 본문을 아직 모른다 — 기준선은 본문이 도착할 때 채운다.
        const baseUnknown = !existing && key !== fileKey;

        next[key] = {
          value: draft.value,
          base: existing?.base ?? (key === fileKey ? serverValue : ''),
          baseRevision: existing?.baseRevision ?? draft.baseRevision,
          touchedAt: now,
          ...(baseUnknown ? { baseUnknown: true } : {}),
        };
      }

      return next;
    });

    setOpenKeys((keys) => {
      const merged = new Set(keys);
      Object.keys(pendingDrafts).forEach((key) => merged.add(key));

      return [...merged];
    });

    setLabels((current) => {
      const next = { ...current };
      Object.keys(pendingDrafts).forEach((key) => {
        next[key] = next[key] ?? labelFromKey(key);
      });

      return next;
    });

    dismissPending();
  }, [pendingDrafts, fileKey, serverValue, dismissPending]);

  const toggleWordWrap = useCallback(() => {
    setWordWrap((current) => {
      const next = !current;
      writeWordWrap(draftNamespace, next);

      return next;
    });
  }, [draftNamespace]);

  const goToTab = useCallback(
    (offset: number) => {
      if (openKeys.length < 2) {
        return;
      }

      const index = openKeys.indexOf(fileKey);
      const nextIndex = (index + offset + openKeys.length) % openKeys.length;
      onRequestSelect?.(openKeys[nextIndex]);
    },
    [openKeys, fileKey, onRequestSelect]
  );

  /** 미저장이 있으면 되묻는다 — 없으면 그냥 보낸다. */
  const confirmLeave = useCallback((): boolean => {
    if (dirtyKeys.length === 0 || typeof window === 'undefined') {
      return true;
    }

    return window.confirm(t('admin.templates.layout_edit.draft.leave_confirm', { count: dirtyKeys.length }));
  }, [dirtyKeys.length]);

  const handleExit = useCallback(() => {
    // 시트·서랍·도움말이 열려 있으면 그것부터 닫는다 — Esc 한 번에 화면을 떠나 버리지 않는다.
    if (helpOpen) {
      setHelpOpen(false);

      return;
    }

    /*
     * 위에 창이 떠 있으면 Esc 는 **그 창의 것**이다.
     *
     * 판본 이력 같은 창은 레이아웃이 소유해 이 컴포넌트가 알 수 없다. 그래서 `helpOpen` 만 보면
     * 창을 띄운 채 Esc 를 눌렀을 때 창이 아니라 **화면 전체를 떠난다** — 실측 2026-09-12 에
     * 판본 이력을 연 상태로 Esc 를 누르니 편집기가 통째로 닫히고 `/admin/users` 로 나갔다.
     * 누가 띄웠든 열린 대화상자가 있으면 물러난다.
     */
    if (typeof document !== 'undefined' && document.querySelector('[role="dialog"]')) {
      return;
    }

    if (!confirmLeave()) {
      return;
    }

    onExit?.();
  }, [helpOpen, confirmLeave, onExit]);

  /**
   * SPA 이탈 가드 (D5).
   *
   * `beforeunload` 는 **브라우저를 떠날 때만** 발화한다 — 같은 앱 안에서 라우트를 바꾸는 이동은
   * 그 이벤트를 내지 않으므로, 머리말 [취소]나 좌측 메뉴로 나가면 버퍼가 조용히 사라진다.
   *
   * 엔진의 선언형 `confirm` 은 진입점에서 **무조건** 발화해 조건부로 물을 수 없다. 그래서 dirty 여부를
   * 아는 이 컴포넌트가 캡처 단계에서 클릭을 먼저 받아 판단한다. 대상은 `data-leave-guard` 가 붙은
   * 요소뿐이라 화면의 다른 클릭에는 영향이 없다.
   */
  useEffect(() => {
    if (typeof document === 'undefined' || dirtyKeys.length === 0) {
      return;
    }

    const onCapturedClick = (event: MouseEvent): void => {
      const target = event.target;

      if (!(target instanceof Element) || !target.closest('[data-leave-guard]')) {
        return;
      }

      if (confirmLeave()) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      // 같은 요소에 걸린 다른 리스너까지 세워야 이동이 실제로 멈춘다.
      event.stopImmediatePropagation();
    };

    document.addEventListener('click', onCapturedClick, true);

    return () => document.removeEventListener('click', onCapturedClick, true);
  }, [dirtyKeys.length, confirmLeave]);

  /**
   * 브라우저 뒤로가기 가드 (D5).
   *
   * `popstate` 는 이미 이동이 일어난 뒤에 온다 — 막을 수는 없고, 되돌릴 수만 있다. 그래서 지킴이
   * 항목을 하나 쌓아 두고(`pushState`), 뒤로가기가 그것을 소비하면 확인을 물은 뒤 취소 시 다시 쌓는다.
   */
  useEffect(() => {
    if (typeof window === 'undefined' || dirtyKeys.length === 0) {
      return;
    }

    window.history.pushState({ g7CodeEditorGuard: true }, '');

    const onPopState = (): void => {
      if (confirmLeave()) {
        window.history.back();

        return;
      }

      window.history.pushState({ g7CodeEditorGuard: true }, '');
    };

    window.addEventListener('popstate', onPopState);

    return () => window.removeEventListener('popstate', onPopState);
  }, [dirtyKeys.length, confirmLeave]);

  // 읽기 전용이면 쓰기 단축키(저장·모두 저장·새 파일)는 아무것도 하지 않는다 — 머리말 저장 버튼을 숨긴 것과
  // 같은 판정이다. 핸들러를 비우지 않고 빈 함수로 두는 것은 브라우저 기본 동작(페이지 저장·새 창)을 계속 막기 위함이다.
  const blockedWrite = useCallback(() => {}, []);

  const shortcutHandlers = useMemo<CodeShortcutHandlers>(
    () => ({
      save: readOnly ? blockedWrite : onSave,
      saveAll: readOnly ? blockedWrite : onSaveAll,
      preview: onPreview,
      versionHistory: onVersionHistory,
      exit: handleExit,
      newFile: readOnly ? blockedWrite : onNewFile,
      quickOpen: onQuickOpen,
      searchContent: onSearchContent,
      prevTab: () => goToTab(-1),
      nextTab: () => goToTab(1),
      closeTab: () => closeTab(fileKey),
      toggleView: onToggleView,
      toggleWordWrap,
      fontSizeUp: () => adjustFontSize(1),
      fontSizeDown: () => adjustFontSize(-1),
      help: () => setHelpOpen((open) => !open),
    }),
    [
      readOnly,
      blockedWrite,
      onSave,
      onSaveAll,
      onPreview,
      onVersionHistory,
      handleExit,
      onNewFile,
      onQuickOpen,
      onSearchContent,
      goToTab,
      closeTab,
      fileKey,
      onToggleView,
      toggleWordWrap,
      adjustFontSize,
    ]
  );

  useCodeEditorShortcuts({ handlers: shortcutHandlers });

  // Monaco 가 선점하는 조합은 편집기 안에서도 같은 동작이 나게 재바인딩한다 —
  // 창 리스너만 달면 편집기에 포커스가 있을 때 동작이 갈린다.
  const editorCommands = useMemo<CodeEditorCommand[]>(() => {
    const commands: CodeEditorCommand[] = [];

    for (const spec of CODE_EDITOR_SHORTCUTS) {
      const handler = shortcutHandlers[spec.id];

      if (!handler) {
        continue;
      }

      // `mod+s` 는 Monaco 가 잡지 않지만, 편집기 안에서 눌렀을 때 브라우저의 "페이지 저장" 이
      // 뜨는 것을 막으려면 여기에도 걸어야 한다.
      if (!spec.rebindInEditor && spec.id !== 'save' && spec.id !== 'saveAll') {
        continue;
      }

      for (const combo of spec.combos) {
        commands.push({ key: combo.key, mod: combo.mod, shift: combo.shift, alt: combo.alt, run: handler });
      }
    }

    return commands;
  }, [shortcutHandlers]);

  /**
   * 서버에 더 이상 없는 열린 키.
   *
   * `knownKeys` 를 주지 않았거나 아직 비어 있으면 **아무것도 없는 파일로 보지 않는다** —
   * 목록이 도착하기 전의 한 렌더에서 열린 탭을 전부 «없는 파일» 로 몰면, 멀쩡한 파일이
   * 잠깐 읽기 전용으로 잠긴다.
   */
  const missingKeys = useMemo(
    () => (knownKeys && knownKeys.length > 0 ? openKeys.filter((key) => !knownKeys.includes(key)) : []),
    [knownKeys, openKeys]
  );
  const isActiveMissing = missingKeys.includes(fileKey);

  const tabs = useMemo<FileTabItem[]>(
    () =>
      openKeys.map((key) => ({
        key,
        label: labels[key] ?? labelFromKey(key),
        title: key.replace(/^(layout|ext):/, ''),
        dirty: dirtyKeys.includes(key),
        missing: missingKeys.includes(key),
      })),
    [openKeys, labels, dirtyKeys, missingKeys]
  );

  const pendingDraftKeys = Object.keys(pendingDrafts);
  const showDraftBanner = draftBannerOpen && pendingDraftKeys.length > 0;
  const showConflict = conflictKeys.includes(fileKey);
  const isMac = isMacPlatform();

  return (
    <Div id={id} className={`flex flex-col min-h-0 ${className}`} {...(editorAttrs ?? {})}>
      <FileTabs
        tabs={tabs}
        activeKey={fileKey}
        onSelect={(key) => onRequestSelect?.(key)}
        onClose={closeTab}
      />

      {showDraftBanner && (
        <Div
          role="status"
          data-testid="draft-restore-banner"
          className="m-3 p-3 rounded-lg border border-blue-500 bg-blue-50 dark:bg-blue-900/30 text-sm flex items-start gap-3"
        >
          <Icon name="fa-solid fa-rotate-left" className="text-base text-blue-600 dark:text-blue-400 mt-0.5" />
          <Div className="flex-1 min-w-0">
            <Span className="block font-medium text-gray-900 dark:text-gray-100">
              {t('admin.templates.layout_edit.draft.found', { count: pendingDraftKeys.length })}
            </Span>
            <Span className="block mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate">
              {pendingDraftKeys.map((key) => labelFromKey(key)).join(' · ')}
            </Span>
            <Div className="flex gap-2 mt-2">
              <Button
                type="button"
                data-testid="draft-restore"
                className="px-3 py-1 text-xs rounded-lg bg-gray-900 dark:bg-white text-white dark:text-gray-900"
                onClick={() => {
                  restoreDrafts();
                  setDraftBannerOpen(false);
                }}
              >
                {t('admin.templates.layout_edit.draft.restore')}
              </Button>
              <Button
                type="button"
                data-testid="draft-discard"
                className="px-3 py-1 text-xs rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300"
                onClick={() => {
                  forgetAllDrafts();
                  setDraftBannerOpen(false);
                }}
              >
                {t('admin.templates.layout_edit.draft.discard')}
              </Button>
            </Div>
          </Div>
        </Div>
      )}

      {isActiveMissing && (
        <Div
          role="alert"
          data-testid="missing-file-bar"
          className="m-3 p-3 rounded-lg border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/30 text-sm flex items-start gap-3"
        >
          <Icon name="fa-solid fa-link-slash" className="text-base text-red-600 dark:text-red-400 mt-0.5" />
          <Div className="flex-1 min-w-0">
            <Span className="block font-medium text-red-800 dark:text-red-300">
              {t('admin.templates.layout_edit.file.missing_title')}
            </Span>
            <Span className="block mt-0.5 text-xs text-red-700 dark:text-red-400">
              {t('admin.templates.layout_edit.file.missing_draft_notice')}
            </Span>
            <Div className="flex gap-2 mt-2">
              {/* 다른 이름으로 저장은 새 파일을 만드는 쓰기 동작이다 — 읽기 전용이면 보이지 않는다 */}
              {!readOnly && (
                <Button
                  type="button"
                  data-testid="missing-save-as"
                  className="px-3 py-1 text-xs rounded-lg bg-gray-900 dark:bg-white text-white dark:text-gray-900"
                  onClick={() => onSaveAs?.({ target: { value: activeBuffer?.value ?? '', key: fileKey } })}
                >
                  {t('admin.templates.layout_edit.file.save_as')}
                </Button>
              )}
              <Button
                type="button"
                data-testid="missing-discard"
                className="px-3 py-1 text-xs rounded-lg border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300"
                onClick={() => closeTab(fileKey, true)}
              >
                {t('admin.templates.layout_edit.file.discard_draft')}
              </Button>
            </Div>
          </Div>
        </Div>
      )}

      {showConflict && (
        <Div
          role="alert"
          data-testid="conflict-bar"
          className="m-3 p-3 rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 text-sm flex items-start gap-3"
        >
          <Icon name="fa-solid fa-triangle-exclamation" className="text-base text-amber-600 dark:text-amber-400 mt-0.5" />
          <Div className="flex-1 min-w-0">
            <Span className="block font-medium text-amber-800 dark:text-amber-300">
              {t('admin.templates.layout_edit.conflict.title')}
            </Span>
            <Span className="block mt-0.5 text-xs text-amber-700 dark:text-amber-400">
              {t('admin.templates.layout_edit.conflict.description', {
                mine: activeBuffer?.baseRevision ?? 0,
                server: serverRevision,
              })}
            </Span>
            <Div className="flex gap-2 mt-2">
              <Button
                type="button"
                data-testid="conflict-keep-mine"
                className="px-3 py-1 text-xs rounded-lg border border-amber-400 dark:border-amber-600 text-amber-800 dark:text-amber-300"
                onClick={keepMine}
              >
                {t('admin.templates.layout_edit.conflict.keep_mine')}
              </Button>
              <Button
                type="button"
                data-testid="conflict-take-server"
                className="px-3 py-1 text-xs rounded-lg border border-amber-400 dark:border-amber-600 text-amber-800 dark:text-amber-300"
                onClick={acceptServer}
              >
                {t('admin.templates.layout_edit.conflict.take_server')}
              </Button>
              <Button
                type="button"
                data-testid="conflict-view-diff"
                className="px-3 py-1 text-xs rounded-lg border border-amber-400 dark:border-amber-600 text-amber-800 dark:text-amber-300"
                onClick={() => setDiffOpen(true)}
              >
                {t('admin.templates.layout_edit.conflict.view_diff')}
              </Button>
            </Div>
          </Div>
        </Div>
      )}

      <Div className="flex items-center gap-1 px-3 py-1.5 border-b border-gray-200 dark:border-gray-700 shrink-0">
        {children}
        <Span className="flex-1" />
        <Button
          type="button"
          data-testid="word-wrap-toggle"
          aria-pressed={wordWrap}
          title={t('admin.templates.layout_edit.wordwrap.toggle')}
          className={`flex items-center gap-1.5 px-2 py-1 rounded-md text-xs ${
            wordWrap
              ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 font-semibold'
              : 'text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700'
          }`}
          onClick={toggleWordWrap}
        >
          <Icon name="fa-solid fa-arrow-turn-down" className="text-[11px]" />
          <Span className="hidden sm:inline">{t('admin.templates.layout_edit.wordwrap.label')}</Span>
        </Button>
        <Button
          type="button"
          data-testid="shortcut-help-open"
          aria-label={t('admin.templates.layout_edit.shortcuts.title')}
          title={t('admin.templates.layout_edit.shortcuts.title')}
          className="px-2 py-1 rounded-md text-xs text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"
          onClick={() => setHelpOpen(true)}
        >
          <Icon name="fa-regular fa-keyboard" className="text-[11px]" />
        </Button>
      </Div>

      <Div className="relative flex-1 min-h-0">
        <CodeEditor
          value={activeValue}
          modelKey={fileKey}
          language={language}
          readOnly={readOnly || isActiveMissing}
          markers={markers}
          revealAt={revealAt}
          wordWrap={wordWrap}
          fontSize={editorFontSize}
          theme="auto"
          height="100%"
          commands={editorCommands}
          onChange={handleEditorChange}
        />

        {(isLoading || isFetching) && (
          <Div
            aria-busy="true"
            data-testid="editor-loading-overlay"
            className="absolute inset-0 flex items-center justify-center bg-white/60 dark:bg-gray-900/60 text-sm text-gray-600 dark:text-gray-300"
          >
            {t('common.loading')}
          </Div>
        )}
      </Div>

      {closeRequest && (
        <Div
          role="dialog"
          aria-modal="true"
          aria-label={t('admin.templates.layout_edit.draft.close_title', {
            name: labels[closeRequest.key] ?? labelFromKey(closeRequest.key),
          })}
          data-testid="close-dialog"
          className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 dark:bg-gray-950/70 p-6"
          onClick={() => {
            setCloseRequest(null);
            closeSaveFiredRef.current = false;
          }}
        >
          <Div
            className="w-[440px] max-w-full rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-2xl p-5"
            onClick={(event: React.MouseEvent) => event.stopPropagation()}
          >
            <Span className="block font-medium text-gray-900 dark:text-gray-100">
              {t('admin.templates.layout_edit.draft.close_title', {
                name: labels[closeRequest.key] ?? labelFromKey(closeRequest.key),
              })}
            </Span>
            <Span className="block mt-1 text-xs text-gray-500 dark:text-gray-400" aria-live="polite">
              {closeRequest.saving
                ? t('admin.templates.layout_edit.draft.close_saving')
                : closeRequest.failed
                  ? t('admin.templates.layout_edit.draft.close_failed')
                  : t('admin.templates.layout_edit.draft.close_question')}
            </Span>
            <Div className="flex flex-wrap justify-end gap-2 mt-4">
              <Button
                type="button"
                data-testid="close-cancel"
                className="px-3 py-1.5 text-xs rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300"
                onClick={() => {
                  setCloseRequest(null);
                  closeSaveFiredRef.current = false;
                }}
              >
                {t('admin.templates.layout_edit.draft.close_cancel')}
              </Button>
              <Button
                type="button"
                data-testid="close-discard"
                disabled={closeRequest.saving}
                className="px-3 py-1.5 text-xs rounded-lg border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 disabled:opacity-50"
                onClick={() => {
                  performClose(closeRequest.key);
                  setCloseRequest(null);
                }}
              >
                {t('admin.templates.layout_edit.draft.close_discard')}
              </Button>
              {onSave && (
                <Button
                  type="button"
                  data-testid="close-save"
                  disabled={closeRequest.saving}
                  className="px-3 py-1.5 text-xs rounded-lg bg-gray-900 dark:bg-white text-white dark:text-gray-900 disabled:opacity-50"
                  onClick={requestSaveAndClose}
                >
                  {t('admin.templates.layout_edit.draft.close_save')}
                </Button>
              )}
            </Div>
          </Div>
        </Div>
      )}

      {diffOpen && (
        <Div
          role="dialog"
          aria-modal="true"
          aria-label={t('admin.templates.layout_edit.conflict.diff_title')}
          data-testid="conflict-diff"
          className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 dark:bg-gray-950/70 p-6"
          onClick={() => setDiffOpen(false)}
        >
          <Div
            className="w-[1100px] max-w-full h-[80vh] flex flex-col rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden"
            onClick={(event: React.MouseEvent) => event.stopPropagation()}
          >
            <Div className="flex items-center gap-3 px-4 py-3 border-b border-gray-200 dark:border-gray-700 shrink-0">
              <Span className="font-medium text-gray-900 dark:text-gray-100">
                {t('admin.templates.layout_edit.conflict.diff_title')}
              </Span>
              <Span className="flex-1 text-xs text-gray-500 dark:text-gray-400 truncate">
                {t('admin.templates.layout_edit.conflict.diff_legend', {
                  mine: activeBuffer?.baseRevision ?? 0,
                  server: serverRevision,
                })}
              </Span>
              <Button
                type="button"
                data-testid="conflict-diff-close"
                className="px-3 py-1 text-xs rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300"
                onClick={() => setDiffOpen(false)}
              >
                {t('admin.templates.layout_edit.conflict.diff_close')}
              </Button>
            </Div>
            <Div className="flex-1 min-h-0">
              <DiffEditor
                original={serverValue}
                modified={activeBuffer?.value ?? ''}
                language={language}
                theme={isDarkDocument() ? 'vs-dark' : 'vs'}
                height="100%"
                options={{ readOnly: true, originalEditable: false, renderSideBySide: true, minimap: { enabled: false } }}
              />
            </Div>
          </Div>
        </Div>
      )}

      {helpOpen && (
        <Div
          role="dialog"
          aria-modal="true"
          aria-label={t('admin.templates.layout_edit.shortcuts.title')}
          data-testid="shortcut-help"
          className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 dark:bg-gray-950/70 p-6"
          onClick={() => setHelpOpen(false)}
        >
          <Div
            className="w-[760px] max-w-full max-h-full overflow-auto rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-2xl"
            onClick={(event: React.MouseEvent) => event.stopPropagation()}
          >
            <Div className="flex items-center gap-3 px-5 py-4 border-b border-gray-200 dark:border-gray-700">
              <Span className="flex-1 font-medium text-gray-900 dark:text-gray-100">
                {t('admin.templates.layout_edit.shortcuts.title')}
              </Span>
              <Button
                type="button"
                aria-label={t('common.close')}
                className="px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300"
                onClick={() => setHelpOpen(false)}
              >
                <Icon name="fa-solid fa-xmark" className="text-xs" />
              </Button>
            </Div>
            <Div className="grid grid-cols-1 sm:grid-cols-2 gap-x-7 px-5 py-4">
              {CODE_SHORTCUT_GROUP_ORDER.map((group) => {
                const specs = CODE_EDITOR_SHORTCUTS.filter((spec) => spec.group === group);
                const owned = EDITOR_OWNED_SHORTCUTS.filter((entry) => entry.group === group);

                if (specs.length === 0 && owned.length === 0) {
                  return null;
                }

                return (
                  <Div key={group}>
                    <Span className="block mt-3 mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                      {t(CODE_SHORTCUT_GROUP_LABEL[group])}
                    </Span>
                    {specs.map((spec) => (
                      <Div
                        key={spec.id}
                        className="flex justify-between gap-4 py-1 text-xs border-b border-dashed border-gray-200 dark:border-gray-700"
                      >
                        <Span className="text-gray-700 dark:text-gray-300">{t(spec.labelKey)}</Span>
                        <Span className="font-mono text-gray-500 dark:text-gray-400 whitespace-nowrap">
                          {spec.combos.map((combo) => formatCodeCombo(combo, isMac)).join(' · ')}
                        </Span>
                      </Div>
                    ))}
                    {owned.map((entry) => (
                      <Div
                        key={entry.labelKey}
                        className="flex justify-between gap-4 py-1 text-xs border-b border-dashed border-gray-200 dark:border-gray-700"
                      >
                        <Span className="text-gray-700 dark:text-gray-300">{t(entry.labelKey)}</Span>
                        <Span className="font-mono text-gray-500 dark:text-gray-400 whitespace-nowrap">
                          {formatCodeCombo(entry.combo, isMac)}
                        </Span>
                      </Div>
                    ))}
                  </Div>
                );
              })}
            </Div>
          </Div>
        </Div>
      )}
    </Div>
  );
};

/**
 * 저장해 둔 줄바꿈 설정을 읽습니다.
 *
 * 사용자가 한 번이라도 토글했으면 그 값이 기기 기본값을 이긴다 — 모바일 기본 ON 은
 * "아무것도 고르지 않았을 때" 의 값이지 강제가 아니다.
 *
 * @param namespace 저장 네임스페이스
 * @param fallback 기기 기본값
 * @returns 줄바꿈 여부
 */
function readWordWrap(namespace: string, fallback: boolean): boolean {
  if (typeof window === 'undefined') {
    return fallback;
  }

  try {
    const raw = window.localStorage.getItem(`${namespace}${WORD_WRAP_SUFFIX}`);

    return raw === null ? fallback : raw === '1';
  } catch {
    return fallback;
  }
}

/**
 * 줄바꿈 설정을 적어 둡니다.
 *
 * @param namespace 저장 네임스페이스
 * @param value 줄바꿈 여부
 * @returns 없음
 */
function writeWordWrap(namespace: string, value: boolean): void {
  if (typeof window === 'undefined') {
    return;
  }

  try {
    window.localStorage.setItem(`${namespace}${WORD_WRAP_SUFFIX}`, value ? '1' : '0');
  } catch {
    // 설정 기억은 편의 장치다 — 적어 두지 못해도 편집은 그대로 동작한다.
  }
}

/**
 * 적어 둔 글자 크기를 읽습니다 (§7.6 — 사용자가 고른 값은 기기 기본값보다 우선).
 *
 * @param namespace 저장 네임스페이스
 * @returns 12~20 사이의 값, 없거나 범위 밖이면 null
 */
function readFontSize(namespace: string): number | null {
  if (typeof window === 'undefined') {
    return null;
  }

  try {
    const parsed = Number.parseInt(window.localStorage.getItem(`${namespace}${FONT_SIZE_SUFFIX}`) ?? '', 10);

    return Number.isInteger(parsed) && parsed >= FONT_SIZE_MIN && parsed <= FONT_SIZE_MAX ? parsed : null;
  } catch {
    return null;
  }
}

/**
 * 글자 크기를 적어 둡니다.
 *
 * @param namespace 저장 네임스페이스
 * @param value 글자 크기(px)
 * @returns 없음
 */
function writeFontSize(namespace: string, value: number): void {
  if (typeof window === 'undefined') {
    return;
  }

  try {
    window.localStorage.setItem(`${namespace}${FONT_SIZE_SUFFIX}`, String(value));
  } catch {
    // 설정 기억은 편의 장치다 — 적어 두지 못해도 편집은 그대로 동작한다.
  }
}
