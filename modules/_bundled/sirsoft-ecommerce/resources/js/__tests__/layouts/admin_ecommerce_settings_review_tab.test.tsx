// @vitest-environment jsdom
import '@testing-library/jest-dom';

/**
 * 환경설정 리뷰 탭 레이아웃 렌더링 테스트
 *
 * @description
 * - _tab_review_settings.json 렌더링 검증
 * - 3개 Input(작성기간, 최대이미지수, 최대용량) 구조 및 setState 검증
 * - 다국어 키 네임스페이스 검증
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import tabReviewSettings from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_review_settings.json';

// ─── 테스트용 컴포넌트 ───

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> =
    ({ className, children, 'data-testid': testId }) => (
        <div className={className} data-testid={testId}>{children}</div>
    );

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <span className={className}>{children || text}</span>;

const TestH3: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <p className={className}>{children || text}</p>;

const TestInput: React.FC<{
    type?: string; value?: string | number; min?: string | number; max?: string | number;
    step?: string | number; className?: string; disabled?: boolean;
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void; 'data-testid'?: string; name?: string;
}> = ({ type, value, min, max, className, disabled, onChange, 'data-testid': testId, name }) => (
    <input
        type={type} value={value} min={min} max={max}
        className={className} disabled={disabled}
        onChange={onChange} data-testid={testId} name={name}
    />
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    };
    return registry;
}

// ─── 다국어 ───

const translations = {
    'sirsoft-ecommerce': {
        admin: {
            settings: {
                review_settings: {
                    title: '리뷰 설정',
                    description: '리뷰 관련 설정을 관리합니다.',
                    write_deadline_days_label: '리뷰 작성 가능 기간',
                    write_deadline_days_hint: '구매확정 후 작성 가능한 기간입니다.',
                    max_images_label: '이미지 최대 업로드 수',
                    max_images_hint: '리뷰 당 업로드 가능한 최대 이미지 수입니다.',
                    max_image_size_mb_label: '이미지 최대 크기',
                    max_image_size_mb_hint: '업로드 가능한 이미지 최대 크기입니다.',
                    days_unit: '일',
                    images_unit: '장',
                },
            },
        },
    },
};

// ─── Partial 래퍼 레이아웃 ───
// Partial은 단독 렌더링 불가 — 최소한의 래퍼 레이아웃에 포함해서 테스트
const wrapperLayout = {
    version: '1.0.0',
    layout_name: 'test_review_settings_wrapper',
    components: [
        {
            id: 'wrapper',
            type: 'basic',
            name: 'Div',
            props: { 'data-testid': 'wrapper' },
            children: [tabReviewSettings],
        },
    ],
};

describe('리뷰 설정 탭 (_tab_review_settings.json) 렌더링', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;
    let registry: ComponentRegistry;

    beforeEach(() => {
        registry = setupTestRegistry();
        testUtils = createLayoutTest(wrapperLayout as any, {
            auth: {
                isAuthenticated: true,
                user: { id: 1, name: 'Admin', role: 'super_admin' },
                authType: 'admin',
            },
            translations,
            locale: 'ko',
            initialState: {
                _global: {
                    // partial 의 if 조건은 _global.activeEcommerceSettingsTab 를 참조
                    activeEcommerceSettingsTab: 'review_settings',
                },
                _local: {
                    form: {
                        review_settings: {
                            write_deadline_days: 90,
                            max_images: 5,
                            max_image_size_mb: 10,
                        },
                    },
                    isReadOnly: false,
                },
            },
            componentRegistry: registry,
        });
    });

    afterEach(() => {
        testUtils.cleanup();
    });

    // ─── JSON 구조 검증 (렌더링 전) ───

    describe('JSON 구조 검증', () => {
        it('is_partial 메타데이터가 설정되어 있다', () => {
            expect((tabReviewSettings as any).meta.is_partial).toBe(true);
        });

        it('tab_content_review_settings ID를 가진다', () => {
            expect((tabReviewSettings as any).id).toBe('tab_content_review_settings');
        });

        it('review_settings 탭 활성화 조건이 있다', () => {
            expect((tabReviewSettings as any).if).toContain('review_settings');
        });

        it('review_settings_card 카드가 1개 있다', () => {
            expect((tabReviewSettings as any).children).toHaveLength(1);
            expect((tabReviewSettings as any).children[0].id).toBe('review_settings_card');
        });

        it('카드 본문에 3개 설정 섹션이 있다', () => {
            const cardBody = (tabReviewSettings as any).children[0].children[1];
            // 3개 설정 + 2개 구분선 = 5개
            expect(cardBody.children.length).toBeGreaterThanOrEqual(3);
        });

        it('write_deadline_days Input이 min=1, max=365다', () => {
            const cardBody = (tabReviewSettings as any).children[0].children[1];
            const deadlineSection = cardBody.children[0];
            const input = deadlineSection.children.find((c: any) => c.name === 'Input');
            expect(input).toBeDefined();
            expect(String(input.props.min)).toBe('1');
            expect(String(input.props.max)).toBe('365');
        });

        it('max_images Input이 min=0, max=10이다', () => {
            const cardBody = (tabReviewSettings as any).children[0].children[1];
            const maxImagesSection = cardBody.children[2]; // 구분선(index 1) 건너뜀
            const input = maxImagesSection.children.find((c: any) => c.name === 'Input');
            expect(input).toBeDefined();
            expect(String(input.props.min)).toBe('0');
            expect(String(input.props.max)).toBe('10');
        });

        it('max_image_size_mb Input이 min=1, max=20이다', () => {
            const cardBody = (tabReviewSettings as any).children[0].children[1];
            const maxSizeSection = cardBody.children[4]; // 구분선(index 3) 건너뜀
            const input = maxSizeSection.children.find((c: any) => c.name === 'Input');
            expect(input).toBeDefined();
            expect(String(input.props.min)).toBe('1');
            expect(String(input.props.max)).toBe('20');
        });

        it('모든 Input의 onChange가 setState + hasChanges: true를 사용한다', () => {
            const cardBody = (tabReviewSettings as any).children[0].children[1];
            const inputSections = [cardBody.children[0], cardBody.children[2], cardBody.children[4]];
            for (const section of inputSections) {
                const input = section.children.find((c: any) => c.name === 'Input');
                if (!input) continue;
                const action = input.actions?.[0];
                expect(action?.handler).toBe('setState');
                expect(action?.params?.hasChanges).toBe(true);
                expect(action?.params?.target).toBe('local');
            }
        });
    });

    // ─── 렌더링 검증 ───

    describe('렌더링 검증', () => {
        it('레이아웃이 정상 렌더링된다', async () => {
            const { container } = await testUtils.render();
            expect(container.innerHTML.length).toBeGreaterThan(0);
        });

        it('리뷰 설정 제목이 렌더링된다', async () => {
            const { container } = await testUtils.render();
            // i18n 키 치환 결과가 자식 노드로 분리될 수 있어 단순 getByText 매칭이
            // 실패할 수 있음. card-title 클래스 H3 의 textContent 로 직접 검증한다
            const titleNode = container.querySelector('h3.card-title');
            expect(titleNode).not.toBeNull();
            expect(titleNode?.textContent ?? '').toContain('리뷰 설정');
        });

        it('레이아웃 검증 오류가 없어야 한다', async () => {
            await testUtils.render();
            expect(() => testUtils.assertNoValidationErrors()).not.toThrow();
        });
    });

    // ─── 상태 변경 검증 ───

    describe('상태 변경 검증', () => {
        it('write_deadline_days 상태를 변경할 수 있다', async () => {
            await testUtils.render();
            testUtils.setState('form.review_settings.write_deadline_days', 60, 'local');
            const state = testUtils.getState();
            expect(state._local.form?.review_settings?.write_deadline_days).toBe(60);
        });

        it('max_images 상태를 변경할 수 있다', async () => {
            await testUtils.render();
            testUtils.setState('form.review_settings.max_images', 3, 'local');
            const state = testUtils.getState();
            expect(state._local.form?.review_settings?.max_images).toBe(3);
        });

        it('max_image_size_mb 상태를 변경할 수 있다', async () => {
            await testUtils.render();
            testUtils.setState('form.review_settings.max_image_size_mb', 5, 'local');
            const state = testUtils.getState();
            expect(state._local.form?.review_settings?.max_image_size_mb).toBe(5);
        });
    });
});
