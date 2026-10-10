/**
 * 관리자 템플릿 FileUploader — 항목을 지우거나 파일을 고른 뒤 키보드 초점이 머무는 자리.
 *
 * 7.1.0 전수 실측(O-M3-1, O-G3-2, 결정 P-6):
 * - 키보드로 항목의 「삭제」를 누르면 그 단추가 목록에서 사라지며 초점이 문서 맨 위(BODY)로 빠졌다.
 *   다음 항목(없으면 이전 항목, 둘 다 없으면 「추가」 자리)으로 초점을 옮긴다. 항목이 모두 사라지면
 *   목록 대신 드롭존이 「추가」 자리를 맡는다.
 * - 빈 드롭존에서 키보드로 파일을 고르면 드롭존이 목록으로 바뀌며 초점이 BODY 로 빠졌다.
 *   「추가」 타일(없으면 새로 추가된 첫 항목)로 초점을 돌린다.
 *
 * @scenario-manifest tests/scenarios/file-uploader-focus-recovery.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// SortableThumbnailItem·utils 가 모듈 스코프에서 G7Core 를 잡으므로 import 전에 둔다
vi.hoisted(() => {
  (window as any).G7Core = {
    ...((window as any).G7Core ?? {}),
    t: (key: string) => `T(${key})`,
    api: { get: () => Promise.resolve(null), delete: () => Promise.resolve({}) },
    toast: { error: () => {}, success: () => {} },
    componentEvent: { on: () => () => {}, emit: async () => [] },
    createLogger: () => ({ log: () => {}, warn: () => {}, error: () => {} }),
  };
});

import { FileUploader } from '../FileUploader';

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

/**
 * 기존 첨부(이미지 아님)를 만든다. ID 는 테스트마다 달리 준다 — 지운 첨부 ID 는 모듈 전역 삭제 기록에
 * 남아 다음 테스트의 initialFiles 에서 걸러진다.
 *
 * @param id 첨부 ID
 * @param name 파일 이름
 * @returns 첨부 객체
 */
function attachment(id: number, name: string) {
  return {
    id,
    hash: `hash_${id}`,
    original_filename: name,
    mime_type: 'text/plain',
    size: 8,
    size_formatted: '8 B',
    download_url: null,
    order: id,
    is_image: false,
  } as any;
}

/**
 * 초점이 있는 단추가 속한 항목의 파일 이름을 읽는다(항목 밖이면 null).
 *
 * @returns 파일 이름 또는 null
 */
function focusedItemName(): string | null {
  const item = (document.activeElement as HTMLElement | null)?.closest('.group');
  return item?.querySelector('p[title]')?.getAttribute('title') ?? null;
}

/**
 * 파일 이름이 name 인 항목의 「삭제」 단추를 찾는다.
 *
 * @param name 파일 이름
 * @returns 삭제 단추
 */
function deleteButtonOf(name: string): HTMLElement {
  const item = screen.getByTitle(name).closest('.group') as HTMLElement;
  return item.querySelector('button[aria-label="T(common.delete)"]') as HTMLElement;
}

