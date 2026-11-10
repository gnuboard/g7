/**
 * FileUploader 움직이는 이미지 압축 건너뛰기 테스트 (#869 P4)
 *
 * 이미지 압축이 켜진 업로더(사이트 로고 등)가 움직이는 GIF·WebP·APNG 를 browser-image-compression 으로
 * 다시 인코딩해 첫 프레임만 남은 정지 그림이 올라갔다. 움직임은 MIME 이 아니라 파일 바이트로 가린다 —
 * 판정 규칙은 서버 썸네일 생성기(ThumbnailGenerator::animatedFormat)와 같다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import imageCompression from 'browser-image-compression';
import { useFileUploader } from '../FileUploader/useFileUploader';
import { detectAnimatedImageFormat } from '../FileUploader/utils';
import type { Attachment } from '../FileUploader/types';

beforeEach(() => {
  if (!(window as any).G7Core) {
    (window as any).G7Core = {};
  }
  const g7 = (window as any).G7Core;
  g7.componentEvent = { on: vi.fn(() => () => {}), emit: vi.fn() };
  g7.createLogger = vi.fn(() => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }));
  g7.t = vi.fn((key: string, params?: Record<string, unknown>) => (params?.name ? `${key}|${String(params.name)}` : key));
  vi.mocked(imageCompression).mockClear();
});

vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(new Blob(['압축본'], { type: file.type }))),
}));

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

const bytes = (...parts: Array<string | number[]>): Uint8Array<ArrayBuffer> => {
  const out: number[] = [];
  for (const part of parts) {
    if (typeof part === 'string') for (const ch of part) out.push(ch.charCodeAt(0));
    else out.push(...part);
  }
  return new Uint8Array(out);
};
const u32le = (n: number) => [n & 0xff, (n >> 8) & 0xff, (n >> 16) & 0xff, (n >> 24) & 0xff];
const u32be = (n: number) => [(n >> 24) & 0xff, (n >> 16) & 0xff, (n >> 8) & 0xff, n & 0xff];

/** GIF — 그래픽 제어 확장 블록(21 F9 04 …… 00) 뒤에 이미지 서술자(2C)가 오는 프레임을 n 개 둔다 */
const gif = (frames: number) => {
  const frame = [0x21, 0xf9, 0x04, 0x00, 0x0a, 0x00, 0x00, 0x00, 0x2c, 0, 0, 0, 0, 1, 0, 1, 0, 0, 0x02, 0x02, 0x44, 0x01, 0x00];
  const body: number[] = [];
  for (let i = 0; i < frames; i++) body.push(...frame);
  return bytes('GIF89a', [1, 0, 1, 0, 0x80, 0, 0], [0, 0, 0, 0xff, 0xff, 0xff], body, [0x3b]);
};
/** WebP — RIFF 안에 VP8X 와 ANMF 청크를 n 개 둔다(본문 4바이트) */
const webp = (anmf: number) => {
  const chunks: number[] = [];
  chunks.push(...bytes('VP8X', u32le(10), [0x02, 0, 0, 0, 0, 0, 0, 0, 0, 0]));
  if (anmf > 0) chunks.push(...bytes('ANIM', u32le(6), [0, 0, 0, 0, 0, 0]));
  for (let i = 0; i < anmf; i++) chunks.push(...bytes('ANMF', u32le(4), [0, 0, 0, 0]));
  return bytes('RIFF', u32le(4 + chunks.length), 'WEBP', chunks);
};
/** PNG — IHDR 다음에 acTL(프레임 수 n) 을 두고 IDAT 를 둔다. n=0 이면 acTL 없음 */
const png = (frames: number) => {
  const chunk = (type: string, data: number[]) => [...u32be(data.length), ...bytes(type), ...data, 0, 0, 0, 0];
  return bytes(
    [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a],
    chunk('IHDR', [0, 0, 0, 1, 0, 0, 0, 1, 8, 6, 0, 0, 0]),
    frames > 0 ? chunk('acTL', [...u32be(frames), 0, 0, 0, 0]) : [],
    chunk('IDAT', [0x78, 0x9c, 0, 0]),
    chunk('IEND', [])
  );
};
const fileOf = (data: Uint8Array<ArrayBuffer>, name: string, type: string) => new File([data], name, { type });

describe('detectAnimatedImageFormat — 파일 바이트로 움직임을 가린다', () => {
  /**
   * @scenario image_format=gif
   * @effects animated_image_detected_by_bytes
   */
  it('GIF 는 프레임이 둘 이상이면 gif, 하나면 null', async () => {
    expect(await detectAnimatedImageFormat(fileOf(gif(2), 'a.gif', 'image/gif'))).toBe('gif');
    expect(await detectAnimatedImageFormat(fileOf(gif(1), 'b.gif', 'image/gif'))).toBeNull();
  });

  /**
   * @scenario image_format=webp
   * @effects animated_image_detected_by_bytes
   */
  it('WebP 는 ANMF 프레임이 둘 이상이면 webp, 애니메이션 표시만 있고 프레임이 하나면 null', async () => {
    expect(await detectAnimatedImageFormat(fileOf(webp(2), 'a.webp', 'image/webp'))).toBe('webp');
    expect(await detectAnimatedImageFormat(fileOf(webp(1), 'b.webp', 'image/webp'))).toBeNull();
    expect(await detectAnimatedImageFormat(fileOf(webp(0), 'c.webp', 'image/webp'))).toBeNull();
  });

  /**
   * @scenario image_format=png
   * @effects animated_image_detected_by_bytes
   */
  it('PNG 는 IDAT 앞 acTL 의 프레임 수가 둘 이상이면 png, acTL 이 없거나 한 프레임이면 null', async () => {
    expect(await detectAnimatedImageFormat(fileOf(png(3), 'a.png', 'image/png'))).toBe('png');
    expect(await detectAnimatedImageFormat(fileOf(png(1), 'b.png', 'image/png'))).toBeNull();
    expect(await detectAnimatedImageFormat(fileOf(png(0), 'c.png', 'image/png'))).toBeNull();
  });

  /**
   * @scenario image_format=mime_mismatch
   * @effects animated_image_detected_by_bytes
   */
  it('MIME 이 아니라 파일 머리로 형식을 가린다 — 확장자·MIME 이 jpeg 여도 바이트가 움직이는 GIF 면 gif', async () => {
    expect(await detectAnimatedImageFormat(fileOf(gif(2), 'renamed.jpg', 'image/jpeg'))).toBe('gif');
    expect(await detectAnimatedImageFormat(fileOf(bytes([0xff, 0xd8, 0xff, 0xe0]), 'x.jpg', 'image/jpeg'))).toBeNull();
  });
});

