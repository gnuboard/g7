<?php

namespace Plugins\Sirsoft\HeroSection\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\LocalizedValue;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;

/**
 * 히어로섹션 활동 로그 설명 해석기
 *
 * 활동 로그에는 배너 id 만 저장하고, 화면에 보일 때 이름으로 바꿉니다. 이 플러그인의 설명 키만 처리하고
 * 다른 확장의 키는 그대로 돌려줍니다. 해석 순서는 기록 시점 스냅샷(`properties.name`) → DB 의 현재 제목 → 「ID: {id}」 입니다.
 */
class ActivityLogDescriptionResolver implements HookListenerInterface
{
    /**
     * 이 플러그인 활동 로그 설명 키의 접두
     */
    private const KEY_PREFIX = 'sirsoft-hero_section::activity_log.description.';

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.activity_log.filter_description_params' => [
                'method' => 'resolveDescriptionParams',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 설명 파라미터의 배너 id 를 사람이 읽을 이름으로 바꿉니다.
     *
     * @param  array  $params  앞선 필터가 넘긴 설명 파라미터
     * @param  string  $descriptionKey  활동 로그 설명 키
     * @param  array  $properties  로그 기록 시점 속성
     * @return array 이름을 채운 설명 파라미터
     */
    public function resolveDescriptionParams(array $params, string $descriptionKey, array $properties = []): array
    {
        if (! str_starts_with($descriptionKey, self::KEY_PREFIX)) {
            return $params;
        }

        if (! empty($params['banner_title']) || ! array_key_exists('banner_id', $params)) {
            return $params;
        }

        $snapshot = $properties['name'] ?? null;
        $name = $this->text($snapshot);

        if ($name === '') {
            $name = $this->text(app(BannerRepositoryInterface::class)->findTitle((int) $params['banner_id']));
        }

        $params['banner_title'] = $name !== '' ? $name : 'ID: '.$params['banner_id'];

        return $params;
    }

    /**
     * 다국어 값(또는 JSON 문자열)에서 현재 언어 글자를 고릅니다.
     *
     * @param  mixed  $value  다국어 배열·JSON 문자열·문자열
     * @return string 고른 글자 (없으면 빈 문자열)
     */
    private function text(mixed $value): string
    {
        if (is_string($value) && str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : $value;
        }

        if (is_array($value)) {
            return (string) (LocalizedValue::pick($value, app()->getLocale()) ?? '');
        }

        return (string) ($value ?? '');
    }
}
