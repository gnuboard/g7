/**
 * @file pluginSettingsChannelLabelName.test.ts
 * @description 마케팅 설정 › 채널 추가·편집 창 — 이름 칸 제목에 언어를 박아 두지 않는다
 *
 * 이름 칸은 언어 탭이 달린 다국어 입력칸이라, 관리자 부품이 곁 라벨 글자 뒤에 칸마다 「({언어})」 를 붙여 읽힌다.
 * 곁 라벨이 「항목명 (한국어)」(`channel_modal.label_ko`)라 화면 낭독기가 「항목명 (한국어) (English)」 처럼 언어를
 * 두 번, 서로 어긋나게 읽었고(관찰), 화면 제목도 English·日本語 탭을 보고 있을 때 「(한국어)」 로 남았다.
 * 제목은 언어 없는 칸 이름(채널 목록 표의 「항목명」 열 제목과 같은 키)을 쓴다.
 *
 * 검증 방식: 굽기 산출물(JSON)의 정적 판정 + 그 키를 ko·en·ja(번들 언어팩) 문구로 풀어 본다.
 *
 * @scenario-manifest tests/scenarios/channel-label-accessible-name.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import pluginSettings from '../../../layouts/admin/plugin_settings.json';

const EXT_ROOT = path.resolve(__dirname, '../../../..');
const REPO = path.resolve(EXT_ROOT, '../../..');

/** 로케일별 플러그인 문구 */
const dicts: Record<string, any> = {
    ko: JSON.parse(fs.readFileSync(path.join(EXT_ROOT, 'resources/lang/ko.json'), 'utf8')),
    en: JSON.parse(fs.readFileSync(path.join(EXT_ROOT, 'resources/lang/en.json'), 'utf8')),
    ja: JSON.parse(fs.readFileSync(path.join(REPO, 'lang-packs/_bundled/g7-plugin-sirsoft-marketing-ja/frontend/ja.json'), 'utf8')),
};

/**
 * `$t:sirsoft-marketing.…` 키를 그 로케일 문구로 푼다.
 *
 * @param locale 로케일
 * @param text 원문
 * @returns 푼 글자
 */
const translate = (locale: string, text: string): string =>
    text.replace(/\$t:sirsoft-marketing\.([A-Za-z0-9_.]+)/g, (raw, key: string) => {
        const value = key.split('.').reduce((cur: any, seg) => cur?.[seg], dicts[locale]);
        return typeof value === 'string' ? value : raw;
    });

/** 트리에서 조건을 만족하는 노드와 그 부모를 찾는다. */
function findWithParent(node: any, pred: (n: any) => boolean, parent: any = null): { node: any; parent: any } | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const hit = findWithParent(child, pred, parent);
            if (hit) return hit;
        }
        return null;
    }
    if (!node || typeof node !== 'object') return null;
    if (pred(node)) return { node, parent };
    for (const [key, value] of Object.entries(node)) {
        const hit = findWithParent(value, pred, key === 'children' ? node : parent);
        if (hit) return hit;
    }
    return null;
}

const LANGUAGE_RE = /한국어|영어|일본어|English|Korean|Japanese|日本語|韓国語|英語/;

describe('채널 창 이름 칸 제목 — 언어 없는 칸 이름', () => {
    const field = findWithParent(pluginSettings, (n) => n?.id === 'channel_label_field');
    const labelNode = field ? field.parent.children[field.parent.children.indexOf(field.node) - 1] : null;

    /**
     * @scenario surface=channel_edit_modal
     * @effects channel_label_title_has_no_language
     */
    it('이름 칸(다국어 입력칸) 바로 앞에 제목 Label 이 있다', () => {
        expect(field).not.toBeNull();
        expect(JSON.stringify(field!.node)).toContain('"MultilingualInput"');
        expect(labelNode?.name).toBe('Label');
        expect(typeof labelNode?.text).toBe('string');
    });

    /**
     * @scenario surface=channel_edit_modal
     * @effects channel_label_title_has_no_language
     */
    it('제목을 ko·en·ja 로 풀어도 언어 이름이 없고 빈 글자가 아니다', () => {
        for (const locale of ['ko', 'en', 'ja']) {
            const text = translate(locale, String(labelNode?.text ?? ''));
            expect(text, `${locale}`).not.toMatch(/\$t:/);
            expect(text.trim(), `${locale}`).not.toBe('');
            expect(text, `${locale}: ${text}`).not.toMatch(LANGUAGE_RE);
        }
    });

    /**
     * 채널 목록 표의 「항목명」 열 제목과 같은 이름 — 표와 창이 같은 칸을 같은 이름으로 부른다.
     *
     * @scenario surface=channel_edit_modal
     * @effects channel_label_title_matches_list_column
     */
    it('제목이 채널 목록 표의 항목명 열 제목과 같은 키다', () => {
        expect(labelNode?.text).toBe('$t:sirsoft-marketing.settings.channels.col_label');
    });
});
