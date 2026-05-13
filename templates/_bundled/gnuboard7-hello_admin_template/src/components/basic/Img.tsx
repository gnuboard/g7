import React from 'react';

export interface ImgProps extends React.ImgHTMLAttributes<HTMLImageElement> {}

/**
 * 기본 이미지 컴포넌트
 */
export const Img: React.FC<ImgProps> = ({
  className = '',
  alt = '',
  ...props
}) => {
  return <img className={className} alt={alt} {...props} />;
};
