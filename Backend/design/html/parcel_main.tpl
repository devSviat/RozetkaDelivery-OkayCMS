{* ЕН, коли вона вже є: під товарами в основній колонці, як картка накладної NP. *}
{if $rozetka_parcel && $rozetka_parcel.mode == 'card'}{include file=$smarty.current_dir|cat:'/parcel.tpl'}{/if}
