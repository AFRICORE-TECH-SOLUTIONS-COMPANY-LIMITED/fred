{* This page keeps the EPP Code request explicit so the code is only emailed after the client clicks the button. *}
<div class="fred-epp-page">
    <div class="row">
        <div class="col-md-8">
            <h3 class="m-t-0 mt-0">Get EPP Code</h3>
            <p>
                The EPP Code is used when transferring <strong>{$domain|escape}</strong> to another registrar.
            </p>
            <p class="text-muted">
                For security, the registry emails the code to the domain registrant/admin contacts instead of showing it in the browser.
            </p>
        </div>
        <div class="col-md-4">
            <div class="alert alert-info" role="alert">
                Please confirm your domain contact email addresses are up to date before sending the code.
            </div>
        </div>
    </div>

    {if $message}
        <div class="alert alert-{if $messageType eq 'success'}success{elseif $messageType eq 'danger'}danger{else}info{/if}" role="alert">
            {$message|escape}
        </div>
    {/if}

    <form method="post" action="clientarea.php?action=domaindetails&amp;domainid={$domainid|escape}&amp;modop=custom&amp;a=eppcodepage" class="m-t-20 mt-3">
        <input type="hidden" name="fred_send_epp" value="1">
        <input type="hidden" name="fred_epp_token" value="{$eppToken|escape}">
        <button type="submit" class="btn btn-primary btn-lg">
            Send EPP Code
        </button>
    </form>
</div>
