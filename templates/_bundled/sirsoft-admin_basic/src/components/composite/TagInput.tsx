import React, { useCallback, useMemo, useState, useEffect, useRef } from 'react';
import Select, { components, MultiValue, SingleValue, ActionMeta, InputActionMeta } from 'react-select';
import CreatableSelect from 'react-select/creatable';

export interface TagOption {
  value: string | number;
  label: string;
  count?: number;
  isDisabled?: boolean;
  /** 그룹명 (드롭다운에서 그룹 헤더로 표시) */
  group?: string;
  /** 상세 설명 (옵션 아래에 작은 글씨로 표시) */
  description?: string;
}

export interface GroupedOption {
  label: string;
  options: TagOption[];
}

export type TagVariant = 'blue' | 'green' | 'amber' | 'red' | 'purple' | 'gray';

export interface TagInputProps {
  /** 선택된 값 (멀티: 배열, 싱글: 단일 값 또는 배열) */
  value: (string | number)[] | string | number | null;
  /** 선택 가능한 옵션 목록 */
  options?: TagOption[];
  /** 값 변경 콜백 (이벤트 객체 또는 배열) */
  onChange: ((event: any) => void) | ((values: (string | number)[] | string | number | null) => void);
  /** 새 항목 추가 가능 여부 */
  creatable?: boolean;
  /** 입력창 placeholder */
  placeholder?: string;
  /** 검색 결과 없을 때 메시지 */
  noOptionsMessage?: string;
  /** 새 항목 추가 라벨 (문자열 또는 생성 함수) */
  formatCreateLabel?: string | ((inputValue: string) => string);
  /** 새 항목 추가 접미사 (기본값: "추가") */
  createLabelSuffix?: string;
  /** 최대 선택 개수 (멀티 모드에서만 유효) */
  maxItems?: number;
  /** 비활성화 여부 (boolean 또는 문자열 "true"/"false") */
  disabled?: boolean | string;
  /** 삭제 전 확인 콜백 (true: 허용, string: 불가 + 메시지) */
  onBeforeRemove?: (option: TagOption) => boolean | string;
  /** 새 옵션 생성 시 콜백 */
  onCreateOption?: (inputValue: string) => void;
  /** 추가 className */
  className?: string;
  /** 태그 값별 variant 맵핑 */
  tagVariants?: Record<string | number, TagVariant>;
  /** 기본 variant (지정되지 않은 태그) */
  defaultVariant?: TagVariant;
  /** 폼 필드 이름 (템플릿 엔진용) */
  name?: string;
  /** 다중 선택 여부 (기본값: true) */
  isMulti?: boolean;
  /** 선택 해제 가능 여부 (싱글 모드에서 유효, 기본값: true) */
  isClearable?: boolean;
  /** 검색 가능 여부 (기본값: true) */
  isSearchable?: boolean;
  /** 태그 구분자 배열 (creatable 모드에서 입력 시 자동 분리, 기본값: [',']) */
  delimiters?: string[];
  /** 붙여넣기 시 자동 분리 여부 (기본값: true) */
  splitOnPaste?: boolean;
  /** 검색 입력 변경 콜백 (비동기 검색용, 매 키 입력 시 호출) */
  onInputChange?: (event: any) => void;
}

