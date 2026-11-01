<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Traits\LocalizesDescription;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

/**
 * 코어 artisan 명령의 설명(`php artisan list`·`--help`)이 사이트 언어를 따른다.
 *
 * 모집단은 등록된 명령 중 `App\Console\Commands\` 클래스 전부(새 명령이 늘면 자동 포함). 설명 문장은
 * `lang/{locale}/commands.php` 의 `descriptions.{명령 이름}` 에 있고, 명령 클래스는 `LocalizesDescription` 으로 읽는다.
 */
class CommandDescriptionLocalizationTest extends TestCase
{
    /**
     * 코어 명령 목록 (이름 → 명령).
     *
     * @return array<string, Command> 별칭을 뺀 코어 명령
     */
    private function coreCommands(): array
    {
        $commands = [];
        foreach (Artisan::all() as $name => $command) {
            if (str_starts_with($command::class, 'App\\Console\\Commands\\') && $name === $command->getName()) {
                $commands[$name] = $command;
            }
        }

        return $commands;
    }

    /**
     * 모든 코어 명령이 trait 을 쓰고, ko·en 설명이 키가 아닌 문장이며 두 언어가 서로 다르다.
     *
     * @effects core_command_descriptions_follow_locale
     *
     * @return void 반환값 없음
     */
    public function test_every_core_command_description_is_localized(): void
    {
        $commands = $this->coreCommands();
        $this->assertGreaterThan(100, count($commands), '코어 명령 모집단이 비었거나 줄었다');

        $problems = [];
        foreach ($commands as $name => $command) {
            if (! in_array(LocalizesDescription::class, class_uses_recursive($command), true)) {
                $problems[] = "{$name}: LocalizesDescription 미사용";

                continue;
            }
            $texts = [];
            foreach (['ko', 'en'] as $locale) {
                app()->setLocale($locale);
                $texts[$locale] = $command->getDescription();
                if (! trans()->hasForLocale('commands.descriptions.'.$name, $locale)) {
                    $problems[] = "{$name}: {$locale} 키 없음";
                }
            }
            if ($texts['ko'] === $texts['en']) {
                $problems[] = "{$name}: ko·en 설명이 같다 — {$texts['ko']}";
            }
        }

        $this->assertSame([], $problems);
    }
}
