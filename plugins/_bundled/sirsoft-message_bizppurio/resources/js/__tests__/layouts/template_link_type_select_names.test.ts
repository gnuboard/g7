/**
 * 알림톡 템플릿 편집 모달의 「링크 유형」 선택칸은 칸마다 이름(aria-label)이 있다.
 *
 * 버튼·바로연결 행은 한 줄에 링크 유형 선택칸, 이름 입력칸, 삭제 버튼이 나란히 놓이고, 그 위의 라벨(「버튼 (최대 5개)」 등)
 * 하나가 여러 행을 묶는다. 곁 라벨 이름을 칸에 자동으로 잇는 훅은 라벨 하나 뒤에 칸이 둘 이상이면 잇지 않으므로,
 * 링크 유형 선택칸은 화면 낭독기에 이름 없이 읽혔다. 칸마다 몇 번째 버튼·바로연결인지 구분되는 이름을 직접 단다.
 *
 * 플러그인 설정 화면(plugin_settings)과 확장점 조각(notification_template_form_sections) 두 곳에 같은 모달 본문이 있으므로
 * 두 레이아웃의 구운 JSON 을 모두 훑는다. 이름 키가 ko·en 언어 파일에 실제로 있는지도 함께 본다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const PLUGIN_ROOT = resolve(__dirname, '../../../..');
const PREFIX = 'sirsoft-message_bizppurio.';

const LAYOUTS = [
    'resources/layouts/admin/plugin_settings.json',
    'resources/extensions/notification_template_form_sections.json',
];

interface Found {
    file: string;
    row: string;
    ariaLabel: unknown;
}

/** 링크 유형 선택칸 — value 가 `<행>Item.linkType ?? 'WL'` 인 Select 노드 */
function collectLinkTypeSelects(node: any, file: string, out: Found[]): Found[] {
    if (Array.isArray(node)) {
        node.forEach((n) => collectLinkTypeSelects(n, file, out));
        return out;
    }
    if (!node || typeof node !== 'object') return out;
    const value = node.props?.value;
    const match = typeof value === 'string' ? value.match(/^\{\{(\w+)Item\.linkType \?\? 'WL'\}\}$/) : null;
    if (node.name === 'Select' && match) {
        out.push({ file, row: match[1], ariaLabel: node.props?.['aria-label'] });
    }
    Object.values(node).forEach((v) => {
        if (v && typeof v === 'object') collectLinkTypeSelects(v, file, out);
    });
    return out;
}

function langHas(locale: string, dotted: string): boolean {
    const lang = JSON.parse(readFileSync(resolve(PLUGIN_ROOT, `resources/lang/${locale}.json`), 'utf8'));
    const value = dotted.split('.').reduce((acc: any, k) => (acc && typeof acc === 'object' ? acc[k] : undefined), lang);
    return typeof value === 'string' && value.includes('{index}');
}

describe('알림톡 템플릿 편집 모달 — 링크 유형 선택칸 이름', () => {
    const selects = LAYOUTS.flatMap((file) =>
        collectLinkTypeSelects(JSON.parse(readFileSync(resolve(PLUGIN_ROOT, file), 'utf8')), file, []),
    );

    // @scenario surface=plugin_settings_modal
    // @scenario surface=extension_section
    // @effects template_link_type_selects_have_names
    it('두 레이아웃에서 버튼·바로연결 링크 유형 선택칸을 모두 찾는다', () => {
        expect(selects.map((s) => `${s.file}#${s.row}`).sort()).toEqual(
            LAYOUTS.flatMap((file) => [`${file}#buttons`, `${file}#quickReplies`]).sort(),
        );
    });

    // @scenario surface=plugin_settings_modal
    // @scenario surface=extension_section
    // @effects template_link_type_selects_have_names
    it('링크 유형 선택칸은 칸마다 aria-label 이 있다 — 몇 번째 행인지 구분되는 이름', () => {
        const expected: Record<string, string> = {
            buttons: 'template.form.button_link_type',
            quickReplies: 'template.form.quick_reply_link_type',
        };
        for (const s of selects) {
            expect(s.ariaLabel, `${s.file} ${s.row}`).toBe(
                `$t:${PREFIX}${expected[s.row]}|index={{${s.row}Idx + 1}}`,
            );
        }
        for (const key of Object.values(expected)) {
            expect(langHas('ko', key), `ko ${key}`).toBe(true);
            expect(langHas('en', key), `en ${key}`).toBe(true);
        }
    });
});
