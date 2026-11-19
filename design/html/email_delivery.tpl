{if $rozetka_order_delivery}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$lang->sviat__rozetka__order_city|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$rozetka_order_delivery.city|escape}</span></td>
    </tr>
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$lang->sviat__rozetka__order_point|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$rozetka_order_delivery.point|escape}</span></td>
    </tr>
    {if $rozetka_order_delivery.estimatedDate}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$lang->sviat__rozetka__order_estimated|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$rozetka_order_delivery.estimatedDate|date_format:'%d.%m.%Y'|escape}</span></td>
    </tr>
    {/if}
    {if $rozetka_order_delivery.cod}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$lang->sviat__rozetka__order_cod|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{if $rozetka_order_delivery.codFee !== null}{$rz_fee = $rozetka_order_delivery.codFee|string_format:'%.2f'}{$lang->sviat__rozetka__order_cod_fee|replace:'%fee%':$rz_fee|escape}{else}{$lang->sviat__rozetka__order_cod_yes|escape}{/if}</span></td>
    </tr>
    {/if}
    {if $rozetka_order_delivery.receiverPays}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$lang->sviat__rozetka__order_payer|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$lang->sviat__rozetka__order_payer_receiver|escape}</span></td>
    </tr>
    {/if}
{/if}
