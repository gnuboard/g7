<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CLI 와 웹 화면이 **함께 쓰는** 코어 업데이트 문구는 한쪽 전용 조작을 지시하지 않는다.
 *
 * 이 안의 존재 이유가 「명령줄을 쓸 수 없는 호스팅」이라, 그 운영자가 보는 화면이
 * 명령줄 옵션을 따르라고 말하면 그 자리에서 길이 끊긴다. 실제로 압축 해제 실패 안내가
 * 「`--source` 옵션으로 수동 업데이트를 시도하세요」였고, 그 문구는 관리자 화면의
 * 「직접 올린 ZIP 으로 업데이트」를 이미 쓰고 있는 사람에게 그대로 나갔다
 * (G8 실측 2026-09-02 · 계획서 §9.11.3 F7).
 *
 * 이 검사는 **오류가 아니라 안내의 수신자**를 잰다 — 문구는 문법적으로 멀쩡했고
 * 어떤 테스트도 red 가 되지 않았다.
 *
 * 커버 축: core_update_web message_channel · locale ko·en·ja
 */
class CoreUpdateSharedMessageChannelTest extends TestCase
{
    /**
     * 명령줄에서만 성립하는 지시의 표지.
     *
     * 옵션 표기(`--xxx`)와 실행 지시(`php artisan …`) 둘 다 본다 — 앞의 것만 막으면
     * 「서버에 접속해 `php artisan core:update` 를 실행하세요」가 그대로 통과한다.
     */
    private const CLI_ONLY_MARKERS = ['--', 'php artisan'];

    /**
     * 명령줄 지시를 담아도 되는 키와 그 사유.
     *
     * 표에 등재됐는데 정작 어느 로케일에도 그 지시가 없으면 이 검사가 실패한다 —
     * 낡은 면제가 남아 있으면 나중에 그 자리에 명령줄 지시가 다시 들어와도 조용히
     * 통과한다.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        // 코어 업데이트 실패 흐름이 아니라 스토리지 잔여물 정리 안내다. 2026-09-02 PO 결정으로
        // 이번 라운드의 범위는 코어 업데이트 화면 두 곳이며, 이 키는 그 밖이다.
        'storage_link_backup_accumulated' => '스토리지 정리 안내 — 코어 업데이트 실패 흐름 밖 (PO 범위 결정 2026-09-02)',
    ];

    /**
     * CLI 와 웹이 공유하는 문구 키를 **디스크에서 파생**합니다.
     *
     * 「공유 여부」를 호출부의 성질로 가르는 것은 성립하지 않지만(둘 다 같은 서비스를
     * 부른다), 그 뒤집은 형태는 파생된다 — **`app/Console/` 밖에서 참조되는 키는
     * 웹에서도 나간다**. 콘솔 전용 절차 안내(`manual_update_guide`)가 그 경계 덕에
     * 자연히 빠지고, 같은 함수의 형제 키는 자동으로 들어온다.
     *
     * 앞 판본은 이 목록이 손으로 적힌 **1개**여서, 같은 메서드에서 던져지는 형제 키
     * (`no_extract_method_available`)가 명령줄 옵션을 지시하는데도 초록이었다.
     *
     * @return array<string, array{0: string}>
     */
    public static function sharedKeys(): array
    {
        $out = [];

        foreach (self::webReachableKeys() as $key) {
            if (array_key_exists($key, self::EXEMPT)) {
                continue;
            }

            $out[$key] = ['settings.core_update.'.$key];
        }

        return $out;
    }

    /**
     * `app/Console/` 밖에서 참조되는 `settings.core_update.*` 키 중 문구(문자열)인 것.
     *
     * DataProvider 는 앱 부팅 전에 돌므로 `base_path()` 를 쓰지 않는다.
     *
     * @return list<string>
     */
    public static function webReachableKeys(): array
    {
        $root = dirname(__DIR__, 3);

        $referenced = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (str_contains($path, '/app/Console/')) {
                continue;
            }

            preg_match_all(
                '/settings\.core_update\.([a-z0-9_]+)/',
                (string) file_get_contents($path),
                $matches
            );

            foreach ($matches[1] as $key) {
                $referenced[$key] = true;
            }
        }

        /** @var array<string, mixed> $ko */
        $ko = require $root.'/lang/ko/settings.php';
        $messages = $ko['core_update'] ?? [];

