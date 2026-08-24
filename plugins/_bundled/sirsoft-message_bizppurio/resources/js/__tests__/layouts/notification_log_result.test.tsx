// e2e:allow 검수 라벨 표시(is_test_mode)는 Chrome MCP로 실제 회원가입→SMS 발송→화면 렌더까지
// 실측 검증 완료(2026-07-24). 정식 E2E는 비즈뿌리오 자격증명·채널 활성화·webhook 등 발송 인프라
// 의존이 커서 이번 변경 범위를 벗어나며, 별도 계획(plan-e2e-tests)에서 다룰 예정.
/**
 * 코어 알림 발송 이력 결과 컬럼 주입 렌더 테스트 (A-2)
 *
 * notification_log_result.json overlay 는 코어 "알림 발송 이력" 화면(admin_notification_log_list)의
 * DataGrid columns 에 결과 컬럼 1개를 _append 로 얹는다. 코어 화면·코어 앱·코어 테이블 무수정 —
 * 현재 페이지의 코어 로그 id 배열로 결과(dispatchResults)를 배치 조회해 row.id 로 매칭한다.
 *
 * 이 테스트는 overlay 에서 결과 컬럼 정의를 그대로 추출해 실제 렌더한다(구조 검증이 아니라 렌더).
 * row 컨텍스트는 iteration 으로 재현하고, dispatchResults 는 mockApi 로 채운다. 각 케이스에서
 * 올바른 배지 텍스트(상태 `사유 (코드)`·잔액부족·대체발송)가 DOM 에 뜨는지, 매칭 안 되는 행은
 * 빈 셀(-)인지 확인한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import overlay from '../../../extensions/notification_log_result.json';

/** overlay 에서 결과 컬럼(field: bizppurio_result)의 cellChildren 을 추출한다. */
function getResultCellChildren(): any[] {
    const inj = (overlay as any).injections.find(
        (i: any) => i.target_id === 'notification_log_datagrid' && i.position === 'inject_props',
    );
    const column = inj.props.columns._append.find((c: any) => c.field === 'bizppurio_result');
    if (!column) throw new Error('결과 컬럼(bizppurio_result)을 찾지 못함');
    return column.cellChildren;
}

/** overlay 에서 행 토글(expandChildren) 에 append 된 결과 블록을 추출한다. */
function getResultExpandChildren(): any[] {
    const inj = (overlay as any).injections.find(
        (i: any) => i.target_id === 'notification_log_datagrid' && i.position === 'inject_props',
    );
    return inj.props.expandChildren._append;
}

/** 결과 컬럼 cellChildren 을 iteration row 컨텍스트로 렌더하는 프로브 레이아웃. */
function buildProbe() {
    return {
        version: '1.0.0',
        layout_name: 'test/a2-dispatch-result',
        data_sources: [
            { id: 'notificationLogs', type: 'api', endpoint: '/api/test/logs', method: 'GET', auto_fetch: true },
            { id: 'dispatchResults', type: 'api', endpoint: '/api/test/results', method: 'POST', auto_fetch: true },
        ],
        components: [
            {
                type: 'basic',
                name: 'Div',
                iteration: { source: '{{notificationLogs.data?.data ?? []}}', item_var: 'row' },
                props: { 'data-testid': 'result-cell' },
                children: getResultCellChildren(),
            },
        ],
    };
}

/** 행 토글(expandChildren) 결과 블록을 iteration row 컨텍스트로 렌더하는 프로브 레이아웃. */
function buildExpandProbe() {
    return {
        version: '1.0.0',
        layout_name: 'test/a2-dispatch-result-expand',
        data_sources: [
            { id: 'notificationLogs', type: 'api', endpoint: '/api/test/logs', method: 'GET', auto_fetch: true },
            { id: 'dispatchResults', type: 'api', endpoint: '/api/test/results', method: 'POST', auto_fetch: true },
        ],
        components: [
            {
                type: 'basic',
                name: 'Div',
                iteration: { source: '{{notificationLogs.data?.data ?? []}}', item_var: 'row' },
                props: { 'data-testid': 'result-expand' },
                children: getResultExpandChildren(),
            },
        ],
    };
}

