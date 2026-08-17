<?php
/**
 * Account Reports - WHMCS Addon Module
 *
 * Lets logged-in clients view and export (CSV/PDF) a report of their
 * invoices and services, joined on the invoice line items that billed
 * each service. Admins control both a global default and a per-customer
 * override for whether the feature is available.
 *
 * @see lib/Reports/InvoiceServiceReportService.php  Query/data logic
 * @see lib/Services/ClientAccessService.php          Per-customer enable/disable
 * @see lib/Services/ConfigService.php                Typed addon config access
 * @see hooks.php                                     Nav injection + admin client profile tab
 */

use WHMCS\Module\Addon\AccountReports\Reports\InvoiceServiceReportService;
use WHMCS\Module\Addon\AccountReports\Services\ClientAccessService;
use WHMCS\Module\Addon\AccountReports\Services\ConfigService;
use WHMCS\Module\Addon\AccountReports\Export\CsvExporter;
use WHMCS\Module\Addon\AccountReports\Export\PdfExporter;
use WHMCS\Module\Addon\AccountReports\Security\CsrfGuard;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Reports/InvoiceServiceReportService.php';
require_once __DIR__ . '/lib/Services/ClientAccessService.php';
require_once __DIR__ . '/lib/Services/ConfigService.php';
require_once __DIR__ . '/lib/Export/CsvExporter.php';
require_once __DIR__ . '/lib/Export/PdfExporter.php';
require_once __DIR__ . '/lib/Security/CsrfGuard.php';
require_once __DIR__ . '/lib/helpers.php';

if (!defined('ACCOUNTREPORTS_MODULE_NAME')) {
    define('ACCOUNTREPORTS_MODULE_NAME', 'accountreports');
}

