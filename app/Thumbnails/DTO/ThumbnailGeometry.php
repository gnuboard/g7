<?php

namespace App\Thumbnails\DTO;

/**
 * 규격과 원본 크기로 잘라낼 영역·결과 크기를 계산합니다 (엔진 공용).
 *
 * GD·Imagick 이 같은 결과 크기를 내야 엔진을 바꿔도 화면 배치가 흔들리지 않는다 — 계산은 이 한 곳에 둔다.
 */
final readonly class ThumbnailGeometry
{
    /**
     * ThumbnailGeometry 생성자
     *
     * @param  int  $srcX  원본에서 잘라낼 시작 x
     * @param  int  $srcY  원본에서 잘라낼 시작 y
     * @param  int  $srcW  원본에서 잘라낼 가로
     * @param  int  $srcH  원본에서 잘라낼 세로
     * @param  int  $dstW  결과 가로
     * @param  int  $dstH  결과 세로
     */
    public function __construct(
        public int $srcX,
        public int $srcY,
        public int $srcW,
        public int $srcH,
        public int $dstW,
        public int $dstH,
    ) {}

    /**
     * 규격과 원본 크기로 영역을 계산합니다.
     *
     * 확대를 끈 규격(`upscale=false`)은 배율을 1 로 묶는다 — 원본보다 큰 결과를 만들지 않는다. 자르기(cover)는
     * 그때도 규격 비율을 지킨다(원본 안 규격 비율 최대 영역을 1:1 로).
     *
     * @param  int  $width  원본 가로
     * @param  int  $height  원본 세로
     * @param  ThumbnailSpec  $spec  규격
     * @return self 계산 결과
     */
    public static function compute(int $width, int $height, ThumbnailSpec $spec): self
    {
        $width = max(1, $width);
        $height = max(1, $height);
        $targetW = $spec->width;
        $targetH = $spec->height ?? 0;

        if ($spec->fit === ThumbnailSpec::FIT_COVER && $targetH > 0) {
            $scale = max($targetW / $width, $targetH / $height);

            if ($spec->upscale || $scale <= 1.0) {
                // 결과 크기는 규격 그대로
                $dstW = $targetW;
                $dstH = $targetH;
                $cropW = max(1, min($width, (int) round($dstW / $scale)));
                $cropH = max(1, min($height, (int) round($dstH / $scale)));
            } else {
                // 확대 금지로 배율이 1 에 묶이면 원본 안에서 규격 비율로 가장 크게 잘라 1:1 로 낸다 — 두 변을 따로
                // 줄이면(800×800 → 1200×630 규격에서 800×630) 결과가 규격 비율을 잃는다.
                $ratio = $targetW / $targetH;

                if ($width / $height > $ratio) {
                    $cropH = $height;
                    $cropW = max(1, min($width, (int) round($height * $ratio)));
                } else {
                    $cropW = $width;
                    $cropH = max(1, min($height, (int) round($width / $ratio)));
                }

                $dstW = $cropW;
                $dstH = $cropH;
            }

            [$x, $y] = self::anchorOffset($spec->anchor, $width - $cropW, $height - $cropH);

            return new self($x, $y, $cropW, $cropH, $dstW, $dstH);
        }

        if ($spec->fit === ThumbnailSpec::FIT_CONTAIN && $targetH > 0) {
            $scale = min($targetW / $width, $targetH / $height);
        } else {
            $scale = $targetW / $width;
        }

        if (! $spec->upscale) {
            $scale = min($scale, 1.0);
        }

        return new self(
            0,
            0,
            $width,
            $height,
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        );
    }

    /**
     * 기준점에 따른 자르기 시작점을 돌려줍니다.
     *
     * @param  string  $anchor  기준점 (center·top·bottom·left·right)
     * @param  int  $spareX  가로 여유 (원본 − 자를 폭)
     * @param  int  $spareY  세로 여유
     * @return array{0: int, 1: int} [x, y]
     */
    private static function anchorOffset(string $anchor, int $spareX, int $spareY): array
    {
        $centerX = intdiv(max(0, $spareX), 2);
        $centerY = intdiv(max(0, $spareY), 2);

        return match ($anchor) {
            'top' => [$centerX, 0],
            'bottom' => [$centerX, max(0, $spareY)],
            'left' => [0, $centerY],
            'right' => [max(0, $spareX), $centerY],
            default => [$centerX, $centerY],
        };
    }

    /**
     * 진단·로그에 그대로 넘길 수 있는 배열로 돌려줍니다.
     *
     * @return array{src_x: int, src_y: int, src_w: int, src_h: int, dst_w: int, dst_h: int} 계산 값
     */
    public function toArray(): array
    {
        return [
            'src_x' => $this->srcX,
            'src_y' => $this->srcY,
            'src_w' => $this->srcW,
            'src_h' => $this->srcH,
            'dst_w' => $this->dstW,
            'dst_h' => $this->dstH,
        ];
    }
}
