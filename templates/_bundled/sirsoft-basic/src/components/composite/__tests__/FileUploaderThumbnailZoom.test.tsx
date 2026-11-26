/**
 * 업로더 기존 첨부의 확대 보기 주소 (프론트 검토 4)
 *
 * 썸네일로 그리는 첨부(그릴 주소가 서빙 경로)는 업로더가 원본 blob 을 받지 않는다. 종전에는 확대 보기가 그 대신
 * 인증 없는 원본 주소(다운로드 주소)를 열어, 권한이 걸린 첨부는 썸네일 칸이 인증 blob 으로 돌아간 뒤에도 확대 보기만
 * 깨졌다(칸이 받은 blob 은 칸 안에만 있었다). 이 시험은 확대 보기가 다음 순서로 주소를 고르는지 잠근다.
 *
 * 1. 업로더가 가진 인증 blob (썸네일이 실패한 칸은 업로더가 대신 받는다)
 * 2. 미리보기 주소(`preview_url`, 서버가 서명하거나 열람 규칙을 담아 내는 주소) — 그대로 연다
 * 3. 둘 다 없으면 확대 보기를 열 때 업로더가 인증 요청으로 받는다
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act, waitFor } from '@testing-library/react';
import {
  contentSrcset,
  getContentPreset,
  getThumbnailPresets,
  isThumbnailableUrl,
  stripThumbnailSize,
  thumbnailSrcset,
  withThumbnailSize,
} from '@core/support/thumbnailUrl';

const { apiGet, gallery } = vi.hoisted(() => {
  const get = vi.fn(() => Promise.resolve(new Blob(['x'], { type: 'image/png' })));
  (window as any).G7Core = { api: { get } };
  return { apiGet: get, gallery: { images: null as null | Array<{ src: string }> } };
});

// 라이트박스 본체(yarl)는 이 시험의 관심 밖이다 — 업로더가 넘기는 슬라이드 주소만 본다
vi.mock('../ImageGallery', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../ImageGallery')>();
  return {
    ...actual,
    ImageGallery: (props: { images: Array<{ src: string }> }) => {
      gallery.images = props.images;
      return null;
    },
  };
});

import { FileUploader } from '../FileUploader';

const PRESETS = [
  { name: 'xs', width: 64, height: 64, fit: 'cover', numeric: false },
  { name: 'sm', width: 160, height: 160, fit: 'cover', numeric: false },
  { name: 'md', width: 640, height: null, fit: 'width', numeric: true },
];

let blobSeq = 0;

beforeEach(() => {
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
    t: (key: string) => key,
    dispatch: () => {},
    api: { get: apiGet },
    toast: { error: () => {}, success: () => {} },
    componentEvent: { on: () => () => {}, emit: async () => [] },
    createLogger: () => ({ log: () => {}, warn: () => {}, error: () => {} }),
    thumbnail: {
      isThumbnailable: isThumbnailableUrl,
      url: withThumbnailSize,
      srcset: thumbnailSrcset,
      contentSrcset,
      contentPreset: getContentPreset,
      strip: stripThumbnailSize,
      presets: getThumbnailPresets,
    },
  });
  apiGet.mockClear();
  gallery.images = null;
  blobSeq = 0;
  (URL as any).createObjectURL = vi.fn(() => `blob:auth-${++blobSeq}`);
  (URL as any).revokeObjectURL = vi.fn();
});

afterEach(() => {
  cleanup();
  delete (window as any).G7Config;
});

const attachment = (over: Record<string, unknown>) => ({
  id: 7,
  hash: 'h7',
  original_filename: 'a.png',
  mime_type: 'image/png',
  size: 1,
  size_formatted: '1B',
  download_url: '/api/attachment/h7',
  order: 1,
  is_image: true,
  ...over,
});

async function settle(): Promise<void> {
  await act(async () => {
    await new Promise((r) => setTimeout(r, 30));
  });
}

async function mount(file: Record<string, unknown>): Promise<HTMLElement> {
  let container!: HTMLElement;
  await act(async () => {
    ({ container } = render(<FileUploader collection="zoom" autoUpload={false} initialFiles={[file] as never} />));
  });
  await settle();
  return container;
}

function cellImage(container: HTMLElement): HTMLImageElement {
  return container.querySelector('[data-uploader-item] img') as HTMLImageElement;
}

async function openZoom(container: HTMLElement): Promise<void> {
  await act(async () => {
    fireEvent.click(cellImage(container).parentElement!);
  });
  await settle();
}

describe('FileUploader 확대 보기 — 썸네일 첨부의 원본 주소', () => {
  // @scenario component=file_uploader
  // @effects uploader_zoom_uses_fallback_blob
  it('썸네일이 실패한 칸은 업로더가 인증 blob 을 한 번 받고, 칸과 확대 보기가 그 blob 을 함께 쓴다', async () => {
    const container = await mount(attachment({}));
    expect(apiGet).not.toHaveBeenCalled();
    expect(cellImage(container).getAttribute('src')).toBe('/api/attachment/h7?size=sm');

    await act(async () => {
      fireEvent.error(cellImage(container));
    });
    await settle();

    expect(apiGet).toHaveBeenCalledTimes(1);
    expect((apiGet.mock.calls[0] as unknown[])[0]).toBe('/api/attachment/h7');
    expect(cellImage(container).getAttribute('src')).toBe('blob:auth-1');

    await openZoom(container);
    expect(gallery.images?.[0]?.src).toBe('blob:auth-1');
    expect(apiGet).toHaveBeenCalledTimes(1);
  });

  // @scenario component=file_uploader
  // @effects uploader_zoom_preview_url_direct
  it('미리보기 주소가 있으면 확대 보기는 그 주소를 그대로 열고 원본을 받지 않는다', async () => {
    const preview = '/api/modules/sirsoft-board/boards/free/attachment/h7/preview?expires=1&signature=s';
    const container = await mount(
      attachment({ download_url: '/api/modules/sirsoft-board/boards/free/attachment/h7/download', preview_url: preview }),
    );
    await openZoom(container);
    expect(gallery.images?.[0]?.src).toBe(preview);
    expect(apiGet).not.toHaveBeenCalled();
  });

  // @scenario component=file_uploader
  // @effects uploader_zoom_gated_without_preview_fetches_on_open
  it('미리보기 주소가 없는 게이트 원본은 확대 보기를 열 때 인증 요청으로 받는다 — 인증 없는 원본 주소를 열지 않는다', async () => {
    const container = await mount(attachment({}));
    expect(apiGet).not.toHaveBeenCalled();

    await openZoom(container);
    expect(gallery.images?.[0]?.src).not.toBe('/api/attachment/h7');
    await waitFor(() => expect(apiGet).toHaveBeenCalledTimes(1));
    expect((apiGet.mock.calls[0] as unknown[])[0]).toBe('/api/attachment/h7');
    await waitFor(() => expect(gallery.images?.[0]?.src).toBe('blob:auth-1'));
    // 칸은 계속 썸네일이다
    expect(cellImage(container).getAttribute('src')).toBe('/api/attachment/h7?size=sm');
  });
});
