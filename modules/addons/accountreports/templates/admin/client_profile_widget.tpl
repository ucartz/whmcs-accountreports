<div class="panel panel-default accountreports-profile-widget">
    <div class="panel-heading">Account Reports Access</div>
    <div class="panel-body">
        <p style="margin-bottom: 10px;">
            Status:
            {if $effective}
                <span class="label label-success">Enabled</span>
            {else}
                <span class="label label-danger">Disabled</span>
            {/if}
            {if $override === null}
                <span class="text-muted">(using account-wide default: {if $default}enabled{else}disabled{/if})</span>
            {else}
                <span class="text-muted">(per-customer override)</span>
            {/if}
        </p>
        <form method="post" action="{$actionUrl}">
            <input type="hidden" name="token" value="{$csrfToken}">
            <input type="hidden" name="client_id" value="{$clientId}">
            <input type="hidden" name="from_profile" value="1">
            {if !$effective}
                <button type="submit" name="ar_action" value="enable" class="btn btn-xs btn-success">Enable Reports</button>
            {else}
                <button type="submit" name="ar_action" value="disable" class="btn btn-xs btn-warning">Disable Reports</button>
            {/if}
            {if $override !== null}
                <button type="submit" name="ar_action" value="clear" class="btn btn-xs btn-default">Reset to Default</button>
            {/if}
        </form>
    </div>
</div>
