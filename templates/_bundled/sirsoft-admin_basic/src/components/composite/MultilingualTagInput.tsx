import React, { useState, useCallback, useMemo, useEffect, useRef } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';
import { Input } from '../basic/Input';
import { Icon } from '../basic/Icon';
import { Modal } from './Modal';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

// G7Core에서 지원 언어 목록 가져오기
const getSupportedLocales = (): string[] => {
  return (window as any).G7Core?.locale?.supported?.() ?? ['ko', 'en'];
};

// G7Core에서 현재 언어 가져오기
const getCurrentLocale = (): string => {
  return (window as any).G7Core?.locale?.current?.() ?? 'ko';
};

// 언어 코드로 언어명 가져오기 (다국어 키 사용)
const getLocaleNameByCode = (code: string): string => {
  const translatedName = t(`common.language_${code}`);
  if (translatedName === `common.language_${code}`) {
    const fallbackNames: Record<string, string> = {
      ko: '한국어',
      en: 'English',
      ja: '日本語',
      zh: '中文',
    };
    return fallbackNames[code] || code.toUpperCase();
  }
  return translatedName;
};

/** 다국어 값 객체 */
export interface MultilingualValue {
  [locale: string]: string;
}

export interface MultilingualTagInputProps {
  /** 다국어 태그 배열 [{ko: "빨강", en: "Red"}, ...] */
  value?: MultilingualValue[];
  /** 값 변경 콜백 */
  onChange?: (event: any) => void;
  /** 입력창 placeholder */
  placeholder?: string;
  /** 비활성화 여부 */
  disabled?: boolean;
  /** 새 항목 추가 가능 여부 (기본값: true) */
  creatable?: boolean;
  /** 태그 구분자 배열 (creatable 모드에서 입력 시 자동 분리, 기본값: [',']) */
  delimiters?: string[];
  /** 추가 className */
  className?: string;
  /** 폼 필드 이름 (템플릿 엔진용) */
  name?: string;
  /** 에러 메시지 */
  error?: string;
  /** 최대 태그 개수 */
  maxItems?: number;
  /**
   * 외부 모달 ID (레이아웃 JSON의 modals 섹션에 정의된 모달)
   * 지정 시 내장 모달 대신 openModal 핸들러로 외부 모달을 엽니다.
   * 외부 모달은 _global.multilingualTagEdit에서 편집 정보를 읽고,
   * 저장 시 setParentLocal()로 부모 상태를 직접 수정해야 합니다.
   */
  modalId?: string;
  /**
   * 부모 상태에서의 실제 데이터 경로 (점 표기법, 예: "ui.optionInputs.0.values")
   * modalId와 함께 사용하며, 핸들러에서 setParentLocal() 호출 시 이 경로를 사용합니다.
   * 미지정 시 name prop을 기반으로 "form.{name}" 경로를 사용합니다.
   */
  statePath?: string;
  /**
   * 컴포넌트 컨텍스트 (DynamicRenderer에서 자동 전달)
   * openModal 호출 시 부모 컨텍스트를 정확히 지정하기 위해 사용됩니다.
   * @internal
   */
  __componentContext?: {
    state: Record<string, any>;
    setState: (updates: any) => void;
  };
}

/**
 * 다국어 태그 입력 컴포넌트
 *
 * 각 태그가 다국어 값을 가지는 태그 입력 컴포넌트입니다.
 * 태그 추가/수정 시 모달을 통해 모든 언어의 값을 입력받습니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "MultilingualTagInput",
 *   "props": {
 *     "value": "{{form.optionValues}}",
 *     "placeholder": "옵션값 입력 후 Enter"
 *   },
 *   "actions": [{
 *     "type": "change",
 *     "handler": "setState",
 *     "params": { "form.optionValues": "{{$event.target.value}}" }
 *   }]
 * }
 */
