<?php

namespace Tests\Feature\Seeders;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Models\Attachment;
use App\Models\User;
use Database\Seeders\Sample\ThumbnailMeasureCleanupSeeder;
use Database\Seeders\Sample\ThumbnailMeasureKit;
use Database\Seeders\Sample\ThumbnailMeasureSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 이미지 썸네일 실측 시더(개발 DB 전용 샘플 시더) 코어 쪽의 콘솔 출력, 거부 사유, 정리 동작을 검증한다.
 *
 * 실측 시더는 `G7_PLAYWRIGHT_BYPASS=1` 을 준 명령줄 실행에서만 돈다. 그 조건이 없으면 시작 안내만 찍고 데이터와 파일을
 * 건드리기 전에 멈춘다. 표 머리글과 정리 건수 이름은 번역 키에서 오고, 행을 만드는 함수의 열 수가 머리글 수와 같다.
 * 정리 시더는 원래 로고 백업이 없어도 지운 실측 로고를 사이트 로고 설정에 남기지 않는다. 키가 ko·en·ja 세 곳에
 * 있는지와 치환 자리 일치는 `ConsoleOutputTranslationCatalogTest` 가 맡는다.
 */
class ThumbnailMeasureSeederOutputTest extends TestCase
{
    use DatabaseTransactions;

    /** 실측 시더의 옵트인 환경변수 */
    private const BYPASS = 'G7_PLAYWRIGHT_BYPASS';

    /** @var array{process: string|false, env: string|null, server: string|null} 원래 환경변수 */
    private array $originalBypass;

    /** 콘솔 출력 버퍼 */
    private BufferedOutput $buffer;

    /**
     * 옵트인 환경변수를 기억하고 세 통로(`getenv`·`$_ENV`·`$_SERVER`)에서 모두 지웁니다.
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

        $this->setBypass(false);
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
        unset($_ENV[self::BYPASS], $_SERVER[self::BYPASS]);
        if ($original['env'] !== null) {
            $_ENV[self::BYPASS] = $original['env'];
        }
        if ($original['server'] !== null) {
            $_SERVER[self::BYPASS] = $original['server'];
        }

        parent::tearDown();
    }

    /**
     * 옵트인 환경변수를 켜거나 끕니다 (세 통로 모두).
     *
     * @param  bool  $on  켤지 여부
     * @return void 반환값 없음
     */
    private function setBypass(bool $on): void
    {
        if ($on) {
            putenv(self::BYPASS.'=1');
            $_ENV[self::BYPASS] = '1';
            $_SERVER[self::BYPASS] = '1';

            return;
        }

        putenv(self::BYPASS);
        unset($_ENV[self::BYPASS], $_SERVER[self::BYPASS]);
    }

