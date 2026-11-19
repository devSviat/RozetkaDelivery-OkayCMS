{if !empty($order->rozetka_list_parcel)}
    {$rp = $order->rozetka_list_parcel}
    <div class="rz-list-status">
        <img class="rz-logo" src="{$rootUrl|escape}/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/images/rozetka.svg" width="14" height="14" alt="{$btr->sviat__rozetka__logo_alt|escape}">
        {if $rp.track}<a href="" class="rz-list-status__track fn_clipboard fn_rz_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="{$btr->sviat__rozetka__parcel_copy|escape}" data-hint-copied="{$btr->sviat__rozetka__parcel_copied|escape}" data-copy-string="{$rp.track|escape}" data-copy-failed="{$btr->sviat__rozetka__parcel_copy_failed|escape}">{$rp.track|escape}</a>{/if}
        {if $rp.statusKey}<span class="rz-parcel__badge rz-parcel__badge--{$rp.group|escape}{if $rp.eventName} hint-bottom-middle-t-info-s-small-mobile hint-anim{/if}"{if $rp.eventName} data-hint="{$rp.eventName|escape}"{/if}>{$btr->getTranslation($rp.statusKey)|escape}</span>{/if}
    </div>
{/if}