// Tailwind 스타일 클래스 상수
const STYLES = {
  control: 'px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus-within:outline-none focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-blue-500',
  valueContainer: 'gap-1 flex flex-wrap',
  multiValue: {
    blue: 'bg-blue-100 dark:bg-blue-900/20 rounded px-1.5 py-0.5 flex items-center gap-1',
    green: 'bg-green-100 dark:bg-green-900/20 rounded px-1.5 py-0.5 flex items-center gap-1',
    amber: 'bg-amber-100 dark:bg-amber-900/20 rounded px-1.5 py-0.5 flex items-center gap-1',
    red: 'bg-red-100 dark:bg-red-900/20 rounded px-1.5 py-0.5 flex items-center gap-1',
    purple: 'bg-purple-100 dark:bg-purple-900/20 rounded px-1.5 py-0.5 flex items-center gap-1',
    gray: 'bg-gray-100 dark:bg-gray-700/50 rounded px-1.5 py-0.5 flex items-center gap-1',
  },
  multiValueLabel: {
    blue: 'text-blue-700 dark:text-blue-300 text-xs',
    green: 'text-green-700 dark:text-green-300 text-xs font-semibold',
    amber: 'text-amber-700 dark:text-amber-300 text-xs font-semibold',
    red: 'text-red-700 dark:text-red-300 text-xs font-semibold',
    purple: 'text-purple-700 dark:text-purple-300 text-xs font-semibold',
    gray: 'text-gray-700 dark:text-gray-300 text-xs',
  },
  multiValueRemove: {
    blue: 'text-blue-700 dark:text-blue-300 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-600 dark:hover:text-red-400 rounded px-1',
    green: 'text-green-700 dark:text-green-300 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-600 dark:hover:text-red-400 rounded px-1',
    amber: 'text-amber-700 dark:text-amber-300 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-600 dark:hover:text-red-400 rounded px-1',
    red: 'text-red-700 dark:text-red-300 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-600 dark:hover:text-red-400 rounded px-1',
    purple: 'text-purple-700 dark:text-purple-300 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-600 dark:hover:text-red-400 rounded px-1',
    gray: 'text-gray-700 dark:text-gray-300 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-600 dark:hover:text-red-400 rounded px-1',
  },
  input: 'text-gray-900 dark:text-white text-sm',
  placeholder: 'text-gray-400 dark:text-gray-500 text-sm',
  menu: 'mt-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg',
  menuList: 'py-1',
  option: {
    base: 'px-3 py-2 cursor-pointer text-gray-900 dark:text-white text-[13px]',
    selected: 'bg-blue-100 dark:bg-blue-900/20',
    focused: 'bg-gray-100 dark:bg-gray-700',
    default: 'bg-white dark:bg-gray-800',
  },
  noOptionsMessage: 'px-3 py-2 text-gray-500 dark:text-gray-400 text-[13px]',
  checkbox: {
    base: 'w-4 h-4 rounded border flex items-center justify-center text-white text-[10px] shrink-0',
    selected: 'bg-blue-500 border-blue-500',
    unselected: 'border-gray-400 dark:border-gray-500',
  },
} as const;

// 옵션 컴포넌트 (체크박스 스타일 + description 지원)
const CustomOption = (props: any) => {
  const { data, isSelected } = props;
  return (
    <components.Option {...props}>
      <div className="flex items-center gap-2">
        <span className={`${STYLES.checkbox.base} ${isSelected ? STYLES.checkbox.selected : STYLES.checkbox.unselected}`}>
          {isSelected ? '✓' : ''}
        </span>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-1">
            <span className="text-[13px]">{data.label}</span>
            {data.count !== undefined && data.count > 0 && (
              <span className="text-gray-500 dark:text-gray-400 text-[10px]">({data.count})</span>
            )}
          </div>
          {data.description && (
            <p className="text-xs text-gray-500 dark:text-gray-400 truncate">{data.description}</p>
          )}
        </div>
      </div>
    </components.Option>
  );
};

// 그룹 헤더 컴포넌트
const GroupHeading = (props: any) => {
  return (
    <components.GroupHeading {...props}>
      <div className="px-3 py-1.5 text-xs font-medium text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
        {props.data.label}
      </div>
    </components.GroupHeading>
  );
};

// 다중 값 라벨 컴포넌트 (count 표시)
const CustomMultiValueLabel = (props: any) => {
  const { data } = props;
  return (
    <components.MultiValueLabel {...props}>
      <span>{data.label}</span>
      {data.count !== undefined && data.count > 0 && (
        <span className="text-xs opacity-60 ml-1">({data.count})</span>
      )}
    </components.MultiValueLabel>
  );
};

/**
 * 태그 입력 컴포넌트
 *
 * 다중 선택이 가능한 태그 입력 컴포넌트입니다.
 * isMulti=false로 설정하면 단일 선택 모드로 동작합니다.
 * creatable 모드에서는 새 항목 추가가 가능합니다.
 */
