<?php

namespace Modules\Gnuboard7\HelloModule\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 확장 썸네일 규격 등록 리스너 (학습용 데모)
 *
 * 코어 필터 `core.thumbnail.filter_presets` 에 이 모듈의 규격 하나를 더합니다. 규격 이름은
 * `{확장 식별자}.{키}` 형식만 받습니다 — 형식이 어긋난 행은 코어가 조용히 버립니다.
 *
 * 등록한 규격은 환경설정 > 업로드 탭 「썸네일 규격」 표에 이름이 잠긴 행으로 나타나고, 운영자는 값만
 * 고칠 수 있으며 「기본값으로」를 누르면 여기 적은 값으로 돌아갑니다. 모듈을 끄면 표에서 빠지지만
 * 운영자가 고친 값은 설정에 남아 다시 켜면 되살아납니다. 이미지 주소에 `?size=gnuboard7-hello_module.sample`
 * 을 붙이면 이 규격의 썸네일이 나갑니다.
 *
 * 행의 `label` 에 다국어 키를 주면 표가 식별자 대신 그 이름(「Hello 모듈 샘플」)을 보입니다. 코어가 키를 로케일마다
 * 풀어 실으므로 ko·en 은 이 모듈의 lang 파일에, 다른 언어는 언어팩의 같은 키에 둡니다. 생략하면 식별자를 보입니다.
 */
class ThumbnailPresetListener implements HookListenerInterface
{
    /** 이 모듈이 등록하는 규격 이름 */
    public const PRESET = 'gnuboard7-hello_module.sample';

    /** 이 규격의 표시 이름 다국어 키 */
    public const PRESET_LABEL = 'gnuboard7-hello_module::messages.thumbnail.sample_preset_label';

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 구독 설정(method·priority·type)
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.thumbnail.filter_presets' => [
                'method' => 'addPreset',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * Filter 훅은 getSubscribedHooks 에서 지정한 메서드를 직접 호출하므로
     * 이 메서드는 인터페이스 요구사항 충족을 위해서만 존재합니다.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 규격 목록에 이 모듈의 규격을 더합니다 (가로 480 · 세로 270 자르기).
     *
     * 값 키는 코어 고정 슬롯과 같다 — 생략한 키는 코어 기본값(가운데 기준점·원본 형식·확대 끔·
     * 등록 시 생성 끔·숫자 요청 제외·사용)을 쓴다. `label` 은 표시 이름이라 지문·저장 행에 들지 않는다.
     *
     * 앞선 필터가 배열이 아닌 값을 넘기면 손대지 않고 그대로 돌려준다 — 빈 배열로 바꾸면 앞선 리스너가
     * 만든 값을 이 리스너가 지우게 된다. 그 값을 어떻게 다룰지는 코어 레지스트리 몫이다.
     *
     * @param  mixed  $presets  기존 규격 행 목록
     * @return mixed 이 모듈의 규격이 더해진 목록 (배열이 아니면 그대로)
     */
    public function addPreset(mixed $presets): mixed
    {
        if (! is_array($presets)) {
            return $presets;
        }

        $presets[] = [
            'name' => self::PRESET,
            'label' => self::PRESET_LABEL,
            'width' => 480,
            'height' => 270,
            'fit' => 'cover',
            'anchor' => 'center',
        ];

        return $presets;
    }
}
