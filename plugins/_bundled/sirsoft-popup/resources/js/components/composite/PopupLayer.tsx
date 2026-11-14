import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import DOMPurify from 'dompurify';
import { usePopupRefresh } from '../../lib/usePopupRefresh';
import { DEFAULT_SETTINGS, type PopupDisplaySettings, type PublicPopup } from '../../lib/types';
import { MOBILE_MAX_WIDTH, closableOnPage, rememberClosed, visiblePopups } from '../../lib/popupVisibility';
import { readDismissed, rememberDismissed } from '../../lib/dismissCookie';
import { translate as t } from '../../support/editor';
import { boxesFor } from '../../lib/overlays';
import { ImageFrame } from '../../lib/ImageFrame';
import { popupImageSizes, type PopupImageSizesInput } from '../../lib/thumbnail';
import { sitePath } from '../../apiUrl';
import { useViewportWidth as useEngineWidth } from '../../support/viewportWidth';

/** 레이어 배치(이미지 비율 제외) — 장이 제 이미지 비율을 더해 `<img sizes>` 를 정한다 */
type PopupImageLayout = Omit<PopupImageSizesInput, 'ratio'>;

/**
 * 화면 폭을 지켜봅니다 — 모바일 판정(767px 이하)과 창 자리 재조정에 쓴다. 판정 규칙은 `resources/js/support/viewportWidth.ts`.
 *
 * @returns 화면 폭(px)
 */
function useViewportWidth(): number {
  return useEngineWidth(MOBILE_MAX_WIDTH + 1);
}

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

/** 장 전환 시간(ms) — `main.css` 의 `.g7pp-slide--moving` transition 과 같은 값 */
export const SLIDE_MS = 300;

/** 끌기 시작 판정 거리(px) — 장 넘김은 이만큼 움직인 뒤 가로 성분이 세로보다 크면 끌기(아니면 세로 스크롤), 창 옮기기는 이만큼 움직이면 끌기(못 미치면 단추 클릭) */
const DRAG_START_PX = 8;

/** 놓았을 때 옆 장으로 넘기는 거리 — 표시 폭 대비 비율 */
const DRAG_COMMIT_RATIO = 0.2;

/**
 * 빠른 휙 넘김 — 놓기 직전 FLICK_WINDOW_MS 동안 끈 방향으로 이 속도(px/ms) 이상이고 표시 폭의 이 비율 이상 움직였으면
 * 짧아도 넘긴다. 누른 순간부터의 평균으로 재면 잠깐 누르고 있다가 휙 민 손가락이 느린 끌기로 잡혀 넘어가지 않았다
 */
const FLICK_SPEED = 0.5;
const FLICK_MIN_RATIO = 0.04;
const FLICK_WINDOW_MS = 100;

/**
 * 넘김 컨트롤 캡슐(본문 아래 가운데에 겹침)에 점을 그리는 최대 장 수 — 넘으면 같은 캡슐에 「지금 / 전체」 숫자만 둔다.
 * 점은 작게 보여도 누르는 영역이 44px 이라 장 수만큼 캡슐이 넓어지고, 캡슐은 줄을 바꾸지 않는다.
 * PC 는 기본 창 폭 480px 에 점 8개(352px)와 일시정지(44px)가 들어가는 수, 휴대폰은 가장 좁은 320px 화면의 하단 시트에도
 * 들어가는 수(점 5개 220px + 일시정지 44px)다. 창 폭이 더 좁으면 `dotLimit` 이 그 폭에 들어가는 만큼으로 줄인다.
 */
export const MAX_DOTS = 8;
export const MAX_DOTS_MOBILE = 5;

/** 캡슐 안 단추 하나의 누르는 폭(px) — 점·일시정지 모두 44px(명세 접근성) */
const CONTROL_HIT_PX = 44;

/** 캡슐이 점·일시정지 말고 차지하는 폭(px) — 캡슐 안쪽 여백 4px × 2 + 본문 가장자리와 띄우는 12px × 2(`main.css` 의 `.g7pp-pager`) */
const PAGER_CHROME_PX = 32;

/** 옮긴 창이 화면 가장자리와 남기는 여백(px) */
const MOVE_EDGE_PX = 8;

/** 레이어 층(z-index) — `main.css` 의 `.g7pp-root` 와 같은 값. 이 층 이상인 화면 위 고정 요소(헤더 등)는 창을 덮는다 */
const LAYER_Z = 40;

