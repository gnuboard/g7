import React, { useEffect, useMemo, useRef, useState } from 'react';
import { PopupLayer } from './PopupLayer';
import { DEFAULT_SETTINGS, type PopupDisplaySettings, type PublicPopup } from '../../lib/types';
import type { OverlayBox, Overlays, PublicOverlayBox } from '../../lib/overlays';

/** 관리자 폼의 이미지 한 장 (업로드 응답 모양) */
interface FormImage {
  url?: string;
  /** 원본 직접 주소 (공개 자산 디스크 행만) — 사용자 화면과 같은 주소로 미리 그린다 */
  direct_url?: string | null;
  width?: number | null;
  height?: number | null;
}

/** 관리자 등록·수정 폼 값 중 미리보기가 쓰는 것 */
export interface PopupPreviewForm {
  content_type?: 'image' | 'html';
  image_alt?: Record<string, string> | null;
  body_html?: Record<string, string> | null;
  link_url?: string | null;
  link_new_window?: boolean;
  images?: { pc?: FormImage | null; mobile?: FormImage | null } | null;
  overlays?: Overlays<OverlayBox> | null;
}

/** 미리보기 props */
export interface PopupPreviewProps {
  /** 기기 */
  device?: 'pc' | 'mobile';

  /** 표시 방식 (display-settings 응답의 settings) */
  settings?: Partial<PopupDisplaySettings> | null;

  /** 등록·수정 폼 값 — 없으면 표본 팝업 3장(환경설정 화면) */
  form?: PopupPreviewForm | null;

  /** 보여 줄 언어 (언어 탭) */
  locale?: string;

  /** 사이트 기본 언어 — 탭 언어 값이 비면 이 언어로 */
  defaultLocale?: string;

  /** 실제 크기 — 틀을 줄이지 않고 기기 크기 그대로 그린다(넘치면 감싼 칸이 스크롤). 글자 크기를 판단하는 큰 창용 */
  actualSize?: boolean;
}

/** 축소 전 틀 크기 — PC 는 흔한 노트북 화면, 모바일은 시안 보드 폭 */
const FRAMES = {
  pc: { width: 1280, height: 800 },
  mobile: { width: 390, height: 780 },
} as const;

/**
 * 언어 값을 고릅니다(탭 언어 → 기본 언어 → 첫 값).
 *
 * @param value 다국어 값
 * @param locale 탭 언어
 * @param defaultLocale 기본 언어
 * @returns 고른 값
 */
function pick(value: Record<string, string> | null | undefined, locale: string, defaultLocale: string): string {
  for (const key of [...new Set([locale, defaultLocale, ...Object.keys(value ?? {})])]) {
    if (typeof value?.[key] === 'string' && value[key].trim() !== '') return value[key];
  }

  return '';
}

/**
 * 폼의 글 상자를 공개 응답 모양으로 바꿉니다 — 글은 탭 언어 → 기본 언어로 고르고, 글이 빈 상자는 뺍니다(서버와 같은 규칙).
 *
 * @param boxes 폼 상자 목록
 * @param locale 탭 언어
 * @param defaultLocale 기본 언어
 * @returns 공개 상자 목록
 */
function presentBoxes(boxes: OverlayBox[] | null | undefined, locale: string, defaultLocale: string): PublicOverlayBox[] {
  return (boxes ?? []).flatMap((box) => {
    const text = pick(box.text, locale, defaultLocale);

    return text ? [{ ...box, text }] : [];
  });
}

/** 다국어 본문 */
type LocalizedBody = Record<string, string> | null | undefined;

/** `G7Core.state` 중 이 훅이 쓰는 것 */
interface StateApi {
  get?: () => { _local?: { form?: { body_html?: LocalizedBody } } } | null | undefined;
  subscribe?: (listener: (state: { _local?: { form?: { body_html?: LocalizedBody } } }) => void) => unknown;
}

/**
 * 폼 본문을 화면 다시 그리기 없이도 따라갑니다.
 *
 * CKEditor 5 는 타이핑 성능을 위해 본문을 `setLocal({ render: false, selfManaged: true })` 로 상태에만 쓴다. 그래서 `form` props 는
 * 다른 칸이 화면을 다시 그릴 때까지 옛 본문에 머문다 — 운영자가 이전 문구를 보고 배치를 판단하게 된다. 상태 구독은 그런 쓰기도
 * 알려 주므로(`setGlobalState` 가 다시 그리기를 건너뛰기 전에 리스너를 부른다) 그 본문을 받아 쓰고, props 가 바뀌면 props 를 정본으로 돌린다.
 * 마운트할 때는 알림을 기다리지 않고 상태의 지금 본문부터 읽는다.
 *
 * @param enabled 폼 미리보기인지 (표본 미리보기는 구독하지 않는다)
 * @param propBody props 로 받은 본문
 * @returns 지금 보여 줄 본문
 */
