<?php

namespace Tests\Unit\LanguagePack;

use Illuminate\Support\Str;
use Plugins\Sirsoft\MessageBizppurio\Listeners\SeedChannelTemplatesListener;
use Tests\TestCase;

/**
 * 문자 플러그인이 덧붙이는 sms·alimtalk 채널에도 ja 시드 번역이 있는지 검증 (7.1.0 출시 리허설 결함)
 *
 * sms·alimtalk 템플릿은 원본 알림 정의에 없고, 문자 플러그인이 시딩 직전 mail·database 본문을 복제해 덧붙인다.
 * 업데이트·설치 때 모듈 동기화가 언어팩보다 먼저 돌면 그 복제본은 ko·en 만 갖고 만들어지고, 언어팩을 켤 때의 번역
 * 병합은 **시드에 적힌 채널의 행만** 고친다. 그래서 시드에 sms·alimtalk 가 없으면 일본어 사이트의 알림 설정에서
 * 문자·알림톡 제목·본문이 일본어 없이 남았다(결제 본인 확인 `identity.purpose.checkout_verification` 실측).
 *
 * 모집단은 `lang-packs/_bundled/*-ja/seed/notifications.json` 전량과 각 원본 정의에서 파생한다. 어떤 정의가 증강되는지,
 * 증강 본문이 무엇인지는 문자 플러그인의 증강기({@see SeedChannelTemplatesListener::augment()})를 그대로 돌려 정한다 —
 * 규칙이 바뀌면 이 기대값도 함께 바뀐다.
 */
class JaNotificationSeedAugmentedChannelsTest extends TestCase
{
    /**
     * 모집단 공동화 방지 하한(비교한 sms·alimtalk 제목·본문 칸 수).
     *
     * 현재 실측: 증강 대상 79정의 × 2채널 × 제목·본문.
     */
    private const POPULATION_FLOOR = 250;

