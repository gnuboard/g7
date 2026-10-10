<?php

namespace Tests\Unit\Layouts;

use PHPUnit\Framework\TestCase;

/**
 * 관리자 화면 abilities 게이트 회귀 가드 (7.1.0 abilities 게이트 수정).
 *
 * 권한 없는 운영자에게 쓰기 UI 가 잠기는지를 굽힌 레이아웃 JSON(런타임 계약)으로 단언한다.
 * 화면이 쓰는 권한 키는 서버 응답(Resource abilities·설치 미리보기 can_select 등)과 같은 이름이어야
 * 하고, 서버는 같은 권한으로 403 을 낸다(각 Feature 테스트가 담당).
 */
class AdminAbilitiesGateLayoutTest extends TestCase
{
    /**
     * 관리자 템플릿 레이아웃 디렉터리 절대경로
     */
    private const ADMIN_LAYOUTS = __DIR__.'/../../../templates/_bundled/sirsoft-admin_basic/layouts';

    /**
     * 굽힌 레이아웃 JSON 을 읽습니다.
     *
     * @param  string  $relative  layouts 기준 상대 경로(.json)
     * @return array<string, mixed> 레이아웃 트리
     */
    private function layout(string $relative): array
    {
        $path = self::ADMIN_LAYOUTS.'/'.$relative;
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * 조건을 만족하는 모든 컴포넌트 노드를 모읍니다.
     *
     * @param  mixed  $tree  레이아웃 트리
     * @param  callable(array<string, mixed>): bool  $predicate  노드 판정
     * @return array<int, array<string, mixed>> 일치 노드 목록
     */
    private function findNodes(mixed $tree, callable $predicate): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found, $predicate): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['name']) && is_string($node['name']) && $predicate($node)) {
                $found[] = $node;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($tree);

        return $found;
    }

    /**
     * props 중 한 값에 문자열이 들어 있는 노드를 찾습니다.
     *
     * @param  array<string, mixed>  $tree  레이아웃 트리
     * @param  string  $prop  props 키
     * @param  string  $needle  찾을 문자열
     * @return array<int, array<string, mixed>> 일치 노드 목록
     */
    private function nodesWithProp(array $tree, string $prop, string $needle): array
    {
        return $this->findNodes($tree, fn (array $n) => is_string($n['props'][$prop] ?? null)
            && str_contains($n['props'][$prop], $needle));
    }

    /**
     * 노드 text 에 문자열이 들어 있는 노드를 찾습니다.
     *
     * @param  array<string, mixed>  $tree  레이아웃 트리
     * @param  string  $needle  찾을 문자열
     * @return array<int, array<string, mixed>> 일치 노드 목록
     */
    private function nodesWithText(array $tree, string $needle): array
    {
        return $this->findNodes($tree, fn (array $n) => is_string($n['text'] ?? null)
            && str_contains($n['text'], $needle));
    }

    /**
     * 설치 창 3종의 동반 설치 체크박스는 항목별 권한(can_select)으로 잠기고, 권한 없음 안내가 있다 (R2)
     *
     * @scenario screen=extension_install_modal
     *
     * @effects cascade_install_checkboxes_locked_by_can_select
     *
     * @return void 반환값 없음
     */
    public function test_cascade_install_checkboxes_are_locked_by_can_select(): void
    {
        foreach (['module', 'plugin', 'template'] as $kind) {
            $tree = $this->layout("partials/admin_{$kind}_list/_modal_install.json");

            $depBoxes = $this->nodesWithProp($tree, 'checked', 'includes(dep.identifier)');
            $this->assertCount(1, $depBoxes, $kind);
            $this->assertStringContainsString('dep.can_select !== true', $depBoxes[0]['props']['disabled'], $kind);
            $this->assertStringStartsWith('{{dep.can_select === true &&', $depBoxes[0]['props']['checked'], $kind);

            $lpBoxes = $this->nodesWithProp($tree, 'checked', 'includes(lp.bundled_identifier)');
            $this->assertCount(1, $lpBoxes, $kind);
            $this->assertStringStartsWith('{{lp.can_select !== true ||', $lpBoxes[0]['props']['disabled'], $kind);
            $this->assertStringStartsWith('{{lp.can_select === true &&', $lpBoxes[0]['props']['checked'], $kind);

            $encoded = json_encode($tree, JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('modals.cascade_no_permission"', $encoded, $kind);
            $this->assertStringContainsString('modals.cascade_no_permission_hint', $encoded, $kind);
        }
    }

    /**
     * 스케줄 목록: 등록 버튼·일괄 변경·행 선택·행 토글이 abilities 로 잠긴다 (G22)
     *
     * @scenario screen=schedule_list
     *
     * @effects schedule_list_write_controls_gated
     *
     * @return void 반환값 없음
     */
    public function test_schedule_list_write_controls_are_gated(): void
    {
        $page = $this->layout('admin_schedule_list.json');
        $add = $this->findNodes($page, fn (array $n) => ($n['id'] ?? null) === 'add_schedule_button');
        $this->assertCount(1, $add);
        $this->assertSame('{{schedules?.data?.abilities?.can_create !== true}}', $add[0]['props']['disabled'] ?? null);

        $tab = $this->layout('partials/admin_schedule_list/_tab_schedules.json');

        foreach (['bulk_status', 'bulk_action'] as $name) {
            $select = $this->findNodes($tab, fn (array $n) => ($n['props']['name'] ?? null) === $name);
            $this->assertCount(1, $select, $name);
            $this->assertStringContainsString('schedules?.data?.abilities?.can_', $select[0]['props']['disabled'], $name);
        }

        $apply = $this->nodesWithText($tab, 'admin.schedules.bulk.apply');
        $this->assertCount(1, $apply);
        $this->assertStringContainsString('schedules?.data?.abilities?.can_update !== true', $apply[0]['props']['disabled']);

        $grid = $this->findNodes($tab, fn (array $n) => ($n['id'] ?? null) === 'schedules_data_grid');
        $this->assertCount(1, $grid);
        $this->assertSame('{{schedules?.data?.abilities?.can_update === true}}', $grid[0]['props']['selectable'] ?? null);

        $toggle = $this->findNodes($tab, fn (array $n) => $n['name'] === 'Toggle'
            && ($n['props']['checked'] ?? null) === '{{row.is_active}}');
        $this->assertCount(1, $toggle);
        $this->assertSame('{{row.abilities?.can_update !== true}}', $toggle[0]['props']['disabled'] ?? null);
    }

    /**
     * 관리자 헤더 알림 센터(데스크톱·모바일)는 읽음·삭제 권한을 컴포넌트에 넘기고, 클릭 읽음 처리는 권한이 있을 때만 보낸다 (G21)
     *
     * @scenario screen=admin_header_notification_center
     *
     * @effects admin_notification_center_write_actions_gated
     *
     * @return void 반환값 없음
     */
    public function test_admin_header_notification_center_is_gated(): void
    {
        $tree = $this->layout('_admin_base.json');

        $centers = $this->findNodes($tree, fn (array $n) => $n['name'] === 'NotificationCenter');
        $this->assertCount(2, $centers);
        foreach ($centers as $center) {
            $this->assertSame('{{notifications?.data?.abilities?.can_update === true}}', $center['props']['canUpdate'] ?? null);
            $this->assertSame('{{notifications?.data?.abilities?.can_delete === true}}', $center['props']['canDelete'] ?? null);
        }

        $encoded = json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertSame(2, substr_count($encoded, '"if":"{{notifications?.data?.abilities?.can_update === true}}"'));
    }

    /**
     * 대시보드 경고 [복구]·[닫기]는 경고별 abilities 로 잠기고 안내가 뜬다. 경고 소스는 403 을 조용히 넘긴다 (G23)
     *
     * @scenario screen=dashboard_alerts
     *
     * @effects dashboard_alert_actions_gated_with_hint
     *
     * @return void 반환값 없음
     */
    public function test_dashboard_alert_actions_are_gated(): void
    {
        $tree = $this->layout('admin_dashboard.json');

        foreach (['top_alert', 'alert'] as $prefix) {
            $recover = $this->findNodes($tree, fn (array $n) => ($n['id'] ?? null) === $prefix.'_recover_button_{{$idx}}');
            $this->assertCount(1, $recover, $prefix);
            $this->assertSame('{{alert.abilities?.can_recover !== true}}', $recover[0]['props']['disabled'] ?? null);

            $dismiss = $this->findNodes($tree, fn (array $n) => ($n['id'] ?? null) === $prefix.'_dismiss_button_{{$idx}}');
            $this->assertCount(1, $dismiss, $prefix);
            $this->assertSame('{{alert.abilities?.can_dismiss !== true}}', $dismiss[0]['props']['disabled'] ?? null);

            $hint = $this->findNodes($tree, fn (array $n) => ($n['id'] ?? null) === $prefix.'_permission_hint_{{$idx}}');
            $this->assertCount(1, $hint, $prefix);
        }

        $source = collect($tree['data_sources'])->firstWhere('id', 'dashboard_alerts');
        $this->assertNotNull($source);
        $this->assertArrayHasKey('403', $source['errorHandling'] ?? []);
    }

    /**
     * 환경설정 SEO 탭의 모든 입력은 읽기 전용이면 잠긴다 (G06 코어분 13칸)
     *
     * @scenario screen=settings_seo_tab
     *
     * @effects settings_seo_inputs_follow_read_only
     *
     * @return void 반환값 없음
     */
    public function test_settings_seo_tab_inputs_follow_read_only(): void
    {
        $tree = $this->layout('partials/admin_settings/_tab_seo.json');

        $controls = $this->findNodes($tree, fn (array $n) => is_string($n['props']['name'] ?? null)
            && str_starts_with($n['props']['name'], 'seo.'));
        $this->assertGreaterThanOrEqual(20, count($controls));

        $unlocked = array_values(array_map(
            fn (array $n) => $n['props']['name'],
            array_filter($controls, fn (array $n) => ! str_contains((string) ($n['props']['disabled'] ?? ''), '_computed.isReadOnly'))
        ));
        $this->assertSame([], $unlocked, '읽기 전용 게이트가 없는 SEO 입력');
    }

    /**
     * 환경설정 읽기 전용 판정은 설정 응답을 받기 전에도 잠긴 쪽이다 — 응답 전 빈 입력칸이 편집되지 않는다 (settings#6)
     *
     * @scenario screen=settings,state=before_response
     *
     * @effects settings_read_only_fail_closed_before_response
     *
     * @return void 반환값 없음
     */
    public function test_settings_read_only_is_fail_closed_before_response(): void
    {
        $tree = $this->layout('admin_settings.json');

        $this->assertSame(
            '{{_global.settingsAbilities?.can_update !== true}}',
            $tree['computed']['isReadOnly'] ?? null
        );

        $banner = $this->findNodes($tree, fn (array $n) => ($n['id'] ?? null) === 'readonly_banner');
        $this->assertCount(1, $banner);
        $this->assertSame('{{!!_global.settingsAbilities && _global.settingsAbilities.can_update !== true}}', $banner[0]['if'] ?? null);
    }

    /**
     * 개인정보 탭 「지금 실행」은 보안 탭과 같은 방식 — 대상이 있으면 보이고, 실행 권한이 없으면 비활성 (settings#5, 2026-10-04 결정)
     *
     * @scenario screen=settings_privacy_tab
     *
     * @effects privacy_run_now_disabled_without_can_run
     *
     * @return void 반환값 없음
     */
    public function test_privacy_run_now_is_visible_but_disabled_without_can_run(): void
    {
        $tree = $this->layout('partials/admin_settings/_tab_privacy.json');

        foreach (['run_withdrawn', 'run_dormant_stage_{{stage}}'] as $id) {
            $button = $this->findNodes($tree, fn (array $n) => ($n['id'] ?? null) === $id);
            $this->assertCount(1, $button, $id);
            $this->assertStringNotContainsString('can_run', (string) ($button[0]['if'] ?? ''), $id);
            $this->assertStringStartsWith('{{!privacyManualRuns?.data?.targets?.', $button[0]['props']['disabled'], $id);
            $this->assertStringContainsString('?.can_run ||', $button[0]['props']['disabled'], $id);
        }
    }

    /**
     * 확장 목록·레이아웃 편집기 게이트 (G20·#8·#10·#11·#2)
     *
     * - 플러그인 「설정」은 설정 저장 권한(can_update)으로 잠긴다(G20).
     * - 「언어팩 관리」는 언어팩 조회 권한(can_view_language_packs)이 없으면 잠긴다(#8).
     * - 템플릿 편집 버튼·편집기 저장·트리 관리·본문은 abilities 가 없으면 잠기는 쪽이다(#10·#11).
     * - 판본 복원은 수정 권한으로 잠긴다(#2).
     *
     * @scenario screen=extension_lists|layout_editor
     *
     * @effects extension_list_and_editor_gates_fail_closed
     *
     * @return void 반환값 없음
     */
    public function test_extension_list_and_editor_gates_are_fail_closed(): void
    {
        $encode = fn (string $rel) => json_encode($this->layout($rel), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $plugins = $encode('admin_plugin_list.json');
        $this->assertStringContainsString("iconName: 'cog', disabled: !p.has_settings || p.status !== 'active' || p.abilities?.can_update !== true", $plugins);
        $this->assertStringContainsString('plugins?.data?.abilities?.can_view_language_packs !== true', $plugins);
        $this->assertStringContainsString('modules?.data?.abilities?.can_view_language_packs !== true', $encode('admin_module_list.json'));

        foreach (['_tab_user', '_tab_admin'] as $tab) {
            $json = $encode("partials/admin_template_list/{$tab}.json");
            $this->assertStringNotContainsString('can_edit_layouts === false', $json, $tab);
            $this->assertSame(2, substr_count($json, 'can_edit_layouts !== true'), $tab);
            $this->assertStringContainsString('templates?.data?.abilities?.can_view_language_packs !== true', $json, $tab);
        }

        $editor = $encode('admin_template_layout_edit.json');
        $this->assertStringNotContainsString('can_update !== false', $editor);
        $this->assertStringNotContainsString('can_update) === false', $editor);
        $this->assertStringContainsString('can_update) !== true', $editor);

        $this->assertStringContainsString('_global.isRestoring || current_layout?.data?.abilities?.can_update !== true', $encode('partials/admin_template_layout_edit/_modal_version_history.json'));
        $this->assertStringContainsString('_global.isRestoringExtension || current_extension?.data?.abilities?.can_update !== true', $encode('partials/admin_template_layout_edit/_modal_extension_version_history.json'));
    }

    /**
     * 회원 폼·활동 로그 게이트 (core-admin-users #6·#7·#9·#10)
     *
     * @scenario screen=user_form|activity_log_list
     *
     * @effects user_form_and_activity_log_gated
     *
     * @return void 반환값 없음
     */
    public function test_user_form_and_activity_log_gates(): void
    {
        $form = $this->layout('admin_user_form.json');
        $sources = collect($form['data_sources'])->keyBy('id');

        $this->assertSame('{{response?.data?.abilities?.can_update === false}}', $sources['user']['errorCondition']['if'] ?? null);
        $this->assertSame('{{!route?.id}}', $sources['user_check']['if'] ?? null);
        $this->assertSame('{{response?.data?.abilities?.can_create === false}}', $sources['user_check']['errorCondition']['if'] ?? null);

        $roles = $this->findNodes($form, fn (array $n) => ($n['props']['name'] ?? null) === 'role_ids');
        $this->assertCount(1, $roles);
        $this->assertSame(
            '{{route?.id ? user?.data?.abilities?.can_assign_roles !== true : roles?.data?.abilities?.can_assign_roles !== true}}',
            $roles[0]['props']['disabled'] ?? null
        );

        $grid = $this->findNodes($this->layout('partials/admin_activity_log_list/_partial_datagrid.json'), fn (array $n) => ($n['id'] ?? null) === 'activity_log_datagrid');
        $this->assertSame('{{activityLogs?.data?.abilities?.can_delete === true}}', $grid[0]['props']['selectable'] ?? null);

        $bar = $this->findNodes($this->layout('admin_activity_log_list.json'), fn (array $n) => ($n['id'] ?? null) === 'bulk_action_bar');
        $this->assertSame('{{activityLogs?.data?.abilities?.can_delete === true}}', $bar[0]['if'] ?? null);
    }

    /**
     * 역할 폼: 최고관리자 역할은 사용 여부 토글이 잠기고 안내가 뜬다 (S8)
     *
     * @scenario screen=role_form,role=admin
     *
     * @effects role_form_locks_admin_role_active_state
     *
     * @return void 반환값 없음
     */
    public function test_role_form_locks_is_active_for_admin_role(): void
    {
        $tree = $this->layout('admin_role_form.json');

        $toggles = $this->findNodes($tree, fn (array $n) => $n['name'] === 'Toggle'
            && ($n['props']['name'] ?? null) === 'is_active');
        $this->assertCount(1, $toggles);
        $this->assertSame(
            '{{!!route?.id && role?.data?.is_active_locked === true}}',
            $toggles[0]['props']['disabled'] ?? null
        );

        $hint = $this->nodesWithText($tree, 'admin.roles.form.is_active_locked_hint');
        $this->assertCount(1, $hint);
        $this->assertSame('{{!!route?.id && role?.data?.is_active_locked === true}}', $hint[0]['if'] ?? null);
    }
}