function useLiveBody(enabled: boolean, propBody: LocalizedBody): LocalizedBody {
  // 새로 그려지는 미리보기(실제 크기 창 등)는 알림이 이미 지나간 뒤라, 마운트할 때 상태의 지금 본문을 한 번 읽는다 —
  // 창이 받는 부모 `_local` 스냅숏은 다시 그리기 없이 쓴 본문을 담지 못해 옛 본문(새 팝업이면 빈 본문)이다
  const [live, setLive] = useState<{ base: LocalizedBody; body: LocalizedBody } | null>(() => {
    const body = enabled ? (window as { G7Core?: { state?: StateApi } }).G7Core?.state?.get?.()?._local?.form?.body_html : undefined;

    return body && body !== propBody ? { base: propBody, body } : null;
  });
  const baseRef = useRef(propBody);
  baseRef.current = propBody;

  useEffect(() => {
    const state = (window as { G7Core?: { state?: StateApi } }).G7Core?.state;
    if (!enabled || typeof state?.subscribe !== 'function') {
      return undefined;
    }
    const unsubscribe = state.subscribe((global) => {
      const body = global?._local?.form?.body_html;
      // 본문과 무관한 상태 변경은 같은 참조로 오므로 다시 그리지 않는다
      setLive((prev) => ((prev ? prev.body : baseRef.current) === body ? prev : { base: baseRef.current, body }));
    });

    return typeof unsubscribe === 'function' ? (unsubscribe as () => void) : undefined;
  }, [enabled]);

  // 구독으로 받은 본문은 그때의 props 위에서만 유효하다 — props 가 바뀌었으면(다른 칸이 화면을 다시 그림) props 가 최신이다
  return live && live.base === propBody ? live.body : propBody;
}

/** 표시 방식 중 숫자 값 — 환경설정 입력칸은 문자열을 준다 */
const NUMERIC_SETTINGS = ['pc_left', 'pc_top', 'pc_width', 'pc_height', 'autoplay_interval', 'dismiss_days'] as const;

/**
 * 표시 방식 값을 레이어가 쓰는 모양으로 맞춥니다 — 숫자 칸의 문자열은 수로, 비었거나 숫자가 아니면 빼서 기본값을 쓰게 합니다.
 * 환경설정 화면은 저장 전 입력 값을 그대로 넘기므로, 빈 칸이 `min(px, …)` 처럼 깨진 배치가 되지 않게 여기서 거른다.
 *
 * @param settings 표시 방식 (입력 중인 값일 수 있다)
 * @returns 기본값 위에 얹은 표시 방식
 */
function normalizeSettings(settings: Partial<PopupDisplaySettings> | null | undefined): PopupDisplaySettings {
  const out: Record<string, unknown> = { ...DEFAULT_SETTINGS, ...(settings ?? {}) };
  for (const key of NUMERIC_SETTINGS) {
    const raw = (settings as Record<string, unknown> | null | undefined)?.[key];
    const value = typeof raw === 'string' && raw.trim() !== '' ? Number(raw) : raw;
    out[key] = typeof value === 'number' && Number.isFinite(value) ? value : DEFAULT_SETTINGS[key];
  }

  return out as unknown as PopupDisplaySettings;
}

/** 표본 팝업 — 환경설정 화면 미리보기용 */
const SAMPLES: PublicPopup[] = [1, 2, 3].map((n) => ({
  id: -n,
  content_type: 'html',
  page_scope: 'all',
  device: 'all',
  body_html: `<p class="g7pp-sample g7pp-sample--${n}">${n}</p>`,
}));

/**
 * 관리자 미리보기 — 사용자 레이어(`PopupLayer`)와 같은 마크업을 기기 틀 안에 축소해 그린다.
 * 틀 안의 링크는 동작하지 않고, 자동 넘김·닫기·보지 않기도 하지 않는다.
 *
 * @param props 미리보기 props
 * @returns 미리보기
 */
