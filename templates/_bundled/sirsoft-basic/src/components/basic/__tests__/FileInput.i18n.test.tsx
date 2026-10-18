/**
 * @file FileInput.i18n.test.tsx
 * @description 파일 고르개 글자 다국어 (7.1.0 영역 J D-J8, 관측 O-F3)
 *
 * 지우기 단추 이름(「Clear file」)·용량 초과 문구·기본 단추/안내 글자가 언어와 무관하게 영어로 박혀 있어
 * 한국어 관리자 화면에서도 영어로 읽혔다. 번역 키로 바꾸고, 엔진 밖에서만 종전 영어를 대체 문구로 쓴다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { FileInput } from '../FileInput';

const DICT: Record<string, string> = {
  'common.file_input.clear': '파일 지우기',
  'common.file_input.browse': '파일 선택',
  'common.file_input.no_file': '선택된 파일 없음',
};

describe('FileInput 다국어', () => {
  beforeEach(() => {
    (window as any).G7Core = {
      t: (key: string, params?: Record<string, unknown>) =>
        key === 'common.file_input.too_large' ? `파일 크기가 ${params?.size}MB 를 넘습니다.` : (DICT[key] ?? key),
    };
  });
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario control=file_input
   * @effects file_input_texts_follow_locale
   */
  it('기본 단추·안내 글자와 지우기 단추 이름이 번역 문구다', () => {
    const { container } = render(<FileInput onChange={vi.fn()} />);
    expect(screen.getByText('파일 선택')).toBeTruthy();
    expect(screen.getByText('선택된 파일 없음')).toBeTruthy();
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['a'], 'a.zip')] } });
    expect(screen.getByRole('button', { name: '파일 지우기' })).toBeTruthy();
  });

  /**
   * @scenario control=file_input
   * @effects file_input_texts_follow_locale
   */
  it('용량 초과 문구가 번역 문구다', () => {
    const onError = vi.fn();
    const { container } = render(<FileInput maxSize={1} onError={onError} onChange={vi.fn()} />);
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    const big = new File([new Uint8Array(2 * 1024 * 1024)], 'big.zip');
    fireEvent.change(input, { target: { files: [big] } });
    expect(onError).toHaveBeenCalledWith('파일 크기가 1MB 를 넘습니다.');
  });
});
