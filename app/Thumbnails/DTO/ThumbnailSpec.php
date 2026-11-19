<?php

namespace App\Thumbnails\DTO;

/**
 * 썸네일 규격 값 객체
 *
 * 규격 하나가 「어떤 크기·맞춤·형식으로 줄일지」를 담는다. 출처는 세 갈래다 —
 * 코어 고정 슬롯(`core`, xs~xl 과 og), 확장 등록(확장 식별자), 운영자 추가(`custom`).
 * 고정·확장 규격은 이름이 잠겨(`locked`) 값만 고칠 수 있고, 운영자 규격만 이름을 바꾸거나 지울 수 있다.
 *
 * 규격 지문(`fingerprint()`)은 파생본 파일명에 들어간다. 값을 바꾸면 지문이 바뀌어 새 파일을 만들고,
 * 옛 파일은 정리 명령(`thumbnails:prune`)이 지운다 — 캐시 무효화를 파일명 하나로 끝낸다.
 *
 * 확장 규격의 사람이 읽는 이름(등록 행의 `label`)은 이 객체에 담지 않는다. 결과 그림과 무관한 화면 표시값이라
 * 저장 행·지문에 들면 안 되고, 늘 확장 등록에서 다시 읽어야 언어팩·확장 갱신이 바로 반영된다 —
 * 관리자 표(`ThumbnailPresetSettings::presentRows()`)가 등록 행에서 바로 풀어 싣는다.
 */