/** 위아래 고정 요소 사이가 좁을 때 줄이는 본문 높이의 하한(px) */
const FIT_MIN_PX = 120;

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

  /** 문턱(DRAG_START_PX)을 넘겨 끌기로 판정됨 — 그 전에 놓으면 단추 클릭이다 */
  dragging: boolean;

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

  /** 최근 가로 위치 자취 — 맨 앞은 FLICK_WINDOW_MS 직전 지점이다(놓기 직전 속도를 잰다) */
  trail: Array<{ x: number; at: number }>;

  /** 누를 때 잰 슬라이드 영역의 실제 표시 폭 — 축소된 미리보기 틀 안에서도 비율이 맞는다 */
  width: number;

  /** 가로 끌기로 판정됨 */
  dragging: boolean;
}

/**
 * 자취에 지금 위치를 더하고 FLICK_WINDOW_MS 보다 오래된 지점을 걷는다 — 창 직전 지점 하나는 남겨 속도의 기준으로 쓴다.
 * 포인터 이벤트는 움직일 때만 오므로 그 지점 뒤로는 그 자리에 머물렀다고 보고, 창(최근 FLICK_WINDOW_MS) 안의 이동만 잰다.
 *
 * @param trail 끌기 자취 (바뀐다)
 * @param x 지금 가로 위치
 * @param at 지금 시각 (ms)
 * @returns 놓기 직전 가로 속도 (px/ms, 왼쪽이 음수)
 */
function trackVelocity(trail: DragState['trail'], x: number, at: number): number {
  trail.push({ x, at });
  while (trail.length > 2 && at - trail[1].at >= FLICK_WINDOW_MS) {
    trail.shift();
  }
  const from = trail[0];

  return (x - from.x) / Math.max(1, at - Math.max(from.at, at - FLICK_WINDOW_MS));
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
 * 옮긴 거리를 화면 안으로 자릅니다 — 창이 들어가면 가장자리 8px 안쪽, 창이 화면보다 크면 맨 아래(하단 바)가 보이는 데까지.
 * 화면 위 고정 요소가 있으면 위쪽 끝은 그 아래, 바닥 배너가 있으면 아래쪽 끝은 그 위다(둘 다 안 되면 아래가 먼저 —
 * 창을 옮기는 곳이자 「닫기」가 있는 하단 바를 가리지 않는다).
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
  const top = Math.min(window.innerHeight - covers.bottom - base.height, clamp(base.top + y, Math.min(minTop, bottom), Math.max(minTop, bottom)));

  return { x: Math.round(left - base.left), y: Math.round(top - base.top) };
}

/**
 * 캡슐에 점으로 보일 최대 장 수를 정합니다 — 기기별 상한(PC 8·휴대폰 5)과 본문 폭에 한 줄로 들어가는 개수 중 작은 값.
 *
 * @param bodyWidth 본문(캡슐이 겹치는 곳)의 폭(px)
 * @param isMobile 휴대폰 배치인지
 * @param withPause 일시정지 단추가 같은 캡슐에 있는지
 * @returns 점으로 보일 최대 장 수
 */
function dotLimit(bodyWidth: number, isMobile: boolean, withPause: boolean): number {
  const room = Math.floor((bodyWidth - PAGER_CHROME_PX - (withPause ? CONTROL_HIT_PX : 0)) / CONTROL_HIT_PX);

  return Math.max(0, Math.min(isMobile ? MAX_DOTS_MOBILE : MAX_DOTS, room));
}

/** 사용자 레이어 props */
export interface PopupLayerProps {
  /** 공개 API 팝업 (서버 순서 = 관리자 순서) */
  popups?: PublicPopup[] | null;

  /** 표시 방식 */
  settings?: Partial<PopupDisplaySettings> | null;

  /** 다음 서버 기간 전환 시각(ISO 8601). 0건이어도 갱신 타이머를 유지한다. */
  refreshAt?: string | null;

