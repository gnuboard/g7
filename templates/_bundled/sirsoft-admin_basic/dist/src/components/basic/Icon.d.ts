import { default as React } from 'react';
import { IconName, IconStyle, IconSize } from './IconTypes';
export interface IconProps extends Omit<React.HTMLAttributes<HTMLElement>, 'style'> {
    /**
     * 아이콘 이름 (Font Awesome 클래스명 또는 IconName enum)
     *
     * @example
     * // 문자열로 직접 지정
     * <Icon name="fa-user" />
     *
     * // enum 사용 (타입 안전)
     * <Icon name={IconName.User} />
     */
    name: string | IconName;
    /**
     * 아이콘 스타일 (Font Awesome 5/6)
     *
     * - solid: fas (기본값)
     * - regular: far
     * - light: fal
     * - duotone: fad
     * - brands: fab
     */
    iconStyle?: IconStyle;
    /**
     * CSS 스타일 객체 (HTML style 속성)
     */
    style?: React.CSSProperties;
    /**
     * 아이콘 크기
     *
     * @default 'md'
     */
    size?: IconSize;
    /**
     * 색상 (Tailwind 클래스 또는 CSS 색상)
     */
    color?: string;
    /**
     * 회전 애니메이션 활성화
     */
    spin?: boolean;
    /**
     * 펄스 애니메이션 활성화 (8단계 회전)
     */
    pulse?: boolean;
    /**
     * 고정 너비 (아이콘 정렬 시 유용)
     */
    fixedWidth?: boolean;
    /**
     * 접근성 레이블 (자동 생성 가능)
     */
    ariaLabel?: string;
}
/**
 * Font Awesome 아이콘 컴포넌트
 *
 * Font Awesome 5/6 버전과 호환되며 다양한 props를 지원합니다.
 *
 * @example
 * // 기본 사용
 * <Icon name="fa-user" />
 *
 * // enum 사용 (타입 안전)
 * <Icon name={IconName.Search} />
 *
 * // 스타일 및 크기 지정
 * <Icon name={IconName.Heart} style="regular" size="2x" />
 *
 * // 애니메이션
 * <Icon name={IconName.Spinner} spin />
 * <Icon name={IconName.CircleNotch} pulse />
 *
 * // 색상 지정
 * <Icon name={IconName.Star} color="text-yellow-500" />
 *
 * // 접근성
 * <Icon name={IconName.User} ariaLabel="사용자 프로필" />
 */
export declare const Icon: React.FC<IconProps>;
