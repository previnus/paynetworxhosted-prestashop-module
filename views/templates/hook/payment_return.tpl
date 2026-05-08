{if $order_reference}
<div class="pnx-confirmation">
    <p>{l s='Thank you! Your payment was processed successfully.' mod='paynetworxhosted'}</p>
    <p>{l s='Order reference:' mod='paynetworxhosted'} <strong>{$order_reference|escape:'html':'UTF-8'}</strong></p>
    <p>{l s='A confirmation email has been sent to you.' mod='paynetworxhosted'}</p>
</div>
{else}
<div class="pnx-confirmation">
    <p>{l s='Thank you! Your payment was processed successfully.' mod='paynetworxhosted'}</p>
</div>
{/if}