// 빈 목록도 고정 참조로 준다 — 생략하면 기본값 `[]` 가 렌더마다 새 배열이라 initialFiles 동기화가 끝없이 돈다
const NO_FILES: any[] = [];

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('[case:file-uploader-focus-recovery] FileUploader (sirsoft-admin_basic) 삭제·파일 선택 뒤 초점', () => {
  /**
   * @scenario case=remove_middle
   * @effects focus_moves_to_next_item
   */
  it('가운데 항목을 키보드로 지우면 초점이 다음 항목의 「삭제」로 간다', async () => {
    const user = userEvent.setup();
    render(
      <FileUploader
        initialFiles={[attachment(101, 'a.txt'), attachment(102, 'b.txt'), attachment(103, 'c.txt')]}
        autoUpload={false}
      />
    );

    deleteButtonOf('b.txt').focus();
    await user.keyboard('{Enter}');

    await waitFor(() => expect(screen.queryByTitle('b.txt')).toBeNull());
    expect(document.activeElement).not.toBe(document.body);
    expect(focusedItemName()).toBe('c.txt');
    expect(document.activeElement?.getAttribute('aria-label')).toBe('T(common.delete)');
  });

  /**
   * @scenario case=remove_last
   * @effects focus_moves_to_previous_item
   */
  it('마지막 항목을 지우면 초점이 이전 항목의 「삭제」로 간다', async () => {
    const user = userEvent.setup();
    render(
      <FileUploader
        initialFiles={[attachment(104, 'a.txt'), attachment(105, 'b.txt')]}
        autoUpload={false}
        maxFiles={2}
      />
    );

    deleteButtonOf('b.txt').focus();
    await user.keyboard(' ');

    await waitFor(() => expect(screen.queryByTitle('b.txt')).toBeNull());
    expect(focusedItemName()).toBe('a.txt');
    expect(document.activeElement?.getAttribute('aria-label')).toBe('T(common.delete)');
  });

  /**
   * @scenario case=remove_only
   * @effects focus_moves_to_add_area
   */
  it('하나뿐인 항목을 지우면 초점이 「추가」 자리(빈 드롭존)로 간다', async () => {
    const user = userEvent.setup();
    render(<FileUploader initialFiles={[attachment(106, 'a.txt')]} autoUpload={false} />);

    deleteButtonOf('a.txt').focus();
    await user.keyboard('{Enter}');

    await waitFor(() => expect(screen.queryByTitle('a.txt')).toBeNull());
    const dropZone = screen.getByRole('button', { name: /T\(attachment\.drop_or_click\)/ });
    expect(document.activeElement).toBe(dropZone);
  });

  /**
   * @scenario case=remove_after_confirm
   * @effects focus_moves_to_next_item
   */
  it('삭제 확인 창을 거쳐 지워도 초점이 다음 항목으로 간다', async () => {
    const user = userEvent.setup();
    render(
      <FileUploader
        initialFiles={[attachment(107, 'a.txt'), attachment(108, 'b.txt')]}
        autoUpload={false}
        confirmBeforeRemove
      />
    );

    deleteButtonOf('a.txt').focus();
    await user.keyboard('{Enter}');
    // 확인 창의 「삭제」 단추(항목 단추와 같은 이름) — 창 안에서 찾는다
    const dialog = await screen.findByRole('dialog');
    const dialogConfirm = Array.from(dialog.querySelectorAll('button')).find((b) => b.textContent?.includes('T(common.delete)')) as HTMLElement;
    dialogConfirm.focus();
    await user.keyboard('{Enter}');

    await waitFor(() => expect(screen.queryByTitle('a.txt')).toBeNull());
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(focusedItemName()).toBe('b.txt');
  });

  /**
   * 실제 서버처럼 삭제 요청이 늦게 끝나면 확인 창의 「삭제」 단추가 처리 중으로 잠기며 초점이 먼저 BODY 로 빠진다.
   * 그 사이에도 지울 항목의 자리를 잊지 않고, 삭제가 끝난 뒤 다음 항목으로 초점을 옮긴다(브라우저 재실측에서 드러남).
   *
   * @scenario case=remove_after_slow_confirm
   * @effects focus_moves_to_next_item
   */
  it('삭제 요청이 늦게 끝나 확인 창 단추가 잠기는 동안 초점이 빠져도, 끝난 뒤 다음 항목으로 간다', async () => {
    const user = userEvent.setup();
    const g7 = (window as any).G7Core;
    const originalDelete = g7.api.delete;
    let finish: (value: unknown) => void = () => {};
    g7.api.delete = () => new Promise((resolve) => { finish = resolve; });
    try {
      render(
        <FileUploader
          initialFiles={[attachment(112, 'a.txt'), attachment(113, 'b.txt')]}
          autoUpload={false}
          confirmBeforeRemove
        />
      );

      deleteButtonOf('a.txt').focus();
      await user.keyboard('{Enter}');
      const dialog = await screen.findByRole('dialog');
      const dialogConfirm = Array.from(dialog.querySelectorAll('button')).find((b) => b.textContent?.includes('T(common.delete)')) as HTMLElement;
      dialogConfirm.focus();
      await user.keyboard('{Enter}');
      // 처리 중 — 브라우저는 잠긴 단추의 초점을 BODY 로 떨어뜨린다. jsdom 은 잠긴 단추의 초점을 놓지 않으므로,
      // 창 안의 임시 요소로 초점을 옮겼다가 그 요소를 빼서 같은 상태(초점이 BODY, 지울 항목은 아직 남음)를 만든다.
      await new Promise((resolve) => setTimeout(resolve, 10));
      expect(dialogConfirm.hasAttribute('disabled')).toBe(true);
      const temp = document.createElement('button');
      dialog.appendChild(temp);
      temp.focus();
      temp.remove();
      await new Promise((resolve) => setTimeout(resolve, 10));
      expect(document.activeElement).toBe(document.body);
      expect(screen.getByTitle('a.txt')).toBeTruthy();

      finish({});
      await waitFor(() => expect(screen.queryByTitle('a.txt')).toBeNull());
      await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
      expect(focusedItemName()).toBe('b.txt');
    } finally {
      g7.api.delete = originalDelete;
    }
  });

  /**
   * @scenario case=pointer_elsewhere
   * @effects focus_not_stolen
   */
  it('항목 단추를 쓴 뒤 업로더 밖 빈 곳을 누르면, 그 뒤 목록이 바뀌어도 초점을 끌어오지 않는다', async () => {
    const user = userEvent.setup();
    render(
      <>
        <FileUploader initialFiles={[attachment(114, 'a.txt'), attachment(115, 'b.txt')]} autoUpload={false} />
        <p>outside text</p>
      </>
    );

    deleteButtonOf('a.txt').focus();
    await user.click(screen.getByText('outside text'));
    expect(document.activeElement).toBe(document.body);
    fireEvent.click(deleteButtonOf('a.txt'));
    await waitFor(() => expect(screen.queryByTitle('a.txt')).toBeNull());

    expect(document.activeElement).toBe(document.body);
  });

  /**
   * @scenario case=pick_from_dropzone
   * @effects focus_moves_to_add_tile
   */
  it('빈 드롭존에서 파일을 고르면 초점이 「추가」 타일로 간다', async () => {
    const { container } = render(<FileUploader initialFiles={NO_FILES} autoUpload={false} maxFiles={5} />);

    const dropZone = screen.getByRole('button', { name: /T\(attachment\.drop_or_click\)/ });
    dropZone.focus();
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, {
      target: { files: [new File(['x'], 'n1.txt', { type: 'text/plain' }), new File(['y'], 'n2.txt', { type: 'text/plain' })] },
    });

    await screen.findByTitle('n1.txt');
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'T(common.add)' }));
  });

  /**
   * @scenario case=pick_fills_limit
   * @effects focus_moves_to_first_new_item
   */
  it('「추가」 타일로 골라 한도가 차서 타일이 사라지면 초점이 새로 추가된 첫 항목으로 간다', async () => {
    const { container } = render(
      <FileUploader initialFiles={[attachment(109, 'a.txt')]} autoUpload={false} maxFiles={3} />
    );

    screen.getByRole('button', { name: 'T(common.add)' }).focus();
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, {
      target: { files: [new File(['x'], 'n1.txt', { type: 'text/plain' }), new File(['y'], 'n2.txt', { type: 'text/plain' })] },
    });

    await screen.findByTitle('n2.txt');
    expect(screen.queryByRole('button', { name: 'T(common.add)' })).toBeNull();
    expect(focusedItemName()).toBe('n1.txt');
  });

  /**
   * @scenario case=focus_outside
   * @effects focus_not_stolen
   */
  it('초점이 업로더 밖으로 나간 뒤에는 목록이 바뀌어도 초점을 끌어오지 않는다', async () => {
    render(
      <>
        <FileUploader initialFiles={[attachment(110, 'a.txt'), attachment(111, 'b.txt')]} autoUpload={false} />
        <button type="button">outside</button>
      </>
    );

    // 삭제 단추에 초점을 두었다가 바깥 단추로 옮긴 뒤, 초점 이동 없이 삭제가 일어나게 한다
    deleteButtonOf('a.txt').focus();
    screen.getByRole('button', { name: 'outside' }).focus();
    await new Promise((resolve) => setTimeout(resolve, 0));
    fireEvent.click(deleteButtonOf('a.txt'));
    await waitFor(() => expect(screen.queryByTitle('a.txt')).toBeNull());

    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'outside' }));
  });
});
