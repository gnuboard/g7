import React, { useEffect, useState } from 'react';
import type { PopupImage } from './types';
import { overlayStyle, type PublicOverlayBox } from './overlays';

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
export const ImageFrame: React.FC<ImageFrameProps> = ({ image, alt, eager = false, boxes = [], children, onRatio }) => {
  const [natural, setNatural] = useState<{ src: string; w: number; h: number } | null>(null);
  const known = image.width && image.height ? { w: image.width, h: image.height } : null;
  const measured = natural && natural.src === image.url ? natural : null;
  const size = known ?? measured;
  const ratio = size ? size.w / size.h : null;

  useEffect(() => {
    if (ratio) onRatio?.(ratio);
  }, [ratio, image.url]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div
      className={`g7pp-media__frame${size ? '' : ' g7pp-media__frame--unsized'}`}
      style={size ? { ['--g7pp-ratio' as string]: String(size.w / size.h) } : undefined}
      data-ratio={size ? (size.w / size.h).toFixed(4) : undefined}
    >
      <img
        src={image.url}
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
