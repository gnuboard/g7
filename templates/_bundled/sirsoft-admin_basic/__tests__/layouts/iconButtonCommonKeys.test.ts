/**
 * @file iconButtonCommonKeys.test.ts
 * @description 아이콘만 있는 버튼의 이름으로 쓰는 공용 문구가 ko·en·ja 에 모두 있는지
 *
 * 확장 화면의 아이콘 버튼(휴지통·연필·×·체크·새로고침 등)은 이 템플릿의 공용 문구(`common.*`)를 aria-label 로 쓴다
 * (이커머스 전수 실측 S5, 2026-10-07). 칩·선택 해제용 「제거」(`common.remove`)는 이때 새로 생긴 문구다.
 * 한 언어라도 빠지면 그 언어 화면에서는 키 이름이 그대로 읽힌다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const LANG: Record<string, string> = {
    ko: '../../lang/partial/ko/common.json',
    en: '../../lang/partial/en/common.json',
    ja: '../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/common.json',
};

const KEYS = ['page_loading', 'user_menu', 'more_actions', 'remove_language', 'remove_tag', 'remove', 'refresh', 'reset', 'back', 'edit', 'delete', 'save', 'cancel', 'add', 'search', 'prev', 'next', 'expand', 'collapse', 'actions'];

describe('아이콘 버튼 이름용 공용 문구', () => {
    /**
     * @scenario case=c01
     * @effects icon_button_common_keys_localized
     */
    it.each(Object.keys(LANG))('%s 에 모든 문구가 있다', (locale) => {
        const json = JSON.parse(readFileSync(resolve(__dirname, LANG[locale]), 'utf8'));
        const missing = KEYS.filter((k) => typeof json[k] !== 'string' || json[k].trim() === '');
        expect(missing).toEqual([]);
    });
});
