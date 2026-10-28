import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import DOMPurify from 'dompurify';
import { DEFAULT_SETTINGS, type PopupDisplaySettings, type PublicPopup } from '../../lib/types';
import { MOBILE_MAX_WIDTH, rememberClosed, visiblePopups } from '../../lib/popupVisibility';
import { readDismissed, rememberDismissed } from '../../lib/dismissCookie';
import { translate as t } from '../../support/editor';

/** GDPR 쿠키 배너 요소 id — GDPR 조각의 `data-ref` 가 DOM id 가 된다(공개 계약 아님, 결정 R8). */
export const GDPR_BANNER_ID = 'gdpr_cookie_banner';

/** 본문 HTML 출력 정화 — 템플릿 HtmlContent 와 같은 금지 목록(저장 때 HTMLPurifier 로 이미 걸렀고 여기서 한 번 더) */
const PURIFY_CONFIG = {
  FORBID_TAGS: [
    'script', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'portal',
    'form', 'input', 'textarea', 'select', 'button', 'style', 'link', 'meta', 'base',
    'body', 'head', 'html', 'title', 'svg', 'math', 'audio', 'video', 'source', 'track', 'canvas',
    'details', 'dialog', 'plaintext', 'xmp', 'listing', 'marquee', 'noframes', 'noembed', 'template', 'slot',
  ],
  FORBID_ATTR: ['onerror', 'onload', 'onclick', 'onmouseover', 'onfocus', 'onblur', 'ontoggle', 'oncanplay', 'onloadstart', 'formaction', 'xlink:href', 'action'],
  ADD_ATTR: ['target'],
};

/** 장 전환 시간(ms) — `popup.css` 의 `.g7pp-slide--moving` transition 과 같은 값 */
export const SLIDE_MS = 300;

/** 끌기 시작 판정 거리(px) — 이만큼 움직인 뒤 가로 성분이 세로보다 크면 끌기, 아니면 세로 스크롤에 맡긴다 */
const DRAG_START_PX = 8;

/** 놓았을 때 옆 장으로 넘기는 거리 — 표시 폭 대비 비율 */
const DRAG_COMMIT_RATIO = 0.2;

/** 빠른 휙 넘김 — 이 속도(px/ms) 이상이고 표시 폭의 이 비율 이상 움직였으면 짧아도 넘긴다 */
const FLICK_SPEED = 0.5;
const FLICK_MIN_RATIO = 0.04;

/** 점 단추를 그리는 최대 장 수 — 넘으면 점(각 44px)이 여러 줄로 늘어 띠가 길어지므로 「지금 / 전체」 숫자만 둔다 */
export const MAX_DOTS = 8;

/** 옮긴 창이 화면 가장자리와 남기는 여백(px) */
const MOVE_EDGE_PX = 8;

/** 창 옮기기 손잡이에서 화살표 키 한 번에 옮기는 거리(px) */
const MOVE_KEY_PX = 16;

/** 레이어 층(z-index) — `popup.css` 의 `.g7pp-root` 와 같은 값. 이 층 이상인 화면 위 고정 요소(헤더 등)는 창을 덮는다 */
const LAYER_Z = 40;

/** 위아래 고정 요소 사이가 좁을 때 줄이는 본문 높이의 하한(px) */
const FIT_MIN_PX = 120;

/** 하단 시트를 놓았을 때 닫는 내림 거리 — 시트 높이 대비 비율 */
const SHEET_CLOSE_RATIO = 0.3;

/** 하단 시트를 빠르게 휙 내렸을 때 닫는 최소 거리(px) — 속도는 장 넘김과 같은 FLICK_SPEED */
const SHEET_FLICK_MIN_PX = 24;

/** 창을 옮긴 거리(px) — 기존 배치(가운데·좌표·시트) 위에 더해진다 */
interface MoveShift {
  x: number;
  y: number;
}

/** 옮기지 않은 자리 */
const NO_SHIFT: MoveShift = { x: 0, y: 0 };

/** 창을 덮는 화면 위·아래 고정 요소의 두께(px) — 위는 헤더 등, 아래는 GDPR 배너 */
interface Covers {
  top: number;
  bottom: number;
}

/** 옮기기 전 창의 표시 자리 */
interface MoveBase {
  left: number;
  top: number;
  width: number;
  height: number;
}

/** 진행 중인 창 옮기기 */
interface MoveState {
  /** 포인터 id */
  id: number;

  /** 누른 위치 (화면 px) */
  x: number;
  y: number;

  /** 누른 시각 (ms) */
  at: number;

  /** 누를 때 이미 옮겨져 있던 거리 */
  start: MoveShift;

  /** 옮기기 전 창의 표시 자리 */
  base: MoveBase;

  /** 누를 때 잰 화면 위·아래 고정 요소 */
  covers: Covers;
}

/**
 * 장 위치 상태 — 현재 장과 함께 보이는 옆 장 하나만 ±100% 에 두고 옮긴다(전체 트랙 없음).
 * 끝 → 처음 넘김도 옆 장 하나만 들어오므로 한 칸 넘김으로 보인다.
 */
interface SlideMotion {
  /** 현재 장과 함께 보이는 옆 장 (없으면 null) */
  peer: number | null;

  /** 옆 장이 놓인 쪽 — 1 = 오른쪽, -1 = 왼쪽 */
  side: 1 | -1;

  /** 현재 장의 가로 위치 (장 폭 대비 %) — 옆 장은 여기에 side × 100 을 더한 자리 */
  offset: number;

  /** 위치를 미끄러지듯 옮기는 중 (transition 적용) */
  moving: boolean;
}

/** 멈춘 상태 — 현재 장만 제자리 */
const REST: SlideMotion = { peer: null, side: 1, offset: 0, moving: false };

/** 진행 중인 끌기 */
interface DragState {
  /** 포인터 id */
  id: number;

  /** 누른 위치 (화면 px) */
  x: number;
  y: number;

  /** 누른 시각 (ms) */
  at: number;

  /** 누를 때 잰 슬라이드 영역의 실제 표시 폭 — 축소된 미리보기 틀 안에서도 비율이 맞는다 */
  width: number;