final readonly class ThumbnailSpec
{
    /** 맞춤 방식: 지정 크기를 꽉 채우고 넘치는 부분을 자른다 */
    public const FIT_COVER = 'cover';

    /** 맞춤 방식: 지정 크기 안에 전체가 들어가게 줄인다 */
    public const FIT_CONTAIN = 'contain';

    /** 맞춤 방식: 가로만 맞추고 세로는 비율대로 */
    public const FIT_WIDTH = 'width';

    /** @var list<string> 허용 맞춤 방식 */
    public const FITS = [self::FIT_COVER, self::FIT_CONTAIN, self::FIT_WIDTH];

    /** @var list<string> 허용 자르기 기준점 */
    public const ANCHORS = ['center', 'top', 'bottom', 'left', 'right'];

    /** @var list<string> 허용 출력 형식 (`original` = 원본 형식 유지) */
    public const FORMATS = ['original', 'webp', 'avif'];

    /** 출처: 코어 */
    public const SOURCE_CORE = 'core';

    /** 출처: 운영자 추가 */
    public const SOURCE_CUSTOM = 'custom';

    /**
     * ThumbnailSpec 생성자
     *
     * @param  string  $name  규격 이름 (요청의 `?size=` 값)
     * @param  string  $source  출처 (`core`·`custom`·확장 식별자)
     * @param  int  $width  가로 (px)
     * @param  int|null  $height  세로 (px, `width` 맞춤이면 null 가능)
     * @param  string  $fit  맞춤 방식 (cover·contain·width)
     * @param  string  $anchor  자르기 기준점
     * @param  int|null  $quality  품질 1~100 (null 이면 업로드 탭 이미지 품질)
     * @param  string  $format  출력 형식 (original·webp·avif)
     * @param  bool  $upscale  원본이 규격보다 작을 때 확대할지
     * @param  bool  $pregenerate  원본 등록 직후 미리 만들지
     * @param  bool  $numeric  `?w=` 숫자 요청의 후보인지
     * @param  bool  $enabled  사용 여부
     * @param  bool  $locked  이름 잠금 (고정·확장 규격)
     */
    public function __construct(
        public string $name,
        public string $source,
        public int $width,
        public ?int $height,
        public string $fit,
        public string $anchor = 'center',
        public ?int $quality = null,
        public string $format = 'original',
        public bool $upscale = false,
        public bool $pregenerate = false,
        public bool $numeric = false,
        public bool $enabled = true,
        public bool $locked = false,
    ) {}

    /**
     * 설정 행(배열)에서 규격을 만듭니다. 잘못된 값은 안전한 기본값으로 접습니다.
     *
     * @param  array<string, mixed>  $row  설정 행
     * @param  string|null  $source  출처를 강제할 때 (null 이면 행의 `source`, 없으면 custom)
     * @param  bool|null  $locked  잠금을 강제할 때
     * @return self|null 이름·가로가 없으면 null
     */
    public static function fromArray(array $row, ?string $source = null, ?bool $locked = null): ?self
    {
        $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
        $width = is_numeric($row['width'] ?? null) ? (int) $row['width'] : 0;

        if ($name === '' || $width <= 0) {
            return null;
        }

        $height = is_numeric($row['height'] ?? null) && (int) $row['height'] > 0 ? (int) $row['height'] : null;
        $fit = in_array($row['fit'] ?? null, self::FITS, true) ? $row['fit'] : self::FIT_WIDTH;

        // 세로가 없으면 자르기·맞추기가 성립하지 않는다 — 가로만 맞춤으로 접는다.
        if ($height === null) {
            $fit = self::FIT_WIDTH;
        }

        $quality = is_numeric($row['quality'] ?? null) ? max(1, min(100, (int) $row['quality'])) : null;
        $resolvedSource = $source ?? (is_string($row['source'] ?? null) && $row['source'] !== '' ? $row['source'] : self::SOURCE_CUSTOM);

        return new self(
            name: $name,
            source: $resolvedSource,
            width: $width,
            height: $height,
            fit: $fit,
            anchor: in_array($row['anchor'] ?? null, self::ANCHORS, true) ? $row['anchor'] : 'center',
            quality: $quality,
            format: in_array($row['format'] ?? null, self::FORMATS, true) ? $row['format'] : 'original',
            upscale: (bool) ($row['upscale'] ?? false),
            pregenerate: (bool) ($row['pregenerate'] ?? false),
            numeric: (bool) ($row['numeric'] ?? false),
            enabled: (bool) ($row['enabled'] ?? true),
            locked: $locked ?? (bool) ($row['locked'] ?? false),
        );
    }

    /**
     * 설정 행 형태로 돌려줍니다 (저장·화면 공용).
     *
     * @return array<string, mixed> 설정 행
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'source' => $this->source,
            'width' => $this->width,
            'height' => $this->height,
            'fit' => $this->fit,
            'anchor' => $this->anchor,
            'quality' => $this->quality,
            'format' => $this->format,
            'upscale' => $this->upscale,
            'pregenerate' => $this->pregenerate,
            'numeric' => $this->numeric,
            'enabled' => $this->enabled,
            'locked' => $this->locked,
        ];
    }

    /**
     * 실제 인코딩에 쓸 품질을 돌려줍니다.
     *
     * @return int 1~100
     */
    public function effectiveQuality(): int
    {
        if ($this->quality !== null) {
            return $this->quality;
        }

        $fallback = (int) config('attachment.image_quality', 85);

        return max(1, min(100, $fallback > 0 ? $fallback : 85));
    }

    /**
     * 규격 지문 — 정규화한 속성과 엔진 id 의 sha1 앞 8자.
     *
     * 이름·사용 여부·미리 생성·숫자 요청은 결과 그림에 영향이 없어 넣지 않는다(바꿔도 파일을 다시 만들 이유가 없다).
     * 엔진을 넣는 이유: GD 와 Imagick 은 같은 규격으로도 결과 바이트가 달라, 엔진을 바꾸면 새로 만들어야 한다.
     *
     * 움직이는 원본의 첫 프레임 썸네일은 끝에 `first-frame` 을 더한 다른 지문을 쓴다 — 「움직이는 이미지는 원본」 판정을
     * 다시 켰을 때 정지 이미지 키로 첫 프레임 썸네일이 나가지 않게(`ThumbnailGenerator`). 정지 이미지 지문은 그대로다.
     *
     * @param  string  $engineId  엔진 id (gd·imagick 등)
     * @param  bool  $firstFrame  움직이는 원본의 첫 프레임 썸네일 지문인지
     * @return string 8자 16진수
     */
    public function fingerprint(string $engineId, bool $firstFrame = false): string
    {
        $parts = [
            $this->width,
            $this->height ?? '',
            $this->fit,
            $this->anchor,
            $this->effectiveQuality(),
            $this->format,
            $this->upscale ? '1' : '0',
            $engineId,
        ];

        if ($firstFrame) {
            $parts[] = 'first-frame';
        }

        return substr(sha1(implode('|', $parts)), 0, 8);
    }

    /**
     * 원본이 이 규격 안에 들어가 줄일 것이 없는지 판정합니다.
     *
     * @param  int  $sourceWidth  원본 가로
     * @param  int  $sourceHeight  원본 세로
     * @return bool 원본이 규격 이하면 true
     */
    public function containsSource(int $sourceWidth, int $sourceHeight): bool
    {
        if ($this->fit === self::FIT_WIDTH) {
            return $sourceWidth <= $this->width;
        }

        return $sourceWidth <= $this->width && $sourceHeight <= (int) $this->height;
    }
}
