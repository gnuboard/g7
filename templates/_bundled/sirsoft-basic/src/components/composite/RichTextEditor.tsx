/**
 * RichTextEditor 컴포넌트
 *
 * 게시글 작성을 위한 리치 텍스트 에디터입니다.
 * TipTap 또는 Quill 등의 외부 라이브러리를 래핑합니다.
 *
 * @note 실제 구현 시 TipTap, Quill, CKEditor 등 선택하여 래핑
 */

import React, { useEffect, useRef, useState } from 'react';
import { Div } from '../basic/Div';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:RichTextEditor')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:RichTextEditor]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:RichTextEditor]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:RichTextEditor]', ...args),
};
import { Button } from '../basic/Button';
import { Input } from '../basic/Input';
import { Textarea } from '../basic/Textarea';
import { H3 } from '../basic/H3';
import { Label } from '../basic/Label';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

interface RichTextEditorProps {
  /** 필드 이름 */
  name: string;
  /** 초기 HTML 값 */
  initialValue?: string;
  /** 플레이스홀더 */
  placeholder?: string;
  /** 값 변경 콜백 */
  onChange?: (html: string) => void;
  /** 이미지 업로드 URL */
  imageUploadUrl?: string;
  /** 최소 높이 */
  minHeight?: string;
  /** 비활성화 여부 */
  disabled?: boolean;
  /** 추가 CSS 클래스 */
  className?: string;
}

type FormatType =
  | 'bold'
  | 'italic'
  | 'underline'
  | 'strike'
  | 'code'
  | 'h1'
  | 'h2'
  | 'h3'
  | 'bulletList'
  | 'orderedList'
  | 'blockquote'
  | 'link'
  | 'image';

/**
 * 리치 텍스트 에디터 컴포넌트
 *
 * @example
 * ```tsx
 * <RichTextEditor
 *   name="content"
 *   initialValue={post?.content}
 *   placeholder="내용을 입력하세요..."
 *   onChange={(html) => setContent(html)}
 * />
 * ```
 *
 * @example
 * ```json
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "RichTextEditor",
 *   "props": {
 *     "name": "content",
 *     "placeholder": "$t:board.form.content_placeholder",
 *     "initialValue": "{{post.data?.content ?? ''}}"
 *   }
 * }
 * ```
 */