let registry: MockComponentRegistry;
beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
});
afterEach(() => {
    vi.clearAllMocks();
});

/** 로그 행 목록. 셀 탭 가드가 row.channel 을 보므로 채널을 함께 준다(기본 sms). */
const logs = (ids: number[], channel: string = 'sms') => ({ data: { data: ids.map((id) => ({ id, channel })) } });

describe('A-2 결과 컬럼 주입 — overlay 구조', () => {
    it('코어 알림 발송 이력 datagrid 에 결과 컬럼을 _append 로 얹는다', () => {
        expect((overlay as any).target_layout).toBe('admin_notification_log_list');
        const inj = (overlay as any).injections.find((i: any) => i.target_id === 'notification_log_datagrid');
        expect(inj.position).toBe('inject_props');
        expect(inj.props.columns._append).toBeTruthy();
    });

    it('결과는 파라미터 없이 GET 으로 최근 결과 맵을 받는다(타이밍 무관, kginicis 선례)', () => {
        // 다른 data_source(notificationLogs)를 params 로 참조하면, params 가 notificationLogs 응답
        // 도착 전에 평가돼 빈 배열이 전송되는 타이밍 결함이 있다(브라우저 실측: 결과 컬럼 전부 빈 셀).
        // 파라미터 없이 최근 결과 맵을 받아 row.id 로 매칭한다(로드 순서 무관).
        const ds = (overlay as any).data_sources.find((d: any) => d.id === 'dispatchResults');
        expect(ds.method).toBe('GET');
        expect(ds.endpoint).toContain('/dispatch-results/recent');
        expect(ds.params).toBeUndefined();
        expect(ds.if).toBeUndefined();
    });

    it('결과 컬럼 셀은 비즈뿌리오 발송 행(row.channel)에서만 표시한다(메일·사이트내알림 숨김)', () => {
        const cell = getResultCellChildren();
        const guard = cell[0];
        // 셀 렌더 컨텍스트는 row/value 만 있고 query 는 없다(코어 DataGrid renderCellChildren 계약).
        // 따라서 탭(query.channel)이 아니라 row.channel 로 비즈뿌리오 발송 행을 판별한다.
        expect(guard.if).toContain("['sms','lms','alimtalk'].includes(row.channel)");
    });

    it('컬럼(헤더 포함)은 메일·사이트내알림 탭에서 hidden 으로 숨긴다', () => {
        // 컬럼 정의는 datagrid props 로 페이지 컨텍스트에서 평가되므로 query 접근 가능(셀과 다름).
        // 메일·사이트내알림(mail/database) 탭이면 hidden=true → 헤더까지 숨긴다.
        const inj = (overlay as any).injections.find((i: any) => i.target_id === 'notification_log_datagrid');
        const column = inj.props.columns._append.find((c: any) => c.field === 'bizppurio_result');
        expect(column.hidden).toContain("['mail','database'].includes(query.channel");
    });

    it('행 토글(expandChildren)에도 결과 블록을 append 한다(비즈뿌리오 발송 행만)', () => {
        const inj = (overlay as any).injections.find((i: any) => i.target_id === 'notification_log_datagrid');
        const appended = inj.props.expandChildren._append;
        expect(appended).toBeTruthy();
        const block = appended[0];
        // 결과가 매칭된 행일 때만 토글에 노출.
        expect(block.if).toContain('dispatchResults?.data?.results');
        expect(JSON.stringify(block)).toContain('dispatch_result.detail_title');
    });

    it('배지는 다크모드에서 solid 색을 쓴다(색-불투명도 희석 금지)', () => {
        const raw = JSON.stringify(overlay);
        // /40 등 색-불투명도는 작은 배지에서 다크 배경과 섞여 묽어지므로 solid(dark:bg-*-700 등) 사용.
        expect(raw).toContain('dark:bg-green-700');
        expect(raw).toContain('dark:bg-red-700');
        expect(raw).not.toContain('dark:bg-green-900/40');
    });
});