  /** 가로 끌기로 판정됨 */
  dragging: boolean;
}

/**
 * 값을 범위 안으로 자릅니다.
 *
 * @param value 값
 * @param min 최솟값
 * @param max 최댓값
 * @returns 자른 값
 */
function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

/**
 * 화면 맨 위에 붙어 레이어를 덮는 고정 요소(템플릿 헤더 등)의 아래 끝을 잽니다 — 없으면 0.
 *
 * 화면 맨 윗줄 세 점에 겹친 요소 중 레이어 밖의 `fixed`·`sticky` 요소이고 층이 레이어(40) 이상인 것만 센다.
 * 화면 절반을 넘게 덮는 요소(전체 화면 배경 등)는 헤더가 아니므로 뺀다.
 *
 * @param root 레이어 루트 (그 안의 요소는 세지 않는다)
 * @returns 덮는 요소의 아래 끝(px)
 */
function measureTopCover(root: Element | null): number {
  if (typeof document.elementsFromPoint !== 'function') {
    return 0;
  }
  let cover = 0;
  for (const x of [1, window.innerWidth / 2, window.innerWidth - 1]) {
    for (const el of document.elementsFromPoint(x, 1)) {
      if (root?.contains(el)) {
        continue;
      }
      const style = window.getComputedStyle(el);
      if (style.position !== 'fixed' && style.position !== 'sticky') {
        continue;
      }
      const rect = el.getBoundingClientRect();
      if ((Number.parseInt(style.zIndex, 10) || 0) >= LAYER_Z && rect.top <= 1 && rect.bottom < window.innerHeight / 2) {
        cover = Math.max(cover, rect.bottom);
      }
    }
  }

  return Math.round(cover);
}

/**
 * 옮긴 거리를 화면 안으로 자릅니다 — 창이 들어가면 가장자리 8px 안쪽, 창이 화면보다 크면 맨 위(손잡이)가 보이는 데까지.
 * 화면 위 고정 요소가 있으면 위쪽 끝은 그 아래, 바닥 배너가 있으면 아래쪽 끝은 그 위다(둘 다 안 되면 위가 먼저).
 *
 * @param base 옮기기 전 창의 표시 자리
 * @param x 옮길 가로 거리(px)
 * @param y 옮길 세로 거리(px)
 * @param covers 화면 위·아래 고정 요소 (없으면 0)
 * @returns 자른 거리
 */
function clampShift(base: MoveBase, x: number, y: number, covers: Covers = { top: 0, bottom: 0 }): MoveShift {
  const right = window.innerWidth - base.width - MOVE_EDGE_PX;
  const bottom = window.innerHeight - covers.bottom - base.height - MOVE_EDGE_PX;
  const minTop = covers.top + MOVE_EDGE_PX;
  const left = clamp(base.left + x, Math.min(MOVE_EDGE_PX, right), Math.max(MOVE_EDGE_PX, right));
  const top = Math.max(covers.top, clamp(base.top + y, Math.min(minTop, bottom), Math.max(minTop, bottom)));

  return { x: Math.round(left - base.left), y: Math.round(top - base.top) };
}

/** 사용자 레이어 props */
export interface PopupLayerProps {
  /** 공개 API 팝업 (서버 순서 = 관리자 순서) */
  popups?: PublicPopup[] | null;

  /** 표시 방식 */
  settings?: Partial<PopupDisplaySettings> | null;

  /** 현재 경로 (`route.path`) — 바뀔 때마다 다시 판정한다 */
  path?: string;

  /** 추가 클래스 */
  className?: string;

  /**
   * 관리자 미리보기 — 기기를 고정하고 거르지 않으며(쿠키·닫음·경로 무시), 포커스를 가두지 않고 링크·닫기를 막는다.
   * 미리보기 틀(`PopupPreview`) 안에서만 쓴다.
   */
  preview?: 'pc' | 'mobile';
}

/**
 * 사이트 안 링크인지 판정합니다(`/` 로 시작하고 `//` 가 아님).
 *
 * @param url 링크 주소
 * @returns 사이트 안이면 true
 */
function isInternal(url: string): boolean {
  return url.startsWith('/') && !url.startsWith('//') && !url.startsWith('/\\');
}

/**
 * 폭이 모바일인지 지켜봅니다(767px 이하).
 *
 * 엔진 `ResponsiveManager` 와 같은 값을 쓴다 — `G7Core.useResponsive()` 의 폭(레이아웃 편집기 미리보기에서는 기기 폭),
 * 훅이 없을 때만 `window.innerWidth`. `matchMedia` 는 소수 배율 화면의 경계 폭에서 1px 어긋나 쓰지 않는다
 * (docs/frontend/responsive-layout.md 「확장 코드에서 같은 경계를 판정할 때」).
 *
 * @returns 모바일이면 true
 */
function useIsMobile(): boolean {
  const useResponsive = (window as { G7Core?: { useResponsive?: () => { width?: number } | null } }).G7Core?.useResponsive;
  // G7Core 는 페이지가 뜰 때 한 번 정해진다 — 렌더마다 훅 호출 여부가 바뀌지 않는다
  const responsive = useResponsive?.();
  const [innerWidth, setInnerWidth] = useState(() => (typeof window !== 'undefined' ? window.innerWidth : MOBILE_MAX_WIDTH + 1));
  const hasEngineWidth = typeof responsive?.width === 'number';

  useEffect(() => {
    if (hasEngineWidth) {
      return undefined;
    }
    const onResize = () => setInnerWidth(window.innerWidth);
    onResize();
    window.addEventListener('resize', onResize);

    return () => window.removeEventListener('resize', onResize);
  }, [hasEngineWidth]);

  return (hasEngineWidth ? (responsive?.width as number) : innerWidth) <= MOBILE_MAX_WIDTH;
}

/**
 * 움직임 줄이기 설정을 지켜봅니다.
 *
 * @returns 움직임 줄이기면 true
 */
