{if $rozetka_mass_result}
    <div class="row fn_rz_mass_result">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="boxed rz-mass">
                <div class="rz-mass__head">
                    <div class="rz-mass__title">{$btr->sviat__rozetka__mass_heading|escape}{if $rozetka_mass_result.title}: {$rozetka_mass_result.title|escape}{/if}</div>
                    <button type="button" class="rz-mass__hide fn_rz_mass_hide">{$btr->sviat__rozetka__mass_hide|escape}</button>
                </div>
                {if $rozetka_mass_result.error}
                    <div class="rz-mass__row rz-mass__row--failed">{$rozetka_mass_result.error|escape}</div>
                {/if}
                {foreach $rozetka_mass_result.rows as $rz_row}
                    <div class="rz-mass__row rz-mass__row--{$rz_row.kind|escape}"><a href="{url controller=OrderAdmin id=$rz_row.orderId return=$smarty.server.REQUEST_URI}">№ {$rz_row.orderId|escape}</a> — {$rz_row.text|escape}</div>
                {/foreach}
                {if $rozetka_mass_result.untouched}
                    <div class="rz-mass__note">{$rozetka_mass_result.untouched|escape}</div>
                {/if}
            </div>
        </div>
    </div>
{/if}
