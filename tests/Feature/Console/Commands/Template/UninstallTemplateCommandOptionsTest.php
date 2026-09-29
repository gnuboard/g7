<?php

namespace Tests\Feature\Console\Commands\Template;

use App\Extension\TemplateManager;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * template:uninstall 의 --force · --delete-data 옵션 (#138 실측 ⑤)
 *
 * 모듈·플러그인 제거 명령은 두 옵션이 있는데 템플릿만 없어, CLI 에서는 확인 질문을 건너뛸 수 없고 관리자 화면의
 * 「데이터도 삭제」 선택을 줄 수 없었다(활동 로그·after_uninstall 구독자가 받는 deleteData 가 늘 false).
 */
class UninstallTemplateCommandOptionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 제거 호출을 기록하는 가짜 템플릿 관리자를 묶습니다.
     *
     * @param  array<int, bool>  $calls  uninstallTemplate 에 넘어온 deleteData 기록
     * @return void 반환값 없음
     */
    private function fakeManager(array &$calls): void
    {
        $manager = Mockery::mock(TemplateManager::class)->shouldIgnoreMissing();
        $manager->shouldReceive('loadTemplates')->andReturnNull();
        $manager->shouldReceive('uninstallTemplate')->andReturnUsing(
            function (string $id, ?\Closure $onProgress = null, ?array &$preserved = null, bool $deleteData = false) use (&$calls) {
                $calls[] = $deleteData;

                return true;
            }
        );
        $this->app->instance(TemplateManager::class, $manager);

        Template::factory()->create(['identifier' => 'acme-theme']);
    }

    /**
     * --force 는 확인 질문 없이 제거하고, --delete-data 는 제거 호출에 데이터 삭제 선택을 싣는다.
     *
     * @scenario case=force_with_delete_data
     *
     * @effects template_uninstall_force_skips_confirm_and_forwards_delete_data
     *
     * @return void 반환값 없음
     */
    public function test_force_skips_confirmation_and_delete_data_is_forwarded(): void
    {
        $calls = [];
        $this->fakeManager($calls);

        $this->artisan('template:uninstall', ['identifier' => 'acme-theme', '--force' => true, '--delete-data' => true])
            ->doesntExpectOutput(__('templates.commands.uninstall.confirm_question').' (yes/no) [no]')
            ->expectsOutput('✅ '.__('templates.commands.uninstall.success', ['template' => 'acme-theme']))
            ->assertExitCode(0);

        $this->assertSame([true], $calls);
    }

    /**
     * 옵션 없이 부르면 종전대로 확인을 묻고, 데이터 삭제는 선택하지 않은 것으로 넘긴다.
     *
     * @scenario case=confirm_without_delete_data
     *
     * @effects template_uninstall_force_skips_confirm_and_forwards_delete_data
     *
     * @return void 반환값 없음
     */
    public function test_without_options_asks_confirmation_and_keeps_data(): void
    {
        $calls = [];
        $this->fakeManager($calls);

        $this->artisan('template:uninstall', ['identifier' => 'acme-theme'])
            ->expectsQuestion(__('templates.commands.uninstall.confirm_question').' (yes/no) [no]', 'yes')
            ->assertExitCode(0);

        $this->assertSame([false], $calls);
    }
}
