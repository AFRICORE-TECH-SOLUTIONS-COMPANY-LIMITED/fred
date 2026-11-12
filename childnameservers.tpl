{* This form follows FRED's nsset rules: child nameservers need valid in-zone hostnames and glue IP addresses. *}
<style>
.fred-child-ns-page .form-control {
    border: 1px solid #b0b9c1;
    border-radius: 4px;
    box-shadow: inset 0 1px 2px rgba(0,0,0,.075);
}
.fred-child-ns-page .input-group {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    width: 100%;
}
.fred-child-ns-page .input-group > .form-control {
    position: relative;
    flex: 1 1 auto;
    width: 1%;
    margin-bottom: 0;
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}
.fred-child-ns-page .input-group-append {
    display: flex;
    margin-left: -1px;
    align-items: stretch;
}
.fred-child-ns-page .input-group-text {
    display: flex;
    align-items: center;
    padding: 0 1rem;
    font-size: 1rem;
    font-weight: 400;
    line-height: 1.5;
    color: #495057;
    text-align: center;
    white-space: nowrap;
    background-color: #e9ecef;
    border: 1px solid #b0b9c1;
    border-radius: 0 4px 4px 0;
    height: 100%;
    min-height: 46px; /* Matches standard WHMCS theme input height */
}
</style>
<div class="fred-child-ns-page">
    <div class="row">
        <div class="col-md-8">
            <h3 class="m-t-0 mt-0">Child Nameservers</h3>
            <p class="text-muted">
                Create or update private nameservers for <strong>{$domain|escape}</strong>, such as <code>ns1.{$domain|escape}</code>.
            </p>
        </div>
        <div class="col-md-4">
            <div class="alert alert-info" role="alert">
                The registry requires 2 to 10 child nameservers with glue IP addresses.
            </div>
        </div>
    </div>

    {if $message}
        <div class="alert alert-{if $messageType eq 'success'}success{elseif $messageType eq 'danger'}danger{else}info{/if}" role="alert">
            {$message|escape}
        </div>
    {/if}

    <form method="post" action="clientarea.php?action=domaindetails&amp;domainid={$domainid|escape}&amp;modop=custom&amp;a=childnameservers">
        <input type="hidden" name="fred_child_ns_action" value="save">

        <div class="form-group mb-3">
            <label for="child_nsset_id" class="control-label form-label font-weight-bold fw-bold">Child nsset ID</label>
            <input type="text" id="child_nsset_id" name="child_nsset_id" class="form-control" value="{$childNssetId|escape}" placeholder="Leave blank when creating child nameservers for the first time">
            <p class="help-block form-text text-muted">
                Keep this blank unless you already know the registry nsset handle. The module saves the returned handle for this domain.
            </p>
        </div>

        {foreach from=$childNameservers item=childNs}
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="control-label form-label font-weight-bold fw-bold" for="child_host_{$childNs.number|escape}">Child host {$childNs.number|escape}</label>
                        <div class="input-group">
                            <input type="text" id="child_host_{$childNs.number|escape}" name="child_host[]" class="form-control" placeholder="ns{$childNs.number|escape}" value="{$childNs.host|escape}" autocomplete="off">
                            <div class="input-group-append">
                                <span class="input-group-text">.{$domain|escape}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="control-label form-label font-weight-bold fw-bold" for="child_ip_{$childNs.number|escape}">Glue IP address</label>
                        <input type="text" id="child_ip_{$childNs.number|escape}" name="child_ip[]" class="form-control" placeholder="196.1.2.3 or 2001:db8::1" value="{$childNs.ip|escape}" autocomplete="off">
                    </div>
                </div>
            </div>
        {/foreach}

        <div class="form-group mb-0 mt-4">
            <button type="submit" class="btn btn-primary mr-2 me-2">
                Save Child Nameservers
            </button>
            <button type="submit" name="fred_child_ns_action" value="use" class="btn btn-default btn-outline-primary">
                Save and Use on Domain
            </button>
        </div>
    </form>
</div>
