<?php

// 提供자 조합을 ExchangeRateProviderMatrixTest가 실제 요청·검증에 사용합니다.
return [
    /** @scenario registered=none, selected=none, save_path=bulk, submitted_rate=unchanged, actor=update_perm */
    ['none', 'none', 'bulk', 'unchanged', 'update_perm'],
    /** @scenario registered=none, selected=none, save_path=bulk, submitted_rate=unchanged, actor=read_only */
    ['none', 'none', 'bulk', 'unchanged', 'read_only'],
    /** @scenario registered=none, selected=none, save_path=bulk, submitted_rate=changed, actor=update_perm */
    ['none', 'none', 'bulk', 'changed', 'update_perm'],
    /** @scenario registered=none, selected=none, save_path=bulk, submitted_rate=changed, actor=read_only */
    ['none', 'none', 'bulk', 'changed', 'read_only'],
    /** @scenario registered=none, selected=none, save_path=bulk, submitted_rate=new_currency, actor=update_perm */
    ['none', 'none', 'bulk', 'new_currency', 'update_perm'],
    /** @scenario registered=none, selected=none, save_path=bulk, submitted_rate=new_currency, actor=read_only */
    ['none', 'none', 'bulk', 'new_currency', 'read_only'],
    /** @scenario registered=none, selected=none, save_path=bulk, submitted_rate=unsupported_code, actor=update_perm */
    ['none', 'none', 'bulk', 'unsupported_code', 'update_perm'],
    /** @scenario registered=none, selected=none, save_path=bulk, submitted_rate=unsupported_code, actor=read_only */
    ['none', 'none', 'bulk', 'unsupported_code', 'read_only'],
    /** @scenario registered=none, selected=none, save_path=bulk, submitted_rate=base_row, actor=update_perm */
    ['none', 'none', 'bulk', 'base_row', 'update_perm'],
    /** @scenario registered=none, selected=none, save_path=bulk, submitted_rate=base_row, actor=read_only */
    ['none', 'none', 'bulk', 'base_row', 'read_only'],
    /** @scenario registered=none, selected=none, save_path=single_key, submitted_rate=unchanged, actor=update_perm */
    ['none', 'none', 'single_key', 'unchanged', 'update_perm'],
    /** @scenario registered=none, selected=none, save_path=single_key, submitted_rate=changed, actor=update_perm */
    ['none', 'none', 'single_key', 'changed', 'update_perm'],
    /** @scenario registered=none, selected=none, save_path=single_key, submitted_rate=new_currency, actor=update_perm */
    ['none', 'none', 'single_key', 'new_currency', 'update_perm'],
    /** @scenario registered=none, selected=none, save_path=single_key, submitted_rate=unsupported_code, actor=update_perm */
    ['none', 'none', 'single_key', 'unsupported_code', 'update_perm'],
    /** @scenario registered=none, selected=none, save_path=single_key, submitted_rate=base_row, actor=update_perm */
    ['none', 'none', 'single_key', 'base_row', 'update_perm'],
    /** @scenario registered=none, selected=unregistered, save_path=bulk, submitted_rate=unchanged, actor=update_perm */
    ['none', 'unregistered', 'bulk', 'unchanged', 'update_perm'],
    /** @scenario registered=none, selected=unregistered, save_path=bulk, submitted_rate=unchanged, actor=read_only */
    ['none', 'unregistered', 'bulk', 'unchanged', 'read_only'],
    /** @scenario registered=none, selected=unregistered, save_path=bulk, submitted_rate=changed, actor=update_perm */
    ['none', 'unregistered', 'bulk', 'changed', 'update_perm'],
    /** @scenario registered=none, selected=unregistered, save_path=bulk, submitted_rate=changed, actor=read_only */
    ['none', 'unregistered', 'bulk', 'changed', 'read_only'],
    /** @scenario registered=none, selected=unregistered, save_path=bulk, submitted_rate=new_currency, actor=update_perm */
    ['none', 'unregistered', 'bulk', 'new_currency', 'update_perm'],
    /** @scenario registered=none, selected=unregistered, save_path=bulk, submitted_rate=new_currency, actor=read_only */
    ['none', 'unregistered', 'bulk', 'new_currency', 'read_only'],
    /** @scenario registered=none, selected=unregistered, save_path=bulk, submitted_rate=unsupported_code, actor=update_perm */
    ['none', 'unregistered', 'bulk', 'unsupported_code', 'update_perm'],
    /** @scenario registered=none, selected=unregistered, save_path=bulk, submitted_rate=unsupported_code, actor=read_only */
    ['none', 'unregistered', 'bulk', 'unsupported_code', 'read_only'],
    /** @scenario registered=none, selected=unregistered, save_path=bulk, submitted_rate=base_row, actor=update_perm */
    ['none', 'unregistered', 'bulk', 'base_row', 'update_perm'],
    /** @scenario registered=none, selected=unregistered, save_path=bulk, submitted_rate=base_row, actor=read_only */
    ['none', 'unregistered', 'bulk', 'base_row', 'read_only'],
    /** @scenario registered=none, selected=unregistered, save_path=single_key, submitted_rate=unchanged, actor=update_perm */
    ['none', 'unregistered', 'single_key', 'unchanged', 'update_perm'],
    /** @scenario registered=none, selected=unregistered, save_path=single_key, submitted_rate=changed, actor=update_perm */
    ['none', 'unregistered', 'single_key', 'changed', 'update_perm'],
    /** @scenario registered=none, selected=unregistered, save_path=single_key, submitted_rate=new_currency, actor=update_perm */
    ['none', 'unregistered', 'single_key', 'new_currency', 'update_perm'],
    /** @scenario registered=none, selected=unregistered, save_path=single_key, submitted_rate=unsupported_code, actor=update_perm */
    ['none', 'unregistered', 'single_key', 'unsupported_code', 'update_perm'],
    /** @scenario registered=none, selected=unregistered, save_path=single_key, submitted_rate=base_row, actor=update_perm */
    ['none', 'unregistered', 'single_key', 'base_row', 'update_perm'],
    /** @scenario registered=one, selected=none, save_path=bulk, submitted_rate=unchanged, actor=update_perm */
    ['one', 'none', 'bulk', 'unchanged', 'update_perm'],
    /** @scenario registered=one, selected=none, save_path=bulk, submitted_rate=unchanged, actor=read_only */
    ['one', 'none', 'bulk', 'unchanged', 'read_only'],
    /** @scenario registered=one, selected=none, save_path=bulk, submitted_rate=changed, actor=update_perm */
    ['one', 'none', 'bulk', 'changed', 'update_perm'],
    /** @scenario registered=one, selected=none, save_path=bulk, submitted_rate=changed, actor=read_only */
    ['one', 'none', 'bulk', 'changed', 'read_only'],
    /** @scenario registered=one, selected=none, save_path=bulk, submitted_rate=new_currency, actor=update_perm */
    ['one', 'none', 'bulk', 'new_currency', 'update_perm'],
    /** @scenario registered=one, selected=none, save_path=bulk, submitted_rate=new_currency, actor=read_only */
    ['one', 'none', 'bulk', 'new_currency', 'read_only'],
    /** @scenario registered=one, selected=none, save_path=bulk, submitted_rate=unsupported_code, actor=update_perm */
    ['one', 'none', 'bulk', 'unsupported_code', 'update_perm'],
    /** @scenario registered=one, selected=none, save_path=bulk, submitted_rate=unsupported_code, actor=read_only */
    ['one', 'none', 'bulk', 'unsupported_code', 'read_only'],
    /** @scenario registered=one, selected=none, save_path=bulk, submitted_rate=base_row, actor=update_perm */
    ['one', 'none', 'bulk', 'base_row', 'update_perm'],
    /** @scenario registered=one, selected=none, save_path=bulk, submitted_rate=base_row, actor=read_only */
    ['one', 'none', 'bulk', 'base_row', 'read_only'],
    /** @scenario registered=one, selected=none, save_path=single_key, submitted_rate=unchanged, actor=update_perm */
    ['one', 'none', 'single_key', 'unchanged', 'update_perm'],
    /** @scenario registered=one, selected=none, save_path=single_key, submitted_rate=changed, actor=update_perm */
    ['one', 'none', 'single_key', 'changed', 'update_perm'],
    /** @scenario registered=one, selected=none, save_path=single_key, submitted_rate=new_currency, actor=update_perm */
    ['one', 'none', 'single_key', 'new_currency', 'update_perm'],
    /** @scenario registered=one, selected=none, save_path=single_key, submitted_rate=unsupported_code, actor=update_perm */
    ['one', 'none', 'single_key', 'unsupported_code', 'update_perm'],
    /** @scenario registered=one, selected=none, save_path=single_key, submitted_rate=base_row, actor=update_perm */
    ['one', 'none', 'single_key', 'base_row', 'update_perm'],
    /** @scenario registered=one, selected=valid, save_path=bulk, submitted_rate=unchanged, actor=update_perm */
    ['one', 'valid', 'bulk', 'unchanged', 'update_perm'],
    /** @scenario registered=one, selected=valid, save_path=bulk, submitted_rate=unchanged, actor=read_only */
    ['one', 'valid', 'bulk', 'unchanged', 'read_only'],
    /** @scenario registered=one, selected=valid, save_path=bulk, submitted_rate=changed, actor=update_perm */
    ['one', 'valid', 'bulk', 'changed', 'update_perm'],
    /** @scenario registered=one, selected=valid, save_path=bulk, submitted_rate=changed, actor=read_only */
    ['one', 'valid', 'bulk', 'changed', 'read_only'],
    /** @scenario registered=one, selected=valid, save_path=bulk, submitted_rate=new_currency, actor=update_perm */
    ['one', 'valid', 'bulk', 'new_currency', 'update_perm'],
    /** @scenario registered=one, selected=valid, save_path=bulk, submitted_rate=new_currency, actor=read_only */
    ['one', 'valid', 'bulk', 'new_currency', 'read_only'],
    /** @scenario registered=one, selected=valid, save_path=bulk, submitted_rate=unsupported_code, actor=update_perm */
    ['one', 'valid', 'bulk', 'unsupported_code', 'update_perm'],
    /** @scenario registered=one, selected=valid, save_path=bulk, submitted_rate=unsupported_code, actor=read_only */
    ['one', 'valid', 'bulk', 'unsupported_code', 'read_only'],
    /** @scenario registered=one, selected=valid, save_path=bulk, submitted_rate=base_row, actor=update_perm */
    ['one', 'valid', 'bulk', 'base_row', 'update_perm'],
    /** @scenario registered=one, selected=valid, save_path=bulk, submitted_rate=base_row, actor=read_only */
    ['one', 'valid', 'bulk', 'base_row', 'read_only'],
    /** @scenario registered=one, selected=valid, save_path=single_key, submitted_rate=unchanged, actor=update_perm */
    ['one', 'valid', 'single_key', 'unchanged', 'update_perm'],
    /** @scenario registered=one, selected=valid, save_path=single_key, submitted_rate=changed, actor=update_perm */
    ['one', 'valid', 'single_key', 'changed', 'update_perm'],
    /** @scenario registered=one, selected=valid, save_path=single_key, submitted_rate=new_currency, actor=update_perm */
    ['one', 'valid', 'single_key', 'new_currency', 'update_perm'],
    /** @scenario registered=one, selected=valid, save_path=single_key, submitted_rate=unsupported_code, actor=update_perm */
    ['one', 'valid', 'single_key', 'unsupported_code', 'update_perm'],
    /** @scenario registered=one, selected=valid, save_path=single_key, submitted_rate=base_row, actor=update_perm */
    ['one', 'valid', 'single_key', 'base_row', 'update_perm'],
    /** @scenario registered=one, selected=unregistered, save_path=bulk, submitted_rate=unchanged, actor=update_perm */
    ['one', 'unregistered', 'bulk', 'unchanged', 'update_perm'],
    /** @scenario registered=one, selected=unregistered, save_path=bulk, submitted_rate=unchanged, actor=read_only */
    ['one', 'unregistered', 'bulk', 'unchanged', 'read_only'],
    /** @scenario registered=one, selected=unregistered, save_path=bulk, submitted_rate=changed, actor=update_perm */
    ['one', 'unregistered', 'bulk', 'changed', 'update_perm'],
    /** @scenario registered=one, selected=unregistered, save_path=bulk, submitted_rate=changed, actor=read_only */
    ['one', 'unregistered', 'bulk', 'changed', 'read_only'],
    /** @scenario registered=one, selected=unregistered, save_path=bulk, submitted_rate=new_currency, actor=update_perm */
    ['one', 'unregistered', 'bulk', 'new_currency', 'update_perm'],
    /** @scenario registered=one, selected=unregistered, save_path=bulk, submitted_rate=new_currency, actor=read_only */
    ['one', 'unregistered', 'bulk', 'new_currency', 'read_only'],
    /** @scenario registered=one, selected=unregistered, save_path=bulk, submitted_rate=unsupported_code, actor=update_perm */
    ['one', 'unregistered', 'bulk', 'unsupported_code', 'update_perm'],
    /** @scenario registered=one, selected=unregistered, save_path=bulk, submitted_rate=unsupported_code, actor=read_only */
    ['one', 'unregistered', 'bulk', 'unsupported_code', 'read_only'],
    /** @scenario registered=one, selected=unregistered, save_path=bulk, submitted_rate=base_row, actor=update_perm */
    ['one', 'unregistered', 'bulk', 'base_row', 'update_perm'],
    /** @scenario registered=one, selected=unregistered, save_path=bulk, submitted_rate=base_row, actor=read_only */
    ['one', 'unregistered', 'bulk', 'base_row', 'read_only'],
    /** @scenario registered=one, selected=unregistered, save_path=single_key, submitted_rate=unchanged, actor=update_perm */
    ['one', 'unregistered', 'single_key', 'unchanged', 'update_perm'],
    /** @scenario registered=one, selected=unregistered, save_path=single_key, submitted_rate=changed, actor=update_perm */
    ['one', 'unregistered', 'single_key', 'changed', 'update_perm'],
    /** @scenario registered=one, selected=unregistered, save_path=single_key, submitted_rate=new_currency, actor=update_perm */
    ['one', 'unregistered', 'single_key', 'new_currency', 'update_perm'],
    /** @scenario registered=one, selected=unregistered, save_path=single_key, submitted_rate=unsupported_code, actor=update_perm */
    ['one', 'unregistered', 'single_key', 'unsupported_code', 'update_perm'],
    /** @scenario registered=one, selected=unregistered, save_path=single_key, submitted_rate=base_row, actor=update_perm */
    ['one', 'unregistered', 'single_key', 'base_row', 'update_perm'],
    /** @scenario registered=two, selected=none, save_path=bulk, submitted_rate=unchanged, actor=update_perm */
    ['two', 'none', 'bulk', 'unchanged', 'update_perm'],
    /** @scenario registered=two, selected=none, save_path=bulk, submitted_rate=unchanged, actor=read_only */
    ['two', 'none', 'bulk', 'unchanged', 'read_only'],
    /** @scenario registered=two, selected=none, save_path=bulk, submitted_rate=changed, actor=update_perm */
    ['two', 'none', 'bulk', 'changed', 'update_perm'],
    /** @scenario registered=two, selected=none, save_path=bulk, submitted_rate=changed, actor=read_only */
    ['two', 'none', 'bulk', 'changed', 'read_only'],
    /** @scenario registered=two, selected=none, save_path=bulk, submitted_rate=new_currency, actor=update_perm */
    ['two', 'none', 'bulk', 'new_currency', 'update_perm'],
    /** @scenario registered=two, selected=none, save_path=bulk, submitted_rate=new_currency, actor=read_only */
    ['two', 'none', 'bulk', 'new_currency', 'read_only'],
    /** @scenario registered=two, selected=none, save_path=bulk, submitted_rate=unsupported_code, actor=update_perm */
    ['two', 'none', 'bulk', 'unsupported_code', 'update_perm'],
    /** @scenario registered=two, selected=none, save_path=bulk, submitted_rate=unsupported_code, actor=read_only */
    ['two', 'none', 'bulk', 'unsupported_code', 'read_only'],
    /** @scenario registered=two, selected=none, save_path=bulk, submitted_rate=base_row, actor=update_perm */
    ['two', 'none', 'bulk', 'base_row', 'update_perm'],
    /** @scenario registered=two, selected=none, save_path=bulk, submitted_rate=base_row, actor=read_only */
    ['two', 'none', 'bulk', 'base_row', 'read_only'],
    /** @scenario registered=two, selected=none, save_path=single_key, submitted_rate=unchanged, actor=update_perm */
    ['two', 'none', 'single_key', 'unchanged', 'update_perm'],
    /** @scenario registered=two, selected=none, save_path=single_key, submitted_rate=changed, actor=update_perm */
    ['two', 'none', 'single_key', 'changed', 'update_perm'],
    /** @scenario registered=two, selected=none, save_path=single_key, submitted_rate=new_currency, actor=update_perm */
    ['two', 'none', 'single_key', 'new_currency', 'update_perm'],
    /** @scenario registered=two, selected=none, save_path=single_key, submitted_rate=unsupported_code, actor=update_perm */
    ['two', 'none', 'single_key', 'unsupported_code', 'update_perm'],
    /** @scenario registered=two, selected=none, save_path=single_key, submitted_rate=base_row, actor=update_perm */
    ['two', 'none', 'single_key', 'base_row', 'update_perm'],
    /** @scenario registered=two, selected=valid, save_path=bulk, submitted_rate=unchanged, actor=update_perm */
    ['two', 'valid', 'bulk', 'unchanged', 'update_perm'],
    /** @scenario registered=two, selected=valid, save_path=bulk, submitted_rate=unchanged, actor=read_only */
    ['two', 'valid', 'bulk', 'unchanged', 'read_only'],
    /** @scenario registered=two, selected=valid, save_path=bulk, submitted_rate=changed, actor=update_perm */
    ['two', 'valid', 'bulk', 'changed', 'update_perm'],
    /** @scenario registered=two, selected=valid, save_path=bulk, submitted_rate=changed, actor=read_only */
    ['two', 'valid', 'bulk', 'changed', 'read_only'],
    /** @scenario registered=two, selected=valid, save_path=bulk, submitted_rate=new_currency, actor=update_perm */
    ['two', 'valid', 'bulk', 'new_currency', 'update_perm'],
    /** @scenario registered=two, selected=valid, save_path=bulk, submitted_rate=new_currency, actor=read_only */
    ['two', 'valid', 'bulk', 'new_currency', 'read_only'],
    /** @scenario registered=two, selected=valid, save_path=bulk, submitted_rate=unsupported_code, actor=update_perm */
    ['two', 'valid', 'bulk', 'unsupported_code', 'update_perm'],
    /** @scenario registered=two, selected=valid, save_path=bulk, submitted_rate=unsupported_code, actor=read_only */
    ['two', 'valid', 'bulk', 'unsupported_code', 'read_only'],
    /** @scenario registered=two, selected=valid, save_path=bulk, submitted_rate=base_row, actor=update_perm */
    ['two', 'valid', 'bulk', 'base_row', 'update_perm'],
    /** @scenario registered=two, selected=valid, save_path=bulk, submitted_rate=base_row, actor=read_only */
    ['two', 'valid', 'bulk', 'base_row', 'read_only'],
    /** @scenario registered=two, selected=valid, save_path=single_key, submitted_rate=unchanged, actor=update_perm */
    ['two', 'valid', 'single_key', 'unchanged', 'update_perm'],
    /** @scenario registered=two, selected=valid, save_path=single_key, submitted_rate=changed, actor=update_perm */
    ['two', 'valid', 'single_key', 'changed', 'update_perm'],
    /** @scenario registered=two, selected=valid, save_path=single_key, submitted_rate=new_currency, actor=update_perm */
    ['two', 'valid', 'single_key', 'new_currency', 'update_perm'],
    /** @scenario registered=two, selected=valid, save_path=single_key, submitted_rate=unsupported_code, actor=update_perm */
    ['two', 'valid', 'single_key', 'unsupported_code', 'update_perm'],
    /** @scenario registered=two, selected=valid, save_path=single_key, submitted_rate=base_row, actor=update_perm */
    ['two', 'valid', 'single_key', 'base_row', 'update_perm'],
    /** @scenario registered=two, selected=unregistered, save_path=bulk, submitted_rate=unchanged, actor=update_perm */
    ['two', 'unregistered', 'bulk', 'unchanged', 'update_perm'],
    /** @scenario registered=two, selected=unregistered, save_path=bulk, submitted_rate=unchanged, actor=read_only */
    ['two', 'unregistered', 'bulk', 'unchanged', 'read_only'],
    /** @scenario registered=two, selected=unregistered, save_path=bulk, submitted_rate=changed, actor=update_perm */
    ['two', 'unregistered', 'bulk', 'changed', 'update_perm'],
    /** @scenario registered=two, selected=unregistered, save_path=bulk, submitted_rate=changed, actor=read_only */
    ['two', 'unregistered', 'bulk', 'changed', 'read_only'],
    /** @scenario registered=two, selected=unregistered, save_path=bulk, submitted_rate=new_currency, actor=update_perm */
    ['two', 'unregistered', 'bulk', 'new_currency', 'update_perm'],
    /** @scenario registered=two, selected=unregistered, save_path=bulk, submitted_rate=new_currency, actor=read_only */
    ['two', 'unregistered', 'bulk', 'new_currency', 'read_only'],
    /** @scenario registered=two, selected=unregistered, save_path=bulk, submitted_rate=unsupported_code, actor=update_perm */
    ['two', 'unregistered', 'bulk', 'unsupported_code', 'update_perm'],
    /** @scenario registered=two, selected=unregistered, save_path=bulk, submitted_rate=unsupported_code, actor=read_only */
    ['two', 'unregistered', 'bulk', 'unsupported_code', 'read_only'],
    /** @scenario registered=two, selected=unregistered, save_path=bulk, submitted_rate=base_row, actor=update_perm */
    ['two', 'unregistered', 'bulk', 'base_row', 'update_perm'],
    /** @scenario registered=two, selected=unregistered, save_path=bulk, submitted_rate=base_row, actor=read_only */
    ['two', 'unregistered', 'bulk', 'base_row', 'read_only'],
    /** @scenario registered=two, selected=unregistered, save_path=single_key, submitted_rate=unchanged, actor=update_perm */
    ['two', 'unregistered', 'single_key', 'unchanged', 'update_perm'],
    /** @scenario registered=two, selected=unregistered, save_path=single_key, submitted_rate=changed, actor=update_perm */
    ['two', 'unregistered', 'single_key', 'changed', 'update_perm'],
    /** @scenario registered=two, selected=unregistered, save_path=single_key, submitted_rate=new_currency, actor=update_perm */
    ['two', 'unregistered', 'single_key', 'new_currency', 'update_perm'],
    /** @scenario registered=two, selected=unregistered, save_path=single_key, submitted_rate=unsupported_code, actor=update_perm */
    ['two', 'unregistered', 'single_key', 'unsupported_code', 'update_perm'],
    /** @scenario registered=two, selected=unregistered, save_path=single_key, submitted_rate=base_row, actor=update_perm */
    ['two', 'unregistered', 'single_key', 'base_row', 'update_perm'],
];
