/**
 * FileUploader 고른 순서 저장·방금 올린 그림 재요청 테스트
 *
 * 1) 파일 선택창에서 여러 파일을 한 번에 고르면 업로더가 동시에 올려, 먼저 끝난 파일이 앞 순서를 받았다.
 *    페이지·게시판처럼 서버가 업로드가 도착한 순서로 번호를 매기는 곳에서는 저장 순서가 고른 순서와
 *    달라졌다(7.1.0 전수 실측 영역 L, O-L1). 기본값은 고른 순서대로 하나씩 올리고, 동시 업로드를
 *    켠 화면에서도 화면 목록과 완료 콜백, 저장 목록은 고른 순서를 지킨다.
 * 2) 방금 올린 그림을 썸네일과 갤러리용으로 서버에서 다시 받다가, 저장이 임시 첨부를 본경로로 옮기는
 *    사이에 404 와 콘솔 오류가 났다(O-L5). 방금 올린 그림은 이미 브라우저에 있는 파일로 보여 준다.
 *
 * @scenario-manifest tests/scenarios/file-uploader-selection-order.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act, renderHook, fireEvent } from '@testing-library/react';
import { FileUploader } from '../FileUploader';
import { useFileUploader } from '../FileUploader/useFileUploader';
import type { Attachment } from '../FileUploader/types';

let blobSeq = 0;

beforeEach(() => {
  const listeners = new Map<string, Set<Function>>();
  if (!(window as any).G7Core) {
    (window as any).G7Core = {};
  }
  const g7 = (window as any).G7Core;
  g7.componentEvent = {
    on: vi.fn((event: string, callback: Function) => {
      if (!listeners.has(event)) listeners.set(event, new Set());
      listeners.get(event)!.add(callback);
      return () => listeners.get(event)?.delete(callback);
    }),
    emit: vi.fn(async (event: string, data?: unknown) => {
      const results = [];
      for (const listener of listeners.get(event) ?? []) results.push(await listener(data));
      return results;
    }),
  };
  g7.createLogger = vi.fn(() => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }));
  g7.t = vi.fn((key: string) => key);
  blobSeq = 0;
  URL.createObjectURL = vi.fn(() => `blob:local-${++blobSeq}`);
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  delete (window as any).G7Core.api;
});

vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(file)),
}));

const STABLE_EMPTY_FILES: Attachment[] = [];
const STABLE_EMPTY_ROLE_IDS: number[] = [];
const ENDPOINTS = { upload: '/api/attachments', delete: '/api/attachments/:id', reorder: '' };

/**
 * 업로드 응답용 첨부를 만든다.
 */
function attachmentFor(name: string, id: number, isImage = false): Attachment {
  return {
    id,
    hash: `h${id}`,
    original_filename: name,
    mime_type: isImage ? 'image/png' : 'application/pdf',
    size: 10,
    size_formatted: '10 B',
    download_url: `/api/attachments/h${id}`,
    order: id,
    is_image: isImage,
  };
}

function asFileList(files: File[]): FileList {
  const list: Record<string | number, unknown> = { length: files.length, item: (i: number) => files[i] };
  files.forEach((f, i) => { list[i] = f; });
  list[Symbol.iterator as unknown as string] = function* () { yield* files; };
  return list as unknown as FileList;
}

/**
 * 파일 이름별 지연(ms)을 두고 응답하는 업로드 대역. 동시에 몇 건이 진행 중이었는지와 요청 순서를 기록한다.
 */
function installDelayedUpload(delays: Record<string, number>) {
  const state = { inFlight: 0, maxInFlight: 0, order: [] as string[] };
  let seq = 0;
  (window as any).G7Core.api = {
    post: vi.fn((_url: string, form: FormData) => {
      const file = form.get('file') as File;
      state.order.push(file.name);
      state.inFlight++;
      state.maxInFlight = Math.max(state.maxInFlight, state.inFlight);
      const id = 100 + ++seq;
      return new Promise((resolve) => {
        setTimeout(() => {
          state.inFlight--;
          resolve({ success: true, data: attachmentFor(file.name, id, file.type.startsWith('image/')) });
        }, delays[file.name] ?? 0);
      });
    }),
    get: vi.fn(() => Promise.reject(new Error('not found'))),
  };
  return state;
}

const FILES = () => [
  new File(['a'], 'a-first.pdf', { type: 'application/pdf' }),
  new File(['b'], 'b-second.pdf', { type: 'application/pdf' }),
  new File(['c'], 'c-third.pdf', { type: 'application/pdf' }),
];

