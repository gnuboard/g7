<?php

namespace Tests\Unit\LanguagePack;

use Tests\TestCase;

/**
 * ja 언어팩의 휴면 용어 단일성 (이슈 #636 S4, 2026-09-22)
 *
 * ko 는 「휴면」, en 은 「dormant」 한 낱말로 일관된데 ja 만 「休眠」과 「休止」로 갈려 있었다.
 * 같은 팩(`g7-core-ja`) 안에서도 로그인 차단 문구는 「休眠状態のアカウント」인데 상태 라벨은
 * 「休止」였다. ja 회원은 메일로 「休眠アカウントに転換されました」를 받고, 로그인하면 토스트가
 * 「休止状態が解除されました」라고 하고, 프로필에는 「休止中」이 찍힌다 — 자동 탈퇴 예고까지
 * 받는 회원이 **자기가 받은 법정 고지와 화면의 표시를 같은 것으로 읽지 못한다.**
 *
 * 그래서 모집단을 손으로 적지 않고 **ko 원본에서 파생**한다 — ko 값에 「휴면」이 들어간 자리
 * 전량을 찾아 그 짝이 되는 ja 값이 정본 낱말을 쓰는지 본다. 키를 하나 더 만들어도, 팩이
 * 하나 늘어도 이 검사가 함께 넓어진다. 모집단이 비거나 좁아지면 하한에서 red 가 된다.
 *
 * 설계 확정(2026-09-22): 정본은 **「休眠」**. 일본에서 장기 미접속 계정을 가리키는 표준어이고,
 * 법정 사전 통지문(`lang-packs/_bundled/g7-core-ja/seed/notifications.json`)과 로그인 차단 문구가 이미 그 낱말이다.
 */
class JaDormancyTermConsistencyTest extends TestCase
{
    /** ja 정본 낱말 — 이 문자열이 없으면 red */
    private const CANONICAL_JA = '休眠';

    /** ko 원본에서 모집단을 고르는 낱말 */
    private const SOURCE_KO = '휴면';

    /**
     * 모집단 공동화 방지 하한.
     *
     * 파생이 좁아져 0건이 되면 `assertSame` 류는 조용히 통과한다. 현재 실측 모집단보다
     * 낮게 잡되, 한두 키가 사라진 정도로는 걸리지 않을 만큼만 낮춘다.
     */
    private const POPULATION_FLOOR = 40;

    /**
     * ko 값에 「휴면」이 든 자리와 그 ja 짝을 저장소에서 전수 파생합니다.
     *
     * 세 갈래를 합집합으로 본다 — 하나만 보면 나머지 갈래에서 낱말이 갈려도 초록이다.
     *   ① 코어 backend lang (`lang/ko/*.php` ↔ `g7-core-ja/backend/ja/*.php`)
     *   ② 템플릿 frontend partial (`templates/_bundled/{t}/lang/partial/ko/*.json`
     *      ↔ `g7-template-{t}-ja/frontend/partial/*.json`)
     *   ③ 코어 알림 정의 시드 (`config('core.notification_definitions')` 의 ko
     *      ↔ `lang-packs/_bundled/g7-core-ja/seed/notifications.json`)
     *
     * @return array<string, array{ko: string, ja: string}> 좌표 => ko·ja 값
     */
    private function dormancyPairs(): array
    {
        return $this->backendPairs() + $this->templatePairs() + $this->seedPairs();
    }

    /**
     * ① 코어 backend lang 파일 짝.
     *
     * @return array<string, array{ko: string, ja: string}> 좌표 => ko·ja 값
     */
    private function backendPairs(): array
    {
        $pairs = [];

        foreach (glob(base_path('lang-packs/_bundled/g7-core-ja/backend/ja/*.php')) ?: [] as $jaPath) {
            $koPath = base_path('lang/ko/'.basename($jaPath));

            if (! is_file($koPath)) {
                continue;
            }

            $this->collect($pairs, 'core:'.basename($jaPath, '.php'), require $koPath, require $jaPath);
        }

        return $pairs;
    }

    /**
     * ② 템플릿 frontend partial 짝.
     *
     * @return array<string, array{ko: string, ja: string}> 좌표 => ko·ja 값
     */
    private function templatePairs(): array
    {
        $pairs = [];

        $packGlob = 'lang-packs/_bundled/g7-template-*-ja/frontend/partial/*.json';

        foreach (glob(base_path($packGlob)) ?: [] as $jaPath) {
            $pack = basename(dirname(dirname(dirname($jaPath))));
            // g7-template-{slug}-ja → {slug}
            $slug = preg_replace('/^g7-template-(.*)-ja$/', '$1', $pack);
            $koPath = base_path('templates/_bundled/'.$slug.'/lang/partial/ko/'.basename($jaPath));

            if ($slug === null || ! is_file($koPath)) {
                continue;
            }

            $ko = json_decode((string) file_get_contents($koPath), true);
            $ja = json_decode((string) file_get_contents($jaPath), true);

            if (! is_array($ko) || ! is_array($ja)) {
                continue;
            }

            $this->collect($pairs, $pack.':'.basename($jaPath, '.json'), $ko, $ja);
        }

        return $pairs;
    }

