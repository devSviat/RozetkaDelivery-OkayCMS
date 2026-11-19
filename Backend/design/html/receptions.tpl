{$meta_title = $btr->sviat__rozetka__receptions_title scope=global}

<div class="main_header">
    <div class="main_header__item">
        <div class="main_header__inner">
            <div class="box_heading heading_page">{$btr->sviat__rozetka__receptions_title|escape}</div>
        </div>
    </div>
</div>

{if $rozetka_reception_message}
    {$rm = $rozetka_reception_message}
    <div class="alert alert--icon alert--{if $rm.ok}success{else}error{/if}" role="status">
        <div class="alert__content">
            {if $rm.key}<p>{if $rm.value}{$rz_rm_value = $rm.value}{$btr->getTranslation($rm.key)|replace:'%s':$rz_rm_value|escape}{else}{$btr->getTranslation($rm.key)|escape}{/if}</p>{/if}
            {if $rm.details}<p>{$rm.details|escape}</p>{/if}
            {foreach $rm.rejected as $rz_rejected}
                <p>{$btr->sviat__rozetka__reception_rejected|escape} {$rz_rejected.track_id|escape}: {$rz_rejected.reason|escape}</p>
            {/foreach}
            {foreach $rm.skipped as $rz_skipped}
                <p>№ {$rz_skipped.order_id|escape}: {$btr->getTranslation($rz_skipped.key)|escape}</p>
            {/foreach}
        </div>
    </div>
{/if}

<div class="boxed">
    <p class="text_grey">{$btr->sviat__rozetka__receptions_intro|escape}</p>
    <form method="post" class="mb-1">
        <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
        <input type="hidden" name="action" value="create_ready">
        <button type="submit" class="btn btn_small btn_blue">{$btr->sviat__rozetka__receptions_create_ready|escape}</button>
    </form>
    {if $rozetka_receptions}
        <table class="table rz-receptions">
            <thead>
                <tr>
                    <th>{$btr->sviat__rozetka__receptions_number|escape}</th>
                    <th>{$btr->sviat__rozetka__receptions_status|escape}</th>
                    <th>{$btr->sviat__rozetka__receptions_amount|escape}</th>
                    <th>{$btr->sviat__rozetka__receptions_created|escape}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {foreach $rozetka_receptions as $rz_reception}
                    <tr>
                        <td>№ {$rz_reception.id|escape}</td>
                        <td>{if $rz_reception.status}{$btr->getTranslation('sviat__rozetka__reception_status_'|cat:$rz_reception.status)|escape}{/if}</td>
                        <td>{$rz_reception.amount|escape}</td>
                        <td>{$rz_reception.created|escape}</td>
                        <td class="rz-receptions__actions">
                            {foreach ['print', 'refresh', 'delete'] as $rz_action}
                                {if $rz_action != 'delete' || $rz_reception.open}
                                    <form method="post"{if $rz_action == 'delete'} class="fn_rz_confirm" data-confirm="{$btr->sviat__rozetka__receptions_confirm_delete|escape}"{/if}>
                                        <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
                                        <input type="hidden" name="action" value="{$rz_action|escape}">
                                        <input type="hidden" name="reception_id" value="{$rz_reception.id|escape}">
                                        <button type="submit" class="btn btn_small {if $rz_action == 'print'}btn_blue{else}btn_border_blue{/if}"{if $rz_action == 'print'} formtarget="_blank"{/if}>{$btr->getTranslation('sviat__rozetka__receptions_'|cat:$rz_action)|escape}</button>
                                    </form>
                                {/if}
                            {/foreach}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p>{$btr->sviat__rozetka__receptions_empty|escape}</p>
    {/if}
</div>
