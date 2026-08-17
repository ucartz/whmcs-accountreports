<div class="accountreports-page">
    <link rel="stylesheet" href="{$WEB_ROOT}/modules/addons/accountreports/assets/css/accountreports.css">
    <script src="{$WEB_ROOT}/modules/addons/accountreports/assets/js/accountreports.js" defer></script>

    {* Built once and reused on every pagination/export link below, instead
       of repeating the same four-parameter query string five times. *}
    {capture "qs"}due_date_from={$filters.due_date_from|escape:'url'}&due_date_to={$filters.due_date_to|escape:'url'}&service_id={$filters.service_id|escape:'url'}&invoice_status={$filters.invoice_status|escape:'url'}{/capture}
    {assign var="qs" value=$smarty.capture.qs}

    <div class="accountreports-filters">
        <form method="get" action="index.php">
            <input type="hidden" name="m" value="accountreports">
            <div class="ar-filter-row">
                <div class="ar-filter-field">
                    <label>{$_ADDONLANG.dueDateFrom|default:'Due Date From'}</label>
                    <input type="date" name="due_date_from" class="form-control"
                           value="{$filters.due_date_from}">
                </div>
                <div class="ar-filter-field">
                    <label>{$_ADDONLANG.dueDateTo|default:'Due Date To'}</label>
                    <input type="date" name="due_date_to" class="form-control"
                           value="{$filters.due_date_to}">
                </div>
                {if $servicesEnabled}
                <div class="ar-filter-field">
                    <label>{$_ADDONLANG.service|default:'Service'}</label>
                    <select name="service_id" class="form-control">
                        <option value="">{$_ADDONLANG.allServices|default:'All Services'}</option>
                        {foreach from=$services item=svc}
                            <option value="{$svc.id}"{if $filters.service_id == $svc.id} selected{/if}>
                                {$svc.domain|escape}
                            </option>
                        {/foreach}
                    </select>
                </div>
                {/if}
                {if $invoicesEnabled}
                <div class="ar-filter-field">
                    <label>{$_ADDONLANG.invoiceStatus|default:'Invoice Status'}</label>
                    <select name="invoice_status" class="form-control">
                        <option value="">{$_ADDONLANG.allStatuses|default:'All Statuses'}</option>
                        {foreach from=$invoiceStatuses item=status}
                            <option value="{$status}"{if $filters.invoice_status == $status} selected{/if}>
                                {$status}
                            </option>
                        {/foreach}
                    </select>
                </div>
                {/if}
                <div class="ar-filter-field ar-filter-actions">
                    <label class="ar-filter-actions-spacer">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">{$_ADDONLANG.applyFilters|default:'Apply Filters'}</button>
                        <a href="index.php?m=accountreports" class="btn btn-default">{$_ADDONLANG.reset|default:'Reset'}</a>
                    </div>
                </div>
            </div>
            {if $maxDateRangeDays > 0}
                <p class="text-muted small">
                    {$_ADDONLANG.dateRangeLimited|default:'Date range filters are limited to %s day(s).'|sprintf:$maxDateRangeDays}
                </p>
            {/if}
        </form>
    </div>

    {if !$hasSearched}
        <div class="alert alert-info accountreports-awaiting-search">
            {$_ADDONLANG.awaitingSearch|default:'Choose a date range or filter above and click "Apply Filters" to view your report.'}
        </div>
    {else}
        <div class="accountreports-export">
            {if $csvAllowed || $pdfAllowed}
            <form method="post" action="index.php?m=accountreports&action=export" class="ar-export-form">
                <input type="hidden" name="token" value="{$csrfToken}">
                <input type="hidden" name="due_date_from" value="{$filters.due_date_from}">
                <input type="hidden" name="due_date_to" value="{$filters.due_date_to}">
                <input type="hidden" name="service_id" value="{$filters.service_id}">
                <input type="hidden" name="invoice_status" value="{$filters.invoice_status}">
                {if $csvAllowed}
                    <button type="submit" name="format" value="csv" class="btn btn-default">
                        <i class="fas fa-file-csv"></i> {$_ADDONLANG.exportCsv|default:'Export CSV'}
                    </button>
                {/if}
                {if $pdfAllowed}
                    <button type="submit" name="format" value="pdf" class="btn btn-default">
                        <i class="fas fa-file-pdf"></i> {$_ADDONLANG.exportPdf|default:'Export PDF'}
                    </button>
                {/if}
            </form>
            {/if}
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-bordered accountreports-table">
                <thead>
                    <tr>
                        {if $servicesEnabled}
                            <th>{$_ADDONLANG.colDomain|default:'Domain'}</th>
                            <th>{$_ADDONLANG.colIp|default:'IP Address'}</th>
                            <th>{$_ADDONLANG.colServiceStart|default:'Service Start'}</th>
                            <th>{$_ADDONLANG.colServiceStatus|default:'Service Status'}</th>
                        {/if}
                        {if $invoicesEnabled}
                            <th>{$_ADDONLANG.colInvoiceNumber|default:'Invoice #'}</th>
                            <th>{$_ADDONLANG.colInvoiceDate|default:'Invoice Date'}</th>
                            <th>{$_ADDONLANG.colDueDate|default:'Due Date'}</th>
                            <th>{$_ADDONLANG.colInvoiceStatus|default:'Invoice Status'}</th>
                            <th>{$_ADDONLANG.colLineAmount|default:'Line Amount'}</th>
                            <th>{$_ADDONLANG.colInvoiceTotal|default:'Invoice Total'}</th>
                        {/if}
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$rows item=row}
                        <tr>
                            {if $servicesEnabled}
                                <td>{$row.service_domain|escape}</td>
                                <td>{$row.service_ip|escape|default:'&mdash;'}</td>
                                <td>{$row.service_start_date|escape}</td>
                                <td>{$row.service_status|escape}</td>
                            {/if}
                            {if $invoicesEnabled}
                                <td><a href="viewinvoice.php?id={$row.invoice_id}">#{$row.invoice_id}</a></td>
                                <td>{$row.invoice_date|escape}</td>
                                <td>{$row.invoice_due_date|escape}</td>
                                <td>{$row.invoice_status|escape}</td>
                                <td>{$row.service_invoice_amount|escape}</td>
                                <td>{$row.invoice_total|escape}</td>
                            {/if}
                        </tr>
                    {foreachelse}
                        <tr>
                            <td colspan="10" class="text-center text-muted">{$_ADDONLANG.noRows|default:'No report rows match your filters.'}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>

        {if $pagination.total_pages > 1}
            {* Pages shown in blocks of 20 (computed in PHP -- see
               accountreports_add_pagination_block()), plus an
               always-visible jump to the last page. *}
            <ul class="pagination">
                {if $pagination.block_start > 1}
                    <li><a href="index.php?m=accountreports&{$qs}&page={$pagination.block_start-1}">&laquo; Prev {$pagination.block_size}</a></li>
                {/if}
                {for $p=$pagination.block_start to $pagination.block_end}
                    <li{if $p == $pagination.page} class="active"{/if}>
                        <a href="index.php?m=accountreports&{$qs}&page={$p}">{$p}</a>
                    </li>
                {/for}
                {if $pagination.block_end < $pagination.total_pages}
                    <li><a href="index.php?m=accountreports&{$qs}&page={$pagination.block_end+1}">Next {$pagination.block_size} &raquo;</a></li>
                    <li><a href="index.php?m=accountreports&{$qs}&page={$pagination.total_pages}">Last ({$pagination.total_pages}) &raquo;&raquo;</a></li>
                {/if}
            </ul>
        {/if}

        <p class="text-muted">{$pagination.total} {$_ADDONLANG.totalRows|default:'total row(s).'}</p>
    {/if}

</div>
