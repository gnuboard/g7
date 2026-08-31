import React from 'react';

import { sitePath } from '../../support/apiUrl';

export interface AProps extends React.AnchorHTMLAttributes<HTMLAnchorElement> {}

/**
 * 기본 a(링크) 컴포넌트
 *
 * `href` 가 사이트 내부 경로면 마운트 접두사를 붙여 내보낸다 — 이 컴포넌트가 렌더한
 * `<a>` 는 브라우저가 그대로 여는 주소이고 앵커 클릭을 가로채는 지점이 없다.
 * 접두사가 없으면 하위 폴더 배치(`https://host/g7/`)에서 링크가 사이트 밖으로 나가고,
 * 오류가 아니라 「엉뚱한 페이지가 떴다」로만 나타난다.
 *
 * 형제 템플릿(`sirsoft-basic`·`sirsoft-admin_basic`)은 14차에 같은 배선을 받았는데
 * 이 두 샘플 템플릿만 남아 있었다 — 게이트가 리터럴 `href` 만 보는데 이 파일에는
 * 그것이 없어 판정 밖이었다 (15차 G3 라운드).
 */
export const A: React.FC<AProps> = ({
  children,
  className = '',
  href,
  ...props
}) => {
  return (
    <a
      className={className}
      href={typeof href === 'string' ? sitePath(href) : href}
      {...props}
    >
      {children}
    </a>
  );
};
