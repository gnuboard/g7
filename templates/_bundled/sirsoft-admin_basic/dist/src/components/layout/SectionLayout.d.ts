import { default as React } from 'react';
export interface SectionLayoutProps {
    /**
     * 섹션 제목
     */
    title?: string;
    /**
     * 섹션 부제목
     */
    subtitle?: string;
    /**
     * 패딩 적용
     */
    padding?: 'none' | 'sm' | 'md' | 'lg' | 'xl';
    /**
     * 배경색
     */
    background?: 'none' | 'white' | 'gray' | 'primary' | 'secondary';
    /**
     * 최대 너비 제한
     */
    maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '4xl' | '6xl' | '7xl' | 'full';
    /**
     * 중앙 정렬 여부
     */
    centered?: boolean;
    /**
     * 경계선 표시
     */
    border?: boolean;
    /**
     * 그림자 효과
     */
    shadow?: 'none' | 'sm' | 'md' | 'lg' | 'xl';
    /**
     * 둥근 모서리
     */
    rounded?: boolean;
    /**
     * 사용자 정의 클래스
     */
    className?: string;
    /**
     * 인라인 스타일
     */
    style?: React.CSSProperties;
    /**
     * 자식 요소
     */
    children?: React.ReactNode;
    /**
     * 클릭 이벤트 핸들러
     */
    onClick?: () => void;
}
/**
 * Section 레이아웃 컴포넌트
 *
 * 시맨틱 구역 분할을 위한 섹션 컴포넌트입니다.
 * 제목, 패딩, 배경색 등 다양한 옵션을 제공합니다.
 *
 * @example
 * // 기본 섹션
 * <Section padding="md" background="white">
 *   <p>Content here</p>
 * </Section>
 *
 * @example
 * // 제목이 있는 섹션
 * <Section
 *   title="사용자 정보"
 *   subtitle="개인 정보를 관리합니다"
 *   padding="lg"
 *   border
 * >
 *   <p>User details</p>
 * </Section>
 */
export declare const SectionLayout: React.FC<SectionLayoutProps>;
