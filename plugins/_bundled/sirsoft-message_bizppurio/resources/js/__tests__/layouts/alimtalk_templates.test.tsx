/**
 * 비즈뿌리오 메시징 플러그인 알림톡 템플릿 조회 탭 구조 검증 (조회 전용)
 *
 * plugin_settings.json 안에 탭으로 배치된 알림톡 템플릿 화면을 검증한다.
 * 조회 전용 전환: 등록·수정·삭제·검수·상태변경을 제거하고 목록·상태·내용 조회 + 알림 연결만
 * 남겼다. 등록·관리는 비즈뿌리오 콘솔로 위임한다.
 * - 탭 네비게이션(환경설정 ↔ 알림톡 템플릿) + 배타 전환(query.tab)
 * - 목록 서브뷰(상태필터·검색·상태배지·[내용] 버튼) — [새 템플릿]·폼·상태변경 없음
 * - 상세 모달([닫기]만, 관리 액션 없음) / readiness 안내 / 준비 안내 / i18n 정합
 *
 * 독립 페이지·메뉴 없음: 설정 페이지 탭으로만 진입.
 */

import { describe, it, expect } from 'vitest';
import layout from '../../../layouts/admin/plugin_settings.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';
import { findById, collectI18nKeys, type AnyNode } from './helpers';

const root = layout as unknown as AnyNode;

describe('alimtalk templates — 탭 네비게이션', () => {
    it('탭 네비게이션에 환경설정·알림톡 템플릿 탭 버튼이 있다', () => {
        const nav = findById(root, 'settings_tabs');
        expect(nav).toBeTruthy();
        expect(findById(nav, 'tab_connection')).toBeTruthy();
        expect(findById(nav, 'tab_templates')).toBeTruthy();
    });

    it('알림톡 탭 버튼이 navigate replace 로 query.tab=templates 를 갱신하고 목록을 refetch 한다', () => {
        const raw = JSON.stringify(findById(root, 'tab_templates'));
        // 탭 전환은 navigate replace(+mergeQuery)로 if 재평가 → 화면 전환 (replaceUrl 은 if 미재평가라 부적합)
        expect(raw).toContain('"handler":"navigate"');
        expect(raw).toContain('"replace":true');
        expect(raw).toContain('"tab":"templates"');
        expect(raw).toContain('alimtalk_templates');
    });

    it('탭 패널이 query.tab 으로 배타 전환된다 (새로고침에도 유지)', () => {
        const connection = findById(root, 'connection_tab_panel');
        const templates = findById(root, 'templates_tab_panel');
        expect((connection as { if?: string }).if).toContain("query.tab ?? 'connection') === 'connection'");
        expect((templates as { if?: string }).if).toContain("query.tab ?? 'connection') === 'templates'");
    });

    it('init_actions 가 templates 탭 진입 시 목록을 자동 로드한다 (새로고침 복원)', () => {
        const inits = (root as { init_actions?: AnyNode[] }).init_actions ?? [];
        const refetch = inits.find((a) => a.handler === 'refetchDataSource');
        expect(refetch).toBeTruthy();
        expect((refetch as { if?: string }).if).toContain("query.tab ?? 'connection') === 'templates'");
    });
});

describe('alimtalk templates — 데이터소스', () => {
    it('템플릿 목록 데이터소스가 admin API 를 조회한다 (조회 전용 — 카테고리 데이터소스 없음)', () => {
        const sources = (root as { data_sources?: AnyNode[] }).data_sources ?? [];
        const list = sources.find((s) => s.id === 'alimtalk_templates');
        const cats = sources.find((s) => s.id === 'alimtalk_categories');
        expect(list?.endpoint).toBe('/api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates');
        expect(list?.auto_fetch).toBe(false);
        // 카테고리 데이터소스는 등록 폼 전용이라 제거됨
        expect(cats).toBeUndefined();
    });
});

