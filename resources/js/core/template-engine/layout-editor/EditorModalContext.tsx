// e2e:allow(기반 인프라) — spec/엔진/훅/공용 부품만 변경, 브라우저 가시
// UI 미연결(페이지 설정 탭·셸·진입점은 세션 D). Playwright E2E 는 세션 D 에서 추가.
/**
 * EditorModalContext.tsx — 편집기 전용 모달 스택 컨텍스트
 *
 * 결정 사항:
 *  - 위치: layout-editor 도메인 하위. 코어 글로벌 `_global.modal` 시스템과 분리 —
 *    편집기 캔버스의 격리 store/facade(installPreviewCanvasStore) 와 충돌 회피.
 *  - 백드롭 클릭 / ESC = 닫기 (대다수 편집기 패턴).
 *  - 스택 depth 무제한 — 속성 편집 모달 위에 sub-picker, 그 위에 confirm 등 가능.
 *
 * 편집기 안에서 모달이 자주 쓰일 예정이므로 (요소 추가 / 속성 편집 / 409 충돌
 * 안내 / 미리보기 / dirty 가드 등) 공용 인프라로 둠. 호출자는 `useEditorModal()`
 * 의 `open(node)` / `close(id)` / `closeTop()` / `closeAll()` 만 다룸.
 *
 * @since engine-v1.50.0
 */

import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';

/**
 * 모달 표시 옵션 — 호출자가 `open(...)` 에 전달.
 */
export interface EditorModalOptions {
  /** 모달 식별자 — 미지정 시 자동 생성. 같은 id 재오픈은 idempotent (덮어쓰기) */
  id?: string;
  /** 모달 본문 (header/footer 포함 — EditorModal 컴포넌트가 dialog frame 만 제공) */
  content: React.ReactNode;
  /** ARIA label — 스크린리더용 */
  ariaLabel?: string;
  /**
   * 백드롭 클릭 시 닫힘 여부. 기본 true.
   * false 로 두면 명시적 close 호출만 닫음 (정보성 모달 / 강제 확인).
   */
  closeOnBackdrop?: boolean;
  /**
   * ESC 키로 닫힘 여부. 기본 true.
   * false 로 두면 ESC 무시 (격리 입력 모드 등).
   */
  closeOnEscape?: boolean;
  /**
   * 모달 width — px 또는 'auto'. 기본 'auto'.
   * 큰 모달(팔레트/속성 편집)은 명시 픽셀 권장.
   */
  width?: number | string;
  /**
   * 모달 height — px 또는 'auto'. 기본 'auto'.
   */
  height?: number | string;
  /**
   * 모달 max-height — viewport 비율 (0~1) 또는 px. 기본 0.85 (viewport 의 85%).
   */
  maxHeightRatio?: number;
  /**
   * 헤더 드래그로 모달 위치를 옮길 수 있는지. 기본 false.
   * true 면 (1) 헤더(`g7le-modal__drag-handle` 또는 모달 상단 영역) pointerdown 으로
   * 이동, (2) 백드롭은 `pointerEvents:none` 으로 두어 캔버스 결과를 보며 이동,
   * (3) 초기 위치는 `avoidRect`(선택 요소) 를 피해 좌/우 빈 공간 중 넓은 쪽.
   */
  draggable?: boolean;
  /**
   * draggable 모달이 가리지 말아야 할 영역 — 선택 요소의 viewport 좌표.
   * 좌/우 빈 공간 중 모달 폭이 들어가는 넓은 쪽에 초기 배치한다. 양쪽 다 좁으면
   * 중앙 유지(요청 사양). 미지정 시 중앙.
   */
  avoidRect?: { left: number; top: number; width: number; height: number };
  /** 모달이 닫힐 때 호출 (백드롭/ESC/명시 close 모두) */
  onClose?: () => void;
}

interface InternalEntry
  extends Required<
    Omit<
      EditorModalOptions,
      'id' | 'onClose' | 'width' | 'height' | 'maxHeightRatio' | 'avoidRect'
    >
  > {
  id: string;
  onClose?: () => void;
  width: number | string;
  height: number | string;
  maxHeightRatio: number;
  draggable: boolean;
  avoidRect?: { left: number; top: number; width: number; height: number };
}

