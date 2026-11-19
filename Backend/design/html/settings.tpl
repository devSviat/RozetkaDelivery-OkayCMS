{$meta_title = $btr->sviat__rozetka__title scope=global}

<div class="main_header">
    <div class="main_header__item">
        <div class="main_header__inner">
            <div class="box_heading heading_page">{$btr->sviat__rozetka__title|escape}</div>
        </div>
    </div>
</div>

{if $rozetka_access.flags}
    <div id="rozetka_auth_notice" class="alert alert--icon alert--error" role="alert">
        <div class="alert__content">
            <div class="alert__title">{$btr->sviat__rozetka__auth_title|escape}</div>
            {foreach $rozetka_access.flags as $rozetka_failure}
                <p>{$btr->getTranslation('sviat__rozetka__auth_'|cat:$rozetka_failure.api)|escape}</p>
            {/foreach}
            <p class="mb-0">{$btr->sviat__rozetka__auth_settings_advice|escape}</p>
        </div>
    </div>
{/if}
{if $rozetka_access.warnings}
    <div id="rozetka_partner_notice" class="alert alert--icon alert--warning" role="alert">
        <div class="alert__content">
            {foreach $rozetka_access.warnings as $rozetka_warning}
                <p>{$btr->getTranslation($rozetka_warning.key)|escape} <b>{$rozetka_warning.value|escape}</b></p>
            {/foreach}
        </div>
    </div>
{/if}

{* Секрети в сторінку не потрапляють: поля токена й пароля завжди порожні, збережений токен — лише •••• і чотири останні символи. *}
<div id="rozetka_settings" class="rz-set" data-base="{$smarty.server.SCRIPT_NAME|escape}?controller=Sviat.RozetkaDelivery.RozetkaSettingsAdmin@">
<div class="row">
<div class="col-xl-6 col-lg-12">

