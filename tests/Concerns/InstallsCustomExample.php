<?php

namespace Tests\Concerns;

use App\Console\Commands\Custom\CustomExampleCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/**
 * 시험에서 `custom:example` 견본을 프로젝트 루트 대신 임시 디렉터리에 만들고, 운영과 같은 순서로 등록합니다 (#792 WP-S).
 *
 * custom 라우트 등록은 {@see LoadsCustomRoutes} 를 씁니다.
 */
trait InstallsCustomExample
{
    use LoadsCustomRoutes;

    /**
     * `custom:example` 이 지정한 루트에 만들도록 명령을 바꿔 등록합니다.
     *
     * @param  string  $root  견본을 만들 루트
     * @return void 반환값 없음
     */
    protected function bindCustomExampleRoot(string $root): void
    {
        $this->app->bind(CustomExampleCommand::class, fn () => new class($root) extends CustomExampleCommand
        {
            /**
             * 견본을 만들 루트를 받아 명령을 만듭니다.
             *
             * @param  string  $root  견본을 만들 루트
             */
            public function __construct(private string $root)
            {
                parent::__construct();
            }

            /**
             * 견본을 만들 루트 디렉터리를 반환합니다 (시험용 임시 루트).
             *
             * @return string 디렉터리 경로
             */
            protected function targetRoot(): string
            {
                return $this->root;
            }
        });

        // 이미 만들어진 Artisan 은 원래 명령 인스턴스를 들고 있다 — 다음 호출에서 다시 만든다
        $this->app[ConsoleKernel::class]->setArtisan(null);
    }

    /**
     * 견본을 임시 루트에 만들고 라우트·컨트롤러·뷰·번역을 운영과 같이 등록합니다.
     *
     * @param  string  $root  견본을 만들 루트
     * @param  string  $sample  견본 이름
     * @return void 반환값 없음
     */
    protected function installCustomExample(string $root, string $sample = 'board'): void
    {
        File::ensureDirectoryExists($root);
        $this->bindCustomExampleRoot($root);
        $this->artisan('custom:example', ['sample' => $sample])->assertSuccessful()->run();

        // 운영에서는 Composer 의 App\ 자동 로드가 app/Custom 을 읽는다 — 시험은 임시 루트의 파일을 직접 읽는다
        foreach (File::allFiles($root.'/app/Custom') as $file) {
            $class = 'App\\Custom\\'.str_replace(['/', '.php'], ['\\', ''], str_replace('\\', '/', $file->getRelativePathname()));
            if (! class_exists($class, false)) {
                require_once $file->getPathname();
            }
        }

        View::addLocation($root.'/resources/views');
        $this->app['translator']->addNamespace('custom', $root.'/lang/custom');
        $this->loadCustomRoutesSource(File::get($root.'/routes/custom.php'));
    }
}
