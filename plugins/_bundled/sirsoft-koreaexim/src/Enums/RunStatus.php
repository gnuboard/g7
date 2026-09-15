<?php

namespace Plugins\Sirsoft\Koreaexim\Enums;

/** 환율 RunStatus 분류. */
enum RunStatus: string
{
    case Success = 'success';
    case Partial = 'partial';
    case NoData = 'no_data';
    case Skipped = 'skipped';
    case DryRun = 'dry_run';
    case AuthMissing = 'auth_missing';
    case AuthError = 'auth_error';
    case QuotaExceeded = 'quota_exceeded';
    case BadRequest = 'bad_request';
    case BadResponse = 'bad_response';
    case NetworkError = 'network_error';
    case UnsupportedBase = 'unsupported_base';

    /**
     * 화면에 표시할 번역 라벨을 반환합니다.
     *
     * @return string 처리 결과
     */
    public function label(): string
    {
        return __('sirsoft-koreaexim::messages.RunStatus.'.$this->value);
    }
}
