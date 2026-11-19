/**
 * FileUploader(사용자) 정렬 주소 — `reorder` 가 null·빈 문자열·키 없음이면 정렬 요청을 보내지 않는다
 *
 * 종전에는 `apiEndpoints.reorder ?? '/api/attachments/reorder'` 라 정렬 주소를 넘기지 않은 화면도 존재하지 않는
 * 경로에 PATCH 를 보냈다. 사용자 템플릿에는 공용 정렬 API 가 없으므로 기본값을 두지 않는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, render, renderHook } from '@testing-library/react';

import type { Attachment, ApiEndpoints } from '../FileUploader/types';

// 컴포넌트가 훅에 넘긴 주소를 기록한다 — 실제 훅은 그대로 돈다
const seenEndpoints: ApiEndpoints[] = [];
vi.mock('../FileUploader/useFileUploader', async () => {
  const actual = await vi.importActual<typeof import('../FileUploader/useFileUploader')>('../FileUploader/useFileUploader');
  return {
    ...actual,
    useFileUploader: (options: Parameters<typeof actual.useFileUploader>[0]) => {
      seenEndpoints.push(options.endpoints);
      return actual.useFileUploader(options);
    },
  };
});

vi.mock('browser-image-compression', () => ({
  default: vi.fn((file: File) => Promise.resolve(file)),
}));

import { FileUploader } from '../FileUploader';
import { useFileUploader } from '../FileUploader/useFileUploader';
import { resolveApiEndpoints } from '../FileUploader/utils';

const STABLE_EMPTY_ROLE_IDS: number[] = [];

function attachment(id: number): Attachment {
  return {
    id,
    hash: `h${id}`,
    original_filename: `f${id}.png`,
    mime_type: 'image/png',
    size: 10,
    size_formatted: '10 B',
    download_url: `/api/x/h${id}`,
    order: id,
    is_image: false,
  };
}

const FILES: Attachment[] = [attachment(1), attachment(2)];

beforeEach(() => {
  if (!(window as any).G7Core) {
    (window as any).G7Core = {};
  }
  const g7 = (window as any).G7Core;
  g7.componentEvent = { on: vi.fn(() => () => {}), emit: vi.fn(async () => []) };
  g7.createLogger = vi.fn(() => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }));
  g7.t = vi.fn((key: string) => key);
  g7.toast = { success: vi.fn(), error: vi.fn() };
  g7.api = {
    patch: vi.fn().mockResolvedValue({ message: 'ok' }),
    post: vi.fn(),
    delete: vi.fn(),
    get: vi.fn(() => { throw new Error('test: skip auth image'); }),
  };
  seenEndpoints.length = 0;
  URL.createObjectURL = vi.fn(() => 'blob:x');
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  delete (window as any).G7Core.api;
});

/**
 * 주어진 주소로 훅을 세우고 첫 첨부를 둘째 자리로 끌어 옮긴다.
 */
async function dragFirstToSecond(endpoints: ApiEndpoints) {
  const onReorder = vi.fn();
  const { result } = renderHook(() =>
    useFileUploader({
      collection: 'default',
      maxFiles: 5,
      maxConcurrentUploads: 1,
      roleIds: STABLE_EMPTY_ROLE_IDS,
      autoUpload: false,
      initialFiles: FILES,
      endpoints,
      confirmBeforeRemove: false,
      onReorder,
    })
  );
  await act(async () => {
    await result.current.handleDragEnd({ active: { id: 'h1' }, over: { id: 'h2' } } as any);
  });

  return { onReorder };
}

describe('FileUploader(사용자) — reorder 키 해석', () => {
  // @scenario case=user_reorder_null_empty_or_missing
  // @effects user_uploader_reorder_has_no_default
  it('reorder 가 null·빈 문자열·키 없음이면 정렬 주소가 없고, 값이 있으면 그 값이다', () => {
    expect(resolveApiEndpoints({ upload: '/u', reorder: null }).reorder).toBeNull();
    expect(resolveApiEndpoints({ upload: '/u', reorder: '' }).reorder).toBeNull();
    expect(resolveApiEndpoints({ upload: '/u' }).reorder).toBeNull();
    expect(resolveApiEndpoints({ upload: '/u', reorder: '/r' }).reorder).toBe('/r');
  });

  // @scenario case=user_component_passes_null_reorder
  // @effects user_uploader_reorder_has_no_default
  it('컴포넌트가 reorder: null 을 훅에 null 로 넘긴다', () => {
    render(<FileUploader apiEndpoints={{ upload: '/u', reorder: null }} autoUpload={false} />);
    expect(seenEndpoints.length).toBeGreaterThan(0);
    expect(seenEndpoints.every((e) => e.reorder === null)).toBe(true);
  });
});

describe('FileUploader(사용자) — 정렬 요청', () => {
  // @scenario case=user_drag_with_null_reorder
  // @effects user_uploader_reorder_null_sends_no_patch
  it.each([null, ''])('정렬 주소가 %j 이면 끌어 옮겨도 PATCH 를 보내지 않고 onReorder 는 부른다', async (reorder) => {
    const { onReorder } = await dragFirstToSecond(resolveApiEndpoints({ upload: '/u', delete: '/d/:id', reorder }));

    expect((window as any).G7Core.api.patch).not.toHaveBeenCalled();
    expect(onReorder).toHaveBeenCalledTimes(1);
    expect(onReorder.mock.calls[0][0].map((f: Attachment) => f.id)).toEqual([2, 1]);
  });

  // @scenario case=user_drag_with_reorder_endpoint
  // @effects user_uploader_reorder_endpoint_sends_one_patch
  it('정렬 주소가 있으면 PATCH 를 한 번 보낸다(대조군)', async () => {
    await dragFirstToSecond(resolveApiEndpoints({ upload: '/u', delete: '/d/:id', reorder: '/r' }));

    expect((window as any).G7Core.api.patch).toHaveBeenCalledTimes(1);
    expect((window as any).G7Core.api.patch.mock.calls[0][0]).toBe('/r');
  });
});