  /** 응답을 만든 서버 시각(ISO 8601). 방문자 시계 차이 보정용. */
  serverTime?: string | null;

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
export const PopupLayer: React.FC<PopupLayerProps> = ({ popups, settings, path = '/', className = '', preview, refreshAt, serverTime }) => {
  usePopupRefresh(refreshAt, serverTime, !preview);
  const config: PopupDisplaySettings = { ...DEFAULT_SETTINGS, ...(settings ?? {}) };
  const viewportWidth = useViewportWidth();
  const viewportMobile = viewportWidth <= MOBILE_MAX_WIDTH;
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
  // 하단 바를 끌어 옮긴 뒤 같은 동작에서 오는 click 은 「닫기」·「보지 않기」가 아니다 — 그 click 하나를 막는다
  const movedRef = useRef(false);
  // 하단 바 누름 동안 창(window)에 붙인 포인터 감시를 떼고 누름을 끝내는 함수
  const moveEndRef = useRef<(() => void) | null>(null);
  shiftRef.current = shift;
  // 위아래 고정 요소 사이에 들어가도록 줄인 본문 높이 상한(px) — 바뀌면 다시 그린 뒤 자리를 잡는다
  const [fit, setFit] = useState<number | null>(null);
  const fitRef = useRef<number | null>(null);
  const pendingRef = useRef<MoveShift | null>(null);
  const gdprRef = useRef(0);
  // 내용을 한 번 그린 장(팝업 id) — 숨은 장은 지금 장·다음 장·옆 장이 될 때 처음 그리고, 그 뒤로는 계속 둔다(받은 이미지를 다시 받지 않게)
  const renderedRef = useRef<Set<number>>(new Set());

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
    // 하단 바는 그대로 두고 본문만 줄인다(넘김 컨트롤은 본문 위에 겹쳐 있다)
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

  // 엔진이 바뀐 폭을 알린 렌더에서 한 번 더 맞춘다 — 엔진은 resize 를 늦춰(150ms) 알리고 템플릿은 그때 폭에 맞는 헤더를
  // 다시 그리므로, resize 이벤트 순간에 잰 위 덮개는 옛 모양이다(좁은 화면 → 넓은 화면이면 헤더가 아직 없어 0 으로 잰다)
  const widthRef = useRef(viewportWidth);
  useLayoutEffect(() => {
    if (widthRef.current === viewportWidth) {
      return;
    }
    widthRef.current = viewportWidth;
    if (open && !isSheet && !preview) {
      settle(shiftRef.current.x, shiftRef.current.y);
    }
  }, [viewportWidth, open, isSheet, preview, settle]);

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

  // 누른 채 레이어가 사라지면 창에 붙인 포인터 감시를 뗀다
  useEffect(() => () => moveEndRef.current?.(), []);

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
    // 보이던 팝업만이 아니라 이 경로의 후보 전부 — 폭이 바뀌어 기기 판정이 달라져도 레이어가 다시 열리지 않게(#902)
    rememberClosed(closableOnPage(Array.isArray(popups) ? popups : [], path));
    setDismissVersion((v) => v + 1);
  }, [popups, path, preview]);

  const dismiss = useCallback(() => {
    if (config.dismiss_mode === 'close' || preview) {
      return;
    }
    // 「보지 않기」 기록은 보이던 팝업만(2026-10-27 결정), 이 페이지에서 레이어를 닫는 것은 「닫기」와 같이 이 경로 후보 전부(#902)
    rememberDismissed(visible.map((p) => p.id), config.dismiss_mode, config.dismiss_days);
    rememberClosed(closableOnPage(Array.isArray(popups) ? popups : [], path));
    setDismissVersion((v) => v + 1);
  }, [visible, popups, path, config.dismiss_mode, config.dismiss_days, preview]);

  const onKeyDown = useCallback((event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();

      return;
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
  }, [close, count, go, preview]);

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
    const velocity = trackVelocity(drag.trail, event.clientX, Date.now());
    const flick = Math.abs(ratio) >= FLICK_MIN_RATIO && Math.abs(velocity) >= FLICK_SPEED && Math.sign(velocity) === Math.sign(ratio);
    const commit = !cancelled && ratio !== 0 && (Math.abs(ratio) >= DRAG_COMMIT_RATIO || flick);
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
    // 넘김 단추·컨트롤 캡슐 위 누름은 끌기가 아니다(본문 위에 겹쳐 있어도 장을 끌지 않는다)
    if ((event.target as Element).closest?.('.g7pp-arrow, .g7pp-pager')) {
      return;
    }
    draggedRef.current = false;
    const width = bodyRef.current?.getBoundingClientRect().width ?? 0;
    if (width <= 0) {
      return;
    }
    dragRef.current = { id: event.pointerId, x: event.clientX, y: event.clientY, trail: [{ x: event.clientX, at: Date.now() }], width, dragging: false };
    setPressing(true);
  };

  const onPointerMove = (event: React.PointerEvent<HTMLDivElement>) => {
    const drag = dragRef.current;
    if (!drag || drag.id !== event.pointerId) {
      return;
    }
    const dx = event.clientX - drag.x;
    const dy = event.clientY - drag.y;
    trackVelocity(drag.trail, event.clientX, Date.now());
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
   * 하단 바를 누른다 — 아직 옮기지 않는다. 문턱(DRAG_START_PX)을 넘겨 움직여야 창 옮기기가 되고, 못 미치고 놓으면
   * 누른 단추(「닫기」·「보지 않기」)의 click 이 그대로 동작한다.
   *
   * 움직임·놓음은 창(window)에서 받는다 — 마우스는 누른 요소가 포인터를 잡지 않으므로, 빠르게 끌면 문턱을 넘는 첫 움직임이
   * 바(높이 52px) 밖에서 와 바에는 오지 않는다. 바는 끌기로 판정된 뒤에 포인터를 잡는다 — 누를 때 잡으면 단추 클릭의
   * 대상이 바로 바뀐다.
   *
   * @param event 누름 포인터 이벤트
   */
  const onMoveDown = (event: React.PointerEvent<HTMLElement>) => {
    if (moveRef.current?.dragging || (event.pointerType === 'mouse' && event.button !== 0)) {
      return;
    }
    const base = measureBase();
    if (!base) {
      return;
    }
    moveEndRef.current?.();
    const bar = event.currentTarget;
    const move: MoveState = {
      id: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      dragging: false,
      start: shiftRef.current,
      base,
      covers: measureCovers(),
    };
    movedRef.current = false;
    moveRef.current = move;
    setPressing(true);

    const onMove = (e: PointerEvent) => {
      if (e.pointerId !== move.id || moveRef.current !== move) {
        return;
      }
      const dx = e.clientX - move.x;
      const dy = e.clientY - move.y;
      if (!move.dragging) {
        if (Math.abs(dx) < DRAG_START_PX && Math.abs(dy) < DRAG_START_PX) {
          return;
        }
        move.dragging = true;
        movedRef.current = true;
        setHolding(true);
        try {
          bar.setPointerCapture?.(move.id);
        } catch {
          // 합성 이벤트 등 잡을 수 없는 포인터
        }
      }
      // 누르는 동안 생긴 글자 선택을 걷어낸다
      window.getSelection?.()?.removeAllRanges();
      setShift(clampShift(move.base, move.start.x + dx, move.start.y + dy, move.covers));
    };

    /** 누름을 끝낸다 — 옮긴 자리에 두고, 끌었으면 같은 동작에서 오는 click 하나를 막는다 */
    const finish = () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onEnd);
      window.removeEventListener('pointercancel', onEnd);
      if (moveEndRef.current === finish) {
        moveEndRef.current = null;
      }
      if (moveRef.current === move) {
        moveRef.current = null;
      }
      setPressing(false);
      if (!move.dragging) {
        return;
      }
      setHolding(false);
      try {
        bar.releasePointerCapture?.(move.id);
      } catch {
        // 이미 풀린 포인터
      }
      // 손가락으로 끌면 브라우저가 click 을 보내지 않는다 — 막을 표시가 다음 누름까지 남지 않게 이 동작이 끝나면 지운다
      window.setTimeout(() => {
        movedRef.current = false;
      }, 0);
    };

    function onEnd(e: PointerEvent) {
      if (e.pointerId === move.id) {
        finish();
      }
    }

    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onEnd);
    window.addEventListener('pointercancel', onEnd);
    moveEndRef.current = finish;
  };

  /** 창 옮기기 — PC 창·모바일 가운데 창의 하단 바. 하단 시트·미리보기는 끌어도 아무것도 하지 않는다(단추 click 은 그대로) */
  const movable = !preview && !isSheet;
  const moveHandlers = movable ? {
    onPointerDown: onMoveDown,
    onClickCapture: (event: React.MouseEvent<HTMLElement>) => {
      if (movedRef.current) {
        // 끌어 옮긴 동작의 click — 「닫기」·「보지 않기」를 실행하지 않는다
        event.preventDefault();
        event.stopPropagation();
        movedRef.current = false;
      }
    },
  } : {};

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
      // 아래 몫 76px = 하단 바 52px + 여백 24px (넘김 컨트롤은 본문 위에 겹쳐 높이를 차지하지 않는다)
      dialogStyle.top = `min(${config.pc_top}px, max(16px, calc(100vh - ${config.pc_height}px - 76px)))`;
    }
  }
  if (fit !== null) {
    dialogStyle['--g7pp-fit-h' as string] = `${fit}px`;
  }
  if (shift.x !== 0 || shift.y !== 0) {
    dialogStyle['--g7pp-move-x' as string] = `${shift.x}px`;
    dialogStyle['--g7pp-move-y' as string] = `${shift.y}px`;
  }

  // 「보지 않기」는 그때 보이던 팝업 전부를 기록한다 — 여러 장이면 문구에 그 범위(「모두」)를 밝힌다
  const dismissScope = count > 1 ? '_all' : '';
  const dismissLabel = config.dismiss_mode === 'days'
    ? t(`sirsoft-popup.layer.dismiss_days${dismissScope}`, { days: config.dismiss_days })
    : t(`sirsoft-popup.layer.dismiss_today${dismissScope}`);
  const counter = t('sirsoft-popup.layer.counter', { n: current + 1, total: count });

  const withPause = config.autoplay && !reducedMotion;
  // 캡슐이 겹치는 본문의 폭 — PC 는 설정 폭(화면 좌우 16px 여백 안), 휴대폰 가운데 창은 좌우 24px 여백, 하단 시트는 화면 폭.
  // 미리보기는 틀이 기기 크기라 화면 폭으로 줄이지 않는다
  const screenWidth = preview ? Number.POSITIVE_INFINITY : viewportWidth;
  let bodyWidth = Math.min(config.pc_width, screenWidth - 32);
  if (isMobile) {
    bodyWidth = isSheet ? screenWidth : screenWidth - 48;
  }
  const showDots = count <= dotLimit(bodyWidth, isMobile, withPause);
  // 이미지 srcset 의 sizes 를 정할 배치 — 위 본문 폭과 같은 기하(PC 설정 폭·높이, 휴대폰 가운데 창·하단 시트), 미리보기는 기기 틀 안의 상한.
  // 장마다 이미지 비율이 달라(잘리지 않게 담는 폭 = 칸 높이 × 비율) sizes 는 장이 제 이미지로 마저 정한다
  // 헤더·GDPR 배너를 피해 줄인 본문 상한(fit)도 넘긴다 — CSS 본문 높이와 같은 식이어야 그린 폭에 맞는 후보를 고른다
  const imageLayout: PopupImageLayout = { isMobile, isSheet, pcWidth: config.pc_width, pcHeight: config.pc_height, preview: !!preview, fitHeight: fit };
  // 옮길 수 있는 창(PC·휴대폰 가운데 창)은 본문 상한(fit)을 잰 뒤에 내용을 붙인다 — 재기 전에 이미지를 붙이면 fit 없는 sizes 로
  // 큰 후보를 먼저 요청했다가, fit 이 정해져 sizes 가 바뀌는 순간 끊고 작은 후보를 다시 받는다(실측: lg 요청 취소 뒤 md).
  // fit 은 열리는 렌더의 layout effect 에서 정해져 화면에 그려지기 전에 다시 그리므로 빈 창이 보이지 않는다
  const contentReady = !movable || fit !== null;

  // 넘김 컨트롤 — 별도 띠 없이 본문 아래쪽 가운데에 겹친 캡슐(점 또는 「n / N」 + 일시정지). 덮는 링크보다 위 층이고
  // 본문 끌기를 시작하지 않는다(onPointerDown). 장(슬라이드) 밖에 있어 장을 넘겨도 제자리다
  const pager = count > 1 ? (
    <div className="g7pp-pager">
      {showDots ? visible.map((popup, i) => (
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
      )) : (
        <span className="g7pp-counter">{counter}</span>
      )}
      {withPause && (
        <button
          type="button"
          className="g7pp-pager__pause"
          aria-pressed={paused}
          aria-label={paused ? t('sirsoft-popup.layer.play') : t('sirsoft-popup.layer.pause')}
          onClick={() => setPaused((p) => !p)}
        >
          <span aria-hidden="true">{paused ? '▶' : 'Ⅱ'}</span>
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
        <div
          ref={bodyRef}
          className={`g7pp-body${count > 1 ? ' g7pp-body--paged' : ''}${dragging ? ' g7pp-body--dragging' : ''}`}
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
              // 내용은 지금 장·다음 장·끄는(넘기는) 동안의 옆 장·이미 그린 장만 그린다 — 숨은 장의 이미지와 본문 이미지를
              // 첫 방문에 받지 않는다. 점으로 먼 장에 가도 그 장이 지금 장이 되는 렌더에서 바로 그린다
              const wanted = isCurrent || isPeer || i === (current + 1) % count || renderedRef.current.has(popup.id);
              if (wanted) {
                renderedRef.current.add(popup.id);
              }

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
                  {wanted && contentReady && (
                    <PopupContent popup={popup} eager={isCurrent} isMobile={isMobile} imageLayout={imageLayout} onNavigate={close} inert={!!preview} />
                  )}
                </div>
              );
            })}
          </div>
          {/* 이전·다음 — PC·휴대폰 모두 본문 양옆에 겹친 원형 단추 */}
          {count > 1 && (
            <>
              <button type="button" className="g7pp-arrow g7pp-arrow--prev" aria-label={t('sirsoft-popup.layer.previous')} onClick={() => go(-1)}>
                <span aria-hidden="true">‹</span>
              </button>
              <button type="button" className="g7pp-arrow g7pp-arrow--next" aria-label={t('sirsoft-popup.layer.next')} onClick={() => go(1)}>
                <span aria-hidden="true">›</span>
              </button>
            </>
          )}
          {pager}
        </div>
        {/* 하단 바를 끌면(단추 위에서 눌러도) 창이 옮겨진다 — 끌지 않고 누르면 단추가 동작한다 */}
        <div
          className={`g7pp-footer${config.dismiss_mode === 'close' ? ' g7pp-footer--single' : ''}${movable ? ' g7pp-footer--movable' : ''}`}
          {...moveHandlers}
        >
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

  /** 지금 보이는 장인지 (이미지를 바로 받는다) */
  eager: boolean;

  /** 레이어가 판정한 기기가 모바일인지 (모바일 이미지가 있으면 그것을 쓴다) */
  isMobile?: boolean;

  /** 이미지가 그려질 폭의 상한(`<img sizes>`) — 주면 코어 썸네일 srcset 을 붙인다(`imageLayout` 보다 먼저) */
  imageSizes?: string;

  /** 레이어 배치 — 주면 보이는 이미지의 비율과 함께 `<img sizes>` 를 정하고 코어 썸네일 srcset 을 붙인다 */
  imageLayout?: PopupImageLayout;

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
export const PopupContent: React.FC<PopupContentProps> = ({ popup, eager, isMobile = false, imageSizes, imageLayout, onNavigate, inert = false }) => {
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
        // audit:allow extension-class-self-contained ck-content 는 CKEditor 라이브러리 본문 CSS 가 거는 이름이다 — 편집기 플러그인이 있으면 그 본문 서식이 그대로 읽히게 함께 둔다
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
  const sizes = imageSizes ?? (imageLayout && shown
    ? popupImageSizes({ ...imageLayout, ratio: shown.width && shown.height ? shown.width / shown.height : null })
    : undefined);
  const boxes = boxesFor(popup.overlays, isMobile && !!mobile);
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
      {/* 글 상자는 이미지 표시 사각형(프레임) 기준 % 로 겹치고, 누름·끌기를 받지 않아 덮는 링크·장 넘김이 그대로 동작한다 */}
      {shown && <ImageFrame image={shown} alt={link ? '' : alt} eager={eager} boxes={boxes} sizes={sizes} />}
      {link && (
        <a
          className="g7pp-cover-link"
          // 새 탭·가운데 단추로 열면 브라우저가 그대로 여는 주소라 하위 폴더 마운트 접두사를 붙인다. 보통 누름은
          // 엔진 navigate 에 접두사 없는 경로를 넘긴다(라우터가 주소창에 쓸 때 붙인다)
          href={sitePath(link)}
          aria-label={alt || t('sirsoft-popup.layer.link')}
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
