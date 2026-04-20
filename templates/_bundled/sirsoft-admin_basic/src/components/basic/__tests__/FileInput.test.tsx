import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { FileInput } from '../FileInput';

describe('FileInput 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('기본 버튼 텍스트와 placeholder가 렌더링된다', () => {
      render(<FileInput />);
      expect(screen.getByText('Browse')).toBeTruthy();
      expect(screen.getByText('No file selected')).toBeTruthy();
    });

    it('커스텀 버튼 텍스트가 적용된다', () => {
      render(<FileInput buttonText="파일 선택" />);
      expect(screen.getByText('파일 선택')).toBeTruthy();
    });

    it('커스텀 placeholder가 적용된다', () => {
      render(<FileInput placeholder="파일을 선택하세요" />);
      expect(screen.getByText('파일을 선택하세요')).toBeTruthy();
    });

    it('숨겨진 file input이 렌더링된다', () => {
      const { container } = render(<FileInput />);
      const fileInput = container.querySelector('input[type="file"]');
      expect(fileInput).toBeTruthy();
      expect(fileInput?.className).toContain('hidden');
    });
  });

  describe('파일 선택', () => {
    it('버튼 클릭 시 file input이 트리거된다', () => {
      const { container } = render(<FileInput />);
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;
      const clickSpy = vi.spyOn(fileInput, 'click');

      const button = screen.getByText('Browse');
      fireEvent.click(button);

      expect(clickSpy).toHaveBeenCalled();
    });

    it('파일 선택 시 onChange 콜백이 호출된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<FileInput onChange={handleChange} />);
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;

      const file = new File(['test content'], 'test.zip', { type: 'application/zip' });
      Object.defineProperty(fileInput, 'files', {
        value: [file],
        writable: false,
      });

      fireEvent.change(fileInput);

      expect(handleChange).toHaveBeenCalledWith({ target: { value: file, name: 'test.zip' } });
    });

    it('파일 선택 시 파일명이 표시된다', () => {
      const { container } = render(<FileInput />);
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;

      const file = new File(['test content'], 'module.zip', { type: 'application/zip' });
      Object.defineProperty(fileInput, 'files', {
        value: [file],
        writable: false,
      });

      fireEvent.change(fileInput);

      expect(screen.getByText('module.zip')).toBeTruthy();
    });
  });

  describe('파일 크기 검증', () => {
    it('파일 크기가 maxSize를 초과하면 onError가 호출된다', () => {
      const handleError = vi.fn();
      const handleChange = vi.fn();
      const { container } = render(
        <FileInput maxSize={1} onChange={handleChange} onError={handleError} />
      );
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;

      // 2MB 파일 생성 (maxSize 1MB 초과)
      const largeContent = new ArrayBuffer(2 * 1024 * 1024);
      const file = new File([largeContent], 'large.zip', { type: 'application/zip' });
      Object.defineProperty(fileInput, 'files', {
        value: [file],
        writable: false,
      });

      fireEvent.change(fileInput);

      expect(handleError).toHaveBeenCalledWith('File size exceeds 1MB limit');
      expect(handleChange).not.toHaveBeenCalled();
    });

    it('파일 크기가 maxSize 이내이면 정상 처리된다', () => {
      const handleError = vi.fn();
      const handleChange = vi.fn();
      const { container } = render(
        <FileInput maxSize={5} onChange={handleChange} onError={handleError} />
      );
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;

      // 1MB 파일 생성 (maxSize 5MB 이내)
      const content = new ArrayBuffer(1 * 1024 * 1024);
      const file = new File([content], 'small.zip', { type: 'application/zip' });
      Object.defineProperty(fileInput, 'files', {
        value: [file],
        writable: false,
      });

      fireEvent.change(fileInput);

      expect(handleError).not.toHaveBeenCalled();
      expect(handleChange).toHaveBeenCalledWith({ target: { value: file, name: 'small.zip' } });
    });
  });

  describe('파일 초기화', () => {
    it('파일 선택 후 클리어 버튼이 표시된다', () => {
      const { container } = render(<FileInput />);
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;

      const file = new File(['test'], 'test.zip', { type: 'application/zip' });
      Object.defineProperty(fileInput, 'files', {
        value: [file],
        writable: false,
      });

      fireEvent.change(fileInput);

      const clearButton = screen.getByLabelText('Clear file');
      expect(clearButton).toBeTruthy();
    });

    it('클리어 버튼 클릭 시 파일이 초기화된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<FileInput onChange={handleChange} />);
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;

      const file = new File(['test'], 'test.zip', { type: 'application/zip' });
      Object.defineProperty(fileInput, 'files', {
        value: [file],
        writable: false,
      });

      fireEvent.change(fileInput);
      expect(handleChange).toHaveBeenCalledWith({ target: { value: file, name: 'test.zip' } });

      const clearButton = screen.getByLabelText('Clear file');
      fireEvent.click(clearButton);

      expect(handleChange).toHaveBeenLastCalledWith({ target: { value: null, name: '' } });
      expect(screen.getByText('No file selected')).toBeTruthy();
    });
  });

  describe('비활성화 상태', () => {
    it('disabled 상태에서 버튼이 비활성화된다', () => {
      render(<FileInput disabled />);
      const button = screen.getByText('Browse');
      expect(button).toHaveProperty('disabled', true);
    });

    it('disabled 상태에서 file input이 비활성화된다', () => {
      const { container } = render(<FileInput disabled />);
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;
      expect(fileInput.disabled).toBe(true);
    });

    it('disabled 상태에서 클리어 버튼이 표시되지 않는다', () => {
      const { container } = render(<FileInput disabled />);
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;

      const file = new File(['test'], 'test.zip', { type: 'application/zip' });
      Object.defineProperty(fileInput, 'files', {
        value: [file],
        writable: false,
      });

      fireEvent.change(fileInput);

      const clearButton = screen.queryByLabelText('Clear file');
      expect(clearButton).toBeNull();
    });
  });

  describe('accept 속성', () => {
    it('accept 속성이 file input에 적용된다', () => {
      const { container } = render(<FileInput accept=".zip,.tar.gz" />);
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;
      expect(fileInput.accept).toBe('.zip,.tar.gz');
    });
  });

  describe('className 속성', () => {
    it('className이 wrapper에 적용된다', () => {
      const { container } = render(<FileInput className="custom-class" />);
      const wrapper = container.firstChild as HTMLElement;
      expect(wrapper.className).toContain('custom-class');
    });
  });

  describe('빈 파일 선택', () => {
    it('파일 선택 없이 change 이벤트 발생 시 onChange에 null이 전달된다', () => {
      const handleChange = vi.fn();
      const { container } = render(<FileInput onChange={handleChange} />);
      const fileInput = container.querySelector('input[type="file"]') as HTMLInputElement;

      Object.defineProperty(fileInput, 'files', {
        value: [],
        writable: false,
      });

      fireEvent.change(fileInput);

      expect(handleChange).toHaveBeenCalledWith({ target: { value: null, name: '' } });
    });
  });
});
