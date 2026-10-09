/**
 * FileUploader 허용 형식·거절 안내 테스트
 *
 * 업로더의 `accept` 는 확장자(`.png`)와 MIME(`text/plain`, `image/*`)을 함께 받는다. 파일 선택창은 두 표기를
 * 모두 따르는데 업로더의 사전 검사가 확장자만 보면, 운영자가 허용 형식을 MIME 으로 둔 경우(예: 페이지 첨부
 * 설정에 `text/plain`·`image/svg+xml` 추가) 서버가 받아 주는 파일을 화면이 먼저 거절한다.
 * 용량·형식 거절 안내는 화면 언어를 따라야 한다(한국어 문장 고정 금지).
 *
 * @scenario-manifest tests/scenarios/file-uploader-accept-policy.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useFileUploader } from '../FileUploader/useFileUploader';
import type { Attachment } from '../FileUploader/types';

beforeEach(() => {
  if (!(window as any).G7Core) {
    (window as any).G7Core = {};
  }
  const g7 = (window as any).G7Core;
  g7.componentEvent = { on: vi.fn(() => () => {}), emit: vi.fn(async () => []) };
  g7.createLogger = vi.fn(() => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }));
  // 번역 함수: 키와 인자를 그대로 드러내 안내가 번역 키를 거쳤는지 본다
  g7.t = vi.fn((key: string, params?: Record<string, unknown>) => `${key}${params ? ' ' + JSON.stringify(params) : ''}`);
});

vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(file)),
}));

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

const STABLE_EMPTY_FILES: Attachment[] = [];
const STABLE_EMPTY_ROLE_IDS: number[] = [];

/**
 * 허용 형식·용량을 정해 훅을 렌더한다.
 *
 * @param accept 업로더 accept 값
 * @param onUploadError 거절 안내 콜백
 * @param maxSize 파일 1개 용량 상한(MB)
 * @returns renderHook 결과
 */
function renderUploader(accept: string, onUploadError: (message: string, file: File) => void, maxSize = 10) {
  return renderHook(() =>
    useFileUploader({
      collection: 'test',
      maxFiles: 10,
      maxSize,
      accept,
      maxConcurrentUploads: 3,
      roleIds: STABLE_EMPTY_ROLE_IDS,
      autoUpload: false,
      initialFiles: STABLE_EMPTY_FILES,
      confirmBeforeRemove: false,
      onUploadError,
      endpoints: { upload: '/api/attachments', delete: '/api/attachments/:id', reorder: '/api/attachments/reorder' },
    })
  );
}

/**
 * File 배열을 FileList 모양으로 감싼다.
 *
 * @param files 파일 목록
 * @returns FileList 흉내 객체
 */
function asFileList(files: File[]): FileList {
  const list: Record<string | number, unknown> = { length: files.length, item: (i: number) => files[i] };
  files.forEach((f, i) => { list[i] = f; });
  list[Symbol.iterator as unknown as string] = function* () { yield* files; };
  return list as unknown as FileList;
}

describe('[case:file-uploader-accept-mime-tokens] FileUploader - accept 의 MIME 표기', () => {
  /**
   * @scenario input=mime_token
   *
   * @effects mime_token_accepts_matching_file, unlisted_type_rejected
   */
  it('accept 에 MIME 으로 적힌 형식(text/plain·image/svg+xml)의 파일을 받는다', async () => {
    const onUploadError = vi.fn();
    const { result } = renderUploader('.png,.pdf,text/plain,image/svg+xml', onUploadError);

    const txt = new File(['plain'], 'note.txt', { type: 'text/plain' });
    const svg = new File(['<svg/>'], 'vec.svg', { type: 'image/svg+xml' });
    const jpg = new File(['x'], 'photo.jpg', { type: 'image/jpeg' });
    await act(async () => {
      await result.current.handleFiles(asFileList([txt, svg, jpg]));
    });

    expect(result.current.pendingFiles.map((f) => f.original_filename)).toEqual(['note.txt', 'vec.svg']);
    expect(onUploadError).toHaveBeenCalledTimes(1);
    expect(onUploadError.mock.calls[0][1]).toBe(jpg);
  });

  /**
   * @scenario input=wildcard_token
   *
   * @effects wildcard_token_accepts_family
   */
  it('accept 의 와일드카드(image/*)는 같은 계열 MIME 을 받는다', async () => {
    const onUploadError = vi.fn();
    const { result } = renderUploader('image/*', onUploadError);

    const png = new File(['x'], 'a.png', { type: 'image/png' });
    const pdf = new File(['x'], 'b.pdf', { type: 'application/pdf' });
    await act(async () => {
      await result.current.handleFiles(asFileList([png, pdf]));
    });

    expect(result.current.pendingFiles.map((f) => f.original_filename)).toEqual(['a.png']);
    expect(onUploadError).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario input=extension_token
   *
   * @effects extension_token_unchanged
   */
  it('확장자 표기는 종전처럼 확장자로 판정한다(대소문자 무시)', async () => {
    const onUploadError = vi.fn();
    const { result } = renderUploader('.jpg,.jpeg,.png,.gif,.webp,.pdf,.zip', onUploadError);

    const upper = new File(['x'], 'PHOTO.PNG', { type: 'image/png' });
    const txt = new File(['x'], 'note.txt', { type: 'text/plain' });
    await act(async () => {
      await result.current.handleFiles(asFileList([upper, txt]));
    });

    expect(result.current.pendingFiles.map((f) => f.original_filename)).toEqual(['PHOTO.PNG']);
    expect(onUploadError).toHaveBeenCalledTimes(1);
  });
});

describe('[case:file-uploader-reject-message-i18n] FileUploader - 거절 안내 다국어', () => {
  /**
   * @scenario input=rejected_type
   *
   * @effects type_reject_message_uses_translation_key
   */
  it('형식 거절 안내는 번역 키 attachment.type_not_allowed 를 쓴다', async () => {
    const onUploadError = vi.fn();
    const { result } = renderUploader('.png', onUploadError);

    const jpg = new File(['x'], 'photo.jpg', { type: 'image/jpeg' });
    await act(async () => {
      await result.current.handleFiles(asFileList([jpg]));
    });

    expect(onUploadError).toHaveBeenCalledTimes(1);
    const message = onUploadError.mock.calls[0][0] as string;
    expect(message.startsWith('attachment.type_not_allowed')).toBe(true);
    expect(message).toContain('photo.jpg');
    expect(message).not.toMatch(/[가-힣]/);
  });

  /**
   * @scenario input=oversize_file
   *
   * @effects size_reject_message_uses_translation_key
   */
  it('용량 거절 안내는 번역 키 attachment.file_too_large 를 쓴다', async () => {
    const onUploadError = vi.fn();
    const { result } = renderUploader('.pdf', onUploadError, 1);

    const big = new File([new Uint8Array(2 * 1024 * 1024)], 'big.pdf', { type: 'application/pdf' });
    await act(async () => {
      await result.current.handleFiles(asFileList([big]));
    });

    expect(onUploadError).toHaveBeenCalledTimes(1);
    const message = onUploadError.mock.calls[0][0] as string;
    expect(message.startsWith('attachment.file_too_large')).toBe(true);
    expect(message).toContain('big.pdf');
    expect(message).toContain('"max":1');
    expect(message).not.toMatch(/[가-힣]/);
  });
});
