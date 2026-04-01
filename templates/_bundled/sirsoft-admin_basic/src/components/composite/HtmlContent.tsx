import React, { useMemo } from 'react';
// @ts-ignore - DOMPurify 타입 정의 없음
import DOMPurify from 'dompurify';
import { Div } from '../basic/Div';

export interface HtmlContentProps {
  /**
   * 콘텐츠 (HTML 또는 일반 텍스트)
   */
  content?: string;

  /**
   * 콘텐츠가 HTML 형식인지 여부
   * - true: HTML 렌더링 (DOMPurify 적용, prose 스타일)
   * - false: 일반 텍스트 (whitespace-pre-wrap으로 줄바꿈 보존)
   * @default true
   */
  isHtml?: boolean;

  /**
   * 사용자 정의 클래스
   */
  className?: string;

  /**
   * DOMPurify 설정 오버라이드 (isHtml=true일 때만 사용)
   */
  purifyConfig?: any;

  /**
   * 레이아웃 JSON에서 text 속성으로 전달되는 콘텐츠
   * content보다 우선순위가 높음
   */
  text?: string;
}

/**
 * HtmlContent 콘텐츠 렌더링 컴포넌트
 *
 * HTML과 일반 텍스트를 안전하게 렌더링하는 범용 composite 컴포넌트입니다.
 * - isHtml=true: DOMPurify를 사용하여 XSS 공격 방지
 * - isHtml=false: 일반 텍스트로 렌더링 (줄바꿈 보존)
 *
 * @example
 * // HTML 렌더링 (기본값)
 * <HtmlContent content="<p>안녕하세요</p>" />
 * <HtmlContent content="<p>안녕하세요</p>" isHtml={true} />
 *
 * // 일반 텍스트 렌더링
 * <HtmlContent
 *   content="안녕하세요\n줄바꿈이 보존됩니다"
 *   isHtml={false}
 * />
 *
 * // 커스텀 클래스 적용
 * <HtmlContent
 *   content="<p>게시글 내용</p>"
 *   className="prose dark:prose-invert"
 * />
 *
 * // DOMPurify 설정 커스터마이징 (HTML 모드)
 * <HtmlContent
 *   content="<p>내용</p>"
 *   purifyConfig={{ ALLOWED_TAGS: ['p', 'br', 'strong', 'em'] }}
 * />
 */
export const HtmlContent: React.FC<HtmlContentProps> = ({
  content,
  text,
  isHtml = true,
  className = '',
  purifyConfig,
}) => {
  // text prop이 우선순위가 높음 (레이아웃 JSON에서 사용)
  const actualContent = text ?? content ?? '';

  // 빈 값 처리
  if (!actualContent || actualContent.trim() === '') {
    return null;
  }

  // isHtml=false: 일반 텍스트 렌더링
  if (!isHtml) {
    const textClasses = `
      whitespace-pre-wrap
      text-gray-900 dark:text-gray-100
      font-sans
      ${className}
    `.trim().replace(/\s+/g, ' ');

    return (
      <Div className={textClasses}>
        {actualContent}
      </Div>
    );
  }

  // isHtml=true: HTML 렌더링 (기존 로직)
  // 기본 DOMPurify 설정
  const defaultConfig: any = {
    ALLOWED_TAGS: [
      'p', 'br', 'strong', 'em', 'u', 's', 'strike', 'del', 'ins',
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
      'ul', 'ol', 'li',
      'a', 'img',
      'blockquote', 'code', 'pre',
      'table', 'thead', 'tbody', 'tr', 'th', 'td',
      'div', 'span',
    ],
    ALLOWED_ATTR: [
      'href', 'target', 'rel', 'title', 'alt', 'src', 'width', 'height',
      'class', 'style', 'id', 'data-*',
    ],
    ALLOW_DATA_ATTR: true,
    // 외부 링크는 rel="noopener noreferrer" 자동 추가
    ADD_ATTR: ['target'],
    // target="_blank"인 링크에 rel="noopener noreferrer" 자동 추가
    SAFE_FOR_TEMPLATES: true,
  };

  // sanitize된 HTML을 메모이제이션
  const sanitizedHtml = useMemo(() => {
    const config = purifyConfig || defaultConfig;
    const cleaned = DOMPurify.sanitize(actualContent, config) as unknown as string;

    // target="_blank" 링크에 rel 속성 추가 (보안)
    return cleaned.replace(
      /<a\s+([^>]*?)href=["']([^"']+)["']([^>]*?)>/gi,
      (match: string, before: string, href: string, after: string) => {
        // 외부 링크인 경우
        if (href.startsWith('http://') || href.startsWith('https://')) {
          // rel 속성이 없으면 추가
          if (!match.includes('rel=')) {
            return `<a ${before}href="${href}"${after} rel="noopener noreferrer">`;
          }
        }
        return match;
      }
    );
  }, [actualContent, purifyConfig, defaultConfig]);

  // 컨테이너 클래스 조합
  const containerClasses = `
    prose dark:prose-invert max-w-none
    prose-p:my-2
    prose-headings:font-bold prose-headings:mt-6 prose-headings:mb-4
    prose-h1:text-2xl prose-h2:text-xl prose-h3:text-lg
    prose-a:text-blue-600 dark:prose-a:text-blue-400
    prose-a:no-underline hover:prose-a:underline
    prose-img:rounded-lg prose-img:shadow-md
    prose-blockquote:border-l-4 prose-blockquote:border-gray-300 dark:prose-blockquote:border-gray-600
    prose-blockquote:pl-4 prose-blockquote:italic
    prose-code:bg-gray-100 dark:prose-code:bg-gray-800
    prose-code:px-1 prose-code:py-0.5 prose-code:rounded
    prose-pre:bg-gray-100 dark:prose-pre:bg-gray-800
    prose-pre:p-4 prose-pre:rounded-lg prose-pre:overflow-x-auto
    prose-table:border prose-table:border-gray-300 dark:prose-table:border-gray-600
    prose-th:bg-gray-100 dark:prose-th:bg-gray-800
    prose-th:border prose-th:border-gray-300 dark:prose-th:border-gray-600
    prose-th:px-4 prose-th:py-2
    prose-td:border prose-td:border-gray-300 dark:prose-td:border-gray-600
    prose-td:px-4 prose-td:py-2
    ${className}
  `.trim().replace(/\s+/g, ' ');

  return (
    <Div
      className={containerClasses}
      dangerouslySetInnerHTML={{ __html: sanitizedHtml }}
    />
  );
};