export interface EditorModalContextValue {
  /** 모달 열기 — id 반환 (호출자가 나중에 close 호출 시 사용) */
  open: (options: EditorModalOptions) => string;
  /** 특정 id 모달 닫기 */
  close: (id: string) => void;
  /** 스택 최상위 모달 닫기 (ESC/백드롭의 기본 동작과 동일) */
  closeTop: () => void;
  /** 전체 모달 닫기 */
  closeAll: () => void;
  /** 현재 열려 있는 모달 스택 (디버그/테스트용) */
  stack: ReadonlyArray<InternalEntry>;
  /**
   * 모달을 최소화한다. **언마운트하지 않고** display:none +
   * 하단 바로 접는다 — 모달 내부 입력값·활성 탭·picker 진행 상태가 전부 보존된다. picker 가
   * 캔버스를 선택하려면 그 위 모달이 비켜야 하므로(범용 기능 — picker 전용 아님).
   *
   * @param id 최소화할 모달 id
   * @param label 하단 바에 표시할 라벨(미지정 시 ariaLabel)
   */
  minimize: (id: string, label?: string) => void;
  /**
   * 최소화된 모달을 복원한다.
   *
   * @param id 복원할 모달 id
   */
  restore: (id: string) => void;
  /** 최소화된 모달 id 목록 (하단 바 렌더용) */
  minimizedStack: ReadonlyArray<string>;
}

const EditorModalContext = createContext<EditorModalContextValue | null>(null);

let nextAutoId = 1;

export interface EditorModalProviderProps {
  children: React.ReactNode;
  /**
   * 다국어 해석 함수 — 최소화 바의 사용자 대면 문구(복원/기본 라벨) 해석용.
   * 미지정 시 키 폴백(identity). LayoutEditorChrome 이 `t` 를 전달한다.
   */
  t?: (key: string) => string;
}

/**
 * Provider — LayoutEditorChrome 의 최상단에서 한 번만 마운트.
 *
 * Provider 자체는 모달 DOM 을 렌더하지 않는다 — `EditorModalRoot` 가 분리
 * 마운트되어 portal-style 로 caller subtree 최상위에 표시. 두 컴포넌트는
 * 같은 Context 인스턴스를 공유해 state 가 단일 진실 공급원.
 */
export function EditorModalProvider({ children, t }: EditorModalProviderProps): React.ReactElement {
  const [stack, setStack] = useState<InternalEntry[]>([]);
  const stackRef = useRef<InternalEntry[]>([]);
  // 최소화 상태 — id → 하단 바 라벨. 언마운트 X(display:none + 하단 바).
  const [minimized, setMinimized] = useState<Record<string, string>>({});

  const open = useCallback((options: EditorModalOptions): string => {
    const id = options.id ?? `editor-modal-${nextAutoId++}`;
    const entry: InternalEntry = {
      id,
      content: options.content,
      ariaLabel: options.ariaLabel ?? '',
      closeOnBackdrop: options.closeOnBackdrop ?? true,
      closeOnEscape: options.closeOnEscape ?? true,
      width: options.width ?? 'auto',
      height: options.height ?? 'auto',
      maxHeightRatio: options.maxHeightRatio ?? 0.85,
      draggable: options.draggable ?? false,
      avoidRect: options.avoidRect,
      onClose: options.onClose,
    };
    setStack((prev) => {
      // 같은 id 재오픈은 덮어쓰기 (idempotent)
      const filtered = prev.filter((e) => e.id !== id);
      const next = [...filtered, entry];
      stackRef.current = next;
      return next;
    });
    return id;
  }, []);

  const close = useCallback((id: string): void => {
    setStack((prev) => {
      const target = prev.find((e) => e.id === id);
      const next = prev.filter((e) => e.id !== id);
      stackRef.current = next;
      // setState 이후 비동기로 onClose 호출 — 콜백 안에서 다시 open/close 호출 시
      // 안전성 보장 (현재 reducer 가 끝난 후 실행)
      if (target?.onClose) {
        queueMicrotask(() => target.onClose?.());
      }
      return next;
    });
  }, []);

  const closeTop = useCallback((): void => {
    const top = stackRef.current[stackRef.current.length - 1];
    if (top) close(top.id);
  }, [close]);

  const closeAll = useCallback((): void => {
    setStack((prev) => {
      // 닫히는 순서대로 onClose 호출 (top → bottom)
      for (let i = prev.length - 1; i >= 0; i--) {
        const entry = prev[i];
        if (entry?.onClose) {
          queueMicrotask(() => entry.onClose?.());
        }
      }
      stackRef.current = [];
      return [];
    });
  }, []);

  const minimize = useCallback((id: string, label?: string): void => {
    setMinimized((prev) => {
      const entry = stackRef.current.find((e) => e.id === id);
      const text = label ?? entry?.ariaLabel ?? '';
      return { ...prev, [id]: text };
    });
  }, []);

  const restore = useCallback((id: string): void => {
    setMinimized((prev) => {
      if (!(id in prev)) return prev;
      const next = { ...prev };
      delete next[id];
      return next;
    });
  }, []);

  // close/closeAll 시 최소화 상태도 함께 정리(잔존 하단 바 방지).
  const closeAndUnminimize = useCallback(
    (id: string): void => {
      restore(id);
      close(id);
    },
    [restore, close],
  );

  const closeAllAndClear = useCallback((): void => {
    setMinimized({});
    closeAll();
  }, [closeAll]);

  const minimizedStack = useMemo<string[]>(() => Object.keys(minimized), [minimized]);

  const value = useMemo<EditorModalContextValue>(
    () => ({
      open,
      close: closeAndUnminimize,
      closeTop,
      closeAll: closeAllAndClear,
      stack,
      minimize,
      restore,
      minimizedStack,
    }),
    [open, closeAndUnminimize, closeTop, closeAllAndClear, stack, minimize, restore, minimizedStack]
  );

  return React.createElement(
    EditorModalContext.Provider,
    { value },
    children,
    React.createElement(MinimizedBar, { minimized, onRestore: restore, t, key: '__min_bar' }),
  );
}

