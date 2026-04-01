import React, { useCallback, useState, useMemo } from 'react';
import { Div } from '../basic/Div';
import { Label } from '../basic/Label';
import { Button } from '../basic/Button';
import { Textarea } from '../basic/Textarea';
import { Input } from '../basic/Input';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { HtmlContent } from './HtmlContent';

// G7Core 참조
const G7Core = () => (window as any).G7Core;

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  G7Core()?.t?.(key, params) ?? key;

// G7Core에서 지원 언어 목록 가져오기
const getSupportedLocales = (): string[] => {
  return G7Core()?.locale?.supported?.() ?? ['ko', 'en'];
};

// G7Core에서 현재 언어 가져오기
const getCurrentLocale = (): string => {
  return G7Core()?.locale?.current?.() ?? 'ko';
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

// 다국어 값 타입
export interface MultilingualValue {
  [locale: string]: string;
}

export interface HtmlEditorProps {
  /**
   * 콘텐츠 값 (단일 언어 모드)
   */
  content?: string;

  /**
   * 다국어 콘텐츠 값 (다국어 모드)
   * multilingual=true일 때 사용
   */
  value?: MultilingualValue;

  /**
   * 콘텐츠 변경 콜백
   * - 단일 언어: { target: { name, value: string } }
   * - 다국어: { target: { name, value: MultilingualValue } }
   */
  onChange?: (event: { target: { name: string; value: string | MultilingualValue } }) => void;

  /**
   * 다국어 모드 활성화
   * true일 때 언어 탭 UI 표시, value는 다국어 객체
   * @default false
   */
  multilingual?: boolean;

  /**
   * HTML 모드 여부 (미리보기 버튼 표시 조건)
   * 자동 바인딩된 값을 Layout JSON에서 전달받아 사용
   */
  isHtml?: boolean;

  /**
   * HTML 모드 변경 콜백
   * Form 자동 바인딩을 위해 이벤트 객체 형식으로 전달
   */
  onIsHtmlChange?: (event: { target: { name: string; checked: boolean } }) => void;

  /**
   * Textarea 행 수
   * @default 15
   */
  rows?: number;

  /**
   * placeholder 텍스트
   */
  placeholder?: string;

  /**
   * 라벨 텍스트
   */
  label?: string;

  /**
   * HTML 모드 체크박스 표시 여부
   * @default true
   */
  showHtmlModeToggle?: boolean;

  /**
   * HtmlContent 렌더링 시 적용할 클래스
   */
  contentClassName?: string;

  /**
   * DOMPurify 설정 (HTML 모드)
   */
  purifyConfig?: any;

  /**
   * 추가 className
   */
  className?: string;

  /**
   * 콘텐츠 필드 name 속성
   * @default 'content'
   */
  name?: string;

  /**
   * HTML 모드 체크박스 name 속성
   * @default 'content_mode'
   */
  htmlFieldName?: string;

  /**
   * 읽기 전용 모드
   */
  readOnly?: boolean;
}

/**
 * HtmlEditor 컴포넌트
 *
 * HTML과 일반 텍스트를 편집할 수 있는 범용 에디터 컴포넌트입니다.
 * - HTML 모드 체크박스는 자동 바인딩됩니다 (htmlFieldName prop 사용)
 * - HTML 모드에서는 편집/미리보기 토글 가능 (내부 상태 관리)
 * - 일반 텍스트 모드에서는 Textarea만 표시
 * - multilingual=true일 때 다국어 탭 UI 지원 (v1.5.0+)
 *
 * @example
 * // 단일 언어 모드
 * {
 *   "type": "composite",
 *   "name": "HtmlEditor",
 *   "props": {
 *     "content": "{{_local.form?.content}}",
 *     "name": "content"
 *   }
 * }
 *
 * @example
 * // 다국어 모드
 * {
 *   "type": "composite",
 *   "name": "HtmlEditor",
 *   "props": {
 *     "multilingual": true,
 *     "value": "{{_local.form?.description}}",
 *     "name": "description"
 *   }
 * }
 */
export const HtmlEditor: React.FC<HtmlEditorProps> = ({
  content = '',
  value,
  onChange,
  multilingual = false,
  isHtml: isHtmlProp = false,
  onIsHtmlChange,
  rows = 15,
  placeholder = '',
  label,
  showHtmlModeToggle = true,
  contentClassName = '',
  purifyConfig,
  className = '',
  name = 'content',
  htmlFieldName = 'content_mode',
  readOnly = false,
}) => {
  // 지원 언어 목록
  const supportedLocales = useMemo(() => getSupportedLocales(), []);
  const defaultLocale = useMemo(() => getCurrentLocale(), []);

  // 현재 선택된 언어 탭 (다국어 모드)
  const [currentLocale, setCurrentLocale] = useState(defaultLocale);

  // HTML 모드 내부 상태 (Toggle과 동기화)
  const [isHtml, setIsHtml] = useState(isHtmlProp);

  // 단일 언어 콘텐츠 내부 상태
  const [localContent, setLocalContent] = useState(content);

  // 다국어 콘텐츠 내부 상태
  const [localMultilingualContent, setLocalMultilingualContent] = useState<MultilingualValue>(
    value ?? {}
  );

  // 미리보기 모드 내부 상태 (컴포넌트 자체에서 관리)
  const [previewMode, setPreviewMode] = useState(false);

  // Props 변경 시 내부 상태 동기화
  React.useEffect(() => {
    setIsHtml(isHtmlProp);
  }, [isHtmlProp]);

  // content prop 변경 시 로컬 상태 동기화 (단일 언어 모드)
  React.useEffect(() => {
    if (!multilingual) {
      setLocalContent(content);
    }
  }, [content, multilingual]);

  // value prop 변경 시 로컬 상태 동기화 (다국어 모드)
  React.useEffect(() => {
    if (multilingual && value) {
      setLocalMultilingualContent(value);
    }
  }, [value, multilingual]);

  // 단일 언어 콘텐츠 변경 핸들러
  const handleContentChange = useCallback((e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const newContent = e.target.value;
    setLocalContent(newContent);

    if (onChange) {
      const event = G7Core()?.createChangeEvent?.({ value: newContent, name, type: 'textarea' })
        ?? { target: { name, value: newContent } };
      onChange(event);
    }
  }, [onChange, name]);

  // 다국어 콘텐츠 변경 핸들러
  const handleMultilingualContentChange = useCallback((locale: string, newContent: string) => {
    const newValue = {
      ...localMultilingualContent,
      [locale]: newContent,
    };
    setLocalMultilingualContent(newValue);

    if (onChange) {
      const event = G7Core()?.createChangeEvent?.({ value: newValue, name, type: 'multilingual' })
        ?? { target: { name, value: newValue } };
      onChange(event);
    }
  }, [onChange, name, localMultilingualContent]);

  // HTML 모드 변경 핸들러
  const handleHtmlModeChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const isHtmlMode = e.target.checked;
    setIsHtml(isHtmlMode);

    if (!isHtmlMode) {
      setPreviewMode(false);
    }

    if (onIsHtmlChange) {
      const event = G7Core()?.createChangeEvent?.({ checked: isHtmlMode, name: htmlFieldName, type: 'checkbox' })
        ?? { target: { name: htmlFieldName, checked: isHtmlMode } };
      onIsHtmlChange(event);
    }
  }, [onIsHtmlChange, htmlFieldName]);

  // 미리보기 모드 토글 핸들러
  const handlePreviewModeToggle = useCallback(() => {
    setPreviewMode(prev => !prev);
  }, []);

  // 현재 표시할 콘텐츠 (단일/다국어 모드에 따라)
  const currentContent = multilingual
    ? (localMultilingualContent[currentLocale] ?? '')
    : localContent;

  // 입력 완료 여부 확인 (다국어 모드)
  const hasValue = useCallback((locale: string) => {
    return Boolean(localMultilingualContent[locale]?.trim());
  }, [localMultilingualContent]);

  // 다국어 탭 렌더링
  const renderLocaleTabs = () => (
    <Div className="flex items-center gap-2 mb-3">
      {supportedLocales.map(localeCode => {
        const isDefault = localeCode === defaultLocale;
        const isActive = currentLocale === localeCode;

        return (
          <Button
            key={localeCode}
            type="button"
            className={`
              inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold transition-all
              ${isActive
                ? isDefault
                  ? 'bg-blue-500 text-white dark:bg-blue-600 dark:text-white shadow-md scale-105'
                  : 'bg-gray-500 text-white dark:bg-gray-600 dark:text-white shadow-md scale-105'
                : isDefault
                  ? 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/50'
                  : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'
              }
            `}
            onClick={() => setCurrentLocale(localeCode)}
          >
            <Icon name="globe" className="w-3 h-3" />
            <Span>{getLocaleNameByCode(localeCode)}</Span>
            {isDefault && <Span className="text-red-500">*</Span>}
            {/* 입력 완료 표시 */}
            {hasValue(localeCode) && !isActive && (
              <Icon name="check" className="w-3 h-3 text-green-500 dark:text-green-400" />
            )}
          </Button>
        );
      })}
    </Div>
  );

  return (
    <Div className={`space-y-2 ${className}`}>
      {/* 라벨 및 HTML 모드 토글 */}
      <Div className="flex items-center justify-between">
        {label && (
          <Label className="block text-sm font-medium text-gray-500 dark:text-gray-400">
            {label}
          </Label>
        )}

        <Div className="flex items-center gap-3">
          {/* 미리보기 버튼 (HTML 모드일 때만 표시) */}
          {isHtml && !readOnly && (
            <Button
              type="button"
              onClick={handlePreviewModeToggle}
              className={`px-3 py-1.5 text-xs font-bold rounded-lg focus:outline-none focus:ring-2 ${
                previewMode
                  ? 'text-gray-700 dark:text-gray-200 bg-gray-200 dark:bg-gray-600 border border-gray-300 dark:border-gray-500 hover:bg-gray-300 dark:hover:bg-gray-500 focus:ring-gray-400 dark:focus:ring-gray-500'
                  : 'text-blue-600 dark:text-blue-400 bg-white dark:bg-gray-700 border border-blue-300 dark:border-blue-600 hover:bg-blue-50 dark:hover:bg-gray-600 focus:ring-blue-500 dark:focus:ring-blue-600'
              }`}
            >
              {previewMode ? t('common.preview_off') : t('common.preview')}
            </Button>
          )}

          {showHtmlModeToggle && (
            <Label className="flex items-center gap-2 p-2 cursor-pointer">
              <Input
                type="checkbox"
                name={htmlFieldName}
                checked={isHtml}
                onChange={handleHtmlModeChange}
                disabled={readOnly}
                className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600"
              />
              <Div className="text-sm font-medium text-gray-700 dark:text-gray-300">
                {t('common.html_mode')}
              </Div>
            </Label>
          )}
        </Div>
      </Div>

      {/* 다국어 탭 (multilingual 모드일 때만) */}
      {multilingual && renderLocaleTabs()}

      {/* 콘텐츠 편집 영역 */}
      {!previewMode && (
        multilingual ? (
          // 다국어 모드: 각 언어별 Textarea
          supportedLocales.map(localeCode => (
            <Div
              key={localeCode}
              className={currentLocale === localeCode ? 'block' : 'hidden'}
            >
              <Textarea
                name={`${name}[${localeCode}]`}
                value={localMultilingualContent[localeCode] ?? ''}
                onChange={(e) => handleMultilingualContentChange(localeCode, e.target.value)}
                placeholder={`${placeholder} (${getLocaleNameByCode(localeCode)})`}
                rows={rows}
                readOnly={readOnly}
                className={`block w-full rounded-lg border px-3 py-2 text-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-1 disabled:opacity-50 disabled:cursor-not-allowed ${
                  isHtml
                    ? 'font-mono bg-white dark:bg-gray-800 border-blue-300 dark:border-blue-600 text-gray-800 dark:text-gray-200 focus:border-blue-500 focus:ring-blue-500'
                    : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-blue-500'
                }`}
              />
            </Div>
          ))
        ) : (
          // 단일 언어 모드
          <Textarea
            name={name}
            value={localContent}
            onChange={handleContentChange}
            placeholder={placeholder}
            rows={rows}
            readOnly={readOnly}
            className={`block w-full rounded-lg border px-3 py-2 text-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-1 disabled:opacity-50 disabled:cursor-not-allowed ${
              isHtml
                ? 'font-mono bg-white dark:bg-gray-800 border-blue-300 dark:border-blue-600 text-gray-800 dark:text-gray-200 focus:border-blue-500 focus:ring-blue-500'
                : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-blue-500'
            }`}
          />
        )
      )}

      {/* 미리보기 영역 (미리보기 모드일 때만) */}
      {previewMode && (
        <Div className="p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 min-h-[400px]">
          <HtmlContent
            content={currentContent}
            isHtml={true}
            className={contentClassName || 'prose dark:prose-invert max-w-none text-gray-900 dark:text-gray-100'}
            purifyConfig={purifyConfig}
          />
        </Div>
      )}
    </Div>
  );
};