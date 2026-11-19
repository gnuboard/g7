<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Seeders;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Modules\Sirsoft\Board\Database\Seeders\Sample\ThumbnailMeasureCleanupSeeder;
use Modules\Sirsoft\Board\Database\Seeders\Sample\ThumbnailMeasureSeeder;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 게시판 이미지 썸네일 실측 시더(개발 DB 전용 샘플 시더)의 시작 안내와 표 모양을 검증한다.
 *
 * 옵트인(`G7_PLAYWRIGHT_BYPASS=1`) 없이 부르면 시작 안내만 찍고 데이터에 손대기 전에 멈추므로, 이 검사는 DB 에 쓰지 않는다.
 * 실측 데이터 표의 행을 만드는 함수는 DB 없이 부를 수 있고, 그 열 수가 머리글 수와 같아야 한다.
 */
class ThumbnailMeasureSeederTest extends ModuleTestCase
{
    /** 실측 시더의 옵트인 환경변수 */
    private const BYPASS = 'G7_PLAYWRIGHT_BYPASS';

    /** @var array{process: string|false, env: string|null, server: string|null} 원래 환경변수 */
    private array $originalBypass;

    /**
     * 옵트인 환경변수를 기억하고 세 통로에서 모두 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBypass = [
            'process' => getenv(self::BYPASS),
            'env' => $_ENV[self::BYPASS] ?? null,
            'server' => $_SERVER[self::BYPASS] ?? null,
        ];
        putenv(self::BYPASS);
        unset($_ENV[self::BYPASS], $_SERVER[self::BYPASS]);
    }

    /**
     * 옵트인 환경변수를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $original = $this->originalBypass;
        putenv($original['process'] === false ? self::BYPASS : self::BYPASS.'='.$original['process']);
        if ($original['env'] !== null) {
            $_ENV[self::BYPASS] = $original['env'];
        }
        if ($original['server'] !== null) {
            $_SERVER[self::BYPASS] = $original['server'];
        }

        parent::tearDown();
    }

    /**
     * 검사할 사이트 언어.
     *
     * @return array<string, array{0: string}> 로케일
     */
    public static function locales(): array
    {
        return ['ko' => ['ko'], 'en' => ['en']];
    }

    /**
     * 생성, 정리 시더는 run() 첫머리에서 그 언어의 시작 안내를 찍고, 옵트인이 없으면 그 뒤에 멈춘다.
     *
     * @scenario seeder=generate
     *
     * @effects start_message_printed_first
     *
     * @param  string  $locale  사이트 언어
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('locales')]
    public function test_seeders_print_start_message_first(string $locale): void
    {
        $this->app->setLocale($locale);

        foreach ([
            ThumbnailMeasureSeeder::class => 'sirsoft-board::messages.seeders.thumbnail_measure.start',
            ThumbnailMeasureCleanupSeeder::class => 'sirsoft-board::messages.seeders.thumbnail_measure.cleanup_start',
        ] as $seeder => $key) {
            $expected = __($key);
            $this->assertNotSame($key, $expected, $key.' 번역이 없습니다');

            $buffer = new BufferedOutput;
            try {
                (new $seeder)->setCommand($this->bufferedCommand($buffer))->run();
                $this->fail($seeder.' 가 옵트인 없이 실행됐습니다');
            } catch (RuntimeException) {
                // 옵트인 가드 거부 — 그 전에 찍힌 안내만 본다
            }

            $this->assertStringContainsString($expected, $buffer->fetch(), $seeder);
        }
    }

    /**
     * 실측 데이터 표의 행 열 수(첨부가 있을 때와 없을 때 모두)가 머리글 수(8)와 같다.
     *
     * @scenario seeder=generate
     *
     * @effects table_rows_match_headers
     *
     * @param  string  $locale  사이트 언어
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('locales')]
    public function test_attachment_row_matches_header_count(string $locale): void
    {
        $this->app->setLocale($locale);
        $headers = (array) __('sirsoft-board::messages.seeders.thumbnail_measure.data_headers');
        $attachment = (object) ['id' => 3, 'hash' => 'abcdefabcdef', 'mime_type' => 'image/jpeg', 'meta' => ['width' => 2000, 'height' => 2000], 'preview_url' => '/p'];

        $this->assertCount(8, $headers);
        $this->assertCount(count($headers), ThumbnailMeasureSeeder::attachmentRow('thumb-measure-card', 'kind', 10, $attachment));
        $this->assertCount(count($headers), ThumbnailMeasureSeeder::attachmentRow('thumb-measure-card', 'kind', 10, null));
    }

    /**
     * 출력을 버퍼에 모으는 비대화형 콘솔 명령을 만듭니다.
     *
     * @param  BufferedOutput  $buffer  출력 버퍼
     * @return Command 명령
     */
    private function bufferedCommand(BufferedOutput $buffer): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'test:board-thumbnail-measure-seed';
        };
        $command->setLaravel($this->app);

        $input = new ArrayInput([], $command->getDefinition());
        $input->setInteractive(false);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $buffer));

        return $command;
    }
}
