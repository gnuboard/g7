<?php

namespace Tests\Feature\Console;

use App\Console\Commands\LanguagePack\UninstallLanguagePackCommand;
use App\Console\Commands\Module\UninstallModuleCommand;
use App\Console\Commands\Module\UpdateModuleCommand;
use App\Console\Commands\Plugin\UninstallPluginCommand;
use App\Console\Commands\Plugin\UpdatePluginCommand;
use App\Console\Commands\Template\UninstallTemplateCommand;
use App\Console\Commands\Template\UpdateTemplateCommand;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\LanguagePack;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 확인 질문에 답할 수 없는 확장 업데이트, 제거 명령 실행이 성공으로 끝나지 않는지 잰다.
 *
 * `module:update`, `plugin:update`, `template:update`, `module:uninstall`, `plugin:uninstall`, `template:uninstall`,
 * `language-pack:uninstall` 은 `--force` 없이 실행하면 진행 확인을 묻는다. cron, CI, 원격 명령, `--no-interaction` 은
 * 그 확인에 답할 수 없어 종전에는 기본값 「아니오」로 「취소」 한 줄을 내고 종료 코드 0 으로 끝났고, 자동화는 작업이 끝난
 * 줄 알았다. 지금은 사유와 `--force` 안내를 출력하고 종료 코드 1 로 끝난다. 대화형에서 운영자가 직접 「아니오」를 답한
 * 취소는 종전처럼 0 이다.
 *
 * 매니저와 서비스는 목으로 바꾼다. 확인을 통과한 실행은 실제 업데이트, 제거 메서드 대신 목이 던지는 표식 예외에서
 * 멈추므로 확장은 한 번도 바뀌지 않는다.
 */
