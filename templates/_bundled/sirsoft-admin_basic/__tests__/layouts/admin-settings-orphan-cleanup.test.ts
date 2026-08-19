/**
 * 환경설정 업로드 탭 — 고아 첨부 정리 필드 계약 테스트 (공개 #115)
 *
 * @description
 * 고아 첨부 정리는 사용자 파일을 파기하므로 기본 꺼짐이다. 화면 바인딩 키가 코어
 * 기본값·서버 검증과 일치하는지 고정한다. 한 곳만 어긋나면 저장은 성공하는데 값이
 * 조용히 버려지고, 운영자는 켰다고 믿는 상태로 남는다.
 *
 * @effects core_settings_orphan_toggle_default_off, core_settings_orphan_keys_declared
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import uploadTab from '../../layouts/partials/admin_settings/_tab_upload.json';

/**
 * 저장소 루트(composer.json + artisan 기준)를 위로 훑어 찾는다.
 *
 * @returns 저장소 루트 절대경로
 */
function repoRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'artisan'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('artisan 을 가진 저장소 루트를 찾지 못했습니다.');
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
 * 템플릿 매니페스트가 선언한 컴포넌트 종류를 읽는다.
 *
 * 레이아웃 노드의 `type` 은 렌더러가 DOM 안전 필터링을 고르는 기준이라
 * 매니페스트 선언과 어긋나면 `editorAttrs` 가 제거된다(DynamicRenderer).
 *
 * @param name 컴포넌트 이름
 * @returns 매니페스트가 선언한 type
 */
function manifestType(name: string): string {
    const manifest = JSON.parse(
        fs.readFileSync(
            path.join(repoRoot(), 'templates/_bundled/sirsoft-admin_basic/components.json'),
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

const card = findNode(uploadTab, (n) => n.id === 'card_orphan_cleanup');

describe('고아 첨부 정리 설정 — 계약', () => {
    it('업로드 탭에 고아 첨부 정리 카드가 있다', () => {
        expect(card).not.toBeNull();
    });

    it('토글과 보존기간이 upload 카테고리 키를 바인딩한다', () => {
        const toggle = findNode(card, (n) => n.name === 'Toggle');
        const input = findNode(card, (n) => n.name === 'Input');

        expect(toggle.props.name).toBe('upload.orphan_cleanup_enabled');
        expect(input.props.name).toBe('upload.orphan_retention_days');
        expect(input.props.min).toContain('upload_orphan_retention_days_min');
        expect(input.props.max).toContain('upload_orphan_retention_days_max');
    });

    it('토글 노드의 type 이 매니페스트 선언과 같다', () => {
        const toggle = findNode(card, (n) => n.name === 'Toggle');

        // 어긋나면 렌더러가 editorAttrs 를 떼어내 이 노드만 레이아웃 편집기에서 표식을 잃는다.
        expect(toggle.type).toBe(manifestType('Toggle'));
    });

    it('읽기 전용 권한에서는 두 입력이 비활성화된다', () => {
        const toggle = findNode(card, (n) => n.name === 'Toggle');
        const input = findNode(card, (n) => n.name === 'Input');

        expect(toggle.props.disabled).toBe('{{_computed.isReadOnly}}');
        expect(input.props.disabled).toBe('{{_computed.isReadOnly}}');
    });

    it('기본값이 꺼짐이고 보존기간 기본이 30일이다 (코어 defaults.json)', () => {
        const defaults = JSON.parse(
            fs.readFileSync(path.join(repoRoot(), 'config/settings/defaults.json'), 'utf-8'),
        );

        expect(defaults.defaults.upload.orphan_cleanup_enabled).toBe(false);
        expect(defaults.defaults.upload.orphan_retention_days).toBe(30);
        expect(defaults.frontend_schema.upload.fields.orphan_cleanup_enabled.expose).toBe(false);
    });

    it('서버 검증이 같은 키를 받고 화면과 같은 한계값 출처를 쓴다', () => {
        const request = fs.readFileSync(
            path.join(repoRoot(), 'app/Http/Requests/Settings/SaveSettingsRequest.php'),
            'utf-8',
        );
        const coreConfig = fs.readFileSync(path.join(repoRoot(), 'config/core.php'), 'utf-8');

        expect(request).toContain("'upload.orphan_cleanup_enabled' => ['nullable', 'boolean']");
        // 화면 입력과 저장 규칙이 같은 config 키를 읽어야 "화면이 허용한 값인데 422" 가 생기지 않는다.
        expect(request).toContain('core.settings_limits.upload_orphan_retention_days_min');
        expect(request).toContain('core.settings_limits.upload_orphan_retention_days_max');
        expect(coreConfig).toContain("'upload_orphan_retention_days_min' => 1");
        expect(coreConfig).toContain("'upload_orphan_retention_days_max' => 3650");
    });

    it('스케줄이 등록되고 커맨드가 같은 설정을 false 폴백으로 재확인한다', () => {
        const console = fs.readFileSync(path.join(repoRoot(), 'routes/console.php'), 'utf-8');
        const command = fs.readFileSync(
            path.join(repoRoot(), 'app/Console/Commands/PruneOrphanAttachmentsCommand.php'),
            'utf-8',
        );

        expect(console).toContain("attachments:prune-orphans --scheduled");
        expect(command).toContain("g7_core_settings('upload.orphan_cleanup_enabled', false)");
    });
});
