<?php

// rate-calculation.yaml의 입력 조합. 각 조합은 계산기 테스트 루프에서 실제 단언합니다.
return [
    /** @scenario base=KRW, target=USD, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['KRW', 'USD', 'deal_bas_r', 'normal', 0],
    /** @scenario base=KRW, target=USD, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['KRW', 'USD', 'deal_bas_r', 'normal', 2],
    /** @scenario base=KRW, target=USD, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['KRW', 'USD', 'deal_bas_r', 'normal', 6],
    /** @scenario base=KRW, target=USD, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['KRW', 'USD', 'deal_bas_r', 'zero', 0],
    /** @scenario base=KRW, target=USD, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['KRW', 'USD', 'deal_bas_r', 'zero', 2],
    /** @scenario base=KRW, target=USD, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['KRW', 'USD', 'deal_bas_r', 'zero', 6],
    /** @scenario base=KRW, target=USD, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['KRW', 'USD', 'deal_bas_r', 'blank', 0],
    /** @scenario base=KRW, target=USD, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['KRW', 'USD', 'deal_bas_r', 'blank', 2],
    /** @scenario base=KRW, target=USD, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['KRW', 'USD', 'deal_bas_r', 'blank', 6],
    /** @scenario base=KRW, target=USD, rate_type=ttb, rate_value=normal, decimals=0 */
    ['KRW', 'USD', 'ttb', 'normal', 0],
    /** @scenario base=KRW, target=USD, rate_type=ttb, rate_value=normal, decimals=2 */
    ['KRW', 'USD', 'ttb', 'normal', 2],
    /** @scenario base=KRW, target=USD, rate_type=ttb, rate_value=normal, decimals=6 */
    ['KRW', 'USD', 'ttb', 'normal', 6],
    /** @scenario base=KRW, target=USD, rate_type=ttb, rate_value=zero, decimals=0 */
    ['KRW', 'USD', 'ttb', 'zero', 0],
    /** @scenario base=KRW, target=USD, rate_type=ttb, rate_value=zero, decimals=2 */
    ['KRW', 'USD', 'ttb', 'zero', 2],
    /** @scenario base=KRW, target=USD, rate_type=ttb, rate_value=zero, decimals=6 */
    ['KRW', 'USD', 'ttb', 'zero', 6],
    /** @scenario base=KRW, target=USD, rate_type=ttb, rate_value=blank, decimals=0 */
    ['KRW', 'USD', 'ttb', 'blank', 0],
    /** @scenario base=KRW, target=USD, rate_type=ttb, rate_value=blank, decimals=2 */
    ['KRW', 'USD', 'ttb', 'blank', 2],
    /** @scenario base=KRW, target=USD, rate_type=ttb, rate_value=blank, decimals=6 */
    ['KRW', 'USD', 'ttb', 'blank', 6],
    /** @scenario base=KRW, target=USD, rate_type=tts, rate_value=normal, decimals=0 */
    ['KRW', 'USD', 'tts', 'normal', 0],
    /** @scenario base=KRW, target=USD, rate_type=tts, rate_value=normal, decimals=2 */
    ['KRW', 'USD', 'tts', 'normal', 2],
    /** @scenario base=KRW, target=USD, rate_type=tts, rate_value=normal, decimals=6 */
    ['KRW', 'USD', 'tts', 'normal', 6],
    /** @scenario base=KRW, target=USD, rate_type=tts, rate_value=zero, decimals=0 */
    ['KRW', 'USD', 'tts', 'zero', 0],
    /** @scenario base=KRW, target=USD, rate_type=tts, rate_value=zero, decimals=2 */
    ['KRW', 'USD', 'tts', 'zero', 2],
    /** @scenario base=KRW, target=USD, rate_type=tts, rate_value=zero, decimals=6 */
    ['KRW', 'USD', 'tts', 'zero', 6],
    /** @scenario base=KRW, target=USD, rate_type=tts, rate_value=blank, decimals=0 */
    ['KRW', 'USD', 'tts', 'blank', 0],
    /** @scenario base=KRW, target=USD, rate_type=tts, rate_value=blank, decimals=2 */
    ['KRW', 'USD', 'tts', 'blank', 2],
    /** @scenario base=KRW, target=USD, rate_type=tts, rate_value=blank, decimals=6 */
    ['KRW', 'USD', 'tts', 'blank', 6],
    /** @scenario base=KRW, target=JPY, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['KRW', 'JPY', 'deal_bas_r', 'normal', 0],
    /** @scenario base=KRW, target=JPY, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['KRW', 'JPY', 'deal_bas_r', 'normal', 2],
    /** @scenario base=KRW, target=JPY, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['KRW', 'JPY', 'deal_bas_r', 'normal', 6],
    /** @scenario base=KRW, target=JPY, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['KRW', 'JPY', 'deal_bas_r', 'zero', 0],
    /** @scenario base=KRW, target=JPY, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['KRW', 'JPY', 'deal_bas_r', 'zero', 2],
    /** @scenario base=KRW, target=JPY, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['KRW', 'JPY', 'deal_bas_r', 'zero', 6],
    /** @scenario base=KRW, target=JPY, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['KRW', 'JPY', 'deal_bas_r', 'blank', 0],
    /** @scenario base=KRW, target=JPY, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['KRW', 'JPY', 'deal_bas_r', 'blank', 2],
    /** @scenario base=KRW, target=JPY, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['KRW', 'JPY', 'deal_bas_r', 'blank', 6],
    /** @scenario base=KRW, target=JPY, rate_type=ttb, rate_value=normal, decimals=0 */
    ['KRW', 'JPY', 'ttb', 'normal', 0],
    /** @scenario base=KRW, target=JPY, rate_type=ttb, rate_value=normal, decimals=2 */
    ['KRW', 'JPY', 'ttb', 'normal', 2],
    /** @scenario base=KRW, target=JPY, rate_type=ttb, rate_value=normal, decimals=6 */
    ['KRW', 'JPY', 'ttb', 'normal', 6],
    /** @scenario base=KRW, target=JPY, rate_type=ttb, rate_value=zero, decimals=0 */
    ['KRW', 'JPY', 'ttb', 'zero', 0],
    /** @scenario base=KRW, target=JPY, rate_type=ttb, rate_value=zero, decimals=2 */
    ['KRW', 'JPY', 'ttb', 'zero', 2],
    /** @scenario base=KRW, target=JPY, rate_type=ttb, rate_value=zero, decimals=6 */
    ['KRW', 'JPY', 'ttb', 'zero', 6],
    /** @scenario base=KRW, target=JPY, rate_type=ttb, rate_value=blank, decimals=0 */
    ['KRW', 'JPY', 'ttb', 'blank', 0],
    /** @scenario base=KRW, target=JPY, rate_type=ttb, rate_value=blank, decimals=2 */
    ['KRW', 'JPY', 'ttb', 'blank', 2],
    /** @scenario base=KRW, target=JPY, rate_type=ttb, rate_value=blank, decimals=6 */
    ['KRW', 'JPY', 'ttb', 'blank', 6],
    /** @scenario base=KRW, target=JPY, rate_type=tts, rate_value=normal, decimals=0 */
    ['KRW', 'JPY', 'tts', 'normal', 0],
    /** @scenario base=KRW, target=JPY, rate_type=tts, rate_value=normal, decimals=2 */
    ['KRW', 'JPY', 'tts', 'normal', 2],
    /** @scenario base=KRW, target=JPY, rate_type=tts, rate_value=normal, decimals=6 */
    ['KRW', 'JPY', 'tts', 'normal', 6],
    /** @scenario base=KRW, target=JPY, rate_type=tts, rate_value=zero, decimals=0 */
    ['KRW', 'JPY', 'tts', 'zero', 0],
    /** @scenario base=KRW, target=JPY, rate_type=tts, rate_value=zero, decimals=2 */
    ['KRW', 'JPY', 'tts', 'zero', 2],
    /** @scenario base=KRW, target=JPY, rate_type=tts, rate_value=zero, decimals=6 */
    ['KRW', 'JPY', 'tts', 'zero', 6],
    /** @scenario base=KRW, target=JPY, rate_type=tts, rate_value=blank, decimals=0 */
    ['KRW', 'JPY', 'tts', 'blank', 0],
    /** @scenario base=KRW, target=JPY, rate_type=tts, rate_value=blank, decimals=2 */
    ['KRW', 'JPY', 'tts', 'blank', 2],
    /** @scenario base=KRW, target=JPY, rate_type=tts, rate_value=blank, decimals=6 */
    ['KRW', 'JPY', 'tts', 'blank', 6],
    /** @scenario base=KRW, target=KRW, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['KRW', 'KRW', 'deal_bas_r', 'normal', 0],
    /** @scenario base=KRW, target=KRW, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['KRW', 'KRW', 'deal_bas_r', 'normal', 2],
    /** @scenario base=KRW, target=KRW, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['KRW', 'KRW', 'deal_bas_r', 'normal', 6],
    /** @scenario base=KRW, target=KRW, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['KRW', 'KRW', 'deal_bas_r', 'zero', 0],
    /** @scenario base=KRW, target=KRW, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['KRW', 'KRW', 'deal_bas_r', 'zero', 2],
    /** @scenario base=KRW, target=KRW, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['KRW', 'KRW', 'deal_bas_r', 'zero', 6],
    /** @scenario base=KRW, target=KRW, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['KRW', 'KRW', 'deal_bas_r', 'blank', 0],
    /** @scenario base=KRW, target=KRW, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['KRW', 'KRW', 'deal_bas_r', 'blank', 2],
    /** @scenario base=KRW, target=KRW, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['KRW', 'KRW', 'deal_bas_r', 'blank', 6],
    /** @scenario base=KRW, target=KRW, rate_type=ttb, rate_value=normal, decimals=0 */
    ['KRW', 'KRW', 'ttb', 'normal', 0],
    /** @scenario base=KRW, target=KRW, rate_type=ttb, rate_value=normal, decimals=2 */
    ['KRW', 'KRW', 'ttb', 'normal', 2],
    /** @scenario base=KRW, target=KRW, rate_type=ttb, rate_value=normal, decimals=6 */
    ['KRW', 'KRW', 'ttb', 'normal', 6],
    /** @scenario base=KRW, target=KRW, rate_type=ttb, rate_value=zero, decimals=0 */
    ['KRW', 'KRW', 'ttb', 'zero', 0],
    /** @scenario base=KRW, target=KRW, rate_type=ttb, rate_value=zero, decimals=2 */
    ['KRW', 'KRW', 'ttb', 'zero', 2],
    /** @scenario base=KRW, target=KRW, rate_type=ttb, rate_value=zero, decimals=6 */
    ['KRW', 'KRW', 'ttb', 'zero', 6],
    /** @scenario base=KRW, target=KRW, rate_type=ttb, rate_value=blank, decimals=0 */
    ['KRW', 'KRW', 'ttb', 'blank', 0],
    /** @scenario base=KRW, target=KRW, rate_type=ttb, rate_value=blank, decimals=2 */
    ['KRW', 'KRW', 'ttb', 'blank', 2],
    /** @scenario base=KRW, target=KRW, rate_type=ttb, rate_value=blank, decimals=6 */
    ['KRW', 'KRW', 'ttb', 'blank', 6],
    /** @scenario base=KRW, target=KRW, rate_type=tts, rate_value=normal, decimals=0 */
    ['KRW', 'KRW', 'tts', 'normal', 0],
    /** @scenario base=KRW, target=KRW, rate_type=tts, rate_value=normal, decimals=2 */
    ['KRW', 'KRW', 'tts', 'normal', 2],
    /** @scenario base=KRW, target=KRW, rate_type=tts, rate_value=normal, decimals=6 */
    ['KRW', 'KRW', 'tts', 'normal', 6],
    /** @scenario base=KRW, target=KRW, rate_type=tts, rate_value=zero, decimals=0 */
    ['KRW', 'KRW', 'tts', 'zero', 0],
    /** @scenario base=KRW, target=KRW, rate_type=tts, rate_value=zero, decimals=2 */
    ['KRW', 'KRW', 'tts', 'zero', 2],
    /** @scenario base=KRW, target=KRW, rate_type=tts, rate_value=zero, decimals=6 */
    ['KRW', 'KRW', 'tts', 'zero', 6],
    /** @scenario base=KRW, target=KRW, rate_type=tts, rate_value=blank, decimals=0 */
    ['KRW', 'KRW', 'tts', 'blank', 0],
    /** @scenario base=KRW, target=KRW, rate_type=tts, rate_value=blank, decimals=2 */
    ['KRW', 'KRW', 'tts', 'blank', 2],
    /** @scenario base=KRW, target=KRW, rate_type=tts, rate_value=blank, decimals=6 */
    ['KRW', 'KRW', 'tts', 'blank', 6],
    /** @scenario base=KRW, target=CNY, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['KRW', 'CNY', 'deal_bas_r', 'normal', 0],
    /** @scenario base=KRW, target=CNY, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['KRW', 'CNY', 'deal_bas_r', 'normal', 2],
    /** @scenario base=KRW, target=CNY, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['KRW', 'CNY', 'deal_bas_r', 'normal', 6],
    /** @scenario base=KRW, target=CNY, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['KRW', 'CNY', 'deal_bas_r', 'zero', 0],
    /** @scenario base=KRW, target=CNY, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['KRW', 'CNY', 'deal_bas_r', 'zero', 2],
    /** @scenario base=KRW, target=CNY, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['KRW', 'CNY', 'deal_bas_r', 'zero', 6],
    /** @scenario base=KRW, target=CNY, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['KRW', 'CNY', 'deal_bas_r', 'blank', 0],
    /** @scenario base=KRW, target=CNY, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['KRW', 'CNY', 'deal_bas_r', 'blank', 2],
    /** @scenario base=KRW, target=CNY, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['KRW', 'CNY', 'deal_bas_r', 'blank', 6],
    /** @scenario base=KRW, target=CNY, rate_type=ttb, rate_value=normal, decimals=0 */
    ['KRW', 'CNY', 'ttb', 'normal', 0],
    /** @scenario base=KRW, target=CNY, rate_type=ttb, rate_value=normal, decimals=2 */
    ['KRW', 'CNY', 'ttb', 'normal', 2],
    /** @scenario base=KRW, target=CNY, rate_type=ttb, rate_value=normal, decimals=6 */
    ['KRW', 'CNY', 'ttb', 'normal', 6],
    /** @scenario base=KRW, target=CNY, rate_type=ttb, rate_value=zero, decimals=0 */
    ['KRW', 'CNY', 'ttb', 'zero', 0],
    /** @scenario base=KRW, target=CNY, rate_type=ttb, rate_value=zero, decimals=2 */
    ['KRW', 'CNY', 'ttb', 'zero', 2],
    /** @scenario base=KRW, target=CNY, rate_type=ttb, rate_value=zero, decimals=6 */
    ['KRW', 'CNY', 'ttb', 'zero', 6],
    /** @scenario base=KRW, target=CNY, rate_type=ttb, rate_value=blank, decimals=0 */
    ['KRW', 'CNY', 'ttb', 'blank', 0],
    /** @scenario base=KRW, target=CNY, rate_type=ttb, rate_value=blank, decimals=2 */
    ['KRW', 'CNY', 'ttb', 'blank', 2],
    /** @scenario base=KRW, target=CNY, rate_type=ttb, rate_value=blank, decimals=6 */
    ['KRW', 'CNY', 'ttb', 'blank', 6],
    /** @scenario base=KRW, target=CNY, rate_type=tts, rate_value=normal, decimals=0 */
    ['KRW', 'CNY', 'tts', 'normal', 0],
    /** @scenario base=KRW, target=CNY, rate_type=tts, rate_value=normal, decimals=2 */
    ['KRW', 'CNY', 'tts', 'normal', 2],
    /** @scenario base=KRW, target=CNY, rate_type=tts, rate_value=normal, decimals=6 */
    ['KRW', 'CNY', 'tts', 'normal', 6],
    /** @scenario base=KRW, target=CNY, rate_type=tts, rate_value=zero, decimals=0 */
    ['KRW', 'CNY', 'tts', 'zero', 0],
    /** @scenario base=KRW, target=CNY, rate_type=tts, rate_value=zero, decimals=2 */
    ['KRW', 'CNY', 'tts', 'zero', 2],
    /** @scenario base=KRW, target=CNY, rate_type=tts, rate_value=zero, decimals=6 */
    ['KRW', 'CNY', 'tts', 'zero', 6],
    /** @scenario base=KRW, target=CNY, rate_type=tts, rate_value=blank, decimals=0 */
    ['KRW', 'CNY', 'tts', 'blank', 0],
    /** @scenario base=KRW, target=CNY, rate_type=tts, rate_value=blank, decimals=2 */
    ['KRW', 'CNY', 'tts', 'blank', 2],
    /** @scenario base=KRW, target=CNY, rate_type=tts, rate_value=blank, decimals=6 */
    ['KRW', 'CNY', 'tts', 'blank', 6],
    /** @scenario base=KRW, target=VND, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['KRW', 'VND', 'deal_bas_r', 'normal', 0],
    /** @scenario base=KRW, target=VND, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['KRW', 'VND', 'deal_bas_r', 'normal', 2],
    /** @scenario base=KRW, target=VND, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['KRW', 'VND', 'deal_bas_r', 'normal', 6],
    /** @scenario base=KRW, target=VND, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['KRW', 'VND', 'deal_bas_r', 'zero', 0],
    /** @scenario base=KRW, target=VND, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['KRW', 'VND', 'deal_bas_r', 'zero', 2],
    /** @scenario base=KRW, target=VND, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['KRW', 'VND', 'deal_bas_r', 'zero', 6],
    /** @scenario base=KRW, target=VND, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['KRW', 'VND', 'deal_bas_r', 'blank', 0],
    /** @scenario base=KRW, target=VND, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['KRW', 'VND', 'deal_bas_r', 'blank', 2],
    /** @scenario base=KRW, target=VND, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['KRW', 'VND', 'deal_bas_r', 'blank', 6],
    /** @scenario base=KRW, target=VND, rate_type=ttb, rate_value=normal, decimals=0 */
    ['KRW', 'VND', 'ttb', 'normal', 0],
    /** @scenario base=KRW, target=VND, rate_type=ttb, rate_value=normal, decimals=2 */
    ['KRW', 'VND', 'ttb', 'normal', 2],
    /** @scenario base=KRW, target=VND, rate_type=ttb, rate_value=normal, decimals=6 */
    ['KRW', 'VND', 'ttb', 'normal', 6],
    /** @scenario base=KRW, target=VND, rate_type=ttb, rate_value=zero, decimals=0 */
    ['KRW', 'VND', 'ttb', 'zero', 0],
    /** @scenario base=KRW, target=VND, rate_type=ttb, rate_value=zero, decimals=2 */
    ['KRW', 'VND', 'ttb', 'zero', 2],
    /** @scenario base=KRW, target=VND, rate_type=ttb, rate_value=zero, decimals=6 */
    ['KRW', 'VND', 'ttb', 'zero', 6],
    /** @scenario base=KRW, target=VND, rate_type=ttb, rate_value=blank, decimals=0 */
    ['KRW', 'VND', 'ttb', 'blank', 0],
    /** @scenario base=KRW, target=VND, rate_type=ttb, rate_value=blank, decimals=2 */
    ['KRW', 'VND', 'ttb', 'blank', 2],
    /** @scenario base=KRW, target=VND, rate_type=ttb, rate_value=blank, decimals=6 */
    ['KRW', 'VND', 'ttb', 'blank', 6],
    /** @scenario base=KRW, target=VND, rate_type=tts, rate_value=normal, decimals=0 */
    ['KRW', 'VND', 'tts', 'normal', 0],
    /** @scenario base=KRW, target=VND, rate_type=tts, rate_value=normal, decimals=2 */
    ['KRW', 'VND', 'tts', 'normal', 2],
    /** @scenario base=KRW, target=VND, rate_type=tts, rate_value=normal, decimals=6 */
    ['KRW', 'VND', 'tts', 'normal', 6],
    /** @scenario base=KRW, target=VND, rate_type=tts, rate_value=zero, decimals=0 */
    ['KRW', 'VND', 'tts', 'zero', 0],
    /** @scenario base=KRW, target=VND, rate_type=tts, rate_value=zero, decimals=2 */
    ['KRW', 'VND', 'tts', 'zero', 2],
    /** @scenario base=KRW, target=VND, rate_type=tts, rate_value=zero, decimals=6 */
    ['KRW', 'VND', 'tts', 'zero', 6],
    /** @scenario base=KRW, target=VND, rate_type=tts, rate_value=blank, decimals=0 */
    ['KRW', 'VND', 'tts', 'blank', 0],
    /** @scenario base=KRW, target=VND, rate_type=tts, rate_value=blank, decimals=2 */
    ['KRW', 'VND', 'tts', 'blank', 2],
    /** @scenario base=KRW, target=VND, rate_type=tts, rate_value=blank, decimals=6 */
    ['KRW', 'VND', 'tts', 'blank', 6],
    /** @scenario base=USD, target=USD, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['USD', 'USD', 'deal_bas_r', 'normal', 0],
    /** @scenario base=USD, target=USD, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['USD', 'USD', 'deal_bas_r', 'normal', 2],
    /** @scenario base=USD, target=USD, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['USD', 'USD', 'deal_bas_r', 'normal', 6],
    /** @scenario base=USD, target=USD, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['USD', 'USD', 'deal_bas_r', 'zero', 0],
    /** @scenario base=USD, target=USD, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['USD', 'USD', 'deal_bas_r', 'zero', 2],
    /** @scenario base=USD, target=USD, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['USD', 'USD', 'deal_bas_r', 'zero', 6],
    /** @scenario base=USD, target=USD, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['USD', 'USD', 'deal_bas_r', 'blank', 0],
    /** @scenario base=USD, target=USD, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['USD', 'USD', 'deal_bas_r', 'blank', 2],
    /** @scenario base=USD, target=USD, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['USD', 'USD', 'deal_bas_r', 'blank', 6],
    /** @scenario base=USD, target=USD, rate_type=ttb, rate_value=normal, decimals=0 */
    ['USD', 'USD', 'ttb', 'normal', 0],
    /** @scenario base=USD, target=USD, rate_type=ttb, rate_value=normal, decimals=2 */
    ['USD', 'USD', 'ttb', 'normal', 2],
    /** @scenario base=USD, target=USD, rate_type=ttb, rate_value=normal, decimals=6 */
    ['USD', 'USD', 'ttb', 'normal', 6],
    /** @scenario base=USD, target=USD, rate_type=ttb, rate_value=zero, decimals=0 */
    ['USD', 'USD', 'ttb', 'zero', 0],
    /** @scenario base=USD, target=USD, rate_type=ttb, rate_value=zero, decimals=2 */
    ['USD', 'USD', 'ttb', 'zero', 2],
    /** @scenario base=USD, target=USD, rate_type=ttb, rate_value=zero, decimals=6 */
    ['USD', 'USD', 'ttb', 'zero', 6],
    /** @scenario base=USD, target=USD, rate_type=ttb, rate_value=blank, decimals=0 */
    ['USD', 'USD', 'ttb', 'blank', 0],
    /** @scenario base=USD, target=USD, rate_type=ttb, rate_value=blank, decimals=2 */
    ['USD', 'USD', 'ttb', 'blank', 2],
    /** @scenario base=USD, target=USD, rate_type=ttb, rate_value=blank, decimals=6 */
    ['USD', 'USD', 'ttb', 'blank', 6],
    /** @scenario base=USD, target=USD, rate_type=tts, rate_value=normal, decimals=0 */
    ['USD', 'USD', 'tts', 'normal', 0],
    /** @scenario base=USD, target=USD, rate_type=tts, rate_value=normal, decimals=2 */
    ['USD', 'USD', 'tts', 'normal', 2],
    /** @scenario base=USD, target=USD, rate_type=tts, rate_value=normal, decimals=6 */
    ['USD', 'USD', 'tts', 'normal', 6],
    /** @scenario base=USD, target=USD, rate_type=tts, rate_value=zero, decimals=0 */
    ['USD', 'USD', 'tts', 'zero', 0],
    /** @scenario base=USD, target=USD, rate_type=tts, rate_value=zero, decimals=2 */
    ['USD', 'USD', 'tts', 'zero', 2],
    /** @scenario base=USD, target=USD, rate_type=tts, rate_value=zero, decimals=6 */
    ['USD', 'USD', 'tts', 'zero', 6],
    /** @scenario base=USD, target=USD, rate_type=tts, rate_value=blank, decimals=0 */
    ['USD', 'USD', 'tts', 'blank', 0],
    /** @scenario base=USD, target=USD, rate_type=tts, rate_value=blank, decimals=2 */
    ['USD', 'USD', 'tts', 'blank', 2],
    /** @scenario base=USD, target=USD, rate_type=tts, rate_value=blank, decimals=6 */
    ['USD', 'USD', 'tts', 'blank', 6],
    /** @scenario base=USD, target=JPY, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['USD', 'JPY', 'deal_bas_r', 'normal', 0],
    /** @scenario base=USD, target=JPY, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['USD', 'JPY', 'deal_bas_r', 'normal', 2],
    /** @scenario base=USD, target=JPY, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['USD', 'JPY', 'deal_bas_r', 'normal', 6],
    /** @scenario base=USD, target=JPY, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['USD', 'JPY', 'deal_bas_r', 'zero', 0],
    /** @scenario base=USD, target=JPY, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['USD', 'JPY', 'deal_bas_r', 'zero', 2],
    /** @scenario base=USD, target=JPY, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['USD', 'JPY', 'deal_bas_r', 'zero', 6],
    /** @scenario base=USD, target=JPY, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['USD', 'JPY', 'deal_bas_r', 'blank', 0],
    /** @scenario base=USD, target=JPY, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['USD', 'JPY', 'deal_bas_r', 'blank', 2],
    /** @scenario base=USD, target=JPY, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['USD', 'JPY', 'deal_bas_r', 'blank', 6],
    /** @scenario base=USD, target=JPY, rate_type=ttb, rate_value=normal, decimals=0 */
    ['USD', 'JPY', 'ttb', 'normal', 0],
    /** @scenario base=USD, target=JPY, rate_type=ttb, rate_value=normal, decimals=2 */
    ['USD', 'JPY', 'ttb', 'normal', 2],
    /** @scenario base=USD, target=JPY, rate_type=ttb, rate_value=normal, decimals=6 */
    ['USD', 'JPY', 'ttb', 'normal', 6],
    /** @scenario base=USD, target=JPY, rate_type=ttb, rate_value=zero, decimals=0 */
    ['USD', 'JPY', 'ttb', 'zero', 0],
    /** @scenario base=USD, target=JPY, rate_type=ttb, rate_value=zero, decimals=2 */
    ['USD', 'JPY', 'ttb', 'zero', 2],
    /** @scenario base=USD, target=JPY, rate_type=ttb, rate_value=zero, decimals=6 */
    ['USD', 'JPY', 'ttb', 'zero', 6],
    /** @scenario base=USD, target=JPY, rate_type=ttb, rate_value=blank, decimals=0 */
    ['USD', 'JPY', 'ttb', 'blank', 0],
    /** @scenario base=USD, target=JPY, rate_type=ttb, rate_value=blank, decimals=2 */
    ['USD', 'JPY', 'ttb', 'blank', 2],
    /** @scenario base=USD, target=JPY, rate_type=ttb, rate_value=blank, decimals=6 */
    ['USD', 'JPY', 'ttb', 'blank', 6],
    /** @scenario base=USD, target=JPY, rate_type=tts, rate_value=normal, decimals=0 */
    ['USD', 'JPY', 'tts', 'normal', 0],
    /** @scenario base=USD, target=JPY, rate_type=tts, rate_value=normal, decimals=2 */
    ['USD', 'JPY', 'tts', 'normal', 2],
    /** @scenario base=USD, target=JPY, rate_type=tts, rate_value=normal, decimals=6 */
    ['USD', 'JPY', 'tts', 'normal', 6],
    /** @scenario base=USD, target=JPY, rate_type=tts, rate_value=zero, decimals=0 */
    ['USD', 'JPY', 'tts', 'zero', 0],
    /** @scenario base=USD, target=JPY, rate_type=tts, rate_value=zero, decimals=2 */
    ['USD', 'JPY', 'tts', 'zero', 2],
    /** @scenario base=USD, target=JPY, rate_type=tts, rate_value=zero, decimals=6 */
    ['USD', 'JPY', 'tts', 'zero', 6],
    /** @scenario base=USD, target=JPY, rate_type=tts, rate_value=blank, decimals=0 */
    ['USD', 'JPY', 'tts', 'blank', 0],
    /** @scenario base=USD, target=JPY, rate_type=tts, rate_value=blank, decimals=2 */
    ['USD', 'JPY', 'tts', 'blank', 2],
    /** @scenario base=USD, target=JPY, rate_type=tts, rate_value=blank, decimals=6 */
    ['USD', 'JPY', 'tts', 'blank', 6],
    /** @scenario base=USD, target=KRW, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['USD', 'KRW', 'deal_bas_r', 'normal', 0],
    /** @scenario base=USD, target=KRW, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['USD', 'KRW', 'deal_bas_r', 'normal', 2],
    /** @scenario base=USD, target=KRW, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['USD', 'KRW', 'deal_bas_r', 'normal', 6],
    /** @scenario base=USD, target=KRW, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['USD', 'KRW', 'deal_bas_r', 'zero', 0],
    /** @scenario base=USD, target=KRW, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['USD', 'KRW', 'deal_bas_r', 'zero', 2],
    /** @scenario base=USD, target=KRW, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['USD', 'KRW', 'deal_bas_r', 'zero', 6],
    /** @scenario base=USD, target=KRW, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['USD', 'KRW', 'deal_bas_r', 'blank', 0],
    /** @scenario base=USD, target=KRW, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['USD', 'KRW', 'deal_bas_r', 'blank', 2],
    /** @scenario base=USD, target=KRW, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['USD', 'KRW', 'deal_bas_r', 'blank', 6],
    /** @scenario base=USD, target=KRW, rate_type=ttb, rate_value=normal, decimals=0 */
    ['USD', 'KRW', 'ttb', 'normal', 0],
    /** @scenario base=USD, target=KRW, rate_type=ttb, rate_value=normal, decimals=2 */
    ['USD', 'KRW', 'ttb', 'normal', 2],
    /** @scenario base=USD, target=KRW, rate_type=ttb, rate_value=normal, decimals=6 */
    ['USD', 'KRW', 'ttb', 'normal', 6],
    /** @scenario base=USD, target=KRW, rate_type=ttb, rate_value=zero, decimals=0 */
    ['USD', 'KRW', 'ttb', 'zero', 0],
    /** @scenario base=USD, target=KRW, rate_type=ttb, rate_value=zero, decimals=2 */
    ['USD', 'KRW', 'ttb', 'zero', 2],
    /** @scenario base=USD, target=KRW, rate_type=ttb, rate_value=zero, decimals=6 */
    ['USD', 'KRW', 'ttb', 'zero', 6],
    /** @scenario base=USD, target=KRW, rate_type=ttb, rate_value=blank, decimals=0 */
    ['USD', 'KRW', 'ttb', 'blank', 0],
    /** @scenario base=USD, target=KRW, rate_type=ttb, rate_value=blank, decimals=2 */
    ['USD', 'KRW', 'ttb', 'blank', 2],
    /** @scenario base=USD, target=KRW, rate_type=ttb, rate_value=blank, decimals=6 */
    ['USD', 'KRW', 'ttb', 'blank', 6],
    /** @scenario base=USD, target=KRW, rate_type=tts, rate_value=normal, decimals=0 */
    ['USD', 'KRW', 'tts', 'normal', 0],
    /** @scenario base=USD, target=KRW, rate_type=tts, rate_value=normal, decimals=2 */
    ['USD', 'KRW', 'tts', 'normal', 2],
    /** @scenario base=USD, target=KRW, rate_type=tts, rate_value=normal, decimals=6 */
    ['USD', 'KRW', 'tts', 'normal', 6],
    /** @scenario base=USD, target=KRW, rate_type=tts, rate_value=zero, decimals=0 */
    ['USD', 'KRW', 'tts', 'zero', 0],
    /** @scenario base=USD, target=KRW, rate_type=tts, rate_value=zero, decimals=2 */
    ['USD', 'KRW', 'tts', 'zero', 2],
    /** @scenario base=USD, target=KRW, rate_type=tts, rate_value=zero, decimals=6 */
    ['USD', 'KRW', 'tts', 'zero', 6],
    /** @scenario base=USD, target=KRW, rate_type=tts, rate_value=blank, decimals=0 */
    ['USD', 'KRW', 'tts', 'blank', 0],
    /** @scenario base=USD, target=KRW, rate_type=tts, rate_value=blank, decimals=2 */
    ['USD', 'KRW', 'tts', 'blank', 2],
    /** @scenario base=USD, target=KRW, rate_type=tts, rate_value=blank, decimals=6 */
    ['USD', 'KRW', 'tts', 'blank', 6],
    /** @scenario base=USD, target=CNY, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['USD', 'CNY', 'deal_bas_r', 'normal', 0],
    /** @scenario base=USD, target=CNY, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['USD', 'CNY', 'deal_bas_r', 'normal', 2],
    /** @scenario base=USD, target=CNY, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['USD', 'CNY', 'deal_bas_r', 'normal', 6],
    /** @scenario base=USD, target=CNY, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['USD', 'CNY', 'deal_bas_r', 'zero', 0],
    /** @scenario base=USD, target=CNY, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['USD', 'CNY', 'deal_bas_r', 'zero', 2],
    /** @scenario base=USD, target=CNY, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['USD', 'CNY', 'deal_bas_r', 'zero', 6],
    /** @scenario base=USD, target=CNY, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['USD', 'CNY', 'deal_bas_r', 'blank', 0],
    /** @scenario base=USD, target=CNY, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['USD', 'CNY', 'deal_bas_r', 'blank', 2],
    /** @scenario base=USD, target=CNY, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['USD', 'CNY', 'deal_bas_r', 'blank', 6],
    /** @scenario base=USD, target=CNY, rate_type=ttb, rate_value=normal, decimals=0 */
    ['USD', 'CNY', 'ttb', 'normal', 0],
    /** @scenario base=USD, target=CNY, rate_type=ttb, rate_value=normal, decimals=2 */
    ['USD', 'CNY', 'ttb', 'normal', 2],
    /** @scenario base=USD, target=CNY, rate_type=ttb, rate_value=normal, decimals=6 */
    ['USD', 'CNY', 'ttb', 'normal', 6],
    /** @scenario base=USD, target=CNY, rate_type=ttb, rate_value=zero, decimals=0 */
    ['USD', 'CNY', 'ttb', 'zero', 0],
    /** @scenario base=USD, target=CNY, rate_type=ttb, rate_value=zero, decimals=2 */
    ['USD', 'CNY', 'ttb', 'zero', 2],
    /** @scenario base=USD, target=CNY, rate_type=ttb, rate_value=zero, decimals=6 */
    ['USD', 'CNY', 'ttb', 'zero', 6],
    /** @scenario base=USD, target=CNY, rate_type=ttb, rate_value=blank, decimals=0 */
    ['USD', 'CNY', 'ttb', 'blank', 0],
    /** @scenario base=USD, target=CNY, rate_type=ttb, rate_value=blank, decimals=2 */
    ['USD', 'CNY', 'ttb', 'blank', 2],
    /** @scenario base=USD, target=CNY, rate_type=ttb, rate_value=blank, decimals=6 */
    ['USD', 'CNY', 'ttb', 'blank', 6],
    /** @scenario base=USD, target=CNY, rate_type=tts, rate_value=normal, decimals=0 */
    ['USD', 'CNY', 'tts', 'normal', 0],
    /** @scenario base=USD, target=CNY, rate_type=tts, rate_value=normal, decimals=2 */
    ['USD', 'CNY', 'tts', 'normal', 2],
    /** @scenario base=USD, target=CNY, rate_type=tts, rate_value=normal, decimals=6 */
    ['USD', 'CNY', 'tts', 'normal', 6],
    /** @scenario base=USD, target=CNY, rate_type=tts, rate_value=zero, decimals=0 */
    ['USD', 'CNY', 'tts', 'zero', 0],
    /** @scenario base=USD, target=CNY, rate_type=tts, rate_value=zero, decimals=2 */
    ['USD', 'CNY', 'tts', 'zero', 2],
    /** @scenario base=USD, target=CNY, rate_type=tts, rate_value=zero, decimals=6 */
    ['USD', 'CNY', 'tts', 'zero', 6],
    /** @scenario base=USD, target=CNY, rate_type=tts, rate_value=blank, decimals=0 */
    ['USD', 'CNY', 'tts', 'blank', 0],
    /** @scenario base=USD, target=CNY, rate_type=tts, rate_value=blank, decimals=2 */
    ['USD', 'CNY', 'tts', 'blank', 2],
    /** @scenario base=USD, target=CNY, rate_type=tts, rate_value=blank, decimals=6 */
    ['USD', 'CNY', 'tts', 'blank', 6],
    /** @scenario base=USD, target=VND, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['USD', 'VND', 'deal_bas_r', 'normal', 0],
    /** @scenario base=USD, target=VND, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['USD', 'VND', 'deal_bas_r', 'normal', 2],
    /** @scenario base=USD, target=VND, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['USD', 'VND', 'deal_bas_r', 'normal', 6],
    /** @scenario base=USD, target=VND, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['USD', 'VND', 'deal_bas_r', 'zero', 0],
    /** @scenario base=USD, target=VND, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['USD', 'VND', 'deal_bas_r', 'zero', 2],
    /** @scenario base=USD, target=VND, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['USD', 'VND', 'deal_bas_r', 'zero', 6],
    /** @scenario base=USD, target=VND, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['USD', 'VND', 'deal_bas_r', 'blank', 0],
    /** @scenario base=USD, target=VND, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['USD', 'VND', 'deal_bas_r', 'blank', 2],
    /** @scenario base=USD, target=VND, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['USD', 'VND', 'deal_bas_r', 'blank', 6],
    /** @scenario base=USD, target=VND, rate_type=ttb, rate_value=normal, decimals=0 */
    ['USD', 'VND', 'ttb', 'normal', 0],
    /** @scenario base=USD, target=VND, rate_type=ttb, rate_value=normal, decimals=2 */
    ['USD', 'VND', 'ttb', 'normal', 2],
    /** @scenario base=USD, target=VND, rate_type=ttb, rate_value=normal, decimals=6 */
    ['USD', 'VND', 'ttb', 'normal', 6],
    /** @scenario base=USD, target=VND, rate_type=ttb, rate_value=zero, decimals=0 */
    ['USD', 'VND', 'ttb', 'zero', 0],
    /** @scenario base=USD, target=VND, rate_type=ttb, rate_value=zero, decimals=2 */
    ['USD', 'VND', 'ttb', 'zero', 2],
    /** @scenario base=USD, target=VND, rate_type=ttb, rate_value=zero, decimals=6 */
    ['USD', 'VND', 'ttb', 'zero', 6],
    /** @scenario base=USD, target=VND, rate_type=ttb, rate_value=blank, decimals=0 */
    ['USD', 'VND', 'ttb', 'blank', 0],
    /** @scenario base=USD, target=VND, rate_type=ttb, rate_value=blank, decimals=2 */
    ['USD', 'VND', 'ttb', 'blank', 2],
    /** @scenario base=USD, target=VND, rate_type=ttb, rate_value=blank, decimals=6 */
    ['USD', 'VND', 'ttb', 'blank', 6],
    /** @scenario base=USD, target=VND, rate_type=tts, rate_value=normal, decimals=0 */
    ['USD', 'VND', 'tts', 'normal', 0],
    /** @scenario base=USD, target=VND, rate_type=tts, rate_value=normal, decimals=2 */
    ['USD', 'VND', 'tts', 'normal', 2],
    /** @scenario base=USD, target=VND, rate_type=tts, rate_value=normal, decimals=6 */
    ['USD', 'VND', 'tts', 'normal', 6],
    /** @scenario base=USD, target=VND, rate_type=tts, rate_value=zero, decimals=0 */
    ['USD', 'VND', 'tts', 'zero', 0],
    /** @scenario base=USD, target=VND, rate_type=tts, rate_value=zero, decimals=2 */
    ['USD', 'VND', 'tts', 'zero', 2],
    /** @scenario base=USD, target=VND, rate_type=tts, rate_value=zero, decimals=6 */
    ['USD', 'VND', 'tts', 'zero', 6],
    /** @scenario base=USD, target=VND, rate_type=tts, rate_value=blank, decimals=0 */
    ['USD', 'VND', 'tts', 'blank', 0],
    /** @scenario base=USD, target=VND, rate_type=tts, rate_value=blank, decimals=2 */
    ['USD', 'VND', 'tts', 'blank', 2],
    /** @scenario base=USD, target=VND, rate_type=tts, rate_value=blank, decimals=6 */
    ['USD', 'VND', 'tts', 'blank', 6],
    /** @scenario base=JPY, target=USD, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['JPY', 'USD', 'deal_bas_r', 'normal', 0],
    /** @scenario base=JPY, target=USD, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['JPY', 'USD', 'deal_bas_r', 'normal', 2],
    /** @scenario base=JPY, target=USD, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['JPY', 'USD', 'deal_bas_r', 'normal', 6],
    /** @scenario base=JPY, target=USD, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['JPY', 'USD', 'deal_bas_r', 'zero', 0],
    /** @scenario base=JPY, target=USD, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['JPY', 'USD', 'deal_bas_r', 'zero', 2],
    /** @scenario base=JPY, target=USD, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['JPY', 'USD', 'deal_bas_r', 'zero', 6],
    /** @scenario base=JPY, target=USD, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['JPY', 'USD', 'deal_bas_r', 'blank', 0],
    /** @scenario base=JPY, target=USD, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['JPY', 'USD', 'deal_bas_r', 'blank', 2],
    /** @scenario base=JPY, target=USD, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['JPY', 'USD', 'deal_bas_r', 'blank', 6],
    /** @scenario base=JPY, target=USD, rate_type=ttb, rate_value=normal, decimals=0 */
    ['JPY', 'USD', 'ttb', 'normal', 0],
    /** @scenario base=JPY, target=USD, rate_type=ttb, rate_value=normal, decimals=2 */
    ['JPY', 'USD', 'ttb', 'normal', 2],
    /** @scenario base=JPY, target=USD, rate_type=ttb, rate_value=normal, decimals=6 */
    ['JPY', 'USD', 'ttb', 'normal', 6],
    /** @scenario base=JPY, target=USD, rate_type=ttb, rate_value=zero, decimals=0 */
    ['JPY', 'USD', 'ttb', 'zero', 0],
    /** @scenario base=JPY, target=USD, rate_type=ttb, rate_value=zero, decimals=2 */
    ['JPY', 'USD', 'ttb', 'zero', 2],
    /** @scenario base=JPY, target=USD, rate_type=ttb, rate_value=zero, decimals=6 */
    ['JPY', 'USD', 'ttb', 'zero', 6],
    /** @scenario base=JPY, target=USD, rate_type=ttb, rate_value=blank, decimals=0 */
    ['JPY', 'USD', 'ttb', 'blank', 0],
    /** @scenario base=JPY, target=USD, rate_type=ttb, rate_value=blank, decimals=2 */
    ['JPY', 'USD', 'ttb', 'blank', 2],
    /** @scenario base=JPY, target=USD, rate_type=ttb, rate_value=blank, decimals=6 */
    ['JPY', 'USD', 'ttb', 'blank', 6],
    /** @scenario base=JPY, target=USD, rate_type=tts, rate_value=normal, decimals=0 */
    ['JPY', 'USD', 'tts', 'normal', 0],
    /** @scenario base=JPY, target=USD, rate_type=tts, rate_value=normal, decimals=2 */
    ['JPY', 'USD', 'tts', 'normal', 2],
    /** @scenario base=JPY, target=USD, rate_type=tts, rate_value=normal, decimals=6 */
    ['JPY', 'USD', 'tts', 'normal', 6],
    /** @scenario base=JPY, target=USD, rate_type=tts, rate_value=zero, decimals=0 */
    ['JPY', 'USD', 'tts', 'zero', 0],
    /** @scenario base=JPY, target=USD, rate_type=tts, rate_value=zero, decimals=2 */
    ['JPY', 'USD', 'tts', 'zero', 2],
    /** @scenario base=JPY, target=USD, rate_type=tts, rate_value=zero, decimals=6 */
    ['JPY', 'USD', 'tts', 'zero', 6],
    /** @scenario base=JPY, target=USD, rate_type=tts, rate_value=blank, decimals=0 */
    ['JPY', 'USD', 'tts', 'blank', 0],
    /** @scenario base=JPY, target=USD, rate_type=tts, rate_value=blank, decimals=2 */
    ['JPY', 'USD', 'tts', 'blank', 2],
    /** @scenario base=JPY, target=USD, rate_type=tts, rate_value=blank, decimals=6 */
    ['JPY', 'USD', 'tts', 'blank', 6],
    /** @scenario base=JPY, target=JPY, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['JPY', 'JPY', 'deal_bas_r', 'normal', 0],
    /** @scenario base=JPY, target=JPY, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['JPY', 'JPY', 'deal_bas_r', 'normal', 2],
    /** @scenario base=JPY, target=JPY, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['JPY', 'JPY', 'deal_bas_r', 'normal', 6],
    /** @scenario base=JPY, target=JPY, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['JPY', 'JPY', 'deal_bas_r', 'zero', 0],
    /** @scenario base=JPY, target=JPY, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['JPY', 'JPY', 'deal_bas_r', 'zero', 2],
    /** @scenario base=JPY, target=JPY, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['JPY', 'JPY', 'deal_bas_r', 'zero', 6],
    /** @scenario base=JPY, target=JPY, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['JPY', 'JPY', 'deal_bas_r', 'blank', 0],
    /** @scenario base=JPY, target=JPY, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['JPY', 'JPY', 'deal_bas_r', 'blank', 2],
    /** @scenario base=JPY, target=JPY, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['JPY', 'JPY', 'deal_bas_r', 'blank', 6],
    /** @scenario base=JPY, target=JPY, rate_type=ttb, rate_value=normal, decimals=0 */
    ['JPY', 'JPY', 'ttb', 'normal', 0],
    /** @scenario base=JPY, target=JPY, rate_type=ttb, rate_value=normal, decimals=2 */
    ['JPY', 'JPY', 'ttb', 'normal', 2],
    /** @scenario base=JPY, target=JPY, rate_type=ttb, rate_value=normal, decimals=6 */
    ['JPY', 'JPY', 'ttb', 'normal', 6],
    /** @scenario base=JPY, target=JPY, rate_type=ttb, rate_value=zero, decimals=0 */
    ['JPY', 'JPY', 'ttb', 'zero', 0],
    /** @scenario base=JPY, target=JPY, rate_type=ttb, rate_value=zero, decimals=2 */
    ['JPY', 'JPY', 'ttb', 'zero', 2],
    /** @scenario base=JPY, target=JPY, rate_type=ttb, rate_value=zero, decimals=6 */
    ['JPY', 'JPY', 'ttb', 'zero', 6],
    /** @scenario base=JPY, target=JPY, rate_type=ttb, rate_value=blank, decimals=0 */
    ['JPY', 'JPY', 'ttb', 'blank', 0],
    /** @scenario base=JPY, target=JPY, rate_type=ttb, rate_value=blank, decimals=2 */
    ['JPY', 'JPY', 'ttb', 'blank', 2],
    /** @scenario base=JPY, target=JPY, rate_type=ttb, rate_value=blank, decimals=6 */
    ['JPY', 'JPY', 'ttb', 'blank', 6],
    /** @scenario base=JPY, target=JPY, rate_type=tts, rate_value=normal, decimals=0 */
    ['JPY', 'JPY', 'tts', 'normal', 0],
    /** @scenario base=JPY, target=JPY, rate_type=tts, rate_value=normal, decimals=2 */
    ['JPY', 'JPY', 'tts', 'normal', 2],
    /** @scenario base=JPY, target=JPY, rate_type=tts, rate_value=normal, decimals=6 */
    ['JPY', 'JPY', 'tts', 'normal', 6],
    /** @scenario base=JPY, target=JPY, rate_type=tts, rate_value=zero, decimals=0 */
    ['JPY', 'JPY', 'tts', 'zero', 0],
    /** @scenario base=JPY, target=JPY, rate_type=tts, rate_value=zero, decimals=2 */
    ['JPY', 'JPY', 'tts', 'zero', 2],
    /** @scenario base=JPY, target=JPY, rate_type=tts, rate_value=zero, decimals=6 */
    ['JPY', 'JPY', 'tts', 'zero', 6],
    /** @scenario base=JPY, target=JPY, rate_type=tts, rate_value=blank, decimals=0 */
    ['JPY', 'JPY', 'tts', 'blank', 0],
    /** @scenario base=JPY, target=JPY, rate_type=tts, rate_value=blank, decimals=2 */
    ['JPY', 'JPY', 'tts', 'blank', 2],
    /** @scenario base=JPY, target=JPY, rate_type=tts, rate_value=blank, decimals=6 */
    ['JPY', 'JPY', 'tts', 'blank', 6],
    /** @scenario base=JPY, target=KRW, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['JPY', 'KRW', 'deal_bas_r', 'normal', 0],
    /** @scenario base=JPY, target=KRW, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['JPY', 'KRW', 'deal_bas_r', 'normal', 2],
    /** @scenario base=JPY, target=KRW, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['JPY', 'KRW', 'deal_bas_r', 'normal', 6],
    /** @scenario base=JPY, target=KRW, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['JPY', 'KRW', 'deal_bas_r', 'zero', 0],
    /** @scenario base=JPY, target=KRW, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['JPY', 'KRW', 'deal_bas_r', 'zero', 2],
    /** @scenario base=JPY, target=KRW, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['JPY', 'KRW', 'deal_bas_r', 'zero', 6],
    /** @scenario base=JPY, target=KRW, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['JPY', 'KRW', 'deal_bas_r', 'blank', 0],
    /** @scenario base=JPY, target=KRW, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['JPY', 'KRW', 'deal_bas_r', 'blank', 2],
    /** @scenario base=JPY, target=KRW, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['JPY', 'KRW', 'deal_bas_r', 'blank', 6],
    /** @scenario base=JPY, target=KRW, rate_type=ttb, rate_value=normal, decimals=0 */
    ['JPY', 'KRW', 'ttb', 'normal', 0],
    /** @scenario base=JPY, target=KRW, rate_type=ttb, rate_value=normal, decimals=2 */
    ['JPY', 'KRW', 'ttb', 'normal', 2],
    /** @scenario base=JPY, target=KRW, rate_type=ttb, rate_value=normal, decimals=6 */
    ['JPY', 'KRW', 'ttb', 'normal', 6],
    /** @scenario base=JPY, target=KRW, rate_type=ttb, rate_value=zero, decimals=0 */
    ['JPY', 'KRW', 'ttb', 'zero', 0],
    /** @scenario base=JPY, target=KRW, rate_type=ttb, rate_value=zero, decimals=2 */
    ['JPY', 'KRW', 'ttb', 'zero', 2],
    /** @scenario base=JPY, target=KRW, rate_type=ttb, rate_value=zero, decimals=6 */
    ['JPY', 'KRW', 'ttb', 'zero', 6],
    /** @scenario base=JPY, target=KRW, rate_type=ttb, rate_value=blank, decimals=0 */
    ['JPY', 'KRW', 'ttb', 'blank', 0],
    /** @scenario base=JPY, target=KRW, rate_type=ttb, rate_value=blank, decimals=2 */
    ['JPY', 'KRW', 'ttb', 'blank', 2],
    /** @scenario base=JPY, target=KRW, rate_type=ttb, rate_value=blank, decimals=6 */
    ['JPY', 'KRW', 'ttb', 'blank', 6],
    /** @scenario base=JPY, target=KRW, rate_type=tts, rate_value=normal, decimals=0 */
    ['JPY', 'KRW', 'tts', 'normal', 0],
    /** @scenario base=JPY, target=KRW, rate_type=tts, rate_value=normal, decimals=2 */
    ['JPY', 'KRW', 'tts', 'normal', 2],
    /** @scenario base=JPY, target=KRW, rate_type=tts, rate_value=normal, decimals=6 */
    ['JPY', 'KRW', 'tts', 'normal', 6],
    /** @scenario base=JPY, target=KRW, rate_type=tts, rate_value=zero, decimals=0 */
    ['JPY', 'KRW', 'tts', 'zero', 0],
    /** @scenario base=JPY, target=KRW, rate_type=tts, rate_value=zero, decimals=2 */
    ['JPY', 'KRW', 'tts', 'zero', 2],
    /** @scenario base=JPY, target=KRW, rate_type=tts, rate_value=zero, decimals=6 */
    ['JPY', 'KRW', 'tts', 'zero', 6],
    /** @scenario base=JPY, target=KRW, rate_type=tts, rate_value=blank, decimals=0 */
    ['JPY', 'KRW', 'tts', 'blank', 0],
    /** @scenario base=JPY, target=KRW, rate_type=tts, rate_value=blank, decimals=2 */
    ['JPY', 'KRW', 'tts', 'blank', 2],
    /** @scenario base=JPY, target=KRW, rate_type=tts, rate_value=blank, decimals=6 */
    ['JPY', 'KRW', 'tts', 'blank', 6],
    /** @scenario base=JPY, target=CNY, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['JPY', 'CNY', 'deal_bas_r', 'normal', 0],
    /** @scenario base=JPY, target=CNY, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['JPY', 'CNY', 'deal_bas_r', 'normal', 2],
    /** @scenario base=JPY, target=CNY, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['JPY', 'CNY', 'deal_bas_r', 'normal', 6],
    /** @scenario base=JPY, target=CNY, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['JPY', 'CNY', 'deal_bas_r', 'zero', 0],
    /** @scenario base=JPY, target=CNY, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['JPY', 'CNY', 'deal_bas_r', 'zero', 2],
    /** @scenario base=JPY, target=CNY, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['JPY', 'CNY', 'deal_bas_r', 'zero', 6],
    /** @scenario base=JPY, target=CNY, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['JPY', 'CNY', 'deal_bas_r', 'blank', 0],
    /** @scenario base=JPY, target=CNY, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['JPY', 'CNY', 'deal_bas_r', 'blank', 2],
    /** @scenario base=JPY, target=CNY, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['JPY', 'CNY', 'deal_bas_r', 'blank', 6],
    /** @scenario base=JPY, target=CNY, rate_type=ttb, rate_value=normal, decimals=0 */
    ['JPY', 'CNY', 'ttb', 'normal', 0],
    /** @scenario base=JPY, target=CNY, rate_type=ttb, rate_value=normal, decimals=2 */
    ['JPY', 'CNY', 'ttb', 'normal', 2],
    /** @scenario base=JPY, target=CNY, rate_type=ttb, rate_value=normal, decimals=6 */
    ['JPY', 'CNY', 'ttb', 'normal', 6],
    /** @scenario base=JPY, target=CNY, rate_type=ttb, rate_value=zero, decimals=0 */
    ['JPY', 'CNY', 'ttb', 'zero', 0],
    /** @scenario base=JPY, target=CNY, rate_type=ttb, rate_value=zero, decimals=2 */
    ['JPY', 'CNY', 'ttb', 'zero', 2],
    /** @scenario base=JPY, target=CNY, rate_type=ttb, rate_value=zero, decimals=6 */
    ['JPY', 'CNY', 'ttb', 'zero', 6],
    /** @scenario base=JPY, target=CNY, rate_type=ttb, rate_value=blank, decimals=0 */
    ['JPY', 'CNY', 'ttb', 'blank', 0],
    /** @scenario base=JPY, target=CNY, rate_type=ttb, rate_value=blank, decimals=2 */
    ['JPY', 'CNY', 'ttb', 'blank', 2],
    /** @scenario base=JPY, target=CNY, rate_type=ttb, rate_value=blank, decimals=6 */
    ['JPY', 'CNY', 'ttb', 'blank', 6],
    /** @scenario base=JPY, target=CNY, rate_type=tts, rate_value=normal, decimals=0 */
    ['JPY', 'CNY', 'tts', 'normal', 0],
    /** @scenario base=JPY, target=CNY, rate_type=tts, rate_value=normal, decimals=2 */
    ['JPY', 'CNY', 'tts', 'normal', 2],
    /** @scenario base=JPY, target=CNY, rate_type=tts, rate_value=normal, decimals=6 */
    ['JPY', 'CNY', 'tts', 'normal', 6],
    /** @scenario base=JPY, target=CNY, rate_type=tts, rate_value=zero, decimals=0 */
    ['JPY', 'CNY', 'tts', 'zero', 0],
    /** @scenario base=JPY, target=CNY, rate_type=tts, rate_value=zero, decimals=2 */
    ['JPY', 'CNY', 'tts', 'zero', 2],
    /** @scenario base=JPY, target=CNY, rate_type=tts, rate_value=zero, decimals=6 */
    ['JPY', 'CNY', 'tts', 'zero', 6],
    /** @scenario base=JPY, target=CNY, rate_type=tts, rate_value=blank, decimals=0 */
    ['JPY', 'CNY', 'tts', 'blank', 0],
    /** @scenario base=JPY, target=CNY, rate_type=tts, rate_value=blank, decimals=2 */
    ['JPY', 'CNY', 'tts', 'blank', 2],
    /** @scenario base=JPY, target=CNY, rate_type=tts, rate_value=blank, decimals=6 */
    ['JPY', 'CNY', 'tts', 'blank', 6],
    /** @scenario base=JPY, target=VND, rate_type=deal_bas_r, rate_value=normal, decimals=0 */
    ['JPY', 'VND', 'deal_bas_r', 'normal', 0],
    /** @scenario base=JPY, target=VND, rate_type=deal_bas_r, rate_value=normal, decimals=2 */
    ['JPY', 'VND', 'deal_bas_r', 'normal', 2],
    /** @scenario base=JPY, target=VND, rate_type=deal_bas_r, rate_value=normal, decimals=6 */
    ['JPY', 'VND', 'deal_bas_r', 'normal', 6],
    /** @scenario base=JPY, target=VND, rate_type=deal_bas_r, rate_value=zero, decimals=0 */
    ['JPY', 'VND', 'deal_bas_r', 'zero', 0],
    /** @scenario base=JPY, target=VND, rate_type=deal_bas_r, rate_value=zero, decimals=2 */
    ['JPY', 'VND', 'deal_bas_r', 'zero', 2],
    /** @scenario base=JPY, target=VND, rate_type=deal_bas_r, rate_value=zero, decimals=6 */
    ['JPY', 'VND', 'deal_bas_r', 'zero', 6],
    /** @scenario base=JPY, target=VND, rate_type=deal_bas_r, rate_value=blank, decimals=0 */
    ['JPY', 'VND', 'deal_bas_r', 'blank', 0],
    /** @scenario base=JPY, target=VND, rate_type=deal_bas_r, rate_value=blank, decimals=2 */
    ['JPY', 'VND', 'deal_bas_r', 'blank', 2],
    /** @scenario base=JPY, target=VND, rate_type=deal_bas_r, rate_value=blank, decimals=6 */
    ['JPY', 'VND', 'deal_bas_r', 'blank', 6],
    /** @scenario base=JPY, target=VND, rate_type=ttb, rate_value=normal, decimals=0 */
    ['JPY', 'VND', 'ttb', 'normal', 0],
    /** @scenario base=JPY, target=VND, rate_type=ttb, rate_value=normal, decimals=2 */
    ['JPY', 'VND', 'ttb', 'normal', 2],
    /** @scenario base=JPY, target=VND, rate_type=ttb, rate_value=normal, decimals=6 */
    ['JPY', 'VND', 'ttb', 'normal', 6],
    /** @scenario base=JPY, target=VND, rate_type=ttb, rate_value=zero, decimals=0 */
    ['JPY', 'VND', 'ttb', 'zero', 0],
    /** @scenario base=JPY, target=VND, rate_type=ttb, rate_value=zero, decimals=2 */
    ['JPY', 'VND', 'ttb', 'zero', 2],
    /** @scenario base=JPY, target=VND, rate_type=ttb, rate_value=zero, decimals=6 */
    ['JPY', 'VND', 'ttb', 'zero', 6],
    /** @scenario base=JPY, target=VND, rate_type=ttb, rate_value=blank, decimals=0 */
    ['JPY', 'VND', 'ttb', 'blank', 0],
    /** @scenario base=JPY, target=VND, rate_type=ttb, rate_value=blank, decimals=2 */
    ['JPY', 'VND', 'ttb', 'blank', 2],
    /** @scenario base=JPY, target=VND, rate_type=ttb, rate_value=blank, decimals=6 */
    ['JPY', 'VND', 'ttb', 'blank', 6],
    /** @scenario base=JPY, target=VND, rate_type=tts, rate_value=normal, decimals=0 */
    ['JPY', 'VND', 'tts', 'normal', 0],
    /** @scenario base=JPY, target=VND, rate_type=tts, rate_value=normal, decimals=2 */
    ['JPY', 'VND', 'tts', 'normal', 2],
    /** @scenario base=JPY, target=VND, rate_type=tts, rate_value=normal, decimals=6 */
    ['JPY', 'VND', 'tts', 'normal', 6],
    /** @scenario base=JPY, target=VND, rate_type=tts, rate_value=zero, decimals=0 */
    ['JPY', 'VND', 'tts', 'zero', 0],
    /** @scenario base=JPY, target=VND, rate_type=tts, rate_value=zero, decimals=2 */
    ['JPY', 'VND', 'tts', 'zero', 2],
    /** @scenario base=JPY, target=VND, rate_type=tts, rate_value=zero, decimals=6 */
    ['JPY', 'VND', 'tts', 'zero', 6],
    /** @scenario base=JPY, target=VND, rate_type=tts, rate_value=blank, decimals=0 */
    ['JPY', 'VND', 'tts', 'blank', 0],
    /** @scenario base=JPY, target=VND, rate_type=tts, rate_value=blank, decimals=2 */
    ['JPY', 'VND', 'tts', 'blank', 2],
    /** @scenario base=JPY, target=VND, rate_type=tts, rate_value=blank, decimals=6 */
    ['JPY', 'VND', 'tts', 'blank', 6],
];