<form method="post" id="rozetka_access_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__rozetka__access_box|escape}</div>
        <p class="text_grey">{$btr->sviat__rozetka__access_intro|escape}</p>
        {if $rozetka_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $rozetka_errors as $rozetka_error}
                    <p>{$btr->getTranslation($rozetka_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}
        {if $rozetka_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__rozetka__saved_message|escape}</p></div></div>
        {/if}

        <div class="form-group">
            <label class="heading_label" for="rozetka_token">{$btr->sviat__rozetka__token|escape}</label>
            <input id="rozetka_token" type="password" name="token" class="form-control" value=""
                   autocomplete="new-password" spellcheck="false"
                   placeholder="{if $rozetka_access.saved}{$btr->sviat__rozetka__token_stored|escape} ••••{$rozetka_access.token_tail|escape}. {$btr->sviat__rozetka__token_keep|escape}{else}{$btr->sviat__rozetka__token_empty|escape}{/if}">
            {if $rozetka_access.saved}
                <div class="rz-set__key-meta">
                    <span class="text_grey">{$btr->sviat__rozetka__token_stored|escape} ••••{$rozetka_access.token_tail|escape}</span>
                    <label class="rz-set__clear"><input type="checkbox" name="clear_token" value="1"> {$btr->sviat__rozetka__token_clear|escape}</label>
                </div>
            {/if}
        </div>

        <div class="rz-set__buttons">
            <button type="submit" name="rozetka_action" value="save_key" class="btn btn_small btn_blue"><span>{$btr->sviat__rozetka__save|escape}</span></button>
            <button type="submit" name="rozetka_action" value="check_key" class="btn btn_small btn-outline-info" data-rz-busy="{$btr->sviat__rozetka__checking|escape}"><span>{$btr->sviat__rozetka__check|escape}</span></button>
        </div>

        {if $rozetka_access.last_check}
            {assign var=rozetka_check value=$rozetka_access.last_check}
            <div id="rozetka_check_result" class="alert mt-1 {if $rozetka_check.has_problem}alert--warning{else}alert--success{/if}" role="status"><div class="alert__content">
                <div class="alert__title">{$btr->sviat__rozetka__result_title|escape}</div>
                <p class="text_grey">{$btr->sviat__rozetka__checked_at|escape} {$rozetka_check.checked_at|escape}</p>
                <p><span class="rz-set__badge rz-set__badge--{$rozetka_check.auth|escape}">{$btr->getTranslation('sviat__rozetka__status_'|cat:$rozetka_check.auth)|escape}</span></p>
                {if $rozetka_access.partner}
                    {assign var=rozetka_partner value=$rozetka_access.partner}
                    <dl class="rz-set__facts">
                        <dt>{$btr->sviat__rozetka__partner_name|escape}</dt><dd>{$rozetka_partner.name|escape}</dd>
                        <dt>{$btr->sviat__rozetka__partner_status|escape}</dt><dd>{$rozetka_partner.status|escape}</dd>
                        {if $rozetka_partner.contract_number}
                            <dt>{$btr->sviat__rozetka__partner_contract|escape}</dt><dd>{$rozetka_partner.contract_number|escape}{if $rozetka_partner.contract_expires}, {$btr->sviat__rozetka__partner_until|escape} {$rozetka_partner.contract_expires|escape}{/if}</dd>
                        {/if}
                        <dt>{$btr->sviat__rozetka__partner_prepayment|escape}</dt><dd>{$btr->getTranslation('sviat__rozetka__flag_'|cat:$rozetka_partner.has_prepayment)|escape}</dd>
                    </dl>
                {/if}
                {if $rozetka_check_message}
                    <p class="mt-1 mb-0"><span class="text_grey">{$btr->sviat__rozetka__api_answer|escape}:</span> {$rozetka_check_message|escape}</p>
                {/if}
                {if $rozetka_check.has_problem}
                    <p class="mt-1 mb-0">{$btr->sviat__rozetka__problem_advice|escape}</p>
                {/if}
            </div></div>
        {/if}
    </div>
</form>

<form method="post" id="rozetka_issue_form" autocomplete="off">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="rozetka_action" value="issue_token">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__rozetka__issue_box|escape}</div>
        <p class="text_grey">{$btr->sviat__rozetka__issue_intro|escape}</p>
        {if $rozetka_issued}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__rozetka__issue_done|escape}</p></div></div>
        {/if}
        {if $rozetka_issue_warning}
            <div class="alert alert--warning" role="status"><div class="alert__content"><p>{$btr->getTranslation($rozetka_issue_warning)|escape}</p></div></div>
        {/if}
        {if $rozetka_issue_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $rozetka_issue_errors as $rozetka_error}
                    <p>{$btr->getTranslation($rozetka_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}
        <div class="form-group">
            <label class="heading_label" for="rozetka_issue_phone">{$btr->sviat__rozetka__issue_phone|escape}</label>
            <input id="rozetka_issue_phone" class="form-control" type="tel" inputmode="tel" name="issue_phone" maxlength="20" autocomplete="off" value="{$rozetka_issue.phone|escape}" placeholder="380XXXXXXXXX">
        </div>
        <div class="form-group">
            <label class="heading_label" for="rozetka_issue_password">{$btr->sviat__rozetka__issue_password|escape}</label>
            <input id="rozetka_issue_password" class="form-control" type="password" name="issue_password" value="" autocomplete="new-password" spellcheck="false">
            <p class="text_grey mb-0">{$btr->sviat__rozetka__issue_password_hint|escape}</p>
        </div>
        {if $rozetka_access.token_id}
            <div class="form-group">
                <label class="rz-set__clear"><input type="checkbox" name="revoke_previous" value="1"> {$btr->sviat__rozetka__issue_revoke_previous|escape}</label>
            </div>
        {/if}
        <div class="rz-set__buttons">
            <button type="submit" class="btn btn_small btn_blue" data-rz-busy="{$btr->sviat__rozetka__issuing|escape}"><span>{$btr->sviat__rozetka__issue_button|escape}</span></button>
        </div>
    </div>
</form>

{if $rozetka_revoked || $rozetka_revoke_errors || $rozetka_access.saved}
    <div class="boxed mb-2" id="rozetka_revoke_box">
        <div class="heading_box mb-1">{$btr->sviat__rozetka__revoke_box|escape}</div>
        {if $rozetka_revoked}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__rozetka__revoke_done|escape}</p></div></div>
        {/if}
        {if $rozetka_revoke_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $rozetka_revoke_errors as $rozetka_error}
                    <p>{$btr->getTranslation($rozetka_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}
        {if $rozetka_access.saved && $rozetka_access.token_id}
            <form method="post" id="rozetka_revoke_form">
                <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                <p class="text_grey">{$btr->sviat__rozetka__revoke_intro|escape}</p>
                <div class="rz-set__buttons">
                    <button type="submit" name="rozetka_action" value="revoke_token" class="btn btn_small btn-danger" data-rz-confirm="{$btr->sviat__rozetka__revoke_confirm|escape}"><span>{$btr->sviat__rozetka__revoke_button|escape}</span></button>
                </div>
            </form>
        {elseif $rozetka_access.saved}
            <p class="text_grey mb-0">{$btr->sviat__rozetka__revoke_manual|escape}</p>
        {/if}
    </div>
{/if}

<form method="post" id="rozetka_sender_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="rozetka_action" value="sender">
    {$rozetka_s = $rozetka_sender.values}
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__rozetka__sender_box|escape}</div>
        <p class="text_grey">{$btr->sviat__rozetka__sender_intro|escape}</p>
        {if $rozetka_sender_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__rozetka__saved_message|escape}</p></div></div>
        {/if}
        {if $rozetka_sender_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $rozetka_sender_errors as $rozetka_error}
                    <p>{$btr->getTranslation($rozetka_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}
        <div class="form-group">
            <label class="heading_label" for="rozetka_sender_last_name">{$btr->sviat__rozetka__sender_last_name|escape}</label>
            <input id="rozetka_sender_last_name" class="form-control" type="text" name="sender_last_name" maxlength="64" required value="{$rozetka_s.last_name|escape}">
        </div>
        <div class="form-group">
            <label class="heading_label" for="rozetka_sender_first_name">{$btr->sviat__rozetka__sender_first_name|escape}</label>
            <input id="rozetka_sender_first_name" class="form-control" type="text" name="sender_first_name" maxlength="64" required value="{$rozetka_s.first_name|escape}">
        </div>
        <div class="form-group">
            <label class="heading_label" for="rozetka_sender_middle_name">{$btr->sviat__rozetka__sender_middle_name|escape}</label>
            <input id="rozetka_sender_middle_name" class="form-control" type="text" name="sender_middle_name" maxlength="64" value="{$rozetka_s.middle_name|escape}">
        </div>
        <div class="form-group">
            <label class="heading_label" for="rozetka_sender_phone">{$btr->sviat__rozetka__sender_phone|escape}</label>
            <input id="rozetka_sender_phone" class="form-control" type="tel" inputmode="tel" name="sender_phone" maxlength="20" {literal}pattern="[0-9 \(\)+\-]{10,20}"{/literal} value="{$rozetka_s.phone|escape}" placeholder="380XXXXXXXXX">
            <p class="text_grey mb-0">{$btr->sviat__rozetka__sender_phone_hint|escape}</p>
        </div>

        <div class="form-group" id="rozetka_sender_city">
            <label class="heading_label" for="rozetka_sender_city_input">{$btr->sviat__rozetka__sender_city|escape}</label>
            <input type="hidden" id="rozetka_sender_city_id" name="sender_city_id" value="{$rozetka_s.city_id|escape}">
            <div class="rz-set__city-chosen" {if !$rozetka_sender.city}hidden{/if}>
                <span class="rz-set__city-name">{if $rozetka_sender.city}<span class="text_grey">{$rozetka_sender.city.type|escape}</span> <b>{$rozetka_sender.city.name|escape}</b>{if $rozetka_sender.city.region}, {$rozetka_sender.city.region|escape}{/if}{/if}</span>
                <button type="button" class="btn btn_small btn-outline-info rz-set__city-change"><span>{$btr->sviat__rozetka__sender_city_change|escape}</span></button>
            </div>
            <div class="rz-set__search" {if $rozetka_sender.city}hidden{/if}>
                <input id="rozetka_sender_city_input" class="form-control rz-set__city-input" type="text" autocomplete="off" placeholder="{$btr->sviat__rozetka__sender_city_placeholder|escape}">
                <ul class="rz-set__suggest" role="listbox" hidden></ul>
            </div>
        </div>
        <div class="form-group">
            <label class="heading_label" for="rozetka_sender_point_id">{$btr->sviat__rozetka__sender_point|escape}</label>
            <select id="rozetka_sender_point_id" name="sender_point_id" class="selectpicker form-control" data-live-search="true" data-size="10">
                <option value="">{$btr->sviat__rozetka__sender_point_choose|escape}</option>
                {foreach $rozetka_sender.points as $rozetka_point}
                    <option value="{$rozetka_point.id|escape}" {if $rozetka_point.id == $rozetka_s.point_id}selected{/if}>{$rozetka_point.title|escape}</option>
                {/foreach}
            </select>
            <p class="text_grey mb-0">{$btr->sviat__rozetka__sender_point_hint|escape}</p>
        </div>

        <div class="rz-set__buttons">
            <button type="submit" class="btn btn_small btn_blue"><span>{$btr->sviat__rozetka__save|escape}</span></button>
        </div>
    </div>
</form>

<form method="post" id="rozetka_dict_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="rozetka_action" value="dictionaries_run">
    {$rozetka_d = $rozetka_dict}
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__rozetka__dict_box|escape}</div>
        <p class="text_grey">{$btr->sviat__rozetka__dict_intro|escape}</p>
        {if $rozetka_dict_requested}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__rozetka__dict_requested|escape}</p></div></div>
        {/if}
        <dl class="rz-set__facts">
            <dt>{$btr->sviat__rozetka__dict_state|escape}</dt>
            <dd><span class="rz-set__badge rz-set__badge--{$rozetka_d.state|escape}">{$btr->getTranslation('sviat__rozetka__dict_state_'|cat:$rozetka_d.state)|escape}</span></dd>
            {if $rozetka_d.updated_at}
                <dt>{$btr->sviat__rozetka__dict_updated|escape}</dt><dd>{$rozetka_d.updated_at|escape}</dd>
            {/if}
            {if $rozetka_d.attempted_at}
                <dt>{$btr->sviat__rozetka__dict_attempted|escape}</dt><dd>{$rozetka_d.attempted_at|escape}</dd>
            {/if}
            <dt>{$btr->sviat__rozetka__dict_cities|escape}</dt><dd>{$rozetka_d.counts.cities|escape}</dd>
            {foreach ['pickup', 'store', 'postomat', 'partner', 'other'] as $rozetka_kind}
                <dt>{$btr->getTranslation('sviat__rozetka__groups_kind_'|cat:$rozetka_kind)|escape}</dt><dd>{$rozetka_d.counts[$rozetka_kind]|escape}</dd>
            {/foreach}
            <dt>{$btr->sviat__rozetka__dict_statuses|escape}</dt><dd>{$rozetka_d.counts.statuses|escape}</dd>
        </dl>
        {if $rozetka_d.reason}
            <p class="mt-1 mb-0">{$btr->getTranslation('sviat__rozetka__dict_reason_'|cat:$rozetka_d.reason)|escape} <span class="text_grey">({$rozetka_d.received|escape} / {$rozetka_d.total|escape})</span></p>
        {/if}
        {if $rozetka_d.error}
            <p class="mt-1 mb-0">
                <span class="text_grey">{$btr->sviat__rozetka__dict_error|escape}:</span>
                {$btr->getTranslation('sviat__rozetka__dict_err_'|cat:$rozetka_d.error.kind)|escape}
                {if $rozetka_d.error.detail}<span class="text_grey">({$rozetka_d.error.detail|escape})</span>{/if}
            </p>
        {/if}
        {if $rozetka_d.statuses_error}
            <p class="mt-1 mb-0">{$btr->sviat__rozetka__dict_statuses_failed|escape} <span class="text_grey">({$btr->getTranslation('sviat__rozetka__dict_err_'|cat:$rozetka_d.statuses_error)|escape})</span></p>
        {/if}
        {if $rozetka_d.queued}
            <p class="mt-1 mb-0">{if $rozetka_d.scheduler_idle}{$btr->sviat__rozetka__dict_scheduler_idle|escape}{else}{$btr->sviat__rozetka__dict_queued|escape}{/if}</p>
        {/if}
        {if $rozetka_d.prepay_mismatch.count}
            <div class="alert alert--warning mt-1" role="status"><div class="alert__content">
                <div class="alert__title">{$btr->sviat__rozetka__dict_prepay_mismatch|escape} {$rozetka_d.prepay_mismatch.count|escape}</div>
                <ul>
                    {foreach $rozetka_d.prepay_mismatch.names as $rozetka_name}
                        <li>{$rozetka_name|escape}</li>
                    {/foreach}
                </ul>
            </div></div>
        {/if}
        {if $rozetka_d.limit_units_suspect}
            <div class="alert alert--info mt-1" role="status"><div class="alert__content">
                <p class="mb-0">{$btr->sviat__rozetka__dict_limit_units|escape} {$rozetka_d.limit_units_suspect|escape}</p>
            </div></div>
        {/if}
        {if $rozetka_d.unknown_types}
            <div class="alert alert--info mt-1" role="status"><div class="alert__content">
                <div class="alert__title">{$btr->sviat__rozetka__dict_unknown_types|escape}</div>
                <ul>
                    {foreach $rozetka_d.unknown_types as $rozetka_type}
                        <li>{$rozetka_type.type|escape}: {$rozetka_type.count|escape}</li>
                    {/foreach}
                </ul>
            </div></div>
        {/if}
        <div class="rz-set__buttons">
            <button type="submit" class="btn btn_small btn_blue" data-rz-busy="{$btr->sviat__rozetka__dict_requesting|escape}" {if !$rozetka_d.can_run}disabled{/if}><span>{$btr->sviat__rozetka__dict_run|escape}</span></button>
        </div>
    </div>
