import React from 'react';

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

const t = (key: string, params?: Record<string, string | number>) =>
    (window as any).G7Core?.t?.(key, params) ?? key;

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
const PageLoading: React.FC<PageLoadingProps> = ({ options }) => {
    const isDark = document.documentElement.classList.contains('dark');

    const bgColor = isDark ? 'rgb(17,24,39)' : 'rgb(249,250,251)';
    const spinnerColor = isDark ? '#6b7280' : '#9ca3af';
    const textColor = isDark ? '#9ca3af' : '#6b7280';

    return (
        <div
            style={{
                position: 'absolute',
                inset: 0,
                zIndex: 2147483647,
                overflow: 'hidden',
                background: bgColor,
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'flex-start',
                paddingTop: '15%',
                gap: '12px',
            }}
        >
            <div
                style={{
                    width: '32px',
                    height: '32px',
                    border: `3px solid ${spinnerColor}`,
                    borderTopColor: 'transparent',
                    borderRadius: '50%',
                    animation: 'g7-spin 0.8s linear infinite',
                }}
            />
            <span style={{ color: textColor, fontSize: '14px' }}>
                {options?.text || t('nav.loading')}
            </span>
        </div>
    );
};

export default PageLoading;