// WHMCS's own core module loader already loads this file directly
// (via a plain, non "_once" include) whenever it needs to call one of
// the functions below -- it is not guaranteed to only do so once per
// request. Guarding every definition with function_exists() makes a
// second load a harmless no-op instead of a fatal "Cannot redeclare"
// error, regardless of what triggers the repeat load.
if (!function_exists('accountreports_config')) {

/* -----------------------------------------------------------------------
 * Standard addon module functions
 * ---------------------------------------------------------------------*/

function accountreports_config()
{
    return [
        'name' => 'Account Reports',
        'description' => 'Lets customers view and export (CSV/PDF) reports of their invoices and services from the client area, with global and per-customer access control.',
        'version' => '1.0.0',
        'author' => '<a href="https://www.ucartz.com" target="_blank" rel="noopener noreferrer">Ucartz</a>',
        'fields' => [
            'default_enabled' => [
                'FriendlyName' => 'Enable Reports For New Customers By Default',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Off by default (closed unless explicitly opted in). Used for any customer without an explicit per-customer override -- grant access to specific customers individually in the table below rather than turning this on account-wide. Manage overrides from this module\'s Configure page or from a client\'s admin profile.',
            ],
            'max_rows' => [
                'FriendlyName' => 'Max Rows Per Report Export',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '500',
                'Description' => 'Ceiling on rows returned per page and per CSV/PDF export. Hard-capped at 1000 regardless of this value.',
            ],
            'report_type_invoices' => [
                'FriendlyName' => 'Enable Invoice Reports',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Show invoice columns (invoice #, date, due date, total, status).',
            ],
            'report_type_services' => [
                'FriendlyName' => 'Enable Service Reports',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Show service columns (domain, IP, start date, service status).',
            ],
            'export_format_csv' => [
                'FriendlyName' => 'Allow CSV Export',
                'Type' => 'yesno',
                'Default' => 'on',
            ],
            'export_format_pdf' => [
                'FriendlyName' => 'Allow PDF Export',
                'Type' => 'yesno',
                'Default' => 'on',
            ],
            'max_date_range_days' => [
                'FriendlyName' => 'Max Filter Date Range (days)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '0',
                'Description' => 'Largest span allowed between the "from" and "to" due-date filters. 0 = unlimited.',
            ],
            'restrict_product_groups' => [
                'FriendlyName' => 'Restrict To Product Group IDs',
                'Type' => 'text',
                'Size' => '40',
                'Default' => '',
                'Description' => 'Optional comma-separated tblproducts.gid values. Leave blank to include services from all product groups.',
            ],
            'preserve_data_on_deactivate' => [
                'FriendlyName' => 'Preserve Per-Customer Settings On Deactivate',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'When off, deactivating this module also drops the mod_accountreports_customer_settings table and all per-customer overrides.',
            ],
        ],
    ];
}

function accountreports_activate()
{
    try {
        ClientAccessService::migrate();

        return [
            'status' => 'success',
            'description' => 'Account Reports activated successfully.',
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Activation failed: ' . $e->getMessage(),
        ];
    }
}

function accountreports_deactivate()
{
    try {
        // activate()/deactivate() aren't passed $vars by WHMCS, so read
        // the currently saved config directly for the preserve-data flag.
        $config = new ConfigService(accountreports_get_saved_config());
        $preserve = $config->shouldPreserveDataOnDeactivate();

        ClientAccessService::rollback(!$preserve);

        return [
            'status' => 'success',
            'description' => 'Account Reports deactivated. Per-customer settings were '
                . ($preserve ? 'preserved.' : 'removed.'),
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Deactivation failed: ' . $e->getMessage(),
        ];
    }
}

function accountreports_upgrade($vars)
{
    // Idempotent: safe to run on every upgrade regardless of $vars['version'].
    ClientAccessService::migrate();
}

/**
 * Admin "Configure" page output: module status plus the searchable
 * per-customer enable/disable management UI.
 */
function accountreports_output($vars)
{
    $configService = new ConfigService($vars);
    $accessService = new ClientAccessService();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ar_action'])) {
        accountreports_handle_admin_post($accessService);
        return;
    }

    $search = trim((string) ($_GET['search'] ?? ''));
    $clientStatus = (string) ($_GET['client_status'] ?? '');
    if (!in_array($clientStatus, ClientAccessService::CLIENT_STATUSES, true)) {
        $clientStatus = '';
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 20;

    $result = $accessService->searchClients($search, $configService->isDefaultEnabledForNewCustomers(), $page, $perPage, $clientStatus);

    echo accountreports_render_template(__DIR__ . '/templates/admin', 'customers', [
        'search' => $search,
        'clientStatus' => $clientStatus,
        'clientStatuses' => ClientAccessService::CLIENT_STATUSES,
        'page' => $page,
        'totalPages' => max(1, (int) ceil($result['total'] / $perPage)),
        'totalClients' => $result['total'],
        'clients' => $result['data'],
        'defaultEnabled' => $configService->isDefaultEnabledForNewCustomers(),
        'overrideSummary' => $accessService->countOverrides(),
        'csrfToken' => CsrfGuard::token(),
        'moduleLink' => 'addonmodules.php?module=' . ACCOUNTREPORTS_MODULE_NAME,
        'bulkResult' => accountreports_parse_bulk_result($_GET),
    ]);
}

/**
 * @return array{action: string, count: int}|null
 */
function accountreports_parse_bulk_result(array $query): ?array
{
    $action = (string) ($query['bulk'] ?? '');
    if (!in_array($action, ['enable', 'disable', 'clear'], true)) {
        return null;
    }

    return ['action' => $action, 'count' => (int) ($query['count'] ?? 0)];
}

function accountreports_handle_admin_post(ClientAccessService $accessService)
{
    CsrfGuard::requireValid($_POST);

    $action = (string) ($_POST['ar_action'] ?? '');
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $bulkAction = null;
    $bulkCount = 0;

    switch ($action) {
        case 'enable':
        case 'disable':
        case 'clear':
            if ($clientId > 0) {
                if ($action === 'enable') {
                    $accessService->setOverride($clientId, true);
                } elseif ($action === 'disable') {
                    $accessService->setOverride($clientId, false);
                } else {
                    $accessService->clearOverride($clientId);
                }
            }
            break;

        // Bulk actions affect every client at once -- used from the
        // "Enable for all / Disable for all / Reset all to default"
        // buttons above the per-customer table, for installs with too
        // many customers to toggle one at a time.
        case 'bulk_enable':
            $bulkAction = 'enable';
            $bulkCount = $accessService->bulkSetOverrideForAllClients(true);
            break;
        case 'bulk_disable':
            $bulkAction = 'disable';
            $bulkCount = $accessService->bulkSetOverrideForAllClients(false);
            break;
        case 'bulk_clear':
            $bulkAction = 'clear';
            $bulkCount = $accessService->clearAllOverrides();
            break;
    }

    // Support the toggle posted from a client's admin profile page
    // (see hooks.php's AdminAreaClientSummaryOutput handler) without
    // trusting a caller-supplied redirect URL: only ever bounce back to
    // one of two fixed, known-safe destinations.
    if (!empty($_POST['from_profile']) && $clientId > 0) {
        header('Location: clientssummary.php?userid=' . $clientId);
        exit;
    }

    $redirect = 'addonmodules.php?module=' . ACCOUNTREPORTS_MODULE_NAME;
    if (!empty($_GET['search'])) {
        $redirect .= '&search=' . urlencode($_GET['search']);
    }
    if (!empty($_GET['client_status']) && in_array($_GET['client_status'], ClientAccessService::CLIENT_STATUSES, true)) {
        $redirect .= '&client_status=' . urlencode($_GET['client_status']);
    }
    if (!empty($_GET['page'])) {
        $redirect .= '&page=' . (int) $_GET['page'];
    }
    if ($bulkAction !== null) {
        $redirect .= '&bulk=' . $bulkAction . '&count=' . $bulkCount;
    }

    header('Location: ' . $redirect);
    exit;
}

/**
 * Registers the client area page. WHMCS calls this when a logged-in
 * client requests index.php?m=accountreports.
 *
 * $vars here does NOT contain a 'clientsdetails' key -- WHMCS only
 * passes modulelink/version/the addon's own config fields/_lang into
 * an addon module's _clientarea() function, never the logged-in
 * client's identity. $_SESSION['uid'] is WHMCS's actual client-area
 * session identity (the same value hooks.php already uses for the nav
 * injection, and which correctly follows admin impersonation), so it's
 * used here too for consistency and correctness.
 */
function accountreports_clientarea($vars)
{
    $clientId = (int) ($_SESSION['uid'] ?? 0);

    if ($clientId < 1) {
        return [
            'pagetitle' => 'Account Reports',
            'breadcrumb' => ['index.php?m=accountreports' => 'Account Reports'],
            'templatefile' => 'client/disabled',
            'requirelogin' => true,
            'vars' => [
                'message' => 'We were unable to determine your account. Please log in again.',
            ],
        ];
    }

    $configService = new ConfigService($vars);
    $accessService = new ClientAccessService();
    $enabled = $accessService->isEnabledForClient($clientId, $configService->isDefaultEnabledForNewCustomers());

    if (!$enabled) {
        return [
            'pagetitle' => 'Account Reports',
            'breadcrumb' => ['index.php?m=accountreports' => 'Account Reports'],
            'templatefile' => 'client/disabled',
            'requirelogin' => true,
            'vars' => [
                'message' => 'This feature is not available on your account. If you believe this is a mistake, please contact support.',
            ],
        ];
    }

    if (!$configService->isInvoiceReportEnabled() && !$configService->isServiceReportEnabled()) {
        return [
            'pagetitle' => 'Account Reports',
            'breadcrumb' => ['index.php?m=accountreports' => 'Account Reports'],
            'templatefile' => 'client/disabled',
            'requirelogin' => true,
            'vars' => [
                'message' => 'No report types are currently enabled. Please check back later.',
            ],
        ];
    }

    if (($_REQUEST['action'] ?? '') === 'export') {
        $error = accountreports_handle_export($clientId, $configService);

        if ($error === null) {
            // A file was streamed successfully -- headers/body are
            // already sent, nothing left for WHMCS to render.
            exit;
        }

        // Show export failures inside the theme's normal page layout
        // (same template used for "feature not available") instead of a
        // raw die()'d message on a blank page.
        http_response_code($error['httpCode']);

        return [
            'pagetitle' => 'Account Reports',
            'breadcrumb' => ['index.php?m=accountreports' => 'Account Reports'],
            'templatefile' => 'client/disabled',
            'requirelogin' => true,
            'vars' => [
                'message' => $error['message'],
            ],
        ];
    }

    $reportService = new InvoiceServiceReportService($clientId, $configService->getMaxRowsPerExport());

    // The filter form always submits due_date_from/due_date_to/service_id/
    // invoice_status (even blank), so their presence in $_GET reliably
    // means "the customer submitted the filter form at least once" --
    // whereas the bare landing URL (index.php?m=accountreports, no query
    // string at all) means they haven't searched yet. On a fresh landing,
    // skip running the report query entirely and show a prompt instead,
    // rather than fetching/showing the customer's full unfiltered history
    // by default.
    $hasSearched = isset($_GET['due_date_from']) || isset($_GET['due_date_to'])
        || isset($_GET['service_id']) || isset($_GET['invoice_status']) || isset($_GET['page']);

    if ($hasSearched) {
        $filters = accountreports_parse_filters($_GET, $configService);
        $report = $reportService->getReport($filters);
    } else {
        $filters = [];
        $report = ['data' => [], 'pagination' => ['page' => 1, 'per_page' => 0, 'total' => 0, 'total_pages' => 0]];
    }

    // accountreports_parse_filters() only sets a key when a value is
    // actually present (appropriate for the query builder, which checks
    // with empty()), but the template also reads $filters unconditionally
    // to pre-fill form fields -- always supplying every key here avoids
    // "Undefined array key" PHP warnings on any request that omits one.
    $displayFilters = [
        'due_date_from' => $filters['due_date_from'] ?? '',
        'due_date_to' => $filters['due_date_to'] ?? '',
        'service_id' => $filters['service_id'] ?? '',
        'invoice_status' => $filters['invoice_status'] ?? '',
    ];

    $report['pagination'] = accountreports_add_pagination_block($report['pagination']);

    return [
        'pagetitle' => 'Account Reports',
        'breadcrumb' => ['index.php?m=accountreports' => 'Account Reports'],
        'templatefile' => 'client/reports',
        'requirelogin' => true,
        'vars' => [
            'hasSearched' => $hasSearched,
            'rows' => $report['data'],
            'pagination' => $report['pagination'],
            'filters' => $displayFilters,
            'services' => $reportService->getFilterableServices(),
            'invoiceStatuses' => InvoiceServiceReportService::INVOICE_STATUSES,
            'invoicesEnabled' => $configService->isInvoiceReportEnabled(),
            'servicesEnabled' => $configService->isServiceReportEnabled(),
            'csvAllowed' => $configService->isCsvExportAllowed(),
            'pdfAllowed' => $configService->isPdfExportAllowed(),
            'maxDateRangeDays' => $configService->getMaxDateRangeDays(),
            'csrfToken' => CsrfGuard::token(),
        ],
    ];
}

/* -----------------------------------------------------------------------
 * Internal helpers
 * ---------------------------------------------------------------------*/

/**
 * Adds block_start/block_end/block_size to a pagination array, for the
 * client report page's "pages shown in blocks of 20, plus a jump to the
 * last page" pagination widget. Computed here in plain, testable PHP
 * rather than as arithmetic inside the Smarty template -- an earlier
 * attempt at doing this math directly in the .tpl (chaining a division
 * with Smarty's |floor modifier) silently computed the wrong block
 * boundaries, because the modifier bound to the wrong part of the
 * expression.
 */
function accountreports_add_pagination_block(array $pagination, int $blockSize = 20): array
{
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(0, (int) ($pagination['total_pages'] ?? 0));

    $currentBlock = intdiv($page - 1, $blockSize);
    $blockStart = $currentBlock * $blockSize + 1;
    $blockEnd = min($blockStart + $blockSize - 1, $totalPages);

    $pagination['block_size'] = $blockSize;
    $pagination['block_start'] = $blockStart;
    $pagination['block_end'] = $blockEnd;

    return $pagination;
}

/**
 * Normalizes and validates request filters. Never trusts service_id /
 * invoice_status beyond whitelisting; dates are validated by
 * InvoiceServiceReportService itself, but we also clamp the range here
 * against the admin-configured max span.
 */
function accountreports_parse_filters(array $input, ConfigService $configService): array
{
    $filters = [];

    $from = trim((string) ($input['due_date_from'] ?? ''));
    $to = trim((string) ($input['due_date_to'] ?? ''));

    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $filters['due_date_from'] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $filters['due_date_to'] = $to;
    }

    $maxRange = $configService->getMaxDateRangeDays();
    if ($maxRange > 0 && isset($filters['due_date_from'], $filters['due_date_to'])) {
        $fromTs = strtotime($filters['due_date_from']);
        $toTs = strtotime($filters['due_date_to']);
        if ($fromTs !== false && $toTs !== false && $toTs > $fromTs) {
            $spanDays = (int) round(($toTs - $fromTs) / 86400);
            if ($spanDays > $maxRange) {
                $filters['due_date_to'] = date('Y-m-d', $fromTs + $maxRange * 86400);
            }
        }
    }

    if (!empty($input['service_id'])) {
        $filters['service_id'] = (int) $input['service_id'];
    }

    if (!empty($input['invoice_status']) && in_array($input['invoice_status'], InvoiceServiceReportService::INVOICE_STATUSES, true)) {
        $filters['invoice_status'] = $input['invoice_status'];
    }

    $productGroups = $configService->getRestrictedProductGroupIds();
    if ($productGroups) {
        $filters['product_group_ids'] = $productGroups;
    }

    $filters['page'] = max(1, (int) ($input['page'] ?? 1));
    $filters['per_page'] = (int) ($input['per_page'] ?? InvoiceServiceReportService::DEFAULT_PER_PAGE);

    return $filters;
}

/**
 * Handles ?action=export POST requests.
 *
 * On success, streams the CSV/PDF response directly and returns null --
 * the caller should exit immediately without rendering anything else.
 *
 * On failure, returns ['message' => ..., 'httpCode' => ...] instead of
 * die()'ing directly, so the caller can render that message through
 * WHMCS's normal themed page (header/nav/footer intact) rather than a
 * bare, unstyled line of text that looks like the site is broken.
 *
 * @return array{message: string, httpCode: int}|null
 */
function accountreports_handle_export(int $clientId, ConfigService $configService): ?array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return ['message' => 'Export requests must be submitted as POST.', 'httpCode' => 405];
    }

    if (!CsrfGuard::validate($_POST)) {
        return [
            'message' => 'Your session has expired. Please refresh this page and try exporting again.',
            'httpCode' => 403,
        ];
    }

    $format = strtolower((string) ($_POST['format'] ?? ''));

    if ($format === '' || !in_array($format, ['csv', 'pdf'], true)) {
        // Distinguished from the "disabled by admin" case below on
        // purpose -- if this fires, no format reached the server at
        // all (e.g. a form/JS issue), which is a different problem
        // than the admin actually having the format turned off, and
        // showing the wrong one of these two messages is exactly what
        // caused a real, confusing production issue previously.
        return [
            'message' => 'No export format was specified. Please use the Export CSV or Export PDF button.',
            'httpCode' => 400,
        ];
    }

    if (!$configService->isExportFormatAllowed($format)) {
        // Diagnostic only -- not shown to the client. If this fires
        // unexpectedly (admin has the checkbox on and saved), check this
        // line in the PHP/WHMCS error log: it shows exactly what WHMCS
        // handed the module for these two config fields, which confirms
        // whether this is a "checkbox not actually saved on" issue or a
        // module bug reading the config.
        error_log('accountreports export blocked: format=' . $format . ' raw_config='
            . json_encode($configService->debugRawValues(['export_format_csv', 'export_format_pdf'])));

        return [
            'message' => 'CSV/PDF export is currently disabled by the site administrator. (Setup > Addon Modules > Account Reports > Configure > "Allow CSV Export" / "Allow PDF Export")',
            'httpCode' => 403,
        ];
    }

    $filters = accountreports_parse_filters($_POST, $configService);
    unset($filters['page'], $filters['per_page']);

    $reportService = new InvoiceServiceReportService($clientId, $configService->getMaxRowsPerExport());
    $rows = $reportService->getExportRows($filters);

    $columns = accountreports_export_columns($configService);
    $filename = 'account-report-' . $clientId . '-' . date('Ymd-His') . '.' . $format;

    if ($format === 'csv') {
        (new CsvExporter())->stream($rows, $columns, $filename);
        return null;
    }

    // $format === 'pdf' (the only other value in_array() above allows).
    $html = accountreports_render_template(__DIR__ . '/templates/client', 'reports_pdf', [
        'rows' => $rows,
        'columns' => $columns,
        'clientId' => $clientId,
        'generatedAt' => date('Y-m-d H:i'),
    ]);
    (new PdfExporter())->stream($html, $filename);

    return null;
}

/**
 * @return array<string, string> source row key => export column header,
 *                                filtered by which report types are enabled.
 */
function accountreports_export_columns(ConfigService $configService): array
{
    $columns = [];

    if ($configService->isServiceReportEnabled()) {
        $columns['service_id'] = 'Service ID';
        $columns['service_domain'] = 'Domain';
        $columns['service_ip'] = 'IP Address';
        $columns['service_start_date'] = 'Service Start Date';
        $columns['service_status'] = 'Service Status';
    }

    if ($configService->isInvoiceReportEnabled()) {
        $columns['invoice_id'] = 'Invoice #';
        $columns['invoice_date'] = 'Invoice Date';
        $columns['invoice_due_date'] = 'Due Date';
        $columns['invoice_status'] = 'Invoice Status';
        $columns['service_invoice_amount'] = 'Line Amount';
        $columns['invoice_total'] = 'Invoice Total';
    }

    return $columns;
}

// accountreports_render_template(), accountreports_get_saved_config(),
// and accountreports_is_enabled_for_client() now live in
// lib/helpers.php, shared with hooks.php -- see that file's docblock
// for why they were moved out of here.

} // end function_exists('accountreports_config') guard
