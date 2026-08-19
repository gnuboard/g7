/**
 * @file admin-settings-logo-remove-expression.test.ts
 * @description 사이트 로고 제거 시 form 에서 실제로 빠지는지 — 레이아웃 표현식 자체를 평가한다.
 *
 * 배경(브라우저 실측): FileUploader 는 `onRemove` 에 **hash 우선**(`item.hash || item.id`)으로
 * 식별자를 넘긴다. 레이아웃이 `item.id !== $args[0]` 로만 걸러내면 저장된 첨부(항상 hash 보유)는
 * 어떤 항목도 제거되지 않아, 첨부는 서버에서 즉시 삭제됐는데 폼에는 그대로 남는다. 그 상태로
 * 저장하면 이미 없는 id 를 제출해 422 가 되고, 설정에는 삭제된 첨부 id 가 남는다.
 *
 * 기존 테스트는 레이아웃 액션을 파일 안에 복사해 두고 기대 결과를 손으로 넣어 트리거하므로
 * 표현식 자체는 한 번도 평가되지 않는다. 그래서 이 테스트는 **실제 레이아웃 파일**에서 식을
 * 꺼내 평가한다.
 *
 * @scenario protected=current_site_logo, age=past_retention
 *
 * @effects site_logo_replacement_drops_previous, site_logo_stale_submitted_id_filtered
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * 템플릿 루트(template.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 템플릿 루트 절대경로
 */
function templateRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'template.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('template.json 을 가진 템플릿 루트를 찾지 못했습니다.');
}

/**
 * 레이아웃 트리에서 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 노드 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findNode(node: any, predicate: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;
    if (predicate(node)) return node;

    for (const child of node.children ?? []) {
        const found = findNode(child, predicate);
        if (found) return found;
    }

    return null;
}

const generalTab = JSON.parse(
    fs.readFileSync(
        path.join(templateRoot(), 'layouts/partials/admin_settings/_tab_general.json'),
        'utf-8',
    ),
);

const uploader = findNode(generalTab, (n: any) => n.id === 'site_logo_uploader');

const removeExpression: string = uploader.actions
    .filter((a: any) => a.event === 'onRemove')
    .map((a: any) => a.params?.['form.general.site_logo'])
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

const SAVED_LOGO = { id: 101, hash: 'abc123', original_filename: 'logo1.png' };
const OTHER_LOGO = { id: 202, hash: 'def456', original_filename: 'logo2.png' };

describe('사이트 로고 제거 — 레이아웃 표현식 평가', () => {
    it('onRemove 식이 레이아웃에 선언되어 있다', () => {
        expect(typeof removeExpression).toBe('string');
    });

    it('hash 로 통지된 항목이 form 에서 제거된다', () => {
        const result = evaluateBinding(removeExpression, {
            _local: { form: { general: { site_logo: [SAVED_LOGO, OTHER_LOGO] } } },
            $args: [SAVED_LOGO.hash],
        }) as any[];

        expect(result.map((item) => item.id)).toEqual([OTHER_LOGO.id]);
    });

    it('hash 가 없는 항목은 id 로도 제거된다', () => {
        const idOnly = { id: 303, original_filename: 'no-hash.png' };

        const result = evaluateBinding(removeExpression, {
            _local: { form: { general: { site_logo: [idOnly, OTHER_LOGO] } } },
            $args: [idOnly.id],
        }) as any[];

        expect(result.map((item) => item.id)).toEqual([OTHER_LOGO.id]);
    });

    it('대상이 아닌 식별자면 아무것도 제거하지 않는다', () => {
        const result = evaluateBinding(removeExpression, {
            _local: { form: { general: { site_logo: [SAVED_LOGO, OTHER_LOGO] } } },
            $args: ['nonexistent-hash'],
        }) as any[];

        expect(result).toHaveLength(2);
    });
});