describe('alimtalk templates — 조회 전용 (폼·관리 제거)', () => {
    it('폼 서브뷰(templates_form_view)가 제거되었다', () => {
        expect(findById(root, 'templates_form_view')).toBeNull();
    });

    it('상태변경 확인/실행 모달(alimtalk_template_action_modal)이 제거되었다', () => {
        const modals = (root as { modals?: AnyNode[] }).modals ?? [];
        expect(modals.find((m) => m.id === 'alimtalk_template_action_modal')).toBeUndefined();
    });

    it('폼 전환·관리 상태(templateView/templateForm/pendingAction/available_actions)가 레이아웃에 없다', () => {
        const raw = JSON.stringify(root);
        expect(raw).not.toContain('templateView');
        expect(raw).not.toContain('templateForm');
        expect(raw).not.toContain('pendingAction');
        expect(raw).not.toContain('available_actions');
        // 이미지 업로드 핸들러도 제거
        expect(raw).not.toContain('uploadTemplateImage');
    });
});

describe('alimtalk templates — 목록 서브뷰 (조회 전용)', () => {
    it('상태 필터 Select 와 검색 입력이 있다', () => {
        const toolbar = findById(root, 'templates_toolbar');
        const raw = JSON.stringify(toolbar);
        expect(raw).toContain('templateStatus');
        expect(raw).toContain('templateKeyword');
    });

    it('툴바에 [새 템플릿] 등록 버튼이 없고 새로고침만 있다 (캐시 초기화는 환경설정 탭으로 이관)', () => {
        const raw = JSON.stringify(findById(root, 'templates_toolbar'));
        expect(raw).toContain('templates.list.refresh');
        // 캐시 초기화·캐시 시간은 환경설정 탭으로 옮겼으므로 템플릿 툴바에는 없다.
        expect(raw).not.toContain('cache/clear');
        // 등록 진입(폼 전환) 없음
        expect(raw).not.toContain('templates.list.new');
        expect(raw).not.toContain('"form"');
    });

    it('빈 목록에서도 헤더 있는 표(카드)를 항상 표시하고 데이터 유무로 Tbody 를 분기한다', () => {
        const card = findById(root, 'templates_table_card');
        expect(card).toBeTruthy();
        const raw = JSON.stringify(card);
        // 표 헤더는 항상 존재
        expect(raw).toContain('columns.name');
        expect(raw).toContain('columns.status');
        // 데이터 유무 분기 (빈 상태 안내 + 목록 iteration)
        expect(raw).toContain('.length > 0');
        expect(raw).toContain('.length === 0');
        expect(raw).toContain('templates.list.empty');
    });

    it('표가 templates 목록을 iteration 으로 렌더한다', () => {
        const raw = JSON.stringify(findById(root, 'templates_table_card'));
        expect(raw).toContain('alimtalk_templates?.data?.templates');
        expect(raw).toContain('"item_var":"tpl"');
        expect(raw).toContain('tpl.templateName');
        expect(raw).toContain('status_badge');
    });

    it('관리 컬럼이 [내용] 버튼 하나로 상세 조회 후 상세 모달을 연다 (상태별 액션 없음)', () => {
        const raw = JSON.stringify(findById(root, 'templates_table_card'));
        // 상세 조회 → 상세 모달
        expect(raw).toContain('templates.actions.detail');
        expect(raw).toContain('"handler":"apiCall"');
        expect(raw).toContain('alimtalk_template_detail_modal');
        // 관리 액션 메뉴(ActionMenu)·switch 분기 없음
        expect(raw).not.toContain('ActionMenu');
        expect(raw).not.toContain('"handler":"switch"');
        expect(raw).not.toContain("id:'edit'");
        expect(raw).not.toContain("id:'delete'");
    });

    it('목록에 번호·등록요청일·처리일 컬럼이 있다', () => {
        const raw = JSON.stringify(findById(root, 'templates_table_card'));
        expect(raw).toContain('columns.no');
        expect(raw).toContain('columns.requested_at');
        expect(raw).toContain('columns.processed_at');
        // 순번은 pagination 기준 계산 + index_var
        expect(raw).toContain('"index_var":"tplIndex"');
        expect(raw).toContain('pagination?.current_page');
        // 날짜는 kapi 원본 필드
        expect(raw).toContain('tpl.createdAt');
        expect(raw).toContain('tpl.modifiedAt');
    });

    it('상태 배지가 RDY 일 때만 세부(사용전)를 덧붙인다', () => {
        const raw = JSON.stringify(findById(root, 'templates_table_card'));
        expect(raw).toContain("tpl.service_status === 'RDY'");
        expect(raw).toContain('status_sub.rdy');
    });

    it('페이지네이션이 page 상태를 갱신하고 목록을 refetch 한다', () => {
        const listView = JSON.stringify(findById(root, 'templates_list_view'));
        expect(listView).toContain('"name":"Pagination"');
        expect(listView).toContain('onPageChange');
        expect(listView).toContain('templatePage');
        expect(listView).toContain('pagination?.total_page');
    });
});

