<?php
/**
 * Account Reports - English language file.
 *
 * WHMCS automatically loads modules/addons/accountreports/lang/<locale>.php
 * for the active admin/client locale and merges its $_ADDONLANG array into
 * the Smarty context wherever this module's templates render, so .tpl
 * files can reference e.g. {$_ADDONLANG.pageTitle} instead of hardcoded
 * English strings. Add additional locale files (e.g. lang/spanish.php)
 * following the same array shape to translate the client-facing pages.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$_ADDONLANG['pageTitle'] = 'Account Reports';
$_ADDONLANG['dueDateFrom'] = 'Due Date From';
$_ADDONLANG['dueDateTo'] = 'Due Date To';
$_ADDONLANG['service'] = 'Service';
$_ADDONLANG['allServices'] = 'All Services';
$_ADDONLANG['invoiceStatus'] = 'Invoice Status';
$_ADDONLANG['allStatuses'] = 'All Statuses';
$_ADDONLANG['applyFilters'] = 'Apply Filters';
$_ADDONLANG['reset'] = 'Reset';
$_ADDONLANG['exportCsv'] = 'Export CSV';
$_ADDONLANG['exportPdf'] = 'Export PDF';
$_ADDONLANG['colDomain'] = 'Domain';
$_ADDONLANG['colIp'] = 'IP Address';
$_ADDONLANG['colServiceStart'] = 'Service Start';
$_ADDONLANG['colServiceStatus'] = 'Service Status';
$_ADDONLANG['colInvoiceNumber'] = 'Invoice #';
$_ADDONLANG['colInvoiceDate'] = 'Invoice Date';
$_ADDONLANG['colDueDate'] = 'Due Date';
$_ADDONLANG['colInvoiceStatus'] = 'Invoice Status';
$_ADDONLANG['colLineAmount'] = 'Line Amount';
$_ADDONLANG['colInvoiceTotal'] = 'Invoice Total';
$_ADDONLANG['noRows'] = 'No report rows match your filters.';
$_ADDONLANG['totalRows'] = 'total row(s).';
$_ADDONLANG['disabledDefault'] = 'This feature is not available on your account.';
$_ADDONLANG['dateRangeLimited'] = 'Date range filters are limited to %s day(s).';
$_ADDONLANG['awaitingSearch'] = 'Choose a date range or filter above and click "Apply Filters" to view your report.';