describe('[case:file-uploader-selection-order] 여러 파일을 한 번에 골랐을 때의 순서', () => {
  /**
   * @scenario case=default_concurrency
   *
   * @effects file_uploader_uploads_in_selection_order_by_default
   */
  it('기본값은 고른 순서대로 하나씩 올린다(서버가 도착 순서로 번호를 매겨도 고른 순서가 된다)', async () => {
    vi.useFakeTimers();
    try {
      const state = installDelayedUpload({ 'a-first.pdf': 300, 'b-second.pdf': 200, 'c-third.pdf': 10 });
      const onUploadComplete = vi.fn();
      const { container } = render(
        <FileUploader
          collection="test"
          maxFiles={5}
          maxSize={10}
          autoUpload={false}
          initialFiles={STABLE_EMPTY_FILES}
          uploadTriggerEvent="upload:order_default"
          apiEndpoints={ENDPOINTS}
          onUploadComplete={onUploadComplete}
        />
      );
      const input = container.querySelector('input[type="file"]') as HTMLInputElement;
      await act(async () => {
        fireEvent.change(input, { target: { files: asFileList(FILES()) } });
      });

      let results: any[] = [];
      await act(async () => {
        const pending = (window as any).G7Core.componentEvent.emit('upload:order_default');
        await vi.runAllTimersAsync();
        results = await pending;
      });

      expect(state.maxInFlight).toBe(1);
      expect(state.order).toEqual(['a-first.pdf', 'b-second.pdf', 'c-third.pdf']);
      expect(onUploadComplete.mock.calls[0][0].map((a: Attachment) => a.original_filename)).toEqual([
        'a-first.pdf',
        'b-second.pdf',
        'c-third.pdf',
      ]);
      expect(results[0].allFiles.map((a: Attachment) => a.original_filename)).toEqual([
        'a-first.pdf',
        'b-second.pdf',
        'c-third.pdf',
      ]);
    } finally {
      vi.useRealTimers();
    }
  });

  /**
   * @scenario case=parallel_concurrency
   *
   * @effects file_uploader_keeps_selection_order_when_parallel
   */
  it('동시 업로드를 켠 화면에서도 완료 콜백, 화면 목록, 저장 목록은 고른 순서를 지킨다', async () => {
    vi.useFakeTimers();
    try {
      installDelayedUpload({ 'a-first.pdf': 300, 'b-second.pdf': 200, 'c-third.pdf': 10 });
      const onUploadComplete = vi.fn();
      const { result } = renderHook(() =>
        useFileUploader({
          collection: 'test',
          maxFiles: 5,
          maxSize: 10,
          maxConcurrentUploads: 3,
          roleIds: STABLE_EMPTY_ROLE_IDS,
          autoUpload: false,
          initialFiles: STABLE_EMPTY_FILES,
          confirmBeforeRemove: false,
          endpoints: ENDPOINTS,
          onUploadComplete,
          uploadTriggerEvent: 'upload:order_parallel',
        })
      );
      await act(async () => {
        await result.current.handleFiles(asFileList(FILES()));
      });

      let results: any[] = [];
      await act(async () => {
        const pending = (window as any).G7Core.componentEvent.emit('upload:order_parallel');
        await vi.runAllTimersAsync();
        results = await pending;
      });

      const names = (list: Attachment[]) => list.map((a) => a.original_filename);
      const expected = ['a-first.pdf', 'b-second.pdf', 'c-third.pdf'];
      expect(names(onUploadComplete.mock.calls[0][0])).toEqual(expected);
      expect(names(result.current.existingFiles)).toEqual(expected);
      expect(names(results[0].allFiles)).toEqual(expected);
    } finally {
      vi.useRealTimers();
    }
  });
});

describe('[case:file-uploader-no-refetch-after-upload] 방금 올린 그림을 서버에서 다시 받지 않는다', () => {
  /**
   * @scenario case=fresh_image
   *
   * @effects file_uploader_reuses_local_image_after_upload
   */
  it('업로드가 끝난 그림은 내려받기 주소를 다시 요청하지 않고 로컬 파일로 썸네일을 그린다', async () => {
    installDelayedUpload({});
    const { container } = render(
      <FileUploader
        collection="test"
        maxFiles={5}
        maxSize={10}
        autoUpload={false}
        initialFiles={STABLE_EMPTY_FILES}
        uploadTriggerEvent="upload:no_refetch"
        apiEndpoints={ENDPOINTS}
      />
    );
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    await act(async () => {
      fireEvent.change(input, { target: { files: asFileList([new File(['png'], 'fresh.png', { type: 'image/png' })]) } });
    });
    await act(async () => {
      await (window as any).G7Core.componentEvent.emit('upload:no_refetch');
    });
    // 업로드 뒤 효과(썸네일·갤러리 캐시)가 돌 시간을 준다
    await act(async () => {
      await new Promise((r) => setTimeout(r, 20));
    });

    const g7 = (window as any).G7Core;
    expect(g7.api.post).toHaveBeenCalledTimes(1);
    expect(g7.api.get).not.toHaveBeenCalled();
    const img = container.querySelector('img') as HTMLImageElement | null;
    expect(img?.getAttribute('src')).toMatch(/^blob:local-/);
  });

  /**
   * @scenario case=existing_image
   *
   * @effects file_uploader_existing_images_still_fetched
   */
  it('처음부터 있던 그림은 종전대로 인증 요청으로 받는다', async () => {
    (window as any).G7Core.api = { get: vi.fn(() => Promise.resolve(new Blob(['x']))), post: vi.fn() };
    const initial = [attachmentFor('kept.png', 7, true)];
    render(
      <FileUploader collection="test" maxFiles={5} maxSize={10} autoUpload={false} initialFiles={initial} apiEndpoints={ENDPOINTS} />
    );
    await act(async () => {
      await new Promise((r) => setTimeout(r, 20));
    });
    expect((window as any).G7Core.api.get).toHaveBeenCalledWith('/api/attachments/h7', { responseType: 'blob' });
  });
});
