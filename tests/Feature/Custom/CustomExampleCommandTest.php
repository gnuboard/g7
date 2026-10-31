<?php

namespace Tests\Feature\Custom;

use App\Console\Commands\Custom\CustomExampleCommand;
use App\Extension\Helpers\UpdateReservedPaths;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InstallsCustomExample;
use Tests\TestCase;

/**
 * 견본 명령 `custom:example` 테스트 (#792 WP-S)
 *
 * 견본은 예약 자리에 파일을 만들고(`.stub` 제거) 만든 목록을 보여 주며, 만들 파일이 하나라도 있으면
 * 아무것도 쓰지 않고 그 목록을 보여 준 뒤 중단한다. 시험은 프로젝트 루트 대신 임시 디렉터리에 만든다.
 */
class CustomExampleCommandTest extends TestCase
{
    use InstallsCustomExample;

    /**
     * board 견본이 만드는 파일 (프로젝트 루트 기준).
     */
    private const BOARD_FILES = [
        'app/Custom/Http/Controllers/AdminExampleController.php',
        'app/Custom/Http/Controllers/BoardController.php',
        'lang/custom/en/board.php',
        'lang/custom/ko/board.php',
        'public/custom/example.css',
        'public/custom/example.js',
        'resources/views/custom/admin/example.blade.php',
        'resources/views/custom/board/error.blade.php',
        'resources/views/custom/board/list.blade.php',
        'resources/views/custom/board/show.blade.php',
        'resources/views/custom/jquery/index.html',
        'resources/views/custom/layout.blade.php',
        'routes/custom.php',
    ];

    private string $root;

    /**
     * 임시 루트에 만드는 명령으로 바꿔 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/custom_example_'.uniqid());
        File::ensureDirectoryExists($this->root);
        $this->bindCustomExampleRoot($this->root);
    }

    /**
     * 임시 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * board 견본은 예약 자리에만 파일을 만들고, 만든 파일 목록을 출력합니다.
     *
     * @scenario case=creates
     *
     * @effects creates_reserved_files_only, lists_created_files
     *
     * @return void 반환값 없음
     */
    public function test_board_sample_creates_reserved_files_and_lists_them(): void
    {
        $command = $this->artisan('custom:example', ['sample' => 'board'])
            ->expectsOutputToContain('견본 파일 '.count(self::BOARD_FILES).'개를 만들었습니다');
        foreach (self::BOARD_FILES as $file) {
            $command->expectsOutputToContain($file);
        }
        $command->assertSuccessful()->run();

        $created = array_map(
            fn ($f) => str_replace('\\', '/', $f->getRelativePathname()),
            File::allFiles($this->root)
        );
        sort($created);
        $this->assertSame(self::BOARD_FILES, $created);

        // 만든 파일은 모두 코어 업데이트 예약 자리 안이다
        foreach ($created as $file) {
            $this->assertTrue(UpdateReservedPaths::contains($file), $file);
        }

        // 원본 그대로 복사한다
        $this->assertFileEquals(
            CustomExampleCommand::stubRoot().'/board/routes/custom.php.stub',
            $this->root.'/routes/custom.php'
        );
    }

    /**
     * 만들 파일 중 하나라도 있으면 아무것도 쓰지 않고, 있는 파일을 보여 준 뒤 실패로 끝납니다.
     *
     * @scenario case=exists
     *
     * @effects aborts_without_writing, lists_existing_files
     *
     * @return void 반환값 없음
     */
    public function test_aborts_without_writing_when_target_exists(): void
    {
        File::ensureDirectoryExists($this->root.'/routes');
        File::put($this->root.'/routes/custom.php', "<?php // 개발자 파일\n");

        $this->artisan('custom:example', ['sample' => 'board'])
            ->expectsOutputToContain('이미 있는 파일이 있어 견본을 만들지 않았습니다')
            ->expectsOutputToContain('routes/custom.php')
            ->assertFailed();

        $this->assertSame("<?php // 개발자 파일\n", File::get($this->root.'/routes/custom.php'));
        $this->assertSame(['routes/custom.php'], array_map(
            fn ($f) => str_replace('\\', '/', $f->getRelativePathname()),
            File::allFiles($this->root)
        ));
    }

    /**
     * 없는 견본 이름은 가능한 견본을 알려 주고 실패합니다.
     *
     * @scenario case=unknown
     *
     * @effects unknown_sample_fails
     *
     * @return void 반환값 없음
     */
    public function test_unknown_sample_fails(): void
    {
        $this->artisan('custom:example', ['sample' => 'shop'])
            ->expectsOutputToContain('가능한 견본: board')
            ->assertFailed();

        $this->assertSame([], File::allFiles($this->root));
    }
}
