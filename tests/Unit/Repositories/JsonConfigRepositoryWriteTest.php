<?php

namespace Tests\Unit\Repositories;

use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 설정 JSON 저장 실패 판정 테스트 (#161 F5)
 *
 * 7.1.4 까지 `saveCategory()` 는 잠금을 못 잡아 쓰지 않았어도, 쓰기가 잘렸어도 성공(true)을 돌려 화면이 「저장됨」을 보였는데
 * 값은 그대로였다. JSON 변환이 실패하면 파일을 열고 비운 뒤 `false` 를 써 설정 파일이 빈 채로 남았다. 이제 세 경우 모두
 * `false` 를 돌려주고 기존 파일을 건드리지 않는다. 설정 디스크는 TestCase 가 페이크로 바꿔 둔다.
 */
class JsonConfigRepositoryWriteTest extends TestCase
{
    /**
     * 저장 전 설정 파일 내용
     */
    private const ORIGINAL = "{\n    \"site_name\": \"원래 이름\"\n}";

    /**
     * 저장할 카테고리 — 기본값 파일에 등록된 카테고리여야 저장 경로에 들어간다.
     */
    private const CATEGORY = 'general';

    /**
     * 페이크 설정 디스크에 원래 설정 파일을 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk('settings')->put(self::CATEGORY.'.json', self::ORIGINAL);
        ShortWriteStream::reset();
    }

    /**
     * 파일 잠금을 못 잡으면 쓰지 않고 실패로 답한다 — 종전에는 쓰지 않고도 true 였다.
     *
     * @scenario case=lock_fails
     *
     * @effects settings_save_reports_failure_without_touching_file
     *
     * @return void 반환값 없음
     */
    public function test_save_returns_false_when_flock_fails(): void
    {
        Log::spy();

        $repository = new class extends JsonConfigRepository
        {
            /**
             * 잠금을 언제나 못 잡는다.
             *
             * @param  resource  $handle  열린 파일 핸들
             * @return bool 언제나 false
             */
            protected function lockForWrite($handle): bool
            {
                return false;
            }
        };

        $this->assertTrue($repository->categoryExists(self::CATEGORY), '시험 카테고리가 기본값 파일에 있어야 한다');
        $this->assertFalse($repository->saveCategory(self::CATEGORY, ['site_name' => '새 이름']));
        $this->assertSame(self::ORIGINAL, Storage::disk('settings')->get(self::CATEGORY.'.json'), '파일은 그대로다');
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains((string) $message, '설정 파일 잠금 실패'))
            ->once();
    }

    /**
     * 일부만 기록되면(디스크 가득 등) 실패로 답한다 — 실제 쓰기 판정(`writeLocked`)이 쓴 바이트 수를 JSON 길이와 대조한다.
     *
     * @scenario case=short_write
     *
     * @effects settings_save_reports_failure_on_incomplete_write
     *
     * @return void 반환값 없음
     */
    public function test_save_returns_false_on_short_write(): void
    {
        $repository = $this->repositoryWritingTo(maxBytes: 8, flushResult: true);

        $this->assertFalse($repository->saveCategory(self::CATEGORY, ['site_name' => '새 이름']));
        $this->assertSame(8, strlen(ShortWriteStream::$written), '스트림은 8바이트만 받았다');
        $this->assertSame(self::ORIGINAL, Storage::disk('settings')->get(self::CATEGORY.'.json'));
    }

    /**
     * 끝까지 썼어도 디스크로 내보내지(fflush) 못하면 실패로 답한다.
     *
     * @scenario case=flush_fails
     *
     * @effects settings_save_reports_failure_on_incomplete_write
     *
     * @return void 반환값 없음
     */
    public function test_save_returns_false_when_flush_fails(): void
    {
        $repository = $this->repositoryWritingTo(maxBytes: PHP_INT_MAX, flushResult: false);

        $this->assertFalse($repository->saveCategory(self::CATEGORY, ['site_name' => '새 이름']));
        $this->assertStringContainsString('새 이름', ShortWriteStream::$written, 'JSON 은 끝까지 받았다');
    }

    /**
     * 같은 판정 경로가 정상 스트림에서는 성공을 돌려준다 — 위 두 실패 시험이 판정 자체를 고장 낸 것이 아님을 대조한다.
     *
     * @scenario case=write_ok
     *
     * @effects settings_save_reports_failure_on_incomplete_write
     *
     * @return void 반환값 없음
     */
    public function test_save_returns_true_when_write_completes(): void
    {
        $repository = $this->repositoryWritingTo(maxBytes: PHP_INT_MAX, flushResult: true);

        $this->assertTrue($repository->saveCategory(self::CATEGORY, ['site_name' => '새 이름']));
        $this->assertSame('새 이름', json_decode(ShortWriteStream::$written, true)['site_name'] ?? null);
    }

    /**
     * JSON 변환이 실패하면(잘못된 UTF-8) 파일을 열지 않는다 — 종전에는 파일을 비운 뒤 `false` 를 써 설정 파일이 빈 채로 남았다.
     *
     * @scenario case=json_encode_fails
     *
     * @effects settings_save_reports_failure_without_touching_file
     *
     * @return void 반환값 없음
     */
    public function test_save_returns_false_when_json_encode_fails(): void
    {
        Log::spy();

        $repository = new JsonConfigRepository;

        $this->assertFalse($repository->saveCategory(self::CATEGORY, ['site_name' => "\xB1\x31"]));
        $this->assertSame(self::ORIGINAL, Storage::disk('settings')->get(self::CATEGORY.'.json'), '파일이 비워지지 않는다');
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains((string) $message, '설정 JSON 변환 실패'))
            ->once();
    }

    /**
     * 실제 쓰기 판정(`writeLocked`)을 그대로 쓰되, 쓰기 대상만 시험 스트림으로 바꾼 저장소를 만듭니다.
     *
     * @param  int  $maxBytes  시험 스트림이 받아 줄 최대 바이트 수
     * @param  bool  $flushResult  시험 스트림의 fflush 결과
     * @return JsonConfigRepository 시험 저장소
     */
    private function repositoryWritingTo(int $maxBytes, bool $flushResult): JsonConfigRepository
    {
        ShortWriteStream::$maxBytes = $maxBytes;
        ShortWriteStream::$flushResult = $flushResult;

        return new class extends JsonConfigRepository
        {
            /**
             * 잠긴 실제 파일 대신 시험 스트림에 부모의 쓰기 판정을 그대로 돌린다.
             *
             * @param  resource  $handle  잠긴 파일 핸들 (쓰지 않는다)
             * @param  string  $json  쓸 JSON
             * @return bool 쓰기 성공 여부
             */
            protected function writeLocked($handle, string $json): bool
            {
                $stream = ShortWriteStream::open();

                try {
                    return parent::writeLocked($stream, $json);
                } finally {
                    fclose($stream);
                }
            }
        };
    }
}

