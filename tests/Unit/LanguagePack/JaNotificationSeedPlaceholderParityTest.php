<?php

namespace Tests\Unit\LanguagePack;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ja 알림 시드의 자리표시자가 원본(ko) 알림 정의와 같은지 검증 (#703)
 *
 * 언어팩 동기화 검사(check-language-pack-sync)는 키 집합만 비교해, 원본 본문의 자리표시자가 바뀐 뒤 ja 시드
 * 본문이 옛 자리표시자를 그대로 들고 있어도 통과한다. GDPR 연간 통지 메일이 그랬다 — ko·en 은 처리방침 주소가
 * 비면 링크 줄을 생략하는 `{privacy_policy_link}` 로 바뀌었는데 ja 시드만 `<a href="{privacy_policy_url}">`
 * 를 남겨, 주소를 비워 둔 사이트의 일본어 회원은 주소 없는 링크를 받았다.
 *
 * 모집단은 손으로 적지 않고 `lang-packs/_bundled/*-ja/seed/notifications.json` 전량에서 파생한다 — 팩·정의·채널이
 * 늘어도 이 검사가 함께 넓어진다. 짝이 되는 원본은 코어는 `core.notification_definitions`, 확장은 그 확장의
 * `getNotificationDefinitions()` 다(시드 주입기와 같은 규칙으로 정의 type ↔ 시드 키, 채널 ↔ templates 키).
 */
class JaNotificationSeedPlaceholderParityTest extends TestCase
{
    /**
     * 모집단 공동화 방지 하한(비교한 제목·본문 칸 수).
     *
     * 파생이 비거나 좁아지면 비교 없이 초록이 된다. 현재 실측 모집단(6팩)보다 낮게 잡는다.
     */
    private const POPULATION_FLOOR = 40;

    /**
     * ja 시드의 제목·본문마다 자리표시자 집합이 원본 ko 와 같다.
     *
     * @scenario locale=ja, surface=notification_seed
     *
     * @effects ja_notification_seed_placeholders_follow_source
     *
     * @return void 없음
     */
    public function test_ja_notification_seed_placeholders_match_source_definitions(): void
    {
        $compared = 0;
        $mismatches = [];

        foreach (glob(base_path('lang-packs/_bundled/*-ja/seed/notifications.json')) ?: [] as $seedPath) {
            $packId = basename(dirname($seedPath, 2));
            $seed = json_decode((string) file_get_contents($seedPath), true);
            $definitions = $this->sourceDefinitions($packId);

            $this->assertNotNull($definitions, "{$packId}: 원본 알림 정의를 찾지 못했습니다");
            $this->assertIsArray($seed, "{$packId}: 시드 JSON 해석 실패");

            foreach ($definitions as $index => $definition) {
                $type = is_string($index) && $index !== '' ? $index : ($definition['type'] ?? null);
                if (! is_string($type) || ! isset($seed[$type]['templates'])) {
                    continue;
                }

                foreach ($definition['templates'] ?? [] as $template) {
                    $channel = $template['channel'] ?? null;
                    $jaTemplate = $seed[$type]['templates'][$channel] ?? null;
                    if (! is_string($channel) || ! is_array($jaTemplate)) {
                        continue;
                    }

                    foreach (['subject', 'body'] as $field) {
                        $ko = $template[$field]['ko'] ?? null;
                        $ja = $jaTemplate[$field] ?? null;
                        if (! is_string($ko) || ! is_string($ja)) {
                            continue;
                        }

                        $compared++;
                        $koPlaceholders = $this->placeholders($ko);
                        $jaPlaceholders = $this->placeholders($ja);

                        if ($koPlaceholders !== $jaPlaceholders) {
                            $mismatches[] = sprintf(
                                '%s %s.%s.%s — ko [%s] / ja [%s]',
                                $packId,
                                $type,
                                $channel,
                                $field,
                                implode(', ', $koPlaceholders),
                                implode(', ', $jaPlaceholders)
                            );
                        }
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(self::POPULATION_FLOOR, $compared, '비교한 제목·본문 칸이 너무 적습니다 — 모집단 파생이 좁아졌습니다');
        $this->assertSame([], $mismatches, "ja 시드 자리표시자가 원본과 다릅니다:\n".implode("\n", $mismatches));
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

    /**
     * 문자열의 `{이름}` 자리표시자를 정렬된 고유 목록으로 뽑습니다.
     *
     * @param  string  $text  제목 또는 본문
     * @return array<int, string> 자리표시자 이름 목록
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/\{([a-z_][a-z0-9_]*)\}/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
