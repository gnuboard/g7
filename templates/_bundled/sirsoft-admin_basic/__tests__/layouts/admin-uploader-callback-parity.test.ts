/**
 * @file admin-uploader-callback-parity.test.ts
 * @description 첨부 업로더 확장점 콜백이 기본 업로더와 같은 식을 쓰는지 — 관리자 템플릿 레이아웃 전수
 *
 * 첨부 업로더 확장점(`attachment_uploader`)은 대체 업로더가 같은 일을 하도록 기본 업로더의 이벤트 처리를
 * `callbacks` 에 한 벌 더 적는다. 기본 업로더는 `$args[0]`, 콜백은 `$event` 로 같은 값을 받는다. 이 두 벌이 갈라지면
 * 대체 업로더를 끼운 사이트에서만 저장값이 달라지는데, 기본 업로더로 도는 화면·시험에서는 콜백이 한 번도 실행되지 않아
 * 아무도 모른다. 실제로 일반 탭·SEO 탭 콜백이 `_local.form.general.site_logo` 처럼 옵셔널 체이닝을 잃어, 폼에 그 갈래가
 * 아직 없을 때 기본 업로더는 빈 배열로 시작하는데 콜백 식은 평가 오류가 났다.
 *
 * 이 시험은 산출 JSON(런타임이 읽는 짝 `.json`)에서 콜백의 setState 식마다 기본 업로더의 같은 이벤트·같은 키 식을 찾아
 * `$event` → `$args[0]` 치환 뒤 같은지 본다.
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
 * 디렉터리 아래 `.json` 레이아웃 파일을 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns 절대경로 목록
 */
function layoutFiles(dir: string): string[] {
    const out: string[] = [];

    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const abs = path.join(dir, entry.name);
        if (entry.isDirectory()) out.push(...layoutFiles(abs));
        else if (entry.name.endsWith('.json')) out.push(abs);
    }

    return out;
}

/**
 * 값 트리를 깊이 우선으로 돌며 객체마다 콜백을 부른다.
 *
 * @param node 시작 값
 * @param visit 객체 방문 함수
 */
function walk(node: unknown, visit: (n: any) => void): void {
    if (Array.isArray(node)) {
        node.forEach((child) => walk(child, visit));
        return;
    }
    if (!node || typeof node !== 'object') return;

    visit(node);
    Object.values(node).forEach((child) => walk(child, visit));
}

/**
 * 액션(또는 액션 배열)을 sequence·조건 안쪽까지 펼친다.
 *
 * @param action 액션 정의
 * @returns 펼친 액션 목록
 */
function flatten(action: any): any[] {
    if (!action) return [];
    if (Array.isArray(action)) return action.flatMap(flatten);

    return [action, ...flatten(action.actions), ...flatten(action.onSuccess), ...flatten(action.onError)];
}

/** 공백 차이를 무시하고 식을 비교한다 */
const normalize = (expression: string): string => expression.replace(/\s+/g, '');

interface Mismatch {
    file: string;
    slot: string;
    event: string;
    key: string;
    callback: string;
    defaults: string[];
}

const root = templateRoot();
const mismatches: Mismatch[] = [];
let comparedCount = 0;

for (const file of layoutFiles(path.join(root, 'layouts'))) {
    const layout = JSON.parse(fs.readFileSync(file, 'utf-8'));

    walk(layout, (node) => {
        if (node.name !== 'attachment_uploader' || !node.callbacks) return;

        const uploaders: any[] = [];
        walk(node.default, (child) => {
            if (child.name === 'FileUploader') uploaders.push(child);
        });
        const defaultActions = uploaders.flatMap((uploader) => uploader.actions ?? []);

        for (const [event, callback] of Object.entries<any>(node.callbacks)) {
            const defaults = flatten(defaultActions.filter((action) => action.event === event));

            for (const action of flatten(callback)) {
                if (action.handler !== 'setState' || !action.params) continue;

                for (const [key, value] of Object.entries<any>(action.params)) {
                    if (typeof value !== 'string' || !value.includes('{{')) continue;

                    comparedCount++;
                    const expected = normalize(value.replace(/\$event/g, '$args[0]'));
                    const candidates = defaults
                        .map((d) => d.params?.[key])
                        .filter((v): v is string => typeof v === 'string');

                    if (!candidates.some((candidate) => normalize(candidate) === expected)) {
                        mismatches.push({
                            file: path.relative(root, file).replace(/\\/g, '/'),
                            slot: node.id,
                            event,
                            key,
                            callback: value,
                            defaults: candidates,
                        });
                    }
                }
            }
        }
    });
}

describe('첨부 업로더 확장점 콜백 — 기본 업로더와 같은 식', () => {
    // @scenario case=admin_layout_callbacks_match_default
    // @effects admin_uploader_callbacks_match_default_actions
    it('관리자 템플릿 레이아웃에서 비교할 콜백 식을 찾았다', () => {
        // 일반 탭(로고·파비콘)·SEO 탭(OG 기본 이미지)만으로도 6건이 넘는다 — 0 이면 탐색이 산출 구조를 놓친 것이다
        expect(comparedCount).toBeGreaterThanOrEqual(6);
    });

    // @scenario case=admin_layout_callbacks_match_default
    // @effects admin_uploader_callbacks_match_default_actions
    it('콜백의 setState 식은 기본 업로더의 같은 이벤트·같은 키 식과 같다($event ↔ $args[0])', () => {
        expect(mismatches).toEqual([]);
    });

    // @scenario case=admin_layout_callbacks_match_default
    // @effects admin_uploader_callbacks_survive_missing_form_branch
    it('폼에 그 갈래가 아직 없어도 로고 완료 콜백이 기본 업로더처럼 새 파일만 담는다', () => {
        const general = JSON.parse(
            fs.readFileSync(path.join(root, 'layouts/partials/admin_settings/_tab_general.json'), 'utf-8'),
        );
        let expression: string | undefined;
        walk(general, (node) => {
            if (node.id === 'site_logo_uploader_slot') {
                expression = flatten(node.callbacks.onUploadComplete)
                    .map((action) => action.params?.['form.general.site_logo'])
                    .find((v) => typeof v === 'string');
            }
        });

        expect(typeof expression).toBe('string');
        const body = (expression as string).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
        const evaluate = new Function('_local', '$event', `return (${body});`);
        const uploaded = [{ id: 1, hash: 'h1' }];

        expect(evaluate({ form: {} }, uploaded)).toEqual(uploaded);
    });
});
