/**
 * @file admin-settings-upload-trigger-pairs.test.ts
 * @description 환경설정의 「저장할 때 올리는」 업로더마다 저장 버튼이 그 신호를 보내는지 잠근다 (#639 ⑨).
 *
 * 배경(브라우저 실측 2026-10-05): SEO 탭 「기본 공유 이미지」 업로더는 `upload:og_image_default` 를
 * 기다리는데(`autoUpload=false`), 저장 버튼 시퀀스는 `upload:site_logo` 만 보냈다. 새 이미지를 골라
 * 저장해도 업로드 요청이 0건이었고 설정값은 빈 배열 그대로였으며 오류 표시도 없었다.
 *
 * 그래서 탭 조각 전부에서 `uploadTriggerEvent` 를 모아, 저장 시퀀스가 그 신호를 **모두** 보내는지
 * 본다. 업로더를 새로 붙이고 신호를 빠뜨리면 여기서 걸린다.
 *
 * @scenario surface=admin_settings_save
 *
 * @effects every_deferred_uploader_receives_its_trigger_on_save
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
 * 레이아웃 트리를 깊이 우선으로 훑어 조건을 만족하는 값을 모은다.
 *
 * @param node 탐색 시작 노드
 * @param visit 노드마다 부르는 함수
 */
function walk(node: unknown, visit: (n: Record<string, any>) => void): void {
    if (Array.isArray(node)) {
        node.forEach((child) => walk(child, visit));
        return;
    }
    if (!node || typeof node !== 'object') return;

    visit(node as Record<string, any>);
    Object.values(node as Record<string, unknown>).forEach((value) => walk(value, visit));
}

const root = templateRoot();
const settingsLayout = JSON.parse(fs.readFileSync(path.join(root, 'layouts/admin_settings.json'), 'utf-8'));
const tabDir = path.join(root, 'layouts/partials/admin_settings');

/** 탭 조각들에서 「저장 때 올리는」 업로더의 신호 이름 */
const deferredTriggers = new Set<string>();
for (const file of fs.readdirSync(tabDir).filter((f) => f.endsWith('.json'))) {
    walk(JSON.parse(fs.readFileSync(path.join(tabDir, file), 'utf-8')), (n) => {
        const trigger = n.props?.uploadTriggerEvent;
        if (typeof trigger === 'string' && n.props?.autoUpload === false) {
            deferredTriggers.add(trigger);
        }
    });
}

/** 저장 버튼이 보내는 신호 이름 (순서 보존) */
function saveButtonEmits(): string[] {
    let saveButton: Record<string, any> | null = null;
    walk(settingsLayout, (n) => {
        if (!saveButton && n.id === 'save_button') saveButton = n;
    });
    if (!saveButton) throw new Error('save_button 을 찾지 못했습니다.');

    const emitted: string[] = [];
    walk(saveButton, (n) => {
        if (n.handler === 'emitEvent' && typeof n.params?.event === 'string') emitted.push(n.params.event);
    });

    return emitted;
}

describe('환경설정 저장 — 지연 업로더 신호 짝 (#639 ⑨)', () => {
    // @scenario surface=admin_settings_save, uploader=deferred
    // @effects every_deferred_uploader_receives_its_trigger_on_save
    it('탭 조각에서 지연 업로더 신호를 찾는다 (측정 대상이 비면 이 테스트가 무의미)', () => {
        expect([...deferredTriggers]).toEqual(expect.arrayContaining(['upload:site_logo', 'upload:og_image_default']));
    });

    // @scenario surface=admin_settings_save, uploader=deferred
    // @effects every_deferred_uploader_receives_its_trigger_on_save
    it('저장 버튼은 모든 지연 업로더 신호를 보낸다', () => {
        const emitted = saveButtonEmits();

        for (const trigger of deferredTriggers) {
            expect(emitted, `저장 시퀀스에 ${trigger} 가 없다`).toContain(trigger);
        }
    });

    // @scenario surface=admin_settings_save, uploader=deferred
    // @effects every_deferred_uploader_receives_its_trigger_on_save
    it('기본 공유 이미지 신호는 로고 신호 바로 다음에 보낸다 (설정 저장 요청보다 먼저)', () => {
        const emitted = saveButtonEmits();

        expect(emitted.indexOf('upload:og_image_default')).toBe(emitted.indexOf('upload:site_logo') + 1);
    });
});
