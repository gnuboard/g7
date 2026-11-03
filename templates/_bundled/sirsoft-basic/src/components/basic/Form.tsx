import React from 'react';

import { sitePath } from '../../support/apiUrl';

export interface FormProps extends React.FormHTMLAttributes<HTMLFormElement> {}

/**
 * 기본 form 컴포넌트
 *
 * `action` 이 사이트 내부 경로면 마운트 접두사를 붙여 내보낸다.
 *
 * `<form action>` 은 **브라우저가 라우터 없이 직접 제출하는 주소**라 접두사를 붙일 주체가
 * 이 컴포넌트뿐이다 — `A` 의 `href` · `Img` 의 `src` 와 같은 축이다. 종전에는 `action`
 * 을 그대로 흘려 하위 폴더 배치에서 제출이 사이트 밖으로 나갔다 (15차 G3 라운드 W4-2).
 *
 * `sitePath()` 는 멱등이며, 루트 상대(`/…`)가 아닌 값(PG 절대 URL 등)은 그대로 돌려준다.
 */
export const Form: React.FC<FormProps> = ({
  children,
  className = '',
  action,
  ...props
}) => {
  return (
    <form
      className={className}
      action={typeof action === 'string' ? sitePath(action) : action}
      {...props}
    >
      {children}
    </form>
  );
};
