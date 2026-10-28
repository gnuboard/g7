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
  const gdprHeight = useGdprBannerHeight(open && isSheet && !preview);

  const current = count > 0 ? Math.min(index, count - 1) : 0;
  indexRef.current = current;

  // 목록이 바뀌면(경로 이동·기기 전환) 처음 장부터
  useEffect(() => {
    setIndex(0);
    setMotion(REST);
  }, [visibleKey]);

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
      dialogStyle.top = `min(${config.pc_top}px, max(16px, calc(100vh - ${config.pc_height}px - 120px)))`;
    }
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
        className={`g7pp-dialog g7pp-dialog--${variant}`}
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
        {isSheet && <div className="g7pp-handle" aria-hidden="true" />}
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
