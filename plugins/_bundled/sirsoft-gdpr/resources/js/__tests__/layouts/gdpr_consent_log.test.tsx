/**
 * 동의 이력 관리자 레이아웃 (gdpr_consent_log)
 *
 * #27 회귀 가드 — 체크박스 필터의 옵션 C 하이브리드 동작:
 *   1. 체크박스 토글 시 _local.filter 즉시 갱신 (UI 체크 상태 반영)
 *   2. 같은 핸들러에서 searchConsentLogs 를 debounce 300 으로 실행 (URL/API 재호출)
 *   3. 텍스트 입력 (이메일/세션 ID) 은 검색 버튼/Enter 로만 적용 — 즉시 미적용
 *
 * 액션 정의 SSoT: named_actions.searchConsentLogs (navigate handler)
 */

import { describe, it, expect } from 'vitest';
import layout from '../../../layouts/admin/gdpr_consent_log.json';
import type { AnyNode } from './helpers';
import { findById } from './helpers';

describe('layouts/admin/gdpr_consent_log.json — #27 체크박스 즉시 적용 + debounce', () => {
    const root = layout as unknown as AnyNode;

    type ActionDef = {
        type?: string;
        event?: string;
        handler?: string;
        actionRef?: string;
        debounce?: number | { delay: number };
        params?: Record<string, unknown>;
        key?: string;
    };

    /**
     * 체크박스 Input 노드의 actions 배열을 안전하게 추출.
     */
    function actionsOf(node: AnyNode | null): ActionDef[] {
        return ((node?.actions ?? []) as unknown) as ActionDef[];
    }

    describe('named_actions.searchConsentLogs (SSoT)', () => {
        it('searchConsentLogs 가 navigate 핸들러로 정의되어 있다', () => {
            const named = (root as { named_actions?: Record<string, AnyNode> }).named_actions ?? {};
            expect(named.searchConsentLogs).toBeTruthy();
            expect((named.searchConsentLogs as { handler?: string }).handler).toBe('navigate');
        });

        it('searchConsentLogs 가 _local.filter 의 모든 키 (search/consentKeys/actions/sources) 를 query 로 전달한다', () => {
            const named = (root as { named_actions?: Record<string, { params?: Record<string, unknown> }> }).named_actions ?? {};
            const query = (named.searchConsentLogs?.params as { query?: Record<string, string> } | undefined)?.query ?? {};
            // 텍스트 검색은 searchType 분기로 전달
            expect(query.email).toMatch(/_local\.filter\.search/);
            expect(query.session_id).toMatch(/_local\.filter\.search/);
            // 체크박스 필터는 배열로 전달
            expect(query.consent_keys).toMatch(/_local\.filter\.consentKeys/);
            expect(query.actions).toMatch(/_local\.filter\.actions/);
            expect(query.sources).toMatch(/_local\.filter\.sources/);
        });
    });

    describe('체크박스 onChange 액션 패턴 (sequence: setState → searchConsentLogs)', () => {
        /**
         * 한 체크박스의 actions 가 sequence 패턴을 따르는지 검증.
         *
         * sequence 패턴 (#27 옵션 A 최종 형태):
         *   - actions[0]: handler=sequence, type=change
         *   - actions[0].params.actions[0]: setState (filter.{key} 갱신)
         *   - actions[0].params.actions[1]: actionRef=searchConsentLogs
         *
         * 왜 sequence 인가:
         *   actions 배열을 그냥 [setState, navigate] 로 두면 두 액션이 *같은 dataContext closure* 를
         *   공유. React 의 setState 는 비동기 스케줄링이라 actions[1] 시점의 dataContext 는 *클릭 직전*
         *   stale 값 → URL query 가 한 박자 늦은 값 (첫 클릭은 빈 배열로 누락) 으로 회귀.
         *
         *   sequence 핸들러는 ActionDispatcher.handleSequence 가 각 액션 사이에 `currentState` 를
         *   추적하고 sequenceContext.data._local 을 최신 상태로 갱신하므로 actions[1] (navigate) 가
         *   setState 직후의 새 `_local.filter.consentKeys` 를 보고 query 평가 — stale closure 해결.
         */
        function expectSequencePattern(checkbox: AnyNode | null, debugLabel: string): void {
            expect(checkbox, `${debugLabel}: 체크박스 노드 존재`).toBeTruthy();
            const actions = actionsOf(checkbox);

            expect(actions.length, `${debugLabel}: 외곽 actions 가 1개 (sequence wrapper)`).toBe(1);

            const outer = actions[0];
            expect(outer.handler, `${debugLabel}: outer handler=sequence`).toBe('sequence');
            expect(outer.type, `${debugLabel}: outer type=change`).toBe('change');

            const innerActions = ((outer.params as { actions?: ActionDef[] } | undefined)?.actions ?? []) as ActionDef[];
            expect(innerActions.length, `${debugLabel}: sequence.actions 가 2개`).toBe(2);

            // 첫 번째 inner: setState
            expect(innerActions[0].handler, `${debugLabel}: inner[0]=setState`).toBe('setState');

            // 두 번째 inner: searchConsentLogs actionRef
            expect(innerActions[1].actionRef, `${debugLabel}: inner[1]=searchConsentLogs actionRef`).toBe('searchConsentLogs');
        }

        /**
         * filter 카테고리 (consent_keys/action/source) 안의 모든 체크박스 노드를 수집.
         * 각 필터 row 는 Label > Input 구조이며, Input.props.type === 'checkbox' 이다.
         */
        function findCheckboxesIn(filterRowId: string): AnyNode[] {
            const filterRow = findById(root, filterRowId);
            if (!filterRow) return [];
            const out: AnyNode[] = [];
            const stack: AnyNode[] = [filterRow];
            while (stack.length) {
                const cur = stack.pop()!;
                const props = (cur.props ?? {}) as { type?: string };
                if (cur.name === 'Input' && props.type === 'checkbox') {
                    out.push(cur);
                }
                if (Array.isArray(cur.children)) stack.push(...cur.children);
            }
            return out;
        }

        it('consent_keys 필터의 체크박스 5개 모두 옵션 C 패턴', () => {
            // Phase 1 ICO/CNIL 4분류 체계 도입으로 functional 카테고리 추가됨 →
            // 「전체」 + necessary + functional + analytics + marketing = 5개.
            const checkboxes = findCheckboxesIn('consent_key_filter');
            expect(checkboxes.length, '체크박스 5개 (전체 / necessary / functional / analytics / marketing)').toBe(5);
            checkboxes.forEach((cb, idx) => expectSequencePattern(cb, `consent_keys[${idx}]`));
        });

        it('action 필터의 체크박스 3개 모두 옵션 C 패턴', () => {
            const checkboxes = findCheckboxesIn('action_filter');
            expect(checkboxes.length, '체크박스 3개 (전체 / granted / revoked)').toBe(3);
            checkboxes.forEach((cb, idx) => expectSequencePattern(cb, `action[${idx}]`));
        });

        it('source 필터의 체크박스 4개 모두 옵션 C 패턴', () => {
            const checkboxes = findCheckboxesIn('source_filter');
            expect(checkboxes.length, '체크박스 4개 (전체 / banner / preference_center / mypage)').toBe(4);
            checkboxes.forEach((cb, idx) => expectSequencePattern(cb, `source[${idx}]`));
        });
    });

    describe('텍스트 입력 (이메일/세션 ID) — 검색 버튼 의존 유지', () => {
        it('search 입력 필드는 onChange 에 debounce 또는 searchConsentLogs 자동 호출이 없다 (검색 버튼/Enter 만)', () => {
            // 회귀 가드: 텍스트 입력에 debounce 적용 시 사용자가 입력하는 중간마다 API 호출 발생 → 의도된 UX 위반
            const stack: AnyNode[] = [root];
            const matchedTextInputs: AnyNode[] = [];
            while (stack.length) {
                const cur = stack.pop()!;
                const props = (cur.props ?? {}) as { type?: string; placeholder?: string; name?: string };
                // text 입력 = type=text 이거나 type 미지정 + name=search
                if (cur.name === 'Input' && (props.type === 'text' || (!props.type && props.name === 'search'))) {
                    matchedTextInputs.push(cur);
                }
                if (Array.isArray(cur.children)) stack.push(...cur.children);
            }

            // 텍스트 입력이 0~다수 — 각각 onChange 액션이 setState 만 하는지 검증
            matchedTextInputs.forEach((input) => {
                const actions = actionsOf(input);
                actions.forEach((a) => {
                    expect(
                        a.actionRef,
                        `텍스트 입력의 onChange 액션은 searchConsentLogs 를 직접 호출하지 않는다 (검색 버튼/Enter 만)`
                    ).not.toBe('searchConsentLogs');
                });
            });
        });
    });

    describe('subject 컬럼 — 회원 row 클릭 시 회원 상세 페이지 이동 (검토 #26)', () => {
        /**
         * 회귀 가드: subject 컬럼의 회원 row (row.user_id 존재) 는 클릭 시
         * /admin/users/{{row.user.uuid}} 로 이동해야 함. DPO 가 이상 패턴 발견 시
         * 즉시 회원 상세 조회 가능하도록 일관성 확보.
         * 게스트 row 는 본 작업에서 처리하지 않음 (Span 그대로 — 후속 이슈).
         */
        const layoutJson = JSON.stringify(root);

        it('회원 row 가 Button + navigate 액션으로 회원 상세 페이지 경로를 가진다', () => {
            // subject 컬럼의 회원 row 식별 — if 조건이 !!row.user_id
            expect(layoutJson).toContain('"if":"{{!!row.user_id}}"');
            // navigate 경로 — /admin/users/{{row.user.uuid}}
            expect(layoutJson).toContain('/admin/users/{{row.user.uuid}}');
            // click 타입 + navigate 핸들러
            expect(layoutJson).toMatch(/"type":"click"[^}]*"handler":"navigate"/);
        });

        it('게스트 row 는 클릭 액션이 없다 (Span 유지 — 본 작업 범위 외)', () => {
            // 게스트 row 식별 — if 조건이 !row.user_id
            expect(layoutJson).toContain('"if":"{{!row.user_id}}"');
            // 게스트 row 가 actions: [{ ... navigate ... }] 형태를 갖지 않아야 함.
            // 회원 상세 이동 (#26) 외에 다른 navigate (검색/필터/마이페이지 진입 등) 는 별개 기능이므로
            // 게스트 row 의 *주체 셀 안에* navigate 가 있는지만 강하게 검증.
            // 주체 셀의 게스트 분기는 if=!row.user_id 인 Span — actions 키 미존재 필수.
            expect(layoutJson).not.toMatch(/"if":"\{\{!row\.user_id\}\}"[^}]*"actions"/);
        });
    });

    /**
     * 작업 2 (B-3 옵션 X) — policy_version 컬럼이 평문 Span + 본문 보기 Button 분리.
     * 피드백: v배지 클릭만으로는 직관성 부족 — 평문 + 명시 버튼 패턴으로 변경.
     */
    describe('작업 2: policy_version 컬럼 → 평문 + 본문 보기 Button', () => {
        const layoutJson = JSON.stringify(layout);

        it('gdprPolicyVersionSnapshot data_source 가 등록되어 있다', () => {
            expect(layoutJson).toContain('"id":"gdprPolicyVersionSnapshot"');
            expect(layoutJson).toContain('/api/plugins/sirsoft-gdpr/admin/policy-versions/{{_global.viewingPolicyVersion}}');
        });

        it('policy_version_snapshot_modal partial 이 modals 배열에 등록되어 있다', () => {
            expect(layoutJson).toContain('partials/_shared/_policy_version_snapshot_modal.json');
        });

        it('policy_version 컬럼이 평문 Span (값 또는 "-") 으로 항상 표시된다', () => {
            // row.policy_version ?? "-" — Span 텍스트
            expect(layoutJson).toContain('"text":"{{row.policy_version ?? \'-\'}}"');
        });

        it('row.policy_version 존재 시 본문 보기 Button 이 추가 노출된다 (sequence)', () => {
            // Button if=row.policy_version + setState + refetch + openModal sequence
            expect(layoutJson).toContain('"if":"{{row.policy_version}}"');
            expect(layoutJson).toContain('"viewingPolicyVersion":"{{row.policy_version}}"');
            expect(layoutJson).toContain('"dataSourceId":"gdprPolicyVersionSnapshot"');
            expect(layoutJson).toContain('"target":"policy_version_snapshot_modal"');
            expect(layoutJson).toContain('snapshot_view_short');
        });
    });

    describe('#25: 카테고리 스냅샷 — raw JSON → 표 형식 + Pre 컴포넌트 의존성 제거', () => {
        const layoutJson = JSON.stringify(layout);

        it('Pre 컴포넌트와 JSON.stringify 가 레이아웃에서 완전히 제거되었다', () => {
            // Pre 는 admin 템플릿 활성 번들에 미노출되어 "조용히 사라짐" 회귀를 유발하던 컴포넌트
            // feedback_layout_component_registration_check.md 회귀 가드
            expect(layoutJson).not.toContain('"name":"Pre"');
            expect(layoutJson).not.toContain('JSON.stringify');
        });

        it('row.categories_snapshot 배열을 iteration 으로 순회한다 (객체 직접 순회 미사용)', () => {
            // 백엔드 Resource 가 categories 객체를 [{key, label_key, granted}] 배열로 변환
            expect(layoutJson).toContain('"source":"row.categories_snapshot"');
            expect(layoutJson).toContain('"item_var":"snapshot_item"');
        });

        it('iteration 컨테이너가 빈 스냅샷 가드를 가진다', () => {
            // categories_snapshot 이 null 또는 빈 배열이면 영역 자체 미노출
            expect(layoutJson).toContain('{{(row.categories_snapshot?.length ?? 0) > 0}}');
        });

        it('각 행이 동의/거부 두 가지 배지를 분기 노출한다', () => {
            expect(layoutJson).toContain('"if":"{{snapshot_item.granted}}"');
            expect(layoutJson).toContain('"if":"{{!snapshot_item.granted}}"');
            expect(layoutJson).toContain('$t:sirsoft-gdpr.admin.consent_log.detail.snapshot_granted');
            expect(layoutJson).toContain('$t:sirsoft-gdpr.admin.consent_log.detail.snapshot_revoked');
        });

        it('카테고리 라벨은 snapshot_item.label_key 의 $t 해석 + key fallback 패턴이다', () => {
            expect(layoutJson).toContain('{{$t(snapshot_item.label_key) ?? snapshot_item.key}}');
        });

        it('카테고리 스냅샷 영역에 운영자 안내(hint) 한 줄이 노출된다', () => {
            expect(layoutJson).toContain('$t:sirsoft-gdpr.admin.consent_log.detail.categories_hint');
        });

        it('동의/거부 배지가 light/dark 색 쌍을 갖는다 (다크모드 회귀 가드)', () => {
            // 동의 = green 불투명 (plugin_settings.json 활성 배지와 동일 패턴 — /40 alpha 는 너무 밝아 사용 금지)
            // 동의 배지 클래스 자리는 "bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300" 형태로 등장
            expect(layoutJson).toContain('bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300');
            expect(layoutJson).not.toContain('dark:bg-green-900/40');
        });
    });
});