    /**
     * 증강 대상 정의마다 ja 시드에 sms·alimtalk 가 있고, 값이 증강기가 ja 본문으로 만든 값과 같다.
     *
     * @scenario locale=ja, surface=notification_seed
     *
     * @effects ja_notification_seed_covers_augmented_channels
     *
     * @return void 없음
     */
    public function test_ja_seed_has_translations_for_channels_the_sms_plugin_augments(): void
    {
        $augmenter = $this->augmenter();
        $compared = 0;
        $problems = [];

        foreach (glob(base_path('lang-packs/_bundled/*-ja/seed/notifications.json')) ?: [] as $seedPath) {
            $packId = basename(dirname($seedPath, 2));
            $seed = json_decode((string) file_get_contents($seedPath), true);
            $definitions = $this->sourceDefinitions($packId);

            $this->assertNotNull($definitions, "{$packId}: 원본 알림 정의를 찾지 못했습니다");
            $this->assertIsArray($seed, "{$packId}: 시드 JSON 해석 실패");

            foreach ($definitions as $index => $definition) {
                $type = is_string($index) && $index !== '' ? $index : ($definition['type'] ?? null);
                if (! is_string($type) || ! isset($seed[$type]['templates']) || ! is_array($definition)) {
                    continue;
                }

                $augmentedSource = $augmenter->augment([$definition])[0];
                $augmentedChannels = array_diff(
                    array_column($augmentedSource['templates'] ?? [], 'channel'),
                    array_column($definition['templates'] ?? [], 'channel'),
                );
                if ($augmentedChannels === []) {
                    continue; // 증강 대상이 아니다(관리자 전용 알림 등)
                }

                $expected = $this->augmentJapanese($augmenter, $definition, $seed[$type]['templates']);

                foreach ($augmentedChannels as $channel) {
                    $actual = $seed[$type]['templates'][$channel] ?? null;
                    if (! is_array($actual)) {
                        $problems[] = "{$packId} {$type}.{$channel} — ja 시드에 없음";

                        continue;
                    }

                    foreach (['subject', 'body'] as $field) {
                        $want = $expected[$channel][$field] ?? null;
                        if ($want === null) {
                            continue;
                        }
                        $compared++;
                        if (($actual[$field] ?? null) !== $want) {
                            $problems[] = "{$packId} {$type}.{$channel}.{$field} — 증강기가 ja 본문으로 만든 값과 다름";
                        }
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(self::POPULATION_FLOOR, $compared, '비교한 칸이 너무 적습니다 — 모집단 파생이 좁아졌습니다');
        $this->assertSame([], $problems, "ja 시드의 sms·alimtalk 번역이 비었거나 어긋납니다:\n".implode("\n", $problems));
    }

    /**
     * ja 시드의 mail·database 본문만 가진 정의를 증강기에 넣어 sms·alimtalk 의 ja 제목·본문을 구합니다.
     *
     * @param  SeedChannelTemplatesListener  $augmenter  문자 플러그인 증강기
     * @param  array<string, mixed>  $definition  원본 정의 (수신자 규칙을 그대로 쓴다)
     * @param  array<string, array<string, string>>  $jaTemplates  ja 시드의 채널별 제목·본문
     * @return array<string, array<string, string>> 채널 → [subject, body] (ja)
     */
    private function augmentJapanese(SeedChannelTemplatesListener $augmenter, array $definition, array $jaTemplates): array
    {
        $templates = [];
        foreach ($definition['templates'] ?? [] as $template) {
            $channel = $template['channel'] ?? null;
            if (! is_string($channel) || ! isset($jaTemplates[$channel])) {
                continue;
            }
            $templates[] = [
                'channel' => $channel,
                'recipients' => $template['recipients'] ?? [],
                'subject' => isset($jaTemplates[$channel]['subject']) ? ['ja' => $jaTemplates[$channel]['subject']] : null,
                'body' => isset($jaTemplates[$channel]['body']) ? ['ja' => $jaTemplates[$channel]['body']] : null,
            ];
        }

        $augmented = $augmenter->augment([['channels' => $definition['channels'] ?? ['mail'], 'templates' => $templates]])[0];

        $result = [];
        foreach ($augmented['templates'] ?? [] as $template) {
            $result[$template['channel']] = [
                'subject' => $template['subject']['ja'] ?? null,
                'body' => $template['body']['ja'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * 문자 플러그인 증강기를 만듭니다(번들 소스에서 읽는다).
     *
     * @return SeedChannelTemplatesListener 증강기
     */
    private function augmenter(): SeedChannelTemplatesListener
    {
        if (! class_exists(SeedChannelTemplatesListener::class)) {
            require_once base_path('plugins/_bundled/sirsoft-message_bizppurio/src/Listeners/SeedChannelTemplatesListener.php');
        }

        return new SeedChannelTemplatesListener;
    }

    /**
     * 언어팩 식별자에서 원본 알림 정의를 구합니다.
     *
     * @param  string  $packId  언어팩 식별자 (g7-core-ja / g7-module-{id}-ja / g7-plugin-{id}-ja)
     * @return array<int|string, array<string, mixed>>|null 원본 알림 정의 목록, 찾지 못하면 null
     */
    private function sourceDefinitions(string $packId): ?array
    {
        if ($packId === 'g7-core-ja') {
            return (array) config('core.notification_definitions', []);
        }

        if (! preg_match('/^g7-(module|plugin)-([a-z0-9]+)-(.+)-ja$/', $packId, $matches)) {
            return null;
        }

        [, $scope, $vendor, $name] = $matches;
        $class = ($scope === 'module' ? 'Modules\\' : 'Plugins\\')
            .Str::studly($vendor).'\\'.Str::studly($name).'\\'
            .($scope === 'module' ? 'Module' : 'Plugin');

        if (! class_exists($class)) {
            return null;
        }

        return (new $class)->getNotificationDefinitions();
    }
}
