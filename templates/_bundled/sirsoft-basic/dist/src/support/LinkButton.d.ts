import { default as React } from 'react';
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
export declare const LinkButton: React.FC<LinkButtonProps>;
