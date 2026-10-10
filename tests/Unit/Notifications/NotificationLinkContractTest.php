<?php

namespace Tests\Unit\Notifications;

use Tests\Support\Concerns\CollectsNotificationDeclarations;
use Tests\TestCase;

/**
 * 알림 정의 전역 구조 계약 (1층)
 *
 * 손으로 정의마다 단언을 쓰면 새 정의가 늘 때 그물이 다시 비어 버립니다.
 * 그래서 **선언 전수를 순회**하며 구조 계약만 검사합니다 — 정의가 늘어도 자동으로 걸립니다.
 *
 * 검사 축:
 * 1. subject·body·click_url 이 쓰는 모든 `{placeholder}` 가 `variables` 에 선언돼 있다
 * 2. `database` 채널 템플릿은 클릭 대상(`click_url`)이 비어 있지 않다
 *    — 알림센터에서 누를 곳이 없는 알림이 되지 않게
 * 3. 모든 정의가 `audience`(staff/customer)를 선언하고 수신자 규칙과 정합이다
 * 4. 모든 `recipients[*].type` 이 알림 인프라의 인정 어휘 4종 안에 있다
 */
class NotificationLinkContractTest extends TestCase
{
    use CollectsNotificationDeclarations;

    /**
     * 인정 어휘 밖 수신자 타입을 쓰는 정의의 명시적 예외 목록.
     *
     * 비어 있어야 정상입니다. 예외를 더할 때는 **왜 어휘 밖인지**와 언제 닫는지를
     * 값에 적습니다 — 적지 않으면 다음 사람이 "원래 그런 것" 으로 읽습니다.
     *
     * @var array<string, string>
     */
    private array $recipientVocabularyExceptions = [];

    /**
     * 이 파일의 나머지 단언은 전부 「순회하며 위반을 모아 비었는지 본다」 형태다.
     * 그래서 수집기가 **빈 배열을 돌려주면 전부 조용히 초록**이 된다 — 그물이
     * 사라진 것과 통과한 것이 구분되지 않는다. 모집단 자체를 먼저 못박는다.
     *
     * 공급원 셋(코어 config · 모듈 · 플러그인)을 각각 세는 이유: 한 공급원의
     * 순회가 깨져도 총량은 남은 둘로 채워져 하한을 넘길 수 있기 때문이다.
     * 총량 하한은 현재값(코어 5 · 모듈 54 · 플러그인 1 = 60)보다 넉넉히 낮게 잡아
     * 정의가 줄어드는 정상 변경에는 걸리지 않고 붕괴에만 걸리게 한다.
     *
     * @effects notification_declaration_population_is_not_empty
     */
    public function test_the_declared_definition_population_is_not_empty(): void
    {
        $rows = $this->declaredNotificationDefinitions();

        $bySource = array_count_values(array_column($rows, 'extension_type'));

        $this->assertGreaterThan(0, $bySource['core'] ?? 0, '코어 알림 정의 수집이 비었다 — config("core.notification_definitions") 경로가 끊겼다.');
        $this->assertGreaterThan(0, $bySource['module'] ?? 0, '모듈 알림 정의 수집이 비었다 — ModuleManager 순회가 끊겼다.');
        $this->assertGreaterThan(0, $bySource['plugin'] ?? 0, '플러그인 알림 정의 수집이 비었다 — PluginManager 순회가 끊겼다.');

        $this->assertGreaterThanOrEqual(
            40,
            count($rows),
            '알림 정의 모집단이 40건 미만으로 떨어졌다 — 이 파일의 다른 계약들이 빈손으로 초록이 되고 있다.',
        );
    }

