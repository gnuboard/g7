import React, { useEffect, useState } from 'react';
import type { PopupImage } from './types';
import { overlayStyle, type PublicOverlayBox } from './overlays';
import { sitePath } from '../apiUrl';
import { mediaSrc, responsiveSrcset } from './thumbnail';

/** 이미지 프레임 props */
export interface ImageFrameProps {
  /** 보여 줄 이미지 */
  image: PopupImage;

  /** 대체 텍스트 (덮는 링크가 이름을 가지면 빈 값) */
  alt: string;

  /** 첫 장인지 (바로 받는다) */
  eager?: boolean;

  /** 이미지 위 글 상자 (글은 이미 고른 문자열) */
  boxes?: PublicOverlayBox[];

  /** 프레임 안에 더 그릴 것 (편집기의 손잡이 등) — 프레임 크기를 알 때만 그린다 */
  children?: React.ReactNode;

  /** 이미지 비율(가로÷세로)을 알게 되면 알린다 — 편집기가 이미지 교체로 비율이 바뀐 것을 안다 */
  onRatio?: (ratio: number) => void;

  /**
   * 이미지가 그려질 폭의 상한(`<img sizes>`) — 주면 코어 썸네일 srcset 을 붙인다(원본 가로를 알 때만).
   * 사용자 레이어·미리보기는 레이어 배치로 정한 값을 준다. 글 상자 편집기는 주지 않아 원본 그대로 그린다.
   */
  sizes?: string;
}

/**
 * 이미지와 그 위 글 상자를 그리는 프레임 — 사용자 레이어·미리보기·관리자 편집기가 함께 쓴다.
 *
 * 프레임은 이미지 비율(`aspect-ratio`)로 감싸는 칸 안에 담기는 가장 큰 크기라, 이미지를 잘리지 않게 담는(`contain`)
 * 것과 같은 사각형이 된다. 상자는 그 프레임 기준 % 로 놓여 이미지가 커지고 작아지는 만큼 함께 움직인다.
 * 이미지 크기를 모르면(서버가 읽지 못함) 받은 뒤 `naturalWidth/Height` 로 비율을 정하고, 그 전에는 상자를 그리지 않는다.
 *
 * @param props 프레임 props
 * @returns 프레임
 */
export const ImageFrame: React.FC<ImageFrameProps> = ({ image, alt, eager = false, boxes = [], children, onRatio, sizes }) => {
  const [natural, setNatural] = useState<{ src: string; w: number; h: number } | null>(null);
  const known = image.width && image.height ? { w: image.width, h: image.height } : null;
  const measured = natural && natural.src === image.url ? natural : null;
  const size = known ?? measured;
  const ratio = size ? size.w / size.h : null;
  // 썸네일 후보의 기준은 공개 API 주소(같은 출처), 원본 한 장(src·srcset 원본 후보)은 직접 주소가 있으면 그것
  const api = sitePath(image.url);
  const src = sitePath(mediaSrc(image) ?? image.url);
  // 원본 가로를 알 때만 — 모르면 받은 뒤 naturalWidth 로 비율을 재는데, 가로 기술자 후보가 그 값을 바꾸지 않게 원본 하나만 쓴다
  const srcSet = sizes && known ? responsiveSrcset(api, known.w, src) : undefined;

  useEffect(() => {
    if (ratio) onRatio?.(ratio);
  }, [ratio, image.url]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div
      className={`g7pp-media__frame${size ? '' : ' g7pp-media__frame--unsized'}`}
      style={size ? { ['--g7pp-ratio' as string]: String(size.w / size.h) } : undefined}
      data-ratio={size ? (size.w / size.h).toFixed(4) : undefined}
    >
      {/* 브라우저가 그대로 받는 주소라 하위 폴더 설치의 마운트 접두사를 붙인다(멱등, 바깥 주소는 그대로) — 서버가 붙이기 전에 담긴 캐시도 맞춘다 */}
      <img
        // 이미지가 바뀌면(기기 전환으로 PC ↔ 휴대폰) 같은 img 를 갱신하지 않고 새로 만든다 — 갱신하면 새 sizes 가 옛 srcset 과 먼저 만나
        // WebKit 이 옛 이미지를 한 번 더 고른다(브라우저 실측). 새로 만들면 React 가 sizes 를 먼저, srcSet·src 를 맨 뒤에 한 번에 붙인다
        key={src}
        // 속성 순서 sizes → srcSet → src 를 지킨다 — 같은 이미지에서 sizes 만 바뀌는 갱신도 이 순서로 붙는다.
        // WebKit 은 srcset 이 sizes 보다 먼저 붙으면 기본 100vw 로 원본을 한 번 더 받는다
        sizes={srcSet ? sizes : undefined}
        srcSet={srcSet}
        src={src}
        alt={alt}
        width={image.width ?? undefined}
        height={image.height ?? undefined}
        loading={eager ? 'eager' : 'lazy'}
        fetchPriority={eager ? 'high' : 'auto'}
        className="g7pp-media__img"
        draggable={false}
        onLoad={known ? undefined : (event) => {
          const img = event.currentTarget;
          if (img.naturalWidth > 0 && img.naturalHeight > 0) {
            setNatural({ src: image.url, w: img.naturalWidth, h: img.naturalHeight });
          }
        }}
      />
      {size && boxes.map((box) => (
        <div key={box.id} className="g7pp-overlay" style={overlayStyle(box)} data-overlay-id={box.id}>
          {box.text}
        </div>
      ))}
      {size && children}
    </div>
  );
};

export default ImageFrame;
