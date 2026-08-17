{* Relative path (not $WEB_ROOT-based): this output is rendered inside
   the admin area, one directory below the WHMCS root regardless of
   whether the admin folder has been renamed for security. *}
<link rel="stylesheet" href="../modules/addons/accountreports/assets/css/accountreports.css">
<div class="panel panel-default accountreports-admin">
    <div class="panel-heading">
        <strong>Account Reports</strong> &mdash; Per-Customer Access
    </div>
    <div class="panel-body">
        {* Query string shared by every action/pagination link on this page. *}
        {capture "qs"}search={$search|escape:'url'}&client_status={$clientStatus|escape:'url'}&page={$page}{/capture}
        {assign var="qs" value=$smarty.capture.qs}

        {if $bulkResult}
            <div class="alert alert-success">
                {if $bulkResult.action == 'enable'}
                    Enabled Reports for all {$bulkResult.count} customer(s).
                {elseif $bulkResult.action == 'disable'}
                    Disabled Reports for all {$bulkResult.count} customer(s).
                {else}
                    Reset {$bulkResult.count} customer(s) to the account-wide default.
                {/if}
            </div>
        {/if}

        <div class="alert {if $defaultEnabled}alert-warning{else}alert-info{/if}" style="margin-bottom: 15px;">
            <strong>Account-wide default:</strong>
            {if $defaultEnabled}
                <span class="label label-success">Enabled</span> — Reports is available to every
                customer unless individually disabled below.
            {else}
                <span class="label label-default">Disabled</span> — Reports is closed for every
                customer <strong>except</strong> the {$overrideSummary.enabled} customer(s) below with
                an explicit "Enabled" override.
            {/if}
            <br>
            <span class="text-muted">
                {$overrideSummary.enabled} customer(s) individually enabled,
                {$overrideSummary.disabled} individually disabled,
                {$totalClients-$overrideSummary.total} using the default.
                Change the account-wide default under this module's Settings tab.
            </span>
        </div>

        <p class="text-muted">
            Use the table below to override the default for an individual customer, or use the bulk
            actions below to apply a setting to every customer at once.
        </p>

        <div class="well well-sm ar-filter-row">
            <div>
                <strong>Bulk actions</strong> &mdash; applies to <strong>every</strong> customer on this
                installation ({$totalClients} total), not just the current search/page.
            </div>
            <div style="flex-basis: 100%; margin-top: 8px;">
                <form method="post" action="{$moduleLink}&{$qs}" style="display:inline;"
                      onsubmit="return confirm('Enable Account Reports for ALL {$totalClients} customers? This overrides any existing per-customer setting.');">
                    <input type="hidden" name="token" value="{$csrfToken}">
                    <button type="submit" name="ar_action" value="bulk_enable" class="btn btn-sm btn-success">Enable For All Customers</button>
                </form>
                <form method="post" action="{$moduleLink}&{$qs}" style="display:inline;"
                      onsubmit="return confirm('Disable Account Reports for ALL {$totalClients} customers? This overrides any existing per-customer setting.');">
                    <input type="hidden" name="token" value="{$csrfToken}">
                    <button type="submit" name="ar_action" value="bulk_disable" class="btn btn-sm btn-warning">Disable For All Customers</button>
                </form>
                <form method="post" action="{$moduleLink}&{$qs}" style="display:inline;"
                      onsubmit="return confirm('Remove per-customer overrides for ALL customers and fall back to the account-wide default for everyone?');">
                    <input type="hidden" name="token" value="{$csrfToken}">
                    <button type="submit" name="ar_action" value="bulk_clear" class="btn btn-sm btn-default">Reset All To Default</button>
                </form>
            </div>
        </div>

        <form method="get" action="{$moduleLink}" class="ar-filter-row" style="margin: 15px 0;">
            <input type="hidden" name="module" value="accountreports">
            <div class="ar-filter-field" style="flex: 1 1 260px;">
                <input type="text" name="search" class="form-control" placeholder="Search by name, email or client ID"
                       value="{$search|escape}">
            </div>
            <div class="ar-filter-field" style="flex: 0 1 180px;">
                <select name="client_status" class="form-control">
                    <option value="">All Statuses</option>
                    {foreach from=$clientStatuses item=cs}
                        <option value="{$cs}"{if $clientStatus == $cs} selected{/if}>{$cs}</option>
                    {/foreach}
                </select>
            </div>
            <div class="ar-filter-field" style="flex: 0 0 auto;">
                <button type="submit" class="btn btn-default">Search</button>
                {if $search || $clientStatus}
                    <a href="{$moduleLink}" class="btn btn-default">Clear</a>
                {/if}
            </div>
        </form>

        <div class="table-responsive">
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Customer</th>
                    <th>Email</th>
                    <th>Client Status</th>
                    <th>Override</th>
                    <th>Effective Status</th>
                    <th style="width: 260px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$clients item=c}
                    <tr>
                        <td>{$c.client_id}</td>
                        <td><a href="clientssummary.php?userid={$c.client_id}">{$c.name|escape}</a>{if $c.companyname} <small class="text-muted">({$c.companyname|escape})</small>{/if}</td>
                        <td>{$c.email|escape}</td>
                        <td>{$c.client_status|escape}</td>
                        <td>
                            {if $c.override === true}
                                <span class="label label-success">Enabled (override)</span>
                            {elseif $c.override === false}
                                <span class="label label-danger">Disabled (override)</span>
                            {else}
                                <span class="label label-default">Using default</span>
                            {/if}
                        </td>
                        <td>
                            {if $c.effective_enabled}
                                <span class="text-success"><i class="fa fa-check-circle"></i> Enabled</span>
                            {else}
                                <span class="text-danger"><i class="fa fa-times-circle"></i> Disabled</span>
                            {/if}
                        </td>
                        <td>
                            <form method="post" action="{$moduleLink}&{$qs}" class="form-inline" style="display:inline;">
                                <input type="hidden" name="token" value="{$csrfToken}">
                                <input type="hidden" name="client_id" value="{$c.client_id}">
                                {if !$c.effective_enabled}
                                    <button type="submit" name="ar_action" value="enable" class="btn btn-xs btn-success">Enable</button>
                                {else}
                                    <button type="submit" name="ar_action" value="disable" class="btn btn-xs btn-warning">Disable</button>
                                {/if}
                                {if $c.override !== null}
                                    <button type="submit" name="ar_action" value="clear" class="btn btn-xs btn-default">Reset to Default</button>
                                {/if}
                            </form>
                        </td>
                    </tr>
                {foreachelse}
                    <tr>
                        <td colspan="7" class="text-center text-muted">No customers found.</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        </div>

        {if $totalPages > 1}
            {assign var="windowStart" value=$page-3}
            {if $windowStart < 1}{assign var="windowStart" value=1}{/if}
            {assign var="windowEnd" value=$windowStart+6}
            {if $windowEnd > $totalPages}{assign var="windowEnd" value=$totalPages}{/if}
            <ul class="pagination">
                {if $page > 1}
                    <li><a href="{$moduleLink}&search={$search|escape:'url'}&client_status={$clientStatus|escape:'url'}&page=1">&laquo; First</a></li>
                    <li><a href="{$moduleLink}&search={$search|escape:'url'}&client_status={$clientStatus|escape:'url'}&page={$page-1}">&lsaquo; Prev</a></li>
                {/if}
                {for $p=$windowStart to $windowEnd}
                    <li{if $p == $page} class="active"{/if}>
                        <a href="{$moduleLink}&search={$search|escape:'url'}&client_status={$clientStatus|escape:'url'}&page={$p}">{$p}</a>
                    </li>
                {/for}
                {if $page < $totalPages}
                    <li><a href="{$moduleLink}&search={$search|escape:'url'}&client_status={$clientStatus|escape:'url'}&page={$page+1}">Next &rsaquo;</a></li>
                    <li><a href="{$moduleLink}&search={$search|escape:'url'}&client_status={$clientStatus|escape:'url'}&page={$totalPages}">Last &raquo;</a></li>
                {/if}
            </ul>
            <p class="text-muted">Page {$page} of {$totalPages}.</p>
        {/if}

        <p class="text-muted">{$totalClients} total customer(s).</p>
    </div>
    <div class="panel-footer text-muted" style="text-align: right; font-size: 12px;">
        Developed by <a href="https://www.ucartz.com" target="_blank" rel="noopener noreferrer">Ucartz</a>
        &mdash;
        <a href="https://www.ucartz.com" target="_blank" rel="noopener noreferrer">Hire us for custom solutions &amp; IT consulting.</a>
    </div>
</div>
