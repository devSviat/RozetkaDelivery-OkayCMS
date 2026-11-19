{* Сторінка способу оплати, «Додаткові налаштування»: післяплата ROZETKA. Лише для оплат "Manual"; вимкнений прапорець модуля нічого не зберігає. *}
<div class="activity_of_switch_item rz-codpay{if !$rozetka_codpay.manual} hidden{/if}">
    {if $rozetka_codpay.enabled}
        <input type="hidden" name="sviat_rz_cod_form" value="1">
        <div class="okay_switch clearfix">
            <label class="switch_label">
                {$btr->sviat__rozetka__codpay_title|escape}
                <i class="fn_tooltips" title="{$btr->sviat__rozetka__codpay_tip|escape}">{include file='svg_icon.tpl' svgId='icon_tooltips'}</i>
            </label>
            <label class="switch switch-default">
                <input class="switch-input" name="sviat_rz_cod" value="1" type="checkbox" {if $rozetka_codpay.on}checked=""{/if}/>
                <span class="switch-label"></span>
                <span class="switch-handle"></span>
            </label>
        </div>
    {else}
        <p class="text_grey">{$btr->sviat__rozetka__codpay_title|escape}: {$btr->sviat__rozetka__codpay_disabled|escape}</p>
    {/if}
</div>
