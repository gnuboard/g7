import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { HeroSlider, type HeroBanner, type HeroSliderSettings, type TextSpot } from './HeroSlider';
import { youtubeIdFromUrl } from '../../support/media';
import { textOverflows } from '../../support/text';
import { basic, estimateContrast, frameClass, isLightText, localized, sampleImage, translate, type FrameBox, type PreviewFrame, type Rgb } from '../../support/editor';

export type HeroDraft = Partial<Omit<HeroBanner, 'title' | 'eyebrow' | 'description' | 'button_text' | 'image_alt'>> & {
  title?: Record<string, string>;
  eyebrow?: Record<string, string>;
  description?: Record<string, string>;
  button_text?: Record<string, string>;
  image_alt?: Record<string, string>;
  /** 운영자가 넣은 유튜브 주소 — 미리보기는 여기서 영상 ID 를 꺼낸다 */
  youtube_url?: string | null;
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
  /**
   * 활성 사용자 템플릿의 구좌 틀(위치 설정 응답 `editor.preview_frame`). 주면 기기 viewport 안에 템플릿 본문 상자를 같은
   * 템플릿 CSS 로 그려 실제 사이트와 같은 구좌 폭에서 미리 본다. 없거나 그 위치에 확장점이 없으면 기기 폭 전체(종전 동작).
   */
  frame?: PreviewFrame | null;
  /** 틀에서 고를 위치 키(`home_hero`·`shop_hero`) */
  position?: string;
  /**
   * 편집 화면 전용 — 미리보기의 문구 상자를 끌거나 화살표 키로 옮기면 지금 기기의 새 자리를 표현값 조각으로 알린다
   * (`{ text_pc_x, text_pc_y }` 또는 `{ text_mobile_x, text_mobile_y }`). 주지 않으면 문구는 옮길 수 없다(목록 미리보기).
   */
  onTextPositionChange?: (patch: Record<string, number>) => void;
}

/** 문구 넘침 — 배너 1장일 때도 넘침(`alone`), 넘김 단추 자리를 비우면 넘침(`controls`), 넘치지 않음(false) */
type Overflow = 'alone' | 'controls' | false;

/**
 * 독립 문서에서 실제 기기 viewport로 렌더한 뒤 전체를 축소합니다. 스크립트는 복사하지 않습니다.
 *
 * 기기 viewport(iframe 폭)와 안쪽 구좌 폭을 구분한다 — vw·미디어쿼리·글자 크기는 viewport 기준이어야 실제 사이트와 같고,
 * 슬라이더 폭은 템플릿 본문 상자(여백·최대 폭) 안이어야 줄바꿈이 같다. 그래서 viewport 는 그대로 두고 그 안에 템플릿의
 * 상자 사슬을 템플릿 CSS 로 그린 뒤, 슬라이더 위아래만 보이게 잘라 보인다.
 */
