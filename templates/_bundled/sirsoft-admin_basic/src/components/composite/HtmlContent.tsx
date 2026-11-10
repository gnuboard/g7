import React, { useCallback, useEffect, useMemo, useRef } from 'react';
// @ts-ignore - DOMPurify 타입 정의 없음
import DOMPurify from 'dompurify';
import { Div } from '../basic/Div';
import type { EditorAttrs } from '../../types';
import { ImageGallery, useImageGallery } from './ImageGallery';
import { decorateContentImages, ZOOM_ATTR } from '../../support/contentImages';
import { stripThumb } from '../../support/thumbnail';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>): string =>
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  (window as any).G7Core?.t?.(key, params) ?? key;

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
   * 본문 이미지 srcset 의 `sizes` 값 (isHtml=true일 때만 사용)
   * 본문 칸 너비가 레이아웃마다 달라, 브라우저가 고를 후보 폭을 레이아웃이 바꿀 수 있게 연다.
   * @default "(min-width: 1024px) 768px, 100vw"
   */
  imageSizes?: string;

  /**
   * 레이아웃 JSON에서 text 속성으로 전달되는 콘텐츠
   * content보다 우선순위가 높음
   */
  text?: string;

  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
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
  imageSizes,
  id,
  editorAttrs,
}) => {
  // text prop이 우선순위가 높음 (레이아웃 JSON에서 사용)
  const actualContent = text ?? content ?? '';
  const isEmpty = !actualContent || actualContent.trim() === '';
  // 편집 캔버스에서는 클릭이 컴포넌트 선택이라 확대 보기를 붙이지 않는다
  const zoomable = !editorAttrs;

  // 훅은 조기 return 앞에서 모두 부른다 — 종전에는 빈 값·평문 분기가 useMemo 보다 먼저 return 해
  // 내용이 비었다가 채워지는 순간 훅 개수가 바뀌었다(React 훅 규칙 위반).
  const { openGallery, galleryProps } = useImageGallery();
  // 확대 보기를 연 이미지 — 닫힌 뒤 초점을 돌려준다. 라이트박스는 열릴 때 바깥 트리에 inert 를 걸어 누른 이미지의
  // 초점이 먼저 body 로 빠지므로, 라이트박스 자체의 초점 복귀(직전 초점 요소 기억)는 이 경우 아무 데도 돌려주지 못한다.
  const zoomOriginRef = useRef<HTMLElement | null>(null);
  const wasZoomOpenRef = useRef(false);

  // sanitize된 HTML을 메모이제이션
  const sanitizedHtml = useMemo(() => {
    if (isEmpty || !isHtml) {
      return '';
    }

    // 기본 DOMPurify 설정 (FORBID 방식: 위험 태그/속성만 차단, 나머지 허용)
    const defaultConfig: any = {
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

    const config = purifyConfig
      ? {
          ...defaultConfig,
          ...purifyConfig,
          // 보안 기본값은 항상 유지 (사용자 설정으로 덮어쓰기 방지)
          FORBID_TAGS: [...defaultConfig.FORBID_TAGS, ...(purifyConfig.FORBID_TAGS || [])],
          FORBID_ATTR: [...defaultConfig.FORBID_ATTR, ...(purifyConfig.FORBID_ATTR || [])],
        }
      : defaultConfig;
    // 정화 결과를 문자열이 아니라 DOM 조각으로 받아 그 자리에서 고치고 한 번만 직렬화한다 — 종전에는 정화 결과
    // 문자열을 다시 파싱(DOMParser)해 고친 뒤 또 직렬화했다. 조각은 DOMPurify 가 만든 비활성 문서(DOMParser)에
    // 속해 이미지 요청이 나가지 않는다. DOMPurify 는 shadowroot 속성이 허용될 때만 조각을 화면 문서로 가져오므로
    // (importNode — 그 순간 원본 주소로 요청이 나간다) 그 두 속성은 허용 목록에서 뺀다. innerHTML 은 선언형
    // 섀도 루트를 만들지 않아 본문에서 쓸 곳이 없는 속성이다.
    const withoutShadowRoot = (list: unknown) =>
      Array.isArray(list) ? list.filter((name) => name !== 'shadowroot' && name !== 'shadowrootmode') : list;
    const fragment = DOMPurify.sanitize(actualContent, {
      ...config,
      ...(config.ADD_ATTR !== undefined ? { ADD_ATTR: withoutShadowRoot(config.ADD_ATTR) } : {}),
      ...(config.ALLOWED_ATTR !== undefined ? { ALLOWED_ATTR: withoutShadowRoot(config.ALLOWED_ATTR) } : {}),
      RETURN_DOM: false,
      RETURN_DOM_FRAGMENT: true,
      RETURN_TRUSTED_TYPE: false,
    }) as unknown as DocumentFragment;

    // target="_blank" 링크에 rel 속성 추가 (보안) — 외부(http·https) 링크에 rel 이 없을 때만
    fragment.querySelectorAll('a[href]').forEach((anchor) => {
      const href = anchor.getAttribute('href') ?? '';
      if ((href.startsWith('http://') || href.startsWith('https://')) && !anchor.hasAttribute('rel')) {
        anchor.setAttribute('rel', 'noopener noreferrer');
      }
    });

    // 정화 뒤 이미지 표시 처리 — 썸네일·srcset·지연 로딩, 링크 밖 이미지의 확대 보기 표식(저장 본문은 그대로).
    // 봇 화면 짝은 HtmlSanitizer::sanitizeContent 다.
    decorateContentImages(fragment, {
      imageSizes,
      zoomable,
      zoomLabel: (alt: string) => (alt !== '' ? t('common.zoom_image_named', { name: alt }) : t('common.zoom_image')),
    });

    // 직렬화는 조각과 같은 비활성 문서의 요소에 담아 한 번만 한다
    const holder = fragment.ownerDocument.createElement('div');
    holder.appendChild(fragment);

    return holder.innerHTML;
  }, [actualContent, isEmpty, isHtml, purifyConfig, imageSizes, zoomable]);

  // innerHTML 객체를 본문이 바뀔 때만 새로 만든다. React 19 는 dangerouslySetInnerHTML 을 객체 동일성으로 비교해, 렌더마다
  // 새 객체를 주면 같은 문자열이어도 innerHTML 을 다시 쓴다 — 확대 보기를 열고 닫는 상태 변화만으로 본문 이미지 요소가
  // 통째로 다시 만들어져(요청 재평가, 누른 이미지 초점 소실) 화면이 흔들렸다.
  const innerHtml = useMemo(() => ({ __html: sanitizedHtml }), [sanitizedHtml]);

  // 확대 보기가 닫히면(열림 → 닫힘) 누른 이미지로 초점을 돌려준다. 본문이 바뀌어 그 이미지가 문서에서 빠졌으면 두지 않는다.
  useEffect(() => {
    if (galleryProps.isOpen) {
      wasZoomOpenRef.current = true;
      return;
    }
    if (!wasZoomOpenRef.current) {
      return;
    }
    wasZoomOpenRef.current = false;
    const origin = zoomOriginRef.current;
    zoomOriginRef.current = null;
    if (origin && origin.isConnected) {
      origin.focus({ preventScroll: true });
    }
  }, [galleryProps.isOpen]);

  /**
   * 링크 밖 본문 이미지 확대 보기 — 컨테이너 한 곳에서 위임 처리한다.
   * 슬라이드는 같은 본문의 확대 대상 이미지 전부(원본 주소), 시작은 누른 이미지다.
   */
  const openZoom = useCallback((container: HTMLElement, target: EventTarget | null): boolean => {
    if (!(target instanceof Element)) {
      return false;
    }

    const image = target.closest(`img[${ZOOM_ATTR}]`);
    if (!image || !container.contains(image) || image.closest('a')) {
      return false;
    }

    const images = Array.from(container.querySelectorAll<HTMLImageElement>(`img[${ZOOM_ATTR}]`)).filter(
      (img) => !img.closest('a'),
    );
    const index = images.indexOf(image as HTMLImageElement);
    if (index === -1) {
      return false;
    }

    zoomOriginRef.current = image as HTMLElement;
    openGallery(
      images.map((img) => ({
        src: String(stripThumb(img.getAttribute('src') ?? '')),
        title: img.getAttribute('alt') ?? '',
      })),
      index,
    );

    return true;
  }, [openGallery]);

  const handleClick = useCallback((event: React.MouseEvent<HTMLElement>) => {
    if (openZoom(event.currentTarget, event.target)) {
      event.preventDefault();
    }
  }, [openZoom]);

  const handleKeyDown = useCallback((event: React.KeyboardEvent<HTMLElement>) => {
    if (event.key !== 'Enter' && event.key !== ' ' && event.key !== 'Spacebar') {
      return;
    }
    // 키를 누르고 있으면 keydown 이 반복된다 — 확대 보기를 닫자마자 같은 키 반복으로 다시 열리지 않게 첫 입력만 받는다
    if (event.repeat) {
      return;
    }
    if (openZoom(event.currentTarget, event.target)) {
      event.preventDefault();
    }
  }, [openZoom]);

  // 빈 값 처리
  if (isEmpty) {
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
    ${className}
  `.trim().replace(/\s+/g, ' ');

  return (
    <>
      <Div
        id={id}
        className={containerClasses}
        dangerouslySetInnerHTML={innerHtml}
        onClick={zoomable ? handleClick : undefined}
        onKeyDown={zoomable ? handleKeyDown : undefined}
        {...editorAttrs}
      />
      {zoomable && <ImageGallery {...galleryProps} />}
    </>
  );
};
