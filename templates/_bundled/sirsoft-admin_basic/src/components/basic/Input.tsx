import React, { useRef, useCallback, useState, useEffect, forwardRef, useImperativeHandle } from 'react';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
}

/**
 * 기본 입력 필드 컴포넌트
 *
 * IME(한글 등) 조합 중에는 외부 onChange 이벤트를 발생시키지 않습니다.
 * 내부 로컬 상태를 사용하여 입력을 처리하고, 조합이 완료되면 외부에 알립니다.
 *
 * forwardRef를 사용하여 외부에서 ref로 input 요소에 접근할 수 있습니다.
 * 예: indeterminate 상태 설정 시 ref.current.indeterminate = true
 */
export const Input = forwardRef<HTMLInputElement, InputProps>(({
  label,
  error,
  className = '',
  onChange,
  onKeyPress,
  value,
  defaultValue,
  type = 'text',
  checked,
  defaultChecked,
  ...props
}, ref) => {
  // radio/checkbox 타입인지 확인
  const isCheckableType = type === 'radio' || type === 'checkbox';

  // IME 조합 중인지 추적 (text input에만 필요)
  const isComposingRef = useRef(false);
  // input 요소 참조
  const internalRef = useRef<HTMLInputElement>(null);

  // 외부 ref와 내부 ref를 연결
  useImperativeHandle(ref, () => internalRef.current as HTMLInputElement);

  // 내부 로컬 값 (IME 조합 중에도 화면에 표시됨) - text input에만 사용
  const [localValue, setLocalValue] = useState<string>(
    (value as string) ?? (defaultValue as string) ?? ''
  );

  // 외부 value prop이 변경되면 로컬 값도 동기화 (text input에만 적용)
  // 단, 사용자가 입력 중(포커스 상태)이면 동기화 건너뜀 (debounce 대기 중 stale value 플리커 방지)
  useEffect(() => {
    if (!isCheckableType && !isComposingRef.current && value !== undefined) {
      const isFocused = internalRef.current && document.activeElement === internalRef.current;
      if (isFocused) {
        return;
      }
      setLocalValue(value as string);
    }
  }, [value, isCheckableType]);

  const handleCompositionStart = useCallback(() => {
    isComposingRef.current = true;
  }, []);

  const handleCompositionEnd = useCallback(
    (e: React.CompositionEvent<HTMLInputElement>) => {
      isComposingRef.current = false;
      // 조합 완료 후 onChange 이벤트 발생
      if (onChange) {
        // 현재 input의 실제 값을 사용
        const currentValue = (e.target as HTMLInputElement).value;
        setLocalValue(currentValue);

        // 새로운 ChangeEvent 생성
        const changeEvent = new Event('change', { bubbles: true }) as unknown as React.ChangeEvent<HTMLInputElement>;
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
    (e: React.ChangeEvent<HTMLInputElement>) => {
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

  const handleKeyPress = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      // IME 조합 중이면 keypress 무시 (Enter 등)
      if (isComposingRef.current) {
        return;
      }
      if (onKeyPress) {
        onKeyPress(e);
      }
    },
    [onKeyPress]
  );

  // HTML 표준 속성이 아닌 것들을 필터링 (템플릿 엔진 내부 속성)
  const { loadingactions, formdata, ...validProps } = props as any;

  // radio/checkbox: checked 속성 사용
  // text input: value 속성 사용 (IME 처리 포함)
  if (isCheckableType) {
    return (
      <input
        ref={internalRef}
        type={type}
        className={className}
        onChange={onChange}
        checked={checked}
        defaultChecked={defaultChecked}
        value={value}
        {...validProps}
      />
    );
  }

  // text input: controlled/uncontrolled 구분
  const inputProps = value !== undefined
    ? { value: localValue }
    : { defaultValue };

  return (
    <input
      ref={internalRef}
      type={type}
      className={className}
      onChange={handleChange}
      onKeyPress={handleKeyPress}
      onCompositionStart={handleCompositionStart}
      onCompositionEnd={handleCompositionEnd}
      {...inputProps}
      {...validProps}
    />
  );
});

// displayName 설정 (React DevTools에서 컴포넌트 이름 표시)
Input.displayName = 'Input';