/**
 * 호출자가 모달을 열고 닫는 진입점.
 *
 * Provider 외부에서 호출 시 throw — 마운트 순서가 잘못된 경우 fail-fast.
 */
export function useEditorModal(): EditorModalContextValue {
  const ctx = useContext(EditorModalContext);
  if (!ctx) {
    throw new Error('useEditorModal must be used within EditorModalProvider');
  }
  return ctx;
}

/**
 * EditorModalRoot — 모달 스택을 실제 DOM 으로 렌더.
 *
 * Provider 와 같은 Context 인스턴스를 공유하며, 호출자가 LayoutEditorChrome 최상위
 * (Provider 안쪽 + 본문보다 뒤) 에 1회 마운트. portal 없이 자식으로 그리되,
 * `position: fixed` + 최상위 z-index 로 모든 콘텐츠 위에 표시.
 *
 * ESC 키는 가장 위 모달의 closeOnEscape 옵션에 따라 처리. input/textarea 포커스
 * 중에도 ESC 는 모달 닫기로 가로챔 (편집기 입력은 인라인 텍스트 등 별도 흐름).
 */
export function EditorModalRoot(): React.ReactElement | null {
  const ctx = useContext(EditorModalContext);
  if (!ctx) {
    // Provider 없는 환경(테스트 등)에서는 단순 null 렌더
    return null;
  }
  const { stack, close, minimizedStack } = ctx;
  const minimizedSet = useMemo(() => new Set(minimizedStack), [minimizedStack]);

  // ESC 키 전역 핸들러 — 가장 위(최소화 안 된) 모달이 closeOnEscape=true 면 닫기.
  useEffect(() => {
    if (stack.length === 0) return;
    const handler = (e: KeyboardEvent): void => {
      if (e.key !== 'Escape' && e.key !== 'Esc') return;
      // 최소화된 모달은 ESC 대상에서 제외(화면에 없음).
      const visible = stack.filter((s) => !minimizedSet.has(s.id));
      const top = visible[visible.length - 1];
      if (!top?.closeOnEscape) return;
      e.preventDefault();
      e.stopPropagation();
      close(top.id);
    };
    window.addEventListener('keydown', handler, true);
    return () => window.removeEventListener('keydown', handler, true);
  }, [stack, close, minimizedSet]);

  if (stack.length === 0) return null;

  return (
    <>
      {stack.map((entry, index) => (
        <ModalLayer
          key={entry.id}
          entry={entry}
          depth={index}
          // 최소화된 모달은 display:none 으로 숨기되 언마운트하지 않는다(상태 보존).
          hidden={minimizedSet.has(entry.id)}
          onClose={() => close(entry.id)}
        />
      ))}
    </>
  );
}

