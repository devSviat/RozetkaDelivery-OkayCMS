{if $rozetka_order_delivery}
    {if $rozetka_order_family == 'vibe'}
        <dt><span data-language="sviat__rozetka__order_city">{$lang->sviat__rozetka__order_city|escape}</span></dt>
        <dd>{$rozetka_order_delivery.city|escape}</dd>
        <dt><span data-language="sviat__rozetka__order_point">{$lang->sviat__rozetka__order_point|escape}</span></dt>
        <dd>{$rozetka_order_delivery.point|escape}</dd>
        {if $rozetka_order_delivery.estimatedDate}
            <dt><span data-language="sviat__rozetka__order_estimated">{$lang->sviat__rozetka__order_estimated|escape}</span></dt>
            <dd>{$rozetka_order_delivery.estimatedDate|date_format:'%d.%m.%Y'|escape}</dd>
        {/if}
        {if $rozetka_order_delivery.cod}
            <dt><span data-language="sviat__rozetka__order_cod">{$lang->sviat__rozetka__order_cod|escape}</span></dt>
            <dd>{if $rozetka_order_delivery.codFee !== null}{$rz_fee = $rozetka_order_delivery.codFee|string_format:'%.2f'}{$lang->sviat__rozetka__order_cod_fee|replace:'%fee%':$rz_fee|escape}{else}{$lang->sviat__rozetka__order_cod_yes|escape}{/if}</dd>
        {/if}
        {if $rozetka_order_delivery.receiverPays}
            <dt><span data-language="sviat__rozetka__order_payer">{$lang->sviat__rozetka__order_payer|escape}</span></dt>
            <dd>{$lang->sviat__rozetka__order_payer_receiver|escape}</dd>
        {/if}
    {else}
        <tr>
            <td><span data-language="sviat__rozetka__order_city">{$lang->sviat__rozetka__order_city|escape}</span></td>
            <td>{$rozetka_order_delivery.city|escape}</td>
        </tr>
        <tr>
            <td><span data-language="sviat__rozetka__order_point">{$lang->sviat__rozetka__order_point|escape}</span></td>
            <td>{$rozetka_order_delivery.point|escape}</td>
        </tr>
        {if $rozetka_order_delivery.estimatedDate}
            <tr>
                <td><span data-language="sviat__rozetka__order_estimated">{$lang->sviat__rozetka__order_estimated|escape}</span></td>
                <td>{$rozetka_order_delivery.estimatedDate|date_format:'%d.%m.%Y'|escape}</td>
            </tr>
        {/if}
        {if $rozetka_order_delivery.cod}
            <tr>
                <td><span data-language="sviat__rozetka__order_cod">{$lang->sviat__rozetka__order_cod|escape}</span></td>
                <td>{if $rozetka_order_delivery.codFee !== null}{$rz_fee = $rozetka_order_delivery.codFee|string_format:'%.2f'}{$lang->sviat__rozetka__order_cod_fee|replace:'%fee%':$rz_fee|escape}{else}{$lang->sviat__rozetka__order_cod_yes|escape}{/if}</td>
            </tr>
        {/if}
        {if $rozetka_order_delivery.receiverPays}
            <tr>
                <td><span data-language="sviat__rozetka__order_payer">{$lang->sviat__rozetka__order_payer|escape}</span></td>
                <td>{$lang->sviat__rozetka__order_payer_receiver|escape}</td>
            </tr>
        {/if}
    {/if}
{/if}
