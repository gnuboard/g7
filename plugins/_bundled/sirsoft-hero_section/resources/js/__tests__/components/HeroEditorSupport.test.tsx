/** @vitest-environment jsdom */
import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent } from '@testing-library/react';
import { localized, periodStatus } from '../../support/editor';
import { HeroBannerPreview } from '../../components/composite/HeroBannerPreview';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: {}, EffectFade: {} }));
vi.mock('swiper/css', () => ({}));
vi.mock('swiper/css/effect-fade', () => ({}));
afterEach(() => cleanup());

describe('[case:hero-preview-iframe-init] [case:hero-preview-dialog-escape] 사이트 시간·콘텐츠 언어·실제 viewport', () => {
  // @scenario case=preview_viewport
  it('미리보기 스타일 복사에서 inline script 속성을 제거하고 주소는 원 문서 기준으로 고정한다', () => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/preview-style.css';
    link.setAttribute('onload', "this.media='all'");
    link.setAttribute('onerror', 'window.__probe=true');
    document.head.appendChild(link);
    try {
      const view = render(<HeroBannerPreview banners={[{ id: 1, title: 'preview', images: { pc: { url: '/pc.png' } } }]} />);
      const frame = view.container.querySelector('iframe')!;
      const copy = frame.contentDocument!.querySelector('link')!;
      expect(copy.getAttribute('onload')).toBeNull();
      expect(copy.getAttribute('onerror')).toBeNull();
      expect(copy.getAttribute('href')).toBe(link.href);
    } finally { link.remove(); }
  });
  // @scenario case=site_clock_metadata
  it('시간대가 다른 브라우저에서도 사이트 시작·종료 정각은 서버 규약대로 판정한다', () => {
    const status = (instant: string) => periodStatus('range', true, '2026-10-27T10:00', '2026-10-27T11:00', 'Asia/Seoul', Date.parse(instant));
    expect(status('2026-10-27T00:59:59Z')).toBe('scheduled');
    expect(status('2026-10-27T01:00:00Z')).toBe('active');
    expect(status('2026-10-27T02:00:00Z')).toBe('active');
    expect(status('2026-10-27T02:00:01Z')).toBe('ended');
    expect(periodStatus('range', true, '', '', 'Asia/Seoul', Date.now())).toBe('incomplete');
    expect(periodStatus('range', false, '', '', 'Asia/Seoul', Date.now())).toBe('inactive');
  });

  // @scenario case=preview_language
  it('선택 콘텐츠 언어·사이트 기본·en·첫 유효값을 서버와 같은 순서로 고른다', () => {
    expect(localized({ ko: '한국어', en: 'English', ja: '日本語' }, 'ja', 'en')).toBe('日本語');
    expect(localized({ ko: '한국어', en: 'English', ja: ' ' }, 'ja', 'en')).toBe('English');
    expect(localized({ ko: '한국어' }, 'ja', 'en')).toBe('한국어');
  });

  // @scenario case=preview_viewport
  it('draft의 선택 언어를 독립 iframe 안에 그리고 비어 있으면 대체 안내를 보인다', () => {
    const draft = { title: { ko: '한국어', ja: '日本語' }, images: { pc: { url: '/pc.png' } } };
    const view = render(<HeroBannerPreview draft={draft} locale="ja" defaultLocale="ko" device="mobile" />);
    let frame = view.container.querySelector('iframe')!;
    fireEvent.load(frame);
    expect(frame.contentDocument!.body.textContent).toContain('日本語');
    expect(frame.getAttribute('sandbox')).toBe('allow-same-origin');
    view.rerender(<HeroBannerPreview draft={draft} locale="en" defaultLocale="ko" device="mobile" />);
    frame = view.container.querySelector('iframe')!;
    expect(frame.contentDocument!.body.textContent).toContain('한국어');
    expect(view.container.querySelector('.hs-preview__fallback')).not.toBeNull();
  });

  // @scenario case=preview_expand
  // @scenario case=preview_language
  it('실제로 바뀐 문구 언어만 알리고 다른 배너 진입은 편집으로 판정하지 않는다', () => {
    const notify = vi.fn();
    const draft = { id: 1, title: { ko: '한국어', en: 'English' }, images: { pc: { url: '/pc.png' } } };
    const view = render(<HeroBannerPreview draft={draft} locale="ko" defaultLocale="ko" onContentLocaleChange={notify} />);
    expect(notify).not.toHaveBeenCalled();
    view.rerender(<HeroBannerPreview draft={{ ...draft, title: { ...draft.title, en: 'Changed' } }} locale="ko" defaultLocale="ko" onContentLocaleChange={notify} />);
    expect(notify).toHaveBeenLastCalledWith('en');
    notify.mockClear();
    view.rerender(<HeroBannerPreview draft={{ ...draft, id: 2 }} locale="ko" defaultLocale="ko" onContentLocaleChange={notify} />);
    expect(notify).not.toHaveBeenCalled();
  });

  // @scenario case=preview_expand
  it('확대 미리보기를 닫으면 원래 단추로 포커스를 돌린다', () => {
    HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
    const { container } = render(<HeroBannerPreview banners={[{ id: 1, images: { pc: { url: '/pc.png' } } }]} />);
    const opener = container.querySelector('button')!;
    opener.focus();
    fireEvent.click(opener);
    expect(container.querySelectorAll('iframe')).toHaveLength(2);
    fireEvent(container.querySelector('dialog')!, new Event('cancel', { bubbles: false, cancelable: true }));
    expect(container.querySelector('dialog')).toBeNull();
    expect(document.activeElement).toBe(opener);
  });
});