/**
 * MinimizedBar — 화면 하단의 최소화된 모달 복원 바.
 *
 * Provider 가 children 뒤에 렌더한다. 최소화된 모달이 없으면 null. 각 항목 클릭 시 복원.
 *
 * @param minimized id → 라벨 맵
 * @param onRestore 복원 콜백
 */
function MinimizedBar({
  minimized,
  onRestore,
  t,
}: {
  minimized: Record<string, string>;
  onRestore: (id: string) => void;
  t?: (key: string) => string;
}): React.ReactElement | null {
  const tr = t ?? ((k: string) => k);
  const ids = Object.keys(minimized);
  if (ids.length === 0) return null;
  return (
    <div
      className="g7le-minimized-bar"
      data-testid="g7le-minimized-bar"
      style={{
        position: 'fixed',
        left: 16,
        bottom: 16,
        display: 'flex',
        gap: 8,
        zIndex: 9300,
      }}
    >
      {ids.map((id) => (
        <button
          key={id}
          type="button"
          data-testid={`g7le-minimized-${id}`}
          onClick={() => onRestore(id)}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 6,
            padding: '8px 12px',
            background: '#1e293b',
            color: '#fff',
            border: 'none',
            borderRadius: 8,
            boxShadow: '0 8px 24px rgba(15, 23, 42, 0.3)',
            cursor: 'pointer',
            fontSize: 13,
          }}
        >
          <span aria-hidden="true">🗗</span>
          <span>{minimized[id] || tr('layout_editor.modal.minimized_default')}</span>
          <span style={{ opacity: 0.7 }}>{tr('layout_editor.modal.restore')}</span>
        </button>
      ))}
    </div>
  );
}

interface ModalLayerProps {
  entry: InternalEntry;
  depth: number;
  /** 최소화 시 display:none (언마운트 X — 상태 보존) */
  hidden?: boolean;
  onClose: () => void;
}

/**
 * draggable 모달의 초기 위치를 계산.
 *
 * `avoidRect`(선택 요소 viewport 좌표) 의 좌/우 빈 공간 중 모달 폭이 들어가는
 * 넓은 쪽에 배치한다. 양쪽 다 모달 폭보다 좁으면 null(중앙 유지 — 요청 사양).
 * 세로는 선택 요소 상단에 맞추되 viewport 안으로 클램프.
 *
 * @param avoidRect 피할 선택 요소 영역
 * @param modalWidth 모달 폭(px 추정)
 * @param modalHeight 모달 높이(px 추정)
 * @return {left, top} viewport 좌표 또는 null(중앙)
 */
function computeAvoidPosition(
  avoidRect: { left: number; top: number; width: number; height: number } | undefined,
  modalWidth: number,
  modalHeight: number,
): { left: number; top: number } | null {
  if (!avoidRect || typeof window === 'undefined') return null;
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  const gap = 16;
  const leftSpace = avoidRect.left; // 선택 요소 왼쪽 빈 공간
  const rightSpace = vw - (avoidRect.left + avoidRect.width); // 오른쪽 빈 공간
  const needed = modalWidth + gap;

  let left: number;
  if (rightSpace >= leftSpace && rightSpace >= needed) {
    // 오른쪽 빈 공간이 넓고 충분 — 선택 요소 오른쪽에 배치
    left = avoidRect.left + avoidRect.width + gap;
  } else if (leftSpace >= needed) {
    // 왼쪽 빈 공간에 배치 (모달 우측 끝이 선택 요소 좌측 - gap)
    left = Math.max(gap, avoidRect.left - gap - modalWidth);
  } else {
    // 양쪽 다 좁음 — 중앙 유지
    return null;
  }
  // viewport 가로 클램프
  left = Math.min(Math.max(gap, left), Math.max(gap, vw - modalWidth - gap));
  // 세로 — 선택 요소 상단에 맞추되 viewport 안으로 클램프
  let top = avoidRect.top;
  top = Math.min(Math.max(gap, top), Math.max(gap, vh - modalHeight - gap));
  return { left, top };
}

