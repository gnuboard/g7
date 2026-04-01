import React, { forwardRef, useRef, useCallback, useState } from 'react';

export interface FileInputProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type' | 'onChange' | 'onError'> {
  /** 허용되는 파일 확장자 (예: ".zip,.pdf") */
  accept?: string;
  /** 최대 파일 크기 (MB) */
  maxSize?: number;
  /** 파일 선택 시 콜백 */
  onChange?: (file: File | null) => void;
  /** 에러 발생 시 콜백 */
  onError?: (error: string) => void;
  /** 버튼 텍스트 */
  buttonText?: string;
  /** 빈 상태 텍스트 */
  placeholder?: string;
}

/**
 * 파일 업로드 입력 컴포넌트
 *
 * ZIP 파일 등의 파일 업로드를 처리하며, 파일 크기 검증을 지원합니다.
 */
export const FileInput = forwardRef<HTMLInputElement, FileInputProps>(({
  accept,
  maxSize,
  onChange,
  onError,
  buttonText = 'Browse',
  placeholder = 'No file selected',
  className = '',
  disabled,
  ...props
}, ref) => {
  const internalRef = useRef<HTMLInputElement>(null);
  const inputRef = (ref as React.RefObject<HTMLInputElement>) || internalRef;
  const [fileName, setFileName] = useState<string>('');

  const handleClick = useCallback(() => {
    if (!disabled && inputRef.current) {
      inputRef.current.click();
    }
  }, [disabled, inputRef]);

  const handleChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] || null;

    if (!file) {
      setFileName('');
      onChange?.(null);
      return;
    }

    // 파일 크기 검증 (MB 단위)
    if (maxSize && file.size > maxSize * 1024 * 1024) {
      const errorMsg = `File size exceeds ${maxSize}MB limit`;
      onError?.(errorMsg);
      // 입력 초기화
      if (inputRef.current) {
        inputRef.current.value = '';
      }
      setFileName('');
      return;
    }

    setFileName(file.name);
    onChange?.(file);
  }, [maxSize, onChange, onError, inputRef]);

  const handleClear = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    if (inputRef.current) {
      inputRef.current.value = '';
    }
    setFileName('');
    onChange?.(null);
  }, [onChange, inputRef]);

  return (
    <div className={`flex items-center gap-2 ${className}`}>
      <input
        ref={inputRef}
        type="file"
        accept={accept}
        onChange={handleChange}
        disabled={disabled}
        className="hidden"
        {...props}
      />
      <button
        type="button"
        onClick={handleClick}
        disabled={disabled}
        className="px-4 py-2 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
      >
        {buttonText}
      </button>
      <span className="flex-1 text-sm text-gray-600 dark:text-gray-400 truncate">
        {fileName || placeholder}
      </span>
      {fileName && !disabled && (
        <button
          type="button"
          onClick={handleClear}
          className="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
          aria-label="Clear file"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      )}
    </div>
  );
});

FileInput.displayName = 'FileInput';