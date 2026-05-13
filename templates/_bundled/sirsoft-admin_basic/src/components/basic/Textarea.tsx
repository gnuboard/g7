import React, { useRef, useCallback, useState, useEffect } from 'react';

export interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  error?: string;
}

/**
 * 기본 텍스트 영역 컴포넌트
 *
 * IME(한글 등) 조합 중에는 외부 onChange 이벤트를 발생시키지 않습니다.
 * 내부 로컬 상태를 사용하여 입력을 처리하고, 조합이 완료되면 외부에 알립니다.
 */
export const Textarea: React.FC<TextareaProps> = ({
  label,
  error,
  className = '',
  onChange,
  value,
  defaultValue,
  ...props
}) => {
  // IME 조합 중인지 추적
  const isComposingRef = useRef(false);
  // textarea 요소 참조
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  // 내부 로컬 값 (IME 조합 중에도 화면에 표시됨)
  const [localValue, setLocalValue] = useState<string>(
    (value as string) ?? (defaultValue as string) ?? ''
  );

  // 외부 value prop이 변경되면 로컬 값도 동기화
  useEffect(() => {
    if (!isComposingRef.current && value !== undefined) {
      setLocalValue(value as string);
    }
  }, [value]);

  const handleCompositionStart = useCallback(() => {
    isComposingRef.current = true;
  }, []);

  const handleCompositionEnd = useCallback(
    (e: React.CompositionEvent<HTMLTextAreaElement>) => {
      isComposingRef.current = false;
      // 조합 완료 후 onChange 이벤트 발생
      if (onChange) {
        // 현재 textarea의 실제 값을 사용
        const currentValue = (e.target as HTMLTextAreaElement).value;
        setLocalValue(currentValue);

        // 새로운 ChangeEvent 생성
        const changeEvent = new Event('change', { bubbles: true }) as unknown as React.ChangeEvent<HTMLTextAreaElement>;
        Object.defineProperty(changeEvent, 'target', {
          writable: false,
          value: { value: currentValue },
        });
        Object.defineProperty(changeEvent, 'currentTarget', {
          writable: false,
          value: { value: currentValue },
        });

        onChange(changeEvent);
      }
    },
    [onChange]
  );

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLTextAreaElement>) => {
      const newValue = e.target.value;
      // 항상 로컬 값은 업데이트 (화면에 입력이 보이도록)
      setLocalValue(newValue);

      // IME 조합 중이면 외부 onChange를 호출하지 않음
      if (isComposingRef.current) {
        return;
      }
      if (onChange) {
        onChange(e);
      }
    },
    [onChange]
  );

  // HTML 표준 속성이 아닌 것들을 필터링 (템플릿 엔진 내부 속성)
  const { loadingactions, formdata, ...validProps } = props as any;

  // controlled/uncontrolled 구분
  const textareaValueProps = value !== undefined
    ? { value: localValue }
    : { defaultValue };

  return (
    <textarea
      ref={textareaRef}
      className={className}
      onChange={handleChange}
      onCompositionStart={handleCompositionStart}
      onCompositionEnd={handleCompositionEnd}
      {...textareaValueProps}
      {...validProps}
    />
  );
};
