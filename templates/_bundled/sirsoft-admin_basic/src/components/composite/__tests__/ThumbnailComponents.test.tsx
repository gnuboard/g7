/**
 * 이미지 썸네일 컴포넌트 적용 — sirsoft-admin_basic
 *
 * 2000px 원본이 24px 아바타·64px 썸네일 칸·300px 상품 카드에 그대로 내려가던 것을 막는다. 판정은 코어
 * `G7Core.thumbnail`(같은 출처 + 등록된 서빙 경로) 한 곳이고, 이 시험은 실제 코어 구현(`@core/support/thumbnailUrl`)을
 * G7Core 에 꽂아 컴포넌트가 그 판정을 거치는지 본다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, onTestFinished, vi } from 'vitest';
import { render, cleanup, fireEvent, act, waitFor } from '@testing-library/react';
// @ts-ignore - DOMPurify 타입 정의 없음
import DOMPurify from 'dompurify';
import {
  contentSrcset,
  contentSrcsetWithin,
  getContentPreset,
  getThumbnailPresets,
  isThumbnailableUrl,
  stripThumbnailSize,
  thumbnailSrcset,
  withThumbnailSize,
} from '@core/support/thumbnailUrl';

import { Img } from '../../basic/Img';
import { ProductCard } from '../ProductCard';
import { ImageGallery } from '../ImageGallery';
import { HtmlContent } from '../HtmlContent';
import { SortableThumbnailItem } from '../FileUploader/SortableThumbnailItem';
import { FileUploader } from '../FileUploader';

const PRESETS = [
  { name: 'xs', width: 64, height: 64, fit: 'cover', numeric: false },
  { name: 'sm', width: 160, height: 160, fit: 'cover', numeric: false },
  { name: 'md', width: 640, height: null, fit: 'width', numeric: true },
  { name: 'lg', width: 1280, height: null, fit: 'width', numeric: true },
  { name: 'xl', width: 1920, height: null, fit: 'width', numeric: true },
];

// 일부 컴포넌트(SortableThumbnailItem)는 모듈을 읽는 순간 window.G7Core 를 붙잡는다 — import 보다 먼저 같은 객체를 만든다
const { apiGet } = vi.hoisted(() => {
  const get = vi.fn(() => Promise.resolve(new Blob(['x'], { type: 'image/png' })));
  (window as any).G7Core = { api: { get } };
  return { apiGet: get };
});

function installCore(): void {
  (window as any).G7Config = {
    settings: {
      upload: {
        thumbnail_presets_public: PRESETS,
        thumbnail_url_patterns: ['/api/attachment/*', '/api/modules/sirsoft-board/boards/*/attachment/*/preview'],
        thumbnail_content_preset: 'lg',
      },
    },
  };
  Object.assign((window as any).G7Core, {
    t: (key: string, params?: Record<string, string>) => (params?.name ? `${key}:${params.name}` : key),
    dispatch: () => {},
    api: { get: apiGet },
    createLogger: () => ({ log: () => {}, warn: () => {}, error: () => {} }),
    thumbnail: {
      isThumbnailable: isThumbnailableUrl,
      url: withThumbnailSize,
      srcset: thumbnailSrcset,
      contentSrcset,
      contentSrcsetWithin,
      contentPreset: getContentPreset,
      strip: stripThumbnailSize,
      presets: getThumbnailPresets,
    },
  });
}

beforeEach(() => {
  installCore();
  apiGet.mockClear();
  (URL as any).createObjectURL = vi.fn(() => 'blob:mock');
  (URL as any).revokeObjectURL = vi.fn();
});

afterEach(() => {
  cleanup();
  delete (window as any).G7Config;
});

describe('Img thumbPreset', () => {
  // @scenario component=img
  // @effects img_thumb_preset_builds_src_and_srcset, img_thumb_preset_prop_not_leaked_to_dom
  it('서빙 경로에 규격·srcset 을 붙이고 thumbPreset 은 DOM 으로 새지 않는다', () => {
    const { container } = render(<Img src="/api/attachment/abc" thumbPreset="md" alt="a" />);
    const img = container.querySelector('img')!;
    expect(img.getAttribute('src')).toBe('/api/attachment/abc?size=md');
    expect(img.getAttribute('srcset')).toBe('/api/attachment/abc?size=md 1x, /api/attachment/abc?size=lg 2x');
    expect(img.hasAttribute('thumbpreset')).toBe(false);
    expect(img.hasAttribute('thumbPreset')).toBe(false);
    expect(container.innerHTML).not.toMatch(/thumb-?preset/i);
  });

  // @scenario component=img
  // @effects img_lazy_async_defaults_explicit_values_kept
  it('loading 기본 lazy·decoding 기본 async, 명시값은 그대로', () => {
    const { container, rerender } = render(<Img src="/api/attachment/abc" />);
    expect(container.querySelector('img')!.getAttribute('loading')).toBe('lazy');
    expect(container.querySelector('img')!.getAttribute('decoding')).toBe('async');
    rerender(<Img src="/api/attachment/abc" loading="eager" decoding="sync" />);
    expect(container.querySelector('img')!.getAttribute('loading')).toBe('eager');
    expect(container.querySelector('img')!.getAttribute('decoding')).toBe('sync');
  });

  // @scenario component=img
  // @effects img_non_target_untouched_explicit_srcset_wins
  it('외부·정적 주소는 바꾸지 않고, 명시한 srcSet 이 우선한다', () => {
    const { container, rerender } = render(<Img src="https://cdn.example.com/a.png" thumbPreset="sm" />);
    expect(container.querySelector('img')!.getAttribute('src')).toBe('https://cdn.example.com/a.png');
    expect(container.querySelector('img')!.hasAttribute('srcset')).toBe(false);
    rerender(<Img src="/api/attachment/abc" thumbPreset="sm" srcSet="/x 1x" />);
    expect(container.querySelector('img')!.getAttribute('srcset')).toBe('/x 1x');
  });

  // @scenario component=img
  // @effects img_without_core_api_keeps_original_url
  it('코어 API 가 없으면 원 주소 그대로 (구버전 코어 호환)', () => {
    const saved = (window as any).G7Core.thumbnail;
    delete (window as any).G7Core.thumbnail;
    const { container } = render(<Img src="/api/attachment/abc" thumbPreset="sm" />);
    expect(container.querySelector('img')!.getAttribute('src')).toBe('/api/attachment/abc');
    (window as any).G7Core.thumbnail = saved;
  });

  // @scenario component=img
  // @effects img_subfolder_prefix_on_src_and_srcset
  it('하위 폴더 설치 — 접두가 src·srcset 모두에 붙는다', () => {
    (window as any).G7Config.basePath = '/g7';
    const { container } = render(<Img src="/api/attachment/abc" thumbPreset="xs" />);
    const img = container.querySelector('img')!;
    expect(img.getAttribute('src')).toBe('/g7/api/attachment/abc?size=xs');
    expect(img.getAttribute('srcset')).toBe('/g7/api/attachment/abc?size=xs 1x, /g7/api/attachment/abc?size=sm 2x');
  });
});

describe('ProductCard', () => {
  // @scenario component=product_card
  // @effects product_card_requests_card_preset
  it('관리자 카드 이미지(imageUrl, 가로 꽉 참 × 높이 256px)는 md', () => {
    const { container } = render(<ProductCard title="상품" price={1000} imageUrl="/api/attachment/p1" />);
    const img = container.querySelector('img')!;
    expect(img.getAttribute('src')).toBe('/api/attachment/p1?size=md');
    expect(img.getAttribute('loading')).toBe('lazy');
  });
});

describe('ImageGallery slide.thumbnail', () => {
  // @scenario component=image_gallery
  // @effects gallery_strip_sm_main_original
  it('라이트박스 썸네일 줄(120×80 칸)은 sm, 메인 슬라이드는 원본', async () => {
    render(
      <ImageGallery
        images={[{ src: '/api/attachment/g1', title: 'A' }, { src: '/api/attachment/g2', title: 'B', thumbnail: '/custom-thumb.png' }]}
        isOpen
        onClose={() => {}}
      />,
    );
    await waitFor(() => expect(document.querySelectorAll('.yarl__thumbnails_thumbnail img').length).toBeGreaterThan(0));
    const thumbs = Array.from(document.querySelectorAll('.yarl__thumbnails_thumbnail img')).map((img) => img.getAttribute('src'));
    expect(thumbs).toContain('/api/attachment/g1?size=sm');
    expect(thumbs).toContain('/custom-thumb.png');
    const slides = Array.from(document.querySelectorAll('.yarl__slide img')).map((img) => img.getAttribute('src'));
    expect(slides).toContain('/api/attachment/g1');
    expect(slides.some((src) => src?.includes('size='))).toBe(false);
  });
});

describe('HtmlContent 본문 이미지', () => {
  const html =
    '<p>본문</p><img src="/api/attachment/c1" alt="첫 그림" width="1500"><a href="/x"><img src="/api/attachment/c2" alt="링크"></a>'
    + '<img src="https://cdn.example.com/e.png" alt="외부"><img src="/api/attachment/c3" loading="eager">';

  // @scenario component=html_content
  // @effects html_content_images_get_content_preset_srcset_sizes_lazy
  it('서빙 경로 이미지만 본문 규격·srcset·sizes·lazy, 외부는 무변경, 작성자 loading 존중', () => {
    const { container } = render(<HtmlContent content={html} />);
    const [c1, c2, ext, c3] = Array.from(container.querySelectorAll('img'));
    expect(c1.getAttribute('src')).toBe('/api/attachment/c1?size=lg');
    // 원본 가로(width=1500)보다 큰 후보(1920w)는 뺀다 — 서버가 원본을 보내면 브라우저가 1920 으로 알고 작게 그린다
    expect(c1.getAttribute('srcset')).toBe('/api/attachment/c1?size=md 640w, /api/attachment/c1?size=lg 1280w');
    expect(c1.getAttribute('sizes')).toBe('(min-width: 1024px) 768px, 100vw');
    expect(c1.getAttribute('loading')).toBe('lazy');
    expect(c1.getAttribute('decoding')).toBe('async');
    expect(c2.getAttribute('src')).toBe('/api/attachment/c2?size=lg');
    expect(ext.getAttribute('src')).toBe('https://cdn.example.com/e.png');
    expect(ext.hasAttribute('srcset')).toBe(false);
    expect(ext.hasAttribute('loading')).toBe(false);
    expect(c3.getAttribute('loading')).toBe('eager');
  });

  // @scenario component=html_content
  // @effects html_content_image_sizes_prop_overrides_sizes
  it('imageSizes prop 이 sizes 를 바꾼다', () => {
    const { container } = render(<HtmlContent content={html} imageSizes="100vw" />);
    expect(container.querySelector('img')!.getAttribute('sizes')).toBe('100vw');
  });

  // @scenario component=html_content
  // @effects html_content_stored_html_unchanged
  it('저장 HTML(입력 문자열)은 바뀌지 않는다', () => {
    const original = html;
    render(<HtmlContent content={original} />);
    expect(original).toBe(html);
    expect(original).not.toContain('size=');
  });

  // @scenario component=html_content
  // @effects html_content_zoom_only_for_unlinked_images
  it('링크 밖 이미지만 확대 버튼 — role·tabindex·다국어 이름', () => {
    const { container } = render(<HtmlContent content={html} />);
    const [c1, c2, ext] = Array.from(container.querySelectorAll('img'));
    expect(c1.getAttribute('role')).toBe('button');
    expect(c1.getAttribute('tabindex')).toBe('0');
    expect(c1.getAttribute('aria-label')).toBe('common.zoom_image_named:첫 그림');
    expect(c2.hasAttribute('role')).toBe(false);
    expect(ext.getAttribute('role')).toBe('button');
    // 확대 커서는 CSS 규칙([data-g7-zoom])이 준다 — 표식 처리가 style 속성을 만들거나 작성자 서식을 바꾸지 않는다
    // (직렬화한 HTML 의 style 속성은 엄격한 스타일 정책 style-src-attr 'none' 에서 버려진다, 공개 #163)
    expect(c1.hasAttribute('style')).toBe(false);
    expect(ext.hasAttribute('style')).toBe(false);
    const authored = render(<HtmlContent content={'<img src="/api/attachment/s1" style="width: 50%">'} />);
    const styled = authored.container.querySelector('img')!;
    expect(styled.getAttribute('role')).toBe('button');
    expect(styled.getAttribute('style') ?? '').not.toContain('cursor');
    expect(styled.style.width).toBe('50%');
  });

  // @scenario component=html_content
  // @effects html_content_zoom_opens_original_at_clicked_image
  it('누르면 원본 주소로 확대 보기가 열리고 시작은 누른 이미지다', async () => {
    const { container } = render(<HtmlContent content={html} />);
    const imgs = container.querySelectorAll('img');
    await act(async () => {
      fireEvent.click(imgs[2]);
    });
    await waitFor(() => expect(document.querySelector('.yarl__root')).not.toBeNull());
    const current = document.querySelector('.yarl__slide_current img') as HTMLImageElement | null;
    expect(current?.getAttribute('src')).toBe('https://cdn.example.com/e.png');
    const slides = Array.from(document.querySelectorAll('.yarl__slide img')).map((img) => img.getAttribute('src'));
    expect(slides.some((src) => src?.includes('size='))).toBe(false);
  });

  // @scenario component=html_content
  // @effects html_content_zoom_keyboard_open_links_excluded
  it('Enter·Space 로도 열린다, 링크 안 이미지는 열지 않는다', async () => {
    const { container } = render(<HtmlContent content={html} />);
    const imgs = container.querySelectorAll('img');
    await act(async () => {
      fireEvent.click(imgs[1]);
    });
    expect(document.querySelector('.yarl__root')).toBeNull();
    await act(async () => {
      fireEvent.keyDown(imgs[0], { key: 'Enter' });
    });
    await waitFor(() => expect(document.querySelector('.yarl__root')).not.toBeNull());
    expect((document.querySelector('.yarl__slide_current img') as HTMLImageElement).getAttribute('src')).toBe('/api/attachment/c1');
  });

  // @scenario component=html_content
  // @effects html_content_srcset_limited_to_original_width
  it('srcset 은 img width(정수) 이하 후보만, width 를 모르면 srcset 없이 src 만 본문 규격', () => {
    const { container } = render(
      <HtmlContent
        content={
          '<img src="/api/attachment/w1" width="2000">'
          + '<img src="/api/attachment/w2" width="1280">'
          + '<img src="/api/attachment/w3" width="500">'
          + '<img src="/api/attachment/w4">'
          + '<img src="/api/attachment/w5" width="50%" srcset="/api/attachment/w5 1x">'
          + '<img src="/api/attachment/w6" width=" 700 ">'
        }
      />,
    );
    const [w1, w2, w3, w4, w5, w6] = Array.from(container.querySelectorAll('img'));
    expect(w1.getAttribute('srcset')).toBe(
      '/api/attachment/w1?size=md 640w, /api/attachment/w1?size=lg 1280w, /api/attachment/w1?size=xl 1920w',
    );
    expect(w2.getAttribute('srcset')).toBe('/api/attachment/w2?size=md 640w, /api/attachment/w2?size=lg 1280w');
    // 남는 후보가 없으면 srcset·sizes 를 붙이지 않는다
    for (const img of [w3, w4]) {
      expect(img.hasAttribute('srcset')).toBe(false);
      expect(img.hasAttribute('sizes')).toBe(false);
    }
    // width 를 모르면(정수가 아님) 작성자 srcset 은 건드리지 않고 src 만 본문 규격이다
    expect(w5.getAttribute('srcset')).toBe('/api/attachment/w5 1x');
    expect(w6.getAttribute('srcset')).toBe('/api/attachment/w6?size=md 640w');
    for (const img of [w1, w2, w3, w4, w5, w6]) {
      expect(img.getAttribute('src')).toMatch(/\?size=lg$/);
    }
  });

  // @scenario component=html_content
  // @effects html_content_srcset_built_by_core_content_srcset_within
  it('본문 srcset 의 원본 가로 상한은 코어 contentSrcsetWithin 이 정한다 — 원본을 덧붙이지 않는 규칙(이하)으로 부른다', () => {
    const within = vi.fn(contentSrcsetWithin);
    (window as any).G7Core.thumbnail.contentSrcsetWithin = within;
    const { container } = render(<HtmlContent content={'<img src="/api/attachment/w2" width="1280"><img src="/api/attachment/w4">'} />);
    const [w2, w4] = Array.from(container.querySelectorAll('img'));

    expect(within).toHaveBeenCalledTimes(1);
    expect(within).toHaveBeenCalledWith('/api/attachment/w2', 1280);
    expect(w2.getAttribute('srcset')).toBe('/api/attachment/w2?size=md 640w, /api/attachment/w2?size=lg 1280w');
    expect(w4.hasAttribute('srcset')).toBe(false);
  });

  // @scenario component=html_content
  // @effects html_content_without_within_api_has_no_srcset
  it('코어에 contentSrcsetWithin 이 없으면 srcset·sizes 없이 src 만 본문 규격이다 — 원본보다 큰 후보를 내지 않는다', () => {
    delete (window as any).G7Core.thumbnail.contentSrcsetWithin;
    const { container } = render(<HtmlContent content={'<img src="/api/attachment/w1" width="2000">'} />);
    const img = container.querySelector('img') as HTMLImageElement;

    expect(img.getAttribute('src')).toBe('/api/attachment/w1?size=lg');
    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
  });

  // @scenario component=html_content
  // @effects html_content_decorates_inert_fragment_once
  it('정화 결과를 비활성 문서의 DOM 조각으로 받아 고친다 — 원본 주소로 이미지 요청이 먼저 나가지 않는다', () => {
    const sanitize = vi.spyOn(DOMPurify, 'sanitize');
    const importNode = vi.spyOn(document, 'importNode');
    const adoptNode = vi.spyOn(document, 'adoptNode');
    try {
      // 작성자 설정이 shadowroot 속성을 열어도 DOMPurify 가 조각을 현재 문서로 가져오지(importNode) 않게 한다
      render(<HtmlContent content={html} purifyConfig={{ ADD_ATTR: ['shadowrootmode'] }} />);
      expect(sanitize).toHaveBeenCalledTimes(1);
      const result = sanitize.mock.results[0].value as DocumentFragment;
      expect(result.nodeType).toBe(11);
      // 브라우징 맥락이 없는 문서(DOMParser)의 img 는 src 를 받아도 요청하지 않는다
      expect(result.ownerDocument).not.toBe(document);
      expect(result.ownerDocument.defaultView).toBeNull();
      expect(importNode).not.toHaveBeenCalled();
      expect(adoptNode).not.toHaveBeenCalled();
    } finally {
      sanitize.mockRestore();
      importNode.mockRestore();
      adoptNode.mockRestore();
    }
  });

  // @scenario component=html_content
  // @effects html_content_author_zoom_marker_stripped
  it('작성자가 적은 확대 표식(data-g7-zoom)은 지운다 — 링크 안 이미지·편집 캔버스에서 확대 대상이 되지 않는다', () => {
    const authored = '<a href="/x"><img src="/api/attachment/z1" data-g7-zoom=""></a><p data-g7-zoom="">글</p>';
    const { container } = render(<HtmlContent content={authored} />);
    expect(container.querySelector('[data-g7-zoom]')).toBeNull();
    cleanup();
    const canvas = render(
      <HtmlContent content={'<img src="/api/attachment/z2" data-g7-zoom="">'} editorAttrs={{ 'data-editor-id': 'x' } as never} />,
    );
    expect(canvas.container.querySelector('[data-g7-zoom]')).toBeNull();
  });

  // @scenario component=html_content
  // @effects html_content_zoom_ignores_key_repeat
  it('Enter 를 누르고 있어 생기는 자동 반복(event.repeat)은 확대 보기를 열지 않는다', async () => {
    const { container } = render(<HtmlContent content={html} />);
    const first = container.querySelector('img')!;
    await act(async () => {
      fireEvent.keyDown(first, { key: 'Enter', repeat: true });
    });
    expect(document.querySelector('.yarl__root')).toBeNull();
  });

  // @scenario component=html_content
  // @effects html_content_body_dom_kept_across_rerender
  it('확대 보기를 열고 닫는 재렌더에도 본문 DOM 을 다시 만들지 않는다 (React 19 는 innerHTML 객체가 새것이면 다시 쓴다)', async () => {
    const { container } = render(<HtmlContent content={html} />);
    const first = container.querySelector('img') as HTMLImageElement;
    await act(async () => {
      fireEvent.click(first);
    });
    await waitFor(() => expect(document.querySelector('.yarl__root')).not.toBeNull());
    expect(container.querySelector('img')).toBe(first);
  });

  // @scenario component=html_content
  // @effects html_content_zoom_close_returns_focus
  it('확대 보기를 닫으면 누른 이미지로 초점이 돌아간다', async () => {
    // 브라우저는 초점 요소의 조상에 inert 가 걸리면 초점을 body 로 뺀다(jsdom 은 하지 않는다) — 라이트박스가 열릴 때
    // 바깥 트리에 inert 를 거는 그 동작을 흉내 내야, 라이트박스 자체의 「직전 초점 요소」 기억이 비는 실제 상황이 된다.
    const originalSetAttribute = Element.prototype.setAttribute;
    const inertSpy = vi.spyOn(Element.prototype, 'setAttribute').mockImplementation(function (this: Element, name: string, value: string) {
      originalSetAttribute.call(this, name, value);
      if (name === 'inert' && document.activeElement instanceof HTMLElement && this.contains(document.activeElement)) {
        document.activeElement.blur();
      }
    });
    onTestFinished(() => inertSpy.mockRestore());
    const { container } = render(<HtmlContent content={html} />);
    const first = container.querySelector('img') as HTMLImageElement;
    first.focus();
    await act(async () => {
      fireEvent.keyDown(first, { key: 'Enter' });
    });
    await waitFor(() => expect(document.querySelector('.yarl__root')).not.toBeNull());
    // 확대 창 단추 이름은 다국어 라벨(common.lightbox.*)이다 — 이 시험의 t 는 키를 그대로 돌려준다
    const close = document.querySelector('.yarl__toolbar button[aria-label="common.lightbox.close"]') as HTMLButtonElement;
    expect(close).not.toBeNull();
    close.focus();
    await act(async () => {
      fireEvent.click(close);
    });
    await waitFor(() => expect(document.querySelector('.yarl__root')).toBeNull(), { timeout: 2000 });
    expect(document.activeElement).toBe(first);
  });

  // @scenario component=html_content
  // @effects html_content_editor_canvas_has_no_zoom_marker
  it('편집 캔버스(editorAttrs)는 확대 표식을 붙이지 않는다', () => {
    const { container } = render(<HtmlContent content={html} editorAttrs={{ 'data-editor-id': 'x' } as never} />);
    expect(container.querySelector('img')!.hasAttribute('role')).toBe(false);
  });

  // @scenario component=html_content
  // @effects html_content_hook_order_stable_across_empty_values
  it('빈 값 → 내용 → 빈 값 으로 바뀌어도 훅 순서 오류가 없다', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const { rerender, container } = render(<HtmlContent content="" />);
    rerender(<HtmlContent content="<p>a</p>" />);
    rerender(<HtmlContent content="b" isHtml={false} />);
    rerender(<HtmlContent content="" />);
    expect(container.innerHTML).toBe('');
    expect(spy.mock.calls.some((args) => String(args[0]).includes('Rendered'))).toBe(false);
    spy.mockRestore();
  });
});

describe('FileUploader 기존 첨부 썸네일 (D34)', () => {
  const attachment = (over: Record<string, unknown>) => ({
    id: 7,
    hash: 'h7',
    original_filename: 'a.png',
    mime_type: 'image/png',
    size: 1,
    size_formatted: '1B',
    download_url: '/api/modules/sirsoft-board/boards/free/attachment/h7/download',
    order: 1,
    is_image: true,
    ...over,
  });

  // @scenario component=file_uploader
  // @effects uploader_existing_preview_uses_sm_thumbnail
  it('미리보기 주소가 서빙 경로면 sm 썸네일을 <img> 로 바로 그리고 원본을 받지 않는다', async () => {
    const { container } = render(
      <SortableThumbnailItem
        file={attachment({ preview_url: '/api/modules/sirsoft-board/boards/free/attachment/h7/preview?expires=1&signature=s' }) as never}
        onRemove={() => {}}
      />,
    );
    await act(async () => {
      await new Promise((r) => setTimeout(r, 10));
    });
    expect(container.querySelector('img')!.getAttribute('src')).toBe(
      '/api/modules/sirsoft-board/boards/free/attachment/h7/preview?expires=1&signature=s&size=sm',
    );
    expect(apiGet).not.toHaveBeenCalled();
  });

  // @scenario component=file_uploader
  // @effects uploader_non_pattern_preview_fetched_once
  it('패턴 밖 주소는 종전처럼 인증 요청 1회로 받는다 (업로더 밖 단독)', async () => {
    render(<SortableThumbnailItem file={attachment({}) as never} onRemove={() => {}} />);
    await waitFor(() => expect(apiGet).toHaveBeenCalledTimes(1));
  });

  // @scenario component=file_uploader
  // @effects uploader_thumbnail_failure_falls_back_per_cell
  it('썸네일이 실패하면 그 칸만 인증 요청으로 돌아간다', async () => {
    const { container } = render(
      <SortableThumbnailItem file={attachment({ download_url: '/api/attachment/h7' }) as never} onRemove={() => {}} />,
    );
    const img = container.querySelector('img')!;
    expect(img.getAttribute('src')).toBe('/api/attachment/h7?size=sm');
    await act(async () => {
      fireEvent.error(img);
    });
    await waitFor(() => expect(apiGet).toHaveBeenCalledTimes(1));
  });

  /**
   * 종전 basic 은 업로더(useFileUploader)와 칸(SortableThumbnailItem)이 같은 원본을 각각 받았다 — 칸마다 원본 2회.
   */
  // @scenario component=file_uploader
  // @effects uploader_non_pattern_preview_fetched_once
  it('업로더 안에서는 패턴 밖 기존 첨부도 원본을 한 번만 받는다 (basic 이중 다운로드)', async () => {
    (window as any).G7Core.toast = { error: () => {}, success: () => {} };
    (window as any).G7Core.componentEvent = { on: () => () => {}, emit: async () => [] };
    await act(async () => {
      render(<FileUploader collection="t1" autoUpload={false} initialFiles={[attachment({})] as never} />);
    });
    await act(async () => {
      await new Promise((r) => setTimeout(r, 30));
    });
    expect(apiGet).toHaveBeenCalledTimes(1);
  });

  // @scenario component=file_uploader
  // @effects uploader_existing_preview_uses_sm_thumbnail
  it('업로더 안에서 미리보기 주소가 서빙 경로면 원본 요청 0, 칸은 sm 썸네일', async () => {
    (window as any).G7Core.toast = { error: () => {}, success: () => {} };
    (window as any).G7Core.componentEvent = { on: () => () => {}, emit: async () => [] };
    let container!: HTMLElement;
    await act(async () => {
      ({ container } = render(
        <FileUploader
          collection="t2"
          autoUpload={false}
          initialFiles={[attachment({ preview_url: '/api/modules/sirsoft-board/boards/free/attachment/h7/preview' })] as never}
        />,
      ));
    });
    await act(async () => {
      await new Promise((r) => setTimeout(r, 30));
    });
    expect(apiGet).not.toHaveBeenCalled();
    expect(Array.from(container.querySelectorAll('img')).map((img) => img.getAttribute('src'))).toContain(
      '/api/modules/sirsoft-board/boards/free/attachment/h7/preview?size=sm',
    );
  });
});
