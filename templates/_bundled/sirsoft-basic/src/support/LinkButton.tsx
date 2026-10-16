/**
 * 화면 이동 단추를 링크로 그리는 내부 도우미 (sirsoft-basic 합성 컴포넌트 공용, #757)
 *
 * 머리글·모바일 메뉴·꼬리말·회원 메뉴처럼 「누르면 다른 화면으로 가는」 단추를 `<a href>` 로 그린다. 겉모습은 `Button` 과
 * 같게(같은 기본 클래스) 두고, 보통 클릭은 지금처럼 `onNavigate`(SPA 이동 + 메뉴 닫기 등)를 부른다. Ctrl·Meta·Shift·가운데
 * 단추는 막지 않아 브라우저가 새 탭·새 창을 연다. 주소가 링크가 될 수 없으면(빈 값·`#`·실행 스킴) 종전 `Button` 으로 그린다.
 * 바깥 주소(`http(s)://` 다른 출처)는 새 탭으로 연다.
 */

import React from 'react';
import { A } from '../components/basic/A';
import { Button } from '../components/basic/Button';
import { handleLinkClick, resolveLinkHref } from './navigationLink';

/** Button 과 같은 기본 꾸밈 — 링크로 바꿔도 겉모습이 그대로이게 한다 */
const BASE_CLASSES = 'inline-flex items-center justify-center';

export interface LinkButtonProps extends Omit<React.AnchorHTMLAttributes<HTMLAnchorElement>, 'href' | 'onClick'> {
  /** 이동할 주소 */
  href: string | null | undefined;
  /** 보통 클릭일 때 할 일(SPA 이동·메뉴 닫기 등) */
  onNavigate: () => void;
  /** 클릭이 감싼 행(목록 행 클릭 등)으로 올라가지 않게 막는다 — 수정키 클릭도 막는다(행 이동이 같이 일어나지 않게) */
  stopPropagation?: boolean;
}

/**
 * 이동 단추를 링크로 그립니다.
 *
 * @param props 링크 속성
 * @return 링크(또는 주소가 링크가 될 수 없을 때 단추)
 */
export const LinkButton: React.FC<LinkButtonProps> = ({ href, onNavigate, stopPropagation = false, className = '', children, ...rest }) => {
  const link = resolveLinkHref(href);
  if (!link) {
    return (
      <Button
        type="button"
        className={className}
        onClick={(event: React.MouseEvent) => {
          if (stopPropagation) event.stopPropagation();
          onNavigate();
        }}
        {...(rest as Record<string, unknown>)}
      >
        {children}
      </Button>
    );
  }

  const merge = (window as any).G7Core?.style?.mergeClasses;
  const mergedClassName = typeof merge === 'function' ? merge(BASE_CLASSES, className) : `${BASE_CLASSES} ${className}`;

  return (
    <A
      href={link.href}
      className={mergedClassName}
      target={link.external ? '_blank' : undefined}
      rel={link.external ? 'noopener noreferrer' : undefined}
      onClick={(event) => {
        if (stopPropagation) event.stopPropagation();
        if (link.external) return;
        handleLinkClick(event, onNavigate);
      }}
      {...rest}
    >
      {children}
    </A>
  );
};
