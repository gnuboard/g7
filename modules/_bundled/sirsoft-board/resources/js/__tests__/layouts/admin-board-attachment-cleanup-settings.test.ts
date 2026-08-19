/**
 * 게시판 환경설정 — 첨부 정리 섹션 계약 테스트 (공개 #115)
 *
 * @description
 * 삭제 첨부 영구 정리는 사용자 파일을 파기하므로 기본 꺼짐이다. 화면 바인딩 키가
 * 저장 카테고리·기본값·서버 검증 세 지점과 일치하는지를 고정한다. 한 곳만 어긋나도
 * 저장은 성공하는데 값이 조용히 버려진다.
 *
 * @effects board_settings_purge_toggle_default_off, board_settings_purge_keys_declared
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import generalTab from '../../../layouts/admin/partials/admin_board_settings/_tab_general.json';

/**
 * 모듈 루트(module.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 모듈 루트 절대경로
 */
function moduleRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'module.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('module.json 을 가진 모듈 루트를 찾지 못했습니다.');
}

/**
 * 레이아웃 트리에서 조건을 만족하는 첫 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 노드 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findNode(node: any, predicate: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;
    if (predicate(node)) return node;

    for (const child of node.children ?? []) {
        const found = findNode(child, predicate);
        if (found) return found;
    }

    return null;
}

/**
 * 이 화면을 렌더하는 admin 템플릿의 매니페스트가 선언한 컴포넌트 종류를 읽는다.
 *
 * 레이아웃 노드의 `type` 은 렌더러가 DOM 안전 필터링을 고르는 기준이라
 * 매니페스트 선언과 어긋나면 `editorAttrs` 가 제거된다(DynamicRenderer).
 *
 * @param name 컴포넌트 이름
 * @returns 매니페스트가 선언한 type
 */
function manifestType(name: string): string {
    // 모듈 루트(modules/_bundled/sirsoft-board) 에서 저장소 루트로 두 단계 올라간다.
    const repoRoot = path.dirname(path.dirname(path.dirname(moduleRoot())));
    const manifest = JSON.parse(
        fs.readFileSync(
            path.join(repoRoot, 'templates/_bundled/sirsoft-admin_basic/components.json'),
            'utf-8',
        ),
    );

    for (const [kind, entries] of Object.entries<any>(manifest.components ?? {})) {
        if ((entries as any[]).some((entry) => entry.name === name)) {
            return kind;
        }
    }

    throw new Error(`매니페스트에 ${name} 선언이 없습니다.`);
}

const section = findNode(generalTab, (n) => n.id === 'attachment_cleanup_section');