    /**
     * @effects every_notification_placeholder_is_declared_as_a_variable
     */
    public function test_every_placeholder_used_in_a_template_is_declared_as_a_variable(): void
    {
        $violations = [];

        foreach ($this->declaredNotificationDefinitions() as $row) {
            $definition = $row['definition'];
            $declared = array_column($definition['variables'] ?? [], 'key');
            $label = $this->notificationLabel($row);

            foreach ($definition['templates'] ?? [] as $template) {
                $text = $this->flattenTemplateText($template).' '.(string) ($template['click_url'] ?? '');

                foreach ($this->extractPlaceholders($text) as $placeholder) {
                    // `{recipient_name}` 은 코어가 수신자마다 채우는 예약 자리다.
                    if ($placeholder === 'recipient_name') {
                        continue;
                    }

                    if (! in_array($placeholder, $declared, true)) {
                        $violations[] = "{$label}({$template['channel']}): {{$placeholder}}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "본문·링크가 쓰는 변수가 variables 에 없으면 운영자가 그 변수의 존재를 알 수 없고,\n"
            ."치환되지 않은 `{변수}` 가 사람에게 그대로 갑니다:\n - ".implode("\n - ", $violations)
        );
    }

    /**
     * 변수 목록이 운영자에게 읽히는 모양인가 — 설명이 있고, 같은 키가 두 번 서지 않는가.
     *
     * 설명을 빠뜨리면 폴백이 **영문 키를 설명 자리에 그대로** 넣고, 같은 키를 두 곳에서
     * 선언하면 알림 설정 화면의 변수 도움말에 같은 줄이 두 번 뜬다. 둘 다 예외가 없어
     * 선언을 손보는 사람에게는 드러나지 않는다.
     *
     * @effects declared_variables_are_readable_and_unique
     */
    public function test_declared_variables_carry_a_description_and_appear_once(): void
    {
        $undescribed = [];
        $duplicated = [];

        foreach ($this->declaredNotificationDefinitions() as $row) {
            $label = $this->notificationLabel($row);
            $seen = [];

            foreach ($row['definition']['variables'] ?? [] as $variable) {
                $key = (string) ($variable['key'] ?? '');

                if ($key === '') {
                    continue;
                }

                $description = trim((string) ($variable['description'] ?? ''));

                // 폴백이 걸리면 설명이 키와 글자 그대로 같아진다.
                if ($description === '' || $description === $key) {
                    $undescribed[] = "{$label}: {$key}";
                }

                if (isset($seen[$key])) {
                    $duplicated[] = "{$label}: {$key}";
                }

                $seen[$key] = true;
            }
        }

        $this->assertSame(
            [],
            $undescribed,
            "변수에 설명이 없으면 알림 설정 화면의 도움말에 영문 키가 그대로 뜹니다:\n - "
            .implode("\n - ", $undescribed)
        );

        $this->assertSame(
            [],
            $duplicated,
            "같은 변수를 두 번 선언하면 운영자의 변수 목록에 같은 줄이 두 번 뜹니다:\n - "
            .implode("\n - ", $duplicated)
        );
    }

    /**
     * @effects database_templates_always_have_a_click_target
     */
    public function test_database_channel_templates_declare_a_click_target(): void
    {
        $missing = [];
        $malformed = [];

        foreach ($this->declaredNotificationDefinitions() as $row) {
            $label = $this->notificationLabel($row);

            foreach ($row['definition']['templates'] ?? [] as $template) {
                if (($template['channel'] ?? null) !== 'database') {
                    continue;
                }

                $clickUrl = trim((string) ($template['click_url'] ?? ''));

                if ($clickUrl === '') {
                    $missing[] = $label;

                    continue;
                }

                // 고정 경로(`/mypage` 등)는 그대로 둔다 — 수신자와 무관하게 한 곳으로 가는 알림이다.
                // 변수를 쓴다면 링크 하나로 해석돼야 하므로 정확히 한 개이고 `_url` 로 끝나야 한다.
                $placeholders = $this->extractPlaceholders($clickUrl);
                if ($placeholders === []) {
                    continue;
                }

                if (count($placeholders) !== 1 || ! str_ends_with($placeholders[0], '_url')) {
                    $malformed[] = "{$label} = {$clickUrl}";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "database 채널 알림에 click_url 이 없으면 알림센터에서 눌러도 아무 데도 가지 않습니다:\n - "
            .implode("\n - ", $missing)
        );

        $this->assertSame(
            [],
            $malformed,
            "click_url 의 변수는 링크 하나로 해석돼야 합니다(정확히 한 개 · `_url` 접미):\n - "
            .implode("\n - ", $malformed)
        );
    }

    /**
     * @effects every_definition_declares_an_audience_consistent_with_its_recipients
     */
    public function test_every_definition_declares_an_audience_that_matches_its_recipients(): void
    {
        $missing = [];
        $mismatched = [];

        foreach ($this->declaredNotificationDefinitions() as $row) {
            $definition = $row['definition'];
            $label = $this->notificationLabel($row);
            $audience = $definition['audience'] ?? null;

            if (! in_array($audience, ['staff', 'customer'], true)) {
                $missing[] = $label.' = '.var_export($audience, true);

                continue;
            }

            foreach ($definition['templates'] ?? [] as $template) {
                foreach ($template['recipients'] ?? [] as $recipient) {
                    // **역할 규칙은 언제나 운영자 집합이다.** 고객 수신 알림이 역할로 사람을
                    // 뽑으면 관리자 본문이 고객에게 가거나, 고객 본문이 운영자 전원에게 간다.
                    //
                    // 반대 방향(staff 가 어떤 타입을 써야 하는가)은 구조로 판정할 수 없다 —
                    // 운영자 수신 알림도 `trigger_user`(그 작업을 실행한 운영자)나
                    // `related_user`(그 게시판의 관리자)로 뽑는 것이 옳은 경우가 있다.
                    // 운영자 수신 알림의 역할 나열 계약은 그 확장의 정의 테스트가 본다.
                    if ($audience === 'customer' && ($recipient['type'] ?? null) === 'role') {
                        $mismatched[] = "{$label}({$template['channel']}): audience=customer 인데 수신자 타입이 role";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "audience 를 선언하지 않으면 운영자 수신 알림과 고객 수신 알림이 같은 그물에 걸리지 않습니다:\n - "
            .implode("\n - ", $missing)
        );

        $this->assertSame(
            [],
            $mismatched,
            "audience 와 수신자 규칙이 어긋나면 관리자 본문이 고객에게 가거나 그 반대가 됩니다:\n - "
            .implode("\n - ", $mismatched)
        );
    }

    /**
     * @effects every_recipient_type_is_within_the_recognized_vocabulary
     */
    public function test_every_recipient_type_is_within_the_recognized_vocabulary(): void
    {
        $violations = [];

        foreach ($this->declaredNotificationDefinitions() as $row) {
            $label = $this->notificationLabel($row);

            foreach ($row['definition']['templates'] ?? [] as $template) {
                foreach ($template['recipients'] ?? [] as $recipient) {
                    $type = $recipient['type'] ?? null;

                    if (in_array($type, $this->recognizedRecipientTypes, true)) {
                        continue;
                    }

                    if (isset($this->recipientVocabularyExceptions[$label])) {
                        continue;
                    }

                    $violations[] = "{$label}({$template['channel']}) => ".var_export($type, true);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            '인정 어휘('.implode(' · ', $this->recognizedRecipientTypes).") 밖의 수신자 타입은\n"
            ."관리자 화면의 수신자 드롭다운에 없어 **운영자가 그 템플릿을 저장조차 할 수 없습니다**:\n - "
            .implode("\n - ", $violations)
        );
    }
}