</form>

<form method="post" id="rozetka_shipping_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="rozetka_action" value="shipping">
    {$rozetka_p = $rozetka_shipping.values}
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__rozetka__shipping_box|escape}</div>
        <p class="text_grey">{$btr->sviat__rozetka__shipping_intro|escape}</p>
        {if $rozetka_shipping_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__rozetka__saved_message|escape}</p></div></div>
        {/if}
        {if $rozetka_shipping_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $rozetka_shipping_errors as $rozetka_error}
                    <p>{$btr->getTranslation($rozetka_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}
        <div class="form-group">
            <label class="heading_label" for="rozetka_cart_price">{$btr->sviat__rozetka__shipping_cart_price|escape}</label>
            <input id="rozetka_cart_price" class="form-control" type="number" inputmode="decimal" name="cart_price" min="0" max="99999.99" step="0.01" required value="{$rozetka_p.cart_price|escape}">
            <p class="text_grey mb-0">{$btr->sviat__rozetka__shipping_cart_price_hint|escape}</p>
        </div>
        <div class="form-group">
            <label class="rz-set__switch">
                <span class="switch switch-default">
                    <input class="switch-input" type="checkbox" name="receiver_price_note" value="1" {if $rozetka_p.receiver_price_note}checked{/if}>
                    <span class="switch-label"></span>
                    <span class="switch-handle"></span>
                </span>
                <span><b>{$btr->sviat__rozetka__shipping_receiver_price_note|escape}</b></span>
            </label>
        </div>
        <div class="form-group">
            <label class="rz-set__switch">
                <span class="switch switch-default">
                    <input class="switch-input" type="checkbox" name="receiver_pay" value="1" {if $rozetka_p.receiver_pay}checked{/if}>
                    <span class="switch-label"></span>
                    <span class="switch-handle"></span>
                </span>
                <span><b>{$btr->sviat__rozetka__shipping_receiver_pay|escape}</b></span>
            </label>
            <p class="text_grey mb-0">{$btr->sviat__rozetka__shipping_receiver_pay_hint|escape}</p>
        </div>
        <div class="form-group">
            <label class="heading_label" for="rozetka_default_weight">{$btr->sviat__rozetka__shipping_weight|escape}</label>
            <input id="rozetka_default_weight" class="form-control" type="number" inputmode="decimal" name="default_weight" min="0.001" max="15" step="0.001" required value="{$rozetka_p.weight|escape}">
        </div>
        <div class="form-group">
            <span class="heading_label">{$btr->sviat__rozetka__shipping_box_size|escape}</span>
            <div class="rz-set__box">
                <input class="form-control" type="number" inputmode="numeric" name="box_length" min="1" max="120" step="1" required aria-label="{$btr->sviat__rozetka__shipping_length|escape}" value="{$rozetka_p.length|escape}">
                <span>×</span>
                <input class="form-control" type="number" inputmode="numeric" name="box_width" min="1" max="120" step="1" required aria-label="{$btr->sviat__rozetka__shipping_width|escape}" value="{$rozetka_p.width|escape}">
                <span>×</span>
                <input class="form-control" type="number" inputmode="numeric" name="box_height" min="1" max="120" step="1" required aria-label="{$btr->sviat__rozetka__shipping_height|escape}" value="{$rozetka_p.height|escape}">
            </div>
            <p class="text_grey mb-0">{$btr->sviat__rozetka__shipping_box_hint|escape}</p>
        </div>
        <div class="form-group">
            <label class="rz-set__switch">
                <span class="switch switch-default">
                    <input class="switch-input" type="checkbox" name="declare_total" value="1" {if $rozetka_p.declare_total}checked{/if}>
                    <span class="switch-label"></span>
                    <span class="switch-handle"></span>
                </span>
                <span><b>{$btr->sviat__rozetka__shipping_declare_total|escape}</b></span>
            </label>
        </div>
        <div class="form-group">
            <label class="heading_label" for="rozetka_declared_value">{$btr->sviat__rozetka__shipping_declared_value|escape}</label>
            <input id="rozetka_declared_value" class="form-control" type="number" inputmode="decimal" name="declared_value" min="0" max="30000" step="0.01" value="{$rozetka_p.declared_value|escape}">
            <p class="text_grey mb-0">{$btr->sviat__rozetka__shipping_declared_value_hint|escape}</p>
        </div>
        <div class="form-group">
            <label class="heading_label" for="rozetka_description">{$btr->sviat__rozetka__shipping_description|escape}</label>
            <input id="rozetka_description" class="form-control" type="text" name="description" maxlength="250" value="{$rozetka_p.description|escape}">
            <p class="text_grey mb-0">{$btr->sviat__rozetka__shipping_description_hint|escape}</p>
        </div>
        <div class="form-group">
            <label class="heading_label" for="rozetka_cutoff">{$btr->sviat__rozetka__shipping_cutoff|escape}</label>
            <select id="rozetka_cutoff" name="cutoff" class="selectpicker form-control" data-size="8">
                {foreach $rozetka_shipping.cutoff_choices as $rozetka_slot}
                    <option value="{$rozetka_slot|escape}" {if $rozetka_slot == $rozetka_p.cutoff}selected{/if}>{$rozetka_slot|escape}</option>
                {/foreach}
            </select>
            <p class="text_grey mb-0">{$btr->sviat__rozetka__shipping_cutoff_hint|escape}</p>
        </div>
        <div class="rz-set__buttons">
            <button type="submit" class="btn btn_small btn_blue"><span>{$btr->sviat__rozetka__save|escape}</span></button>
        </div>
    </div>
</form>

<form method="post" id="rozetka_cod_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="rozetka_action" value="cod">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__rozetka__cod_box|escape}</div>
        <p class="text_grey">{$btr->sviat__rozetka__cod_intro|escape}</p>
        {if $rozetka_cod_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__rozetka__saved_message|escape}</p></div></div>
        {/if}
        {if $rozetka_cod_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $rozetka_cod_errors as $rozetka_error}
                    <p>{$btr->getTranslation($rozetka_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}
        {if $rozetka_cod->enabled && $rozetka_cod->fiscal == 'unknown'}
            <div class="alert alert--warning" role="status"><div class="alert__content"><p>{$btr->sviat__rozetka__cod_fiscal_unknown_warning|escape}</p></div></div>
        {/if}
        <div class="form-group">
            <label class="rz-set__switch">
                <span class="switch switch-default">
                    <input class="switch-input" type="checkbox" name="cod_enabled" value="1" {if $rozetka_cod->enabled}checked{/if}>
                    <span class="switch-label"></span>
                    <span class="switch-handle"></span>
                </span>
                <span><b>{$btr->sviat__rozetka__cod_enabled|escape}</b></span>
            </label>
        </div>
        <div class="form-group">
            <span class="heading_label">{$btr->sviat__rozetka__cod_fee|escape}</span>
            <div class="rz-set__box">
                <input class="form-control" type="number" inputmode="decimal" name="cod_fee_fixed" min="0" max="1000" step="0.01" required aria-label="{$btr->sviat__rozetka__cod_fee_fixed|escape}" value="{$rozetka_cod->feeFixed|escape}">
                <span>{$btr->sviat__rozetka__cod_fee_plus|escape}</span>
                <input class="form-control" type="number" inputmode="decimal" name="cod_fee_percent" min="0" max="100" step="0.01" required aria-label="{$btr->sviat__rozetka__cod_fee_percent|escape}" value="{$rozetka_cod->feePercent|escape}">
                <span>%</span>
            </div>
            <p class="text_grey mb-0">{$btr->sviat__rozetka__cod_fee_hint|escape}</p>
        </div>
        <div class="form-group">
            <label class="heading_label" for="rozetka_cod_fiscal">{$btr->sviat__rozetka__cod_fiscal|escape}</label>
            <select id="rozetka_cod_fiscal" name="cod_fiscal" class="selectpicker form-control">
                {foreach ['unknown', 'shop', 'provider'] as $rozetka_fiscal}
                    <option value="{$rozetka_fiscal|escape}" {if $rozetka_cod->fiscal == $rozetka_fiscal}selected{/if}>{$btr->getTranslation('sviat__rozetka__cod_fiscal_'|cat:$rozetka_fiscal)|escape}</option>
                {/foreach}
            </select>
        </div>
        <div class="rz-set__buttons">
            <button type="submit" class="btn btn_small btn_blue"><span>{$btr->sviat__rozetka__save|escape}</span></button>
        </div>
    </div>
</form>

</div>
<div class="col-xl-6 col-lg-12">

<form method="post" id="rozetka_groups_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="rozetka_action" value="groups">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__rozetka__groups_box|escape}</div>
        <p class="text_grey">{$btr->sviat__rozetka__groups_intro|escape}</p>
        {if $rozetka_groups_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__rozetka__groups_saved|escape}</p></div></div>
        {/if}
        {if $rozetka_groups_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $rozetka_groups_errors as $rozetka_error}
                    <p>{$btr->getTranslation($rozetka_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}

        <div class="heading_label">{$btr->sviat__rozetka__groups_list|escape}</div>
        <div id="rozetka_group_rows" class="sortable">
            {foreach $rozetka_groups.groups as $rozetka_group}
                <div class="rz-set__group">
                    <input type="hidden" data-field="id" value="{$rozetka_group.id|escape}">
                    <div class="rz-set__drag move_zone" title="{$btr->sviat__rozetka__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                    <input type="text" class="form-control rz-set__group-name" data-field="name" value="{$rozetka_group.name|escape}" maxlength="255" aria-label="{$btr->sviat__rozetka__groups_name|escape}">
                    <label class="rz-set__inline">
                        <span>{$btr->sviat__rozetka__groups_enabled|escape}</span>
                        <span class="switch switch-default">
                            <input class="switch-input" type="checkbox" data-field="enabled" value="1" {if $rozetka_group.enabled}checked{/if}>
                            <span class="switch-label"></span>
                            <span class="switch-handle"></span>
                        </span>
                    </label>
                    <input type="hidden" data-field="delete" value="">
                    <button type="button" class="btn_close rz-set__remove rz-set__group-remove" aria-label="{$btr->sviat__rozetka__groups_delete|escape}" title="{$btr->sviat__rozetka__groups_delete|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
                </div>
            {/foreach}
            <div class="rz-set__group rz-set__group--new" hidden>
                <input type="hidden" data-field="id" value="new">
                <div class="rz-set__drag move_zone" title="{$btr->sviat__rozetka__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                <input type="text" class="form-control rz-set__group-name" data-field="name" value="" maxlength="255" aria-label="{$btr->sviat__rozetka__groups_name|escape}">
                <label class="rz-set__inline">
                    <span>{$btr->sviat__rozetka__groups_enabled|escape}</span>
                    <span class="switch switch-default">
                        <input class="switch-input" type="checkbox" data-field="enabled" value="1" checked>
                        <span class="switch-label"></span>
                        <span class="switch-handle"></span>
                    </span>
                </label>
                <button type="button" class="btn_close rz-set__remove rz-set__group-remove" aria-label="{$btr->sviat__rozetka__groups_delete|escape}" title="{$btr->sviat__rozetka__groups_delete|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
            </div>
        </div>
        <button type="button" class="btn btn_mini btn-secondary btn_openSans mt-1 mb-2" id="rozetka_group_add">
            {include file='svg_icon.tpl' svgId='plus'}
            <span>{$btr->sviat__rozetka__groups_add|escape}</span>
        </button>

        <div class="heading_label">{$btr->sviat__rozetka__groups_kinds|escape}</div>
        {foreach $rozetka_groups.kinds as $rozetka_kind}
            <div class="rz-set__kind">
                <div>
                    <b>{$btr->getTranslation($rozetka_kind.label_key)|escape}</b>
                    <span class="text_grey">{$rozetka_kind.count|escape} {$btr->sviat__rozetka__groups_points|escape}</span>
                </div>
                <select name="kinds[{$rozetka_kind.key|escape}]" class="selectpicker form-control rz-set__kind-group" aria-label="{$btr->sviat__rozetka__groups_group|escape}">
                    <option value="0" {if !$rozetka_kind.group}selected{/if}>{$btr->sviat__rozetka__groups_none|escape}</option>
                    {foreach $rozetka_groups.groups as $rozetka_group}
                        <option value="{$rozetka_group.id|escape}" {if $rozetka_kind.group == $rozetka_group.id}selected{/if}>{$rozetka_group.name|escape}</option>
                    {/foreach}
                </select>
                {if $rozetka_kind.warning}
                    <div class="rz-set__warning">{$btr->getTranslation('sviat__rozetka__groups_warn_'|cat:$rozetka_kind.warning)|escape}</div>
                {/if}
            </div>
        {/foreach}

        <button type="submit" class="btn btn_small btn_blue mt-1"><span>{$btr->sviat__rozetka__save|escape}</span></button>
    </div>
</form>

<form method="post" id="rozetka_popular_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="rozetka_action" value="popular">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__rozetka__popular_box|escape}</div>
        <p class="text_grey">{$btr->sviat__rozetka__popular_intro|escape}</p>
        {if $rozetka_popular_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__rozetka__popular_saved|escape}</p></div></div>
        {/if}
        <div id="rozetka_popular_rows" class="sortable">
            {foreach $rozetka_popular as $rozetka_city}
                <div class="rz-set__popular">
                    <input type="hidden" name="popular[]" value="{$rozetka_city.id|escape}">
                    <div class="rz-set__drag move_zone" title="{$btr->sviat__rozetka__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                    <div class="rz-set__popular-name"><b>{$rozetka_city.name|escape}</b> <span class="text_grey">{$rozetka_city.region|escape}</span></div>
                    <button type="button" class="btn_close rz-set__remove" aria-label="{$btr->sviat__rozetka__popular_remove|escape}" title="{$btr->sviat__rozetka__popular_remove|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
                </div>
            {/foreach}
        </div>
        <template id="rozetka_popular_template">
            <div class="rz-set__popular">
                <input type="hidden" name="popular[]" value="">
                <div class="rz-set__drag move_zone" title="{$btr->sviat__rozetka__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                <div class="rz-set__popular-name"><b></b> <span class="text_grey"></span></div>
                <button type="button" class="btn_close rz-set__remove" aria-label="{$btr->sviat__rozetka__popular_remove|escape}" title="{$btr->sviat__rozetka__popular_remove|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
            </div>
        </template>
        <p class="text_grey rz-set__popular-empty" {if $rozetka_popular}hidden{/if}>{$btr->sviat__rozetka__popular_empty|escape}</p>
        <div class="rz-set__search" id="rozetka_popular_search">
            <label class="heading_label" for="rozetka_popular_input">{$btr->sviat__rozetka__popular_add|escape}</label>
            <input id="rozetka_popular_input" class="form-control rz-set__city-input" type="text" autocomplete="off" placeholder="{$btr->sviat__rozetka__sender_city_placeholder|escape}">
            <ul class="rz-set__suggest" role="listbox" hidden></ul>
        </div>
        <button type="submit" class="btn btn_small btn_blue mt-1"><span>{$btr->sviat__rozetka__save|escape}</span></button>
    </div>
</form>

</div>
</div>
</div>