describe('게시판 첨부 정리 설정 — 계약', () => {
    it('기본 설정 탭에 첨부 정리 섹션이 있다', () => {
        expect(section).not.toBeNull();
    });

    it('토글 노드의 type 이 매니페스트 선언과 같다', () => {
        const toggle = findNode(section, (n) => n.name === 'Toggle');

        // 어긋나면 렌더러가 editorAttrs 를 떼어내 이 노드만 레이아웃 편집기에서 표식을 잃는다.
        expect(toggle.type).toBe(manifestType('Toggle'));
    });

    it('토글과 보존기간이 attachment_settings 카테고리 키를 바인딩한다', () => {
        const toggle = findNode(section, (n) => n.name === 'Toggle');
        const input = findNode(section, (n) => n.name === 'Input');

        expect(toggle.props.name).toBe('attachment_settings.purge_enabled');
        expect(input.props.name).toBe('attachment_settings.purge_retention_days');
        expect(input.props.min).toContain('attachment_purge_retention_days_min');
        expect(input.props.max).toContain('attachment_purge_retention_days_max');
    });

    it('변경 시 form 상태와 hasChanges 를 함께 갱신한다', () => {
        const toggle = findNode(section, (n) => n.name === 'Toggle');
        const action = toggle.actions.find((a: any) => a.type === 'change');

        expect(action.params.hasChanges).toBe(true);

        // 값 갱신은 정적 점 경로 키로 한다. 중첩 객체(`form: { attachment_settings: {...} }`)로
        // 되돌리면 spread 누적이 되살아나 저장은 200 인데 값이 조용히 버려진다.
        expect(action.params['form.attachment_settings.purge_enabled']).toBe('{{$event.target.checked}}');
        expect(action.params.form).toBeUndefined();

        // 키 자체에 표현식을 쓰면 경로가 해석되지 않는다 (setState 규약).
        Object.keys(action.params).forEach((key) => {
            expect(key).not.toContain('{{');
        });
    });

    it('기본값이 꺼짐이고 보존기간 기본이 30일이다 (defaults.json)', () => {
        const defaults = JSON.parse(
            fs.readFileSync(path.join(moduleRoot(), 'config/settings/defaults.json'), 'utf-8'),
        );

        expect(defaults._meta.categories).toContain('attachment_settings');
        expect(defaults.defaults.attachment_settings.purge_enabled).toBe(false);
        expect(defaults.defaults.attachment_settings.purge_retention_days).toBe(30);
    });

    it('서버 검증이 같은 키를 받고 화면과 같은 한계값 출처를 쓴다', () => {
        const request = fs.readFileSync(
            path.join(moduleRoot(), 'src/Http/Requests/Admin/StoreBoardSettingsRequest.php'),
            'utf-8',
        );
        const boardConfig = fs.readFileSync(path.join(moduleRoot(), 'config/board.php'), 'utf-8');

        expect(request).toContain("'attachment_settings.purge_enabled' => ['nullable', 'boolean']");
        // 화면 입력과 저장 규칙이 같은 limits 키를 읽어야 "화면이 허용한 값인데 422" 가 생기지 않는다.
        expect(request).toContain("\$limits['attachment_purge_retention_days_min']");
        expect(request).toContain("\$limits['attachment_purge_retention_days_max']");
        expect(boardConfig).toContain("'attachment_purge_retention_days_min' => 1");
        expect(boardConfig).toContain("'attachment_purge_retention_days_max' => 3650");
    });

    it('기본 설정 탭 저장 payload 에 attachment_settings 가 실린다', () => {
        // 화면에 필드가 있어도 저장 요청 본문에 카테고리가 빠지면 저장은 200 인데 값만 사라진다
        // (브라우저 실측 — 토글을 켜고 저장해도 파일에 반영되지 않음).
        const settingsLayout = JSON.parse(
            fs.readFileSync(
                path.join(moduleRoot(), 'resources/layouts/admin/admin_board_settings.json'),
                'utf-8',
            ),
        );
        const raw = JSON.stringify(settingsLayout);
        const generalBranchStart = raw.indexOf("if (tab === 'general')");
        const generalBranch = raw.slice(generalBranchStart, raw.indexOf('; }', generalBranchStart));

        expect(generalBranchStart).toBeGreaterThan(-1);
        expect(generalBranch).toContain('attachment_settings: form.attachment_settings || {}');
    });

    it('서버가 attachment_settings 카테고리를 저장 대상으로 받는다', () => {
        const request = fs.readFileSync(
            path.join(moduleRoot(), 'src/Http/Requests/Admin/StoreBoardSettingsRequest.php'),
            'utf-8',
        );
        const validCategories = request.match(/\$validCategories = \[[^\]]*\]/)?.[0] ?? '';

        expect(validCategories).toContain("'attachment_settings'");
    });

    it('스케줄이 등록되고 영구 정리 파트만 커맨드 내부에서 게이트된다', () => {
        const manifest = fs.readFileSync(path.join(moduleRoot(), 'module.php'), 'utf-8');
        const command = fs.readFileSync(
            path.join(moduleRoot(), 'src/Console/Commands/PruneAttachmentsCommand.php'),
            'utf-8',
        );

        expect(manifest).toContain('sirsoft-board:prune-attachments --scheduled');
        expect(command).toContain("module_setting('sirsoft-board', 'attachment_settings.purge_enabled', false)");
    });
});