        $keys = array_values(array_filter(
            array_keys($referenced),
            static fn (string $key): bool => is_string($messages[$key] ?? null)
        ));

        sort($keys);

        return $keys;
    }

    /**
     * 모집단이 죽으면 아래 케이스가 통째로 사라진다 — 그 상태와 「전부 통과」는 구분되지 않는다.
     */
    #[Test]
    public function test_the_derived_population_is_not_empty(): void
    {
        $this->assertGreaterThanOrEqual(
            50,
            count(self::webReachableKeys()),
            '웹 도달 문구 키 파생이 무너졌다 — 이 값이 줄면 아래 계약이 재는 대상도 함께 줄어든다'
        );
    }

    /**
     * 낡은 면제를 남기지 않는다.
     */
    #[Test]
    public function test_every_exemption_is_still_needed(): void
    {
        $root = dirname(__DIR__, 3);

        $catalogs = [];
        foreach ([
            'ko' => $root.'/lang/ko/settings.php',
            'en' => $root.'/lang/en/settings.php',
            'ja' => $root.'/lang-packs/_bundled/g7-core-ja/backend/ja/settings.php',
        ] as $locale => $path) {
            if (is_file($path)) {
                /** @var array<string, mixed> $data */
                $data = require $path;
                $catalogs[$locale] = $data['core_update'] ?? [];
            }
        }

        $this->assertNotEmpty($catalogs, '문구 카탈로그를 하나도 읽지 못했다');

        foreach (self::EXEMPT as $key => $reason) {
            $this->assertNotSame('', trim($reason), "면제에 사유가 없다: {$key}");

            $stillCli = false;
            foreach ($catalogs as $messages) {
                $value = $messages[$key] ?? null;
                if (! is_string($value)) {
                    continue;
                }
                foreach (self::CLI_ONLY_MARKERS as $marker) {
                    if (str_contains($value, $marker)) {
                        $stillCli = true;
                    }
                }
            }

            $this->assertTrue(
                $stillCli,
                "면제가 낡았다: {$key} — 어느 로케일에도 명령줄 지시가 없다. 표에서 지워야 "
                .'그 자리에 다시 들어올 때 red 가 된다'
            );
        }
    }

    #[Test]
    #[DataProvider('sharedKeys')]
    public function test_shared_failure_message_names_no_command_line_only_action(string $key): void
    {
        foreach (['ko', 'en'] as $locale) {
            $message = (string) __($key, [], $locale);

            $this->assertNotSame($key, $message, "번역이 없다: {$key} ({$locale})");

            foreach (self::CLI_ONLY_MARKERS as $marker) {
                $this->assertStringNotContainsString(
                    $marker,
                    $message,
                    "CLI 와 웹이 함께 쓰는 문구({$key} · {$locale})가 명령줄 전용 지시를 담고 있다: "
                    ."\"{$message}\" — 명령줄을 쓸 수 없는 호스팅의 운영자는 이 안내를 따를 수 없다"
                );
            }
        }
    }

    /**
     * 번들 일본어 팩도 같은 계약을 따른다.
     *
     * 로케일마다 다른 문구를 쓰므로 한 로케일만 재면 나머지가 조용히 어긋난다 — 실제로
     * ko·en 을 고칠 때 ja 팩이 그대로 남아 있었다.
     */
    #[Test]
    #[DataProvider('sharedKeys')]
    public function test_bundled_japanese_pack_follows_the_same_contract(string $key): void
    {
        $path = base_path('lang-packs/_bundled/g7-core-ja/backend/ja/settings.php');

        if (! is_file($path)) {
            $this->markTestSkipped('번들 ja 팩이 없는 배포본에서는 이 계약이 성립하지 않는다.');
        }

        /** @var array<string, mixed> $translations */
        $translations = require $path;

        $segments = explode('.', $key);
        array_shift($segments); // 파일명(settings) 제거

        $value = $translations;

        foreach ($segments as $segment) {
            $this->assertIsArray($value, "ja 팩에 키 경로가 없다: {$key}");
            $this->assertArrayHasKey($segment, $value, "ja 팩에 키가 없다: {$key}");
            $value = $value[$segment];
        }

        $this->assertIsString($value);

        foreach (self::CLI_ONLY_MARKERS as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $value,
                "ja 팩의 공유 문구({$key})가 명령줄 전용 지시를 담고 있다: \"{$value}\""
            );
        }
    }
}
