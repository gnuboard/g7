<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Upgrades\Data\V7_1_0\Migrations\BackfillNotificationDefinitionRelations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 7.1.0 업그레이드 — 알림 정의 `relations` 백필과 미백필 정의의 저장 호환 (#599 validate).
 *
 * 7.1.0 은 알림 템플릿의 「관련 사용자」 수신자(`related_user.relation`)를 정의가 선언한 키로만
 * 받는다. 그런데 `relations` 컬럼은 추가만 하고 기존 정의는 채우지 않아, 확장이 다시 동기화되기
 * 전까지(제3자 확장은 그 확장이 업데이트될 때까지) relations 가 NULL 이었다. 그동안 기존
 * related_user 규칙이 든 템플릿을 저장하면 `Rule::in([])` 로 422 가 났고 화면은 그 타입을 감췄다.
 *
 * 막는 층: ① 업그레이드 스텝이 템플릿이 실제로 쓰는 relation 키로 NULL 을 채운다(선언 경로와 같은 규칙)
 * ② 그래도 NULL 인 정의(스텝 이후 설치된 확장 등)는 저장 검증을 막지 않는다 — NULL 은 "선언 전", [] 는
 * "선언했는데 없음" 으로 가른다.
 */
class Upgrade710NotificationRelationsBackfillTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 업그레이드 데이터 파일을 불러옵니다(autoload 대상이 아니다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.1.0/migrations/01_BackfillNotificationDefinitionRelations.php');
    }

    /**
     * 정의와 템플릿 한 벌을 만듭니다.
     *
     * @param  array|null  $relations  정의의 relations 값
     * @param  array  $recipients  템플릿 수신자 규칙
     * @return NotificationTemplate 템플릿
     */
    private function templateWith(?array $relations, array $recipients): NotificationTemplate
    {
        $definition = NotificationDefinition::factory()->create(['relations' => $relations]);

        return NotificationTemplate::query()->create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '제목'],
            'body' => ['ko' => '본문'],
            'recipients' => $recipients,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * 알림 템플릿 수정 권한을 가진 관리자 토큰을 발급합니다.
     *
     * @return string Bearer 토큰
     */
    private function adminToken(): string
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => ['ko' => '알림 관리자'], 'identifier' => 'notification_admin_'.uniqid(), 'is_admin' => true]);

        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permission = Permission::firstOrCreate(['identifier' => $identifier], ['name' => ['ko' => $identifier], 'type' => 'admin', 'order' => 1]);
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);

        return $user->createToken('test')->plainTextToken;
    }

    /**
     * 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new BackfillNotificationDefinitionRelations)->run(new UpgradeContext(
            fromVersion: '7.0.11',
            toVersion: '7.1.0',
            currentStep: '7.1.0',
        ));
    }

    /**
     * relations 가 NULL 인 정의를 템플릿이 쓰는 relation 키로 채우고, 이미 선언된 정의는 건드리지 않는다.
     *
     * @scenario definition_state=null, entry=upgrade_step
     *
     * @effects null_relations_are_backfilled_from_template_rules, declared_relations_are_left_untouched
     *
     * @return void 반환값 없음
     */
    public function test_null_relations_are_backfilled_from_the_template_rules(): void
    {
        $legacy = $this->templateWith(null, [
            ['type' => 'related_user', 'relation' => 'post_author'],
            ['type' => 'role', 'value' => ['admin']],
            ['type' => 'related_user', 'relation' => 'post_author'],
            ['type' => 'related_user', 'relation' => 'comment_author'],
        ]);
        $declared = $this->templateWith(['order_owner'], [['type' => 'related_user', 'relation' => 'post_author']]);

        $this->runStep();
        $this->runStep();

        $this->assertSame(['post_author', 'comment_author'], $legacy->definition->fresh()->relations);
        $this->assertSame(['order_owner'], $declared->definition->fresh()->relations, '이미 선언된 relations 를 덮으면 안 된다');
    }

    /**
     * related_user 규칙이 하나도 없는 NULL 정의는 빈 배열로 채운다 — "선언 전" 에서 "없음" 으로.
     *
     * @scenario definition_state=null, entry=upgrade_step
     *
     * @effects null_relations_are_backfilled_from_template_rules
     *
     * @return void 반환값 없음
     */
    public function test_a_definition_without_related_user_rules_becomes_an_empty_list(): void
    {
        $template = $this->templateWith(null, [['type' => 'role', 'value' => ['admin']]]);

        $this->runStep();

        $this->assertSame([], $template->definition->fresh()->relations);
    }

    /**
     * 아직 relations 가 NULL 인 정의의 related_user 규칙은 저장이 막히지 않는다(선언 전 호환).
     *
     * @scenario definition_state=null, entry=template_save
     *
     * @effects undeclared_definition_does_not_block_related_user_save
     *
     * @return void 반환값 없음
     */
    public function test_saving_a_related_user_rule_is_not_blocked_while_relations_are_null(): void
    {
        $template = $this->templateWith(null, [['type' => 'related_user', 'relation' => 'post_author']]);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->putJson("/api/admin/notification-templates/{$template->id}", [
            'subject' => ['ko' => '제목'],
            'body' => ['ko' => '본문'],
            'recipients' => [['type' => 'related_user', 'relation' => 'post_author']],
        ])->assertJsonMissingValidationErrors(['recipients.0.relation']);
    }

    /**
     * 선언된 relations 가 있는 정의는 선언 밖 키를 계속 거절한다(비회귀).
     *
     * @scenario definition_state=declared, entry=template_save
     *
     * @effects declared_relations_still_reject_unknown_keys
     *
     * @return void 반환값 없음
     */
    public function test_declared_relations_still_reject_an_unknown_key(): void
    {
        $template = $this->templateWith(['order_owner'], [['type' => 'related_user', 'relation' => 'order_owner']]);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->putJson("/api/admin/notification-templates/{$template->id}", [
            'subject' => ['ko' => '제목'],
            'body' => ['ko' => '본문'],
            'recipients' => [['type' => 'related_user', 'relation' => 'post_author']],
        ])->assertStatus(422)->assertJsonValidationErrors(['recipients.0.relation']);
    }
}
