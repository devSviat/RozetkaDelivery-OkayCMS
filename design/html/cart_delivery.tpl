{if $rozetka_module_id && $delivery->module_id == $rozetka_module_id && isset($rozetka_prefill[$delivery->id])}
{$rz_choice = $rozetka_prefill[$delivery->id]}
{if $rozetka_unavailable}
<p class="rz-cart-unavailable" role="status">{$lang->sviat__rozetka__cart_dictionary_empty|escape}</p>
{else}
<div class="rz-cart rz-cart--{$rozetka_theme.family|escape}"
     data-delivery-id="{$delivery->id|escape}"
     data-city="{$rz_choice.city_json|escape}"
     data-point="{$rz_choice.point_json|escape}"
     data-texts="{$rozetka_texts_json|escape}"
     data-urls="{$rozetka_urls_json|escape}"
     data-cod-payments="{$rozetka_cod[$delivery->id].payments|escape}"
     data-cod-fee="{$rozetka_cod[$delivery->id].fee|escape}"
     data-chip="{$rozetka_theme.chip|escape}"
     data-chip-selected="{$rozetka_theme.chipSelected|escape}">

    {if $rozetka_popular}
        <div class="rz-cart__popular {$rozetka_theme.chips|escape}" role="group" aria-label="{$lang->sviat__rozetka__cart_popular|escape}">
            {foreach $rozetka_popular as $city}
                <button type="button" class="{$rozetka_theme.chip|escape}" aria-pressed="false" data-rz-city="{$city.id|escape}" data-rz-city-name="{$city.label|escape}" data-rz-city-type="{$city.type|escape}">{$city.label|escape}</button>
            {/foreach}
        </div>
    {/if}

    <div class="rz-cart__city {$rozetka_theme.field|escape}">
        {if $rozetka_theme.family == 'vibe'}
            <label class="{$rozetka_theme.label|escape}" for="rz_city_{$delivery->id|escape}">{$lang->sviat__rozetka__cart_city|escape}*</label>
        {/if}
        {* Ім'я задано лише для jQuery Validate теми (він пропускає поля без імені); сервер його ігнорує. *}
        <input id="rz_city_{$delivery->id|escape}" class="rz-cart__city-input {$rozetka_theme.input|escape}" type="text" autocomplete="off" name="rozetka_city_q[{$delivery->id|escape}]"
               role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="rz_city_list_{$delivery->id|escape}"
               value="{if $rz_choice.city}{$rz_choice.city.name|escape}{/if}"
               {if $rozetka_theme.family == 'vibe'}placeholder="{$lang->sviat__rozetka__cart_city_placeholder|escape}"{/if}>
        {if $rozetka_theme.family != 'vibe'}
            <span class="{$rozetka_theme.label|escape}">{$lang->sviat__rozetka__cart_city|escape}*</span>
        {/if}
        <ul id="rz_city_list_{$delivery->id|escape}" class="rz-cart__suggest" role="listbox" hidden></ul>
    </div>

    {* Показується, щойно місто вибрано, замість популярних міст і поля міста. *}
    <div class="rz-cart__city-line" hidden>
        <span class="rz-cart__city-line-label">{$lang->sviat__rozetka__cart_city|escape}:</span>
        <span class="rz-cart__city-line-type"></span> <b class="rz-cart__city-line-name"></b>
        <span class="rz-cart__city-line-area"></span>
        <button type="button" class="rz-cart__link rz-cart__change-city">{$lang->sviat__rozetka__cart_change_city|escape}</button>
    </div>

    {* Вибрана точка замінює список: ніщо в блоці її не повторює. *}
    <div class="rz-cart__chosen" hidden>
        <div class="rz-cart__chosen-text">
            <span class="rz-cart__chosen-city"></span>
            <b class="rz-cart__chosen-title"></b>
            <span class="rz-cart__chosen-place"></span>
            <span class="rz-cart__chosen-schedule"></span>
            <span class="rz-cart__chosen-note"></span>
        </div>
        <button type="button" class="rz-cart__link rz-cart__change">{$lang->sviat__rozetka__cart_change|escape}</button>
    </div>

    <div class="rz-cart__points" hidden>
        <div class="rz-cart__groups {$rozetka_theme.chips|escape}" role="group" aria-label="{$lang->sviat__rozetka__cart_groups|escape}"></div>
        <div class="rz-cart__search {$rozetka_theme.field|escape}" hidden>
            {if $rozetka_theme.family == 'vibe'}
                <label class="{$rozetka_theme.label|escape}" for="rz_search_{$delivery->id|escape}">{$lang->sviat__rozetka__cart_search|escape}</label>
            {/if}
            <input id="rz_search_{$delivery->id|escape}" class="rz-cart__search-input {$rozetka_theme.input|escape}" type="search" autocomplete="off" name="rozetka_search_q[{$delivery->id|escape}]"
                   {if $rozetka_theme.family == 'vibe'}placeholder="{$lang->sviat__rozetka__cart_search_placeholder|escape}"{/if}>
            {if $rozetka_theme.family != 'vibe'}
                <span class="{$rozetka_theme.label|escape}">{$lang->sviat__rozetka__cart_search|escape}</span>
            {/if}
        </div>
        {* Несе правило "виберіть точку" з jQuery Validate, тож його повідомлення стоїть над списком. *}
        <input class="rz-cart__point-check" type="text" name="rozetka_point_check[{$delivery->id|escape}]" value="" tabindex="-1" aria-hidden="true" autocomplete="off">
        <div class="rz-cart__list" role="listbox" aria-label="{$lang->sviat__rozetka__cart_points|escape}"></div>
        <p class="rz-cart__more" hidden></p>
    </div>

    <p class="rz-cart__message" role="status" aria-live="polite" hidden></p>
    <div class="rz-cart__date" role="status" aria-live="polite" hidden></div>

    <input type="hidden" class="rz-cart__city-id" name="rozetka_city_id[{$delivery->id|escape}]" value="{if $rz_choice.city}{$rz_choice.city.id|escape}{/if}">
    <input type="hidden" class="rz-cart__point-id" name="rozetka_point_id[{$delivery->id|escape}]" value="{if $rz_choice.point}{$rz_choice.point.id|escape}{/if}">
</div>
{/if}
{/if}
