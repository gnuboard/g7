import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { HeroSlider, type HeroBanner, type HeroSliderSettings } from './HeroSlider';
import { basic, localized, translate } from '../../support/editor';

export type HeroDraft = Partial<Omit<HeroBanner, 'title' | 'description' | 'button_text' | 'image_alt'>> & {
  title?: Record<string, string>;
  description?: Record<string, string>;
  button_text?: Record<string, string>;
  image_alt?: Record<string, string>;
};

export interface HeroBannerPreviewProps {
  /** 목록 미리보기의 공개 모양 배너 */
  banners?: HeroBanner[];
  /** 편집 화면의 저장 전 원본 문구·이미지 */
  draft?: HeroDraft;
  /** 콘텐츠 언어·사이트 기본 언어 */
  locale?: string;
  defaultLocale?: string;
  /** 실제로 바뀐 문구 언어를 편집 화면에 알립니다. */
  onContentLocaleChange?: (locale: string) => void;
  /** 위치 설정 */
  settings?: HeroSliderSettings;
  /** 실제 미리보기 viewport */
  device?: 'pc' | 'mobile';
  /** 빈 목록 문구 */
  emptyText?: string;
  /** 사용자 클래스 */
  className?: string;
}

/** 독립 문서에서 실제 기기 viewport로 렌더한 뒤 전체를 축소합니다. 스크립트는 복사하지 않습니다. */
function Viewport({ banners, settings, device }: { banners: HeroBanner[]; settings?: HeroSliderSettings; device: 'pc' | 'mobile' }) {
  const host = useRef<HTMLDivElement>(null);
  const frame = useRef<HTMLIFrameElement>(null);
  const [body, setBody] = useState<HTMLElement | null>(null);
  const documentBody = useRef<HTMLElement | null>(null);
  const viewport = device === 'pc' ? 1366 : 390;
  const height = device === 'pc' ? viewport / 3 : viewport / 0.75;
  const [width, setWidth] = useState(viewport);
  useEffect(() => {
    const element = host.current;
    if (!element) return;
    const resize = () => setWidth(element.getBoundingClientRect().width || viewport);
    resize();
    if (typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(resize);
    observer.observe(element);
    return () => observer.disconnect();
  }, [viewport]);
  const scale = Math.min(1, width / viewport);
  const Div = basic('Div', 'div');
  const setup = () => {
    const doc = frame.current?.contentDocument;
    if (!doc || documentBody.current === doc.body) return;
    documentBody.current = doc.body;
    doc.head.replaceChildren();
    for (const style of document.querySelectorAll('link[rel="stylesheet"], style')) {
      const copy = style.cloneNode(true) as HTMLElement;
      for (const attribute of [...copy.attributes]) {
        if (/^on/i.test(attribute.name)) copy.removeAttribute(attribute.name);
      }
      if (style instanceof HTMLLinkElement) {
        copy.setAttribute('href', style.href);
        if (style.media === 'print' && style.hasAttribute('onload')) copy.setAttribute('media', 'all');
      }
      doc.head.appendChild(copy);
    }
    const reset = doc.createElement('style');
    reset.textContent = 'html{font-size:16px}html,body{margin:0;padding:0;overflow:hidden}';
    doc.head.appendChild(reset);
    doc.documentElement.lang = document.documentElement.lang;
    doc.documentElement.className = document.documentElement.className;
    doc.body.onclick = (event) => { if ((event.target as Element).closest('a')) event.preventDefault(); };
    setBody(doc.body);
  };
  // about:blank는 삽입 즉시 load가 끝나 ref 부착 전에 이벤트가 지나갈 수 있다.
  useLayoutEffect(() => { setup(); }, []);
  return <Div ref={host} className="hs-preview__viewport" style={{ height: height * scale }}>
    {React.createElement('iframe', {
      ref: frame, title: translate('admin.form.preview.frame', { device: device.toUpperCase() }),
      sandbox: 'allow-same-origin', src: 'about:blank', onLoad: setup,
      width: viewport, height: Math.ceil(height),
      style: { display: 'block', border: 0, width: viewport, height: Math.ceil(height), maxWidth: 'none', transform: `scale(${scale})`, transformOrigin: 'top left' },
      'data-preview-width': viewport,
    })}
    {body && createPortal(<HeroSlider banners={banners} settings={settings} forceDevice={device} />, body)}
  </Div>;
}

/** 실제 PC 1366px·모바일 390px 미리보기. 확대 창은 Escape로 닫히고 포커스를 돌려줍니다. */
export const HeroBannerPreview: React.FC<HeroBannerPreviewProps> = ({ banners = [], draft, locale = '', defaultLocale = '', onContentLocaleChange, settings, device = 'pc', emptyText = '', className = '' }) => {
  const [expanded, setExpanded] = useState(false);
  const dialog = useRef<HTMLDialogElement>(null);
  const opener = useRef<HTMLButtonElement>(null);
  const content = JSON.stringify([draft?.title, draft?.description, draft?.button_text, draft?.image_alt]);
  const previousContent = useRef<{ id?: number; content: string } | null>(null);
  useEffect(() => {
    const previous = previousContent.current;
    previousContent.current = { id: draft?.id, content };
    if (!draft || !previous || previous.id !== draft.id || previous.content === content) return;
    const before = JSON.parse(previous.content);
    const after = JSON.parse(content);
    const changed = new Set<string>();
    after.forEach((value: Record<string, string> | null, index: number) => {
      for (const key of new Set([...Object.keys(before[index] ?? {}), ...Object.keys(value ?? {})])) {
        if (before[index]?.[key] !== value?.[key]) changed.add(key);
      }
    });
    if (changed.size === 1) onContentLocaleChange?.([...changed][0]);
  }, [content, draft?.id, onContentLocaleChange]);
  const items = draft ? (draft.images?.pc ? [{
    ...draft, id: draft.id ?? 0,
    title: localized(draft.title, locale, defaultLocale),
    description: localized(draft.description, locale, defaultLocale),
    button_text: localized(draft.button_text, locale, defaultLocale),
    image_alt: localized(draft.image_alt, locale, defaultLocale) || localized(draft.title, locale, defaultLocale),
    link_url: draft.link_url ? '#preview' : null, link_new_window: false,
  } as HeroBanner] : []) : banners;
  const fallback = draft && ['title', 'description', 'button_text', 'image_alt'].some((key) => {
    const value = draft[key as 'title'];
    return !value?.[locale]?.trim() && !!localized(value, locale, defaultLocale);
  });
  useEffect(() => { if (expanded) dialog.current?.showModal(); }, [expanded]);
  const close = () => { if (dialog.current?.open) dialog.current.close(); setExpanded(false); opener.current?.focus(); };
  const Div = basic('Div', 'div');
  const P = basic('P', 'p');
  const Button = basic('Button', 'button');
  return <Div className={`hs-preview hs-preview--${device} ${className}`.trim()} data-device={device}>
    {items.length === 0 ? <P className="hs-preview__empty">{emptyText}</P> : <>
      <Viewport banners={items} settings={settings} device={device} />
      <Button ref={opener} type="button" className="hs-preview__expand" onClick={() => setExpanded(true)}>{translate('admin.form.preview.expand')}</Button>
      {fallback && <P className="form-hint hs-preview__fallback">{translate('admin.form.preview.fallback', { locale: defaultLocale.toUpperCase() })}</P>}
      {expanded && React.createElement('dialog', {
        ref: dialog, className: 'hs-preview__dialog', 'aria-label': translate('admin.form.preview.expand'),
        onCancel: (event: Event) => { event.preventDefault(); close(); }, onClose: close,
        onKeyDownCapture: (event: React.KeyboardEvent) => { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); } },
      }, <><Button type="button" className="hs-preview__expand" onClick={close}>{translate('admin.form.preview.close')}</Button><Viewport banners={items} settings={settings} device={device} /></>)}
    </>}
  </Div>;
};

export default HeroBannerPreview;