describe('A-2 결과 컬럼 주입 — 렌더', () => {
    it('성공 결과는 사유(코드) 라벨을 렌더한다', async () => {
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([1]) });
        utils.mockApi('dispatchResults', {
            response: { data: { results: { 1: { status: 'success', status_label: '성공', result_label: '성공 (4100)', is_low_balance: false, fallback_status: null } } } },
        });
        await utils.render();
        expect(screen.getByText('성공 (4100)')).toBeInTheDocument();
        utils.cleanup();
    });

    it('잔액부족 실패는 사유(코드) 라벨과 잔액부족 배지를 렌더한다', async () => {
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([2]) });
        utils.mockApi('dispatchResults', {
            response: { data: { results: { 2: { status: 'failed', status_label: '실패', result_label: '지갑 잔액 부족 (7436)', is_low_balance: true, fallback_status: null } } } },
        });
        await utils.render();
        // result_label 은 data 값(그대로 렌더). 잔액부족 배지는 is_low_balance=true 조건부 렌더 —
        // $t: 라벨은 이 렌더 환경에서 원문 키로 남으므로 그 키 존재로 배지 렌더를 확인한다.
        expect(screen.getByText('지갑 잔액 부족 (7436)')).toBeInTheDocument();
        expect(screen.getByText('sirsoft-message_bizppurio.dispatch_result.low_balance')).toBeInTheDocument();
        utils.cleanup();
    });

    it('is_low_balance=false 이면 잔액부족 배지를 렌더하지 않는다', async () => {
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([2]) });
        utils.mockApi('dispatchResults', {
            response: { data: { results: { 2: { status: 'failed', status_label: '실패', result_label: '음영 지역 (4400)', is_low_balance: false, fallback_status: null } } } },
        });
        await utils.render();
        expect(screen.getByText('음영 지역 (4400)')).toBeInTheDocument();
        expect(screen.queryByText('sirsoft-message_bizppurio.dispatch_result.low_balance')).not.toBeInTheDocument();
        utils.cleanup();
    });

    it('대체발송 결과가 있으면 대체발송 배지를 렌더한다', async () => {
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([3]) });
        utils.mockApi('dispatchResults', {
            response: { data: { results: { 3: { status: 'success', status_label: '성공', result_label: '성공 (7000)', is_low_balance: false, fallback_status: '성공' } } } },
        });
        await utils.render();
        // 대체발송 배지는 fallback_status 존재 시 조건부 렌더 ($t: 라벨은 원문 키로 남음).
        expect(screen.getByText('sirsoft-message_bizppurio.dispatch_result.fallback')).toBeInTheDocument();
        utils.cleanup();
    });

    it('비즈뿌리오 발송(sms) 행이지만 결과 미매칭이면 빈 셀(-)을 렌더한다', async () => {
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([9], 'sms') });
        utils.mockApi('dispatchResults', { response: { data: { results: {} } } });
        await utils.render();
        expect(screen.getByText('-')).toBeInTheDocument();
        utils.cleanup();
    });

    it('메일 채널 행은 셀 자체를 비운다(빈 셀 - 도 표시 안 함)', async () => {
        // row.channel 이 mail 이면 셀 최상위 가드가 false → 셀 내용(빈 셀 - 포함) 전체 미렌더.
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([10], 'mail') });
        utils.mockApi('dispatchResults', { response: { data: { results: {} } } });
        await utils.render();
        expect(screen.queryByText('-')).not.toBeInTheDocument();
        utils.cleanup();
    });

    it('검수 모드 발송 건은 상태 라벨(발송중) 대신 검수 라벨을 렌더한다', async () => {
        // is_test_mode=true 이면 status='sent'(발송중)이어도 검수 라벨로 대체 표시한다(제품 결정 —
        // "발송중" 문구 자체가 검수 모드에서는 오해 소지라 배지 병기가 아니라 라벨 교체).
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([4]) });
        utils.mockApi('dispatchResults', {
            response: { data: { results: { 4: { status: 'sent', status_label: '발송중', result_label: null, is_low_balance: false, fallback_status: null, is_test_mode: true } } } },
        });
        await utils.render();
        // $t('key') 는 이 렌더 환경에서 번역 실패 시 원문 키를 그대로 반환한다($t: 와 동일 폴백).
        expect(screen.getByText('sirsoft-message_bizppurio.dispatch_result.inspection_label')).toBeInTheDocument();
        expect(screen.queryByText('발송중')).not.toBeInTheDocument();
        utils.cleanup();
    });

    it('운영 모드 발송 건은 검수 라벨 없이 기존 상태 라벨을 그대로 렌더한다', async () => {
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([5]) });
        utils.mockApi('dispatchResults', {
            response: { data: { results: { 5: { status: 'sent', status_label: '발송중', result_label: null, is_low_balance: false, fallback_status: null, is_test_mode: false } } } },
        });
        await utils.render();
        expect(screen.getByText('발송중')).toBeInTheDocument();
        expect(screen.queryByText('sirsoft-message_bizppurio.dispatch_result.inspection_label')).not.toBeInTheDocument();
        utils.cleanup();
    });

    it('is_test_mode 필드가 없는 과거 이력은 검수 라벨 없이 기존 라벨을 렌더한다', async () => {
        // 컬럼 신설 이전 이력(is_test_mode 미포함)도 undefined === true 가 false 이므로 안전하게 기존 라벨.
        const utils = createLayoutTest(buildProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([6]) });
        utils.mockApi('dispatchResults', {
            response: { data: { results: { 6: { status: 'success', status_label: '성공', result_label: '성공 (4100)', is_low_balance: false, fallback_status: null } } } },
        });
        await utils.render();
        expect(screen.getByText('성공 (4100)')).toBeInTheDocument();
        expect(screen.queryByText('sirsoft-message_bizppurio.dispatch_result.inspection_label')).not.toBeInTheDocument();
        utils.cleanup();
    });
});

