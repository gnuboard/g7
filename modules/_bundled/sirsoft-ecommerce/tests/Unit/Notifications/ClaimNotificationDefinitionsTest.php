<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Notifications;

use App\Extension\ModuleManager;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimStatusNotificationListener;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 알림 정의 계약 테스트
 *
 * 검증 축:
 * - 고객 가시 상태 전수에 **고객 정의가 하나씩** 있다(수는 리터럴이 아니라 매핑에서 센다 —
 *   상태가 늘면 기대값도 같이 늘어야 그물이 낡지 않는다)
 * - 정의의 `hooks` 집합과 `ClaimStatusNotificationListener` 의 매핑이 **양방향으로 덮인다**
 *   — 한쪽만 늘면 발화되는데 정의가 없거나(무음) 정의만 있고 아무도 발화하지 않는다(사문)
 * - 한 훅에 같은 수신자군(audience)의 정의가 둘이면 그 사람에게 두 통이 간다
 * - 채널·템플릿·치환 변수 계약(mail+database, ko/en, 본문에 쓴 변수는 variables 에 선언)
 * - **운영자 수신 정의는 이 상점의 운영 역할 셋을 전부 나열한다** — 하나라도 빠지면 그
 *   역할의 사람이 자기 일을 통보받지 못한다(이커머스 관리자 누락의 재발 방지 그물)
 */
class ClaimNotificationDefinitionsTest extends ModuleTestCase
{
    /**
     * 운영자 수신 알림이 나열해야 하는 역할.
     *
     * 모듈이 주문·클레임·문의 권한의 주인으로 선언한 역할과 같아야 한다.
     *
     * @var array<int, string>
     */
    private array $staffRoles = ['admin', 'manager', 'sirsoft-ecommerce.manager'];

