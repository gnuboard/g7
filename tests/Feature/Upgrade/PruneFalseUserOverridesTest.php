<?php

namespace Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_1_0\Migrations\PruneFalseUserOverrides;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 7.1.0 데이터 마이그레이션 — 거짓 "운영자 수정" 표식 정리 회귀 테스트 (#727 W8).
 *
 * 값을 바꾸지 않은 저장이 남긴 표식(지금 값 = 코어 선언값)만 지우고, 진짜 수정 표식과 선언에 없는 로케일 표식은 남기며,
 * 표식이 비면 「기본」 으로 되돌리고, 두 번 돌려도 같은 결과인지 고정한다. 비교 기준은 live config 가 아니라
 * `upgrades/data/7.1.0/*.snapshot.json` 에 동결한 7.1.0 선언이며, 대상 테이블이 없으면 그 대상만 조용히 건너뛴다.
 */
class PruneFalseUserOverridesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 데이터 마이그레이션 클래스를 불러옵니다(업그레이드 스텝이 동적으로 require 하는 파일).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.1.0/migrations/03_PruneFalseUserOverrides.php');
    }

    /**
     * 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new PruneFalseUserOverrides)->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));
    }

    /**
     * 스텝이 비교 기준으로 쓰는 동결 선언 사본을 읽습니다.
     *
     * @param  string  $kind  선언 종류 (identity_policies · notification_definitions · identity_messages)
     * @return array<string, array<string, mixed>> 키 => 선언
     */
    private function frozen(string $kind): array
    {
        $decoded = json_decode((string) file_get_contents(base_path("upgrades/data/7.1.0/{$kind}.snapshot.json")), true);

        return $decoded['declarations'];
    }

    /**
     * 동결 선언의 첫 본인인증 정책 행을 준비하고 키·선언을 돌려줍니다(행이 없으면 선언대로 만든다).
     *
     * @return array{0: string, 1: array<string, mixed>} [정책 키, 동결 선언]
     */
    private function ensureFirstPolicyRow(): array
    {
        $policies = $this->frozen('identity_policies');
        $key = array_key_first($policies);
        $declared = $policies[$key];

        if (DB::table('identity_policies')->where('key', $key)->doesntExist()) {
            DB::table('identity_policies')->insert([
                'key' => $key, 'scope' => $declared['scope'], 'target' => $declared['target'], 'purpose' => $declared['purpose'],
                'provider_id' => $declared['provider_id'] ?? null, 'grace_minutes' => $declared['grace_minutes'] ?? 0,
                'enabled' => $declared['enabled'] ?? true, 'priority' => $declared['priority'] ?? 100,
                'applies_to' => $declared['applies_to'] ?? 'both', 'fail_mode' => $declared['fail_mode'] ?? 'block',
                'conditions' => isset($declared['conditions']) ? json_encode($declared['conditions']) : null,
                'source_type' => 'core', 'source_identifier' => 'core', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$key, $declared];
    }

    /**
     * 코어 선언 본인인증 정책 — 선언값과 같은 칸의 표식만 지운다.
     *
     * @scenario surface=upgrade_prune
     *
     * @effects false_overrides_are_pruned_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_policy_false_overrides_are_pruned_and_real_ones_kept(): void
    {
        [$key, $declared] = $this->ensureFirstPolicyRow();

        // 거짓 표식 8칸 + 진짜 수정 1칸(grace_minutes)
        DB::table('identity_policies')->where('key', $key)->update([
            'grace_minutes' => ((int) ($declared['grace_minutes'] ?? 0)) + 7,
            'enabled' => $declared['enabled'] ?? true,
            'user_overrides' => json_encode(['enabled', 'grace_minutes', 'provider_id', 'fail_mode', 'conditions', 'purpose', 'applies_to', 'priority']),
        ]);

        $this->runStep();

        $this->assertSame(['grace_minutes'], json_decode(DB::table('identity_policies')->where('key', $key)->value('user_overrides'), true));

        // 멱등
        $this->runStep();
        $this->assertSame(['grace_minutes'], json_decode(DB::table('identity_policies')->where('key', $key)->value('user_overrides'), true));
    }

    /**
     * 코어 알림 템플릿 — 선언과 같은 로케일 표식은 지우고 「기본」 으로 되돌리며, 선언에 없는 로케일(ja) 표식은 남긴다.
     *
     * @scenario surface=upgrade_prune
     *
     * @effects false_overrides_are_pruned_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_notification_template_false_overrides_pruned_and_default_restored(): void
    {
        $type = array_key_first($this->frozen('notification_definitions'));
        $template = $this->frozen('notification_definitions')[$type]['templates'][0];
        $definitionId = $this->ensureNotificationDefinition($type);
        DB::table('notification_definitions')->where('id', $definitionId)->update(['is_default' => 0]);
        DB::table('notification_templates')->where('definition_id', $definitionId)->where('channel', $template['channel'])->delete();

        $body = $template['body'];
        $body['ja'] = '日本語';
        $templateId = DB::table('notification_templates')->insertGetId([
            'definition_id' => $definitionId, 'channel' => $template['channel'],
            'subject' => json_encode(array_map(fn ($v) => ' '.$v.' ', $template['subject'] ?? ['ko' => ''])),
            'body' => json_encode($body), 'recipients' => isset($template['recipients']) ? json_encode($template['recipients']) : null,
            'is_active' => 1, 'is_default' => 0,
            'user_overrides' => json_encode(['subject.ko', 'body.ko', 'body.ja']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runStep();

        $row = DB::table('notification_templates')->where('id', $templateId)->first();
        $this->assertSame(['body.ja'], json_decode($row->user_overrides, true), '선언에 없는 로케일 표식만 남아야 한다');
        $this->assertSame(0, (int) $row->is_default, '표식이 남았으니 아직 기본이 아니다');

        // 남은 표식도 사라지면 기본 복귀 + 정의도 기본 복귀
        DB::table('notification_templates')->where('id', $templateId)->update(['user_overrides' => json_encode(['subject.ko'])]);
        $this->runStep();

        $this->assertNull(DB::table('notification_templates')->where('id', $templateId)->value('user_overrides'));
        $this->assertSame(1, (int) DB::table('notification_templates')->where('id', $templateId)->value('is_default'));
    }

    /**
     * 코어 알림 정의 행을 준비하고 ID 를 돌려줍니다(행이 없으면 live config 의 표시 칸으로 만든다 — 판정 칸은 동결 사본).
     *
     * @param  string  $type  알림 정의 type
     * @return int 정의 ID
     */
    private function ensureNotificationDefinition(string $type): int
    {
        $declaration = config('core.notification_definitions')[$type];

        return (int) (DB::table('notification_definitions')->where('type', $type)->where('extension_type', 'core')->value('id')
            ?? DB::table('notification_definitions')->insertGetId([
                'type' => $type, 'hook_prefix' => $declaration['hook_prefix'], 'extension_type' => 'core', 'extension_identifier' => 'core',
                'name' => json_encode($declaration['name']), 'channels' => json_encode($declaration['channels'] ?? ['mail']),
                'hooks' => json_encode($declaration['hooks'] ?? []), 'is_active' => 1, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]));
    }

    /**
     * 비교 기준은 동결한 7.1.0 선언이다 — 실행 시점 config 가 바뀌어도(미래 버전 코드 위에서 돌 때) 판정이 흔들리지 않는다.
     *
     * 지금 값 = 7.1.0 선언값인 칸의 표식은, live config 가 다른 값으로 바뀌었어도 지워져야 한다.
     *
     * @scenario surface=upgrade_prune
     *
     * @effects false_overrides_are_pruned_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_step_compares_against_frozen_710_declarations_not_live_config(): void
    {
        [$key, $declared] = $this->ensureFirstPolicyRow();
        $frozenGrace = (int) ($declared['grace_minutes'] ?? 0);

        DB::table('identity_policies')->where('key', $key)->update([
            'grace_minutes' => $frozenGrace,
            'user_overrides' => json_encode(['grace_minutes']),
        ]);

        // 미래 버전이 선언을 바꾼 상황 — live config 만 다르다
        $live = config('core.identity_policies');
        $live[$key]['grace_minutes'] = $frozenGrace + 99;
        config()->set('core.identity_policies', $live);

        $this->runStep();

        $this->assertNull(
            DB::table('identity_policies')->where('key', $key)->value('user_overrides'),
            '지금 값이 동결된 7.1.0 선언값과 같으니 표식은 지워져야 한다 — live config 와 비교하면 남는다'
        );
    }

    /**
     * 대상 테이블이 없으면 그 대상만 건너뛰고 예외 없이 끝난다 — 다른 대상은 그대로 정리한다 (계획서 §5.11.6).
     *
     * 실제 테이블을 지우지 않고 Schema 파사드의 존재 판정만 바꾼다(DatabaseTransactions 안에서 DDL 은 암묵 커밋을 일으킨다).
     *
     * @scenario surface=upgrade_prune
     *
     * @effects false_overrides_are_pruned_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_missing_target_table_is_skipped_without_error(): void
    {
        // 지금 값 = 선언값(거짓 표식) — 테이블이 있으면 지워질 표식이다
        [$key, $declared] = $this->ensureFirstPolicyRow();
        DB::table('identity_policies')->where('key', $key)->update(['purpose' => $declared['purpose'], 'user_overrides' => json_encode(['purpose'])]);

        $type = array_key_first($this->frozen('notification_definitions'));
        $template = $this->frozen('notification_definitions')[$type]['templates'][0];
        $definitionId = $this->ensureNotificationDefinition($type);
        DB::table('notification_templates')->where('definition_id', $definitionId)->where('channel', $template['channel'])->delete();
        $templateId = DB::table('notification_templates')->insertGetId([
            'definition_id' => $definitionId, 'channel' => $template['channel'],
            'subject' => json_encode($template['subject'] ?? ['ko' => '']), 'body' => json_encode($template['body']),
            'recipients' => isset($template['recipients']) ? json_encode($template['recipients']) : null,
            'is_active' => 1, 'is_default' => 0, 'user_overrides' => json_encode(['subject.ko']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // identity_policies 가 없는 설치본 — 나머지 판정은 실제 스키마 그대로
        $real = Schema::getFacadeRoot();
        Schema::shouldReceive('hasTable')->andReturnUsing(fn (string $table) => $table === 'identity_policies' ? false : $real->hasTable($table));
        Schema::shouldReceive('hasColumn')->andReturnUsing(fn (string $table, string $column) => $table === 'identity_policies' ? false : $real->hasColumn($table, $column));

        $this->runStep();

        $this->assertSame(['purpose'], json_decode(DB::table('identity_policies')->where('key', $key)->value('user_overrides'), true), '없는 테이블로 판정된 대상은 건드리지 않는다');
        $this->assertNull(DB::table('notification_templates')->where('id', $templateId)->value('user_overrides'), '다른 대상은 그대로 정리돼야 한다');
    }

    /**
     * 대상 테이블이 전부 없는 설치본에서도 예외 없이 아무것도 바꾸지 않고 끝난다 (계획서 §5.11.6).
     *
     * @scenario surface=upgrade_prune
     *
     * @effects false_overrides_are_pruned_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_all_target_tables_missing_is_a_no_op(): void
    {
        // 지금 값 = 선언값(거짓 표식) — 테이블이 있으면 지워질 표식이다
        [$key, $declared] = $this->ensureFirstPolicyRow();
        DB::table('identity_policies')->where('key', $key)->update(['purpose' => $declared['purpose'], 'user_overrides' => json_encode(['purpose'])]);

        Schema::shouldReceive('hasTable')->andReturn(false);
        Schema::shouldReceive('hasColumn')->andReturn(false);

        $this->runStep();

        $this->assertSame(['purpose'], json_decode(DB::table('identity_policies')->where('key', $key)->value('user_overrides'), true));
    }
}
