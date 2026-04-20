import { default as React } from 'react';
/**
 * 페이지 로딩 컴포넌트 Props (엔진에서 전달)
 *
 * @since engine-v1.29.0
 */
export interface PageLoadingProps {
    options?: {
        text?: string;
    };
}
/**
 * 어드민 페이지 로딩 인디케이터 컴포넌트
 *
 * `transition_overlay` 의 spinner 스타일에서 사용된다.
 * 엔진은 타겟 요소 내부에 빈 컨테이너만 삽입하며, 포지셔닝/배경/z-index/다크모드
 * 등 모든 비주얼 스타일은 이 컴포넌트가 결정한다. React 트리 외부에 렌더링되므로
 * 인라인 스타일을 사용한다.
 *
 * @since engine-v1.29.0
 */
declare const PageLoading: React.FC<PageLoadingProps>;
export default PageLoading;
