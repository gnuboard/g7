/**
 * 리뷰설정 탭 레이아웃 구조 검증 테스트
 *
 * @description
 * - _tab_review_settings.json 구조 검증
 * - 리뷰 작성 기한, 최대 이미지 수, 최대 이미지 크기 설정 항목 검증
 * - 폼 바인딩 및 핸들러 검증
 * - 다국어 키 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import tabReviewSettings from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_review_settings.json';

/**
 * 재귀적으로 컴포넌트 트리에서 id로 검색
 */
function findById(node: any, id: string): any | null {
    if (!node) return null;
    if (node.id === id) return node;
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findById(child, id);
            if (found) return found;
        }
    }
    return null;
}

/**
 * 재귀적으로 컴포넌트 트리에서 name으로 모든 항목 검색
 */
function findAllByName(node: any, name: string): any[] {
    const results: any[] = [];
    if (!node) return results;
    if (node.name === name) results.push(node);
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...findAllByName(child, name));
        }
    }
    if (node.itemTemplate) {
        results.push(...findAllByName(node.itemTemplate, name));
    }
    return results;
}

/**
 * 재귀적으로 $t: 다국어 키 수집
 */
function collectI18nKeys(node: any): string[] {
    const keys: string[] = [];
    if (!node) return keys;

    // text 속성에서 $t: 키 추출
    if (typeof node.text === 'string' && node.text.startsWith('$t:')) {
        keys.push(node.text.replace('$t:', ''));
    }
    // props 내부 문자열 검색
    if (node.props) {
        for (const val of Object.values(node.props)) {
            if (typeof val === 'string' && val.startsWith('$t:')) {
                keys.push(val.replace('$t:', ''));
            }
            // options 배열 내부
            if (Array.isArray(val)) {
                for (const opt of val as any[]) {
                    if (opt && typeof opt.label === 'string' && opt.label.startsWith('$t:')) {
                        keys.push(opt.label.replace('$t:', ''));
                    }
                }
            }
        }
    }

    // 자식/itemTemplate 재귀
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            keys.push(...collectI18nKeys(child));
        }
    }
    if (node.itemTemplate) {
        keys.push(...collectI18nKeys(node.itemTemplate));
    }
    return keys;
}

// ─── _tab_review_settings.json 구조 검증 ───