class ExtensionLifecycleNonInteractiveConfirmTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 확인을 통과했을 때 실제 작업 대신 목이 던지는 표식 문구.
     */
    private const REACHED_WORK = 'fx-test: reached the work step after confirmation';

    /**
     * 시험할 식별자.
     */
    private const IDENTIFIER = 'acme-confirm_probe';

    /**
     * 진행 확인을 묻는 확장 관리 명령 목록.
     *
     * @return array<string, array{0: string}> 명령 이름
     */
    public static function commandProvider(): array
    {
        return [
            'module:update' => ['module:update'],
            'plugin:update' => ['plugin:update'],
            'template:update' => ['template:update'],
            'module:uninstall' => ['module:uninstall'],
            'plugin:uninstall' => ['plugin:uninstall'],
            'template:uninstall' => ['template:uninstall'],
            'language-pack:uninstall' => ['language-pack:uninstall'],
        ];
    }

    /**
     * `--no-interaction` 실행은 확인을 받지 못한 취소를 사유와 함께 실패로 끝내고 작업을 시작하지 않는다.
     *
     * @scenario input=no_interaction_option, force_option=absent
     *
     * @effects unanswerable_confirmation_fails_with_reason_and_force_hint, work_does_not_start_without_confirmation
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('commandProvider')]
    public function test_no_interaction_run_without_force_fails_with_reason(string $command): void
    {
        $this->prepare($command, workExpected: false);

        $this->artisan($command, $this->arguments($command, ['--no-interaction' => true]))
            ->expectsOutputToContain($this->unavailableMessage($command))
            ->expectsOutputToContain($this->unavailableHint($command))
            ->doesntExpectOutputToContain($this->abortedMessage($command))
            ->assertExitCode(Command::FAILURE)
            ->run();

        $this->assertRefusalKeysExist($command);
    }

    /**
     * 터미널 없이 부른 실행(입력이 콘솔이 아님)도 같은 규칙을 따른다 — `--no-interaction` 을 붙이지 않은 원격 명령.
     *
     * @scenario input=no_terminal, force_option=absent
     *
     * @effects unanswerable_confirmation_fails_with_reason_and_force_hint, work_does_not_start_without_confirmation
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('commandProvider')]
    public function test_run_without_terminal_and_without_force_fails_with_reason(string $command): void
    {
        $this->prepare($command, workExpected: false, withoutTerminal: true);

        $this->artisan($command, $this->arguments($command))
            ->expectsOutputToContain($this->unavailableMessage($command))
            ->expectsOutputToContain($this->unavailableHint($command))
            ->doesntExpectOutputToContain($this->abortedMessage($command))
            ->assertExitCode(Command::FAILURE)
            ->run();

        $this->assertRefusalKeysExist($command);
    }

    /**
     * `--no-interaction` 실행에 `--force` 를 붙이면 확인을 건너뛰고 작업 단계에 닿는다.
     *
     * @scenario input=no_interaction_option, force_option=present
     *
     * @effects force_option_skips_the_confirmation
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('commandProvider')]
    public function test_no_interaction_run_with_force_passes_the_confirmation(string $command): void
    {
        $this->prepare($command, workExpected: true);

        $this->artisan($command, $this->arguments($command, ['--no-interaction' => true, '--force' => true]))
            ->doesntExpectOutputToContain($this->unavailableMessage($command))
            ->expectsOutputToContain(self::REACHED_WORK)
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * 터미널 없는 실행에서도 `--force` 는 확인을 건너뛴다.
     *
     * @scenario input=no_terminal, force_option=present
     *
     * @effects force_option_skips_the_confirmation
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('commandProvider')]
    public function test_run_without_terminal_with_force_passes_the_confirmation(string $command): void
    {
        $this->prepare($command, workExpected: true, withoutTerminal: true);

        $this->artisan($command, $this->arguments($command, ['--force' => true]))
            ->doesntExpectOutputToContain($this->unavailableMessage($command))
            ->expectsOutputToContain(self::REACHED_WORK)
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * 대화형에서 `--force` 를 붙이면 묻지 않는다 (질문이 나오면 테스트가 예기치 않은 질문으로 실패한다).
     *
     * @scenario input=interactive, force_option=present
     *
     * @effects force_option_skips_the_confirmation
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('commandProvider')]
    public function test_interactive_run_with_force_does_not_ask(string $command): void
    {
        $this->prepare($command, workExpected: true);

        $this->artisan($command, $this->arguments($command, ['--force' => true]))
            ->doesntExpectOutputToContain($this->unavailableMessage($command))
            ->expectsOutputToContain(self::REACHED_WORK)
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * 대화형에서 운영자가 직접 「아니오」를 답한 취소는 종전처럼 「취소」 문구와 종료 코드 0 이고 사유 안내를 내지 않는다.
     *
     * @scenario input=interactive, force_option=absent
     *
     * @effects interactive_no_answer_keeps_success_exit_code, work_does_not_start_without_confirmation
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    #[DataProvider('commandProvider')]
    public function test_interactive_no_answer_keeps_success_exit_code(string $command): void
    {
        $this->prepare($command, workExpected: false);

        $this->artisan($command, $this->arguments($command))
            ->expectsQuestion($this->confirmQuestion($command).' (yes/no) [no]', 'no')
            ->expectsOutput($this->abortedMessage($command))
            ->doesntExpectOutputToContain($this->unavailableMessage($command))
            ->assertExitCode(Command::SUCCESS);
    }

    /**
     * 명령이 확인 단계까지 가도록 목을 묶고, 확인 뒤 작업 메서드의 호출 여부를 겁니다.
     *
     * @param  string  $command  명령 이름
     * @param  bool  $workExpected  확인을 통과해 작업 메서드가 한 번 불려야 하면 true, 한 번도 불리면 안 되면 false
     * @param  bool  $withoutTerminal  입력이 콘솔이 아닌 실행을 흉내 낼지
     * @return void 반환값 없음
     */
    private function prepare(string $command, bool $workExpected, bool $withoutTerminal = false): void
    {
        [$owner, $workMethod] = match ($command) {
            'module:update' => [$this->moduleManager(), 'updateModule'],
            'module:uninstall' => [$this->moduleManager(), 'uninstallModule'],
            'plugin:update' => [$this->pluginManager(), 'updatePlugin'],
            'plugin:uninstall' => [$this->pluginManager(), 'uninstallPlugin'],
            'template:update' => [$this->templateManager(), 'updateTemplate'],
            'template:uninstall' => [$this->templateManager(), 'uninstallTemplate'],
            'language-pack:uninstall' => [$this->languagePackService(), 'uninstall'],
        };

        if ($workExpected) {
            $owner->shouldReceive($workMethod)->once()->andThrow(new \RuntimeException(self::REACHED_WORK));
        } else {
            $owner->shouldNotReceive($workMethod);
        }

        if ($withoutTerminal) {
            $this->app[Kernel::class]->registerCommand($this->commandWithoutTerminal($command));
        }
    }

    /**
     * 명령에 넘길 인자를 만듭니다. 업데이트 명령은 운영자 수정 레이아웃 조회를 건너뛰도록 keep 전략을 쓴다.
     *
     * @param  string  $command  명령 이름
     * @param  array<string, mixed>  $extra  추가 옵션
     * @return array<string, mixed> 인자와 옵션
     */
    private function arguments(string $command, array $extra = []): array
    {
        $arguments = ['identifier' => self::IDENTIFIER];

        if (str_ends_with($command, ':update')) {
            $arguments['--layout-strategy'] = 'keep';
        }

        return $arguments + $extra;
    }

    /**
     * 모듈 매니저와 리포지토리 목을 컨테이너에 넣습니다.
     *
     * @return MockInterface ModuleManager 목
     */
    private function moduleManager(): MockInterface
    {
        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('loadModules')->andReturnNull();
        $manager->shouldReceive('checkModuleUpdate')->andReturn($this->updateCheck());
        $manager->shouldReceive('getModule')->andReturnNull();
        $manager->shouldReceive('getModuleLayoutsCount')->andReturn(0);
        $this->app->instance(ModuleManager::class, $manager);

        $repository = Mockery::mock(ModuleRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')->andReturn(
            (new Module)->forceFill(['identifier' => self::IDENTIFIER, 'status' => 'active', 'version' => '1.0.0'])
        );
        $this->app->instance(ModuleRepositoryInterface::class, $repository);

        return $manager;
    }

    /**
     * 플러그인 매니저와 리포지토리 목을 컨테이너에 넣습니다.
     *
     * @return MockInterface PluginManager 목
     */
    private function pluginManager(): MockInterface
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('loadPlugins')->andReturnNull();
        $manager->shouldReceive('checkPluginUpdate')->andReturn($this->updateCheck());
        $manager->shouldReceive('getPlugin')->andReturnNull();
        $manager->shouldReceive('getPluginLayoutsCount')->andReturn(0);
        $this->app->instance(PluginManager::class, $manager);

        $repository = Mockery::mock(PluginRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')->andReturn(
            (new Plugin)->forceFill(['identifier' => self::IDENTIFIER, 'status' => 'active', 'version' => '1.0.0'])
        );
        $this->app->instance(PluginRepositoryInterface::class, $repository);

        return $manager;
    }

    /**
     * 템플릿 매니저와 리포지토리 목을 컨테이너에 넣습니다.
     *
     * @return MockInterface TemplateManager 목
     */
    private function templateManager(): MockInterface
    {
        // 명령 밖의 서비스(TemplateService)가 부르는 조회 메서드는 이 시험과 무관하므로 무시한다
        $manager = Mockery::mock(TemplateManager::class)->shouldIgnoreMissing();
        $manager->shouldReceive('loadTemplates')->andReturnNull();
        $manager->shouldReceive('checkTemplateUpdate')->andReturn($this->updateCheck());
        $this->app->instance(TemplateManager::class, $manager);

        $repository = Mockery::mock(TemplateRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')->andReturn(
            (new Template)->forceFill(['identifier' => self::IDENTIFIER, 'status' => 'active', 'version' => '1.0.0'])
        );
        $this->app->instance(TemplateRepositoryInterface::class, $repository);

        return $manager;
    }

    /**
     * 언어팩 서비스 목을 컨테이너에 넣습니다.
     *
     * @return MockInterface LanguagePackService 목
     */
    private function languagePackService(): MockInterface
    {
        $service = Mockery::mock(LanguagePackService::class);
        $service->shouldReceive('findByIdentifier')->andReturn(
            (new LanguagePack)->forceFill(['identifier' => self::IDENTIFIER, 'status' => 'active'])
        );
        $this->app->instance(LanguagePackService::class, $service);

        return $service;
    }

    /**
     * 새 버전이 있다는 업데이트 확인 결과.
     *
     * @return array<string, mixed> 업데이트 확인 결과
     */
    private function updateCheck(): array
    {
        return [
            'update_available' => true,
            'current_version' => '1.0.0',
            'latest_version' => '1.1.0',
            'update_source' => 'bundled',
        ];
    }

    /**
     * 입력이 콘솔이 아닌 실행을 흉내 내는 명령 인스턴스를 만듭니다.
     *
     * 테스트 실행에서는 `canPromptForAnswer()` 가 늘 true 라(테스트가 입력을 주입하므로) 그 판정만 바꾼다.
     * Windows 처럼 posix 확장이 없어 `--no-interaction` 없이도 대화형으로 남는 원격 실행이 이 경로다.
     *
     * @param  string  $command  명령 이름
     * @return Command 판정을 바꾼 명령
     */
    private function commandWithoutTerminal(string $command): Command
    {
        $app = $this->app;

        return match ($command) {
            'module:update' => new class($app->make(ModuleManager::class), $app->make(ModuleRepositoryInterface::class)) extends UpdateModuleCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
            'module:uninstall' => new class($app->make(ModuleManager::class), $app->make(ModuleRepositoryInterface::class)) extends UninstallModuleCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
            'plugin:update' => new class($app->make(PluginManager::class), $app->make(PluginRepositoryInterface::class)) extends UpdatePluginCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
            'plugin:uninstall' => new class($app->make(PluginManager::class), $app->make(PluginRepositoryInterface::class)) extends UninstallPluginCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
            'template:update' => new class($app->make(TemplateManager::class), $app->make(TemplateRepositoryInterface::class)) extends UpdateTemplateCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
            'template:uninstall' => new class($app->make(TemplateManager::class), $app->make(TemplateRepositoryInterface::class)) extends UninstallTemplateCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
            'language-pack:uninstall' => new class($app->make(LanguagePackService::class)) extends UninstallLanguagePackCommand
            {
                /**
                 * 응답을 받을 수 없는 실행으로 판정합니다.
                 *
                 * @return bool 늘 false
                 */
                protected function canPromptForAnswer(): bool
                {
                    return false;
                }
            },
        };
    }

    /**
     * 명령의 번역 키 접두사와 식별자 치환 자리 이름.
     *
     * @param  string  $command  명령 이름
     * @return array{0: string, 1: string} [키 접두사, 치환 자리 이름]
     */
    private function langPrefix(string $command): array
    {
        return match ($command) {
            'module:update' => ['modules.commands.update', 'module'],
            'module:uninstall' => ['modules.commands.uninstall', 'module'],
            'plugin:update' => ['plugins.commands.update', 'plugin'],
            'plugin:uninstall' => ['plugins.commands.uninstall', 'plugin'],
            'template:update' => ['templates.commands.update', 'template'],
            'template:uninstall' => ['templates.commands.uninstall', 'template'],
            'language-pack:uninstall' => ['language_packs.commands.uninstall', 'identifier'],
        };
    }

    /**
     * 답할 수 없어 시작하지 않았다는 사유 문구.
     *
     * @param  string  $command  명령 이름
     * @return string 번역된 문구
     */
    private function unavailableMessage(string $command): string
    {
        [$prefix, $placeholder] = $this->langPrefix($command);

        return __("{$prefix}.confirm_unavailable", [$placeholder => self::IDENTIFIER]);
    }

    /**
     * `--force` 안내 문구.
     *
     * @param  string  $command  명령 이름
     * @return string 번역된 문구
     */
    private function unavailableHint(string $command): string
    {
        [$prefix, $placeholder] = $this->langPrefix($command);

        return __("{$prefix}.confirm_unavailable_hint", [$placeholder => self::IDENTIFIER]);
    }

    /**
     * 사유와 안내 문구의 번역 키가 실제로 있는지 확인합니다 — 키가 없으면 키 문자열 자체가 출력과 맞는 일을 막는다.
     *
     * @param  string  $command  명령 이름
     * @return void 반환값 없음
     */
    private function assertRefusalKeysExist(string $command): void
    {
        [$prefix] = $this->langPrefix($command);

        foreach (['confirm_unavailable', 'confirm_unavailable_hint'] as $suffix) {
            $this->assertTrue(trans()->has("{$prefix}.{$suffix}"), "번역 키 {$prefix}.{$suffix} 가 있어야 한다");
        }
    }

    /**
     * 진행 확인 질문.
     *
     * @param  string  $command  명령 이름
     * @return string 번역된 질문
     */
    private function confirmQuestion(string $command): string
    {
        [$prefix] = $this->langPrefix($command);

        return $command === 'language-pack:uninstall'
            ? __("{$prefix}.confirm", ['identifier' => self::IDENTIFIER])
            : __("{$prefix}.confirm_question");
    }

    /**
     * 대화형 「아니오」의 취소 문구.
     *
     * @param  string  $command  명령 이름
     * @return string 번역된 문구
     */
    private function abortedMessage(string $command): string
    {
        [$prefix] = $this->langPrefix($command);

        return __($command === 'language-pack:uninstall' ? "{$prefix}.cancelled" : "{$prefix}.aborted");
    }
}
