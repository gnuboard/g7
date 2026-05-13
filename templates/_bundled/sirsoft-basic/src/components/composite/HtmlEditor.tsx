import React, { useCallback, useState } from 'react';
import { Div } from '../basic/Div';
import { Label } from '../basic/Label';
import { Button } from '../basic/Button';
import { Textarea } from '../basic/Textarea';
import { Input } from '../basic/Input';
import { HtmlContent } from './HtmlContent';

// G7Core 참조
const G7Core = () => (window as any).G7Core;

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  G7Core()?.t?.(key, params) ?? key;

export interface HtmlEditorProps {
  /**
   * 콘텐츠 값
   */
  content?: string;

  /**
   * 콘텐츠 변경 콜백
   * Form 자동 바인딩을 위해 이벤트 객체 형식으로 전달
   */
  onChange?: (event: { target: { name: string; value: string } }) => void;

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
 *
 * @example
 * // Layout JSON에서 사용 (자동 바인딩)
 * {
 *   "type": "composite",
 *   "name": "HtmlEditor",
 *   "props": {
 *     "content": "{{_local.form?.content}}",
 *     "name": "content",
 *     "htmlFieldName": "content_mode"
 *   }
 * }
 */
export const HtmlEditor: React.FC<HtmlEditorProps> = ({
  content = '',
  onChange,
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
  // HTML 모드 내부 상태 (Toggle과 동기화)
  const [isHtml, setIsHtml] = useState(isHtmlProp);

  // 콘텐츠 내부 상태 (입력값 즉시 반영용)
  const [localContent, setLocalContent] = useState(content);

  // 미리보기 모드 내부 상태 (컴포넌트 자체에서 관리)
  const [previewMode, setPreviewMode] = useState(false);

  // Props 변경 시 내부 상태 동기화
  React.useEffect(() => {
    setIsHtml(isHtmlProp);
  }, [isHtmlProp]);

  // content prop 변경 시 로컬 상태 동기화
  React.useEffect(() => {
    setLocalContent(content);
  }, [content]);

  // 콘텐츠 변경 핸들러
  const handleContentChange = useCallback((e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const newContent = e.target.value;

    // 로컬 상태 즉시 업데이트 (입력 반응성)
    setLocalContent(newContent);

    // Props 콜백 호출 (G7Core.createChangeEvent 사용으로 ActionDispatcher 호환성 확보)
    if (onChange) {
      const event = G7Core()?.createChangeEvent?.({ value: newContent, name, type: 'textarea' })
        ?? { target: { name, value: newContent } };
      onChange(event);
    }
  }, [onChange, name]);

  // HTML 모드 변경 핸들러
  const handleHtmlModeChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const isHtmlMode = e.target.checked;

    // 로컬 상태 즉시 업데이트 (미리보기 버튼 표시용)
    setIsHtml(isHtmlMode);

    // HTML 모드 비활성화 시 미리보기 모드도 함께 비활성화
    if (!isHtmlMode) {
      setPreviewMode(false);
    }

    // Props 콜백 호출 (G7Core.createChangeEvent 사용으로 ActionDispatcher 호환성 확보)
    if (onIsHtmlChange) {
      const event = G7Core()?.createChangeEvent?.({ checked: isHtmlMode, name: htmlFieldName, type: 'checkbox' })
        ?? { target: { name: htmlFieldName, checked: isHtmlMode } };
      onIsHtmlChange(event);
    }
  }, [onIsHtmlChange, htmlFieldName]);

  // 미리보기 모드 토글 핸들러 (내부 상태만 변경)
  const handlePreviewModeToggle = useCallback(() => {
    setPreviewMode(prev => !prev);
  }, []);

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

      {/* 콘텐츠 편집 영역 */}
      {!previewMode && (
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
      )}

      {/* 미리보기 영역 (미리보기 모드일 때만) */}
      {previewMode && (
        <Div className="p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 min-h-[400px]">
          <HtmlContent
            content={localContent}
            isHtml={true}
            className={contentClassName || 'prose dark:prose-invert max-w-none text-gray-900 dark:text-gray-100'}
            purifyConfig={purifyConfig}
          />
        </Div>
      )}

    </Div>
  );
};
