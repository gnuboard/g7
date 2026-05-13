import React, { useState, useCallback, useMemo, useEffect, useRef } from 'react';
import { Input } from '../basic/Input';
import { Textarea } from '../basic/Textarea';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';

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
  // 다국어 키에서 언어명 가져오기 (common.language_ko, common.language_en 등)
  const translatedName = t(`common.language_${code}`);

  // 번역이 키 그대로 반환되면 (번역 없음) fallback 맵 사용
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

// 시스템 언어 목록을 LocaleOption[]으로 변환
const getSystemLocaleOptions = (): LocaleOption[] => {
  const supportedLocales = getSupportedLocales();
  return supportedLocales.map(code => ({
    code,
    name: getLocaleNameByCode(code),
    nativeName: getLocaleNameByCode(code),
  }));
};

export interface MultilingualValue {
  [locale: string]: string;
}

export interface LocaleOption {
  code: string;
  name: string;
  nativeName?: string;
}

export type MultilingualInputType = 'text' | 'textarea';
export type MultilingualInputLayout = 'inline' | 'tabs' | 'compact';

export interface MultilingualInputProps {
  /** 다국어 값 객체 */
  value?: MultilingualValue;
  /** 값 변경 콜백 */
  onChange?: (event: any) => void;
  /** 입력 타입 (text | textarea) */
  inputType?: MultilingualInputType;
  /** 레이아웃 타입 (inline: 모든 언어 수직 표시 | tabs: 탭 전환 방식 | compact: 탭과 입력이 한 줄) */
  layout?: MultilingualInputLayout;
  /** 사용 가능한 언어 목록 */
  availableLocales?: LocaleOption[];
  /** 기본 언어 코드 */
  defaultLocale?: string;
  /** 입력창 placeholder */
  placeholder?: string;
  /** 필수 입력 여부 */
  required?: boolean;
  /** 비활성화 여부 */
  disabled?: boolean;
  /** 최대 길이 */
  maxLength?: number;
  /** textarea 행 수 (inputType이 'textarea'일 때만 사용) */
  rows?: number;
  /** 추가 className */
  className?: string;
  /** input name */
  name?: string;
  /** 모바일에서 언어 코드로 표시 (기본값: false) */
  showCodeOnMobile?: boolean;
  /** 에러 메시지 (입력 필드에 적색 테두리 표시) */
  error?: string;
}

/**
 * 다국어 입력 컴포넌트
 *
 * 두 가지 레이아웃 모드를 지원합니다:
 * - inline (기본): 카드 형태로 모든 언어를 수직으로 쌓아서 표시
 * - tabs: 탭 방식으로 언어 전환 (카드 형태)
 */
