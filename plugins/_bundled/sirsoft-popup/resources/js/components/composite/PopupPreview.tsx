import React, { useEffect, useMemo, useRef, useState } from 'react';
import { PopupLayer } from './PopupLayer';
import { DEFAULT_SETTINGS, type PopupDisplaySettings, type PublicPopup } from '../../lib/types';
import type { OverlayBox, Overlays, PublicOverlayBox } from '../../lib/overlays';

/** 관리자 폼의 이미지 한 장 (업로드 응답 모양) */
interface FormImage {
  url?: string;
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
export const PopupPreview: React.FC<PopupPreviewProps> = ({ device = 'pc', settings, form, locale: localeProp, defaultLocale: defaultLocaleProp }) => {
  // 언어를 받지 못했으면(설정 조회 실패 등) 특정 언어로 고정하지 않고 지금 화면 언어를 쓴다
  const currentLocale = (window as { G7Core?: { locale?: { current?: () => string } } }).G7Core?.locale?.current?.() ?? '';
  const locale = localeProp || currentLocale;
  const defaultLocale = defaultLocaleProp || currentLocale;
  const frame = FRAMES[device];
  const boxRef = useRef<HTMLDivElement>(null);
  const [scale, setScale] = useState(0.4);

  // 틀 폭에 맞춰 화면을 줄인다(CSS 만으로는 길이÷길이 배율을 낼 수 없다)
  useEffect(() => {
    const box = boxRef.current;
    if (!box) {
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
  }, [frame.width]);
  const popups = useMemo<PublicPopup[]>(() => {
    if (!form) {
      return SAMPLES;
    }
    const isHtml = form.content_type === 'html';
    const pc = form.images?.pc?.url ? { url: form.images.pc.url, width: form.images.pc.width ?? null, height: form.images.pc.height ?? null } : null;
    const mobile = form.images?.mobile?.url ? { url: form.images.mobile.url, width: form.images.mobile.width ?? null, height: form.images.mobile.height ?? null } : null;

    return [{
      id: 0,
      content_type: isHtml ? 'html' : 'image',
      page_scope: 'all',
      device: 'all',
      image: isHtml ? null : { pc, mobile },
      image_alt: isHtml ? '' : pick(form.image_alt, locale, defaultLocale),
      link_url: isHtml ? null : (form.link_url || null),
      link_new_window: !isHtml && !!form.link_new_window,
      body_html: isHtml ? pick(form.body_html, locale, defaultLocale) : '',
      overlays: isHtml || !form.overlays ? null : {
        pc: presentBoxes(form.overlays.pc, locale, defaultLocale),
        // 모바일 이미지가 없으면 휴대폰도 PC 배치(서버가 모바일 배치를 싣지 않는다)
        mobile: mobile && form.overlays.mobile ? presentBoxes(form.overlays.mobile, locale, defaultLocale) : null,
      },
    }];
  }, [form, locale, defaultLocale]);

  return (
    <div ref={boxRef} className={`g7pp-frame g7pp-frame--${device}`} style={{ aspectRatio: `${frame.width} / ${frame.height}` }} data-preview-device={device}>
      <div className="g7pp-frame__screen" style={{ width: frame.width, height: frame.height, transform: `scale(${scale})`, ['--g7pp-frame-h' as string]: `${frame.height}px` }}>
        <div className="g7pp-frame__page" aria-hidden="true">
          <span />
          <span />
          <span />
        </div>
        <PopupLayer popups={popups} settings={{ ...DEFAULT_SETTINGS, ...(settings ?? {}) }} preview={device} />
      </div>
    </div>
  );
};

export default PopupPreview;
