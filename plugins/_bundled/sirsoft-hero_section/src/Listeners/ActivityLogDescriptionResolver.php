<?php

namespace Plugins\Sirsoft\HeroSection\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\LocalizedValue;
use Illuminate\Support\Facades\Lang;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;

/**
 * 히어로섹션 활동 로그 설명 해석기
 *
 * 활동 로그에는 배너 id 만 저장하고, 화면에 보일 때 이름으로 바꿉니다. 이 플러그인의 설명 키만 처리하고
 * 다른 확장의 키는 그대로 돌려줍니다. 해석 순서는 기록 시점 스냅샷(`properties.name`) → DB 의 현재 제목 → 「ID: {id}」 입니다.
 * 목록 한 줄에서 자리를 알아보게 위치 키는 위치 이름으로 바꾸고, 동적 위치면 기록 시점 대상 이름(`properties.target`, 없으면 대상 키)을
 * `target_note` 로 덧붙입니다 — 대상 키가 없는 정적 위치·예전 기록은 빈 글자라 자리 표시가 남지 않습니다.
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
     * 설명 파라미터의 배너 id·위치 키를 사람이 읽을 이름으로 바꾸고 대상 이름을 덧붙입니다.
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

        $params = $this->placement($params, $properties);

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
     * 위치 키를 위치 이름으로 바꾸고 대상 덧붙임(`target_note`)을 채웁니다.
     *
     * @param  array  $params  설명 파라미터
     * @param  array  $properties  로그 기록 시점 속성
     * @return array 위치 이름·대상 덧붙임을 채운 설명 파라미터
     */
    private function placement(array $params, array $properties): array
    {
        if (is_string($params['position'] ?? null) && $params['position'] !== '') {
            $params['position'] = $this->positionName($params['position']);
        }

        $targetKey = (string) ($params['target_key'] ?? '');
        $target = $this->text($properties['target'] ?? null);
        $params['target_note'] = $targetKey === '' ? '' : ' ('.__('sirsoft-hero_section::activity_log.fields.target_key').': '.($target !== '' ? $target : $targetKey).')';
        // 「이 대상 설정」 저장·되돌리기 문구는 `:target_key` 자리에 대상을 적는다 — 게시판 id 같은 내부 키 대신 기록 시점 대상 이름
        if ($targetKey !== '' && $target !== '') {
            $params['target_key'] = $target;
        }

        // 이미지·영상 교체·삭제 문구의 기기 키(pc·mobile)는 기기 이름으로 — 모르는 키는 그대로
        if (is_string($params['device'] ?? null) && Lang::has('sirsoft-hero_section::activity_log.devices.'.$params['device'])) {
            $params['device'] = (string) __('sirsoft-hero_section::activity_log.devices.'.$params['device']);
        }

        return $params;
    }

    /**
     * 위치 이름을 반환합니다 — 언어별 이름으로 등록한 위치는 그 이름, 내장 위치는 문구, 모르는 위치(확장을 껐을 때)는 키 그대로.
     *
     * @param  string  $position  위치 키
     * @return string 위치 이름
     */
    private function positionName(string $position): string
    {
        $label = app(PositionRegistry::class)->find($position)?->label();
        if (is_string($label) && $label !== '') {
            return $label;
        }

        $key = 'sirsoft-hero_section::activity_log.positions.'.$position;

        return Lang::has($key) ? (string) __($key) : $position;
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