export const PopupPreview: React.FC<PopupPreviewProps> = ({ device = 'pc', settings, form, locale: localeProp, defaultLocale: defaultLocaleProp, actualSize = false }) => {
  // 언어를 받지 못했으면(설정 조회 실패 등) 특정 언어로 고정하지 않고 지금 화면 언어를 쓴다
  const currentLocale = (window as { G7Core?: { locale?: { current?: () => string } } }).G7Core?.locale?.current?.() ?? '';
  const locale = localeProp || currentLocale;
  const defaultLocale = defaultLocaleProp || currentLocale;
  const frame = FRAMES[device];
  const boxRef = useRef<HTMLDivElement>(null);
  const [scale, setScale] = useState(0.4);

  // 틀 폭에 맞춰 화면을 줄인다(CSS 만으로는 길이÷길이 배율을 낼 수 없다). 실제 크기면 줄이지 않는다
  useEffect(() => {
    const box = boxRef.current;
    if (!box || actualSize) {
      return undefined;
    }
    const measure = () => {
      if (box.clientWidth > 0) {
        setScale(box.clientWidth / frame.width);
      }
    };
    measure();
    if (typeof ResizeObserver === 'undefined') {
      return undefined;
    }
    const observer = new ResizeObserver(measure);
    observer.observe(box);

    return () => observer.disconnect();
  }, [frame.width, actualSize]);
  const bodyHtml = useLiveBody(!!form, form?.body_html);
  const scrollRef = useRef<HTMLDivElement>(null);

  // 실제 크기 틀이 칸보다 크면(좁은 휴대폰에서 PC 1280 틀) 열릴 때 팝업이 칸 가운데 오게 한다 — 그대로 두면 처음 보이는 곳이 빈 배경이다.
  // 팝업 위치는 레이어가 그린 뒤에 정해지므로 다음 프레임에 잰다
  useEffect(() => {
    const scroller = scrollRef.current;
    if (!actualSize || !scroller || typeof requestAnimationFrame !== 'function') {
      return undefined;
    }
    const frameId = requestAnimationFrame(() => {
      const dialog = scroller.querySelector<HTMLElement>('.g7pp-dialog');
      if (!dialog) {
        return;
      }
      const box = scroller.getBoundingClientRect();
      const target = dialog.getBoundingClientRect();
      scroller.scrollLeft += Math.round(target.left + target.width / 2 - (box.left + scroller.clientWidth / 2));
      scroller.scrollTop += Math.round(target.top + target.height / 2 - (box.top + scroller.clientHeight / 2));
    });

    return () => {
      if (typeof cancelAnimationFrame === 'function') {
        cancelAnimationFrame(frameId);
      }
    };
  }, [actualSize, device]);
  const popups = useMemo<PublicPopup[]>(() => {
    if (!form) {
      return SAMPLES;
    }
    const isHtml = form.content_type === 'html';
    const pc = form.images?.pc?.url ? { url: form.images.pc.url, direct_url: form.images.pc.direct_url ?? null, width: form.images.pc.width ?? null, height: form.images.pc.height ?? null } : null;
    const mobile = form.images?.mobile?.url ? { url: form.images.mobile.url, direct_url: form.images.mobile.direct_url ?? null, width: form.images.mobile.width ?? null, height: form.images.mobile.height ?? null } : null;

    return [{
      id: 0,
      content_type: isHtml ? 'html' : 'image',
      page_scope: 'all',
      device: 'all',
      image: isHtml ? null : { pc, mobile },
      image_alt: isHtml ? '' : pick(form.image_alt, locale, defaultLocale),
      link_url: isHtml ? null : (form.link_url || null),
      link_new_window: !isHtml && !!form.link_new_window,
      body_html: isHtml ? pick(bodyHtml, locale, defaultLocale) : '',
      overlays: isHtml || !form.overlays ? null : {
        pc: presentBoxes(form.overlays.pc, locale, defaultLocale),
        // 모바일 이미지가 없으면 휴대폰도 PC 배치(서버가 모바일 배치를 싣지 않는다)
        mobile: mobile && form.overlays.mobile ? presentBoxes(form.overlays.mobile, locale, defaultLocale) : null,
      },
    }];
  }, [form, bodyHtml, locale, defaultLocale]);

  const screen = (
    <div
      ref={boxRef}
      className={`g7pp-frame g7pp-frame--${device}${actualSize ? ' g7pp-frame--actual' : ''}`}
      style={actualSize ? { width: frame.width, height: frame.height } : { aspectRatio: `${frame.width} / ${frame.height}` }}
      data-preview-device={device}
    >
      <div className="g7pp-frame__screen" style={{ width: frame.width, height: frame.height, transform: `scale(${actualSize ? 1 : scale})`, ['--g7pp-frame-h' as string]: `${frame.height}px` }}>
        <div className="g7pp-frame__page" aria-hidden="true">
          <span />
          <span />
          <span />
        </div>
        <PopupLayer popups={popups} settings={normalizeSettings(settings)} preview={device} />
      </div>
    </div>
  );

  return actualSize ? <div ref={scrollRef} className="g7pp-frame-scroll">{screen}</div> : screen;
};

export default PopupPreview;