export const TagInput: React.FC<TagInputProps> = ({
  value: valueProp,
  options = [],
  onChange,
  creatable = false,
  placeholder = '검색 또는 입력...',
  noOptionsMessage = '검색 결과가 없습니다',
  formatCreateLabel,
  createLabelSuffix = '추가',
  maxItems,
  disabled: disabledProp = false,
  onBeforeRemove,
  onCreateOption,
  className = '',
  tagVariants,
  defaultVariant = 'blue',
  name,
  isMulti = true,
  isClearable = true,
  isSearchable = true,
  delimiters = [','],
  splitOnPaste = true,
  onInputChange: onInputChangeProp,
}) => {
  // disabled prop을 boolean으로 정규화 (문자열 "true"/"false" 처리)
  const disabled = disabledProp === true || disabledProp === 'true';

  // valueProp을 내부 배열 형태로 정규화
  const normalizeValue = useCallback((val: typeof valueProp): (string | number)[] => {
    if (val === null || val === undefined) return [];
    if (Array.isArray(val)) return val;
    return [val];
  }, []);

  // 내부 상태로 관리 (비제어 컴포넌트 패턴)
  const [value, setValue] = useState<(string | number)[]>(() => normalizeValue(valueProp));

  // 입력값 상태 (구분자 감지를 위한 제어 컴포넌트)
  const [inputValue, setInputValue] = useState('');

  // 이전 valueProp 값을 추적하여 실제 값 변경만 감지
  const prevValueRef = useRef<(string | number)[] | undefined>(undefined);

  // props 변경 시 내부 상태 동기화 (배열 내용 변경만 감지)
  useEffect(() => {
    // 배열 내용 비교 헬퍼 함수
    const arraysEqual = (a: (string | number)[] | undefined, b: (string | number)[] | undefined): boolean => {
      if (!a && !b) return true;
      if (!a || !b) return false;
      if (a.length !== b.length) return false;
      return a.every((val, idx) => val === b[idx]);
    };

    const normalizedValue = normalizeValue(valueProp);
    // 실제 값이 변경된 경우에만 상태 업데이트
    if (!arraysEqual(normalizedValue, prevValueRef.current)) {
      setValue(normalizedValue);
      prevValueRef.current = normalizedValue;
    }
  }, [valueProp, normalizeValue]);

  // formatCreateLabel을 함수로 정규화
  const createLabelFormatter = useMemo(() => {
    // 함수가 제공된 경우
    if (formatCreateLabel) {
      if (typeof formatCreateLabel === 'string') {
        return (inputValue: string) => `${formatCreateLabel} "${inputValue}"`;
      }
      return formatCreateLabel;
    }

    // 기본 동작: createLabelSuffix 사용
    return (inputValue: string) => (
      <span className="text-[13px]">
        <strong>{inputValue}</strong> {createLabelSuffix}
      </span>
    );
  }, [formatCreateLabel, createLabelSuffix]);

  // 선택된 값들을 TagOption 배열로 변환
  const selectedOptions = useMemo(() => {
    if (!value || !Array.isArray(value)) return [];

    return value
      .map((v) => {
        const existingOption = options.find((opt) => opt.value === v);
        return existingOption || { value: v, label: String(v) };
      })
      .filter((opt): opt is TagOption => opt !== undefined);
  }, [value, options]);

  // 드롭다운에 표시할 전체 옵션 (선택된 값 포함, 그룹 지원)
  const allOptions = useMemo(() => {
    const existingValues = new Set(options.map(opt => opt.value));
    const additionalOptions: TagOption[] = value
      ? value
          .filter(v => !existingValues.has(v))
          .map(v => ({ value: v, label: String(v) }))
      : [];

    const mergedOptions: TagOption[] = [...options, ...additionalOptions];

    // 그룹이 있는 옵션이 하나라도 있으면 그룹화
    const hasGroups = mergedOptions.some(opt => opt.group);
    if (!hasGroups) {
      return mergedOptions;
    }

    // 그룹별로 옵션 분류
    const groupMap = new Map<string, TagOption[]>();
    const ungroupedOptions: TagOption[] = [];

    mergedOptions.forEach(opt => {
      if (opt.group) {
        const groupOptions = groupMap.get(opt.group) || [];
        groupOptions.push(opt);
        groupMap.set(opt.group, groupOptions);
      } else {
        ungroupedOptions.push(opt);
      }
    });

    // 그룹화된 옵션 배열 생성
    const groupedOptions: GroupedOption[] = [];
    groupMap.forEach((groupOptions, groupLabel) => {
      groupedOptions.push({
        label: groupLabel,
        options: groupOptions,
      });
    });

    // 그룹이 없는 옵션이 있으면 별도 그룹으로 추가
    if (ungroupedOptions.length > 0) {
      groupedOptions.push({
        label: '',
        options: ungroupedOptions,
      });
    }

    return groupedOptions;
  }, [options, value]);

  // 템플릿 엔진용 가짜 이벤트 생성 헬퍼
  const createFakeEvent = useCallback((outputValue: (string | number)[] | string | number | null) => ({
    target: { value: outputValue },
    currentTarget: { value: outputValue },
    preventDefault: () => {}, // ActionDispatcher의 isStandardEvent 체크를 통과하기 위해 필요
    stopPropagation: () => {},
  } as any), []);

  // 구분자로 입력값 분리 함수
  const splitByDelimiters = useCallback((input: string): string[] => {
    if (!delimiters?.length) return [input.trim()].filter(Boolean);
    // 구분자를 정규식 패턴으로 변환 (특수문자 이스케이프)
    const escapedDelimiters = delimiters.map(d => d.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const pattern = new RegExp(`[${escapedDelimiters.join('')}]`);
    return input.split(pattern).map(s => s.trim()).filter(Boolean);
  }, [delimiters]);

  // 입력 변경 핸들러 - 구분자 감지 시 태그 생성 + 외부 onInputChange 콜백
  const handleInputChange = useCallback((newValue: string, actionMeta: InputActionMeta) => {
    if (actionMeta.action !== 'input-change') {
      setInputValue(newValue);
      return;
    }

    // 외부 onInputChange 콜백 호출 (비동기 검색용)
    if (onInputChangeProp) {
      onInputChangeProp(createFakeEvent(newValue));
    }

    // 구분자 포함 여부 확인
    const hasDelimiter = delimiters?.some(d => newValue.includes(d));
    if (!hasDelimiter || !creatable || !isMulti) {
      setInputValue(newValue);
      return;
    }

    // 구분자로 분리하여 태그 생성
    const parts = splitByDelimiters(newValue);
    const newTags = parts.filter(p => p && !value.includes(p));

    if (newTags.length > 0) {
      // 최대 개수 제한 확인
      const availableSlots = maxItems ? maxItems - value.length : Infinity;
      const tagsToAdd = newTags.slice(0, availableSlots);

      if (tagsToAdd.length > 0) {
        const newValues = [...value, ...tagsToAdd];
        setValue(newValues);
        onChange(createFakeEvent(newValues));
      }
    }
    setInputValue('');
  }, [delimiters, creatable, isMulti, splitByDelimiters, value, maxItems, onChange, createFakeEvent, onInputChangeProp]);

  // 붙여넣기 핸들러
  const handlePaste = useCallback((e: React.ClipboardEvent) => {
    if (!splitOnPaste || !creatable || !isMulti) return;

    const pastedText = e.clipboardData.getData('text');
    const parts = splitByDelimiters(pastedText);

    // 여러 항목이 있을 때만 처리 (단일 항목은 기본 동작)
    if (parts.length <= 1) return;

    e.preventDefault();
    const newTags = parts.filter(p => p && !value.includes(p));

    if (newTags.length > 0) {
      // 최대 개수 제한 확인
      const availableSlots = maxItems ? maxItems - value.length : Infinity;
      const tagsToAdd = newTags.slice(0, availableSlots);

      if (tagsToAdd.length > 0) {
        const newValues = [...value, ...tagsToAdd];
        setValue(newValues);
        onChange(createFakeEvent(newValues));
      }
    }
  }, [splitOnPaste, creatable, isMulti, splitByDelimiters, value, maxItems, onChange, createFakeEvent]);

  // 변경 핸들러 (멀티 모드)
  const handleMultiChange = useCallback(
    (newValue: MultiValue<TagOption>, actionMeta: ActionMeta<TagOption>) => {
      // 삭제 작업 시 onBeforeRemove 검증
      if ((actionMeta.action === 'remove-value' || actionMeta.action === 'pop-value') && actionMeta.removedValue && onBeforeRemove) {
        const result = onBeforeRemove(actionMeta.removedValue);
        if (result !== true) {
          const message = typeof result === 'string' ? result : `"${actionMeta.removedValue.label}"은(는) 제거할 수 없습니다.`;
          alert(message);
          return;
        }
      }

      // 최대 개수 제한
      if (maxItems && newValue.length > maxItems) return;

      const newValues = newValue.map((opt) => opt.value);
      setValue(newValues);
      onChange(createFakeEvent(newValues));
    },
    [onChange, onBeforeRemove, maxItems, createFakeEvent]
  );

  // 변경 핸들러 (싱글 모드)
  const handleSingleChange = useCallback(
    (newValue: SingleValue<TagOption>, actionMeta: ActionMeta<TagOption>) => {
      // 삭제 작업 시 onBeforeRemove 검증
      if (actionMeta.action === 'clear' && value.length > 0 && onBeforeRemove) {
        const currentOption = options.find(opt => opt.value === value[0]) || { value: value[0], label: String(value[0]) };
        const result = onBeforeRemove(currentOption);
        if (result !== true) {
          const message = typeof result === 'string' ? result : `"${currentOption.label}"은(는) 제거할 수 없습니다.`;
          alert(message);
          return;
        }
      }

      const outputValue = newValue ? newValue.value : null;
      const internalValue = newValue ? [newValue.value] : [];
      setValue(internalValue);
      onChange(createFakeEvent(outputValue));
    },
    [onChange, onBeforeRemove, value, options, createFakeEvent]
  );

  // 새 옵션 생성 핸들러
  const handleCreate = useCallback(
    (inputValue: string) => {
      if (onCreateOption) onCreateOption(inputValue);

      const newValues = [...(value || []), inputValue];
      setValue(newValues);
      onChange(createFakeEvent(newValues));
    },
    [onChange, onCreateOption, value, createFakeEvent]
  );

  // 새 옵션 유효성 검사 (중복 방지)
  const isValidNewOption = useCallback(
    (inputValue: string) => {
      if (!inputValue || !inputValue.trim()) return false;
      return !value?.includes(inputValue.trim());
    },
    [value]
  );

  // Enter/Tab 키 처리 (태그 생성 또는 잘못된 동작 방지)
  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent<HTMLDivElement>) => {
      const currentInput = inputValue.trim();

      // Tab 키: 현재 입력값으로 태그 생성 (creatable 모드)
      if (event.key === 'Tab' && creatable && isMulti && currentInput) {
        if (!value.includes(currentInput)) {
          // 최대 개수 제한 확인
          if (!maxItems || value.length < maxItems) {
            event.preventDefault();
            const newValues = [...value, currentInput];
            setValue(newValues);
            onChange(createFakeEvent(newValues));
            setInputValue('');
          }
        } else {
          // 중복 시 입력만 초기화
          event.preventDefault();
          setInputValue('');
        }
        return;
      }

      // Enter 키: 빈 입력 또는 중복 값 방지
      if (event.key === 'Enter') {
        if (!currentInput || (value && value.includes(currentInput))) {
          event.preventDefault();
          event.stopPropagation();
        }
      }
    },
    [value, inputValue, creatable, isMulti, maxItems, onChange, createFakeEvent]
  );

  // 태그 variant 가져오기 헬퍼
  const getTagVariant = useCallback(
    (tagValue: string | number): TagVariant => {
      if (tagVariants && tagVariants[tagValue]) {
        return tagVariants[tagValue];
      }
      return defaultVariant;
    },
    [tagVariants, defaultVariant]
  );

  // react-select 공통 스타일 설정
  const commonClassNames = {
    control: () => `${STYLES.control} ${className} ${disabled ? 'opacity-60 bg-gray-100 dark:bg-gray-800 cursor-not-allowed' : ''}`,
    valueContainer: () => STYLES.valueContainer,
    multiValue: (state: any) => {
      const variant = getTagVariant(state.data.value);
      return STYLES.multiValue[variant];
    },
    multiValueLabel: (state: any) => {
      const variant = getTagVariant(state.data.value);
      return STYLES.multiValueLabel[variant];
    },
    multiValueRemove: (state: any) => {
      const variant = getTagVariant(state.data.value);
      return `${STYLES.multiValueRemove[variant]} ${disabled ? 'pointer-events-none' : ''}`;
    },
    singleValue: () => 'text-gray-900 dark:text-white text-sm',
    input: () => STYLES.input,
    placeholder: () => STYLES.placeholder,
    menu: () => STYLES.menu,
    menuList: () => STYLES.menuList,
    menuPortal: () => 'z-[9999]',
    option: (state: any) =>
      `${STYLES.option.base} ${
        state.isSelected
          ? STYLES.option.selected
          : state.isFocused
            ? STYLES.option.focused
            : STYLES.option.default
      }`,
    noOptionsMessage: () => STYLES.noOptionsMessage,
    clearIndicator: () => 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 cursor-pointer px-1',
    dropdownIndicator: () => 'text-gray-400 dark:text-gray-500 px-1',
  };

  // 공통 props
  const baseProps = {
    options: allOptions,
    onKeyDown: handleKeyDown,
    placeholder,
    noOptionsMessage: () => noOptionsMessage,
    isDisabled: disabled,
    isSearchable,
    menuIsOpen: undefined,
    openMenuOnClick: true,
    openMenuOnFocus: true,
    // 드롭다운 메뉴를 body에 포탈로 렌더링하여 부모 컨테이너의 overflow에 의해 잘리지 않도록 함
    menuPortalTarget: typeof document !== 'undefined' ? document.body : null,
    menuPosition: 'fixed' as const,
    classNamePrefix: 'tag-input',
    unstyled: true,
    classNames: commonClassNames,
    // 구분자 기반 태그 분리 또는 외부 onInputChange 콜백이 있을 때 제어 컴포넌트 설정
    ...((creatable && isMulti) || onInputChangeProp ? {
      inputValue,
      onInputChange: handleInputChange,
    } : {}),
  };

  // hidden input 값 계산
  const hiddenValue = isMulti ? value.join(',') : (value[0] ?? '');

  // 싱글 모드
  if (!isMulti) {
    const singleProps = {
      ...baseProps,
      isMulti: false as const,
      value: selectedOptions[0] || null,
      onChange: handleSingleChange,
      isClearable,
      closeMenuOnSelect: true,
      components: {
        Option: CustomOption,
        GroupHeading,
        IndicatorSeparator: () => null,
        ...(isClearable ? {} : { ClearIndicator: () => null }),
      },
    };

    if (creatable) {
      return (
        <>
          {name && <input type="hidden" name={name} value={hiddenValue} />}
          <CreatableSelect<TagOption, false>
            {...singleProps}
            onCreateOption={handleCreate}
            formatCreateLabel={createLabelFormatter}
            isValidNewOption={isValidNewOption}
            createOptionPosition="first"
          />
        </>
      );
    }

    return (
      <>
        {name && <input type="hidden" name={name} value={hiddenValue} />}
        <Select<TagOption, false> {...singleProps} />
      </>
    );
  }

  // 멀티 모드
  const multiProps = {
    ...baseProps,
    isMulti: true as const,
    value: selectedOptions,
    onChange: handleMultiChange,
    closeMenuOnSelect: false,
    hideSelectedOptions: false,
    backspaceRemovesValue: false,
    isClearable: false,
    components: {
      Option: CustomOption,
      GroupHeading,
      MultiValueLabel: CustomMultiValueLabel,
      ClearIndicator: () => null,
      DropdownIndicator: () => null,
      IndicatorSeparator: () => null,
    },
  };

  if (creatable) {
    return (
      <div onPaste={handlePaste}>
        {name && <input type="hidden" name={name} value={hiddenValue} />}
        <CreatableSelect<TagOption, true>
          {...multiProps}
          onCreateOption={handleCreate}
          formatCreateLabel={createLabelFormatter}
          isValidNewOption={isValidNewOption}
          createOptionPosition="first"
        />
      </div>
    );
  }

  return (
    <>
      {name && <input type="hidden" name={name} value={hiddenValue} />}
      <Select<TagOption, true> {...multiProps} />
    </>
  );
};
