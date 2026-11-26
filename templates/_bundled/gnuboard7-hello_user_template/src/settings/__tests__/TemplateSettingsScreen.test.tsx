// e2e:allow 학습용 최소 설정 화면 단위 잠금 — 브라우저 실측은 이력 문서 매트릭스(업로드·저장·새로고침·기본값으로)
/**
 * @file TemplateSettingsScreen.test.tsx
 * @description 학습용 최소 설정 화면의 첨부 칸을 잠근다.
 *
 * - 파일을 고르면 저장 단추를 풀고, 저장 신호를 받아서야 올린 뒤 값을 바꾼다
 * - 이 화면이 그리지 못하는 칸 종류는 입력칸으로 떨어뜨리지 않고 안내만 보인다
 *
 * 시나리오: case=settings_layout
 *
 * 효과: settings_layout_saves_template_settings, settings_save_stops_on_upload_failure
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { TemplateSettingsScreen } from '../TemplateSettingsScreen';

const data = {
  name: 'Hello',
  is_active: true,
  abilities: { can_update: true, can_reset: true },
  sections: [{ key: 'branding', label: '브랜딩', fields: [
    { key: 'favicon', type: 'attachments', label: '파비콘', collection: 'template_settings.hello.branding.favicon', accept: '.png' },
    { key: 'accent', type: 'enum', label: '포인트 색' },
  ] }],
};

describe('학습용 설정 화면', () => {
  let listener: (() => Promise<unknown>) | null = null;
  const post = vi.fn();

  beforeEach(() => {
    listener = null;
    post.mockReset();
    (window as any).G7Core = {
      api: { post },
      componentEvent: {
        on: (_event: string, callback: () => Promise<unknown>) => {
          listener = callback;

          return () => undefined;
        },
      },
    };
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('파일을 고르면 onDirty, 저장 신호에 올리고 값을 바꾼다 — 칸 컬렉션으로 관리자 첨부 API 에 보낸다', async () => {
    post.mockResolvedValue({ data: { id: 41, hash: 'abc', original_filename: 'a.png' } });
    const onDirty = vi.fn();
    const onChange = vi.fn();
    render(<TemplateSettingsScreen data={data} value={{ branding: { favicon: [] } }} activeSection="branding" readOnly={false} onDirty={onDirty} onChange={onChange} />);

    const file = new File(['x'], 'a.png', { type: 'image/png' });
    fireEvent.change(screen.getByLabelText('파비콘'), { target: { files: [file] } });
    expect(onDirty).toHaveBeenCalledTimes(1);
    expect(post).not.toHaveBeenCalled();

    await act(async () => {
      await listener?.();
    });

    expect(post.mock.calls[0][0]).toBe('/api/admin/attachments');
    expect((post.mock.calls[0][1] as FormData).get('collection')).toBe('template_settings.hello.branding.favicon');
    expect(onChange).toHaveBeenCalledWith({ branding: { favicon: [{ id: 41, hash: 'abc', original_filename: 'a.png' }] } });
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('고른 파일이 없으면 저장 신호에 아무것도 올리지 않고, 업로드 실패는 서버 문구로 알린다', async () => {
    const onUploadError = vi.fn();
    render(<TemplateSettingsScreen data={data} value={{}} activeSection="branding" readOnly={false} onUploadError={onUploadError} />);

    await act(async () => {
      await listener?.();
    });
    expect(post).not.toHaveBeenCalled();

    post.mockRejectedValue({ response: { data: { message: '형식이 맞지 않습니다' } } });
    fireEvent.change(screen.getByLabelText('파비콘'), { target: { files: [new File(['x'], 'a.png')] } });
    await act(async () => {
      await listener?.();
    });
    expect(onUploadError).toHaveBeenCalledWith('형식이 맞지 않습니다');
  });

  // @scenario case=settings_layout
  // @effects settings_save_stops_on_upload_failure
  it('저장 신호의 결과로 실패 수를 돌려준다 — 레이아웃이 실패면 PUT 을 멈추고 실패 문구를 남긴다', async () => {
    render(<TemplateSettingsScreen data={data} value={{}} activeSection="branding" readOnly={false} />);

    // 고른 파일이 없으면 실패 0
    let result: any;
    await act(async () => {
      result = await listener?.();
    });
    expect(result).toEqual({ failedCount: 0 });

    // 서버가 거절하면 실패 1 과 서버 문구
    post.mockRejectedValueOnce({ response: { data: { message: '형식이 맞지 않습니다' } } });
    fireEvent.change(screen.getByLabelText('파비콘'), { target: { files: [new File(['x'], 'fake.png')] } });
    await act(async () => {
      result = await listener?.();
    });
    expect(result).toEqual({ failedCount: 1, message: '형식이 맞지 않습니다' });
    // 실패한 파일은 고른 채로 남는다 — 다시 저장하면 다시 올리고, 올리면 실패 0
    post.mockResolvedValueOnce({ data: { id: 7, hash: 'h', original_filename: 'fake.png' } });
    await act(async () => {
      result = await listener?.();
    });
    expect(result).toEqual({ failedCount: 0 });
    expect(post).toHaveBeenCalledTimes(2);
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('이 화면이 그리지 못하는 칸 종류는 입력칸 없이 안내만 보이고, 읽기 전용이면 파일을 고를 수 없다', () => {
    const { rerender } = render(<TemplateSettingsScreen data={data} value={{}} activeSection="branding" readOnly={false} />);

    expect(screen.getByText('This field type is not supported by this screen.')).toBeTruthy();
    expect(document.querySelectorAll('input:not([type="file"])').length).toBe(0);

    rerender(<TemplateSettingsScreen data={data} value={{}} activeSection="branding" readOnly />);
    expect(document.querySelector('input[type="file"]')).toBeNull();
    expect(screen.getByTestId('template-settings-read-only')).toBeTruthy();
    expect(screen.queryByTestId('template-settings-footer')).toBeNull();
  });
});