    /**
     * 고객 가시 상태 전수에 고객 정의가 하나씩 있다.
     *
     * @return void 반환값 없음
     *
     * @effects twenty_nine_claim_notification_definitions_exist
     */
    public function test_every_customer_visible_status_has_exactly_one_customer_definition(): void
    {
        $expected = [];
        foreach (ClaimStatusNotificationListener::notifiableStatuses() as $claimType => $keys) {
            foreach ($keys as $key) {
                $expected[] = $claimType.'_'.$key;
            }
        }
        sort($expected);

        $actual = array_values(array_map(
            fn (array $definition) => $definition['type'],
            array_filter(
                $this->claimDefinitions(),
                fn (array $definition) => ($definition['audience'] ?? null) === 'customer'
                    // 부분 처리 안내·대리 철회 안내는 상태 1:1 이 아니라 사건 알림이다 — 아래 훅 대조가 덮는다.
                    && ! in_array($definition['type'], ['claim_partially_processed', 'claim_withdrawn_on_behalf'], true)
                    // 상태가 아닌 사건 알림(송장 등록·회수 지연)은 상태 1:1 대조 밖이다 — 아래 훅 대조가 덮는다.
                    && ! in_array($definition['type'], array_values(ClaimStatusNotificationListener::eventNotifyHooks()), true)
            )
        ));
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            '고객 가시 상태와 고객 알림 정의는 1:1 이어야 합니다 — 수를 리터럴로 적으면 상태가 늘 때 그물이 낡습니다.'
        );
    }

    /**
     * 발화되는 훅과 정의가 묶인 훅이 서로를 덮는다.
     *
     * @return void 반환값 없음
     *
     * @effects definition_hooks_match_listener_mapping_one_to_one
     */
    public function test_definition_hooks_and_listener_mapping_cover_each_other(): void
    {
        $definedHooks = [];
        foreach ($this->claimDefinitions() as $definition) {
            foreach ($definition['hooks'] as $hook) {
                $definedHooks[] = $hook;
            }
        }

        $mappedHooks = [
            ClaimStatusNotificationListener::WITHDRAWN_HOOK,
            ClaimStatusNotificationListener::WITHDRAWN_ON_BEHALF_HOOK,
            ClaimStatusNotificationListener::PARTIALLY_PROCESSED_HOOK,
            ...array_keys(ClaimStatusNotificationListener::eventNotifyHooks()),
        ];
        foreach (ClaimStatusNotificationListener::notifiableStatuses() as $claimType => $keys) {
            foreach ($keys as $status => $key) {
                $mappedHooks[] = ClaimStatusNotificationListener::notifyHookFor($claimType, $status);
            }
        }

        $definedUnique = array_values(array_unique($definedHooks));
        sort($definedUnique);
        sort($mappedHooks);

        $this->assertSame(
            $mappedHooks,
            $definedUnique,
            '발화되는 훅과 정의가 묶인 훅이 어긋나면, 발화되는데 정의가 없는 무음 상태 또는 아무도 발화하지 않는 사문 정의가 생깁니다.'
        );
    }

    /**
     * 한 훅에 같은 수신자군의 정의가 둘이면 그 사람에게 두 통이 간다.
     *
     * 고객 정의와 운영자 정의가 **같은 훅을 공유하는 것은 정상**이다 — 한 사건에 고객 1통 +
     * 운영자 1통이며, 관리자 본문과 고객 본문은 한 템플릿에 담기지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects one_definition_per_hook_and_audience
     */
    public function test_a_hook_has_at_most_one_definition_per_audience(): void
    {
        $seen = [];
        $duplicates = [];

        foreach ($this->claimDefinitions() as $definition) {
            $audience = $definition['audience'] ?? '(미선언)';
            foreach ($definition['hooks'] as $hook) {
                $key = $hook.'|'.$audience;
                if (isset($seen[$key])) {
                    $duplicates[] = "{$hook} ({$audience}): {$seen[$key]} + {$definition['type']}";
                }
                $seen[$key] = $definition['type'];
            }
        }

        $this->assertSame(
            [],
            $duplicates,
            "같은 훅·같은 수신자군에 정의가 둘이면 그 사람에게 두 통이 갑니다:\n - ".implode("\n - ", $duplicates)
        );
    }

    /**
     * 모든 클레임 알림 정의가 mail·database 채널과 ko·en 템플릿을 갖추는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects every_claim_definition_has_mail_and_database_templates_in_ko_and_en
     */
    public function test_every_claim_definition_has_both_channels_with_bilingual_templates(): void
    {
        foreach ($this->claimDefinitions() as $def) {
            $this->assertContains('mail', $def['channels'], "{$def['type']}: mail 채널 누락");
            $this->assertContains('database', $def['channels'], "{$def['type']}: database 채널 누락");

            $channels = array_column($def['templates'], 'channel');
            $this->assertContains('mail', $channels, "{$def['type']}: mail 템플릿 누락");
            $this->assertContains('database', $channels, "{$def['type']}: database 템플릿 누락");

            foreach ($def['templates'] as $template) {
                foreach (['subject', 'body'] as $field) {
                    $this->assertNotEmpty($template[$field]['ko'] ?? '', "{$def['type']}({$template['channel']}): {$field} ko 누락");
                    $this->assertNotEmpty($template[$field]['en'] ?? '', "{$def['type']}({$template['channel']}): {$field} en 누락");
                }
            }

            foreach (['ko', 'en'] as $locale) {
                $this->assertNotEmpty($def['name'][$locale] ?? '', "{$def['type']}: name {$locale} 누락");
                $this->assertNotEmpty($def['description'][$locale] ?? '', "{$def['type']}: description {$locale} 누락");
            }
        }
    }

    /**
     * 템플릿 본문에 쓴 치환 변수가 모두 variables 에 선언되어 있는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects every_template_placeholder_is_declared_as_a_variable
     */
    public function test_every_placeholder_used_in_a_template_is_declared_as_a_variable(): void
    {
        foreach ($this->claimDefinitions() as $def) {
            $declared = array_column($def['variables'], 'key');

            foreach ($def['templates'] as $template) {
                $text = implode(' ', [
                    $template['subject']['ko'], $template['subject']['en'],
                    $template['body']['ko'], $template['body']['en'],
                    $template['click_url'] ?? '',
                ]);

                preg_match_all('/\{([a-z_]+)\}/', $text, $matches);

                foreach (array_unique($matches[1]) as $placeholder) {
                    // `{recipient_name}` 은 코어가 수신자마다 치환하는 예약 자리다.
                    if ($placeholder === 'recipient_name') {
                        continue;
                    }

                    $this->assertContains(
                        $placeholder,
                        $declared,
                        "{$def['type']}: 본문이 쓰는 '{$placeholder}' 가 variables 에 선언되지 않아 운영자가 그 변수를 알 수 없습니다."
                    );
                }
            }
        }
    }

    /**
     * 수신자 규칙이 선언한 수신자군(`audience`)과 맞는다.
     *
     * 유형 이름으로 "이건 관리자 알림" 을 단정하지 않는다 — 그 가정이 결함과 같은 뿌리이고,
     * 관리자 수신 정의가 늘어도 그물에 걸리지 않게 만든다.
     *
     * @return void 반환값 없음
     *
     * @effects customer_notifications_target_trigger_user_and_withdrawal_targets_admins
     */
    public function test_recipient_rules_match_the_declared_audience(): void
    {
        foreach ($this->claimDefinitions() as $def) {
            $audience = $def['audience'] ?? null;
            $this->assertContains($audience, ['customer', 'staff'], "{$def['type']}: audience 미선언");

            $expected = $audience === 'staff' ? 'role' : 'trigger_user';

            foreach ($def['templates'] as $template) {
                $this->assertSame(
                    $expected,
                    $template['recipients'][0]['type'] ?? null,
                    "{$def['type']}({$template['channel']}): 수신자 규칙이 선언한 audience 와 어긋납니다."
                );
            }
        }
    }

    /**
     * 운영자 수신 정의는 이 상점의 운영 역할 셋을 전부 나열한다.
     *
     * `role:admin` 하나만 적히면 **모듈 설치 시 만들어지는 이커머스 관리자 역할이 빠진다** —
     * 그 사람은 클레임 권한을 다 가졌는데 접수도 철회도 통보받지 못한다. 이 단언이 그
     * 누락의 재발을 막는 그물이다.
     *
     * @return void 반환값 없음
     *
     * @effects staff_definitions_list_every_operational_role
     */
    public function test_staff_definitions_list_every_operational_role(): void
    {
        $missing = [];

        foreach ($this->claimDefinitions() as $def) {
            if (($def['audience'] ?? null) !== 'staff') {
                continue;
            }

            foreach ($def['templates'] as $template) {
                $roles = array_column(
                    array_filter($template['recipients'] ?? [], fn (array $rule) => ($rule['type'] ?? null) === 'role'),
                    'value'
                );

                foreach ($this->staffRoles as $role) {
                    if (! in_array($role, $roles, true)) {
                        $missing[] = "{$def['type']}({$template['channel']}): {$role}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "운영자 수신 알림에서 빠진 역할은 그 역할의 사람이 자기 일을 통보받지 못한다는 뜻입니다:\n - "
            .implode("\n - ", $missing)
        );
    }

    /**
     * 클레임 알림 정의만 골라 반환합니다.
     *
     * @return array<int, array<string, mixed>> 클레임 알림 정의
     */
    private function claimDefinitions(): array
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        $this->assertNotNull($module, '이커머스 모듈 로드 실패');

        $claimTypes = array_keys(ClaimStatusNotificationListener::notifiableStatuses());

        return array_values(array_filter(
            $module->getNotificationDefinitions(),
            function (array $def) use ($claimTypes) {
                if (str_starts_with($def['type'], 'claim_')) {
                    return true;
                }

                foreach ($claimTypes as $claimType) {
                    if (str_starts_with($def['type'], $claimType.'_')) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }
}
