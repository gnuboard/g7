/**
 * @file adminCategoryImageRemoveExpression.test.ts
 * @description 카테고리 이미지 제거 시 form 에서 실제로 빠지는지 — 레이아웃 표현식 자체를 평가한다.
 *
 * 배경(브라우저 실측): FileUploader 는 `onRemove` 에 **hash 우선**(`item.hash || item.id`)으로
 * 식별자를 넘긴다. 레이아웃이 `img.id !== $args[0]` 로만 걸러내면 저장된 이미지(항상 hash 보유)는
 * 어떤 항목도 제거되지 않아, 이미지는 서버에서 즉시 삭제됐는데 폼에는 그대로 남는다.
 *
 * 코어 사이트 로고(`admin-settings-logo-remove-expression`)와 같은 근본 원인이며, 같은 방식으로
 * **실제 레이아웃 파일**에서 식을 꺼내 평가한다 — 액션을 테스트 안에 복사해 두면 표현식 자체는
 * 한 번도 평가되지 않아 회귀를 잡지 못한다.
 *
 * @scenario image_state=temp_unlinked, age=within_retention
 *
 * @effects category_image_remove_filters_by_hash, category_image_remove_falls_back_to_id
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * 모듈 루트(module.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 모듈 루트 절대경로
 */
function moduleRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'module.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('module.json 을 가진 모듈 루트를 찾지 못했습니다.');
}

/**
 * 레이아웃 트리 전체를 훑어 조건을 만족하는 첫 노드를 찾는다.
 *
 * 슬롯·partial 등 children 이외의 키에도 노드가 매달리므로 모든 값을 훑는다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 노드 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findNode(node: unknown, predicate: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;

    if (!Array.isArray(node) && predicate(node)) return node;

    for (const value of Object.values(node)) {
        const found = findNode(value, predicate);
        if (found) return found;
    }

    return null;
}

const panelForm = JSON.parse(
    fs.readFileSync(
        path.join(
            moduleRoot(),
            'resources/layouts/admin/partials/admin_ecommerce_category_index/_panel_form.json',
        ),
        'utf-8',
    ),
);

const uploader = findNode(
    panelForm,
    (n: any) =>
        n.name === 'FileUploader'
        && Array.isArray(n.actions)
        && n.actions.some((a: any) => a.event === 'onRemove'),
);

const removeExpression: string = uploader?.actions
    .filter((a: any) => a.event === 'onRemove')
    .map((a: any) => a.params?.['form.images'])
    .find((v: unknown) => typeof v === 'string');

/**
 * `{{...}}` 바인딩 식을 주어진 컨텍스트로 평가한다.
 *
 * @param expression 레이아웃 바인딩 식
 * @param context 평가 컨텍스트 (_local, $args)
 * @returns 평가 결과
 */
function evaluateBinding(expression: string, context: { _local: any; $args: unknown[] }): unknown {
    const body = expression.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');

    return new Function('_local', '$args', `return (${body});`)(context._local, context.$args);
}

const SAVED_IMAGE = { id: 101, hash: 'abc123', original_filename: 'category1.png' };
const OTHER_IMAGE = { id: 202, hash: 'def456', original_filename: 'category2.png' };

describe('카테고리 이미지 제거 — 레이아웃 표현식 평가', () => {
    it('onRemove 식이 레이아웃에 선언되어 있다', () => {
        expect(typeof removeExpression).toBe('string');
    });

    it('hash 로 통지된 항목이 form 에서 제거된다', () => {
        const result = evaluateBinding(removeExpression, {
            _local: { form: { images: [SAVED_IMAGE, OTHER_IMAGE] } },
            $args: [SAVED_IMAGE.hash],
        }) as any[];

        expect(result.map((item) => item.id)).toEqual([OTHER_IMAGE.id]);
    });

    it('hash 가 없는 항목은 id 로도 제거된다', () => {
        const idOnly = { id: 303, original_filename: 'no-hash.png' };

        const result = evaluateBinding(removeExpression, {
            _local: { form: { images: [idOnly, OTHER_IMAGE] } },
            $args: [idOnly.id],
        }) as any[];

        expect(result.map((item) => item.id)).toEqual([OTHER_IMAGE.id]);
    });

    it('대상이 아닌 식별자면 아무것도 제거하지 않는다', () => {
        const result = evaluateBinding(removeExpression, {
            _local: { form: { images: [SAVED_IMAGE, OTHER_IMAGE] } },
            $args: ['nonexistent-hash'],
        }) as any[];

        expect(result).toHaveLength(2);
    });
});
