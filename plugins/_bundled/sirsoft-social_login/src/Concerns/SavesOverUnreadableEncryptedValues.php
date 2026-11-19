<?php

namespace Plugins\Sirsoft\SocialLogin\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;

/**
 * 앱 키가 바뀌어 풀 수 없는 암호문 칸에 새 값을 넣어도 저장이 멈추지 않게 합니다.
 *
 * Eloquent 는 encrypted 캐스트 칸이 바뀌었는지 볼 때 옛 암호문을 풀어 새 값과 비교한다. 사이트 앱 키가 바뀌어 옛 값을 풀 수
 * 없으면 그 비교가 DecryptException 을 던져 저장 전체가 실패한다(비밀값 다시 입력, 로그인 때 토큰 갱신). 풀 수 없는 옛 값은
 * 새 값과 같다고 볼 근거가 없으므로 바뀐 것으로 본다 — 프레임워크도 이전 키 목록이 있으면 비교 없이 바뀐 것으로 본다.
 *
 * @since 1.0.0
 */
trait SavesOverUnreadableEncryptedValues
{
    /**
     * 칸의 현재 값이 원본과 같은지 확인합니다 — encrypted 캐스트 칸의 원본 암호문을 풀 수 없으면 다르다고 봅니다.
     *
     * @param  string  $key  칸 이름
     * @return bool 같으면 true
     */
    public function originalIsEquivalent($key)
    {
        if (! $this->isEncryptedCastable($key)) {
            return parent::originalIsEquivalent($key);
        }

        try {
            return parent::originalIsEquivalent($key);
        } catch (DecryptException) {
            return false;
        }
    }
}