function Viewport({ banners, settings, device, chain, content, stylesheets, onOverflow, onTextMove, replay }: { banners: HeroBanner[]; settings?: HeroSliderSettings; device: 'pc' | 'mobile'; chain?: FrameBox[]; content?: FrameBox | null; stylesheets?: string[]; onOverflow?: (overflow: Overflow) => void; onTextMove?: (spot: TextSpot, device: 'pc' | 'mobile') => void; replay?: number }) {
  const host = useRef<HTMLDivElement>(null);
  const frame = useRef<HTMLIFrameElement>(null);
  const [body, setBody] = useState<HTMLElement | null>(null);
  const documentBody = useRef<HTMLElement | null>(null);
  const viewport = device === 'pc' ? 1366 : 390;
  const height = device === 'pc' ? viewport / 3 : viewport / 0.75;
  const [width, setWidth] = useState(viewport);
  const [slot, setSlot] = useState<HTMLElement | null>(null);
  const [box, setBox] = useState<{ top: number; height: number; width: number } | null>(null);
  const framed = !!chain && chain.length > 0;
  // 확장점이 넘긴 본문 상자 — 사이트처럼 이 기기 폭에 맞는 클래스를 골라 슬라이더에 넘긴다(표시 폭에 따라 바깥 또는 문구 층)
  const contentClass = framed && content ? frameClass(content, viewport) : '';
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
    // 스크립트를 막은(sandbox) 문서라 on* 처리 속성은 실행되지 않고 콘솔 오류만 남는다 — 바깥 문서의 리스너로 단다.
    doc.body.addEventListener('click', (event) => { if ((event.target as Element).closest('a')) event.preventDefault(); });
    setBody(doc.body);
  };
  /** 슬라이더가 그려진 자리(위쪽 위치·높이·폭)를 잰다 — 템플릿 CSS 가 늦게 오면 그때 다시 잰다. */
  const measure = () => {
    const doc = frame.current?.contentDocument;
    const hero = doc?.querySelector<HTMLElement>('.hs');
    if (!doc || !hero) return;
    const rect = hero.getBoundingClientRect();
    if (!rect.height) return;
    const top = rect.top - doc.body.getBoundingClientRect().top;
    setBox((prev) => (prev && prev.top === top && prev.height === rect.height && prev.width === rect.width ? prev : { top, height: rect.height, width: rect.width }));
  };
  // about:blank는 삽입 즉시 load가 끝나 ref 부착 전에 이벤트가 지나갈 수 있다.
  useLayoutEffect(() => { setup(); }, []);
  // 템플릿 CSS 는 틀(위치 설정 응답)이 오는 때에 맞춘다 — 응답이 미리보기보다 늦게 와도, 위치를 바꿔 틀이 달라져도 실린다.
  const sheets = framed ? (stylesheets ?? []).join('\n') : '';
  useLayoutEffect(() => {
    const doc = frame.current?.contentDocument;
    if (!doc || !body) return;
    const current = [...doc.head.querySelectorAll<HTMLLinkElement>('link[data-hs-template-css]')];
    if (current.map((l) => l.getAttribute('href')).join('\n') === sheets) return;
    current.forEach((l) => l.remove());
    const reset = doc.head.querySelector('style:last-of-type');
    for (const href of sheets ? sheets.split('\n') : []) {
      const link = doc.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.setAttribute('data-hs-template-css', '');
      link.addEventListener('load', () => measure());
      doc.head.insertBefore(link, reset);
    }
  }, [body, sheets]);
  useEffect(() => {
    if (!framed || !body) return;
    measure();
    const hero = body.querySelector('.hs');
    if (!hero || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(measure);
    observer.observe(hero);
    return () => observer.disconnect();
  }, [framed, body, slot, banners, device]);
  // 이 기기에서 문구가 배너 높이를 넘어 문구 영역(.hs__scroll) 안 스크롤이 생기는지 알린다 — 문구가 바뀌거나 CSS 가 실려 크기가 바뀔 때마다.
  // 편집 미리보기는 배너 1장이라 넘김 단추가 없지만 사이트에 2장 이상이면 그 자리만큼 문구 영역이 준다 — 잠깐 그 자리를 비워 한 번 더 잰다.
  useEffect(() => {
    if (!onOverflow || !body) return;
    const hero = body.querySelector<HTMLElement>('.hs');
    const scroll = hero?.querySelector<HTMLElement>('.hs__scroll');
    if (!hero || !scroll) return onOverflow(false);
    const overflows = () => textOverflows(scroll);
    if (overflows()) return onOverflow('alone');
    if (hero.classList.contains('hs--controlled')) return onOverflow(false);
    hero.classList.add('hs--controlled');
    const withControls = overflows();
    hero.classList.remove('hs--controlled');
    onOverflow(withControls ? 'controls' : false);
  }, [onOverflow, body, slot, banners, device, box, sheets]);
  // 틀의 높이를 기기 전체(min-h-screen 등)가 아니라 슬라이더 자리만큼으로 잡고, 그 위쪽은 위로 밀어 보이지 않게 한다.
  const frameHeight = framed && box ? Math.ceil(box.height) : Math.ceil(height);
  const nest = (index: number): React.ReactNode => index >= (chain?.length ?? 0)
    ? <Div ref={setSlot} data-hs-slot="" />
    : <Div className={frameClass(chain![index], viewport)} data-hs-frame="">{nest(index + 1)}</Div>;
  return <Div ref={host} className="hs-preview__viewport" style={{ height: frameHeight * scale }} data-slot-width={framed && box ? Math.round(box.width * 1000) / 1000 : viewport}>
    {React.createElement('iframe', {
      ref: frame, title: translate('admin.form.preview.frame', { device: device.toUpperCase() }),
      sandbox: 'allow-same-origin', src: 'about:blank', onLoad: setup,
      width: viewport, height: frameHeight,
      style: { display: 'block', border: 0, width: viewport, height: frameHeight, maxWidth: 'none', transform: `scale(${scale})`, transformOrigin: 'top left' },
      'data-preview-width': viewport,
    })}
    {body && framed && createPortal(<Div style={{ transform: box ? `translateY(${-box.top}px)` : undefined }}>{nest(0)}</Div>, body)}
    {body && framed && slot && createPortal(<HeroSlider banners={banners} settings={settings} forceDevice={device} contentClass={contentClass} onTextMove={onTextMove} replay={replay} />, slot)}
    {body && !framed && createPortal(<HeroSlider banners={banners} settings={settings} forceDevice={device} onTextMove={onTextMove} replay={replay} />, body)}
  </Div>;
}

