/**
 * 환경설정 화면 partial — 죽은 조각 0 계약 테스트 (7.1.0 전수 실측 validate R3)
 *
 * @description
 * 환경설정 화면(`admin_settings`)의 partial 은 모두 어느 레이아웃이 `partial` 로 끌어와야 한다.
 * 아무도 include 하지 않는 조각은 화면에 나오지 않으면서도 번역 키 점검·왕복 코퍼스·편집기 표본에는
 * 계속 잡혀, 없는 번역 키(예: 옛 메일 템플릿 탭의 `admin.settings.mail_templates.*` 약 30개)를
 * 결함처럼 보이게 한다. 메일 템플릿 탭은 알림 정의 탭으로 옮겨 갔으므로 옛 조각 두 개를 지웠고,
 * 이 테스트가 같은 종류의 잔재가 다시 쌓이지 않게 막는다.
 *
 * @effects admin_settings_partials_are_all_included, removed_mail_template_fragments_stay_removed
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const layoutsDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../layouts');
const settingsPartialsDir = path.join(layoutsDir, 'partials/admin_settings');

/**
 * 디렉터리 아래의 JSON 레이아웃 파일을 모두 모은다.
 *
 * @param dir 탐색 시작 디렉터리
 * @returns JSON 파일 절대경로 목록
 */
function collectJson(dir: string): string[] {
    const found: string[] = [];

    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            found.push(...collectJson(full));
        } else if (entry.name.endsWith('.json')) {
            found.push(full);
        }
    }

    return found;
}

/**
 * 템플릿 레이아웃 전체가 `partial` 로 참조하는 경로 집합을 만든다.
 *
 * @returns partials/….json 형태의 참조 경로 집합
 */
function includedPartials(): Set<string> {
    const included = new Set<string>();

    for (const file of collectJson(layoutsDir)) {
        const text = fs.readFileSync(file, 'utf-8');

        for (const match of text.matchAll(/"partial"\s*:\s*"([^"]+)"/g)) {
            included.add(match[1]);
        }
    }

    return included;
}

describe('환경설정 partial — 죽은 조각 0', () => {
    /**
     * @scenario partial_reach=orphan
     * @effects admin_settings_partials_are_all_included
     */
    it('admin_settings 의 모든 partial 을 어느 레이아웃이 include 한다', () => {
        const included = includedPartials();
        const orphans = fs
            .readdirSync(settingsPartialsDir)
            .filter((name) => name.endsWith('.json'))
            .map((name) => `partials/admin_settings/${name}`)
            .filter((ref) => !included.has(ref));

        expect(orphans).toEqual([]);
    });

    /**
     * @scenario partial_reach=included
     * @effects removed_mail_template_fragments_stay_removed
     */
    it('옛 메일 템플릿 탭·편집 창 조각이 원본과 짝 JSON 모두 없다', () => {
        for (const name of [
            '_tab_mail_templates.g7.html',
            '_tab_mail_templates.json',
            '_modal_mail_template_form.g7.html',
            '_modal_mail_template_form.json',
        ]) {
            expect(fs.existsSync(path.join(settingsPartialsDir, name)), name).toBe(false);
        }
    });
});
