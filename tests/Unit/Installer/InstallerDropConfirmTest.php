<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 기존 테이블 삭제 확인 토큰 회귀 테스트.
 *
 * 결함: 요청 본문의 existing_db_action=drop_tables 만으로 기존 테이블 삭제가 예약됐다 (다른 세션이
 * 값만 바꿔 보내도 DROP). 이제 DB 테스트가 발급한 확인 토큰 + 탐지 테이블 불변일 때만 허용한다.
 */
class InstallerDropConfirmTest extends TestCase
{
    /**
     * 순수 함수 로드.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3).'/public/install/includes/installer-ownership.php';
    }

    /**
     * 확인 토큰 발급 결과.
     *
     * @return array{token: string, record: array<string, mixed>} 확인 토큰 원문과 state 기록
     */
    private function issue(): array
    {
        return installer_issue_drop_confirm('g7_', ['g7_users', 'g7_roles'], 1_000_000);
    }

    /**
     * 발급된 적 없는 확인(state 에 기록 없음)은 거부된다.
     *
     * @effects drop_confirm_requires_token_tables_prefix_unexpired
     *
     * @return void 반환값 없음
     */
    public function test_rejects_when_not_issued(): void
    {
        $verdict = installer_verify_drop_confirm(null, 'anything', 'g7_', ['g7_users'], 1_000_000);

        $this->assertFalse($verdict['ok']);
        $this->assertSame('not_issued', $verdict['reason']);
    }

    /**
     * 토큰이 없거나 다르면 거부된다 — 요청 본문 값만으로는 삭제할 수 없다.
     *
     * @effects drop_confirm_requires_token_tables_prefix_unexpired
     *
     * @return void 반환값 없음
     */
    public function test_rejects_missing_or_wrong_token(): void
    {
        $issued = $this->issue();

        $this->assertSame('token_mismatch', installer_verify_drop_confirm($issued['record'], '', 'g7_', ['g7_users', 'g7_roles'], 1_000_000)['reason']);
        $this->assertSame('token_mismatch', installer_verify_drop_confirm($issued['record'], 'deadbeef', 'g7_', ['g7_users', 'g7_roles'], 1_000_000)['reason']);
    }

    /**
     * 탐지 테이블이 달라지면 거부된다 (순서만 다른 것은 같은 목록).
     *
     * @effects drop_confirm_requires_token_tables_prefix_unexpired
     *
     * @return void 반환값 없음
     */
    public function test_rejects_when_detected_tables_changed(): void
    {
        $issued = $this->issue();

        $this->assertTrue(installer_verify_drop_confirm($issued['record'], $issued['token'], 'g7_', ['g7_roles', 'g7_users'], 1_000_000)['ok']);
        $this->assertSame(
            'tables_changed',
            installer_verify_drop_confirm($issued['record'], $issued['token'], 'g7_', ['g7_users', 'g7_roles', 'g7_orders'], 1_000_000)['reason']
        );
        $this->assertSame(
            'tables_unavailable',
            installer_verify_drop_confirm($issued['record'], $issued['token'], 'g7_', null, 1_000_000)['reason']
        );
    }

    /**
     * 만료된 확인은 거부된다.
     *
     * @effects drop_confirm_requires_token_tables_prefix_unexpired
     *
     * @return void 반환값 없음
     */
    public function test_rejects_expired_confirmation(): void
    {
        $issued = $this->issue();

        $this->assertSame(
            'expired',
            installer_verify_drop_confirm($issued['record'], $issued['token'], 'g7_', ['g7_users', 'g7_roles'], 1_000_000 + INSTALLER_DROP_CONFIRM_TTL + 1)['reason']
        );
    }

    /**
     * 접두사가 바뀌면 거부된다 (다른 테이블 집합을 지우게 되므로).
     *
     * @effects drop_confirm_requires_token_tables_prefix_unexpired
     *
     * @return void 반환값 없음
     */
    public function test_rejects_prefix_change(): void
    {
        $issued = $this->issue();

        $this->assertSame('prefix_changed', installer_verify_drop_confirm($issued['record'], $issued['token'], 'x_', [], 1_000_000)['reason']);
    }

    /**
     * 소비된 확인은 같은 토큰·접두사의 재시도에서 테이블 변동·만료와 무관하게 허용된다 (이슈 #319 —
     * 부분 drop/migrate 후 폴링 재시도).
     *
     * @effects drop_confirm_consumed_allows_owner_retry
     *
     * @return void 반환값 없음
     */
    public function test_consumed_confirmation_allows_owner_retry(): void
    {
        $issued = $this->issue();
        $record = $issued['record'];
        $record['consumed'] = true;

        $verdict = installer_verify_drop_confirm($record, $issued['token'], 'g7_', null, 9_999_999_999);
        $this->assertTrue($verdict['ok']);
        $this->assertSame('retry', $verdict['reason']);

        $this->assertFalse(installer_verify_drop_confirm($record, 'wrong', 'g7_', null)['ok'], '소비된 확인도 토큰은 맞아야 한다');
    }

