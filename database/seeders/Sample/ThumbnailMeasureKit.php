<?php

namespace Database\Seeders\Sample;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 이미지 썸네일 브라우저 실측 시더의 공용 도구 (이슈 #869).
 *
 * 코어, 게시판, 이커머스의 실측 시더 6개(생성 3, 정리 3)가 같은 가드, 표지, 회원, 이미지 생성을 쓴다.
 * 확장 시더가 이 코어 클래스를 쓰는 이유: 실측 데이터의 작성자 회원은 코어 시더가 만들고, 확장 시더는
 * 그 회원이 있어야만 돈다 — 어차피 코어 시더에 묶인 개발 도구라 표지, 회원 식별을 한 곳에 둔다.
 *
 * 개발 DB 전용 샘플 시더다. `--sample` 묶음에 넣지 않으므로 `--class` 로 지정했을 때만 돈다.
 *
 * 보안 가드 (`PlaywrightSeedLayout` 과 같은 2중 규약):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`. 웹 요청에서 절대 도달 불가
 *   ② 명시 옵트인 — `G7_PLAYWRIGHT_BYPASS=1` 프로세스 환경변수. `.env` 를 고치지 않고 그 호출에만 준다
 *
 * 이미지는 GD 로 그 자리에서 만든다(외부 네트워크 금지). 업로드는 각 도메인의 업로드 서비스를 그대로 지나야
 * 저장소 드라이버의 put, delete 에 붙은 파생본 동기화(`SyncsThumbnails`)까지 확인된다 — 이 도구는 파일만
 * 만들고 저장은 하지 않는다.
 *
 * 콘솔 출력과 예외 문구는 `common.console.seeders.thumbnail_measure.*` 키를 쓴다(확장 시더는 그 확장의
 * `messages.seeders.thumbnail_measure.*`).
 */
final class ThumbnailMeasureKit
{
    /** 모든 실측 행의 제목, 이름에 붙는 표지 — 정리 시더가 이 값으로 대상을 고른다. */
    public const MARK = '[실측-썸네일]';

    /** 작성자 회원 — 비밀글, 리뷰, 클레임의 주인 */
    public const AUTHOR_EMAIL = 'thumb-author@measure.g7.test';

    /** 제3자 회원 — 남의 비밀글, 클레임 이미지가 거부되는지 재는 쪽 */
    public const OTHER_EMAIL = 'thumb-other@measure.g7.test';

    /** 생성 이미지 작업 디렉터리 (storage 기준) */
    private const WORK_DIRECTORY = 'app/measure/tmp';

    /** @var list<string> 이번 실행이 만든 임시 파일 */
    private array $tempFiles = [];

    /**
     * CLI 와 `G7_PLAYWRIGHT_BYPASS=1` 이 아니면 즉시 멈춥니다.
     *
     * 예외를 던지는 이유: `db:seed`, `module:seed` 모두 예외를 실패 종료 코드로 바꾼다. 경고만 찍고 돌아가면
     * 자동화가 「성공」으로 읽는다.
     *
     * @return void 통과하면 아무 것도 하지 않는다
     *
     * @throws RuntimeException 가드 위반
     */
    public static function guard(): void
    {
        if (php_sapi_name() !== 'cli') {
            throw new RuntimeException(__('common.console.seeders.thumbnail_measure.cli_only', ['sapi' => php_sapi_name()]));
        }

        // 여기의 env() 는 `.env` 값이 아니라 호출자가 그 프로세스에 준 환경변수다 — config 로 캡처할 대상이 아니다.
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            throw new RuntimeException(__('common.console.seeders.thumbnail_measure.bypass_required', ['example' => "\$env:G7_PLAYWRIGHT_BYPASS='1'"]));
        }
    }

    /**
     * 이 프로세스의 메일 발송을 메모리로 돌립니다.
     *
     * 게시글 블라인드, 리뷰 작성, 회원 생성 훅이 알림 메일을 보낸다. 개발 PC 의 메일 드라이버가 실제 발송
     * 서비스로 잡혀 있으면 `.test` 주소로 메일이 나간다 — 설정 파일은 건드리지 않고 이 실행에만 적용한다.
     *
     * 디버그 모드는 바꾸지 않는다. `PlaywrightSeedLayout` 류 명령이 `app.debug` 를 켜는 것은 토큰 발급과 디버그 응답이
     * 그 값을 읽기 때문인데, 실측 시더가 지나는 업로드, 저장, 삭제 서비스에는 디버그 모드를 읽는 곳이 없다.
     *
     * @return void 반환값 없음
     */
    public function silenceOutbound(): void
    {
        Config::set('mail.default', 'array');
    }

    /**
     * 값(문자열 또는 다국어 배열)에 표지가 있는지 봅니다.
     *
     * 다국어 컬럼은 저장소에 유니코드 이스케이프로 들어가 있어 원문 문자열 비교로는 찾지 못한다 — 모델이
     * 디코드한 값으로 잰다.
     *
     * @param  mixed  $value  이름, 제목 값
     * @return bool 표지가 있으면 true
     */
    public static function hasMark(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, self::MARK);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::hasMark($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 작업 주체로 쓸 최고관리자를 돌려줍니다 (게시판 생성, 블라인드, 상품 등록의 스코프 판정용).
     *
     * @return User 가장 먼저 만든 최고관리자
     *
     * @throws RuntimeException 최고관리자가 없을 때
     */
    public static function superAdmin(): User
    {
        $admin = User::query()->where('is_super', true)->orderBy('id')->first();

        if ($admin === null) {
            throw new RuntimeException(__('common.console.seeders.thumbnail_measure.no_super_admin'));
        }

        return $admin;
    }

    /**
     * 코어 시더가 만든 실측 회원을 찾습니다.
     *
     * @param  string  $email  AUTHOR_EMAIL 또는 OTHER_EMAIL
     * @return User 회원
     *
     * @throws RuntimeException 코어 시더를 먼저 돌리지 않았을 때
     */
    public static function member(string $email): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            throw new RuntimeException(__('common.console.seeders.thumbnail_measure.member_missing', ['email' => $email, 'seeder' => ThumbnailMeasureSeeder::class]));
        }

        return $user;
    }

    /**
     * 실측 JPEG 를 만듭니다 (무늬가 있는 불투명 그림 — 평균색으로 서로 구분된다).
     *
     * @param  int  $width  가로(px)
     * @param  int  $height  세로(px)
     * @param  array{0:int,1:int,2:int}  $rgb  바탕색
     * @param  string  $label  그림에 크게 새길 영문 표식
     * @return string 임시 파일 절대 경로
     */
    public function jpeg(int $width, int $height, array $rgb, string $label): string
    {
        if (! self::gdAvailable()) {
            throw new RuntimeException(__('common.console.seeders.thumbnail_measure.gd_required'));
        }

        $image = $this->patterned($width, $height, $rgb, $label);
        $path = $this->tempPath('jpg');
        imagejpeg($image, $path, 88);
        imagedestroy($image);

        return $path;
    }

    /**
     * 실측 WebP 를 만듭니다.
     *
     * @param  int  $width  가로(px)
     * @param  int  $height  세로(px)
     * @param  array{0:int,1:int,2:int}  $rgb  바탕색
     * @param  string  $label  표식
     * @return string 임시 파일 절대 경로
     */
    public function webp(int $width, int $height, array $rgb, string $label): string
    {
        if (! self::gdAvailable() || ! function_exists('imagewebp')) {
            throw new RuntimeException(__('common.console.seeders.thumbnail_measure.gd_format_required', ['format' => 'WebP']));
        }

        $image = $this->patterned($width, $height, $rgb, $label);
        $path = $this->tempPath('webp');
        imagewebp($image, $path, 85);
        imagedestroy($image);

        return $path;
    }

    /**
     * 실측 PNG 를 만듭니다.
     *
     * 투명이면 바탕 전체가 알파 0 이고 가운데 원만 불투명하다 — 썸네일에서도 모서리 알파가 0 으로 남는지 그 모서리로 확인한다.
     *
     * @param  int  $width  가로(px)
     * @param  int  $height  세로(px)
     * @param  array{0:int,1:int,2:int}  $rgb  그림 색
     * @param  string  $label  표식
     * @param  bool  $transparent  바탕 투명 여부
     * @return string 임시 파일 절대 경로
     */
    public function png(int $width, int $height, array $rgb, string $label, bool $transparent): string
    {
        if (! self::gdAvailable()) {
            throw new RuntimeException(__('common.console.seeders.thumbnail_measure.gd_required'));
        }

        if (! $transparent) {
            $image = $this->patterned($width, $height, $rgb, $label);
        } else {
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagealphablending($image, true);

            $diameter = (int) (min($width, $height) * 0.7);
            imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), $diameter, $diameter, imagecolorallocate($image, ...$rgb));
            // 반투명 띠 — 알파 합성이 썸네일에서도 살아 있는지 눈으로도 보인다
            imagefilledrectangle($image, 0, intdiv($height, 2) - intdiv($height, 20), $width - 1, intdiv($height, 2) + intdiv($height, 20), imagecolorallocatealpha($image, 255, 255, 255, 80));
            $this->stamp($image, $label, imagecolorallocate($image, 20, 20, 20));
        }

        $path = $this->tempPath('png');
        imagepng($image, $path, 6);
        imagedestroy($image);

        return $path;
    }

    /**
     * 움직이는 GIF 를 만듭니다 (GIF89a, 프레임 여러 장, 무한 반복).
     *
     * GD 의 `imagegif()` 는 한 장짜리만 쓴다. 그래서 프레임마다 한 장짜리 GIF 를 만들고, 그 바이트에서 이미지
     * 블록(이미지 서술자 + LZW 자료)을 떼어 내 한 파일로 다시 엮는다. 각 프레임의 전역 색표는 그 프레임의
     * 지역 색표로 옮긴다 — 프레임마다 색표가 달라도 색이 깨지지 않는다. 썸네일 생성은 이 파일을
     * `bypass:animated` 로 건너뛰어야 한다(응답 바이트가 원본과 같아야 한다).
     *
     * @param  int  $width  가로(px)
     * @param  int  $height  세로(px)
     * @param  int  $frames  프레임 수 (2 이상)
     * @return string 임시 파일 절대 경로
     */
    public function animatedGif(int $width, int $height, int $frames = 3): string
    {
        if (! self::gdAvailable() || ! function_exists('imagegif')) {
            throw new RuntimeException(__('common.console.seeders.thumbnail_measure.gd_format_required', ['format' => 'GIF']));
        }

        $palette = [[220, 60, 60], [60, 160, 80], [60, 90, 220], [230, 180, 40]];
        $chunks = [];

        for ($i = 0; $i < max(2, $frames); $i++) {
            $image = imagecreate($width, $height);
            // 처음 할당한 색이 바탕이 된다
            imagecolorallocate($image, ...$palette[$i % count($palette)]);
            $box = imagecolorallocate($image, 255, 255, 255);
            $step = intdiv($width, max(2, $frames) + 1);
            imagefilledrectangle($image, $step * $i, intdiv($height, 3), $step * $i + $step, intdiv($height, 3) * 2, $box);
            $this->stamp($image, 'GIF FRAME '.($i + 1), imagecolorallocate($image, 0, 0, 0));

            ob_start();
            imagegif($image);
            $chunks[] = (string) ob_get_clean();
            imagedestroy($image);
        }

        $path = $this->tempPath('gif');
        file_put_contents($path, $this->assembleGif($chunks, 40));

        return $path;
    }

    /**
     * SVG 를 만듭니다 (래스터가 아니라 썸네일 대상이 아니다 — `bypass:not-raster`).
     *
     * @param  int  $width  가로(px)
     * @param  int  $height  세로(px)
     * @return string 임시 파일 절대 경로
     */
    public function svg(int $width, int $height): string
    {
        $path = $this->tempPath('svg');
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">'
            .'<rect width="100%" height="100%" fill="#3b82f6"/>'
            .'<circle cx="'.intdiv($width, 2).'" cy="'.intdiv($height, 2).'" r="'.intdiv(min($width, $height), 3).'" fill="#facc15"/>'
            .'<text x="50%" y="90%" font-size="'.max(12, intdiv($height, 12)).'" text-anchor="middle" fill="#ffffff">THUMB MEASURE SVG</text>'
            .'</svg>'."\n");

        return $path;
    }

    /**
     * 임시 파일을 업로드 파일로 감쌉니다 (`test=true` — 실제 HTTP 업로드가 아니어도 isValid 를 통과한다).
     *
     * MIME 은 넘기지 않는다 — 업로드 서비스가 `getMimeType()`(파일 내용 판별)으로 읽는 값이 운영과 같아야 한다.
     *
     * @param  string  $path  임시 파일 경로
     * @param  string  $clientName  브라우저가 보냈을 원래 이름
     * @return UploadedFile 업로드 파일
     */
    public function file(string $path, string $clientName): UploadedFile
    {
        return new UploadedFile($path, $clientName, null, null, true);
    }

    /**
     * 이번 실행이 만든 임시 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function cleanup(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempFiles = [];

        $directory = storage_path(self::WORK_DIRECTORY);
        if (File::isDirectory($directory) && File::files($directory) === []) {
            File::deleteDirectory($directory);
        }
    }

    /**
     * 콘솔에 결과 표를 찍습니다.
     *
     * @param  Command|null  $command  시더의 명령 (없으면 찍지 않는다)
     * @param  string  $title  표 제목
     * @param  list<string>  $headers  머리글
     * @param  list<list<scalar|null>>  $rows  행
     * @return void 반환값 없음
     */
    public static function table(?Command $command, string $title, array $headers, array $rows): void
    {
        if ($command === null) {
            return;
        }

        $command->line('');
        $command->line('<info>'.$title.'</info>');
        $command->table($headers, $rows);
    }

    /**
     * 키 목록을 표시 이름 목록으로 바꿉니다 (정리 건수 표의 머리글).
     *
     * @param  list<string>  $keys  건수 배열의 키
     * @param  array<string, string>|string  $labels  키 ⇒ 표시 이름 (배열을 돌려주는 번역 키의 값)
     * @return list<string> 표시 이름 (이름이 없으면 키 그대로)
     */
    public static function labels(array $keys, array|string $labels): array
    {
        $map = is_array($labels) ? $labels : [];

        return array_map(fn (string $key) => (string) ($map[$key] ?? $key), $keys);
    }

    /**
     * 건수 배열을 「이름 건수」 를 쉼표로 이은 한 줄로 풉니다 (이전 실행 데이터 삭제 안내용).
     *
     * @param  array<string, int|string>  $counts  건수 (키 ⇒ 값)
     * @param  array<string, string>|string  $labels  키 ⇒ 표시 이름 (배열을 돌려주는 번역 키의 값)
     * @return string 이름과 건수를 이은 문자열
     */
    public static function summarize(array $counts, array|string $labels): string
    {
        $names = self::labels(array_keys($counts), $labels);

        return implode(', ', array_map(fn (string $name, $count) => $name.' '.$count, $names, array_values($counts)));
    }

    /**
     * GD 사용 가능 여부.
     *
     * @return bool GD 트루컬러 함수가 있으면 true
     */
    public static function gdAvailable(): bool
    {
        return function_exists('imagecreatetruecolor');
    }

    /**
     * 무늬가 있는 불투명 트루컬러 그림을 만듭니다.
     *
     * 단색 그림은 JPEG 가 수 KB 로 눌려 원본, 파생본 전송량 대조가 무의미해진다. 고정 씨앗의 무작위 원과
     * 격자로 실제 사진에 가까운 압축률을 만든다 — 같은 입력이면 같은 그림이 나온다.
     *
     * @param  int  $width  가로(px)
     * @param  int  $height  세로(px)
     * @param  array{0:int,1:int,2:int}  $rgb  바탕색
     * @param  string  $label  표식
     * @return \GdImage 그림
     */
    private function patterned(int $width, int $height, array $rgb, string $label): \GdImage
    {
        // php-ext-guard: 공개 생성 메서드(jpeg, webp, png)가 gdAvailable() 로 먼저 확인한다
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));

        mt_srand(crc32($label.$width.'x'.$height));
        $shapes = (int) max(40, ($width * $height) / 25000);

        for ($i = 0; $i < $shapes; $i++) {
            $color = imagecolorallocatealpha(
                $image,
                max(0, min(255, $rgb[0] + mt_rand(-90, 90))),
                max(0, min(255, $rgb[1] + mt_rand(-90, 90))),
                max(0, min(255, $rgb[2] + mt_rand(-90, 90))),
                mt_rand(20, 90)
            );
            $size = mt_rand(intdiv(min($width, $height), 40) + 4, intdiv(min($width, $height), 6) + 8);
            imagefilledellipse($image, mt_rand(0, $width), mt_rand(0, $height), $size, $size, $color);
        }

        $grid = imagecolorallocatealpha($image, 0, 0, 0, 100);
        $gap = max(20, intdiv(min($width, $height), 20));
        for ($x = 0; $x < $width; $x += $gap) {
            imageline($image, $x, 0, $x, $height, $grid);
        }
        for ($y = 0; $y < $height; $y += $gap) {
            imageline($image, 0, $y, $width, $y, $grid);
        }

        // 가운데 기준 십자 — 자르기(cover) 기준점이 가운데인지 눈으로 확인한다
        $mark = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, intdiv($width, 2) - 2, 0, intdiv($width, 2) + 2, $height, $mark);
        imagefilledrectangle($image, 0, intdiv($height, 2) - 2, $width, intdiv($height, 2) + 2, $mark);

        $this->stamp($image, $label, imagecolorallocate($image, 255, 255, 255));

        return $image;
    }

    /**
     * 그림 위쪽에 표식을 크게 새깁니다 (작은 내장 글꼴로 쓴 뒤 늘려 붙인다 — 글꼴 파일 없이 읽히는 크기).
     *
     * @param  \GdImage  $image  대상 그림
     * @param  string  $label  영문 표식
     * @param  int  $color  글자 색
     * @return void 반환값 없음
     */
    private function stamp(\GdImage $image, string $label, int $color): void
    {
        // php-ext-guard: 호출하는 생성 메서드가 gdAvailable() 로 먼저 확인한다
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($label) + 4;
        $textHeight = imagefontheight($font) + 4;
        $strip = imagecreatetruecolor($textWidth, $textHeight);
        imagealphablending($strip, false);
        imagesavealpha($strip, true);
        imagefill($strip, 0, 0, imagecolorallocatealpha($strip, 0, 0, 0, 127));
        $rgba = imagecolorsforindex($image, $color);
        imagestring($strip, $font, 2, 2, $label, imagecolorallocate($strip, $rgba['red'], $rgba['green'], $rgba['blue']));

        $targetWidth = (int) (imagesx($image) * 0.8);
        $scale = max(1, $targetWidth / $textWidth);
        $targetHeight = (int) ($textHeight * $scale);
        $x = intdiv(imagesx($image) - $targetWidth, 2);
        $y = (int) (imagesy($image) * 0.08);

        if (imageistruecolor($image)) {
            imagealphablending($image, true);
            imagecopyresampled($image, $strip, $x, $y, 0, 0, $targetWidth, $targetHeight, $textWidth, $textHeight);
        } else {
            // 팔레트 그림(GIF 프레임)은 다시 샘플링하면 색이 늘어난다 — 내장 글꼴 그대로 쓴다
            imagestring($image, $font, max(2, $x), max(2, $y), $label, $color);
        }

        imagedestroy($strip);
    }

    /**
     * 한 장짜리 GIF 여러 개를 움직이는 GIF89a 한 파일로 엮습니다.
     *
     * @param  list<string>  $chunks  프레임별 한 장짜리 GIF 바이트
     * @param  int  $delayCentiseconds  프레임 간격 (1/100초)
     * @return string 움직이는 GIF 바이트
     */
    private function assembleGif(array $chunks, int $delayCentiseconds): string
    {
        $first = $chunks[0];
        $screenPacked = ord($first[10]);
        $out = 'GIF89a'.substr($first, 6, 7);

        if ($screenPacked & 0x80) {
            $out .= substr($first, 13, 3 * (2 ** (($screenPacked & 0x07) + 1)));
        }

        // NETSCAPE2.0 반복 확장 — 반복 횟수 0(무한)
        $out .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

        foreach ($chunks as $chunk) {
            $packed = ord($chunk[10]);
            $tableSize = ($packed & 0x80) ? 3 * (2 ** (($packed & 0x07) + 1)) : 0;
            $table = substr($chunk, 13, $tableSize);
            $pos = 13 + $tableSize;

            // 이미지 서술자(0x2C) 앞의 확장 블록은 버린다 — 프레임마다 아래에서 그래픽 제어 확장을 새로 단다
            while ($pos < strlen($chunk) && $chunk[$pos] === "\x21") {
                $pos += 2;
                while (($length = ord($chunk[$pos])) !== 0) {
                    $pos += $length + 1;
                }
                $pos++;
            }

            if (($chunk[$pos] ?? '') !== "\x2C") {
                throw new RuntimeException(__('common.console.seeders.thumbnail_measure.gif_structure'));
            }

            $descriptor = substr($chunk, $pos, 10);
            $descriptorPacked = ord($descriptor[9]);
            $pos += 10;
            $localTable = '';

            if ($descriptorPacked & 0x80) {
                $localSize = 3 * (2 ** (($descriptorPacked & 0x07) + 1));
                $localTable = substr($chunk, $pos, $localSize);
                $pos += $localSize;
            } elseif ($tableSize > 0) {
                // 전역 색표를 그 프레임의 지역 색표로 옮긴다 (비월 표시 비트는 그대로 둔다)
                $descriptor[9] = chr(($descriptorPacked & 0x40) | 0x80 | ($packed & 0x07));
                $localTable = $table;
            }

            $dataStart = $pos;
            $pos++; // LZW 최소 코드 크기
            while (($length = ord($chunk[$pos])) !== 0) {
                $pos += $length + 1;
            }
            $pos++;

            // 그래픽 제어 확장: 처리 방식 1(그대로 둠), 지연, 투명색 없음
            $out .= "\x21\xF9\x04\x04".pack('v', $delayCentiseconds)."\x00\x00";
            $out .= $descriptor.$localTable.substr($chunk, $dataStart, $pos - $dataStart);
        }

        return $out."\x3B";
    }

    /**
     * 확장자가 붙은 임시 파일 경로를 만듭니다 (MIME 판별이 내용 기준이라 확장자는 이름 표시용이다).
     *
     * 시스템 임시 디렉터리(`sys_get_temp_dir`, `tempnam`)는 호스팅이 막을 수 있는 함수라 쓰지 않고
     * 저장소 안 작업 디렉터리를 쓴다 — `cleanup()` 이 실행 끝에 비운다.
     *
     * @param  string  $extension  확장자
     * @return string 임시 파일 경로
     */
    private function tempPath(string $extension): string
    {
        File::ensureDirectoryExists(storage_path(self::WORK_DIRECTORY));
        $path = storage_path(self::WORK_DIRECTORY.'/'.Str::uuid().'.'.$extension);
        $this->tempFiles[] = $path;

        return $path;
    }
}
