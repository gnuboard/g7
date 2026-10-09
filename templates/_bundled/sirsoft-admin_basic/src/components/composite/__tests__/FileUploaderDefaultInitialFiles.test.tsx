/**
 * 관리자 템플릿 FileUploader — `initialFiles` 를 생략하거나 렌더마다 새 빈 배열로 넘겨도 동기화 effect 가 돌지 않는다.
 *
 * 7.1.0 전수 실측(O-PD-2, 결정 ⑪): `initialFiles = []` 기본값이 렌더마다 새 배열이라, 속성을 빼고 쓰면
 * 「initialFiles 바뀜 → existingFiles 갱신 → 다시 렌더 → 새 기본 배열」이 끝없이 돌아 Vitest 워커가 죽었다.
 * 기본값은 모듈 상수(얼린 빈 배열)로 두고, 훅은 내용이 같은 목록이면 상태를 바꾸지 않는다.
 * 루프가 생기면 워커가 멈추지 않도록 훅 호출 횟수가 상한을 넘을 때 렌더를 끊어 실패로 드러낸다.
 *
 * @scenario-manifest tests/scenarios/file-uploader-default-initial-files.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { render, act } from '@testing-library/react';

vi.hoisted(() => {
  (window as any).G7Core = {
    ...((window as any).G7Core ?? {}),
    t: (key: string) => key,
    api: { get: () => Promise.resolve(null), post: () => Promise.resolve({}), delete: () => Promise.resolve({}) },
    toast: { error: () => {}, success: () => {} },
    componentEvent: { on: () => () => {}, emit: async () => [] },
    createLogger: () => ({ log: () => {}, warn: () => {}, error: () => {} }),
  };
});

/** 훅 호출 기록 — 렌더 횟수와 훅이 받은 initialFiles·roleIds 참조 */
const calls: { initialFiles: unknown; roleIds: unknown }[] = [];
/** 이 횟수를 넘으면 동기화 루프로 보고 렌더를 끊는다 */
const RENDER_LIMIT = 30;

vi.mock('../FileUploader/useFileUploader', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../FileUploader/useFileUploader')>();
  return {
    ...actual,
    useFileUploader: (options: any) => {
      calls.push({ initialFiles: options.initialFiles, roleIds: options.roleIds });
      if (calls.length > RENDER_LIMIT) {
        throw new Error(`render loop: useFileUploader called ${calls.length} times`);
      }
      return actual.useFileUploader(options);
    },
  };
});

import { FileUploader } from '../FileUploader';

/**
 * 렌더하고 effect 가 가라앉을 때까지 기다린다. 루프로 렌더가 끊기면 그 오류를 돌려준다.
 *
 * @param ui 렌더할 요소
 * @returns 렌더 결과와 오류(없으면 null)
 */
async function renderSettled(ui: React.ReactElement) {
  let error: unknown = null;
  let result: ReturnType<typeof render> | null = null;
  const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
  try {
    await act(async () => {
      result = render(ui);
    });
    await act(async () => {
      await new Promise((r) => setTimeout(r, 20));
    });
  } catch (e) {
    error = e;
  } finally {
    spy.mockRestore();
  }
  return { result: result as ReturnType<typeof render> | null, error };
}

describe('FileUploader initialFiles 기본값 (admin_basic)', () => {
  beforeEach(() => {
    calls.length = 0;
  });

  // @scenario case=omitted
  // @effects no_sync_loop, stable_default_reference
  it('initialFiles 를 생략하면 렌더가 몇 번 안에 가라앉고, 훅이 받는 기본값은 렌더마다 같은 얼린 배열이다', async () => {
    const { result, error } = await renderSettled(<FileUploader collection="c1" autoUpload={false} />);

    expect(error).toBeNull();
    expect(calls.length).toBeLessThanOrEqual(3);

    // 같은 속성으로 다시 그려도 기본값 참조는 그대로다
    await act(async () => {
      result?.rerender(<FileUploader collection="c1" autoUpload={false} />);
    });
    const defaults = calls.map((c) => c.initialFiles);
    expect(new Set(defaults).size).toBe(1);
    expect(Object.isFrozen(defaults[0])).toBe(true);
    expect(defaults[0]).toEqual([]);
  });

  // @scenario case=omitted_role_ids
  // @effects stable_default_reference
  it('roleIds 를 생략해도 훅이 받는 기본값은 렌더마다 같은 얼린 배열이다', async () => {
    const { result, error } = await renderSettled(<FileUploader collection="c2" autoUpload={false} initialFiles={[]} />);
    expect(error).toBeNull();
    await act(async () => {
      result?.rerender(<FileUploader collection="c2" autoUpload={false} initialFiles={[]} />);
    });
    const roleIds = calls.map((c) => c.roleIds);
    expect(new Set(roleIds).size).toBe(1);
    expect(Object.isFrozen(roleIds[0])).toBe(true);
  });

  // @scenario case=inline_empty_each_render
  // @effects no_sync_loop
  it('렌더마다 새 빈 배열을 넘겨도 동기화 effect 가 상태를 다시 바꾸지 않는다', async () => {
    /** 부모가 다시 그릴 때마다 initialFiles 에 새 빈 배열을 넘긴다 */
    function Parent({ tick }: { tick: number }) {
      return <FileUploader collection={`c3`} autoUpload={false} initialFiles={[]} className={`t${tick}`} />;
    }
    const { result, error } = await renderSettled(<Parent tick={0} />);
    expect(error).toBeNull();
    const afterMount = calls.length;
    expect(afterMount).toBeLessThanOrEqual(3);

    // 부모가 한 번 다시 그리면 업로더도 한 번 — 동기화 effect 가 같은 내용의 목록으로 상태를 바꾸지 않으므로 그 뒤 렌더는 없다
    await act(async () => {
      result?.rerender(<Parent tick={1} />);
    });
    await act(async () => {
      await new Promise((r) => setTimeout(r, 20));
    });
    expect(calls.length - afterMount).toBe(1);
  });

  // @scenario case=content_changed
  // @effects initial_files_sync_kept
  it('내용이 바뀐 initialFiles 는 종전대로 목록에 반영된다', async () => {
    const file = (id: number, name: string) => ({
      id, hash: `h_${id}`, original_filename: name, mime_type: 'text/plain', size: 8, size_formatted: '8 B',
      download_url: null, order: id, is_image: false,
    }) as any;
    const { result, error } = await renderSettled(
      <FileUploader collection="c4" autoUpload={false} initialFiles={[file(9101, 'a.txt')]} />,
    );
    expect(error).toBeNull();
    expect(result?.container.textContent).toContain('a.txt');

    await act(async () => {
      result?.rerender(<FileUploader collection="c4" autoUpload={false} initialFiles={[file(9101, 'a.txt'), file(9102, 'b.txt')]} />);
    });
    expect(result?.container.textContent).toContain('b.txt');
  });
});
