<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Notifications;

use App\Extension\ModuleManager;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimStatusNotificationListener;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 알림 정의 29종 계약 테스트 (S7 / §4-8)
 *
 * 검증 축:
 * - 고객 가시 상태 전수 29종이 정의로 존재한다(유형별 13/10/5 + 철회 관리자 1)
 * - 정의의 `hooks` 집합과 `ClaimStatusNotificationListener` 의 매핑이 **양방향 1:1** 이다
 *   — 한쪽만 늘면 발화되는데 정의가 없거나(무음) 정의만 있고 아무도 발화하지 않는다(사문)
 * - 채널·템플릿·치환 변수 계약(mail+database, ko/en, 본문에 쓴 변수는 variables 에 선언)
 */
class ClaimNotificationDefinitionsTest extends ModuleTestCase
{
    /**
     * 유형별 기대 알림 수 (§4-8 — 교환 13 / 반품 10 / 환불 5).
     *
     * @var array<string, int>
     */
    private array $expectedCounts = [
        'exchange' => 13,
        'return' => 10,
        'refund' => 5,
    ];

    /**
     * @effects twenty_nine_claim_notification_definitions_exist
     */
    public function test_module_declares_twenty_nine_claim_notification_definitions(): void
    {
        $claimDefinitions = $this->claimDefinitions();

        $this->assertCount(29, $claimDefinitions, '§4-8 이 정한 고객 가시 상태 전수는 29종입니다.');

        foreach ($this->expectedCounts as $claimType => $expected) {
            $ofType = array_filter(
                $claimDefinitions,
                fn (array $def) => str_starts_with($def['type'], $claimType.'_')
            );

            $this->assertCount($expected, $ofType, "{$claimType} 알림 정의 수가 어긋납니다.");
        }

        $types = array_column($claimDefinitions, 'type');
        $this->assertContains('claim_withdrawn', $types, '철회(관리자 수신) 알림 정의가 없습니다.');
    }

    /**
     * @effects definition_hooks_match_listener_mapping_one_to_one
     */
    public function test_definition_hooks_and_listener_mapping_are_one_to_one(): void
    {
        $definedHooks = [];
        foreach ($this->claimDefinitions() as $def) {
            foreach ($def['hooks'] as $hook) {
                $definedHooks[] = $hook;
            }
        }

        $mappedHooks = [ClaimStatusNotificationListener::WITHDRAWN_HOOK];
        foreach (ClaimStatusNotificationListener::notifiableStatuses() as $claimType => $keys) {
            foreach ($keys as $status => $key) {
                $mappedHooks[] = ClaimStatusNotificationListener::notifyHookFor($claimType, $status);
            }
        }

        sort($definedHooks);
        sort($mappedHooks);

        $this->assertSame(
            $mappedHooks,
            $definedHooks,
            '발화되는 훅과 정의가 묶인 훅이 어긋나면, 발화되는데 정의가 없는 무음 상태 또는 아무도 발화하지 않는 사문 정의가 생깁니다.'
        );
        $this->assertSame(
            count($definedHooks),
            count(array_unique($definedHooks)),
            '한 훅에 정의가 둘이면 그 상태에서 알림이 두 통 나갑니다.'
        );
    }

    /**
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
     * @effects customer_notifications_target_trigger_user_and_withdrawal_targets_admins
     */
    public function test_customer_notifications_go_to_the_requester_and_withdrawal_goes_to_admins(): void
    {
        foreach ($this->claimDefinitions() as $def) {
            $expected = $def['type'] === 'claim_withdrawn' ? 'role' : 'trigger_user';

            foreach ($def['templates'] as $template) {
                $this->assertSame(
                    $expected,
                    $template['recipients'][0]['type'] ?? null,
                    "{$def['type']}({$template['channel']}): 수신자 규칙이 어긋납니다."
                );
            }
        }
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

        $claimTypes = array_keys($this->expectedCounts);

        return array_values(array_filter(
            $module->getNotificationDefinitions(),
            function (array $def) use ($claimTypes) {
                if ($def['type'] === 'claim_withdrawn') {
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
