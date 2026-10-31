/**
 * @file ActionMenuTriggerName.test.tsx
 * @description 글자 없이 아이콘만 그리는 작업 메뉴 버튼이 읽을 수 있는 이름을 갖는지
 *
 * 목록(DataGrid)의 줄마다 붙는 작업 메뉴(⋯) 버튼은 `triggerLabel=""` 로 아이콘만 그려, 화면 낭독기가 버튼 이름을
 * 아이콘 이름 「ellipsis h」 로 읽었다(이커머스 전수 실측 S5, 2026-10-07 — 쿠폰 목록 줄 메뉴). 관리자 화면의 모든
 * 데이터 목록이 같은 부품을 쓴다. 글자가 없을 때는 「더보기」(`common.more_actions`) 를 버튼 이름으로 단다
 * (글자를 생략한 기본 트리거의 보이는 글자 「작업」(`common.actions`) 과는 다른 문구다).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ActionMenu, ActionMenuItem } from '../ActionMenu';
import { IconName } from '../../basic/IconTypes';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const items: ActionMenuItem[] = [{ id: 1, label: '삭제', iconName: IconName.Trash }];

describe('작업 메뉴 버튼 이름', () => {
    beforeEach(() => {
        const dict: Record<string, string> = { 'common.actions': '작업', 'common.more_actions': '더보기' };
        (window as any).G7Core = { t: (key: string) => dict[key] ?? key };
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario case=c01
     * @effects action_menu_trigger_named
     */
    it('글자 없이 아이콘만 그리면 「더보기」 를 버튼 이름으로 단다(아이콘 이름으로 읽히지 않게)', () => {
        render(<ActionMenu items={items} triggerLabel="" triggerIconName={IconName.EllipsisHorizontal} />);

        const button = screen.getByRole('button', { name: '더보기' });
        expect(button.getAttribute('aria-label')).toBe('더보기');
        expect(screen.queryByRole('button', { name: /ellipsis/ })).toBeNull();
    });

    /**
     * @scenario case=c02
     * @effects action_menu_trigger_named
     */
    it('이름을 넘기면 그 이름을 쓴다', () => {
        render(<ActionMenu items={items} triggerLabel="" triggerAriaLabel="쿠폰 작업" triggerIconName={IconName.EllipsisHorizontal} />);

        expect(screen.getByRole('button', { name: '쿠폰 작업' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c03
     * @effects action_menu_trigger_named
     */
    it('글자가 있으면 그 글자가 이름이다(aria-label 로 덮지 않는다)', () => {
        render(<ActionMenu items={items} triggerLabel="관리" />);

        const button = screen.getByRole('button', { name: /관리/ });
        expect(button.getAttribute('aria-label')).toBeNull();
    });

    /**
     * 새 prop 은 컴포넌트 선언(components.json)에도 있어야 편집기·레이아웃 작성자가 쓸 수 있다.
     *
     * @scenario case=c04
     * @effects action_menu_trigger_named
     */
    it('컴포넌트 선언(components.json)에 triggerAriaLabel 이 있다', () => {
        const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../../../components.json'), 'utf8'));
        const found: any[] = [];
        (function walk(n: any) {
            if (Array.isArray(n)) return n.forEach(walk);
            if (n && typeof n === 'object') {
                if (n.name === 'ActionMenu' && n.props) found.push(n);
                Object.values(n).forEach(walk);
            }
        })(manifest);

        expect(found.length).toBeGreaterThan(0);
        expect(found[0].props.triggerAriaLabel?.type).toBe('string');
    });
});