    /**
     * 최초 사용이 통과하면 확인을 소비로 표시하고 drop_tables 를 유지한다 — DROP 직전 재검증은
     * 소비된 확인만 인정하므로 표시가 빠지면 소유자의 삭제 동의가 무시된다.
     *
     * @effects drop_confirm_first_use_marks_consumed
     *
     * @return void 반환값 없음
     */
    public function test_first_use_marks_confirmation_consumed(): void
    {
        $issued = $this->issue();
        $this->assertFalse($issued['record']['consumed'], '전제: 발급 직후는 미소비');

        $decision = installer_apply_drop_confirm($issued['record'], $issued['token'], 'g7_', ['g7_roles', 'g7_users'], 1_000_000);

        $this->assertSame('drop_tables', $decision['action']);
        $this->assertFalse($decision['rejected']);
        $this->assertSame('first_use', $decision['reason']);
        $this->assertTrue($decision['record']['consumed']);
        $this->assertSame($issued['record']['hash'], $decision['record']['hash'], '소비 표시 외의 기록은 바뀌지 않는다');

        // 소비된 기록으로 다시 요청(같은 소유자의 재시도)해도 drop_tables 가 유지된다.
        $retry = installer_apply_drop_confirm($decision['record'], $issued['token'], 'g7_', null, 9_999_999_999);
        $this->assertSame('drop_tables', $retry['action']);
        $this->assertSame('retry', $retry['reason']);
    }

    /**
     * 검증에 실패하면 skip 으로 강등하고 기록을 소비로 바꾸지 않는다.
     *
     * @effects unconfirmed_drop_downgraded_to_skip
     *
     * @return void 반환값 없음
     */
    public function test_rejected_confirmation_downgrades_to_skip_without_consuming(): void
    {
        $issued = $this->issue();

        $changed = installer_apply_drop_confirm($issued['record'], $issued['token'], 'g7_', ['g7_users'], 1_000_000);
        $this->assertSame('skip', $changed['action']);
        $this->assertTrue($changed['rejected']);
        $this->assertSame('tables_changed', $changed['reason']);
        $this->assertFalse($changed['record']['consumed']);

        $missing = installer_apply_drop_confirm(null, 'anything', 'g7_', ['g7_users'], 1_000_000);
        $this->assertSame('skip', $missing['action']);
        $this->assertNull($missing['record']);
    }

    /**
     * 설치 시작 요청이 확인 적용을 이 함수로만 한다 (소스 계약) — 호출부가 소비 표시를 따로 다루면
     * 위 두 테스트가 재는 동작이 실제 요청 경로에서 빠질 수 있다.
     *
     * @effects drop_confirm_first_use_marks_consumed
     *
     * @return void 반환값 없음
     */
    public function test_install_process_applies_confirmation_through_helper(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/install-process.php');

        $this->assertSame(1, substr_count($source, 'installer_apply_drop_confirm('));
        $this->assertStringNotContainsString('installer_verify_drop_confirm(', $source);
        $this->assertStringContainsString("\$existingDbAction = \$decision['action'];", $source);
        $this->assertStringContainsString("\$dropConfirmRecord = \$decision['record'];", $source);
    }

    /**
     * 빈 접두사는 프론트와 같은 규칙(g7_)으로 정규화된다.
     *
     * @effects fingerprint_normalization_matches_frontend
     *
     * @return void 반환값 없음
     */
    public function test_blank_prefix_normalized_like_frontend(): void
    {
        $issued = installer_issue_drop_confirm('', ['g7_users'], 1_000_000);

        $this->assertSame('g7_', $issued['record']['prefix']);
        $this->assertTrue(installer_verify_drop_confirm($issued['record'], $issued['token'], 'g7_', ['g7_users'], 1_000_000)['ok']);
    }

    /**
     * 기록에는 토큰 해시만 남는다.
     *
     * @effects ownership_state_stores_hashes_only
     *
     * @return void 반환값 없음
     */
    public function test_record_stores_only_token_hash(): void
    {
        $issued = $this->issue();

        $this->assertSame(hash('sha256', $issued['token']), $issued['record']['hash']);
        $this->assertStringNotContainsString($issued['token'], json_encode($issued['record']));
    }
}
