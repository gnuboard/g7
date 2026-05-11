/**
 * 게시판 사용자 레이아웃 abilities 키 마이그레이션 검증 테스트
 *
 * @description
 * - permissions -> abilities 키 마이그레이션 완료 검증
 * - user_permissions -> user_abilities 키 마이그레이션 완료 검증
 * - 모든 board 관련 레이아웃 JSON에서 구 키(permissions, user_permissions) 잔존 여부 확인
 */

import { describe, it, expect } from 'vitest';

// === Board Index 관련 ===
import writeButton from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/index/_write_button.json';

// === Board Show 관련 ===
import commentInput from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/show/_comment_input.json';
import commentSection from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/show/_comment_section.json';
import commentItem from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/show/_comment_item.json';
import postAttachments from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/show/_post_attachments.json';
import basicShow from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/types/basic/show.json';

// === Board Form 관련 ===
import postForm from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/form/_post_form.json';
import typeRenderer from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/form/_type_renderer.json';
import passwordVerifyModal from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/form/_password_verify_modal.json';

// === Board Index 타입별 ===
import galleryIndex from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/types/gallery/index.json';

/**
 * JSON 노드를 재귀적으로 순회하며 모든 문자열 값에서 {{...}} 표현식을 추출합니다.
 *
 * @param node JSON 노드 (객체, 배열, 또는 프리미티브)
 * @param expressions 수집된 표현식 배열 (참조로 전달)
 * @param path 현재 노드 경로 (디버깅용)
 */
function findExpressionsInJson(
    node: unknown,
    expressions: Array<{ expression: string; path: string }> = [],
    path: string = '$'
): Array<{ expression: string; path: string }> {
    if (node === null || node === undefined) return expressions;

    if (typeof node === 'string') {
        // {{...}} 패턴 매칭 (중첩 가능하므로 전체 문자열에서 모두 추출)
        const matches = node.match(/\{\{[^}]*\}\}/g);
        if (matches) {
            for (const match of matches) {
                expressions.push({ expression: match, path });
            }
        }
        return expressions;
    }

    if (Array.isArray(node)) {
        node.forEach((item, index) => {
            findExpressionsInJson(item, expressions, `${path}[${index}]`);
        });
        return expressions;
    }

    if (typeof node === 'object') {
        for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
            findExpressionsInJson(value, expressions, `${path}.${key}`);
        }
    }

    return expressions;
}

/**
 * 주어진 표현식 목록에서 특정 패턴을 포함하는 표현식을 필터링합니다.
 */
function findExpressionsContaining(
    expressions: Array<{ expression: string; path: string }>,
    pattern: string | RegExp
): Array<{ expression: string; path: string }> {
    return expressions.filter(({ expression }) => {
        if (typeof pattern === 'string') {
            return expression.includes(pattern);
        }
        return pattern.test(expression);
    });
}

// 모든 레이아웃 파일 목록 (파일명과 JSON 데이터 매핑)
const allBoardLayouts: Array<{ name: string; json: unknown }> = [
    { name: 'index/_write_button.json', json: writeButton },
    { name: 'show/_comment_input.json', json: commentInput },
    { name: 'show/_comment_section.json', json: commentSection },
    { name: 'show/_comment_item.json', json: commentItem },
    { name: 'show/_post_attachments.json', json: postAttachments },
    { name: 'types/basic/show.json', json: basicShow },
    { name: 'form/_post_form.json', json: postForm },
    { name: 'form/_type_renderer.json', json: typeRenderer },
    { name: 'form/_password_verify_modal.json', json: passwordVerifyModal },
    { name: 'types/gallery/index.json', json: galleryIndex },
];

// 폼 관련 레이아웃 (user_permissions -> user_abilities 확인 대상)
const formRelatedLayouts: Array<{ name: string; json: unknown }> = [
    { name: 'form/_post_form.json', json: postForm },
    { name: 'form/_type_renderer.json', json: typeRenderer },
    { name: 'form/_password_verify_modal.json', json: passwordVerifyModal },
];

