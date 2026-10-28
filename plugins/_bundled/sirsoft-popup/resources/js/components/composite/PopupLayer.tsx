import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
  const dialogRef = useRef<HTMLDivElement>(null);
  const returnFocusRef = useRef<HTMLElement | null>(null);

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

  // 목록이 바뀌면(경로 이동·기기 전환) 처음 장부터
  useEffect(() => {
    setIndex(0);
  }, [visibleKey]);

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

  const autoplaying = open && config.autoplay && count > 1 && !paused && !hovering && !focusWithin && !reducedMotion;

  useEffect(() => {
    if (!autoplaying) {
      return undefined;
    }
    const timer = window.setInterval(() => setIndex((i) => (i + 1) % count), Math.max(1, config.autoplay_interval) * 1000);

    return () => window.clearInterval(timer);
  }, [autoplaying, count, config.autoplay_interval]);

  const go = useCallback((step: number) => setIndex((i) => (i + step + count) % count), [count]);

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
      ) ?? []).filter((el) => !el.closest('[hidden]'));
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

  // 손가락 좌우 넘기기 — 40px 넘게 가로로 끌면 넘긴다
  const touchStart = useRef<{ x: number; y: number } | null>(null);
  const onPointerDown = (event: React.PointerEvent) => {
    if (event.pointerType !== 'mouse') {
      touchStart.current = { x: event.clientX, y: event.clientY };
    }
  };
  const onPointerUp = (event: React.PointerEvent) => {
    const start = touchStart.current;
    touchStart.current = null;
    if (!start || count < 2) {
      return;
    }
    const dx = event.clientX - start.x;
    if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(event.clientY - start.y)) {
      go(dx < 0 ? 1 : -1);
    }
  };

  if (!open) {
    return null;
  }

  const current = Math.min(index, count - 1);
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
      {visible.map((popup, i) => (
        <button
          key={popup.id}
          type="button"
          className={`g7pp-dot${i === current ? ' g7pp-dot--current' : ''}`}
          aria-label={t('sirsoft-popup.layer.go_to', { n: i + 1 })}
          aria-current={i === current ? 'true' : undefined}
          onClick={() => setIndex(i)}
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
        <div className="g7pp-body" onPointerDown={onPointerDown} onPointerUp={onPointerUp}>
          <div className="g7pp-slides" aria-live={autoplaying ? 'off' : 'polite'}>
            {visible.map((popup, i) => (
              <div
                key={popup.id}
                role="group"
                aria-roledescription={t('sirsoft-popup.layer.slide')}
                aria-label={t('sirsoft-popup.layer.counter', { n: i + 1, total: count })}
                className="g7pp-slide"
                hidden={i !== current}
                data-popup-id={popup.id}
              >
                <PopupContent popup={popup} eager={i === 0} isMobile={isMobile} onNavigate={close} inert={!!preview} />
              </div>
            ))}
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