function useReducedMotion(): boolean {
  const [reduced, setReduced] = useState(() => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)').matches : false));

  useEffect(() => {
    if (!window.matchMedia) {
      return undefined;
    }
    const list = window.matchMedia('(prefers-reduced-motion: reduce)');
    const onChange = () => setReduced(list.matches);
    list.addEventListener?.('change', onChange);

    return () => list.removeEventListener?.('change', onChange);
  }, []);

  return reduced;
}

/**
 * GDPR 쿠키 배너가 화면 아래에 차지한 높이를 지켜봅니다 — 없거나 숨으면 0.
 *
 * @param active 지켜볼지 (모바일 하단 시트일 때만)
 * @returns 배너 높이(px)
 */
function useGdprBannerHeight(active: boolean): number {
  const [height, setHeightState] = useState(0);
  const lastHeight = useRef(0);
  // 관찰기는 DOM 이 바뀔 때마다 부른다 — 값이 바뀔 때만 다시 그린다
  const setHeight = useCallback((value: number) => {
    if (lastHeight.current !== value) {
      lastHeight.current = value;
      setHeightState(value);
    }
  }, []);

  useEffect(() => {
    if (!active) {
      setHeight(0);

      return undefined;
    }

    let resize: ResizeObserver | null = null;
    let observed: Element | null = null;

    const measure = () => {
      const banner = document.getElementById(GDPR_BANNER_ID);
      if (banner !== observed) {
        resize?.disconnect();
        observed = banner;
        if (banner && typeof ResizeObserver !== 'undefined') {
          resize = new ResizeObserver(measure);
          resize.observe(banner);
        }
      }
      if (!banner) {
        setHeight(0);

        return;
      }
      const rect = banner.getBoundingClientRect();
      // 화면 아래쪽에 있는 배너(바닥 전폭·좌하·우하 — 바닥에서 조금 떠 있어도)면 그 윗변까지 올린다.
      // 가운데 모달형 배너는 시트와 겹치지 않으므로 올리지 않는다
      const nearBottom = rect.height > 0 && rect.bottom >= window.innerHeight - 24;
      setHeight(nearBottom ? Math.max(0, Math.round(window.innerHeight - rect.top)) : 0);
    };

    measure();
    // 배너는 동의·닫기 때 DOM 에서 빠진다 — 붙고 빠짐을 본다
    const mutations = typeof MutationObserver !== 'undefined' ? new MutationObserver(measure) : null;
    mutations?.observe(document.body, { childList: true, subtree: true });
    window.addEventListener('resize', measure);

    return () => {
      resize?.disconnect();
      mutations?.disconnect();
      window.removeEventListener('resize', measure);
    };
  }, [active, setHeight]);

  return height;
}

/**
 * 사용자 화면 팝업 레이어 — 조건에 맞는 팝업을 한 레이어 안 캐러셀로 보인다.
 *
 * 경로·기기·「보지 않기」·이번 페이지에서 닫음은 여기서 거른다(서버는 기간·대상만). 「보지 않기」는 그때 보이던 팝업 전부를
 * 쿠키 하나에 만료와 함께 기록하고, 「닫기」·ESC·배경 클릭은 새로고침 전까지 같은 팝업을 다시 띄우지 않는다.
 * 레이어는 GDPR 쿠키 배너(z-50) 아래 층(z-40)이고, 모바일 하단 시트는 배너 높이만큼 올라간다.
 *
 * @param props 레이어 props
 * @returns 팝업 레이어 (보일 팝업이 없으면 null)
 */
