// e2e:allow편집기 passthrough 결함#2 수정(data-editor-*/id 를 숨겨진 input 대신 시각적 루트 div 로 라우팅). 라이브 검증은 Chrome MCP T1~T7 매트릭스(에디터 추가/선택/저장 200/reload/게스트 사용자화면), 단위 회귀는 FileInput.test.tsx passthrough describe.
import React, { forwardRef, useRef, useCallback, useState } from 'react';

export interface FileInputProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type' | 'onChange' | 'onError'> {
  /** 허용되는 파일 확장자 (예: ".zip,.pdf") */
  accept?: string;
  /** 최대 파일 크기 (MB) */
  maxSize?: number;
  /** 파일 선택 시 콜백 ({ target: { value } } 패턴) */
  onChange?: (event: { target: { value: File | null; name: string } }) => void;
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

  // 레이아웃 편집기 식별 표식(data-editor-*) 과 DOM id 는 시각적 루트(<div>)에 부착해야
  // 캔버스에서 선택/편집이 닿는다. basic 컴포넌트라 DynamicRenderer 가 개별 data-editor-*
  // 키를 props 로 주입하는데, 숨겨진 <input class="hidden"> 는 0×0 이라 식별 표식이 닿아도
  // 선택 불가. 식별/표식 키만 분리해 루트로 보낸다.
  const rootEditorProps: Record<string, unknown> = {};
  const inputProps: Record<string, unknown> = {};
  Object.entries(props as Record<string, unknown>).forEach(([key, val]) => {
    if (key.startsWith('data-editor-') || key === 'id' || key === 'onMouseMove' || key === 'onMouseLeave') {
      rootEditorProps[key] = val;
    } else {
      inputProps[key] = val;
    }
  });

  const handleClick = useCallback(() => {
    if (!disabled && inputRef.current) {
      inputRef.current.click();
    }
  }, [disabled, inputRef]);

  const handleChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] || null;

    if (!file) {
      setFileName('');
      onChange?.({ target: { value: null, name: '' } });
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
    onChange?.({ target: { value: file, name: file.name } });
  }, [maxSize, onChange, onError, inputRef]);

  const handleClear = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    if (inputRef.current) {
      inputRef.current.value = '';
    }
    setFileName('');
    onChange?.({ target: { value: null, name: '' } });
  }, [onChange, inputRef]);

  return (
    <div className={`flex items-center gap-2 ${className}`} {...rootEditorProps}>
      <input
        ref={inputRef}
        type="file"
        accept={accept}
        onChange={handleChange}
        disabled={disabled}
        className="hidden"
        {...inputProps}
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