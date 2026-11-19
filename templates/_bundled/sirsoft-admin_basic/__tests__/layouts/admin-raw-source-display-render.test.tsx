// e2e:allow 엔진 렌더 경로(레이아웃 → DataGrid props → expandChildren → renderItemChildren) 단언 — 브라우저 축은이 덮는다.
/**
 * @file admin-raw-source-display-render.test.tsx
 * @description 활동 로그 펼친 줄의 원문(지우기 전 본문·변경 전후 값)이 실제 렌더 경로에서 `$t:` 를 그대로 두는지 (3차)
 *
 * 1차 수정은 레이아웃을 `{{raw:…}}` 로 바꾸고 데이터 바인딩 단위만 시험했다. 재실측에서 펼친 화면은 여전히 `$t:` 를 벗겼다 —
 * 실제 경로(엔진이 DataGrid props 를 풀어 넘김 → DataGrid 가 줄마다 expandChildren 을 renderItemChildren 에 row 문맥으로 넘김)로 잰다.
 *
 * @scenario-manifest tests/scenarios/admin-raw-source-display.yaml
 */

import React from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { renderItemChildren } from '@core/template-engine/helpers/RenderHelpers';
import datagridPartial from '../../layouts/partials/admin_activity_log_list/_partial_datagrid.json';

const Box: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const Text: React.FC<any> = ({ className, children, text }) => <span className={className}>{children ?? text}</span>;
const Btn: React.FC<any> = ({ children }) => <button type="button">{children}</button>;
const Ico: React.FC<any> = () => <i />;

/**
 * 펼친 줄만 그리는 DataGrid — 실제 DataGrid 처럼 엔진이 넘긴 expandChildren 을 줄마다 renderItemChildren 에 넘긴다.
 * 펼침 상태(`_local.openSnapshots`)는 DataGrid 가 componentContext.state 로 병합해 넘기는 값과 같게 싣는다.
 */
const ExpandGrid: React.FC<{ data?: any[]; expandChildren?: any[] }> = ({ data, expandChildren }) => {
    const registry = ComponentRegistry.getInstance();
    const map = Object.fromEntries(Object.entries((registry as any).registry).map(([n, e]: [string, any]) => [n, e.component]));

    return (
        <div>
            {(data ?? []).map((row) => (
                <div key={row.id} data-testid="expanded-row">
                    {renderItemChildren(expandChildren ?? [], { row, _local: { openSnapshots: { [row.id]: true } }, _global: {} }, map, `expand-${row.id}`, {
                        translationContext: { templateId: 'test-template', locale: 'ko' },
                    })}
                </div>
            ))}
        </div>
    );
};

// 미리보기는 잘린 본문이라 JSON 끝 괄호로 끝나지 않는다 — 엔진의 「JSON 모양 문자열은 번역하지 않음」 가드에 걸리지 않는 실제 모양
const BODY = '{\n  "meta": { "title": "$t:error.default.title" },\n  "components": [{ "text": "$t:error.default.description" }';

describe('활동 로그 펼친 줄 — 원문은 실제 렌더 경로에서도 번역되지 않는다', () => {
    let utils: ReturnType<typeof createLayoutTest>;

    afterEach(() => utils?.cleanup());

    /**
     * @scenario surface=activity_log_snapshot
     * @effects raw_source_keeps_translation_tokens
     * @return void 펼친 본문·변경 전후 값에 `$t:` 가 그대로 남으면 통과
     */
    it('지우기 전 본문과 변경 전후 값이 `$t:` 를 그대로 보인다', async () => {
        const registry = ComponentRegistry.getInstance();
        (registry as any).registry = {
            Div: { component: Box, metadata: { name: 'Div', type: 'basic' } },
            Span: { component: Text, metadata: { name: 'Span', type: 'basic' } },
            P: { component: Text, metadata: { name: 'P', type: 'basic' } },
            Button: { component: Btn, metadata: { name: 'Button', type: 'basic' } },
            Icon: { component: Ico, metadata: { name: 'Icon', type: 'basic' } },
            DataGrid: { component: ExpandGrid, metadata: { name: 'DataGrid', type: 'composite' } },
            Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
        };
        const row = {
            id: 7,
            has_details: true,
            changes: [{ field: 'text', label: '문구', old: '$t:error.default.title', new: '$t:error.default.description' }],
            bulk_changes: [],
            snapshot: { target: 't', reason_label: 'r', version_count: 0, versions_truncated: false, content_preview: BODY, content_truncated: false },
        };
        utils = createLayoutTest(
            {
                components: [{ id: 'grid', type: 'composite', name: 'DataGrid', props: { data: String((datagridPartial as any).props.data).replace('activityLogs?.data?.data', '_local.rows'), expandChildren: (datagridPartial as any).props.expandChildren } }],
            } as any,
            {
                componentRegistry: registry,
                translations: { error: { default: { title: '오류 제목' } } },
                locale: 'ko',
                initialState: { _local: { rows: [row] } },
            },
        );
        await utils.render();

        const text = screen.getByTestId('expanded-row').textContent ?? '';
        expect(text).toContain('"title": "$t:error.default.title"');
        expect(text).toContain('"text": "$t:error.default.description"');
        expect(text).not.toContain('오류 제목');
        // 변경 전후 값
        expect(text).toContain('$t:error.default.title');
        expect(text).toContain('$t:error.default.description');
    });
});
