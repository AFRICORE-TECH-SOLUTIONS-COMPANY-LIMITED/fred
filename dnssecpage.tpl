<div class="card">
    <div class="card-body">
        <h3 class="card-title">DNSSEC Management</h3>

        {if $successful}
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> {$successful}
            </div>
        {/if}

        {if $error}
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> {$error}
            </div>
        {/if}

        <p>Manage the DNSSEC records (DNSKEY) for your domain below. Note: FRED requires raw DNSKEY details (not DS records).</p>

        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Flags</th>
                    <th>Algorithm</th>
                    <th>Public Key</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$records item=record}
                    <tr>
                        <td>{$record.flags}</td>
                        <td>{$record.alg}</td>
                        <td title="{$record.pubKey}">{$record.pubKey|truncate:40:"..."}</td>
                        <td>
                            <form method="post" action="clientarea.php?action=domaindetails&id={$domainid}&modop=custom&a=dnssecpage">
                                <input type="hidden" name="remove_dnssec" value="1" />
                                <input type="hidden" name="pubKey" value="{$record.pubKey}" />
                                <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                {foreachelse}
                    <tr>
                        <td colspan="4" class="text-center">No DNSSEC records found.</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>

        <hr>

        <h4>Add New DNSKEY Record</h4>
        <form method="post" action="clientarea.php?action=domaindetails&id={$domainid}&modop=custom&a=dnssecpage">
            <input type="hidden" name="add_dnssec" value="1" />
            <input type="hidden" name="protocol" value="3" />
            
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Flags</label>
                    <select name="flags" class="form-control">
                        <option value="257">257 (KSK/CSK)</option>
                        <option value="256">256 (ZSK)</option>
                    </select>
                </div>
                <div class="col-md-6 form-group">
                    <label>Algorithm</label>
                    <select name="algorithm" class="form-control">
                        <option value="8">8 (RSA/SHA-256)</option>
                        <option value="13" selected>13 (ECDSA Curve P-256 with SHA-256)</option>
                        <option value="14">14 (ECDSA Curve P-384 with SHA-384)</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Public Key</label>
                <textarea name="publicKey" class="form-control" rows="4" required></textarea>
            </div>
            
            <div class="form-group text-center">
                <button type="submit" class="btn btn-primary">Add Record</button>
            </div>
        </form>

    </div>
</div>
