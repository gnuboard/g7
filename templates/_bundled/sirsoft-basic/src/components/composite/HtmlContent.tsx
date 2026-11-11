// audit:allow seo-renderer-parity-sync #883 표시 규칙을 코어 「본문 콘텐츠」(G7Core.contentPolicy)로 옮겼다 — 봇 정화기 HtmlSanitizer 도 같은 ContentHtmlPolicy 로 후보 태그를 살리고 마무리한다(외부 영상은 봇에 링크)
import React, { useLayoutEffect, useMemo, useRef } from 'react';
// @ts-ignore - DOMPurify 타입 정의 없음
import DOMPurify from 'dompurify';
import { Div } from '../basic/Div';
import type { EditorAttrs } from '../../types';
import { applyDeferredStyles, getInlineStyleApi, sanitizeDeferred } from '../../support/inlineStyleDeferral';

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

    /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
/**
   * 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread)
   */
  editorAttrs?: EditorAttrs;
}

/** 기본 DOMPurify 설정 (FORBID 방식: 위험 태그/속성만 차단, 나머지 허용) */
const DEFAULT_PURIFY_CONFIG: any = {
  FORBID_TAGS: [
    // 스크립트 실행
    'script', 'noscript',
    // 외부 콘텐츠 삽입
    'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'portal',
    // 폼 요소 (피싱 방지)
    'form', 'input', 'textarea', 'select', 'button',
    // 메타/스타일 조작
    'style', 'link', 'meta', 'base',
    // 구조 태그 주입
    'body', 'head', 'html', 'title',
    // SVG/MathML (XSS 벡터)
    'svg', 'math',
    // 미디어 이벤트 핸들러 (onerror, onloadstart)
    'audio', 'video', 'source', 'track', 'canvas',
    // UI 오버레이/이벤트
    'details', 'dialog',
    // HTML 파서 혼란/sanitizer 우회
    'plaintext', 'xmp', 'listing',
    // 레거시/비표준
    'marquee', 'noframes', 'noembed', 'template', 'slot',
  ],
  FORBID_ATTR: [
    'onerror', 'onload', 'onclick', 'onmouseover', 'onfocus', 'onblur',
    'ontoggle', 'oncanplay', 'onloadstart',
    'formaction', 'xlink:href', 'action',
  ],
  ALLOW_DATA_ATTR: true,
  ADD_ATTR: ['target'],
};

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
  id,
  editorAttrs,
}) => {
  // text prop이 우선순위가 높음 (레이아웃 JSON에서 사용)
  const actualContent = text ?? content ?? '';

  // sanitize된 HTML을 메모이제이션. 훅은 조기 return 위에 둔다 — 그리지 않는 경우(빈 값·일반 텍스트)는 정화하지 않고 빈 문자열
  const sanitizedHtml = useMemo(() => {
    if (!isHtml || !actualContent || actualContent.trim() === '') {
      return '';
    }
    const config = purifyConfig
      ? {
          ...DEFAULT_PURIFY_CONFIG,
          ...purifyConfig,
          // 보안 기본값은 항상 유지 (사용자 설정으로 덮어쓰기 방지)
          FORBID_TAGS: [...DEFAULT_PURIFY_CONFIG.FORBID_TAGS, ...(purifyConfig.FORBID_TAGS || [])],
          FORBID_ATTR: [...DEFAULT_PURIFY_CONFIG.FORBID_ATTR, ...(purifyConfig.FORBID_ATTR || [])],
        }
      : DEFAULT_PURIFY_CONFIG;
    // 코어 「본문 콘텐츠」 규칙(G7Core.contentPolicy, engine-v1.90.0)이 있으면 그 규칙으로 정화한다 — 켜진 후보(그림 캡션·업로드 동영상·
    // 오디오·외부 영상)를 살리고 외부 영상은 정해진 플레이어 iframe 으로 바꾼다. prop 으로 덧붙인 금지는 규칙으로 풀 수 없다.
    // 규칙이 없으면(옛 코어) 종전 경로다 (#883)
    // 본문 서식 지연 장치(G7Core.inlineStyle)가 있으면 style 속성을 `data-g7-style` 로 받아 붙인 뒤 CSSOM 으로 입힌다 — 엄격한 스타일
    // 정책(`style-src-attr 'none'`)에서 HTML 문자열의 style 속성이 막히기 때문이다. 장치가 없으면(옛 코어) 종전 그대로다 (공개 #163)
    const deferStyle = getInlineStyleApi() !== null;
    const policySanitized = (window as any)?.G7Core?.contentPolicy?.sanitize?.(DOMPurify, actualContent, {
      ...config,
      FORBID_TAGS: [...DEFAULT_PURIFY_CONFIG.FORBID_TAGS],
      G7_KEEP_FORBID_TAGS: purifyConfig?.FORBID_TAGS || [],
      ...(deferStyle ? { G7_DEFER_STYLE: true } : {}),
    });
    const cleaned = typeof policySanitized === 'string'
      ? policySanitized
      : (sanitizeDeferred(actualContent, config) ?? (DOMPurify.sanitize(actualContent, config) as unknown as string));

    // target="_blank" 링크에 rel 속성 추가 (보안)
    return cleaned.replace(
      /<a\s+([^>]*?)href=["']([^"']+)["']([^>]*?)>/gi,
      (match: string, before: string, href: string, after: string) => {
        if (href.startsWith('http://') || href.startsWith('https://')) {
          if (!match.includes('rel=')) {
            return `<a ${before}href="${href}"${after} rel="noopener noreferrer">`;
          }
        }
        return match;
      }
    );
  }, [actualContent, purifyConfig, isHtml]);

  // React 19 는 `dangerouslySetInnerHTML` 객체가 바뀔 때마다(문자열이 같아도) innerHTML 을 다시 쓴다. 렌더마다 새 객체를 넘기면
  // 부모가 다시 그릴 때마다 본문 DOM 이 통째로 갈리고, 입혀 둔 서식이 지연 속성(`data-g7-style`) 상태로 되돌아간다(브라우저 실측:
  // 글 보기 첫 표시 60ms 뒤 재렌더에서 글자색·정렬이 사라짐). 그래서 객체를 문자열 기준으로 고정한다.
  const innerHtml = useMemo(() => ({ __html: sanitizedHtml }), [sanitizedHtml]);

  // 지연된 서식을 화면에 붙은 뒤 입힌다. 본문이 바뀌면 React 가 innerHTML 을 다시 쓰므로 다시 입혀야 하는데, 다른 경로로
  // innerHTML 이 다시 쓰이는 경우까지 덮도록 커밋마다 확인한다(남은 지연 속성이 없으면 querySelectorAll 한 번으로 끝난다).
  const containerRef = useRef<HTMLDivElement>(null);
  useLayoutEffect(() => {
    applyDeferredStyles(containerRef.current);
  });

  // 빈 값 처리
  if (!actualContent || actualContent.trim() === '') {
    return null;
  }

  // isHtml=false: 일반 텍스트 렌더링
  if (!isHtml) {
    // wrap-anywhere: 본문·검색 하이라이트의 공백 없는 긴 URL 이 칸을 넘지 않게 아무 데서나 줄을 바꾼다(#143).
    const textClasses = `
      whitespace-pre-wrap
      wrap-anywhere
      text-gray-900 dark:text-gray-100
      font-sans
      ${className}
    `.trim().replace(/\s+/g, ' ');

    return (
      <Div className={textClasses} id={id} {...editorAttrs}>
        {actualContent}
      </Div>
    );
  }

  // 컨테이너 클래스 조합
  const containerClasses = `
    prose dark:prose-invert max-w-none
    prose-p:my-2
    prose-headings:font-bold prose-headings:mt-6 prose-headings:mb-4
    prose-h1:text-2xl prose-h2:text-xl prose-h3:text-lg
    prose-a:text-blue-600 dark:prose-a:text-blue-400
    prose-a:no-underline hover:prose-a:underline
    prose-blockquote:border-l-4 prose-blockquote:border-gray-300 dark:prose-blockquote:border-gray-600
    prose-blockquote:pl-4 prose-blockquote:italic
    prose-code:bg-gray-100 dark:prose-code:bg-gray-800
    prose-code:px-1 prose-code:py-0.5 prose-code:rounded
    prose-pre:bg-gray-100 dark:prose-pre:bg-gray-800
    prose-pre:p-4 prose-pre:rounded-lg prose-pre:overflow-x-auto
    [&_table]:w-full [&_table]:border-collapse [&_table]:border [&_table]:border-gray-300
    dark:[&_table]:border-gray-600
    [&_th]:border [&_th]:border-gray-300 [&_th]:px-4 [&_th]:py-2 [&_th]:bg-gray-100 [&_th]:text-left
    dark:[&_th]:border-gray-600 dark:[&_th]:bg-gray-800
    [&_td]:border [&_td]:border-gray-300 [&_td]:px-4 [&_td]:py-2
    dark:[&_td]:border-gray-600
    [&_tr:hover]:bg-gray-50 dark:[&_tr:hover]:bg-gray-700/50
    after:content-[''] after:block after:clear-both
    wrap-anywhere
    ${className}
  `.trim().replace(/\s+/g, ' ');

  return (
    <Div
      id={id}
      className={containerClasses}
      ref={containerRef}
      dangerouslySetInnerHTML={innerHtml}
      {...editorAttrs}
    />
  );
};
