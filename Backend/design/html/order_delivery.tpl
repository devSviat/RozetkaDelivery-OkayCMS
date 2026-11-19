{if $rozetka_order_point || $rozetka_order_delivery}
{$p = $rozetka_order_point}
{$d = $rozetka_order_delivery}
<div class="rz-op mb-1{if $p} fn_rz_order_point{/if}"{if $p} data-url="{$smarty.server.SCRIPT_NAME|escape}?controller=Sviat.RozetkaDelivery.OrderPointAdmin" data-order="{$p.orderId|escape}" data-token="{$smarty.session.id|escape}" data-currency="{$p.currency|escape}" data-city-id="{$p.cityId|escape}" data-city-name="{$p.cityName|escape}" data-point="{$p.pointId|escape}" data-texts="{$p.textsJson|escape}" data-open="{if $p.needsChoice && !$p.locked}1{/if}"{/if}>
    <div class="rz-op__head">
        <span class="heading_label">{$btr->sviat__rozetka__order_title|escape}</span>
        {if $p && !$p.locked}<button type="button" class="rz-op__link fn_rz_op_change"{if $p.needsChoice} hidden{/if}>{$btr->sviat__rozetka__op_change|escape}</button>{/if}
    </div>
    {if $p && $p.locked}<div class="rz-op__hint mb-h">{$btr->sviat__rozetka__op_locked|escape}</div>{/if}
    {if $p && $p.needsChoice && !$p.locked}
        <div class="alert alert--warning mb-h fn_rz_op_needs"><div class="alert__content"><p>{$btr->sviat__rozetka__op_needs_point|escape}</p></div></div>
    {/if}
    {* Редактор після збереження перемальовує цей блок сам, з тих самих рядків OrderDeliveryView. *}
    <div class="rz-op__summary">
    {if $d}
        <div class="rz-op__row"><span class="rz-op__label">{$btr->sviat__rozetka__order_city|escape}</span>{$d.city|escape}</div>
        <div class="rz-op__row"><span class="rz-op__label">{$btr->sviat__rozetka__order_point|escape}</span>{$d.point|escape}</div>
        {if $d.estimatedDate}
            <div class="rz-op__row"><span class="rz-op__label">{$btr->sviat__rozetka__order_estimated|escape}</span>{$d.estimatedDate|date_format:'%d.%m.%Y'|escape}</div>
        {/if}
        {if $d.cod}
            <div class="rz-op__row"><span class="rz-op__label">{$btr->sviat__rozetka__order_cod|escape}</span>{if $d.codFee !== null}{$rz_fee = $d.codFee|string_format:'%.2f'}{$btr->sviat__rozetka__order_cod_fee|replace:'%fee%':$rz_fee|escape}{else}{$btr->sviat__rozetka__order_cod_yes|escape}{/if}</div>
        {/if}
        {if $d.receiverPays}
            <div class="rz-op__row"><span class="rz-op__label">{$btr->sviat__rozetka__order_payer|escape}</span>{$btr->sviat__rozetka__order_payer_receiver|escape}</div>
        {/if}
    {/if}
    </div>
    {if $p}
        <div class="rz-op__message" role="status"></div>
        <div class="rz-op__editor" hidden></div>
    {/if}
</div>
{/if}
