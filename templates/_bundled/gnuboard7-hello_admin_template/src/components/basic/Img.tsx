import React from 'react';

import { sitePath } from '../../support/apiUrl';
import { thumbSrcset, thumbUrl } from '../../support/thumbnail';

export interface ImgProps extends React.ImgHTMLAttributes<HTMLImageElement> {
  /**
   * 썸네일 규격 이름(`xs`·`sm`·`md`·`lg`·`xl`·운영자 규격). 지정하면 서빙 경로 이미지에 `size` 를 붙이고
   * `srcSet` 을 따로 주지 않았으면 1x·2x srcset 을 만든다. 외부·정적·`data:` 주소는 그대로다.
   * DOM 속성이 아니므로 `<img>` 로 내보내지 않는다.
   */
  thumbPreset?: string;
}

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
 *
 * `src` 가 사이트 내부 경로면 마운트 접두사를 붙여 내보낸다.
 *
 * `<img src>` 는 **브라우저가 라우터 없이 직접 받아오는 주소**라 접두사를 붙일 주체가 이
 * 컴포넌트뿐이다 — `A` 의 `href` 와 같은 축이다. 종전에는 `src` 를 그대로 흘려 하위
 * 폴더 배치(`https://host/g7/`)에서 레이아웃이 리터럴로 적은 `/images/…` 가 사이트
 * 밖을 가리켰다. 증상은 오류가 아니라 깨진 이미지뿐이다.
 *
 * `thumbPreset` 이 있으면 썸네일 주소와 srcset 을 만든다(판정은 코어 `G7Core.thumbnail`).
 *
 * `sitePath()` 는 멱등이며, 루트 상대(`/…`)가 아닌 값(절대 URL · `//` 프로토콜 상대 ·
 * `data:` · 상대 경로)은 그대로 돌려준다. 서버가 이미 접두사를 실어 준 값에 다시 붙지 않는다.
 */
export const Img: React.FC<ImgProps> = ({
  className = '',
  alt = '',
  src,
  thumbPreset,
  srcSet,
  loading,
  decoding,
  ...props
}) => {
  const resolved = resolveImageSrc(src as unknown);
  // 접두사를 먼저 붙인 주소로 썸네일을 계산한다 — 코어 판정은 접두사를 벗겨 대조하고 주소는 그대로 두므로
  // src 와 srcset 후보가 모두 같은 마운트 접두사를 갖는다.
  const sited = resolved === undefined ? undefined : sitePath(resolved);
  const preset = typeof thumbPreset === 'string' && thumbPreset.trim() !== '' ? thumbPreset.trim() : undefined;
  const finalSrc = sited !== undefined && preset ? thumbUrl(sited, preset) : sited;
  const finalSrcSet = srcSet ?? (sited !== undefined && preset ? thumbSrcset(sited, preset) : undefined);

  // 지연 로딩·비동기 디코딩이 기본이다(목록·썸네일 칸이 대부분). 첫 화면 이미지(로고·상세 메인·배너)는
  // 레이아웃이 `loading="eager"` 로 명시한다.
  return (
    <img
      className={className}
      alt={alt}
      src={finalSrc}
      srcSet={finalSrcSet}
      loading={loading ?? 'lazy'}
      decoding={decoding ?? 'async'}
      {...props}
    />
  );
};
