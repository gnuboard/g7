/**
 * PageSkeleton 가로 줄 컨테이너 테스트
 *
 * @description 가로 상품 줄(ProductRail)·분류 칩 줄(ChipRows)은 자식(카드·칩)을 레이아웃이 넘기고
 *   줄 배치 클래스는 컴포넌트 안에 있다. 불러오는 동안의 뼈대가 범용 div 로 떨어지면 카드가
 *   세로로 쌓여 실제 화면과 모양이 달라지므로, 같은 가로 배치로 감싸는지 잠근다.
 *
 * @scenario-manifest tests/scenarios/page-skeleton-rails.yaml
 */

import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { PageSkeleton } from '../PageSkeleton';

describe('PageSkeleton — 가로 줄 컨테이너', () => {
    const defaultOptions = { animation: 'pulse' as const, iteration_count: 3 };

    // @scenario case=c01
    // @effects skeleton_product_rail_is_horizontal_track
    it('ProductRail 은 자식 카드를 실제와 같은 가로 줄(grid-flow-col)로 그려야 한다', () => {
        const components = [
            {
                name: 'ProductRail',
                type: 'composite',
                props: { title: '인기 상품' },
                children: [
                    { name: 'ProductCard', type: 'composite', iteration: { source: 'items', item_var: 'p' } },
                ],
            },
        ];

        const { container } = render(
            <PageSkeleton components={components} options={defaultOptions} />
        );

        const track = container.querySelector('.grid-flow-col');
        expect(track).toBeTruthy();
        expect(track!.className).toContain('auto-cols-[calc(50%_-_8px)]');
        expect(track!.className).toContain('lg:auto-cols-[calc(25%_-_12px)]');
        // iteration_count(3)만큼 카드 뼈대가 같은 줄 안에
        expect(track!.querySelectorAll('.h-48').length).toBe(3);
    });

    // @scenario case=c02
    // @effects skeleton_chip_rows_is_wrapping_row
    it('ChipRows 는 자식 칩을 측정 전 모양과 같은 줄바꿈 가로 배치(flex-wrap)로 그려야 한다', () => {
        const components = [
            {
                name: 'ChipRows',
                type: 'composite',
                children: [
                    { name: 'A', type: 'basic', iteration: { source: 'cats', item_var: 'c' } },
                ],
            },
        ];

        const { container } = render(
            <PageSkeleton components={components} options={defaultOptions} />
        );

        const row = container.querySelector('nav .flex.flex-wrap');
        expect(row).toBeTruthy();
        expect(row!.children.length).toBe(3);
    });
});