export const PopupLayer: React.FC<PopupLayerProps> = ({ popups, settings, path = '/', className = '', preview }) => {
  const config: PopupDisplaySettings = { ...DEFAULT_SETTINGS, ...(settings ?? {}) };
  const viewportMobile = useIsMobile();
  const isMobile = preview ? preview === 'mobile' : viewportMobile;
  const systemReducedMotion = useReducedMotion();
  // 미리보기는 넘기지 않는다(관리자 화면에서 혼자 움직이면 입력을 방해한다)
  const reducedMotion = preview ? true : systemReducedMotion;
  const [dismissVersion, setDismissVersion] = useState(0);
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const [hovering, setHovering] = useState(false);
  const [focusWithin, setFocusWithin] = useState(false);
  // 장 전환 — 움직임 줄이기면 미끄러짐 없이 바로 바꾼다(미리보기도 같은 움직임을 보인다)
  const animate = !systemReducedMotion;
  const [motion, setMotion] = useState<SlideMotion>(REST);
  const [pressing, setPressing] = useState(false);
  const [dragging, setDragging] = useState(false);
  const dialogRef = useRef<HTMLDivElement>(null);
  const bodyRef = useRef<HTMLDivElement>(null);
  const returnFocusRef = useRef<HTMLElement | null>(null);
  const indexRef = useRef(0);
  const slideStartRef = useRef(false);
  const dragRef = useRef<DragState | null>(null);
  // 끈 뒤 따라오는 click 은 링크 이동이 아니다 — 다음 click 하나를 막는다
  const draggedRef = useRef(false);
  // 창 옮기기 — 옮긴 자리는 기억하지 않는다(새로 열리거나 경로가 바뀌면 원래 자리)
  const [shift, setShift] = useState<MoveShift>(NO_SHIFT);
  const [holding, setHolding] = useState(false);
  const shiftRef = useRef<MoveShift>(NO_SHIFT);
  const moveRef = useRef<MoveState | null>(null);
  shiftRef.current = shift;
  // 위아래 고정 요소 사이에 들어가도록 줄인 본문 높이 상한(px) — 바뀌면 다시 그린 뒤 자리를 잡는다
  const [fit, setFit] = useState<number | null>(null);
  const fitRef = useRef<number | null>(null);
  const pendingRef = useRef<MoveShift | null>(null);
  const gdprRef = useRef(0);

  const visible = useMemo(() => {
    if (preview) {
      return Array.isArray(popups) ? popups : [];
    }
    const nowSeconds = Math.floor(Date.now() / 1000);

    return visiblePopups(Array.isArray(popups) ? popups : [], {
      path,
      isMobile,
      dismissed: readDismissed(typeof document !== 'undefined' ? document.cookie : '', nowSeconds),
      nowSeconds,
    });
    // dismissVersion: 닫기·보지 않기 뒤 다시 거른다
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [popups, path, isMobile, dismissVersion, preview]);

  const visibleKey = visible.map((p) => p.id).join(',');
  const count = visible.length;
  const open = count > 0;
  const isSheet = isMobile && config.mobile_style === 'sheet';
  // 시트는 배너 위로 올리고, 가운데·좌표 창은 배너 위에 들어가게 놓는다
  const gdprHeight = useGdprBannerHeight(open && !preview);

  const current = count > 0 ? Math.min(index, count - 1) : 0;
  indexRef.current = current;

  // 목록이 바뀌면(경로 이동·기기 전환) 처음 장부터
  useEffect(() => {
    setIndex(0);
    setMotion(REST);
  }, [visibleKey]);

  /**
   * 옮기기 전 창의 표시 자리를 잰다 — 지금 자리에서 옮긴 거리를 뺀다.
   *
   * @returns 옮기기 전 자리 (창이 없으면 null)
   */
  const measureBase = useCallback((): MoveBase | null => {
    const rect = dialogRef.current?.getBoundingClientRect();
    if (!rect) {
      return null;
    }
    const now = shiftRef.current;

    return { left: rect.left - now.x, top: rect.top - now.y, width: rect.width, height: rect.height };
  }, []);

  /**
   * 창을 덮는 화면 위·아래 고정 요소를 잰다 — 위는 헤더 등(층 40 이상), 아래는 GDPR 배너.
   *
   * @returns 위·아래 두께(px)
   */
  const measureCovers = useCallback((): Covers => ({
    top: measureTopCover(dialogRef.current?.parentElement ?? null),
    bottom: gdprRef.current,
  }), []);

  /**
   * 창을 원래 자리에서 (x, y) 만큼 옮겨 둔다 — 화면 안, 위아래 고정 요소 사이로 자른다.
   * 그 사이가 창보다 좁으면 본문 높이 상한을 먼저 줄이고, 다시 그린 뒤 자리를 잡는다.
   *
   * @param x 옮길 가로 거리(px)
   * @param y 옮길 세로 거리(px)
   */
  const settle = useCallback((x: number, y: number) => {
    const base = measureBase();
    const bodyHeight = bodyRef.current?.getBoundingClientRect().height ?? 0;
    if (!base) {
      return;
    }
    const covers = measureCovers();
    // 손잡이·위치 표시·닫기 띠는 그대로 두고 본문만 줄인다
    const nextFit = Math.max(FIT_MIN_PX, Math.floor(window.innerHeight - covers.top - covers.bottom - MOVE_EDGE_PX * 2 - (base.height - bodyHeight)));
    if (nextFit !== fitRef.current) {
      fitRef.current = nextFit;
      pendingRef.current = { x, y };
      setFit(nextFit);

      return;
    }
    const next = clampShift(base, x, y, covers);
    const now = shiftRef.current;
    if (next.x !== now.x || next.y !== now.y) {
      shiftRef.current = next;
      setShift(next);
    }
  }, [measureBase, measureCovers]);

  // 본문 상한을 바꿔 다시 그렸으면 그 크기로 자리를 잡는다
  useLayoutEffect(() => {
    const pending = pendingRef.current;
    if (pending) {
      pendingRef.current = null;
      settle(pending.x, pending.y);
    }
  }, [fit, settle]);

  // 열리거나 경로·목록·배치가 바뀌면 원래 자리로 — 헤더·GDPR 배너가 덮으면 그 사이로 비켜 둔다
  useLayoutEffect(() => {
    moveRef.current = null;
    setHolding(false);
    if (!open || isSheet || preview) {
      shiftRef.current = NO_SHIFT;
      fitRef.current = null;
      setShift(NO_SHIFT);
      setFit(null);

      return;
    }
    settle(0, 0);
  }, [open, visibleKey, path, isMobile, isSheet, preview, settle]);

  // 브라우저 창 크기가 바뀌거나 GDPR 배너가 붙고 빠지면 옮긴 자리를 지키며 다시 맞춘다
  gdprRef.current = gdprHeight;
  useEffect(() => {
    if (!open || isSheet || preview) {
      return undefined;
    }
    const onResize = () => settle(shiftRef.current.x, shiftRef.current.y);
    onResize();
    window.addEventListener('resize', onResize);

    return () => window.removeEventListener('resize', onResize);
  }, [open, isSheet, preview, gdprHeight, settle]);

  // 들어올 장을 시작 자리에 그린 직후(그리기 전) 목표 자리로 옮긴다 — 시작 자리를 먼저 확정해야 transition 이 일어난다
  useLayoutEffect(() => {
    if (!slideStartRef.current) {
      return;
    }
    slideStartRef.current = false;
    // 시작 자리 배치를 강제로 확정한다(읽기만 해도 스타일이 계산된다)
    void bodyRef.current?.offsetWidth;
    setMotion((m) => ({ ...m, offset: 0, moving: true }));
  }, [motion]);

  // 미끄러짐이 끝나면 옆 장을 숨긴다 — transitionend 가 오지 않아도(탭 숨김 등) 시간이 지나면 끝낸다
  useEffect(() => {
    if (!motion.moving) {
      return undefined;
    }
    const timer = window.setTimeout(() => setMotion(REST), SLIDE_MS + 80);

    return () => window.clearTimeout(timer);
  }, [motion]);

  // 포커스가 화면 밖으로 나간 장에 남지 않게 레이어로 옮긴다
  useEffect(() => {
    if (preview) {
      return;
    }
    const slide = (document.activeElement as HTMLElement | null)?.closest?.('.g7pp-slide');
    if (slide && dialogRef.current?.contains(slide) && slide.getAttribute('data-popup-id') !== String(visible[current]?.id)) {
      dialogRef.current.focus();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [current]);

  // 열릴 때 레이어로 포커스, 닫힐 때 열기 전 포커스로
  useEffect(() => {
    if (!open || preview) {
      return undefined;
    }
    returnFocusRef.current = (document.activeElement as HTMLElement | null) ?? null;
    dialogRef.current?.focus();

    return () => {
      const target = returnFocusRef.current;
      if (target && typeof target.focus === 'function' && document.contains(target)) {
        target.focus();
      }
    };
  }, [open, preview]);

  const autoplaying = open && config.autoplay && count > 1 && !paused && !hovering && !focusWithin && !pressing && !reducedMotion;

  /**
   * 다른 장으로 옮긴다 — 들어올 장 하나만 옆(±100%)에서 미끄러져 들어온다.
   *
   * @param target 옮길 장
   * @param dir 넘기는 방향 (1 = 다음 장처럼 오른쪽에서, -1 = 이전 장처럼 왼쪽에서)
   * @param startOffset 들어올 장의 시작 위치(%) — 끌다 놓았으면 그 자리, 아니면 dir × 100
   */
  const moveTo = useCallback((target: number, dir: 1 | -1, startOffset?: number) => {
    const from = indexRef.current;
    if (target === from) {
      return;
    }
    setIndex(target);
    if (!animate) {
      setMotion(REST);

      return;
    }
    slideStartRef.current = true;
    setMotion({ peer: from, side: dir === 1 ? -1 : 1, offset: startOffset ?? dir * 100, moving: false });
  }, [animate]);

  const go = useCallback((step: 1 | -1) => {
    if (count > 1) {
      moveTo((indexRef.current + step + count) % count, step);
    }
  }, [count, moveTo]);

  useEffect(() => {
    if (!autoplaying) {
      return undefined;
    }
    const timer = window.setInterval(() => go(1), Math.max(1, config.autoplay_interval) * 1000);

    return () => window.clearInterval(timer);
  }, [autoplaying, go, config.autoplay_interval]);

  const close = useCallback(() => {
    if (preview) {
      return;
    }
    rememberClosed(visible.map((p) => p.id));
    setDismissVersion((v) => v + 1);
  }, [visible, preview]);

  const dismiss = useCallback(() => {
    if (config.dismiss_mode === 'close' || preview) {
      return;
    }
    const ids = visible.map((p) => p.id);
    rememberDismissed(ids, config.dismiss_mode, config.dismiss_days);
    rememberClosed(ids);
    setDismissVersion((v) => v + 1);
  }, [visible, config.dismiss_mode, config.dismiss_days, preview]);

  const onKeyDown = useCallback((event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();

      return;
    }
    // 창 옮기기 손잡이 — 화살표는 창을 옮기고(장을 넘기지 않는다) Home 은 원래 자리로
    if ((event.target as HTMLElement).closest?.('.g7pp-grip')) {
      const step: Record<string, [number, number]> = {
        ArrowLeft: [-MOVE_KEY_PX, 0],
        ArrowRight: [MOVE_KEY_PX, 0],
        ArrowUp: [0, -MOVE_KEY_PX],
        ArrowDown: [0, MOVE_KEY_PX],
      };
      if (event.key === 'Home') {
        event.preventDefault();
        settle(0, 0);

        return;
      }
      if (step[event.key]) {
        event.preventDefault();
        const [dx, dy] = step[event.key];
        settle(shiftRef.current.x + dx, shiftRef.current.y + dy);

        return;
      }
    }
    if (count > 1 && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
      const target = event.target as HTMLElement;
      // 본문 스크롤 영역 안에서는 가로 스크롤을 빼앗지 않는다
      if (!target.closest?.('[data-popup-scroll]')) {
        event.preventDefault();
        go(event.key === 'ArrowRight' ? 1 : -1);
      }

      return;
    }
    if (event.key === 'Tab' && !preview) {
      const focusables = Array.from(dialogRef.current?.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])',
      ) ?? []).filter((el) => !el.closest('[hidden], [inert], [aria-hidden="true"]'));
      if (focusables.length === 0) {
        event.preventDefault();

        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || active === dialogRef.current)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    }
  }, [close, count, go, preview, settle]);

  /**
   * 끌기를 끝낸다 — 일정 거리(폭의 20%) 또는 빠른 휙 넘김이면 옆 장으로, 아니면 제자리로.
   *
   * @param event 놓음·취소 포인터 이벤트
   * @param cancelled 브라우저가 포인터를 가져갔으면(스크롤 등) true — 넘기지 않는다
   */
  const endDrag = (event: React.PointerEvent<HTMLDivElement>, cancelled: boolean) => {
    const drag = dragRef.current;
    if (!drag || drag.id !== event.pointerId) {
      return;
    }
    dragRef.current = null;
    setPressing(false);
    if (!drag.dragging) {
      return;
    }
    setDragging(false);
    try {
      event.currentTarget.releasePointerCapture?.(event.pointerId);
    } catch {
      // 이미 풀린 포인터
    }
    // 끈 뒤 같은 동작에서 오는 click 만 막는다 — 그 뒤 키보드·다음 누름의 click 은 막지 않는다
    window.setTimeout(() => {
      draggedRef.current = false;
    }, 0);

    const dx = event.clientX - drag.x;
    const ratio = clamp(dx / drag.width, -1, 1);
    const speed = Math.abs(dx) / Math.max(1, Date.now() - drag.at);
    const commit = !cancelled && ratio !== 0
      && (Math.abs(ratio) >= DRAG_COMMIT_RATIO || (Math.abs(ratio) >= FLICK_MIN_RATIO && speed >= FLICK_SPEED));
    if (commit) {
      const dir = ratio < 0 ? 1 : -1;
      // 들어올 장은 지금 옆 장 자리(현재 위치 + dir × 100)에서 이어서 들어온다
      moveTo((indexRef.current + dir + count) % count, dir, ratio * 100 + dir * 100);

      return;
    }
    setMotion((m) => (animate && m.peer !== null && m.offset !== 0 ? { ...m, offset: 0, moving: true } : REST));
  };

  const onPointerDown = (event: React.PointerEvent<HTMLDivElement>) => {
    if (count < 2 || dragRef.current || (event.pointerType === 'mouse' && event.button !== 0)) {
      return;
    }
    // 넘김 단추 누름은 끌기가 아니다
    if ((event.target as Element).closest?.('.g7pp-arrow')) {
      return;
    }
    draggedRef.current = false;
    const width = bodyRef.current?.getBoundingClientRect().width ?? 0;
    if (width <= 0) {
      return;
    }
    dragRef.current = { id: event.pointerId, x: event.clientX, y: event.clientY, at: Date.now(), width, dragging: false };
    setPressing(true);
  };

  const onPointerMove = (event: React.PointerEvent<HTMLDivElement>) => {
    const drag = dragRef.current;
    if (!drag || drag.id !== event.pointerId) {
      return;
    }
    const dx = event.clientX - drag.x;
    const dy = event.clientY - drag.y;
    if (!drag.dragging) {
      if (Math.abs(dx) < DRAG_START_PX && Math.abs(dy) < DRAG_START_PX) {
        return;
      }
      if (Math.abs(dx) <= Math.abs(dy)) {
        // 세로 의도 — 본문 스크롤에 맡긴다
        dragRef.current = null;
        setPressing(false);

        return;
      }
      drag.dragging = true;
      draggedRef.current = true;
      setDragging(true);
      try {
        event.currentTarget.setPointerCapture?.(event.pointerId);
      } catch {
        // 합성 이벤트 등 잡을 수 없는 포인터
      }
    }
    // 누르는 동안 생긴 글자 선택을 걷어낸다
    window.getSelection?.()?.removeAllRanges();
    const ratio = clamp(dx / drag.width, -1, 1);
    const dir: 1 | -1 = ratio < 0 ? 1 : -1;
    setMotion({ peer: (indexRef.current + dir + count) % count, side: dir, offset: ratio * 100, moving: false });
  };

  /**
   * 창 옮기기를 시작한다 — 가운데·좌표 창은 손잡이 띠, 하단 시트는 손잡이 막대를 누른다.
   *
   * @param event 누름 포인터 이벤트
   */
  const onMoveDown = (event: React.PointerEvent<HTMLElement>) => {
    if (moveRef.current || (event.pointerType === 'mouse' && event.button !== 0)) {
      return;
    }
    const base = measureBase();
    if (!base) {
      return;
    }
    moveRef.current = {
      id: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      at: Date.now(),
      start: shiftRef.current,
      base,
      covers: isSheet ? { top: 0, bottom: 0 } : measureCovers(),
    };
    setHolding(true);
    setPressing(true);
    try {
      event.currentTarget.setPointerCapture?.(event.pointerId);
    } catch {
      // 합성 이벤트 등 잡을 수 없는 포인터
    }
  };

  const onMoveMove = (event: React.PointerEvent<HTMLElement>) => {
    const move = moveRef.current;
    if (!move || move.id !== event.pointerId) {
      return;
    }
    window.getSelection?.()?.removeAllRanges();
    const dx = event.clientX - move.x;
    const dy = event.clientY - move.y;
    // 시트는 아래로만 따라 내려간다
    setShift(isSheet
      ? { x: 0, y: Math.max(0, dy) }
      : clampShift(move.base, move.start.x + dx, move.start.y + dy, move.covers));
  };

  /**
   * 창 옮기기를 끝낸다 — 시트는 높이의 30% 이상 또는 빠르게 휙 내렸으면 닫고, 아니면 제자리로 돌아온다.
   *
   * @param event 놓음·취소 포인터 이벤트
   * @param cancelled 브라우저가 포인터를 가져갔으면 true — 시트를 닫지 않는다
   */
  const onMoveEnd = (event: React.PointerEvent<HTMLElement>, cancelled: boolean) => {
    const move = moveRef.current;
    if (!move || move.id !== event.pointerId) {
      return;
    }
    moveRef.current = null;
    setHolding(false);
    setPressing(false);
    try {
      event.currentTarget.releasePointerCapture?.(event.pointerId);
    } catch {
      // 이미 풀린 포인터
    }
    if (!isSheet) {
      return;
    }
    const dy = Math.max(0, event.clientY - move.y);
    const speed = dy / Math.max(1, Date.now() - move.at);
    if (!cancelled && (dy >= move.base.height * SHEET_CLOSE_RATIO || (dy >= SHEET_FLICK_MIN_PX && speed >= FLICK_SPEED))) {
      close();
    }
    setShift(NO_SHIFT);
  };

  /** 창 옮기기 포인터 핸들러 — 미리보기에서는 붙이지 않는다 */
  const moveHandlers = preview ? {} : {
    onPointerDown: onMoveDown,
    onPointerMove: onMoveMove,
    onPointerUp: (event: React.PointerEvent<HTMLElement>) => onMoveEnd(event, false),
    onPointerCancel: (event: React.PointerEvent<HTMLElement>) => onMoveEnd(event, true),
    onLostPointerCapture: (event: React.PointerEvent<HTMLElement>) => {
      // 터치는 누른 자식이 잡았던 포인터를 넘겨줄 때 자식의 lostpointercapture 가 올라온다 — 손잡이 자신이 잃었을 때만 끝낸다
      if (event.target === event.currentTarget) {
        onMoveEnd(event, true);
      }
    },
  };

  const onClickCapture = (event: React.MouseEvent<HTMLDivElement>) => {
    if (draggedRef.current) {
      // 끌다 놓은 자리의 링크(덮는 링크·본문 링크)로 이동하지 않는다
      event.preventDefault();
      event.stopPropagation();
      draggedRef.current = false;
    }
  };

  if (!open) {
    return null;
  }

  const dialogStyle: React.CSSProperties = {};
  let variant = 'pc-center';
  if (isMobile) {
    variant = isSheet ? 'm-sheet' : 'm-center';
    if (isSheet) {
      dialogStyle.bottom = `${gdprHeight}px`;
    }
  } else {
    dialogStyle.width = `${config.pc_width}px`;
    dialogStyle['--g7pp-body-h' as string] = `${config.pc_height}px`;
    if (config.pc_position === 'coords') {
      variant = 'pc-coords';
      // 창이 좁으면 화면 안으로 끌어들인다(오른쪽·아래 16px 여백)
      dialogStyle.left = `min(${config.pc_left}px, max(16px, calc(100vw - ${config.pc_width}px - 16px)))`;
      dialogStyle.top = `min(${config.pc_top}px, max(16px, calc(100vh - ${config.pc_height}px - 148px)))`;
    }
  }
  if (fit !== null) {
    dialogStyle['--g7pp-fit-h' as string] = `${fit}px`;
  }
  if (shift.x !== 0 || shift.y !== 0) {
    dialogStyle['--g7pp-move-x' as string] = `${shift.x}px`;
    dialogStyle['--g7pp-move-y' as string] = `${shift.y}px`;
  }

  const dismissLabel = config.dismiss_mode === 'days'
    ? t('sirsoft-popup.layer.dismiss_days', { days: config.dismiss_days })
    : t('sirsoft-popup.layer.dismiss_today');
  const counter = t('sirsoft-popup.layer.counter', { n: current + 1, total: count });

  const navigation = count > 1 ? (
    <div className="g7pp-nav">
      {isMobile && (
        <button type="button" className="g7pp-nav__arrow" aria-label={t('sirsoft-popup.layer.previous')} onClick={() => go(-1)}>
          <span aria-hidden="true">‹</span>
        </button>
      )}
      {count <= MAX_DOTS && visible.map((popup, i) => (
        <button
          key={popup.id}
          type="button"
          className={`g7pp-dot${i === current ? ' g7pp-dot--current' : ''}`}
          aria-label={t('sirsoft-popup.layer.go_to', { n: i + 1 })}
          aria-current={i === current ? 'true' : undefined}
          onClick={() => moveTo(i, i > current ? 1 : -1)}
        >
          <span aria-hidden="true" />
        </button>
      ))}
      <span className="g7pp-counter">{counter}</span>
      {config.autoplay && !reducedMotion && (
        <button
          type="button"
          className="g7pp-nav__pause"
          aria-pressed={paused}
          aria-label={paused ? t('sirsoft-popup.layer.play') : t('sirsoft-popup.layer.pause')}
          onClick={() => setPaused((p) => !p)}
        >
          <span aria-hidden="true">{paused ? '▶' : 'Ⅱ'}</span>
        </button>
      )}
      {isMobile && (
        <button type="button" className="g7pp-nav__arrow" aria-label={t('sirsoft-popup.layer.next')} onClick={() => go(1)}>
          <span aria-hidden="true">›</span>
        </button>
      )}
    </div>
  ) : null;

  return (
    <div className={`g7pp-root${preview ? ' g7pp-root--preview' : ''} ${className}`.trim()} data-popup-layer={preview ?? ''}>
      {(config.dim_background || config.close_on_backdrop) && (
        <div
          className={`g7pp-backdrop${config.dim_background ? ' g7pp-backdrop--dim' : ''}`}
          aria-hidden="true"
          data-popup-backdrop=""
          onClick={config.close_on_backdrop ? close : undefined}
        />
      )}
      <div
        ref={dialogRef}
        role={preview ? 'group' : 'dialog'}
        aria-modal={preview ? undefined : 'true'}
        aria-label={preview ? t('sirsoft-popup.admin.preview.region') : t('sirsoft-popup.layer.region')}
        tabIndex={preview ? undefined : -1}
        className={`g7pp-dialog g7pp-dialog--${variant}${holding ? ' g7pp-dialog--held' : ''}`}
        style={dialogStyle}
        onKeyDown={onKeyDown}
        onMouseEnter={() => setHovering(true)}
        onMouseLeave={() => setHovering(false)}
        onFocus={(event) => {
          // 열릴 때 레이어 자체로 옮긴 포커스는 멈춤 사유가 아니다 — 안의 단추·링크로 간 포커스만 멈춘다
          if (event.target !== dialogRef.current) {
            setFocusWithin(true);
          }
        }}
        onBlur={(event) => {
          if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
            setFocusWithin(false);
          }
        }}
      >
        {!isSheet && (preview ? (
          <div className="g7pp-grip" aria-hidden="true"><span className="g7pp-grip__dots" /></div>
        ) : (
          // 누르기만 해서는 하는 일이 없다 — 끌거나 화살표 키로 창을 옮긴다
          <button type="button" className="g7pp-grip" aria-label={t('sirsoft-popup.layer.move')} {...moveHandlers}>
            <span className="g7pp-grip__dots" aria-hidden="true" />
          </button>
        ))}
        {/* 시트 손잡이를 아래로 끌어 내리면 닫힌다 — 키보드는 「닫기」·ESC 가 같은 일을 한다 */}
        {isSheet && <div className="g7pp-handle" aria-hidden="true" {...moveHandlers} />}
        <div
          ref={bodyRef}
          className={`g7pp-body${dragging ? ' g7pp-body--dragging' : ''}`}
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={(event) => endDrag(event, false)}
          onPointerCancel={(event) => endDrag(event, true)}
          onLostPointerCapture={(event) => {
            // 터치는 브라우저가 누른 자식에 포인터를 잡아 두므로, 레이어가 넘겨받을 때 자식의 lostpointercapture 가
            // 올라온다 — 이 영역 자신이 잡은 포인터를 잃었을 때만 끌기를 끝낸다
            if (event.target === event.currentTarget) {
              endDrag(event, true);
            }
          }}
          onClickCapture={onClickCapture}
          // 링크·이미지를 끌어 옮기는 브라우저 기본 동작이 끌기를 가로채지 않게
          onDragStart={count > 1 ? (event) => event.preventDefault() : undefined}
        >
          <div className="g7pp-slides" aria-live={autoplaying ? 'off' : 'polite'}>
            {visible.map((popup, i) => {
              const isCurrent = i === current;
              const isPeer = !isCurrent && i === motion.peer;
              const x = isCurrent ? motion.offset : motion.offset + motion.side * 100;
              const placed = isPeer || (isCurrent && (motion.peer !== null || motion.offset !== 0));

              return (
                <div
                  key={popup.id}
                  role="group"
                  aria-roledescription={t('sirsoft-popup.layer.slide')}
                  aria-label={t('sirsoft-popup.layer.counter', { n: i + 1, total: count })}
                  className={`g7pp-slide${motion.moving && (isCurrent || isPeer) ? ' g7pp-slide--moving' : ''}`}
                  // 화면 밖 장은 숨기고, 끄는 동안·넘기는 동안 잠깐 보이는 옆 장은 보조기술·포커스에서 뺀다
                  hidden={!isCurrent && !isPeer}
                  aria-hidden={isPeer ? 'true' : undefined}
                  inert={isPeer}
                  style={placed ? { transform: `translateX(${x}%)` } : undefined}
                  onTransitionEnd={isCurrent ? (event) => {
                    if (event.target === event.currentTarget && event.propertyName === 'transform') {
                      setMotion(REST);
                    }
                  } : undefined}
                  data-popup-id={popup.id}
                >
                  <PopupContent popup={popup} eager={i === 0} isMobile={isMobile} onNavigate={close} inert={!!preview} />
                </div>
              );
            })}
          </div>
          {!isMobile && count > 1 && (
            <>
              <button type="button" className="g7pp-arrow g7pp-arrow--prev" aria-label={t('sirsoft-popup.layer.previous')} onClick={() => go(-1)}>
                <span aria-hidden="true">‹</span>
              </button>
              <button type="button" className="g7pp-arrow g7pp-arrow--next" aria-label={t('sirsoft-popup.layer.next')} onClick={() => go(1)}>
                <span aria-hidden="true">›</span>
              </button>
            </>
          )}
        </div>
        {navigation}
        <div className={`g7pp-footer${config.dismiss_mode === 'close' ? ' g7pp-footer--single' : ''}`}>
          {config.dismiss_mode !== 'close' && (
            <button type="button" className="g7pp-footer__dismiss" onClick={dismiss}>{dismissLabel}</button>
          )}
          <button type="button" className="g7pp-footer__close" onClick={close}>{t('sirsoft-popup.layer.close')}</button>
        </div>
      </div>
    </div>
  );
};

