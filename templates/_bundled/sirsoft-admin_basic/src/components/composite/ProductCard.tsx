import React from 'react';
import { Div } from '../basic/Div';
import { Img } from '../basic/Img';
import { H2 } from '../basic/H2';
import { H3 } from '../basic/H3';
import { P } from '../basic/P';
import { Span } from '../basic/Span';
import { Button } from '../basic/Button';

export interface ProductCardProps {
  imageUrl?: string;
  imageAlt?: string;
  title: string;
  subtitle?: string;
  description?: string;
  price: number;
  originalPrice?: number;
  discountRate?: number;
  currency?: string;
  buttonText?: string;
  onButtonClick?: () => void;
  className?: string;
  style?: React.CSSProperties;
}

/**
 * ProductCard 집합 컴포넌트
 *
 * 상품 정보를 표시하는 카드 컴포넌트입니다.
 * 이미지, 제목, 부제목, 설명, 가격, 할인율, 액션 버튼을 포함합니다.
 *
 * 기본 컴포넌트 조합: Div + Img + H2 + H3 + P + Span + Button
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "ProductCard",
 *   "props": {
 *     "imageUrl": "/product.png",
 *     "title": "프리미엄 제품",
 *     "subtitle": "신제품",
 *     "description": "최고급 소재로 제작된 프리미엄 제품입니다.",
 *     "price": 45000,
 *     "originalPrice": 50000,
 *     "discountRate": 10,
 *     "buttonText": "구매하기"
 *   }
 * }
 */
export const ProductCard: React.FC<ProductCardProps> = ({
  imageUrl,
  imageAlt = '',
  title,
  subtitle,
  description,
  price,
  originalPrice,
  discountRate,
  currency = '₩',
  buttonText = '자세히 보기',
  onButtonClick,
  className = '',
  style,
}) => {
  const hasDiscount = discountRate && discountRate > 0;
  const formattedPrice = price.toLocaleString();
  const formattedOriginalPrice = originalPrice?.toLocaleString();

  return (
    <Div
      className={`rounded-lg bg-white dark:bg-gray-800 shadow-md hover:shadow-xl transition-shadow border border-gray-200 dark:border-gray-700 overflow-hidden ${className}`}
      style={style}
    >
      {/* 상품 이미지 */}
      {imageUrl && (
        <Div className="relative w-full">
          <Img
            src={imageUrl}
            alt={imageAlt || title}
            className="w-full h-64 object-cover"
          />
          {hasDiscount && (
            <Div className="absolute top-2 right-2 bg-red-500 text-white px-2 py-1 rounded-md">
              <Span className="text-sm font-bold">-{discountRate}%</Span>
            </Div>
          )}
        </Div>
      )}

      {/* 상품 정보 */}
      <Div className="p-4">
        {/* 부제목 (태그/카테고리) */}
        {subtitle && (
          <H3 className="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wider mb-2">
            {subtitle}
          </H3>
        )}

        {/* 제목 */}
        <H2 className="text-xl font-bold text-gray-900 dark:text-white mb-2 line-clamp-2">
          {title}
        </H2>

        {/* 설명 */}
        {description && (
          <P className="text-sm text-gray-600 dark:text-gray-400 mb-4 line-clamp-3">
            {description}
          </P>
        )}

        {/* 가격 정보 */}
        <Div className="flex items-center gap-2 mb-4">
          <Span className="text-2xl font-bold text-gray-900 dark:text-white">
            {currency}{formattedPrice}
          </Span>
          {originalPrice && (
            <Span className="text-sm text-gray-400 dark:text-gray-500 line-through">
              {currency}{formattedOriginalPrice}
            </Span>
          )}
        </Div>

        {/* 액션 버튼 */}
        {buttonText && (
          <Button
            onClick={onButtonClick}
            className="w-full bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition-colors"
          >
            {buttonText}
          </Button>
        )}
      </Div>
    </Div>
  );
};