/**
 * 비-draggable 모달의 상단 고정 여백. 상단 정렬 + 이 여백으로 탭이 바뀌어도
 * 모달 top 이 고정된다. maxHeight 도 이 여백의 2배(상·하)를 빼 화면 안에 들어오게 한다.
 */
const MODAL_TOP_GAP = 'clamp(16px, 6vh, 56px)';

function ModalLayer({ entry, depth, hidden, onClose }: ModalLayerProps): React.ReactElement {
  // 최상위 z-index 베이스 9100 — LayoutEditorChrome 의 z-index 9000 보다 위.
  // depth 별로 10씩 증가해 스택 위 모달이 항상 아래 모달 위에 표시.
  const zIndex = 9100 + depth * 10;

  // viewport 기준 max-height — vh/calc 문자열로 둔다(픽셀 고정 금지).
  // 픽셀로 한 번 계산해 박으면(마운트 시점 window.innerHeight) 창을 줄여도 재계산되지 않아
  // 모달이 화면을 넘쳐 백드롭이 스크롤되고 상단 탭/제목이 사라진다.
  // 비-draggable 모달은 상단 정렬 + 상단 여백(MODAL_TOP_GAP)을 쓰므로, 그 여백과 같은 크기의
  // 하단 여백을 함께 빼 모달이 화면(제목·탭 포함) 안에 항상 들어오게 한다. ratio 는 상한으로만.
  const ratioCap = entry.maxHeightRatio > 0 ? `${entry.maxHeightRatio * 100}vh` : undefined;
  // 비-draggable 모달은 상단 정렬 + 상단 여백을 쓰므로 그 여백의 2배(상·하)를 빼 화면(제목·탭
  // 포함) 안에 항상 들어오게 한다. min()/clamp() 복합값은 jsdom 인라인 style 이 떨구므로 calc
  // 하나로만 표현(브라우저는 calc 안에서 vh·px 혼합을 리사이즈마다 자동 재계산 — 픽셀 고정 회피).
  // draggable 모달은 종전대로 ratio vh 상한(자유 배치라 화면맞춤 calc 불필요).
  const maxHeight = entry.draggable
    ? ratioCap
    : `calc(100vh - (${MODAL_TOP_GAP}) * 2)`;

  // draggable 모달 위치 상태 — null = 미배치(중앙) / {left,top} = 자유 위치.
  const [position, setPosition] = useState<{ left: number; top: number } | null>(null);
  const modalElRef = useRef<HTMLDivElement | null>(null);
  const dragRef = useRef<{ startX: number; startY: number; baseLeft: number; baseTop: number } | null>(
    null,
  );

  // 초기 자동 회피 위치 계산 — 모달이 실제로 마운트돼 크기를 알게 된 직후 1회.
  // draggable 이 아니면 position 을 두지 않아 종전 중앙 정렬 그대로(회귀 0).
  useEffect(() => {
    if (!entry.draggable) return;
    const el = modalElRef.current;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const pos = computeAvoidPosition(entry.avoidRect, rect.width, rect.height);
    if (pos) setPosition(pos);
    // entry.id 가 바뀌면(다른 모달) 재계산. avoidRect 는 open 시점 고정값.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [entry.id, entry.draggable]);

  // 헤더 드래그 — 경량 포인터 핸들러(useResizeHandles 패턴 답습, dnd-kit 비사용).
  const onHeaderPointerDown = useCallback(
    (e: React.PointerEvent): void => {
      if (!entry.draggable) return;
      const target = e.target as HTMLElement;
      // 드래그 시작점은 헤더 드래그 핸들(`data-modal-drag-handle`) 영역만 —
      // 모달 본문(탭/컨트롤)에서 시작한 포인터는 이동 트리거 아님.
      const handle = target.closest('[data-modal-drag-handle]');
      if (!handle) return;
      // 핸들 안이라도 닫기 버튼/입력 등 인터랙티브 요소는 제외.
      if (target.closest('button, a, input, textarea, select')) return;
      const el = modalElRef.current;
      const rect = el?.getBoundingClientRect();
      const baseLeft = position?.left ?? rect?.left ?? 0;
      const baseTop = position?.top ?? rect?.top ?? 0;
      dragRef.current = { startX: e.clientX, startY: e.clientY, baseLeft, baseTop };

      const onMove = (ev: PointerEvent): void => {
        const d = dragRef.current;
        if (!d) return;
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const w = el?.offsetWidth ?? 0;
        const h = el?.offsetHeight ?? 0;
        let nextLeft = d.baseLeft + (ev.clientX - d.startX);
        let nextTop = d.baseTop + (ev.clientY - d.startY);
        // viewport 클램프 — 모달이 화면 밖으로 완전히 나가지 않도록
        nextLeft = Math.min(Math.max(0, nextLeft), Math.max(0, vw - w));
        nextTop = Math.min(Math.max(0, nextTop), Math.max(0, vh - h));
        setPosition({ left: nextLeft, top: nextTop });
      };
      const onUp = (): void => {
        dragRef.current = null;
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
      };
      window.addEventListener('pointermove', onMove);
      window.addEventListener('pointerup', onUp);
    },
    [entry.draggable, position],
  );

  const handleBackdropClick = (e: React.MouseEvent): void => {
    if (!entry.closeOnBackdrop) return;
    // draggable 모달은 백드롭이 pointerEvents:none 이라 이 핸들러가 호출되지 않지만,
    // 안전상 currentTarget 일치 검사 유지.
    if (e.target !== e.currentTarget) return;
    onClose();
  };

  const positioned = entry.draggable && position !== null;

  // draggable 모달 — 백드롭을 pointerEvents:none 으로 두어 캔버스 결과를 보며 이동.
  // 모달 본문만 pointerEvents:auto. 비-draggable 모달은 종전 중앙 정렬 + 어둡게(회귀 0).
  const backdropStyle: React.CSSProperties = entry.draggable
    ? {
        position: 'fixed',
        inset: 0,
        background: 'transparent',
        pointerEvents: 'none',
        zIndex,
        // 최소화 시 숨김(언마운트 X — 상태 보존). draggable 의 flex 미사용.
        ...(hidden ? { display: 'none' } : {}),
      }
    : {
        position: 'fixed',
        inset: 0,
        background: 'rgba(15, 23, 42, 0.45)',
        display: hidden ? 'none' : 'flex',
        // 상단 정렬(flex-start) — 세로 중앙 정렬이면 탭마다 모달 높이가 바뀔 때 중앙 기준
        // top 이 출렁이고(위치가 자꾸 바뀜), 콘텐츠가 maxHeight 에 닿으면 위로 밀려 제목·탭이
        // 화면 밖으로 잘린다. 상단 고정 여백을 두면 탭이 바뀌어도 top 이 고정되고
        // 높이는 아래로만 늘며, maxHeight(vh)가 화면 초과를 막아 제목·탭이 항상 보인다.
        alignItems: 'flex-start',
        justifyContent: 'center',
        paddingTop: MODAL_TOP_GAP,
        boxSizing: 'border-box',
        zIndex,
      };

  const modalStyle: React.CSSProperties = {
    background: '#fff',
    borderRadius: 10,
    boxShadow: '0 20px 60px rgba(15, 23, 42, 0.25)',
    width: entry.width,
    height: entry.height,
    maxHeight,
    maxWidth: '92vw',
    overflow: 'hidden',
    display: 'flex',
    flexDirection: 'column',
    ...(entry.draggable
      ? {
          position: 'absolute',
          pointerEvents: 'auto',
          // 자동 회피 위치가 정해지기 전(초기 1프레임)은 중앙 유지
          left: positioned ? position!.left : '50%',
          top: positioned ? position!.top : '50%',
          transform: positioned ? undefined : 'translate(-50%, -50%)',
        }
      : {}),
  };

  return (
    <div
      className="g7le-modal-backdrop"
      role="presentation"
      data-testid={`g7le-modal-backdrop-${entry.id}`}
      data-draggable={entry.draggable ? 'true' : 'false'}
      onClick={handleBackdropClick}
      style={backdropStyle}
    >
      <div
        ref={modalElRef}
        className="g7le-modal"
        role="dialog"
        aria-modal="true"
        aria-label={entry.ariaLabel || undefined}
        data-testid={`g7le-modal-${entry.id}`}
        data-positioned={positioned ? 'true' : 'false'}
        onClick={(e) => e.stopPropagation()}
        onPointerDown={entry.draggable ? onHeaderPointerDown : undefined}
        style={modalStyle}
      >
        {entry.content}
      </div>
    </div>
  );
}
