{foreach $rozetka_delivery_warnings as $rozetka_warning}
    {* Червоний — лише для видачі без оплати: статична ціна працює, але її треба звіряти з тарифами. *}
    <div class="alert alert--icon {if $rozetka_warning == 'manual_without_cod'}alert--error{else}alert--warning{/if} mt-1">
        <div class="alert__content">
            <div class="alert__title">{$btr->getTranslation('sviat__rozetka__dwarn_'|cat:$rozetka_warning|cat:'_title')|escape}</div>
            <p>{$btr->getTranslation('sviat__rozetka__dwarn_'|cat:$rozetka_warning|cat:'_text')|escape}</p>
        </div>
    </div>
{/foreach}