const RichTextEditor: React.FC<RichTextEditorProps> = ({
  name,
  initialValue = '',
  placeholder,
  onChange,
  imageUploadUrl = '/api/upload/image',
  minHeight = '300px',
  disabled = false,
  className = '',
}) => {
  const editorRef = useRef<HTMLDivElement>(null);
  const [content, setContent] = useState(initialValue);
  const [showCodeView, setShowCodeView] = useState(false);
  const [showLinkModal, setShowLinkModal] = useState(false);
  const [linkUrl, setLinkUrl] = useState('');

  // 에디터 초기화 (실제 구현 시 TipTap/Quill 초기화)
  useEffect(() => {
    // 초기값 설정 (dangerouslySetInnerHTML 대신 직접 설정)
    if (editorRef.current && !editorRef.current.innerHTML) {
      editorRef.current.innerHTML = initialValue;
    }
  }, [initialValue]);

  // 값 변경 시 콜백 호출
  useEffect(() => {
    if (onChange) {
      onChange(content);
    }
  }, [content, onChange]);

  // 포맷 적용 (실제 구현 시 에디터 API 사용)
  const applyFormat = (format: FormatType) => {
    // TODO: 에디터 라이브러리의 포맷 API 호출
    logger.log('Apply format:', format);
  };

  // 이미지 업로드
  const handleImageUpload = async (file: File) => {
    const formData = new FormData();
    formData.append('file', file);

    try {
      const response = await fetch(imageUploadUrl, {
        method: 'POST',
        body: formData,
        credentials: 'include',
      });

      if (!response.ok) throw new Error('업로드 실패');

      const data = await response.json();
      // TODO: 에디터에 이미지 삽입
      logger.log('Image uploaded:', data.url);
    } catch (error) {
      logger.error('이미지 업로드 오류:', error);
    }
  };

  // 파일 선택 핸들러
  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      handleImageUpload(file);
    }
  };

  // 링크 삽입
  const insertLink = () => {
    if (linkUrl) {
      // TODO: 에디터에 링크 삽입
      logger.log('Insert link:', linkUrl);
      setShowLinkModal(false);
      setLinkUrl('');
    }
  };

  const toolbarButtons: { format: FormatType; icon: React.ReactNode; title: string }[] = [
    { format: 'bold', icon: 'B', title: t('editor.toolbar.bold') },
    { format: 'italic', icon: 'I', title: t('editor.toolbar.italic') },
    { format: 'underline', icon: 'U', title: t('editor.toolbar.underline') },
    { format: 'strike', icon: 'S', title: t('editor.toolbar.strike') },
    { format: 'code', icon: '</>', title: t('editor.toolbar.code') },
  ];

  const headingButtons: { format: FormatType; label: string }[] = [
    { format: 'h1', label: 'H1' },
    { format: 'h2', label: 'H2' },
    { format: 'h3', label: 'H3' },
  ];

  const listButtons: { format: FormatType; icon: string; title: string }[] = [
    { format: 'bulletList', icon: '•', title: t('editor.toolbar.bullet_list') },
    { format: 'orderedList', icon: '1.', title: t('editor.toolbar.ordered_list') },
    { format: 'blockquote', icon: '❝', title: t('editor.toolbar.blockquote') },
  ];

  return (
    <Div className={`border border-gray-300 dark:border-gray-600 rounded-lg overflow-hidden ${className}`}>
      {/* 툴바 */}
      <Div className="flex flex-wrap items-center gap-1 p-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
        {/* 텍스트 포맷 */}
        <Div className="flex items-center gap-1 pr-2 border-r border-gray-200 dark:border-gray-700">
          {toolbarButtons.map((btn) => (
            <Button
              key={btn.format}
              type="button"
              onClick={() => applyFormat(btn.format)}
              disabled={disabled}
              className="w-8 h-8 flex items-center justify-center text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 rounded disabled:opacity-50"
              title={btn.title}
            >
              {btn.icon}
            </Button>
          ))}
        </Div>

        {/* 제목 */}
        <Div className="flex items-center gap-1 pr-2 border-r border-gray-200 dark:border-gray-700">
          {headingButtons.map((btn) => (
            <Button
              key={btn.format}
              type="button"
              onClick={() => applyFormat(btn.format)}
              disabled={disabled}
              className="px-2 h-8 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 rounded disabled:opacity-50"
            >
              {btn.label}
            </Button>
          ))}
        </Div>

        {/* 리스트 */}
        <Div className="flex items-center gap-1 pr-2 border-r border-gray-200 dark:border-gray-700">
          {listButtons.map((btn) => (
            <Button
              key={btn.format}
              type="button"
              onClick={() => applyFormat(btn.format)}
              disabled={disabled}
              className="w-8 h-8 flex items-center justify-center text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 rounded disabled:opacity-50"
              title={btn.title}
            >
              {btn.icon}
            </Button>
          ))}
        </Div>

        {/* 미디어 */}
        <Div className="flex items-center gap-1 pr-2 border-r border-gray-200 dark:border-gray-700">
          <Button
            type="button"
            onClick={() => setShowLinkModal(true)}
            disabled={disabled}
            className="w-8 h-8 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 rounded disabled:opacity-50"
            title={t('editor.toolbar.link')}
          >
            🔗
          </Button>
          <Label className="w-8 h-8 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 rounded cursor-pointer">
            🖼
            <Input
              type="file"
              accept="image/*"
              onChange={handleFileSelect}
              disabled={disabled}
              className="hidden"
            />
          </Label>
        </Div>

        {/* 코드 보기 토글 */}
        <Button
          type="button"
          onClick={() => setShowCodeView(!showCodeView)}
          disabled={disabled}
          className={`px-2 h-8 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 rounded disabled:opacity-50 ${
            showCodeView ? 'bg-gray-200 dark:bg-gray-700' : ''
          }`}
        >
          {'</>'}
          {t('editor.toolbar.code_view')}
        </Button>
      </Div>

      {/* 에디터 영역 */}
      {showCodeView ? (
        <Textarea
          value={content}
          onChange={(e) => setContent(e.target.value)}
          placeholder={placeholder}
          disabled={disabled}
          className="w-full p-4 bg-gray-900 text-gray-100 font-mono text-sm focus:outline-none resize-none"
          style={{ minHeight }}
        />
      ) : (
        <Div
          ref={editorRef}
          contentEditable={!disabled}
          onInput={(e) => setContent(e.currentTarget.innerHTML)}
          className="p-4 bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none prose dark:prose-invert max-w-none"
          style={{ minHeight }}
          data-placeholder={placeholder}
        />
      )}

      {/* 숨겨진 input (폼 전송용) */}
      <Input type="hidden" name={name} value={content} />

      {/* 링크 모달 */}
      {showLinkModal && (
        <Div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <Div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-96">
            <H3 className="text-lg font-medium text-gray-900 dark:text-white mb-4">
              {t('editor.link_modal.title')}
            </H3>
            <Input
              type="url"
              value={linkUrl}
              onChange={(e) => setLinkUrl(e.target.value)}
              placeholder="https://example.com"
              className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white mb-4"
            />
            <Div className="flex justify-end gap-2">
              <Button
                type="button"
                onClick={() => {
                  setShowLinkModal(false);
                  setLinkUrl('');
                }}
                className="px-4 py-2 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg"
              >
                {t('common.cancel')}
              </Button>
              <Button
                type="button"
                onClick={insertLink}
                className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
              >
                {t('editor.link_modal.insert')}
              </Button>
            </Div>
          </Div>
        </Div>
      )}
    </Div>
  );
};

export default RichTextEditor;