describe('게시판 사용자 레이아웃 abilities 키 마이그레이션 검증', () => {
    describe('permissions -> abilities 키 잔존 검사 (전체 레이아웃)', () => {
        it.each(allBoardLayouts)(
            '$name: permissions 키가 남아있지 않아야 합니다',
            ({ json }) => {
                const expressions = findExpressionsInJson(json);
                // "permissions" 단어를 포함하지만 "abilities"의 일부가 아닌 표현식 검색
                // 예: "permissions?.can_write" (구 키) vs "abilities?.can_write" (신 키)
                const oldKeyExpressions = findExpressionsContaining(
                    expressions,
                    /(?<!\w)permissions(?!\w)/
                );

                expect(
                    oldKeyExpressions,
                    `구 키 "permissions"가 다음 위치에 남아있습니다:\n${oldKeyExpressions.map((e) => `  - ${e.path}: ${e.expression}`).join('\n')}`
                ).toHaveLength(0);
            }
        );
    });

    describe('user_permissions -> user_abilities 키 잔존 검사 (폼 관련 레이아웃)', () => {
        it.each(formRelatedLayouts)(
            '$name: user_permissions 키가 남아있지 않아야 합니다',
            ({ json }) => {
                const expressions = findExpressionsInJson(json);
                const oldKeyExpressions = findExpressionsContaining(
                    expressions,
                    'user_permissions'
                );

                expect(
                    oldKeyExpressions,
                    `구 키 "user_permissions"가 다음 위치에 남아있습니다:\n${oldKeyExpressions.map((e) => `  - ${e.path}: ${e.expression}`).join('\n')}`
                ).toHaveLength(0);
            }
        );
    });

    describe('abilities 키 사용 확인 (양성 검증)', () => {
        it('_write_button.json: posts?.data?.abilities?.can_write 표현식 포함', () => {
            const expressions = findExpressionsInJson(writeButton);
            const abilitiesExpressions = findExpressionsContaining(
                expressions,
                'abilities?.can_write'
            );
            expect(abilitiesExpressions.length).toBeGreaterThanOrEqual(1);
        });

        it('_write_button.json: disabled prop에 abilities?.can_write 체크 포함', () => {
            const button = writeButton as Record<string, unknown>;
            const props = button.props as Record<string, unknown>;
            expect(props.disabled).toBeDefined();
            expect(String(props.disabled)).toContain('abilities?.can_write');
        });

        it('_comment_input.json: post?.data?.abilities?.can_write_comments 표현식 포함', () => {
            const expressions = findExpressionsInJson(commentInput);
            const abilitiesExpressions = findExpressionsContaining(
                expressions,
                'abilities?.can_write_comments'
            );
            expect(abilitiesExpressions.length).toBeGreaterThanOrEqual(1);
        });

        it('_comment_section.json: abilities?.can_read_comments 및 abilities?.can_write_comments 표현식 포함', () => {
            const expressions = findExpressionsInJson(commentSection);

            const canReadComments = findExpressionsContaining(
                expressions,
                'abilities?.can_read_comments'
            );
            const canWriteComments = findExpressionsContaining(
                expressions,
                'abilities?.can_write_comments'
            );

            expect(canReadComments.length).toBeGreaterThanOrEqual(1);
            expect(canWriteComments.length).toBeGreaterThanOrEqual(1);
        });

        it('_comment_item.json: abilities?.can_write_comments 및 abilities?.can_manage 표현식 포함', () => {
            const expressions = findExpressionsInJson(commentItem);

            const canWriteComments = findExpressionsContaining(
                expressions,
                'abilities?.can_write_comments'
            );
            const canManage = findExpressionsContaining(
                expressions,
                'abilities?.can_manage'
            );

            expect(canWriteComments.length).toBeGreaterThanOrEqual(1);
            expect(canManage.length).toBeGreaterThanOrEqual(1);
        });

        it('types/basic/show.json: abilities?.can_write 및 abilities?.can_manage 표현식 포함', () => {
            const expressions = findExpressionsInJson(basicShow);

            const canWrite = findExpressionsContaining(
                expressions,
                'abilities?.can_write'
            );
            const canManage = findExpressionsContaining(
                expressions,
                'abilities?.can_manage'
            );

            expect(canWrite.length).toBeGreaterThanOrEqual(1);
            expect(canManage.length).toBeGreaterThanOrEqual(1);
        });

        it('types/basic/show.json: abilities?.can_read_comments 표현식 포함', () => {
            const expressions = findExpressionsInJson(basicShow);
            const canReadComments = findExpressionsContaining(
                expressions,
                'abilities?.can_read_comments'
            );
            expect(canReadComments.length).toBeGreaterThanOrEqual(1);
        });

        it('form/_post_form.json: user_abilities?.can_manage 및 user_abilities?.can_upload 표현식 포함', () => {
            const expressions = findExpressionsInJson(postForm);

            const canManage = findExpressionsContaining(
                expressions,
                'user_abilities?.can_manage'
            );
            const canUpload = findExpressionsContaining(
                expressions,
                'user_abilities?.can_upload'
            );

            expect(canManage.length).toBeGreaterThanOrEqual(1);
            expect(canUpload.length).toBeGreaterThanOrEqual(1);
        });

        it('form/_type_renderer.json: user_abilities?.can_manage 표현식 포함', () => {
            const expressions = findExpressionsInJson(typeRenderer);
            const canManage = findExpressionsContaining(
                expressions,
                'user_abilities?.can_manage'
            );
            expect(canManage.length).toBeGreaterThanOrEqual(1);
        });

        it('form/_password_verify_modal.json: user_abilities?.can_manage 표현식 포함', () => {
            const expressions = findExpressionsInJson(passwordVerifyModal);
            const canManage = findExpressionsContaining(
                expressions,
                'user_abilities?.can_manage'
            );
            expect(canManage.length).toBeGreaterThanOrEqual(1);
        });

    });

    describe('findExpressionsInJson 헬퍼 정합성 검증', () => {
        it('중첩 JSON에서 모든 표현식을 추출합니다', () => {
            const testNode = {
                if: '{{a.permissions.x}}',
                props: {
                    className: 'static-class',
                    disabled: '{{b.abilities.y}}',
                },
                children: [
                    {
                        text: '{{c.value}}',
                    },
                ],
            };

            const expressions = findExpressionsInJson(testNode);
            expect(expressions).toHaveLength(3);
            expect(expressions[0].expression).toBe('{{a.permissions.x}}');
            expect(expressions[1].expression).toBe('{{b.abilities.y}}');
            expect(expressions[2].expression).toBe('{{c.value}}');
        });

        it('표현식이 없는 정적 문자열은 무시합니다', () => {
            const testNode = {
                type: 'basic',
                name: 'Div',
                text: 'no expressions here',
            };

            const expressions = findExpressionsInJson(testNode);
            expect(expressions).toHaveLength(0);
        });

        it('한 문자열에 여러 표현식이 있으면 모두 추출합니다', () => {
            const testNode = {
                text: '{{a.permissions}} and {{b.abilities}}',
            };

            const expressions = findExpressionsInJson(testNode);
            expect(expressions).toHaveLength(2);
        });
    });
});