describe('A-2 행 토글 — 알림톡 실제 발송 내용', () => {
    it('알림톡 채널 + 실제 발송 내용이 있으면 코어 "본문"과 별도로 렌더한다', async () => {
        const utils = createLayoutTest(buildExpandProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([7], 'alimtalk') });
        utils.mockApi('dispatchResults', {
            response: {
                data: {
                    results: {
                        7: {
                            status: 'success',
                            status_label: '성공',
                            result_label: '성공 (7000)',
                            is_low_balance: false,
                            fallback_status: null,
                            channel: 'alimtalk',
                            content: '[그누보드7] 회원가입을 환영합니다\n\n김으네님, 가입이 완료되었습니다.',
                        },
                    },
                },
            },
        });
        await utils.render();
        expect(screen.getByText(/회원가입을 환영합니다/)).toBeInTheDocument();
        utils.cleanup();
    });

    it('sms 채널은 실제 발송 내용 값이 있어도 렌더하지 않는다(코어 본문과 동일하므로 중복 표시 불필요)', async () => {
        const utils = createLayoutTest(buildExpandProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([8], 'sms') });
        utils.mockApi('dispatchResults', {
            response: {
                data: {
                    results: {
                        8: {
                            status: 'success',
                            status_label: '성공',
                            result_label: '성공 (4100)',
                            is_low_balance: false,
                            fallback_status: null,
                            channel: 'sms',
                            content: '문자 본문',
                        },
                    },
                },
            },
        });
        await utils.render();
        expect(screen.queryByText('문자 본문')).not.toBeInTheDocument();
        utils.cleanup();
    });

    it('알림톡 채널이지만 실제 발송 내용이 없으면(과거 이력 등) 렌더하지 않는다', async () => {
        const utils = createLayoutTest(buildExpandProbe(), { componentRegistry: registry as any, locale: 'ko' });
        utils.mockApi('notificationLogs', { response: logs([9], 'alimtalk') });
        utils.mockApi('dispatchResults', {
            response: {
                data: {
                    results: {
                        9: {
                            status: 'success',
                            status_label: '성공',
                            result_label: '성공 (7000)',
                            is_low_balance: false,
                            fallback_status: null,
                            channel: 'alimtalk',
                            content: null,
                        },
                    },
                },
            },
        });
        await utils.render();
        expect(screen.queryByText('sirsoft-message_bizppurio.dispatch_result.sent_content_label')).not.toBeInTheDocument();
        utils.cleanup();
    });
});
