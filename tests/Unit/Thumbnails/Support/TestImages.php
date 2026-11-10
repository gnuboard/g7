<?php

namespace Tests\Unit\Thumbnails\Support;

/**
 * 썸네일 시험용 이미지 파일을 만드는 도우미 (GD 로 그려 임시 파일에 쓴다)
 *
 * 엔진 시험(GD·Imagick)과 서빙 시험이 같은 그림을 쓰도록 한 곳에 둔다. 만든 파일은 `cleanup()` 이 지운다.
 */
final class TestImages
{
    /** @var list<string> 만든 임시 파일 */
    private static array $created = [];

    /**
     * 빈 임시 파일 경로를 만듭니다.
     *
     * @param  string  $extension  확장자
     * @return string 경로
     */
    public static function tempPath(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'g7thumbtest_');
        @unlink($base);
        $path = $base.'.'.$extension;
        self::$created[] = $path;

        return $path;
    }

    /**
     * 두 색으로 반씩 칠한 이미지를 만듭니다.
     *
     * @param  int  $width  가로
     * @param  int  $height  세로
     * @param  string  $split  `vertical`(왼쪽/오른쪽) 또는 `horizontal`(위/아래)
     * @param  array{0:int,1:int,2:int}  $first  왼쪽 또는 위 색
     * @param  array{0:int,1:int,2:int}  $second  오른쪽 또는 아래 색
     * @param  string  $format  저장 형식 (png, jpeg, gif, webp)
     * @return string 파일 경로
     */
    public static function halves(int $width, int $height, string $split, array $first, array $second, string $format = 'png'): string
    {
        $image = imagecreatetruecolor($width, $height);
        $a = imagecolorallocate($image, ...$first);
        $b = imagecolorallocate($image, ...$second);

        if ($split === 'vertical') {
            imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, $a);
            imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, $b);
        } else {
            imagefilledrectangle($image, 0, 0, $width - 1, intdiv($height, 2) - 1, $a);
            imagefilledrectangle($image, 0, intdiv($height, 2), $width - 1, $height - 1, $b);
        }

        return self::write($image, $format);
    }

    /**
     * 한 색으로 칠한 이미지를 만듭니다.
     *
     * @param  int  $width  가로
     * @param  int  $height  세로
     * @param  string  $format  저장 형식 (png, jpeg, gif, webp)
     * @return string 파일 경로
     */
    public static function solid(int $width, int $height, string $format = 'png'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));

        return self::write($image, $format);
    }

    /**
     * 모서리는 완전히 투명하고 가운데만 불투명한 PNG 를 만듭니다.
     *
     * @param  int  $width  가로
     * @param  int  $height  세로
     * @return string 파일 경로
     */
    public static function transparentPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle(
            $image,
            intdiv($width, 4), intdiv($height, 4),
            intdiv($width * 3, 4), intdiv($height * 3, 4),
            imagecolorallocatealpha($image, 255, 0, 0, 0)
        );

        return self::write($image, 'png');
    }

    /**
     * EXIF 방향 태그를 단 JPEG 을 만듭니다 (저장된 픽셀은 가로로 긴 그대로, 태그만 회전을 지시).
     *
     * GD 는 EXIF 를 쓰지 못하므로 SOI 바로 뒤에 APP1(Exif) 세그먼트를 직접 끼운다.
     *
     * @param  int  $width  저장 픽셀 가로
     * @param  int  $height  저장 픽셀 세로
     * @param  int  $orientation  EXIF 방향 값 Orientation (1~8)
     * @return string 파일 경로
     */
    public static function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        return self::tagOrientation(self::solid($width, $height, 'jpeg'), $orientation);
    }

    /**
     * 사분면 색 표 — 저장 픽셀의 왼쪽 위·오른쪽 위·왼쪽 아래·오른쪽 아래 색 (JPEG 손실에도 서로 멀리 떨어진 색)
     */
    public const QUADRANT_COLORS = [
        'red' => [230, 20, 20],
        'green' => [20, 200, 20],
        'blue' => [20, 20, 230],
        'yellow' => [240, 240, 20],
    ];

    /**
     * 네 사분면을 다른 색으로 칠하고 EXIF 방향 태그를 단 JPEG 을 만듭니다.
     *
     * 저장 픽셀은 왼쪽 위 red, 오른쪽 위 green, 왼쪽 아래 blue, 오른쪽 아래 yellow 다. 방향마다 세운 뒤의
     * 사분면 배치는 `exifOrientationCases()` 가 정한다 — 회전만 하는 엔진은 뒤집기 방향(2·4·5·7)에서 어긋난다.
     *
     * @param  int  $width  저장 픽셀 가로
     * @param  int  $height  저장 픽셀 세로
     * @param  int  $orientation  EXIF 방향 값 (1~8)
     * @return string 파일 경로
     */
    public static function jpegQuadrantsWithOrientation(int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor($width, $height);
        [$halfW, $halfH] = [intdiv($width, 2), intdiv($height, 2)];
        $colors = array_map(fn (array $rgb) => imagecolorallocate($image, ...$rgb), self::QUADRANT_COLORS);

        imagefilledrectangle($image, 0, 0, $halfW - 1, $halfH - 1, $colors['red']);
        imagefilledrectangle($image, $halfW, 0, $width - 1, $halfH - 1, $colors['green']);
        imagefilledrectangle($image, 0, $halfH, $halfW - 1, $height - 1, $colors['blue']);
        imagefilledrectangle($image, $halfW, $halfH, $width - 1, $height - 1, $colors['yellow']);

        return self::tagOrientation(self::write($image, 'jpeg'), $orientation);
    }

    /**
     * EXIF 방향 8가지와 가로로 긴 저장 그림(왼쪽 위 red, 오른쪽 위 green, 왼쪽 아래 blue, 오른쪽 아래 yellow)을
     * 세운 뒤 보여야 하는 사분면 배치 [왼쪽 위, 오른쪽 위, 왼쪽 아래, 오른쪽 아래] 와 세로로 서는지
     *
     * 배치는 EXIF 규격(TIFF 6.0 Orientation)의 정의를 따른다: 2 좌우 뒤집기, 3 180도, 4 상하 뒤집기,
     * 5 주대각선 전치, 6 시계 방향 90도, 7 반대 대각선 전치, 8 반시계 방향 90도.
     *
     * @return array<string, array{0:int,1:list<string>,2:bool}> 이름 → [방향, 배치, 가로세로가 바뀌는지]
     */
    public static function exifOrientationCases(): array
    {
        return [
            '1 그대로' => [1, ['red', 'green', 'blue', 'yellow'], false],
            '2 좌우 뒤집기' => [2, ['green', 'red', 'yellow', 'blue'], false],
            '3 180도' => [3, ['yellow', 'blue', 'green', 'red'], false],
            '4 상하 뒤집기' => [4, ['blue', 'yellow', 'red', 'green'], false],
            '5 주대각선 전치' => [5, ['red', 'blue', 'green', 'yellow'], true],
            '6 시계 방향 90도' => [6, ['blue', 'red', 'yellow', 'green'], true],
            '7 반대 대각선 전치' => [7, ['yellow', 'green', 'blue', 'red'], true],
            '8 반시계 방향 90도' => [8, ['green', 'yellow', 'red', 'blue'], true],
        ];
    }

    /**
     * 결과 그림의 네 사분면 가운데 점이 각각 어느 사분면 색에 가장 가까운지 [왼쪽 위, 오른쪽 위, 왼쪽 아래, 오른쪽 아래]
     *
     * @param  string  $path  결과 파일 경로
     * @return list<string> 색 이름 4개
     */
    public static function quadrantLayout(string $path): array
    {
        [$width, $height] = self::size($path);
        $points = [
            [intdiv($width, 4), intdiv($height, 4)],
            [intdiv($width * 3, 4), intdiv($height, 4)],
            [intdiv($width, 4), intdiv($height * 3, 4)],
            [intdiv($width * 3, 4), intdiv($height * 3, 4)],
        ];

        return array_map(function (array $point) use ($path): string {
            [$r, $g, $b] = self::pixel($path, $point[0], $point[1]);
            $nearest = '';
            $best = PHP_INT_MAX;

            foreach (self::QUADRANT_COLORS as $name => [$cr, $cg, $cb]) {
                $distance = ($r - $cr) ** 2 + ($g - $cg) ** 2 + ($b - $cb) ** 2;

                if ($distance < $best) {
                    [$best, $nearest] = [$distance, $name];
                }
            }

            return $nearest;
        }, $points);
    }

    /**
     * JPEG 파일에 EXIF 방향 태그를 끼웁니다.
     *
     * @param  string  $path  JPEG 경로 (덮어쓴다)
     * @param  int  $orientation  EXIF 방향 값 (1~8)
     * @return string 같은 경로
     */
    private static function tagOrientation(string $path, int $orientation): string
    {
        $bytes = (string) file_get_contents($path);

        // 빅엔디언 TIFF 헤더 + IFD0 항목 1개(0x0112 Orientation, SHORT, 1개) + 다음 IFD 없음
        $tiff = 'MM'."\x00\x2A"."\x00\x00\x00\x08"
            ."\x00\x01"
            ."\x01\x12"."\x00\x03"."\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00"
            ."\x00\x00\x00\x00";
        $payload = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        file_put_contents($path, substr($bytes, 0, 2).$segment.substr($bytes, 2));

        return $path;
    }

    /**
     * 여러 프레임 GIF 를 바이트로 직접 짭니다 (GD 는 움직이는 GIF 를 쓰지 못한다).
     *
     * 프레임마다 그래픽 제어 확장(GCE) 블록 하나 + 1×1 그림 하나다. `$loopExtension` 이 false 면
     * 반복 표시(NETSCAPE2.0) 블록이 없어 첫 GCE 앞 바이트가 전역 색상표의 마지막 바이트(0xFF)가 된다 —
     * 한 번만 재생하는 GIF 를 흉내 낸다.
     *
     * @param  int  $frames  프레임 수
     * @param  bool  $loopExtension  NETSCAPE2.0 반복 블록을 넣을지
     * @param  int  $padTo  파일을 이 바이트 이상으로 키울지 (주석 확장으로 채움, 0 이면 그대로)
     * @param  int  $screen  논리 화면 한 변(px) — `getimagesize` 가 이 크기를 원본 크기로 읽는다
     * @return string 파일 경로
     */
    public static function gif(int $frames, bool $loopExtension = true, int $padTo = 0, int $screen = 1): string
    {
        $bytes = 'GIF89a'
            .pack('v', $screen).pack('v', $screen)   // 논리 화면 가로·세로
            ."\x80\x00\x00"         // 전역 색상표 2색
            ."\x00\x00\x00\xFF\xFF\xFF";

        if ($loopExtension) {
            $bytes .= "\x21\xFF\x0B".'NETSCAPE2.0'."\x03\x01\x00\x00\x00";
        }

        if ($padTo > 0) {
            // 주석 확장(0x21 0xFE)으로 앞부분을 키워 프레임이 읽기 청크 경계 뒤에 오게 한다
            while (strlen($bytes) < $padTo) {
                $bytes .= "\x21\xFE\xFF".str_repeat('a', 255)."\x00";
            }
        }

        for ($i = 0; $i < $frames; $i++) {
            $bytes .= "\x21\xF9\x04\x00\x0A\x00\x00\x00"   // GCE: 지연 10, 투명 없음
                ."\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00" // 이미지 기술자 1×1
                ."\x02\x02\x44\x01\x00";                    // LZW 최소 코드 2, 데이터 1블록, 종료
        }

        $bytes .= "\x3B";
        $path = self::tempPath('gif');
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * 프레임마다 실제 크기 그림이 든 움직이는 GIF — GD 로 그린 정지 GIF 의 이미지 블록을 그래픽 제어 확장과 함께 되풀이한다.
     *
     * `gif()` 의 프레임은 1×1 이라 첫 프레임 썸네일 크기를 잴 수 없다. 이 함수는 첫 프레임이 화면 전체 크기다.
     *
     * @param  int  $frames  프레임 수
     * @param  int  $width  가로
     * @param  int  $height  세로
     * @return string 파일 경로
     */
    public static function fullFrameGif(int $frames, int $width = 400, int $height = 300): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 160, 30));
        ob_start();
        imagegif($image);
        $still = (string) ob_get_clean();

        $packed = ord($still[10]);
        $headerLength = 13 + (($packed & 0x80) !== 0 ? 3 * (2 ** (($packed & 0x07) + 1)) : 0);
        $blockStart = strpos($still, "\x2C", $headerLength);
        $block = substr($still, (int) $blockStart, strlen($still) - (int) $blockStart - 1);
        $bytes = substr($still, 0, $headerLength)."\x21\xFF\x0B".'NETSCAPE2.0'."\x03\x01\x00\x00\x00";

        for ($i = 0; $i < $frames; $i++) {
            $bytes .= "\x21\xF9\x04\x00\x0A\x00\x00\x00".$block;
        }

        $path = self::tempPath('gif');
        file_put_contents($path, $bytes."\x3B");

        return $path;
    }

    /**
     * 움직이는 PNG(APNG) — GD 로 그린 단색 PNG 에 `acTL`·`fcTL`·`fdAT` 를 끼운다(모든 프레임이 같은 그림).
     *
     * 기본 그림(IDAT)이 첫 프레임이라 APNG 를 모르는 디코더(GD)는 첫 프레임을 읽는다.
     *
     * @param  int  $frames  프레임 수 (`acTL` 의 num_frames)
     * @param  int  $width  가로
     * @param  int  $height  세로
     * @return string 파일 경로
     */
    public static function apng(int $frames, int $width = 400, int $height = 300): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $chunks = [];
        $idat = '';

        for ($offset = 8; $offset + 8 <= strlen($png);) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);
            $chunks[] = [$type, $data];
            $idat .= $type === 'IDAT' ? $data : '';
            $offset += 12 + $length;
        }

        $control = static fn (int $sequence): string => pack('NNNNNnnCC', $sequence, $width, $height, 0, 0, 1, 10, 0, 0);
        $bytes = "\x89PNG\r\n\x1a\n";
        $sequence = 0;

        foreach ($chunks as [$type, $data]) {
            if ($type === 'IHDR') {
                $bytes .= $chunk($type, $data).$chunk('acTL', pack('NN', $frames, 0));
            } elseif ($type === 'IEND') {
                $bytes .= $chunk('fcTL', $control($sequence++)).$chunk('IDAT', $idat);

                for ($i = 1; $i < $frames; $i++) {
                    $bytes .= $chunk('fcTL', $control($sequence++));
                    $bytes .= $chunk('fdAT', pack('N', $sequence++).$idat);
                }

                $bytes .= $chunk('IEND', '');
            } elseif ($type !== 'IDAT') {
                $bytes .= $chunk($type, $data);
            }
        }

        $path = self::tempPath('png');
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * 움직이는 WebP — GD 로 그린 정지 WebP 의 비트스트림을 `ANMF` 프레임으로 감싸 `VP8X`(애니메이션 표시)·`ANIM` 을 붙인다.
     *
     * @param  int  $frames  `ANMF` 프레임 수 (1 이면 애니메이션 표시는 있지만 한 장)
     * @param  int  $width  가로
     * @param  int  $height  세로
     * @return string 파일 경로
     */
    public static function animatedWebp(int $frames, int $width = 400, int $height = 300): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 30, 200));
        ob_start();
        imagewebp($image, null, 80);
        $still = (string) ob_get_clean();

        $riff = static function (string $type, string $data): string {
            return $type.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\x00" : '');
        };
        $u24 = static fn (int $value): string => substr(pack('V', $value), 0, 3);
        $bitstream = '';

        for ($offset = 12; $offset + 8 <= strlen($still);) {
            $type = substr($still, $offset, 4);
            $length = unpack('V', substr($still, $offset + 4, 4))[1];
            $padded = $length + ($length % 2);

            if ($type === 'VP8 ' || $type === 'VP8L') {
                $bitstream = substr($still, $offset, 8 + $padded);
            }

            $offset += 8 + $padded;
        }

        $body = 'WEBP'
            .$riff('VP8X', chr(0x02).$u24(0).$u24($width - 1).$u24($height - 1))
            .$riff('ANIM', pack('V', 0).pack('v', 0));

        for ($i = 0; $i < $frames; $i++) {
            $body .= $riff('ANMF', $u24(0).$u24(0).$u24($width - 1).$u24($height - 1).$u24(100).chr(0).$bitstream);
        }

        $path = self::tempPath('webp');
        file_put_contents($path, 'RIFF'.pack('V', strlen($body)).$body);

        return $path;
    }

    /**
     * 파일의 가로·세로
     *
     * @param  string  $path  경로
     * @return array{0:int,1:int} [가로, 세로]
     */
    public static function size(string $path): array
    {
        $size = getimagesize($path);

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * 파일의 MIME (getimagesize 판정)
     *
     * @param  string  $path  경로
     * @return string 감지한 MIME
     */
    public static function mime(string $path): string
    {
        return (string) (getimagesize($path)['mime'] ?? '');
    }

    /**
     * 한 점의 색 [r, g, b, alpha(0 불투명~127 투명)]
     *
     * @param  string  $path  경로
     * @param  int  $x  가로 좌표
     * @param  int  $y  세로 좌표
     * @return array{0:int,1:int,2:int,3:int} 색
     */
    public static function pixel(string $path, int $x, int $y): array
    {
        $image = imagecreatefromstring((string) file_get_contents($path));
        $rgba = imagecolorat($image, $x, $y);

        if (! imageistruecolor($image)) {
            $color = imagecolorsforindex($image, $rgba);
            imagedestroy($image);

            return [$color['red'], $color['green'], $color['blue'], $color['alpha']];
        }

        imagedestroy($image);

        return [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, ($rgba >> 24) & 0x7F];
    }

    /**
     * 만든 임시 파일을 모두 지웁니다.
     *
     * @return void 반환값 없음
     */
    public static function cleanup(): void
    {
        foreach (self::$created as $path) {
            @unlink($path);
        }

        self::$created = [];
    }

    /**
     * GD 이미지를 형식대로 임시 파일에 씁니다.
     *
     * @param  \GdImage  $image  이미지
     * @param  string  $format  형식
     * @return string 경로
     */
    private static function write(\GdImage $image, string $format): string
    {
        $extension = $format === 'jpeg' ? 'jpg' : $format;
        $path = self::tempPath($extension);

        match ($format) {
            'jpeg' => imagejpeg($image, $path, 95),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path, 95),
            default => imagepng($image, $path),
        };

        imagedestroy($image);

        return $path;
    }
}
