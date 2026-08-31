<?php

namespace App\Extension\Helpers;

/**
 * 백업 디렉토리 이름의 시각 표기 — **만드는 쪽과 지우는 쪽이 같은 곳을 본다.**
 *
 * 이 클래스가 생긴 이유는 그 둘이 실제로 갈렸기 때문이다. 생산자가 같은 초 충돌을
 * 막으려고 이름 끝에 난수를 붙였는데 정리 쪽 정규식은 시각으로 끝나는 형태 그대로여서,
 * 그 뒤로 만들어진 코어 백업이 **정리 대상 목록에 한 번도 들어가지 않았다**. 오류도
 * 경고도 나지 않는다 — 「30일 + 최신 1개」 등급이 그 대상군에서 조용히 사라지고 코어
 * 전량 스냅샷이 실행마다 쌓인다. 디스크 할당량이 작은 공유 호스팅에서는 그것이 곧
 * 사이트 정지다.
 *
 * 그래서 이름의 **모양**을 한 곳에서만 정의하고, 생산자는 `stamp()` 로 만들고 정리
 * 쪽은 `STAMP` 로 판정한다. 한쪽만 바꾸는 것이 구조적으로 불가능해진다.
 */
final class BackupDirectoryName
{
    /**
     * 시각 + 같은 초 충돌 방지 접미사의 정규식 조각 (캡처 없음).
     *
     * 접미사는 **선택**이다 — 이 규약 이전에 만들어져 디스크에 남아 있는 백업
     * (`core_20260601_020202`)도 계속 인식해야 한다. 인식하지 못하면 그것들이 영원히
     * 정리되지 않는데, 그것이 바로 이 클래스가 고치는 결함이다.
     */
    public const STAMP = '\d{8}_\d{6}(?:_[0-9a-f]{8})?';

    /**
     * 백업 디렉토리 이름에 쓸 시각 표기를 만듭니다.
     *
     * 초 단위 시각만으로는 고유하지 않다 — 같은 초에 시작한 두 실행(운영자 재시도 ·
     * cron 중복 · 웹 오케스트레이터의 이중 클릭)이 같은 디렉토리를 백업 대상으로 잡으면,
     * 뒤 실행이 앞 실행이 이미 적용한 **신 버전 디스크**를 그 디렉토리에 덮어써
     * 「업데이트 이전 스냅샷」이라는 백업의 정의가 깨진다. 그 뒤 어느 쪽이 실패해
     * 롤백해도 복원되는 것은 구 버전이 아니다.
     *
     * @return string `Ymd_His_xxxxxxxx` 형태
     */
    public static function stamp(): string
    {
        return date('Ymd_His').'_'.self::randomSuffix();
    }

    /**
     * 이름 전체를 판정하는 정규식을 만듭니다.
     *
     * @param  string  $prefixPattern  시각 앞에 오는 부분의 정규식 조각 (캡처 포함 가능)
     * @return string `preg_match` 에 그대로 넘길 수 있는 완성 패턴
     */
    public static function pattern(string $prefixPattern): string
    {
        return '/^'.$prefixPattern.'('.self::STAMP.')$/';
    }

    /**
     * 이름에서 정렬·비교에 쓸 시각 부분만 돌려줍니다.
     *
     * 접미사는 난수라 정렬 기준이 될 수 없다 — 문자열 정렬에 그대로 쓰면 같은 초에
     * 만들어진 둘의 앞뒤가 내용과 무관하게 갈린다.
     *
     * @param  string  $stamp  `stamp()` 형태 또는 접미사 없는 구형 표기
     * @return string 시각 부분 (`Ymd_His`)
     */
    public static function sortKey(string $stamp): string
    {
        return substr($stamp, 0, 15);
    }

    /**
     * 충돌 방지 접미사 (16진 8자리).
     *
     * `random_bytes()` 는 시스템 엔트로피원이 없으면 던진다. 이 값은 암호학적 용도가
     * 아니라 **같은 초 안에서만 구별되면 되는** 값이므로, 던지는 환경에서는 약한
     * 난수로 내려가는 편이 백업 생성 자체를 실패시키는 것보다 낫다.
     */
    private static function randomSuffix(): string
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return substr(str_pad(dechex(mt_rand(0, 0xFFFFFFFF)), 8, '0', STR_PAD_LEFT), 0, 8);
        }
    }
}
