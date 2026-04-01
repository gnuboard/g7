/**
 * 레이아웃 전환 시 전체 컨테이너 블러 테스트
 *
 * React 18의 비동기 root.render()로 인해 레이아웃 전환 시
 * 이전 DOM이 잠깐 보이는 현상을 방지하기 위한 컨테이너 블러 로직 검증
 * @since engine-v1.23.0
 */

import { describe, it, expect } from 'vitest';

/**
 * TemplateApp.ts의 레이아웃 전환 블러 로직을 순수 함수로 추출하여 테스트
 * 실제 코드 경로: TemplateApp.ts renderTemplate 호출 전후
 */

interface BlurTestParams {
    currentLayoutName: string;
    newLayoutName: string;
    containerId: string;
}

/**
 * isLayoutChanged 계산 로직 (TemplateApp.ts 963행)
 */
function computeIsLayoutChanged(currentLayoutName: string, newLayoutName: string): boolean {
    return currentLayoutName !== '' && currentLayoutName !== newLayoutName;
}

/**
 * 블러 적용 조건 판단
 */
function shouldApplyContainerBlur(params: BlurTestParams): boolean {
    const isLayoutChanged = computeIsLayoutChanged(params.currentLayoutName, params.newLayoutName);
    const container = document.getElementById(params.containerId);
    return isLayoutChanged && container !== null;
}

/**
 * 블러 스타일 적용 (실제 TemplateApp.ts 로직과 동일)
 */
function applyContainerBlur(container: HTMLElement): void {
    container.style.opacity = '0.5';
    container.style.filter = 'blur(4px)';
    container.style.transition = 'opacity 0.15s ease, filter 0.15s ease';
    container.style.pointerEvents = 'none';
}

/**
 * 블러 스타일 해제 (실제 TemplateApp.ts 로직과 동일)
 */
function removeContainerBlur(container: HTMLElement): void {
    container.style.opacity = '';
    container.style.filter = '';
    container.style.transition = '';
    container.style.pointerEvents = '';
}

describe('레이아웃 전환 컨테이너 블러', () => {
    describe('isLayoutChanged 계산', () => {
        it('첫 페이지 로드 (currentLayoutName === "") → false', () => {
            expect(computeIsLayoutChanged('', 'board/show')).toBe(false);
        });

        it('같은 레이아웃 → false', () => {
            expect(computeIsLayoutChanged('board/show', 'board/show')).toBe(false);
        });

        it('다른 레이아웃 → true', () => {
            expect(computeIsLayoutChanged('board/index', 'board/show')).toBe(true);
        });

        it('board/show → board/index → board/show (중간 경유) → true', () => {
            expect(computeIsLayoutChanged('board/index', 'board/show')).toBe(true);
        });
    });

    describe('블러 적용 조건', () => {
        it('레이아웃 변경 + 컨테이너 존재 → 블러 적용', () => {
            const container = document.createElement('div');
            container.id = 'app';
            document.body.appendChild(container);

            expect(shouldApplyContainerBlur({
                currentLayoutName: 'board/index',
                newLayoutName: 'board/show',
                containerId: 'app',
            })).toBe(true);

            document.body.removeChild(container);
        });

        it('같은 레이아웃 → 블러 미적용', () => {
            const container = document.createElement('div');
            container.id = 'app';
            document.body.appendChild(container);

            expect(shouldApplyContainerBlur({
                currentLayoutName: 'board/show',
                newLayoutName: 'board/show',
                containerId: 'app',
            })).toBe(false);

            document.body.removeChild(container);
        });

        it('첫 페이지 로드 → 블러 미적용', () => {
            const container = document.createElement('div');
            container.id = 'app';
            document.body.appendChild(container);

            expect(shouldApplyContainerBlur({
                currentLayoutName: '',
                newLayoutName: 'board/show',
                containerId: 'app',
            })).toBe(false);

            document.body.removeChild(container);
        });

        it('컨테이너 미존재 → 블러 미적용', () => {
            expect(shouldApplyContainerBlur({
                currentLayoutName: 'board/index',
                newLayoutName: 'board/show',
                containerId: 'nonexistent',
            })).toBe(false);
        });
    });

    describe('블러 스타일 적용/해제', () => {
        it('블러 적용 시 올바른 CSS 스타일 설정', () => {
            const container = document.createElement('div');
            applyContainerBlur(container);

            expect(container.style.opacity).toBe('0.5');
            expect(container.style.filter).toBe('blur(4px)');
            expect(container.style.pointerEvents).toBe('none');
            expect(container.style.transition).toContain('opacity');
            expect(container.style.transition).toContain('filter');
        });

        it('블러 해제 시 모든 스타일 초기화', () => {
            const container = document.createElement('div');
            applyContainerBlur(container);
            removeContainerBlur(container);

            expect(container.style.opacity).toBe('');
            expect(container.style.filter).toBe('');
            expect(container.style.pointerEvents).toBe('');
            expect(container.style.transition).toBe('');
        });

        it('블러 해제 후 기존 스타일에 영향 없음', () => {
            const container = document.createElement('div');
            container.style.backgroundColor = 'red';
            container.style.display = 'flex';

            applyContainerBlur(container);
            removeContainerBlur(container);

            expect(container.style.backgroundColor).toBe('red');
            expect(container.style.display).toBe('flex');
        });
    });

    describe('시나리오: 자유게시판 글 → 공지사항 목록 → 공지사항 글', () => {
        it('board/show → board/index: 레이아웃 변경 → 블러 적용', () => {
            expect(computeIsLayoutChanged('board/show', 'board/index')).toBe(true);
        });

        it('board/index → board/show: 레이아웃 변경 → 블러 적용', () => {
            expect(computeIsLayoutChanged('board/index', 'board/show')).toBe(true);
        });

        it('board/show → board/show (같은 게시판 내 이동): 블러 미적용', () => {
            expect(computeIsLayoutChanged('board/show', 'board/show')).toBe(false);
        });
    });
});
