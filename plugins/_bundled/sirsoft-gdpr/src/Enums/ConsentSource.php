<?php

namespace Plugins\Sirsoft\Gdpr\Enums;

/**
 * 동의 기록의 발생 경로 (출처)
 *
 * `gdpr_user_consent_histories.source` / `gdpr_user_consents.last_source` 에 저장되는 값의
 * 단일 출처(SSoT)입니다. 관리자 동의 이력 화면의 출처 필터 옵션과 라벨도 이 목록에서 파생합니다.
 *
 * 어휘를 이 enum 밖(FormRequest `in:` 문자열, 서비스/리스너 리터럴)에 흩어 두면
 * 화면 필터가 실제 기록 어휘의 부분집합이 되어 일부 행이 어떤 필터로도 도달하지 못하고,
 * 라벨 키가 없는 값은 목록 셀에 원시 키 문자열로 노출됩니다 (#492 실측 결함).
 *
 * - banner: 쿠키 배너에서 동의/거부
 * - preference_center: 환경설정(선호 센터)에서 변경
 * - register: 회원가입 시 동의 (GdprAuthConsentListener)
 * - mypage: 마이페이지 동의 관리에서 변경
 * - mypage_renew_all: 정책 개정 후 마이페이지에서 일괄 재동의 (GdprConsentService::renewAll)
 */
enum ConsentSource: string
{
    case Banner = 'banner';
    case PreferenceCenter = 'preference_center';
    case Register = 'register';
    case Mypage = 'mypage';
    case MypageRenewAll = 'mypage_renew_all';

    /**
     * 사용자 친화 라벨을 반환합니다.
     *
     * @return string lang 키 해석 결과 (locale 자동 반영)
     */
    public function label(): string
    {
        return __('sirsoft-gdpr::messages.admin.consent_log.source.'.$this->value);
    }

    /**
     * 모든 케이스의 string 값 목록.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 사용자 요청으로 직접 지정할 수 있는 출처 값 목록.
     *
     * `register` / `mypage_renew_all` 은 서버가 스스로 기록하는 경로이므로
     * 공개 요청 본문에서 지정할 수 없습니다.
     *
     * @return array<int, string>
     */
    public static function requestSelectableValues(): array
    {
        return [
            self::Banner->value,
            self::PreferenceCenter->value,
            self::Register->value,
            self::Mypage->value,
        ];
    }
}