export const MultilingualTagInput: React.FC<MultilingualTagInputProps> = ({
  value: valueProp = [],
  onChange,
  placeholder = '입력 후 Enter',
  disabled = false,
  creatable = true,
  delimiters = [','],
  className = '',
  name,
  error,
  maxItems,
  modalId,
  statePath,
  __componentContext,
}) => {
  const supportedLocales = useMemo(() => getSupportedLocales(), []);
  const defaultLocale = useMemo(() => getCurrentLocale(), []);

  // 내부 상태
  const [tags, setTags] = useState<MultilingualValue[]>(() => valueProp || []);
  const [inputValue, setInputValue] = useState('');
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingIndex, setEditingIndex] = useState<number | null>(null);
  const [editingValues, setEditingValues] = useState<MultilingualValue>({});

  const inputRef = useRef<HTMLInputElement>(null);
  const prevValueRef = useRef<MultilingualValue[] | undefined>(undefined);

  // props 변경 시 내부 상태 동기화
  useEffect(() => {
    const arraysEqual = (a: MultilingualValue[] | undefined, b: MultilingualValue[] | undefined): boolean => {
      if (!a && !b) return true;
      if (!a || !b) return false;
      if (a.length !== b.length) return false;
      return JSON.stringify(a) === JSON.stringify(b);
    };

    const normalizedValue = valueProp || [];
    if (!arraysEqual(normalizedValue, prevValueRef.current)) {
      setTags(normalizedValue);
      prevValueRef.current = normalizedValue;
    }
  }, [valueProp]);

  // 이벤트 생성 헬퍼
  const createFakeEvent = useCallback((newTags: MultilingualValue[]) => ({
    target: { name, value: newTags },
    currentTarget: { name, value: newTags },
    preventDefault: () => {},
    stopPropagation: () => {},
  }), [name]);

  // 태그의 다국어 값을 축약 형태로 표시 (예: "KO: 빨강 / EN: Red")
  const getTagLabel = useCallback((tag: MultilingualValue): string => {
    const parts: string[] = [];
    // 지원 언어 순서대로 표시
    for (const locale of supportedLocales) {
      const value = tag[locale];
      if (value?.trim()) {
        parts.push(`${locale.toUpperCase()}: ${value}`);
      }
    }
    // 값이 없으면 빈 문자열
    return parts.length > 0 ? parts.join(' / ') : '';
  }, [supportedLocales]);

  // 태그 삭제
  const handleRemoveTag = useCallback((index: number) => {
    const newTags = tags.filter((_, i) => i !== index);
    setTags(newTags);
    onChange?.(createFakeEvent(newTags));
  }, [tags, onChange, createFakeEvent]);

  // 외부 모달 열기 (modalId 사용 시)
  const openExternalModal = useCallback((editingIdx: number | null, values: MultilingualValue, currentTags: MultilingualValue[]) => {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.dispatch) return;

    // supportedLocales에 라벨 정보 포함 (외부 모달에서 사용)
    const supportedLocalesWithLabels = supportedLocales.map(code => ({
      code,
      label: getLocaleNameByCode(code),
    }));

    // _global.multilingualTagEdit에 편집 정보 설정
    G7Core.dispatch({
      handler: 'setState',
      params: {
        target: 'global',
        multilingualTagEdit: {
          fieldName: name,
          editingIndex: editingIdx,
          values: values,
          supportedLocales: supportedLocalesWithLabels,
          defaultLocale: defaultLocale,
          statePath: statePath, // 부모 상태 경로 (setParentLocal 용)
          currentTags: currentTags, // 현재 태그 배열
        },
      },
    });

    // 외부 모달 열기 (componentContext 전달로 부모 컨텍스트 정확히 지정)
    G7Core.dispatch(
      {
        handler: 'openModal',
        target: modalId,
      },
      { componentContext: __componentContext }
    );
  }, [name, supportedLocales, defaultLocale, modalId, statePath, __componentContext]);

  // 태그 클릭 (편집 모달 열기)
  const handleTagClick = useCallback((index: number) => {
    if (disabled) return;

    if (modalId) {
      // 외부 모달 사용
      openExternalModal(index, { ...tags[index] }, tags);
    } else {
      // 내장 모달 사용
      setEditingIndex(index);
      setEditingValues({ ...tags[index] });
      setIsModalOpen(true);
    }
  }, [disabled, tags, modalId, openExternalModal]);

  // 새 태그 추가 모달 열기
  const openAddModal = useCallback((initialValue: string = '') => {
    const initial: MultilingualValue = {};
    supportedLocales.forEach(locale => {
      initial[locale] = locale === defaultLocale ? initialValue : '';
    });

    if (modalId) {
      // 외부 모달 사용
      openExternalModal(null, initial, tags);
      setInputValue('');
    } else {
      // 내장 모달 사용
      setEditingIndex(null);
      setEditingValues(initial);
      setIsModalOpen(true);
      setInputValue('');
    }
  }, [supportedLocales, defaultLocale, modalId, openExternalModal, tags]);

  // 모달 저장
  const handleModalSave = useCallback(() => {
    // 최소 하나의 값이 있는지 확인
    const hasValue = Object.values(editingValues).some(v => v.trim() !== '');
    if (!hasValue) {
      setIsModalOpen(false);
      return;
    }

    let newTags: MultilingualValue[];
    if (editingIndex !== null) {
      // 기존 태그 수정
      newTags = tags.map((tag, i) => i === editingIndex ? editingValues : tag);
    } else {
      // 새 태그 추가
      newTags = [...tags, editingValues];
    }

    setTags(newTags);
    onChange?.(createFakeEvent(newTags));
    setIsModalOpen(false);
    setEditingValues({});
    setEditingIndex(null);
  }, [editingValues, editingIndex, tags, onChange, createFakeEvent]);

  // 모달 닫기
  const handleModalClose = useCallback(() => {
    setIsModalOpen(false);
    setEditingValues({});
    setEditingIndex(null);
  }, []);

  // 입력값 변경
  const handleInputChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value;

    // 구분자 감지
    const hasDelimiter = delimiters.some(d => newValue.includes(d));
    if (hasDelimiter && creatable) {
      const escapedDelimiters = delimiters.map(d => d.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
      const pattern = new RegExp(`[${escapedDelimiters.join('')}]`);
      const parts = newValue.split(pattern).map(s => s.trim()).filter(Boolean);

      if (parts.length > 0) {
        // 첫 번째 값으로 모달 열기
        openAddModal(parts[0]);
        return;
      }
    }

    setInputValue(newValue);
  }, [delimiters, creatable, openAddModal]);

  // 키 입력 처리
  const handleKeyDown = useCallback((e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && inputValue.trim() && creatable) {
      e.preventDefault();

      // 최대 개수 확인
      if (maxItems && tags.length >= maxItems) return;

      openAddModal(inputValue.trim());
    }
  }, [inputValue, creatable, maxItems, tags.length, openAddModal]);

  // 편집 모달 내 값 변경
  const handleEditingValueChange = useCallback((locale: string, value: string) => {
    setEditingValues(prev => ({ ...prev, [locale]: value }));
  }, []);

  const hasError = !!error;

  return (
    <Div className={className}>
      {/* 태그 표시 영역 */}
      <Div
        className={`flex flex-wrap gap-1.5 p-2 border rounded-lg bg-white dark:bg-gray-700 min-h-[42px] ${
          hasError
            ? 'border-red-500 dark:border-red-400'
            : 'border-gray-300 dark:border-gray-600 focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-blue-500'
        } ${disabled ? 'opacity-60 bg-gray-100 dark:bg-gray-800 cursor-not-allowed' : ''}`}
      >
        {tags.map((tag, index) => (
          <Div
            key={index}
            className="flex items-center gap-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded px-2 py-0.5 text-sm"
          >
            <Span
              className={`cursor-pointer hover:underline ${disabled ? 'cursor-not-allowed' : ''}`}
              onClick={() => handleTagClick(index)}
            >
              {getTagLabel(tag)}
            </Span>
            {!disabled && (
              <Button
                type="button"
                className="p-0 h-4 w-4 min-w-0 border-0 bg-transparent text-blue-600 dark:text-blue-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-transparent"
                onClick={(e) => {
                  e.stopPropagation();
                  handleRemoveTag(index);
                }}
              >
                <Icon name="times" className="text-xs" />
              </Button>
            )}
          </Div>
        ))}

        {/* 입력 필드 */}
        {creatable && !disabled && (!maxItems || tags.length < maxItems) && (
          <input
            ref={inputRef}
            type="text"
            value={inputValue}
            onChange={handleInputChange}
            onKeyDown={handleKeyDown}
            placeholder={tags.length === 0 ? placeholder : ''}
            disabled={disabled}
            className="flex-1 min-w-[120px] bg-transparent border-none outline-none text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"
          />
        )}
      </Div>

      {/* 에러 메시지 */}
      {error && (
        <Span className="text-xs text-red-500 dark:text-red-400 mt-1">
          {error}
        </Span>
      )}

      {/* 다국어 편집 모달 (내장 모달 - modalId가 없을 때만 렌더링) */}
      {!modalId && (
        <Modal
          isOpen={isModalOpen}
          onClose={handleModalClose}
          title={editingIndex !== null ? t('common.edit') : t('common.add')}
          width="400px"
        >
          <Div className="space-y-4 p-4">
            {supportedLocales.map(locale => (
              <Div key={locale} className="space-y-1">
                <Div className="flex items-center gap-2">
                  <Span className="text-sm font-medium text-gray-700 dark:text-gray-300 w-16">
                    {getLocaleNameByCode(locale)}
                  </Span>
                  <Span className="text-xs text-gray-400 dark:text-gray-500">
                    ({locale.toUpperCase()})
                  </Span>
                </Div>
                <Input
                  type="text"
                  value={editingValues[locale] ?? ''}
                  onChange={(e) => handleEditingValueChange(locale, e.target.value)}
                  placeholder={`${getLocaleNameByCode(locale)} 값 입력`}
                  className="w-full"
                  autoFocus={locale === defaultLocale}
                />
              </Div>
            ))}

            <Div className="flex justify-end gap-2 pt-4 border-t border-gray-200 dark:border-gray-700">
              <Button
                type="button"
                variant="secondary"
                onClick={handleModalClose}
              >
                {t('common.cancel')}
              </Button>
              <Button
                type="button"
                variant="primary"
                onClick={handleModalSave}
              >
                {editingIndex !== null ? t('common.save') : t('common.add')}
              </Button>
            </Div>
          </Div>
        </Modal>
      )}

      {/* hidden input for form submission */}
      {name && (
        <input
          type="hidden"
          name={name}
          value={JSON.stringify(tags)}
        />
      )}
    </Div>
  );
};