/**
 * 시험용 스트림 — 정해진 바이트 수까지만 받고(그 뒤는 0바이트 기록), fflush 결과를 정할 수 있다.
 */
class ShortWriteStream
{
    /** 스트림 프로토콜 이름 */
    private const PROTOCOL = 'i161short';

    /** 받아 줄 최대 바이트 수 */
    public static int $maxBytes = PHP_INT_MAX;

    /** fflush 결과 */
    public static bool $flushResult = true;

    /** 받은 내용 */
    public static string $written = '';

    /** 스트림 컨텍스트 (PHP 가 채운다) */
    public $context;

    /**
     * 시험 상태를 처음으로 되돌립니다.
     *
     * @return void 반환값 없음
     */
    public static function reset(): void
    {
        self::$maxBytes = PHP_INT_MAX;
        self::$flushResult = true;
        self::$written = '';
    }

    /**
     * 프로토콜을 등록하고 시험 스트림을 엽니다.
     *
     * @return resource 시험 스트림 핸들
     */
    public static function open()
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        return fopen(self::PROTOCOL.'://settings', 'c');
    }

    /**
     * 스트림을 엽니다.
     *
     * @param  string  $path  경로
     * @param  string  $mode  모드
     * @param  int  $options  옵션
     * @param  string|null  $openedPath  실제 연 경로
     * @return bool 언제나 true
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    /**
     * 남은 허용량만큼만 받습니다 — 허용량을 다 쓰면 0을 돌려 「더 쓸 수 없음」을 흉내 낸다.
     *
     * @param  string  $data  쓸 내용
     * @return int 받은 바이트 수
     */
    public function stream_write(string $data): int
    {
        $room = max(0, self::$maxBytes - strlen(self::$written));
        $accepted = substr($data, 0, $room);
        self::$written .= $accepted;

        return strlen($accepted);
    }

    /**
     * 내용을 비웁니다.
     *
     * @param  int  $size  남길 크기
     * @return bool 언제나 true
     */
    public function stream_truncate(int $size): bool
    {
        self::$written = substr(self::$written, 0, $size);

        return true;
    }

    /**
     * 정해 둔 fflush 결과를 돌려줍니다.
     *
     * @return bool fflush 결과
     */
    public function stream_flush(): bool
    {
        return self::$flushResult;
    }

    /**
     * 스트림을 닫습니다.
     *
     * @return void 반환값 없음
     */
    public function stream_close(): void {}
}
