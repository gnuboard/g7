import { default as React } from 'react';
/**
 * 레이아웃 컴포넌트 정의 (엔진에서 전달하는 트리 구조)
 */
interface LayoutComponent {
    name?: string;
    type?: string;
    className?: string;
    props?: Record<string, any>;
    children?: LayoutComponent[];
    iteration?: {
        source?: string;
        item_var?: string;
        index_var?: string;
    };
    if?: string;
    responsive?: {
        desktop?: {
            props?: Record<string, any>;
        };
        tablet?: {
            props?: Record<string, any>;
        };
    };
}
/**
 * 스켈레톤 컴포넌트 Props (엔진에서 전달)
 */
export interface PageSkeletonProps {
    /** 레이아웃 JSON의 components 배열 (전체 컴포넌트 트리) */
    components: LayoutComponent[];
    /** 스켈레톤 옵션 */
    options: {
        animation: 'pulse' | 'wave' | 'none';
        iteration_count: number;
    };
}
/**
 * PageSkeleton — 단일 스켈레톤 렌더러 컴포넌트
 *
 * 엔진이 레이아웃 JSON의 컴포넌트 트리를 props로 전달하면,
 * 재귀적으로 순회하며 각 컴포넌트의 스켈레톤 플레이스홀더를 생성합니다.
 *
 * - 레이아웃 컨테이너(Div, Flex, Grid 등): className 유지, 자식 순회
 * - 텍스트 컴포넌트(H1~H6, P 등): 회색 바
 * - 인풋 컴포넌트(Input, Select 등): 사각형
 * - 미디어 컴포넌트(Img, Avatar 등): 큰 사각형
 * - 복합 컴포넌트(DataGrid, Tabs 등): 특화 스켈레톤
 * - iteration 블록: iteration_count 횟수만큼 반복
 * - 조건부 분기: 로딩/에러 상태 스킵, 콘텐츠 브랜치만 렌더
 *
 * @since engine-v1.24.0
 */
export declare const PageSkeleton: React.FC<PageSkeletonProps>;
export default PageSkeleton;
