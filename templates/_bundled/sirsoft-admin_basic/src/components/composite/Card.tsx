import React from 'react';
import { Div } from '../basic/Div';
import { H2 } from '../basic/H2';
import { P } from '../basic/P';
import { Img } from '../basic/Img';

export interface CardProps {
  title?: string;
  content?: string;
  imageUrl?: string;
  imageAlt?: string;
  className?: string;
  onClick?: () => void;
  style?: React.CSSProperties;
}

/**
 * Card 집합 컴포넌트
 *
 * 기본 컴포넌트를 조합하여 카드 UI를 구성합니다.
 * 레이아웃 JSON에서 title, content, imageUrl 등의 props만 전달하면
 * 내부적으로 Div, H2, P, Img 컴포넌트를 조합하여 완성된 카드를 렌더링합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Card",
 *   "props": {
 *     "title": "사용자 정보",
 *     "content": "홍길동 (hong@example.com)",
 *     "imageUrl": "/avatar.png"
 *   }
 * }
 */
export const Card: React.FC<CardProps> = ({
  title,
  content,
  imageUrl,
  imageAlt = '',
  className = '',
  onClick,
  style,
}) => {
  return (
    <Div
      className={`rounded-lg bg-white dark:bg-gray-800 shadow-md hover:shadow-lg transition-shadow border border-gray-200 dark:border-gray-700 overflow-hidden ${className}`}
      onClick={onClick}
      style={style}
    >
      {imageUrl && (
        <Div className="w-full">
          <Img
            src={imageUrl}
            alt={imageAlt}
            className="w-full h-48 object-cover"
          />
        </Div>
      )}

      <Div className="p-4">
        {title && (
          <H2 className="text-xl font-semibold text-gray-800 dark:text-white mb-2">
            {title}
          </H2>
        )}

        {content && (
          <P className="text-gray-600 dark:text-gray-300 text-sm whitespace-pre-line">
            {content}
          </P>
        )}
      </Div>
    </Div>
  );
};