describe('리뷰설정 탭 구조 검증 (_tab_review_settings.json)', () => {
    const tab = tabReviewSettings as any;

    describe('탭 메인 구조', () => {
        it('is_partial 메타데이터가 설정되어야 한다', () => {
            expect(tab.meta.is_partial).toBe(true);
        });

        it('tab_content_review_settings ID를 가져야 한다', () => {
            expect(tab.id).toBe('tab_content_review_settings');
        });

        it('review_settings 탭 활성화 조건이 있어야 한다', () => {
            expect(tab.if).toContain('review_settings');
        });

        it('1개 카드 섹션을 포함해야 한다', () => {
            expect(tab.children).toHaveLength(1);
        });
    });

    describe('카드 ID 검증', () => {
        it('review_settings_card가 존재해야 한다', () => {
            expect(tab.children[0].id).toBe('review_settings_card');
        });
    });

    describe('리뷰설정 카드 구조', () => {
        const card = tab.children[0];

        it('card 클래스를 가져야 한다', () => {
            expect(card.props.className).toBe('card');
        });

        it('카드 헤더에 제목이 있어야 한다', () => {
            const header = card.children[0];
            expect(header.props.className).toBe('card-header');
            const titleEl = header.children.find((c: any) => c.name === 'H3');
            expect(titleEl).toBeDefined();
            expect(titleEl.text).toBe(
                '$t:sirsoft-ecommerce.admin.settings.review_settings.title',
            );
        });

        it('카드 헤더에 설명이 있어야 한다', () => {
            const header = card.children[0];
            const descEl = header.children.find((c: any) => c.name === 'P');
            expect(descEl).toBeDefined();
            expect(descEl.text).toBe(
                '$t:sirsoft-ecommerce.admin.settings.review_settings.description',
            );
        });
    });

    describe('write_deadline_days 설정 항목', () => {
        const card = tab.children[0];
        // 설정 항목 영역: card.children[1] (space-y-4 Div)
        // 첫 번째 항목 (write_deadline_days): children[0]
        const settingsArea = card.children[1];
        const writeDeadlineSection = settingsArea.children[0];

        it('레이블 텍스트가 다국어 키를 사용해야 한다', () => {
            const labelEl = writeDeadlineSection.children.find(
                (c: any) => c.name === 'Span' && typeof c.text === 'string',
            );
            expect(labelEl).toBeDefined();
            expect(labelEl.text).toBe(
                '$t:sirsoft-ecommerce.admin.settings.review_settings.write_deadline_days_label',
            );
        });

        it('Input이 type=number, min=1, max=365여야 한다', () => {
            const input = writeDeadlineSection.children.find((c: any) => c.name === 'Input');
            expect(input).toBeDefined();
            expect(input.props.type).toBe('number');
            expect(String(input.props.min)).toBe('1');
            expect(String(input.props.max)).toBe('365');
        });

        it('Input 기본값이 30을 참조해야 한다', () => {
            const input = writeDeadlineSection.children.find((c: any) => c.name === 'Input');
            expect(input.props.value).toContain('write_deadline_days');
            expect(input.props.value).toContain('30');
        });

        it('Input onChange가 setState 핸들러를 사용해야 한다', () => {
            const input = writeDeadlineSection.children.find((c: any) => c.name === 'Input');
            const action = input.actions[0];
            expect(action.handler).toBe('setState');
            expect(action.params.target).toBe('local');
        });

        it('Input onChange가 write_deadline_days를 설정해야 한다', () => {
            const input = writeDeadlineSection.children.find((c: any) => c.name === 'Input');
            const action = input.actions[0];
            const paramKeys = Object.keys(action.params);
            const deadlineKey = paramKeys.find((k) => k.includes('write_deadline_days'));
            expect(deadlineKey).toBeDefined();
        });

        it('Input onChange에 hasChanges=true가 포함되어야 한다', () => {
            const input = writeDeadlineSection.children.find((c: any) => c.name === 'Input');
            const action = input.actions[0];
            expect(action.params.hasChanges).toBe(true);
        });

        it('단위 텍스트 Span이 있어야 한다', () => {
            const spans = writeDeadlineSection.children.filter((c: any) => c.name === 'Span');
            // 레이블 Span + 단위 Span 2개 이상
            expect(spans.length).toBeGreaterThanOrEqual(2);
            const unitSpan = spans.find((s: any) =>
                typeof s.text === 'string' && s.text.includes('days_unit'),
            );
            expect(unitSpan).toBeDefined();
        });

        it('힌트 텍스트 P가 있어야 한다', () => {
            const hint = writeDeadlineSection.children.find(
                (c: any) => c.name === 'P' && typeof c.text === 'string',
            );
            expect(hint).toBeDefined();
            expect(hint.text).toContain('write_deadline_days_hint');
        });
    });

    describe('max_images 설정 항목', () => {
        const card = tab.children[0];
        const settingsArea = card.children[1];
        // 구분선(Div, index 1) 다음 max_images 항목(index 2)
        const maxImagesSection = settingsArea.children[2];

        it('Input이 type=number, min=0, max=10여야 한다', () => {
            const input = maxImagesSection.children.find((c: any) => c.name === 'Input');
            expect(input).toBeDefined();
            expect(input.props.type).toBe('number');
            expect(String(input.props.min)).toBe('0');
            expect(String(input.props.max)).toBe('10');
        });

        it('Input 기본값이 5를 참조해야 한다', () => {
            const input = maxImagesSection.children.find((c: any) => c.name === 'Input');
            expect(input.props.value).toContain('max_images');
            expect(input.props.value).toContain('5');
        });

        it('Input onChange가 max_images를 설정해야 한다', () => {
            const input = maxImagesSection.children.find((c: any) => c.name === 'Input');
            const action = input.actions[0];
            expect(action.handler).toBe('setState');
            const paramKeys = Object.keys(action.params);
            const maxImagesKey = paramKeys.find((k) => k.includes('max_images'));
            expect(maxImagesKey).toBeDefined();
        });

        it('레이블 텍스트가 다국어 키를 사용해야 한다', () => {
            const labelEl = maxImagesSection.children.find(
                (c: any) =>
                    c.name === 'Span' &&
                    typeof c.text === 'string' &&
                    c.text.includes('max_images_label'),
            );
            expect(labelEl).toBeDefined();
        });
    });

    describe('max_image_size_mb 설정 항목', () => {
        const card = tab.children[0];
        const settingsArea = card.children[1];
        // 구분선(index 3) 다음 max_image_size_mb 항목(index 4)
        const maxSizeSection = settingsArea.children[4];

        it('Input이 type=number, min=1, max=20, step=0.5여야 한다', () => {
            const input = maxSizeSection.children.find((c: any) => c.name === 'Input');
            expect(input).toBeDefined();
            expect(input.props.type).toBe('number');
            expect(String(input.props.min)).toBe('1');
            expect(String(input.props.max)).toBe('20');
            expect(String(input.props.step)).toBe('0.5');
        });

        it('Input 기본값이 5를 참조해야 한다', () => {
            const input = maxSizeSection.children.find((c: any) => c.name === 'Input');
            expect(input.props.value).toContain('max_image_size_mb');
            expect(input.props.value).toContain('5');
        });

        it('Input onChange가 max_image_size_mb를 설정해야 한다', () => {
            const input = maxSizeSection.children.find((c: any) => c.name === 'Input');
            const action = input.actions[0];
            expect(action.handler).toBe('setState');
            const paramKeys = Object.keys(action.params);
            const sizeKey = paramKeys.find((k) => k.includes('max_image_size_mb'));
            expect(sizeKey).toBeDefined();
        });

        it('레이블 텍스트가 다국어 키를 사용해야 한다', () => {
            const labelEl = maxSizeSection.children.find(
                (c: any) =>
                    c.name === 'Span' &&
                    typeof c.text === 'string' &&
                    c.text.includes('max_image_size_mb_label'),
            );
            expect(labelEl).toBeDefined();
        });

        it('MB 단위 텍스트가 있어야 한다', () => {
            const mbSpan = maxSizeSection.children.find(
                (c: any) => c.name === 'Span' && c.text === 'MB',
            );
            expect(mbSpan).toBeDefined();
        });
    });

    describe('설정 항목 전체 Input 검증', () => {
        it('총 3개의 Input이 존재해야 한다', () => {
            const inputs = findAllByName(tab, 'Input');
            expect(inputs).toHaveLength(3);
        });

        it('모든 Input onChange가 hasChanges=true를 포함해야 한다', () => {
            const inputs = findAllByName(tab, 'Input');
            for (const input of inputs) {
                expect(input.actions[0].params.hasChanges).toBe(true);
            }
        });

        it('모든 Input onChange가 target=local을 사용해야 한다', () => {
            const inputs = findAllByName(tab, 'Input');
            for (const input of inputs) {
                expect(input.actions[0].params.target).toBe('local');
            }
        });
    });

    describe('다국어 키 종합 검증', () => {
        const prefix = 'sirsoft-ecommerce.admin.settings.review_settings';

        it('모든 다국어 키가 review_settings 네임스페이스를 사용해야 한다', () => {
            const keys = collectI18nKeys(tab);
            expect(keys.length).toBeGreaterThan(0);
            for (const key of keys) {
                expect(key).toMatch(new RegExp(`^${prefix.replace(/\./g, '\\.')}`));
            }
        });

        it('title, description 다국어 키가 존재해야 한다', () => {
            const keys = collectI18nKeys(tab);
            expect(keys).toContain(`${prefix}.title`);
            expect(keys).toContain(`${prefix}.description`);
        });

        it('write_deadline_days 관련 다국어 키가 존재해야 한다', () => {
            const keys = collectI18nKeys(tab);
            const deadlineKeys = keys.filter((k) => k.includes('write_deadline_days'));
            expect(deadlineKeys.length).toBeGreaterThan(0);
        });

        it('max_images 관련 다국어 키가 존재해야 한다', () => {
            const keys = collectI18nKeys(tab);
            const maxImagesKeys = keys.filter((k) => k.includes('max_images'));
            expect(maxImagesKeys.length).toBeGreaterThan(0);
        });

        it('max_image_size_mb 관련 다국어 키가 존재해야 한다', () => {
            const keys = collectI18nKeys(tab);
            const maxSizeKeys = keys.filter((k) => k.includes('max_image_size_mb'));
            expect(maxSizeKeys.length).toBeGreaterThan(0);
        });
    });
});
