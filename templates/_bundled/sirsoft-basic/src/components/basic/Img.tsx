import React from 'react';

export interface ImgProps extends React.ImgHTMLAttributes<HTMLImageElement> {}

/**
 * `image` 위젯 값 객체의 키 집합 — 레이아웃 편집기가 만들던 `{url,size,repeat,position}`.
 *
 * 편집기는 이제 단일 값 슬롯에 url 문자열만 기록하고(코어 변환 계층), 이미 잘못 저장된
 * 레이아웃은 업그레이드 스텝이 정정한다. 이 상수는 **업그레이드 전 화면**을 위한
 * 런타임 방어이며, 판정 강도를 백필과 **완전히 같게** 유지한다 — 두 방어선의 기준이
 * 어긋나면 한쪽만 통과하는 값이 생긴다.
 */
const IMAGE_VALUE_KEYS = ['url', 'size', 'repeat', 'position'] as const;

/**
 * `src` 로 들어온 값에서 실제 이미지 URL 을 해석한다.
 *
 * 문자열이면 그대로(회귀 0). 이미지 값 객체(키 집합 ⊆ 4키 **AND** `url` 보유)면 그 url.
 * 그 외(배열·숫자·불리언·4키 밖 키를 가진 객체·비문자열 url)는 `undefined` 를 돌려주어
 * `src` 속성을 **아예 붙이지 않는다** — `src="[object Object]"`(엑박)도
 * `src=""`(현재 문서 재요청)도 만들지 않는다.
 *
 * `ImgProps` 를 넓히지 않는다(`src?: string` 계약 유지) — export 하면 그 자체가 공개
 * 계약이 되어 느슨한 타입이 다시 새어 나간다.
 */
function resolveImageSrc(src: unknown): string | undefined {
  if (typeof src === 'string') return src === '' ? undefined : src;
  if (!src || typeof src !== 'object' || Array.isArray(src)) return undefined;
  const keys = Object.keys(src as Record<string, unknown>);
  if (!keys.every((k) => (IMAGE_VALUE_KEYS as readonly string[]).includes(k))) return undefined;
  const url = (src as { url?: unknown }).url;
  return typeof url === 'string' && url !== '' ? url : undefined;
}

/**
 * 기본 이미지 컴포넌트
 */
export const Img: React.FC<ImgProps> = ({
  className = '',
  alt = '',
  src,
  ...props
}) => {
  return (
    <img
      className={className}
      alt={alt}
      src={resolveImageSrc(src as unknown)}
      {...props}
    />
  );
};