describe('useFileUploader — 움직이는 이미지는 압축하지 않고 원본을 올린다', () => {
  const STABLE_EMPTY_FILES: Attachment[] = [];
  const STABLE_EMPTY_ROLE_IDS: number[] = [];
  const makeFileList = (files: File[]): FileList =>
    ({ ...files, length: files.length, item: (i: number) => files[i] ?? null }) as unknown as FileList;

  const setup = () =>
    renderHook(() =>
      useFileUploader({
        collection: 'site_logo',
        maxFiles: 10,
        maxSize: 10,
        maxConcurrentUploads: 3,
        roleIds: STABLE_EMPTY_ROLE_IDS,
        autoUpload: false,
        initialFiles: STABLE_EMPTY_FILES,
        confirmBeforeRemove: false,
        imageCompression: { maxSizeMB: 1, maxWidthOrHeight: 1920 },
        endpoints: { upload: '/api/attachments', delete: '/api/attachments/:id', reorder: '/api/attachments/reorder' },
      })
    );

  /**
   * @scenario image_format=gif|webp|png
   * @effects animated_image_uploaded_without_compression
   */
  it('움직이는 GIF·WebP·APNG 는 압축기를 부르지 않고 원본 그대로 남는다', async () => {
    const { result } = setup();
    const files = [
      fileOf(gif(2), 'logo.gif', 'image/gif'),
      fileOf(webp(2), 'logo.webp', 'image/webp'),
      fileOf(png(2), 'logo.png', 'image/png'),
    ];

    await act(async () => {
      await result.current.handleFiles(makeFileList(files));
    });

    expect(imageCompression, '움직이는 이미지를 압축기에 넘겼습니다 — 첫 프레임만 남은 정지 그림이 올라갑니다').not.toHaveBeenCalled();
    const pending = result.current.pendingFiles;
    expect(pending.map((p: any) => p.original_filename)).toEqual(['logo.gif', 'logo.webp', 'logo.png']);
    for (const [i, p] of pending.entries()) {
      expect(p.compressedBlob).toBeUndefined();
      expect(p.size).toBe(files[i].size);
      expect(p.status).toBe('pending');
    }
  });

  /**
   * @scenario image_format=still
   * @effects still_image_compressed
   */
  it('정지 그림(한 프레임 GIF, acTL 없는 PNG)은 종전대로 압축한다', async () => {
    const { result } = setup();

    await act(async () => {
      await result.current.handleFiles(makeFileList([fileOf(gif(1), 'still.gif', 'image/gif'), fileOf(png(0), 'still.png', 'image/png')]));
    });

    expect(imageCompression).toHaveBeenCalledTimes(2);
    for (const p of result.current.pendingFiles) {
      expect(p.compressedBlob).toBeInstanceOf(Blob);
    }
  });

  /**
   * @scenario image_format=still
   * @effects compression_failure_message_translated
   */
  it('압축이 실패하면 오류 문구를 번역 키로 만든다 — 한국어 리터럴이 다른 언어 화면에 섞이지 않는다 (#869 G4)', async () => {
    vi.mocked(imageCompression).mockImplementationOnce(() => Promise.reject(new Error('압축기 실패')));
    const { result } = setup();

    await act(async () => {
      await result.current.handleFiles(makeFileList([fileOf(png(0), 'still.png', 'image/png')]));
    });

    const [p] = result.current.pendingFiles;
    expect(p.status).toBe('error');
    expect(p.error).toBe('attachment.compression_failed|still.png');
  });

  /**
   * @scenario image_format=still
   * @effects compressed_larger_than_original_uploads_original
   */
  it('압축 결과가 원본보다 크거나 같으면 원본을 올린다 (#869 브라우저 실측: 22,273 → 28,823 바이트)', async () => {
    vi.mocked(imageCompression).mockImplementationOnce((file: File) => Promise.resolve(new File([new Uint8Array(file.size + 100)], file.name, { type: file.type })));
    const { result } = setup();
    const still = fileOf(png(0), 'still.png', 'image/png');

    await act(async () => {
      await result.current.handleFiles(makeFileList([still]));
    });

    const [p] = result.current.pendingFiles;
    expect(imageCompression).toHaveBeenCalledTimes(1);
    expect(p.compressedBlob, '원본보다 큰 압축본을 올립니다').toBeUndefined();
    expect(p.size).toBe(still.size);
    expect(p.status).toBe('pending');
  });
});