export const MultilingualInput: React.FC<MultilingualInputProps> = ({
  value = {},
  onChange,
  inputType = 'text',
  layout = 'inline',
  availableLocales,
  defaultLocale,
  placeholder = '',
  required = false,
  disabled = false,
  maxLength,
  rows = 4,
  className = '',
  name = 'multilingual_input',
  showCodeOnMobile = false,
  error,
}) => {

  // 에러 상태 여부
  const hasError = !!error;
  // G7Core에서 실제 사용할 언어 목록과 기본 언어 계산
  const actualAvailableLocales = useMemo(() => {
    return availableLocales ?? getSystemLocaleOptions();
  }, [availableLocales]);

  const actualDefaultLocale = useMemo(() => {
    return defaultLocale ?? getCurrentLocale();
  }, [defaultLocale]);

  // ── 로컬 상태 버퍼 (debounce 대기 중 사용자 입력값 보존) ──
  // props.value는 debounce가 fire된 후에야 갱신되므로,
  // locale 탭 전환 시 stale props.value로 렌더링되어 입력값이 유실됨.
  // localValue는 사용자 입력을 즉시 반영하고, props.value 갱신 시 동기화함.
  const localValueRef = useRef<MultilingualValue>(value);
  const [localValue, setLocalValue] = useState<MultilingualValue>(value);

  // props.value의 이전 내용을 추적 — 내용 기반 비교로 불필요한 동기화 방지
  const lastExternalValueRef = useRef<string>(JSON.stringify(value));

  // props.value 외부 변경 시 로컬 상태 동기화
  // 동일 내용의 새 객체 참조(부모 rerender)는 무시하여 로컬 편집분 보존
  useEffect(() => {
    const valueStr = JSON.stringify(value);
    if (valueStr !== lastExternalValueRef.current) {
      lastExternalValueRef.current = valueStr;
      localValueRef.current = value;
      setLocalValue(value);
    }
  }, [value]);

  // 공통 onChange 호출 헬퍼
  const emitChange = useCallback((newValue: MultilingualValue, changedKeys?: string[]) => {
    const event: any = {
      target: {
        name,
        value: newValue,
      },
    };
    // _changedKeys: 엔진 디바운스 병합에서 stale closure로 인한 키 유실 방지
    if (changedKeys) {
      event._changedKeys = changedKeys;
    }
    onChange?.(event);
  }, [onChange, name]);

  // 활성화된 언어 탭 목록 (기본 언어는 항상 포함)
  const [activeLocales, setActiveLocales] = useState<string[]>(() => {
    // tabs/compact 레이아웃일 때는 모든 지원 로케일을 표시
    if (layout === 'tabs' || layout === 'compact') {
      const supportedCodes = actualAvailableLocales.map(l => l.code);
      // 기본 언어를 맨 앞에 배치
      const sorted = [actualDefaultLocale, ...supportedCodes.filter(c => c !== actualDefaultLocale)];
      return sorted;
    }

    const valueKeys = Object.keys(value).filter(key => value[key] !== undefined);

    if (valueKeys.length === 0) {
      return [actualDefaultLocale];
    }

    // 기본 언어를 맨 앞에 배치
    const locales: string[] = [];

    // 1. 기본 언어가 있으면 맨 앞에 추가
    if (valueKeys.includes(actualDefaultLocale)) {
      locales.push(actualDefaultLocale);
    }

    // 2. 나머지 언어들 추가 (기본 언어 제외)
    valueKeys.forEach(key => {
      if (key !== actualDefaultLocale) {
        locales.push(key);
      }
    });

    return locales;
  });

  // 현재 선택된 언어 탭 (tabs 레이아웃용)
  const [currentLocale, setCurrentLocale] = useState<string>(actualDefaultLocale);

  // 추가 가능한 언어 목록 (이미 활성화된 언어 제외)
  const addableLocales = useMemo(() => {
    return actualAvailableLocales.filter(locale => !activeLocales.includes(locale.code));
  }, [actualAvailableLocales, activeLocales]);

  // 언어 추가 드롭다운 표시 여부
  const [showAddMenu, setShowAddMenu] = useState(false);

  // value prop이 변경될 때 activeLocales 동기화
  useEffect(() => {
    // tabs/compact 레이아웃일 때는 항상 모든 지원 로케일 유지
    if (layout === 'tabs' || layout === 'compact') {
      const supportedCodes = actualAvailableLocales.map(l => l.code);
      const sorted = [actualDefaultLocale, ...supportedCodes.filter(c => c !== actualDefaultLocale)];
      const currentStr = activeLocales.join(',');
      const newStr = sorted.join(',');
      if (currentStr !== newStr) {
        setActiveLocales(sorted);
      }
      return;
    }

    const valueKeys = Object.keys(value).filter(key => value[key] !== undefined);

    // value가 비어있거나 모든 값이 빈 문자열이면 기본 언어만 표시
    if (valueKeys.length === 0) {
      setActiveLocales([actualDefaultLocale]);
      return;
    }

    // value에 있는 언어들로 activeLocales 구성
    // 기본 언어를 맨 앞에 배치
    const newLocales: string[] = [];

    // 1. 기본 언어가 있으면 맨 앞에 추가
    if (valueKeys.includes(actualDefaultLocale)) {
      newLocales.push(actualDefaultLocale);
    }

    // 2. 나머지 언어들 추가 (기본 언어 제외)
    valueKeys.forEach(key => {
      if (key !== actualDefaultLocale) {
        newLocales.push(key);
      }
    });

    // 순서를 유지하면서 비교하여 변경되었을 때만 업데이트
    const currentStr = activeLocales.join(',');
    const newStr = newLocales.join(',');

    if (currentStr !== newStr) {
      setActiveLocales(newLocales);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, actualDefaultLocale, layout, actualAvailableLocales]);

  // 언어 입력값 변경 핸들러
  // localValueRef 사용: debounce 대기 중에도 최신 입력값 기반으로 병합
  const handleInputChange = useCallback((locale: string, inputValue: string) => {
    const newValue = {
      ...localValueRef.current,
      [locale]: inputValue,
    };
    localValueRef.current = newValue;
    setLocalValue(newValue);
    emitChange(newValue, [locale]);
  }, [emitChange]);

  // 언어 탭 추가
  const handleAddLocale = useCallback((localeCode: string) => {
    setActiveLocales(prev => [...prev, localeCode]);
    setCurrentLocale(localeCode);
    setShowAddMenu(false);

    // 새 언어 필드를 빈 문자열로 초기화
    const newValue = {
      ...localValueRef.current,
      [localeCode]: '',
    };
    localValueRef.current = newValue;
    setLocalValue(newValue);
    emitChange(newValue);
  }, [emitChange]);

  // 언어 탭 제거
  const handleRemoveLocale = useCallback((localeCode: string) => {
    // 기본 언어는 제거 불가
    if (localeCode === actualDefaultLocale) return;

    setActiveLocales(prev => prev.filter(code => code !== localeCode));

    // 제거된 언어가 현재 선택된 언어면 기본 언어로 전환
    if (currentLocale === localeCode) {
      setCurrentLocale(actualDefaultLocale);
    }

    // value에서 해당 언어 제거
    const newValue = { ...localValueRef.current };
    delete newValue[localeCode];
    localValueRef.current = newValue;
    setLocalValue(newValue);
    emitChange(newValue);
  }, [currentLocale, actualDefaultLocale, emitChange]);

  // 언어명 표시
  const getLocaleName = useCallback((localeCode: string) => {
    const locale = actualAvailableLocales.find(l => l.code === localeCode);
    return locale?.nativeName || locale?.name || localeCode.toUpperCase();
  }, [actualAvailableLocales]);

  // 입력 필드가 채워져 있는지 확인
  const hasValue = useCallback((localeCode: string) => {
    return Boolean(localValue[localeCode]?.trim());
  }, [localValue]);

  // 언어 추가 버튼 렌더링 (Pill Badge 스타일)
  const renderAddLocaleButtonCompact = useCallback(() => {
    if (addableLocales.length === 0) return null;

    return (
      <Div className="relative">
        <Button
          type="button"
          className="inline-flex items-center gap-1 px-3 py-1 text-xs font-medium text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30 hover:bg-blue-100 dark:hover:bg-blue-900/50 rounded-full border border-dashed border-blue-300 dark:border-blue-600 transition-colors"
          onClick={() => setShowAddMenu(!showAddMenu)}
          disabled={disabled}
        >
          <Icon name="plus" className="w-3 h-3" />
          <Span>{t('common.add_language')}</Span>
        </Button>

        {showAddMenu && (
          <Div className="absolute top-full left-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg z-10 min-w-[120px]">
            {addableLocales.map(locale => (
              <Button
                key={locale.code}
                type="button"
                className="w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 first:rounded-t-lg last:rounded-b-lg transition-colors"
                onClick={() => handleAddLocale(locale.code)}
              >
                <Icon name="globe" className="w-3.5 h-3.5 text-gray-400 dark:text-gray-500" />
                <Span>{locale.nativeName || locale.name}</Span>
              </Button>
            ))}
          </Div>
        )}
      </Div>
    );
  }, [addableLocales, showAddMenu, disabled, handleAddLocale]);

  // 입력 필드 렌더링 (라벨 위에 표시) - Pill Badge 스타일
  const renderInputFieldWithTopLabel = useCallback((localeCode: string, showAddButton: boolean = false) => {
    const errorBorderClass = hasError
      ? 'border-red-500 dark:border-red-500 focus:ring-red-500 focus:border-red-500'
      : 'border-gray-200 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500';
    const inputClassName = `w-full px-3 py-2 border ${errorBorderClass} rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2`;
    const isDefault = localeCode === actualDefaultLocale;
    const localizedPlaceholder = `${placeholder}${placeholder ? ' (' : ''}${getLocaleName(localeCode)}${placeholder ? ')' : ''}`;

    return (
      <Div key={localeCode} className="space-y-2">
        {/* 언어 배지 (Pill Badge 스타일) */}
        <Div className="flex items-center gap-2">
          <Span className={`
            inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium
            ${isDefault
              ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300'
              : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
            }
          `}>
            {/* 다국어 아이콘 */}
            <Icon name="globe" className="w-3 h-3" />
            {getLocaleName(localeCode)}
            {isDefault && <Span className="text-red-500 dark:text-red-400">*</Span>}
            {/* 비기본 언어 삭제 버튼 - 배지 안에 */}
            {!isDefault && (
              <Button
                type="button"
                className="ml-0.5 p-0.5 rounded-full hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                onClick={() => handleRemoveLocale(localeCode)}
                disabled={disabled}
              >
                <Icon name="xmark" className="w-3 h-3" />
              </Button>
            )}
          </Span>
          {/* 기본 언어 옆에 추가 버튼 */}
          {isDefault && showAddButton && renderAddLocaleButtonCompact()}
        </Div>

        {/* 입력 필드 */}
        {inputType === 'textarea' ? (
          <Textarea
            name={`${name}[${localeCode}]`}
            value={localValue[localeCode] || ''}
            onChange={(e) => handleInputChange(localeCode, e.target.value)}
            placeholder={localizedPlaceholder}
            required={required && isDefault}
            disabled={disabled}
            maxLength={maxLength}
            rows={rows}
            className={`${inputClassName} resize-vertical`}
          />
        ) : (
          <Input
            type="text"
            name={`${name}[${localeCode}]`}
            value={localValue[localeCode] || ''}
            onChange={(e) => handleInputChange(localeCode, e.target.value)}
            placeholder={localizedPlaceholder}
            required={required && isDefault}
            disabled={disabled}
            maxLength={maxLength}
            className={inputClassName}
          />
        )}
      </Div>
    );
  }, [name, localValue, handleInputChange, placeholder, required, disabled, maxLength, rows, inputType, getLocaleName, actualDefaultLocale, handleRemoveLocale, renderAddLocaleButtonCompact, hasError]);

  // 입력 필드 렌더링 (라벨 없음 버전 - tabs용)
  const renderInputField = useCallback((localeCode: string) => {
    const errorBorderClass = hasError
      ? 'border-red-500 dark:border-red-500 focus:ring-red-500 focus:border-red-500'
      : 'border-gray-200 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500';
    const inputClassName = `w-full px-3 py-2 border ${errorBorderClass} rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2`;
    const localizedPlaceholder = `${placeholder}${placeholder ? ' (' : ''}${getLocaleName(localeCode)}${placeholder ? ')' : ''}`;

    return (
      <Div key={localeCode}>
        {inputType === 'textarea' ? (
          <Textarea
            name={`${name}[${localeCode}]`}
            value={localValue[localeCode] || ''}
            onChange={(e) => handleInputChange(localeCode, e.target.value)}
            placeholder={localizedPlaceholder}
            required={required && localeCode === actualDefaultLocale}
            disabled={disabled}
            maxLength={maxLength}
            rows={rows}
            className={`${inputClassName} resize-vertical`}
          />
        ) : (
          <Input
            type="text"
            name={`${name}[${localeCode}]`}
            value={localValue[localeCode] || ''}
            onChange={(e) => handleInputChange(localeCode, e.target.value)}
            placeholder={localizedPlaceholder}
            required={required && localeCode === actualDefaultLocale}
            disabled={disabled}
            maxLength={maxLength}
            className={inputClassName}
          />
        )}
      </Div>
    );
  }, [name, localValue, handleInputChange, placeholder, required, disabled, maxLength, rows, inputType, actualDefaultLocale, getLocaleName]);

  // Inline 레이아웃 렌더링
  const renderInlineLayout = () => (
    <Div className={className}>
      {/* 입력 필드들 */}
      <Div className="space-y-4">
        {activeLocales.map((localeCode, index) =>
          renderInputFieldWithTopLabel(localeCode, index === 0)
        )}
      </Div>
    </Div>
  );

  // Tabs 레이아웃 렌더링 - Pill Badge 스타일
  const renderTabsLayout = () => (
    <Div className={className}>
      {/* 언어 탭들 + 추가 버튼 (Pill Badge 스타일) */}
      <Div className="flex items-center gap-2 mb-3">
        {activeLocales.map(localeCode => (
          <Button
            key={localeCode}
            type="button"
            className={`
              inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold transition-all
              ${currentLocale === localeCode
                ? localeCode === actualDefaultLocale
                  ? 'bg-blue-500 text-white dark:bg-blue-600 dark:text-white shadow-md scale-105'
                  : 'bg-gray-500 text-white dark:bg-gray-600 dark:text-white shadow-md scale-105'
                : localeCode === actualDefaultLocale
                  ? 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/50'
                  : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'
              }
            `}
            onClick={() => setCurrentLocale(localeCode)}
          >
            {/* 다국어 아이콘 */}
            <Icon name="globe" className="w-3 h-3" />
            {/* showCodeOnMobile: 모바일에서는 언어 코드, 데스크탑에서는 전체 언어명 표시 */}
            {showCodeOnMobile ? (
              <>
                <Span className="hidden md:inline">{getLocaleName(localeCode)}</Span>
                <Span className="inline md:hidden">{localeCode.toUpperCase()}</Span>
              </>
            ) : (
              getLocaleName(localeCode)
            )}
            {localeCode === actualDefaultLocale && (
              <Span className="text-red-500 dark:text-red-400">*</Span>
            )}
            {/* 입력 완료 표시 */}
            {hasValue(localeCode) && currentLocale !== localeCode && (
              <Icon name="check" className="w-3 h-3 text-green-500 dark:text-green-400" />
            )}
            {/* 삭제 버튼 (기본 언어 제외) - 배지 안에 */}
            {localeCode !== actualDefaultLocale && (
              <Button
                type="button"
                className="ml-0.5 p-0.5 rounded-full hover:bg-gray-300 dark:hover:bg-gray-500 transition-colors"
                onClick={(e) => {
                  e.stopPropagation();
                  handleRemoveLocale(localeCode);
                }}
                disabled={disabled}
              >
                <Icon
                  name="xmark"
                  className="w-3 h-3"
                />
              </Button>
            )}
          </Button>
        ))}

        {/* 언어 추가 버튼 */}
        {renderAddLocaleButtonCompact()}
      </Div>

      {/* 입력 필드 */}
      {activeLocales.map(localeCode => (
        <Div
          key={localeCode}
          className={currentLocale === localeCode ? 'block' : 'hidden'}
        >
          {renderInputField(localeCode)}
        </Div>
      ))}
    </Div>
  );

  // Compact 레이아웃 렌더링 - 언어 선택과 입력 필드가 한 줄에 배치
  const renderCompactLayout = () => {
    const errorBorderClass = hasError
      ? 'border-red-500 dark:border-red-500 focus:ring-red-500 focus:border-red-500'
      : 'border-gray-200 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500';

    return (
      <Div className={className}>
        <Div className="flex items-center gap-1">
          {/* 언어 선택 버튼들 - 컴팩트 스타일 */}
          <Div className="flex items-center shrink-0">
            {activeLocales.map(localeCode => (
              <Button
                key={localeCode}
                type="button"
                className={`
                  px-2 py-1.5 text-xs font-medium border-y border-l first:rounded-l last:rounded-r last:border-r transition-colors
                  ${currentLocale === localeCode
                    ? 'bg-blue-500 text-white border-blue-500 dark:bg-blue-600 dark:border-blue-600'
                    : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700'
                  }
                `}
                onClick={() => setCurrentLocale(localeCode)}
                title={getLocaleName(localeCode)}
              >
                {localeCode.toUpperCase()}
                {localeCode === actualDefaultLocale && (
                  <Span className="text-red-400 ml-0.5">*</Span>
                )}
              </Button>
            ))}
          </Div>

          {/* 입력 필드 - flex-1로 나머지 공간 차지 */}
          <Div className="flex-1 min-w-0">
            {inputType === 'textarea' ? (
              <Textarea
                name={`${name}[${currentLocale}]`}
                value={localValue[currentLocale] || ''}
                onChange={(e) => handleInputChange(currentLocale, e.target.value)}
                placeholder={placeholder}
                required={required && currentLocale === actualDefaultLocale}
                disabled={disabled}
                maxLength={maxLength}
                rows={rows}
                className={`w-full px-2 py-1.5 border ${errorBorderClass} rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm`}
              />
            ) : (
              <Input
                type="text"
                name={`${name}[${currentLocale}]`}
                value={localValue[currentLocale] || ''}
                onChange={(e) => handleInputChange(currentLocale, e.target.value)}
                placeholder={placeholder}
                required={required && currentLocale === actualDefaultLocale}
                disabled={disabled}
                maxLength={maxLength}
                className={`w-full px-2 py-1.5 border ${errorBorderClass} rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm`}
              />
            )}
          </Div>
        </Div>
      </Div>
    );
  };

  // 레이아웃에 따라 렌더링
  return (
    <Div className={disabled ? 'opacity-50 cursor-not-allowed' : undefined}>
      {layout === 'compact' ? renderCompactLayout() : layout === 'tabs' ? renderTabsLayout() : renderInlineLayout()}
      {/* 에러 메시지 표시 */}
      {hasError && error && (
        <Span className="text-xs text-red-500 dark:text-red-400 mt-1 block">
          {error}
        </Span>
      )}
    </Div>
  );
};

MultilingualInput.displayName = 'MultilingualInput';
