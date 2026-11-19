{* Блок ЕН ROZETKA. Він усередині форми замовлення, тож поля мають data-field замість name:
   збереження замовлення не повинне їх надсилати. Дії йдуть у ParcelAdmin окремими POST. *}
{if $rozetka_parcel}
{$s = $rozetka_parcel}
{function name=rz_parcel_error field=''}<span class="rz-parcel__error" data-for="{$field|escape}">{if isset($s.issues[$field])}{$btr->getTranslation($s.issues[$field])|escape}{/if}</span>{/function}
{function name=rz_parcel_attach}
<div class="rz-parcel__attach" hidden>
    <div class="rz-parcel__row">
        <input class="form-control" data-attach="track" maxlength="20" autocomplete="off" spellcheck="false" placeholder="{$btr->sviat__rozetka__parcel_attach_placeholder|escape}" aria-label="{$btr->sviat__rozetka__parcel_attach_placeholder|escape}">
        <button type="button" class="btn btn_small btn_border_blue fn_rz_parcel_attach">{$btr->sviat__rozetka__parcel_attach|escape}</button>
    </div>
    <div class="rz-parcel__issues rz-parcel__issues--attach" role="alert"></div>
</div>
{/function}
<div class="rz-parcel fn_rz_parcel{if $s.mode == 'card'} rz-parcel--card{/if}" data-url="{$smarty.server.SCRIPT_NAME|escape}?controller=Sviat.RozetkaDelivery.ParcelAdmin" data-order="{$s.orderId|escape}" data-token="{$smarty.session.id|escape}"
     data-confirm-delete="{$btr->sviat__rozetka__parcel_confirm_delete|escape}" data-confirm-unlink="{$btr->sviat__rozetka__parcel_confirm_unlink|escape}" data-confirm-unlink-check="{$btr->sviat__rozetka__parcel_confirm_unlink_check|escape}"
     data-busy="{$btr->sviat__rozetka__parcel_creating|escape}" data-failed="{$btr->sviat__rozetka__parcel_failed|escape}" data-network="{$btr->sviat__rozetka__op_network|escape}" data-expired="{$btr->sviat__rozetka__parcel_err_session|escape}" data-unexpected="{$btr->sviat__rozetka__parcel_err_unexpected|escape}"
     data-toast-ok="{$btr->toastr_success|escape}" data-toast-error="{$btr->toastr_error|escape}" data-order-text="{$btr->sviat__rozetka__parcel_open_order|escape}" data-choose="{$btr->sviat__rozetka__parcel_choose|escape}"
     data-done-create="{$btr->sviat__rozetka__parcel_done_create|escape}" data-done-attach="{$btr->sviat__rozetka__parcel_done_attach|escape}" data-done-lookup="{$btr->sviat__rozetka__parcel_done_lookup|escape}" data-done-choose="{$btr->sviat__rozetka__parcel_done_lookup|escape}" data-done-delete="{$btr->sviat__rozetka__parcel_done_delete|escape}" data-done-unlink="{$btr->sviat__rozetka__parcel_done_unlink|escape}" data-done-refresh="{$btr->sviat__rozetka__parcel_done_refresh|escape}">
    <div class="rz-parcel__head">
        <span class="{if $s.mode == 'card'}heading_box{else}heading_label{/if}">{if $s.mode == 'form'}{$btr->sviat__rozetka__parcel_form_title|escape}{else}{$btr->sviat__rozetka__parcel_title|escape}{/if}</span>
    </div>

    {if $s.auth}
        <div class="alert alert--error rz-parcel__auth" role="alert"><div class="alert__content">
            <p class="text_600">{$btr->sviat__rozetka__auth_title|escape}</p>
            {foreach $s.auth as $rz_failure}
                <p>{$btr->getTranslation('sviat__rozetka__auth_'|cat:$rz_failure.api)|escape}</p>
            {/foreach}
            <p class="text_grey">{$btr->sviat__rozetka__parcel_advice_key|escape}</p>
        </div></div>
    {/if}
    <div class="rz-parcel__notices" role="status"></div>

    {if $s.mode == 'check'}
        <div class="alert alert--warning"><div class="alert__content"><p>{$btr->sviat__rozetka__parcel_check_text|escape}</p></div></div>
        <div class="rz-parcel__actions">
            <button type="button" class="btn btn_small btn_blue fn_rz_parcel_lookup">{$btr->sviat__rozetka__parcel_lookup|escape}</button>
            <button type="button" class="btn btn_small btn_border_blue fn_rz_parcel_attach_toggle" aria-expanded="false">{$btr->sviat__rozetka__parcel_attach_open|escape}</button>
        </div>
        <div class="rz-parcel__candidates" hidden></div>
        {rz_parcel_attach}
        <div class="rz-parcel__issues" role="alert"></div>
        <div class="rz-parcel__unlink">
            <p class="text_grey">{$btr->sviat__rozetka__parcel_unlink_check_warning|escape}</p>
            <button type="button" class="btn btn_small btn_border_blue fn_rz_parcel_unlink" data-uncertain="1">{$btr->sviat__rozetka__parcel_not_created|escape}</button>
        </div>
    {elseif $s.mode == 'pending' || $s.mode == 'refusal'}
        <div class="alert alert--{if $s.mode == 'pending'}info{else}warning{/if}"><div class="alert__content"><p>{$btr->getTranslation($s.refusalKey)|escape}</p></div></div>
        {if isset($s.can.attach)}
            <div class="rz-parcel__actions"><button type="button" class="btn btn_small btn_border_blue fn_rz_parcel_attach_toggle" aria-expanded="false">{$btr->sviat__rozetka__parcel_attach_open|escape}</button></div>
            {rz_parcel_attach}
        {/if}
        <div class="rz-parcel__issues" role="alert"></div>
    {elseif $s.mode == 'form'}
        {$f = $s.form}
        <div class="rz-parcel__form">
            <div class="rz-parcel__caps">{$btr->sviat__rozetka__parcel_receiver|escape}</div>
            <div class="rz-parcel__grid rz-parcel__grid--three">
                <label class="rz-parcel__field"><span class="heading_label">{$btr->sviat__rozetka__parcel_last_name|escape}</span><input class="form-control" data-field="last_name" maxlength="100" autocomplete="off" value="{$f.lastName|escape}"></label>
                <label class="rz-parcel__field"><span class="heading_label">{$btr->sviat__rozetka__parcel_first_name|escape}</span><input class="form-control" data-field="first_name" maxlength="100" autocomplete="off" value="{$f.firstName|escape}"></label>
                <label class="rz-parcel__field"><span class="heading_label">{$btr->sviat__rozetka__parcel_middle_name|escape}</span><input class="form-control" data-field="middle_name" maxlength="100" autocomplete="off" value="{$f.middleName|escape}"></label>
            </div>
            {rz_parcel_error field='last_name'}
            <label class="rz-parcel__field"><span class="heading_label">{$btr->sviat__rozetka__parcel_phone|escape}</span><input class="form-control" data-field="phone" maxlength="20" inputmode="tel" autocomplete="off" value="{$f.phone|escape}"></label>
            {rz_parcel_error field='phone'}
            {if $f.point}<div class="rz-parcel__line"><span class="rz-parcel__label">{$btr->sviat__rozetka__parcel_point|escape}</span>{$f.point|escape}</div>{/if}
            {rz_parcel_error field='point'}

            <div class="rz-parcel__caps">{$btr->sviat__rozetka__parcel_section|escape}</div>
            <label class="rz-parcel__field"><span class="heading_label">{$btr->sviat__rozetka__parcel_weight|escape}</span><input class="form-control" data-field="weight" inputmode="decimal" value="{$f.weight|escape}"></label>
            {rz_parcel_error field='weight'}
            <span class="heading_label">{$btr->sviat__rozetka__parcel_box_size|escape}</span>
            <div class="rz-parcel__grid rz-parcel__grid--three">
                <input class="form-control" data-field="length" inputmode="numeric" value="{$f.length|escape}" aria-label="{$btr->sviat__rozetka__parcel_length|escape}" title="{$btr->sviat__rozetka__parcel_length|escape}">
                <input class="form-control" data-field="width" inputmode="numeric" value="{$f.width|escape}" aria-label="{$btr->sviat__rozetka__parcel_width|escape}" title="{$btr->sviat__rozetka__parcel_width|escape}">
                <input class="form-control" data-field="height" inputmode="numeric" value="{$f.height|escape}" aria-label="{$btr->sviat__rozetka__parcel_height|escape}" title="{$btr->sviat__rozetka__parcel_height|escape}">
            </div>
            {rz_parcel_error field='sides'}
            {$rz_limit = $f.costLimit}
            <label class="rz-parcel__field"><span class="heading_label">{$btr->sviat__rozetka__parcel_declared|escape}</span><input class="form-control" data-field="declared_value" inputmode="decimal" value="{$f.declared|escape}"></label>
            <span class="rz-parcel__hint">{$btr->sviat__rozetka__parcel_declared_limit|replace:'%limit%':$rz_limit|escape}</span>
            {rz_parcel_error field='declared_value'}
            <label class="rz-parcel__field"><span class="heading_label">{$btr->sviat__rozetka__parcel_payer|escape}</span><select class="form-control" data-field="payer">
                <option value="receiver" {if $f.payer == 'receiver'}selected{/if}>{$btr->sviat__rozetka__parcel_party_receiver|escape}</option>
                <option value="sender" {if $f.payer == 'sender'}selected{/if}>{$btr->sviat__rozetka__parcel_party_sender|escape}</option>
            </select></label>
            {if $f.payerReason == 'in_total' || $f.payerReason == 'free'}<span class="rz-parcel__hint">{$btr->getTranslation('sviat__rozetka__parcel_reason_'|cat:$f.payerReason)|escape}</span>{/if}
            {rz_parcel_error field='payer'}
            {if $f.codAllowed}
                <label class="rz-parcel__field"><span class="heading_label">{$btr->sviat__rozetka__parcel_cod_amount|escape}</span><input class="form-control" data-field="cod_amount" inputmode="decimal" value="{$f.codAmount|escape}"></label>
                {if $f.codFee !== null}{$rz_fee = $f.codFee}<span class="rz-parcel__hint">{$btr->sviat__rozetka__parcel_cod_fee_estimate|replace:'%fee%':$rz_fee|escape}</span>{/if}
                {* Аванс передоплати (Checkbox) зменшує суму післяплати; Checkbox показує тут своє попередження. *}
                {get_design_block block="rozetka_parcel_payment"}
                {if $f.fiscalBanner}<div class="alert alert--warning rz-parcel__fiscal"><div class="alert__content"><p>{$btr->sviat__rozetka__cod_fiscal_unknown_banner|escape}</p></div></div>{/if}
            {/if}
            {rz_parcel_error field='cod_amount'}
            <label class="rz-parcel__field"><span class="heading_label">{$btr->sviat__rozetka__parcel_description|escape}</span><input class="form-control" data-field="description" maxlength="255" required value="{$f.description|escape}"></label>
            {rz_parcel_error field='description'}
            {rz_parcel_error field='sender'}
            {* Без позначки за замовчуванням: точка видасть товар безкоштовно, тож менеджер підтверджує оплату поза сайтом сам. *}
            {if $f.awaitsPayment || $f.codAllowed}
                <label class="rz-parcel__check fn_rz_parcel_release"{if !$f.awaitsPayment} hidden{/if}><input type="checkbox" data-field="release_unpaid" value="1"><span>{$btr->sviat__rozetka__parcel_release_unpaid|escape}</span></label>
            {/if}
            {rz_parcel_error field='release_unpaid'}
        </div>
        <div class="rz-parcel__issues" role="alert"></div>
        <div class="rz-parcel__actions">
            <button type="button" class="btn btn_small btn_blue fn_rz_parcel_create">{$btr->sviat__rozetka__parcel_create|escape}</button>
            <button type="button" class="btn btn_small btn_border_blue fn_rz_parcel_attach_toggle" aria-expanded="false">{$btr->sviat__rozetka__parcel_attach_open|escape}</button>
        </div>
        {rz_parcel_attach}
    {else}
        {$c = $s.card}
        <div class="rz-card">
            <div class="rz-card__top">
                {if $c.created}<span class="rz-card__muted">{$btr->sviat__rozetka__parcel_created_at|escape} {$c.created|escape}</span>{/if}
                <div class="rz-card__head">
                    <img class="rz-logo rz-logo--card" src="{$rootUrl|escape}/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/images/rozetka.svg" width="19" height="19" alt="{$btr->sviat__rozetka__logo_alt|escape}">
                    {if $c.trackId}<a href="" class="rz-card__number fn_clipboard fn_rz_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="{$btr->sviat__rozetka__parcel_copy|escape}" data-hint-copied="{$btr->sviat__rozetka__parcel_copied|escape}" data-copy-string="{$c.trackId|escape}" data-copy-failed="{$btr->sviat__rozetka__parcel_copy_failed|escape}">{$c.trackId|escape}</a>{/if}
                    {if $c.trackUrl}<a class="rz-card__track" href="{$c.trackUrl|escape}" target="_blank" rel="noopener noreferrer" title="{$btr->sviat__rozetka__parcel_track|escape}" aria-label="{$btr->sviat__rozetka__parcel_track|escape}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 7l-10 10"/><path d="M8 7l9 0l0 9"/></svg></a>{/if}
                    {if $c.statusKey}<span class="rz-parcel__badge rz-parcel__badge--{$c.statusGroup|escape}">{$btr->getTranslation($c.statusKey)|escape}{if $c.partial} · {$btr->sviat__rozetka__parcel_partial|escape}{/if}</span>{/if}
                </div>
                <div class="rz-card__event">
                    {if $c.event}
                        <b>{$c.event.name|escape}</b>{if $c.event.at}<span class="rz-card__dot">{$c.event.at|escape}</span>{/if}
                    {elseif $c.checked}
                        <span class="text_grey">{$btr->sviat__rozetka__parcel_no_events|escape}</span>
                    {else}
                        <span class="text_grey">{$btr->sviat__rozetka__parcel_not_checked|escape}</span>
                    {/if}
                </div>
            </div>
            {if $c.fiscalBanner}<div class="alert alert--warning rz-card__alert"><div class="alert__content"><p>{$btr->sviat__rozetka__cod_fiscal_unknown_banner|escape}</p></div></div>{/if}
            {if $c.receptionHint}<div class="alert alert--info rz-card__alert"><div class="alert__content"><p>{$btr->sviat__rozetka__reception_hint|escape}</p></div></div>{/if}
            {if $c.partial && $c.cod}<div class="alert alert--warning rz-card__alert"><div class="alert__content"><p>{$btr->sviat__rozetka__parcel_partial_check|escape}</p></div></div>{/if}
            {if $c.recipient || $c.point || $c.shippingCost || $c.cod || $c.declared || $c.deliveryDate || $c.expirationDate || $c.reception}
            <div class="rz-card__body">
                <dl class="rz-card__facts">
                    {if $c.recipient}<div><dt>{$btr->sviat__rozetka__parcel_receiver|escape}</dt><dd>{$c.recipient|escape}</dd></div>{/if}
                    {if $c.point}<div><dt>{$btr->sviat__rozetka__parcel_point|escape}</dt><dd>{$c.point|escape}</dd></div>{/if}
                    {if $c.shippingCost}<div><dt>{$btr->sviat__rozetka__parcel_shipping_cost|escape}</dt><dd><b>{$c.shippingCost|escape} ₴</b>{if $c.payerKey}<span class="rz-card__dot">{$btr->getTranslation($c.payerKey)|escape}</span>{/if}{if $c.payerReasonKey}<span class="rz-card__hint">{$btr->getTranslation($c.payerReasonKey)|escape}</span>{/if}</dd></div>{/if}
                    {if $c.cod}<div><dt>{$btr->sviat__rozetka__parcel_cod|escape}</dt><dd><b>{$c.cod.amount|escape} ₴</b>{if $c.cod.fee}<span class="rz-card__dot">{$rz_card_fee = $c.cod.fee}{$btr->sviat__rozetka__parcel_cod_fee|replace:'%fee%':$rz_card_fee|escape}</span>{/if}{if $c.cod.paid === true}<span class="rz-card__dot">{$btr->sviat__rozetka__parcel_cod_paid|escape}</span>{elseif $c.cod.paid === false}<span class="rz-card__dot">{$btr->sviat__rozetka__parcel_cod_unpaid|escape}</span>{/if}</dd></div>{/if}
                    {if $c.declared}<div><dt>{$btr->sviat__rozetka__parcel_card_declared|escape}</dt><dd>{$c.declared|escape} ₴</dd></div>{/if}
                    {if $c.deliveryDate}<div><dt>{$btr->sviat__rozetka__parcel_planned|escape}</dt><dd>{$c.deliveryDate|escape}</dd></div>{/if}
                    {if $c.expirationDate}<div><dt>{$btr->sviat__rozetka__parcel_expiration|escape}</dt><dd>{$c.expirationDate|escape}</dd></div>{/if}
                    {if $c.reception}<div><dt>{$btr->sviat__rozetka__parcel_reception|escape}</dt><dd>№ {$c.reception.number|escape}{if $c.reception.statusKey}<span class="rz-card__dot">{$btr->getTranslation($c.reception.statusKey)|escape}</span>{/if}</dd></div>{/if}
                </dl>
            </div>
            {/if}
            {if $c.more || $c.history}
                <div class="rz-card__more" hidden><div class="rz-card__more-inner">
                    {if $c.more}
                        {$m = $c.more}
                        <dl class="rz-card__facts">
                            {if isset($m.weight)}<div><dt>{$btr->sviat__rozetka__parcel_weight_short|escape}</dt><dd>{$m.weight|escape}</dd></div>{/if}
                            {if isset($m.sides)}<div><dt>{$btr->sviat__rozetka__parcel_sides|escape}</dt><dd>{$m.sides|escape}</dd></div>{/if}
                            {if isset($m.volume)}<div><dt>{$btr->sviat__rozetka__parcel_volume|escape}</dt><dd>{$m.volume|escape}</dd></div>{/if}
                            {if isset($m.description)}<div><dt>{$btr->sviat__rozetka__parcel_description|escape}</dt><dd>{$m.description|escape}</dd></div>{/if}
                            {if isset($m.visibleId)}<div><dt>{$btr->sviat__rozetka__parcel_visible_id|escape}</dt><dd>{$m.visibleId|escape}</dd></div>{/if}
                        </dl>
                    {/if}
                    {if $c.history}
                        <div class="rz-card__caps">{$btr->sviat__rozetka__parcel_history|escape}</div>
                        <ol class="rz-card__history">
                            {foreach $c.history as $rz_event}
                                <li><span class="rz-card__when">{$rz_event.at|escape}</span><span>{$rz_event.name|escape}</span></li>
                            {/foreach}
                        </ol>
                    {/if}
                </div></div>
            {/if}
            <div class="rz-parcel__issues rz-card__issues" role="alert"></div>
            <div class="rz-card__footer">
                {if $c.more || $c.history}<button type="button" class="rz-card__more-toggle fn_rz_parcel_more" aria-expanded="false" data-more="{$btr->sviat__rozetka__parcel_more|escape}" data-less="{$btr->sviat__rozetka__parcel_less|escape}"><span>{$btr->sviat__rozetka__parcel_more|escape}</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6"/></svg></button>{/if}
                <span class="rz-card__tools">
                    {if $c.checked}<span class="rz-card__meta">{$btr->sviat__rozetka__parcel_checked_at|escape} {$c.checked|escape}</span>{/if}
                    <button type="button" class="rz-card__icon fn_rz_parcel_refresh" title="{$btr->sviat__rozetka__parcel_refresh|escape}" aria-label="{$btr->sviat__rozetka__parcel_refresh|escape}"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/></svg></button>
                    <span class="rz-card__sep" aria-hidden="true"></span>
                    <button type="button" class="btn btn_small btn_blue rz-card__label fn_rz_parcel_label"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2"/><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4"/><path d="M7 15a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z"/></svg>{$btr->sviat__rozetka__parcel_label|escape}</button>
                    {if isset($s.can.delete)}<button type="button" class="rz-card__icon rz-card__icon--danger fn_rz_parcel_delete" title="{$btr->sviat__rozetka__parcel_delete_title|escape}" aria-label="{$btr->sviat__rozetka__parcel_delete_title|escape}"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></svg></button>{/if}
                    <button type="button" class="rz-card__icon rz-card__icon--danger fn_rz_parcel_unlink" title="{$btr->sviat__rozetka__parcel_unlink_title|escape}" aria-label="{$btr->sviat__rozetka__parcel_unlink_title|escape}"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 15l6 -6"/><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/><path d="M16 19h5"/></svg></button>
                </span>
            </div>
        </div>
    {/if}
</div>
{/if}