describe('alimtalk templates — 콘솔 안내 (조회 전용)', () => {
    it('목록 상단 안내가 배지 의미(제목+배지명+설명) + 콘솔 위임 안내를 포함한다', () => {
        const raw = JSON.stringify(findById(root, 'templates_list_notice'));
        // 배지 의미 — 제목 + 배지명/설명 분리(의미색 라벨)
        expect(raw).toContain('status_guide.title');
        expect(raw).toContain('status_guide.sendable_label');
        expect(raw).toContain('status_guide.inspecting_label');
        expect(raw).toContain('status_guide.pending_label');
        // 배지명은 의미색(초록/amber/빨강) solid 로 표의 배지와 매칭
        expect(raw).toContain('text-green-700');
        expect(raw).toContain('text-amber-700');
        expect(raw).toContain('text-red-700');
        // 콘솔 위임 안내 + 콘솔 링크
        expect(raw).toContain('list_notice.console_desc');
        expect(raw).toContain('list_notice.console_link');
        expect(raw).toContain('bizppurio.com');
    });

    it('환경설정 탭에 사용 전 준비 안내(카카오·SMS 통합 1박스)가 있고 info_panel 은 제거되었다', () => {
        const notice = findById(root, 'preparation_notice');
        expect(notice).toBeTruthy();
        const raw = JSON.stringify(notice);
        // 통합 박스: 문자(발신번호) + 카카오(채널·템플릿·API키 3단계) + 콘솔 링크 (박스 제목 없이 채널 그룹만)
        expect(raw).toContain('preparation.sms_label');
        expect(raw).toContain('preparation.sms_sender');
        expect(raw).toContain('preparation.kakao_label');
        expect(raw).toContain('preparation.kakao_channel');
        expect(raw).toContain('preparation.kakao_template');
        expect(raw).toContain('preparation.kakao_apikey');
        expect(raw).toContain('preparation.console_link');
        // 제목·본문 sm 로 상향(가독성)
        expect(raw).toContain('text-sm');
        // 중복이던 연동정보 안내(info_panel)는 제거(운영전환 경고·리포트 안내는 폼/리포트 섹션이 담당)
        expect(findById(root, 'info_panel')).toBeNull();
    });
});

describe('alimtalk templates — 상세 모달 & readiness (조회 전용)', () => {
    it('상세 모달이 정의되어 있다', () => {
        const modals = (root as { modals?: AnyNode[] }).modals ?? [];
        const detail = modals.find((m) => m.id === 'alimtalk_template_detail_modal');
        expect(detail).toBeTruthy();
    });

    it('상세 모달이 상태 배지·내용을 표시하고 [닫기]만 노출한다 (관리 액션 없음)', () => {
        const modals = (root as { modals?: AnyNode[] }).modals ?? [];
        const detail = JSON.stringify(modals.find((m) => m.id === 'alimtalk_template_detail_modal'));
        // 상태 배지 + 내용(카테고리·유형·버튼) 표시
        expect(detail).toContain('status_badge');
        expect(detail).toContain('templates.detail.category');
        expect(detail).toContain('templates.detail.buttons');
        expect(detail).toContain('columns.requested_at');
        // 닫기만 — 관리 액션(available_actions)·상태변경 모달 연결 없음
        expect(detail).toContain('templates.detail.close');
        expect(detail).not.toContain('available_actions');
        expect(detail).not.toContain('alimtalk_template_action_modal');
    });

    it('readiness 안내가 미준비(ready=false) 시 조건부로 표시되고 항목별 상태를 노출한다', () => {
        const readiness = findById(root, 'templates_readiness');
        // 최상위 노출 조건은 ready 플래그 기반
        const cond = (readiness as { if?: string }).if ?? '';
        expect(cond).toContain('templates_readiness?.data');
        expect(cond).toContain('ready');
        // 자식 노드에서 항목별 미설정(api_key_set/sender_key_set) 조건부 표시
        const raw = JSON.stringify(readiness);
        expect(raw).toContain('api_key_set');
        expect(raw).toContain('sender_key_set');
    });
});