/** 한 장의 내용 props */
interface PopupContentProps {
  /** 팝업 */
  popup: PublicPopup;

  /** 첫 장인지 (이미지를 바로 받는다) */
  eager: boolean;

  /** 레이어가 판정한 기기가 모바일인지 (모바일 이미지가 있으면 그것을 쓴다) */
  isMobile?: boolean;

  /** 사이트 안 링크로 이동할 때 (레이어를 닫는다) */
  onNavigate?: () => void;

  /** 미리보기면 링크를 눌러도 이동하지 않는다 */
  inert?: boolean;
}

/**
 * 팝업 한 장의 내용 — 이미지형(덮는 링크) 또는 직접 작성형(정화된 본문, 넘칠 때만 스크롤 영역).
 *
 * @param props 내용 props
 * @returns 내용
 */
export const PopupContent: React.FC<PopupContentProps> = ({ popup, eager, isMobile = false, onNavigate, inert = false }) => {
  const scrollRef = useRef<HTMLDivElement>(null);
  const [overflowing, setOverflowing] = useState(false);
  const isHtml = popup.content_type === 'html';
  const cleanHtml = useMemo(() => (isHtml ? String(DOMPurify.sanitize(popup.body_html ?? '', PURIFY_CONFIG)) : ''), [isHtml, popup.body_html]);

  useEffect(() => {
    if (!isHtml) {
      return undefined;
    }
    const el = scrollRef.current;
    const check = () => setOverflowing(!!el && el.scrollHeight > el.clientHeight + 1);
    check();
    if (typeof ResizeObserver === 'undefined' || !el) {
      return undefined;
    }
    const observer = new ResizeObserver(check);
    observer.observe(el);

    return () => observer.disconnect();
  }, [isHtml, cleanHtml]);

  if (isHtml) {
    return (
      <div
        ref={scrollRef}
        className="g7pp-html ck-content"
        data-popup-scroll=""
        role={overflowing ? 'region' : undefined}
        aria-label={overflowing ? t('sirsoft-popup.layer.scroll_region') : undefined}
        tabIndex={overflowing && !inert ? 0 : undefined}
        // 저장 때 HTMLPurifier, 출력 때 DOMPurify 로 거른 본문
        dangerouslySetInnerHTML={{ __html: cleanHtml }}
      />
    );
  }

  const pc = popup.image?.pc ?? null;
  const mobile = popup.image?.mobile ?? null;
  // 레이어 판정(엔진 폭·미리보기 기기)과 같은 기준으로 고른다 — `<picture media>` 는 창 폭으로 판정해 어긋난다
  const shown = isMobile ? (mobile ?? pc) : pc;
  const alt = popup.image_alt ?? '';
  const link = popup.link_url ?? '';
  const newWindow = !!popup.link_new_window;

  const onLinkClick = (event: React.MouseEvent<HTMLAnchorElement>) => {
    if (inert) {
      event.preventDefault();

      return;
    }
    if (newWindow || !isInternal(link) || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
      return;
    }
    const core = (window as { G7Core?: { dispatch?: (action: Record<string, unknown>) => void } }).G7Core;
    if (core?.dispatch) {
      event.preventDefault();
      onNavigate?.();
      core.dispatch({ handler: 'navigate', params: { path: link } });
    }
  };

  return (
    <div className="g7pp-media">
      {shown && (
        <img
          src={shown.url}
          alt={link ? '' : alt}
          width={shown.width ?? undefined}
          height={shown.height ?? undefined}
          loading={eager ? 'eager' : 'lazy'}
          fetchPriority={eager ? 'high' : 'auto'}
          className="g7pp-media__img"
          draggable={false}
        />
      )}
      {link && (
        <a
          className="g7pp-cover-link"
          href={link}
          aria-label={alt}
          target={newWindow ? '_blank' : undefined}
          rel={newWindow ? 'noopener noreferrer' : undefined}
          tabIndex={inert ? -1 : undefined}
          onClick={onLinkClick}
        />
      )}
    </div>
  );
};

export default PopupLayer;