/** 실제 PC 1366px·모바일 390px 미리보기. 확대 창은 Escape로 닫히고 포커스를 돌려줍니다. */
export const HeroBannerPreview: React.FC<HeroBannerPreviewProps> = ({ banners = [], draft, locale = '', defaultLocale = '', onContentLocaleChange, settings, device = 'pc', emptyText = '', className = '', frame = null, position = '', onTextPositionChange }) => {
  const slotFrame = position ? frame?.positions?.[position] ?? null : null;
  const chain = slotFrame?.chain;
  const contentBox = slotFrame?.content ?? null;
  const stylesheets = frame?.stylesheets ?? [];
  const [expanded, setExpanded] = useState(false);
  const dialog = useRef<HTMLDialogElement>(null);
  const opener = useRef<HTMLButtonElement>(null);
  const content = JSON.stringify([draft?.title, draft?.description, draft?.button_text, draft?.image_alt, draft?.eyebrow]);
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
    eyebrow: localized(draft.eyebrow, locale, defaultLocale),
    description: localized(draft.description, locale, defaultLocale),
    button_text: localized(draft.button_text, locale, defaultLocale),
    image_alt: localized(draft.image_alt, locale, defaultLocale) || localized(draft.title, locale, defaultLocale),
    link_url: draft.link_url ? '#preview' : null, link_new_window: false,
    // 미디어는 고른 종류의 것만 — 종류를 바꿔도 화면에 남은 영상·주소가 미리보기에 섞이지 않게
    youtube_id: draft.media_type === 'youtube' ? youtubeIdFromUrl(draft.youtube_url) : null,
    videos: draft.media_type === 'video' ? draft.videos ?? null : null,
  } as HeroBanner] : []) : banners;
  const fallback = draft && ['title', 'eyebrow', 'description', 'button_text', 'image_alt'].some((key) => {
    const value = draft[key as 'title'];
    return !value?.[locale]?.trim() && !!localized(value, locale, defaultLocale);
  });
  // 안내 — 문구 넘침(지금 기기 실측)과 글자 대비(사진 평균색 + 문구 배경으로 어림). 문구·글자색을 바꾸거나 저장을 막지 않는다.
  const [overflow, setOverflow] = useState<Overflow>(false);
  const imageUrl = draft ? (device === 'mobile' ? draft.images?.mobile?.url ?? draft.images?.pc?.url : draft.images?.pc?.url) : undefined;
  const [imageColor, setImageColor] = useState<Rgb | null>(null);
  useEffect(() => {
    let live = true;
    setImageColor(null);
    if (imageUrl) sampleImage(imageUrl).then((rgb) => { if (live) setImageColor(rgb); });
    return () => { live = false; };
  }, [imageUrl]);
  const lowContrast = draft && imageColor ? ([
    ['title', 3, draft.title_visible !== false && items[0]?.title, draft.title_color ?? '#FFFFFF'],
    ['description', 4.5, draft.description_visible !== false && items[0]?.description, draft.description_color ?? '#E5E7EB'],
  ] as const).flatMap(([part, minimum, shown, color]) => {
    const ratio = shown ? estimateContrast(color, imageColor, draft.presentation?.overlay) : null;
    return ratio !== null && ratio < minimum ? [{ part, ratio: Math.round(ratio * 10) / 10, color }] : [];
  }) : [];
  useEffect(() => { if (expanded) dialog.current?.showModal(); }, [expanded]);
  // 문구 끌기 — 편집 화면만. 놓은 기기의 키로 표현값 조각을 알린다
  const onTextMove = draft && onTextPositionChange
    ? (spot: TextSpot, moved: 'pc' | 'mobile') => onTextPositionChange({ [`text_${moved}_x`]: spot.x, [`text_${moved}_y`]: spot.y })
    : undefined;
  // 효과 다시 보기 — 값이 바뀌면 슬라이더가 문구 등장 효과를 처음부터 다시 재생한다
  const [replay, setReplay] = useState(0);
  const animated = !!draft && !!draft.presentation?.animation && draft.presentation.animation !== 'none';
  const close = () => { if (dialog.current?.open) dialog.current.close(); setExpanded(false); opener.current?.focus(); };
  const Div = basic('Div', 'div');
  const P = basic('P', 'p');
  const Button = basic('Button', 'button');
  return <Div className={`hs-preview hs-preview--${device} ${className}`.trim()} data-device={device}>
    {items.length === 0 ? <P className="hs-preview__empty">{emptyText}</P> : <>
      <Viewport banners={items} settings={settings} device={device} chain={chain} content={contentBox} stylesheets={stylesheets} onOverflow={draft ? setOverflow : undefined} onTextMove={onTextMove} replay={replay} />
      {onTextMove && <P className="form-hint hs-preview__hint" data-hint="text-drag">{translate('admin.form.text_layout.drag_hint')}</P>}
      {animated && <Button type="button" className="hs-preview__expand" data-hs-action="replay" onClick={() => setReplay((value) => value + 1)}>{translate('admin.form.text_layout.replay')}</Button>}
      {draft && overflow && <P className="form-hint hs-preview__hint" data-hint="text-overflow" role="status">{translate(overflow === 'alone' ? 'admin.form.preview.text_overflow' : 'admin.form.preview.text_overflow_controls', { device: device.toUpperCase() })}</P>}
      {lowContrast.length > 0 && <P className="form-hint hs-preview__hint" data-hint="contrast" role="status">
        {translate('admin.form.preview.contrast_low', { parts: lowContrast.map((c) => translate(`admin.form.preview.contrast_${c.part}`, { ratio: String(c.ratio) })).join(' · ') })}
        {' '}
        {isLightText(lowContrast[0].color)
          ? translate('admin.form.preview.contrast_tip_light', { option: translate('admin.form.presentation.dark') })
          : translate('admin.form.preview.contrast_tip_dark', { option: translate('admin.form.presentation.light') })}
      </P>}
      <Button ref={opener} type="button" className="hs-preview__expand" onClick={() => setExpanded(true)}>{translate('admin.form.preview.expand')}</Button>
      {fallback && <P className="form-hint hs-preview__fallback">{translate('admin.form.preview.fallback', { locale: defaultLocale.toUpperCase() })}</P>}
      {expanded && React.createElement('dialog', {
        ref: dialog, className: 'hs-preview__dialog', 'aria-label': translate('admin.form.preview.expand'),
        onCancel: (event: Event) => { event.preventDefault(); close(); }, onClose: close,
        onKeyDownCapture: (event: React.KeyboardEvent) => { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); } },
      }, <><Button type="button" className="hs-preview__expand" onClick={close}>{translate('admin.form.preview.close')}</Button><Viewport banners={items} settings={settings} device={device} chain={chain} content={contentBox} stylesheets={stylesheets} onTextMove={onTextMove} replay={replay} /></>)}
    </>}
  </Div>;
};

export default HeroBannerPreview;