describe('alimtalk templates — 회귀', () => {
    it('컴포넌트 최상위 actions 는 모두 이벤트 type 을 가진다 (charAt 회귀 방지)', () => {
        // 회귀: 컴포넌트의 actions 배열 최상위 항목에 type(click 등) 이 없으면 엔진이
        // getReactEventName 에서 eventType.charAt(0) 을 호출하다 "Cannot read properties of
        // undefined (reading 'charAt')" 로 그 컴포넌트 렌더가 통째로 실패한다(PO 브라우저 검수로 발견).
        const bad: string[] = [];
        const walk = (node: AnyNode, path: string): void => {
            if (Array.isArray(node)) {
                node.forEach((n, i) => walk(n as AnyNode, `${path}[${i}]`));
                return;
            }
            if (node && typeof node === 'object') {
                const obj = node as Record<string, unknown>;
                // 컴포넌트의 actions 만 검사 (sequence/switch 내부 하위 actions 는 handler 를 가짐 → 제외)
                if (Array.isArray(obj.actions) && obj.handler === undefined) {
                    (obj.actions as Array<Record<string, unknown>>).forEach((a, i) => {
                        if (a && typeof a === 'object' && a.type === undefined) {
                            bad.push(`${path}.actions[${i}] handler=${String(a.handler)}`);
                        }
                    });
                }
                for (const k of Object.keys(obj)) walk(obj[k] as AnyNode, `${path}.${k}`);
            }
        };
        walk(root, 'root');
        expect(bad, `type 누락 최상위 액션:\n${bad.join('\n')}`).toEqual([]);
    });
});

describe('alimtalk templates — i18n 정합', () => {
    it('레이아웃 $t: 키가 ko/en 다국어 파일에 모두 존재한다', () => {
        const keys = collectI18nKeys(layout);
        const resolve = (dict: Record<string, unknown>, path: string): unknown =>
            path.split('.').reduce<unknown>((acc, seg) => {
                if (acc && typeof acc === 'object') {
                    return (acc as Record<string, unknown>)[seg];
                }
                return undefined;
            }, dict);

        for (const raw of keys) {
            const path = raw.replace('$t:sirsoft-message_bizppurio.', '');
            expect(resolve(ko, path), `ko 누락: ${path}`).toBeTruthy();
            expect(resolve(en, path), `en 누락: ${path}`).toBeTruthy();
        }
    });
});

describe('alimtalk templates — 환경설정 탭: 발송 내용 캐시 (독립 카드)', () => {
    it('발송 내용 캐시가 독립 카드로 있고 캐시 시간(분) 입력을 담는다 (form 저장 대상)', () => {
        const card = findById(root, 'cache_section');
        expect(card).toBeTruthy();
        const raw = JSON.stringify(card);
        // 카드 제목 + 분 단위 숫자 입력 + form 바인딩(name)
        expect(raw).toContain('settings.cache.section_title');
        expect(raw).toContain('template_cache_minutes');
        expect(raw).toContain('"number"');
        expect(raw).toContain('settings.fields.template_cache_minutes.label');
        expect(raw).toContain('settings.fields.template_cache_minutes.hint');
    });

    it('같은 카드에 캐시 초기화 버튼 + 안내문이 함께 있다 (한 묶음)', () => {
        const raw = JSON.stringify(findById(root, 'cache_section'));
        // 즉시 캐시 비우기 — API 호출 + 성공/실패 토스트 + 상시 안내문
        expect(raw).toContain('alimtalk-templates/cache/clear');
        expect(raw).toContain('apiCall');
        expect(raw).toContain('settings.fields.template_cache_minutes.clear_cache');
        expect(raw).toContain('settings.fields.template_cache_minutes.clear_cache_hint');
        expect(raw).toContain('settings.fields.template_cache_minutes.clear_cache_success');
        expect(raw).toContain('settings.fields.template_cache_minutes.clear_cache_failed');
    });
});