    /**
     * ③ 코어 알림 정의 시드 짝.
     *
     * ja 시드는 `{type}.definition.{name,description}` · `{type}.templates.{channel}.{subject,body}`
     * 모양이므로 ko 쪽을 같은 좌표로 펴서 맞춘다.
     *
     * @return array<string, array{ko: string, ja: string}> 좌표 => ko·ja 값
     */
    private function seedPairs(): array
    {
        $seedPath = base_path('lang-packs/_bundled/g7-core-ja/seed/notifications.json');

        if (! is_file($seedPath)) {
            return [];
        }

        $ja = json_decode((string) file_get_contents($seedPath), true);
        $definitions = config('core.notification_definitions');

        if (! is_array($ja) || ! is_array($definitions)) {
            return [];
        }

        $ko = [];

        foreach ($definitions as $type => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            foreach (['name', 'description'] as $field) {
                if (isset($definition[$field]['ko'])) {
                    $ko[$type]['definition'][$field] = $definition[$field]['ko'];
                }
            }

            foreach ($definition['templates'] ?? [] as $template) {
                $channel = $template['channel'] ?? null;

                if (! is_string($channel)) {
                    continue;
                }

                foreach (['subject', 'body'] as $field) {
                    if (isset($template[$field]['ko'])) {
                        $ko[$type]['templates'][$channel][$field] = $template[$field]['ko'];
                    }
                }
            }
        }

        $pairs = [];
        $this->collect($pairs, 'core:seed_notifications', $ko, $ja);

        return $pairs;
    }

    /**
     * 두 배열을 같은 점 표기 좌표로 펴서 ko 에 「휴면」이 든 자리만 모읍니다.
     *
     * @param  array<string, array{ko: string, ja: string}>  $pairs  누적 대상 (참조)
     * @param  string  $channel  채널 이름 (좌표 접두사)
     * @param  mixed  $ko  ko 원본 배열
     * @param  mixed  $ja  ja 번역 배열
     * @return void 반환값 없음 — `$pairs` 에 직접 쌓는다
     */
    private function collect(array &$pairs, string $channel, mixed $ko, mixed $ja): void
    {
        if (! is_array($ko) || ! is_array($ja)) {
            return;
        }

        $koFlat = $this->flatten($ko);
        $jaFlat = $this->flatten($ja);

        foreach ($koFlat as $key => $value) {
            if (! str_contains($value, self::SOURCE_KO) || ! isset($jaFlat[$key])) {
                continue;
            }

            $pairs[$channel.' '.$key] = ['ko' => $value, 'ja' => $jaFlat[$key]];
        }
    }

    /**
     * 중첩 배열을 점 표기 문자열 맵으로 폅니다.
     *
     * @param  array<array-key, mixed>  $values  대상 배열
     * @param  string  $prefix  현재 좌표 접두사
     * @return array<string, string> 점 표기 좌표 => 문자열 값
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            if (is_string($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /**
     * ko 가 「휴면」이라고 쓴 자리는 ja 도 한 낱말(「休眠」)로 쓴다
     *
     * @scenario locale=ja, surface=dormancy_term
     *
     * @effects ja_pack_uses_one_word_for_dormancy
     *
     * @return void 반환값 없음
     */
    public function test_ja_pack_uses_a_single_word_for_dormancy(): void
    {
        $pairs = $this->dormancyPairs();

        $this->assertGreaterThanOrEqual(
            self::POPULATION_FLOOR,
            count($pairs),
            'ko 에서 파생한 휴면 자리가 '.count($pairs).'건뿐입니다 — 파생이 좁아졌거나 짝 파일을 못 찾고 있습니다. '
                .'모집단이 비면 아래 단언은 아무것도 재지 않습니다.',
        );

        $offenders = [];

        foreach ($pairs as $coordinate => $pair) {
            if (! str_contains($pair['ja'], self::CANONICAL_JA)) {
                $offenders[$coordinate] = $pair['ja'];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ja 번역이 정본 낱말 「'.self::CANONICAL_JA.'」을 쓰지 않는 자리가 있습니다. '
                .'ja 회원이 받는 고지와 화면 표시가 서로 다른 낱말이 됩니다 — '
                .print_r($offenders, true),
        );
    }
}
