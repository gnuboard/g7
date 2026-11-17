<?php

namespace Tests\Feature\Console;

use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\Integrity\PackageVerification;
use App\Extension\Integrity\UnsignedPackagePolicy;
use App\Models\LanguagePack;
use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArgvInput;
use Tests\TestCase;

/**
 * `--yes`(`-y`) 를 받는 명령과 `language-pack:update` 의 `--yes` 를 잰다 (#918).
 *
 * - `--yes` 를 선언한 명령은 모두 `-y` 축약도 받는다. 종전에는 `--yes` 만 선언해 `-y` 로 부르면 Symfony 가
 *   「The "-y" option does not exist.」 로 종료 코드 1 을 냈다.
 * - `language-pack:update` 는 진행 확인을 묻지 않지만 다른 `{type}:update` 와 같은 형태(`-y`)로 부르는 일괄 갱신
 *   스크립트가 언어팩에서만 실패하지 않도록 `--yes` 를 받는다. 받아도 미서명 패키지 확인은 대신하지 않는다
 *   (`--allow-unsigned` 하나로만 동의).
 */
class YesOptionShortcutTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 시험할 언어팩 식별자.
     */
    private const PACK = 'g9-yes-probe-ja';

    /**
     * 시험 뒤 목을 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * `--yes` 를 선언한 명령 목록.
     *
     * @return array<string, array{0: string}> 명령 이름
     */
    public static function yesCommandProvider(): array
    {
        $commands = [
            'module:update', 'plugin:update', 'template:update',
            'module:uninstall', 'plugin:uninstall', 'template:uninstall',
            'language-pack:update', 'language-pack:uninstall',
            'core:update', 'core:execute-upgrade-steps',
            'stage:apply-to-live', 'stage-layouts:reset-from-live',
            // 7.1.6 에서 `--yes`(`-y`) 를 받던 명령(확인 질문 트레이트가 자동으로 붙였다) — 7.2.0 으로 올려도 7.1.6 스크립트가
            // 「옵션 없음」으로 실패하지 않게 같은 형태를 유지한다(7.1.6 과 같은 형태)
            'settings:migrate-to-json', 'search:index',
            'analytics:anonymize', 'analytics:purge', 'analytics:purge-extension',
            'hotfix:rollback-stale-files',
        ];

        return array_combine($commands, array_map(fn (string $c) => [$c], $commands));
    }

    /**
     * 명령줄 그대로의 `-y` 가 `--yes` 로 읽힌다 — 축약이 없으면 ArgvInput 이 「옵션 없음」 예외를 던진다.
     *
     * @scenario case=short_y_parse
     *
     * @effects short_y_is_read_as_yes_option
     *
     * @param  string  $name  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('yesCommandProvider')]
    public function test_short_y_is_read_as_yes(string $name): void
    {
        $command = $this->app[Kernel::class]->all()[$name] ?? null;
        $this->assertNotNull($command, "{$name} 명령이 등록돼 있어야 한다");

        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('yes'), "{$name} 에 --yes 가 있어야 한다");
        $this->assertSame('y', $definition->getOption('yes')->getShortcut(), "{$name} 의 --yes 에 -y 축약이 있어야 한다");

        // 필수 인자 검사(validate)는 이 시험의 관심 밖이라 bind 만 한다 — bind 가 「-y 옵션 없음」을 판정한다
        $input = new ArgvInput(['artisan', '-y']);
        $input->bind($definition);
        $this->assertTrue((bool) $input->getOption('yes'));
    }

    /**
     * `language-pack:update -y` 는 받아들여 그대로 업데이트한다 — `--yes` 는 미서명 동의(allowUnsigned)를 켜지 않는다.
     *
     * @scenario case=language_pack_update_yes
     *
     * @effects language_pack_update_accepts_yes_and_updates
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_update_accepts_short_y(): void
    {
        $calls = [];
        $this->languagePackService(function (LanguagePack $pack, bool $force, PackageIntegrityOptions $integrity) use (&$calls) {
            $calls[] = ['force' => $force, 'allow_unsigned' => $integrity->allowUnsigned];

            return (clone $pack)->forceFill(['version' => '1.0.1']);
        });

        $this->artisan('language-pack:update', ['identifier' => self::PACK, '--source' => 'bundled', '-y' => true, '--no-interaction' => true])
            ->expectsOutputToContain('1.0.1')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame([['force' => true, 'allow_unsigned' => false]], $calls);

        $calls = [];
        $this->artisan('language-pack:update', ['identifier' => self::PACK, '--source' => 'github', '--yes' => true, '--no-interaction' => true])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame([['force' => false, 'allow_unsigned' => false]], $calls, '--yes 가 --force 효과를 켰다');
    }

    /**
     * 미서명 새 판에서 `-y` 만 준 비대화형 실행은 묻지도 받아들이지도 않고 `--allow-unsigned` 안내와 함께 실패한다.
     * `--allow-unsigned` 를 붙이면 진행한다.
     *
     * @scenario case=unsigned_yes_noninteractive
     *
     * @effects yes_option_does_not_answer_unsigned_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_update_yes_noninteractive_does_not_accept_unsigned(): void
    {
        $calls = [];
        $this->unsignedLanguagePackService($calls);

        $this->artisan('language-pack:update', ['identifier' => self::PACK, '--source' => 'github', '-y' => true, '--no-interaction' => true])
            ->expectsOutputToContain('--allow-unsigned')
            ->assertExitCode(Command::FAILURE);
        $this->assertSame([false], $calls, '-y 가 미서명 확인을 대신했다');

        $calls = [];
        $this->artisan('language-pack:update', ['identifier' => self::PACK, '--source' => 'github', '-y' => true, '--no-interaction' => true, '--allow-unsigned' => true])
            ->assertExitCode(Command::SUCCESS);
        $this->assertSame([true], $calls);
    }

    /**
     * 대화형이면 `-y` 가 있어도 미서명 확인을 묻고, 「예」면 미서명 동의로 다시 실행한다.
     *
     * @scenario case=unsigned_yes_interactive
     *
     * @effects yes_option_does_not_answer_unsigned_confirmation
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_update_yes_interactive_still_asks_unsigned(): void
    {
        $calls = [];
        $this->unsignedLanguagePackService($calls);

        $this->artisan('language-pack:update', ['identifier' => self::PACK, '--source' => 'github', '-y' => true])
            ->expectsQuestion(__('extension_integrity.commands.unsigned_confirm', ['reason' => __('extension_integrity.reasons.unsigned')]).' (yes/no) [no]', 'yes')
            ->assertExitCode(Command::SUCCESS);
        $this->assertSame([false, true], $calls);
    }

    /**
     * 새 판이 미서명인 언어팩 서비스 목을 넣습니다 — 미서명 동의 없이 부르면 정책이 거절합니다.
     *
     * @param  array<int, bool>  $calls  호출마다 미서명 동의 여부를 쌓을 배열
     * @return void 반환값 없음
     */
    private function unsignedLanguagePackService(array &$calls): void
    {
        $this->languagePackService(function (LanguagePack $pack, bool $force, PackageIntegrityOptions $integrity) use (&$calls) {
            $calls[] = $integrity->allowUnsigned;
            app(UnsignedPackagePolicy::class)->decide(PackageVerification::unsigned(), $integrity);

            return (clone $pack)->forceFill(['version' => '1.0.1']);
        });
    }

    /**
     * 언어팩 서비스 목을 컨테이너에 넣습니다.
     *
     * @param  \Closure(LanguagePack, bool, PackageIntegrityOptions): LanguagePack  $performUpdate  업데이트 대역
     * @return void 반환값 없음
     */
    private function languagePackService(\Closure $performUpdate): void
    {
        $service = Mockery::mock(LanguagePackService::class);
        $service->shouldReceive('findByIdentifier')->with(self::PACK)->andReturn(
            (new LanguagePack)->forceFill(['identifier' => self::PACK, 'status' => 'active', 'version' => '1.0.0'])
        );
        $service->shouldReceive('performUpdate')->andReturnUsing($performUpdate);
        $this->app->instance(LanguagePackService::class, $service);
    }
}
