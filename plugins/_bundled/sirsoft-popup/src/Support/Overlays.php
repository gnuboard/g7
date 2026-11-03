<?php

namespace Plugins\Sirsoft\Popup\Support;

use App\Support\LocalizedValue;

/**
 * 이미지 위 글 상자(`popups.overlays`) 값의 한도와 모양 — 검증·저장·공개 응답이 같은 숫자를 쓰도록 한 곳에 둔다.
 *
 * 저장 모양: `{"pc": [상자…], "mobile": [상자…] | null}`. 위치(x·y)·폭(w)·글자 크기(size)는 이미지 폭·높이 대비 % 라
 * 이미지가 커지고 작아지는 만큼 함께 움직인다. 서식은 언어 공통이고 글(`text`)만 언어별이다. `mobile` 이 null 이면 PC 배치를 쓴다.
 * 프런트의 같은 한도는 `resources/js/lib/overlays.ts` 에 있다 — 한쪽만 바꾸지 않는다.
 */
final class Overlays
{
    /** 기기별 최대 상자 수 */
    public const MAX_BOXES = 10;

    /** 상자 글 최대 길이(언어별) */
    public const MAX_TEXT = 200;

    /** 상자 폭 하한(%) */
    public const MIN_WIDTH = 5;

    /** 글자 크기 범위(이미지 폭 대비 %) */
    public const MIN_SIZE = 2;

    public const MAX_SIZE = 20;

    /** 바탕 진하기 상한(%) — 100 이면 바탕이 이미지를 가린다 */
    public const MAX_OPACITY = 90;

    /**
     * 검증된 값을 저장 모양으로 맞춥니다 — 숫자 자리수를 고정하고, 상자가 하나도 없으면 null 입니다.
     *
     * @param  mixed  $overlays  검증된 `overlays`
     * @return array{pc: array<int, array<string, mixed>>, mobile: array<int, array<string, mixed>>|null}|null 저장 값
     */
    public static function normalize(mixed $overlays): ?array
    {
        if (! is_array($overlays)) {
            return null;
        }

        $pc = self::boxes($overlays['pc'] ?? []);
        $mobile = is_array($overlays['mobile'] ?? null) ? self::boxes($overlays['mobile']) : null;

        if ($pc === [] && ($mobile === null || $mobile === [])) {
            return null;
        }

        return ['pc' => $pc, 'mobile' => $mobile];
    }

    /**
     * 공개 응답용 상자 목록을 만듭니다 — 글을 요청 언어(없으면 사이트 기본 언어)로 고른 문자열 하나로 바꾸고, 글이 빈 상자는 뺍니다.
     *
     * @param  mixed  $boxes  저장된 상자 목록
     * @param  string  $locale  요청 언어
     * @return array<int, array<string, mixed>> 공개 상자 목록
     */
    public static function present(mixed $boxes, string $locale): array
    {
        if (! is_array($boxes)) {
            return [];
        }

        $out = [];
        foreach ($boxes as $box) {
            if (! is_array($box)) {
                continue;
            }
            $text = is_array($box['text'] ?? null) ? (string) (LocalizedValue::pick($box['text'], $locale) ?? '') : '';
            if (trim($text) === '') {
                continue;
            }
            $out[] = ['text' => $text] + $box;
        }

        return $out;
    }

    /**
     * 상자 목록의 숫자를 저장 자리수로 맞춥니다(위치·폭 소수 1자리, 글자 크기 0.5 단위, 투명도 정수).
     *
     * @param  mixed  $boxes  검증된 상자 목록
     * @return array<int, array<string, mixed>> 저장 상자 목록
     */
    private static function boxes(mixed $boxes): array
    {
        if (! is_array($boxes)) {
            return [];
        }

        return array_values(array_map(fn (array $box) => [
            'id' => (string) $box['id'],
            'x' => round((float) $box['x'], 1),
            'y' => round((float) $box['y'], 1),
            'w' => round((float) $box['w'], 1),
            'text' => $box['text'],
            'size' => round((float) $box['size'] * 2) / 2,
            'color' => strtoupper((string) $box['color']),
            'bold' => (bool) ($box['bold'] ?? false),
            'align' => (string) $box['align'],
            'bg' => is_array($box['bg'] ?? null)
                ? ['color' => strtoupper((string) $box['bg']['color']), 'opacity' => (int) $box['bg']['opacity']]
                : null,
            'shadow' => (bool) ($box['shadow'] ?? false),
        ], array_filter($boxes, 'is_array')));
    }
}
