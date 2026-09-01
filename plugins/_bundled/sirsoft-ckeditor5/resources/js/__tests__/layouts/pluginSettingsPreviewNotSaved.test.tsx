/**
 * CKEditor5 설정 화면 — 미리보기 편집기는 저장 대상이 아니다
 *
 * @description
 * 설정 화면 하단의 미리보기 편집기는 운영자가 설정을 시험해 보는 자리다. 그런데 그
 * 편집기도 `props.name` 을 갖기 때문에 폼 자동바인딩으로 `_local.form` 에 쌓인다.
 * 여기서 두 가지가 조용히 어긋난다:
 *
 *   ① 저장 body 가 `_local.form` 을 통째로 보내면 미리보기 입력이 설정으로 저장된다.
 *      서버는 200 을 돌려주므로 화면에는 아무 이상이 없다.
 *   ② 미리보기 입력이 `_local.hasChanges` 를 켜면 [저장] 버튼이 활성화된다.
 *      바뀐 설정이 없는데 바뀐 것처럼 보인다.
 *
 * 둘 다 **선언**으로 막는다 — 핸들러가 필드명(`preview_content`)을 알아보게 하면,
 * 다른 확장이 같은 미리보기 패턴을 쓸 때 그대로 재발한다.
 *
 * 이 계약은 화면에 오류로 드러나지 않으므로(저장은 성공하고 버튼은 눌린다) 선언 자체를
 * 잠근다. 특히 body 표현식은 평가기가 거부하는 구문(rest 구조분해 / 콜백 안 멤버 대입)을
 * 쓰면 **원문 문자열이 그대로 전송**되고 200 이 떨어져 드러나지 않는다.
 *
 * @effects preview_editor_excluded_from_save_body, preview_editor_does_not_dirty_form
 */

import { describe, expect, it } from 'vitest';

import pluginSettings from '../../../layouts/admin/plugin_settings.json';
import htmlEditorExtension from '../../../extensions/html-editor.json';

/**
 * 레이아웃 트리에서 조건을 만족하는 첫 노드를 찾는다 (slots·default 포함).
 *
 * @param root 탐색 시작 노드
 * @param match 판정 함수
 * @return 찾은 노드 또는 null
 */
function findNode(root: unknown, match: (node: any) => boolean): any | null {
    let found: any = null;

    const walk = (node: any): void => {
        if (found || !node || typeof node !== 'object') return;
        if (!Array.isArray(node) && match(node)) {
            found = node;
            return;
        }
        for (const value of Object.values(node)) {
            if (Array.isArray(value)) value.forEach(walk);
            else if (value && typeof value === 'object') walk(value);
        }
    };

    walk(root);
    return found;
}

describe('설정 화면 미리보기 편집기 — 저장 대상 제외', () => {
    it('미리보기 편집기가 trackChanges:false 를 선언한다', () => {
        const preview = findNode(pluginSettings, (n) => n.id === 'preview_editor');

        expect(preview, 'preview_editor 노드를 찾아야 한다').not.toBeNull();
        expect(
            preview.props?.trackChanges,
            '선언이 빠지면 미리보기 입력만으로 [저장] 이 켜진다',
        ).toBe(false);
    });

    it('확장점이 trackChanges 를 핸들러 params 까지 나른다', () => {
        const container = findNode(htmlEditorExtension, (n) => n.id === 'ckeditor5_container');
        const onMount = container?.lifecycle?.onMount?.[0];

        expect(onMount?.handler).toBe('sirsoft-ckeditor5.initEditor');
        expect(
            onMount?.params?.trackChanges,
            'params 로 나르지 않으면 레이아웃 선언이 핸들러에 도달하지 않는다 (선언은 있는데 무효)',
        ).toBe("{{extensionPointProps.trackChanges ?? true}}");
    });

    it('선언하지 않은 편집기는 기본값(변경 추적함)으로 남는다', () => {
        // 확장점 표현식의 기본값이 true 여야 게시글 편집기의 저장 버튼 활성화가 유지된다.
        const container = findNode(htmlEditorExtension, (n) => n.id === 'ckeditor5_container');
        const expr = container.lifecycle.onMount[0].params.trackChanges as string;

        expect(expr, '기본값이 false 로 뒤집히면 저장 버튼 회귀가 되돌아온다').toContain('?? true');
    });

    it('저장 body 가 미리보기 키를 제외한다', () => {
        const save = findNode(
            pluginSettings,
            (n) =>
                n.handler === 'apiCall'
                && typeof n.params?.body === 'string'
                && n.params.body.includes('_local.form'),
        );

        expect(save, '설정 저장 apiCall 을 찾아야 한다').not.toBeNull();

        const body = save.params.body as string;
        expect(body, '미리보기 본문을 제외해야 한다').toContain('preview_content');
        expect(body, '미리보기 모드 플래그도 제외해야 한다').toContain('preview_content_mode');

        // 평가기가 거부하는 구문이 들어가면 식이 죽고 원문 문자열이 그대로 전송된다
        // (오류 없이 200 이 떨어져 화면으로는 드러나지 않는다).
        expect(body, 'rest 구조분해 금지').not.toMatch(/\.\.\.\w+\s*\}\s*=/);
        expect(body, '콜백 안 멤버 대입 금지').not.toMatch(/\w+\[[^\]]+\]\s*=[^=]/);
    });

    it('저장 body 표현식이 실제로 미리보기 키를 걸러낸다 (죽은 식이면 여기서 잡힌다)', () => {
        const save = findNode(
            pluginSettings,
            (n) =>
                n.handler === 'apiCall'
                && typeof n.params?.body === 'string'
                && n.params.body.includes('_local.form'),
        );

        // `{{...}}` 를 벗겨 식만 실행한다.
        const expr = (save.params.body as string).replace(/^\{\{/, '').replace(/\}\}$/, '');
        // eslint-disable-next-line no-new-func
        const run = new Function('_local', `return ${expr};`);

        const out = run({
            form: {
                editorHeight: 500,
                toolbar: 'full',
                preview_content: { ko: '<p>시험</p>' },
                preview_content_mode: 'html',
            },
        });

        expect(Object.keys(out).sort(), '설정 키만 남아야 한다').toEqual(['editorHeight', 'toolbar']);
    });
});