    /**
     * 출력을 버퍼에 모으는 비대화형 콘솔 명령을 만듭니다.
     *
     * @return Command 명령
     */
    private function bufferedCommand(): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'test:thumbnail-measure-seed';
        };
        $command->setLaravel($this->app);

        $input = new ArrayInput([], $command->getDefinition());
        $input->setInteractive(false);
        $command->setInput($input);
        $this->buffer = new BufferedOutput;
        $command->setOutput(new OutputStyle($input, $this->buffer));

        return $command;
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
     * 옵트인 없이 생성, 정리 시더를 부르면 데이터에 손대기 전에 그 언어의 사유로 멈춘다.
     *
     * @scenario surface=thumbnail_measure_seeders
     *
     * @effects command_output_uses_site_locale
     *
     * @param  string  $locale  사이트 언어
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('locales')]
    public function test_seeders_refuse_without_opt_in_in_site_locale(string $locale): void
    {
        $this->app->setLocale($locale);
        $expected = __('common.console.seeders.thumbnail_measure.bypass_required', ['example' => "\$env:G7_PLAYWRIGHT_BYPASS='1'"]);

        $this->assertStringNotContainsString('common.console', $expected, '번역 키가 해석되지 않았습니다');
        $this->assertStringContainsString("\$env:G7_PLAYWRIGHT_BYPASS='1'", $expected, '치환 자리가 채워지지 않았습니다');

        foreach ([ThumbnailMeasureSeeder::class, ThumbnailMeasureCleanupSeeder::class] as $seeder) {
            try {
                (new $seeder)->run();
                $this->fail($seeder.' 가 옵트인 없이 실행됐습니다');
            } catch (RuntimeException $e) {
                $this->assertSame($expected, $e->getMessage(), $seeder);
            }
        }
    }

    /**
     * 생성, 정리 시더는 run() 첫머리에서 시작 안내를 찍는다 — 옵트인 가드에 막혀도 무엇이 멈췄는지 보인다.
     *
     * @scenario surface=thumbnail_measure_seeders
     *
     * @effects command_output_uses_site_locale
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
            ThumbnailMeasureSeeder::class => 'common.console.seeders.thumbnail_measure.start',
            ThumbnailMeasureCleanupSeeder::class => 'common.console.seeders.thumbnail_measure.cleanup_start',
        ] as $seeder => $key) {
            $expected = __($key);
            $this->assertNotSame($key, $expected, $key.' 번역이 없습니다');

            try {
                (new $seeder)->setCommand($this->bufferedCommand())->run();
                $this->fail($seeder.' 가 옵트인 없이 실행됐습니다');
            } catch (RuntimeException) {
                // 가드 거부는 앞 시험이 본다 — 여기서는 그 전에 찍힌 안내만 본다
            }

            $this->assertStringContainsString($expected, $this->buffer->fetch(), $seeder);
        }
    }

    /**
     * 원래 로고 백업이 없을 때 정리 시더가 실측 로고 첨부를 지우면서 사이트 로고 설정에서도 그 ID 를 뺀다.
     *
     * 종전에는 백업이 없으면 설정을 건드리지 않아, 지운 첨부 ID 가 `general.site_logo` 에 남았다(로고 자리가 깨진 그림).
     * 운영자가 따로 넣은 다른 로고 ID 는 그대로 둔다.
     *
     * @scenario surface=thumbnail_measure_seeders
     *
     * @effects command_output_uses_site_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_cleanup_without_backup_drops_measure_logo_from_site_logo(): void
    {
        if (is_file(storage_path(ThumbnailMeasureSeeder::LOGO_BACKUP))) {
            $this->markTestSkipped('개발 환경에 실측 로고 백업이 있습니다 — 실측 중인 데이터를 건드리지 않으려 건너뜁니다.');
        }

        User::factory()->create(['is_super' => true]);
        $kept = Attachment::factory()->create(['collection' => 'site_logo', 'original_filename' => 'operator-logo.png']);
        $measure = Attachment::factory()->create([
            'collection' => 'site_logo',
            'original_filename' => ThumbnailMeasureSeeder::LOGO_FILENAME,
            'path' => 'attachments/thumbnail-measure-test/'.Str::uuid().'.png',
        ]);

        $config = app(ConfigRepositoryInterface::class);
        $config->set('general.site_logo', [$kept->id, $measure->id]);

        $this->setBypass(true);
        (new ThumbnailMeasureCleanupSeeder)->setCommand($this->bufferedCommand())->run();

        $this->assertSame([$kept->id], array_values(array_map('intval', (array) $config->get('general.site_logo', []))));
        $this->assertNull(Attachment::query()->find($measure->id), '실측 로고 첨부가 남았습니다');
        $this->assertNotNull(Attachment::query()->find($kept->id), '운영자 로고 첨부가 지워졌습니다');
    }

    /**
     * 외부 발송 차단은 메일 드라이버만 바꾸고 디버그 모드는 건드리지 않는다 — 실측 시더 경로에 디버그 모드를 읽는 곳이 없다.
     *
     * @scenario surface=thumbnail_measure_seeders
     *
     * @effects command_output_uses_site_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_silence_outbound_only_switches_mail_driver(): void
    {
        Config::set('app.debug', false);
        Config::set('mail.default', 'smtp');

        (new ThumbnailMeasureKit)->silenceOutbound();

        $this->assertSame('array', config('mail.default'));
        $this->assertFalse(config('app.debug'));
    }

    /**
     * 정리 건수 표의 머리글과 「이전 실행 데이터 삭제」 한 줄이 번역된 이름을 쓰고, 이름이 없는 키는 키 그대로 둔다.
     *
     * @scenario surface=thumbnail_measure_seeders
     *
     * @effects command_output_uses_site_locale
     *
     * @param  string  $locale  사이트 언어
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('locales')]
    public function test_cleanup_labels_and_summary_use_translated_names(string $locale): void
    {
        $this->app->setLocale($locale);
        $labels = __('common.console.seeders.thumbnail_measure.cleanup_headers');

        $this->assertIsArray($labels);
        $this->assertSame(
            [$labels['logo_attachments'], $labels['members'], 'unlisted_key'],
            ThumbnailMeasureKit::labels(['logo_attachments', 'members', 'unlisted_key'], $labels)
        );
        $this->assertSame(
            $labels['logo_attachments'].' 1, '.$labels['members'].' 2',
            ThumbnailMeasureKit::summarize(['logo_attachments' => 1, 'members' => 2], $labels)
        );
        $this->assertNotSame('logo_attachments', $labels['logo_attachments']);
    }

    /**
     * 생성 시더가 찍는 두 표에서 행을 만드는 함수의 열 수가 머리글 수와 같다.
     *
     * @scenario surface=thumbnail_measure_seeders
     *
     * @effects command_output_uses_site_locale
     *
     * @param  string  $locale  사이트 언어
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('locales')]
    public function test_table_rows_match_header_count(string $locale): void
    {
        $this->app->setLocale($locale);

        $user = (object) ['id' => 1, 'email' => ThumbnailMeasureKit::AUTHOR_EMAIL];
        $image = (object) ['id' => 2, 'hash' => 'abcdefabcdef', 'meta' => ['width' => 160, 'height' => 160], 'download_url' => '/x'];

        $this->assertCount(
            count((array) __('common.console.seeders.thumbnail_measure.members_headers')),
            ThumbnailMeasureSeeder::memberRow('author', $user, 'secret', $image)
        );
        $this->assertCount(
            count((array) __('common.console.seeders.thumbnail_measure.logo_headers')),
            ThumbnailMeasureSeeder::logoRow($image, '/backup.json')
        );
    }
}
