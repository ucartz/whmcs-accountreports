<?php
/**
 * Account Reports - hooks
 *
 * Auto-loaded by WHMCS on every request (admin and client area) while
 * this addon module is active.
 *
 * Deliberately does NOT require accountreports.php. WHMCS's own core
 * module loader already loads that file directly (via a plain,
 * non "_once" include) whenever it needs to call one of its standard
 * module functions -- e.g. rendering the admin Addon Modules list or
 * Configure page. hooks.php runs on every request too; if it also
 * required accountreports.php, any page where both loaders ran in the
 * same request would fatal with "Cannot redeclare accountreports_config()"
 * (exactly this happened in testing). So hooks.php requires only the
 * lib/ classes and shared helpers it actually needs, directly.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Services/ClientAccessService.php';
require_once __DIR__ . '/lib/Services/ConfigService.php';
require_once __DIR__ . '/lib/Security/CsrfGuard.php';
require_once __DIR__ . '/lib/helpers.php';

use WHMCS\Module\Addon\AccountReports\Services\ClientAccessService;
use WHMCS\Module\Addon\AccountReports\Services\ConfigService;
use WHMCS\Module\Addon\AccountReports\Security\CsrfGuard;

/* -----------------------------------------------------------------------
 * Client area: inject a "Reports" nav item, only for clients who are
 * actually effective_enabled.
 * ---------------------------------------------------------------------*/

add_hook('ClientAreaPrimaryNavbar', 1, function (\WHMCS\View\Menu\Item $primaryNavbar) {
    // $_SESSION['uid'] is WHMCS's own client-area session identity. When
    // an admin is impersonating a client, WHMCS points this at the
    // impersonated client for the duration of the session, so using it
    // here (rather than anything admin-session-derived) automatically
    // keeps this scoped to the impersonated client, never the admin.
    $clientId = (int) ($_SESSION['uid'] ?? 0);

    if ($clientId < 1 || !accountreports_is_enabled_for_client($clientId)) {
        return;
    }

    $attributes = [
        'label' => 'Reports',
        'uri' => 'index.php?m=accountreports',
        'order' => 550,
    ];

    // Best-effort: nest under an existing account-style menu if the
    // active theme's primary navbar happens to expose one under one of
    // these IDs. Lagom's "My Account" links are actually rendered via
    // the secondary/user-dropdown navbar rather than as primary navbar
    // children, so there is no ID that's reliably present here across
    // WHMCS versions/themes -- getChild() simply returns null when it
    // isn't found, so this degrades safely to a top-level item.
    $accountMenu = $primaryNavbar->getChild('Account') ?: $primaryNavbar->getChild('My Account');

    if ($accountMenu) {
        $accountMenu->addChild('AccountReports', $attributes);
    } else {
        $primaryNavbar->addChild('AccountReports', $attributes);
    }
});

/* -----------------------------------------------------------------------
 * Admin: per-customer enable/disable toggle directly on the client's
 * profile page (clientssummary.php).
 *
 * The task mentions AdminClientProfileTabsSummary as a possible hook for
 * this; it is not a hook name in WHMCS's documented hook point registry
 * as of this module's target version (8.x), so rather than register a
 * hook that silently never fires, this uses AdminAreaClientSummaryOutput
 * -- the long-standing, documented hook point for injecting output
 * directly into a client's admin summary page.
 * ---------------------------------------------------------------------*/

add_hook('AdminAreaClientSummaryOutput', 1, function (array $vars) {
    $clientId = (int) ($vars['userid'] ?? $vars['clientid'] ?? 0);

    if ($clientId < 1) {
        return '';
    }

    $configService = new ConfigService(accountreports_get_saved_config());
    $accessService = new ClientAccessService();

    $override = $accessService->getOverride($clientId);
    $default = $configService->isDefaultEnabledForNewCustomers();
    $effective = $override === null ? $default : $override;

    return accountreports_render_template(__DIR__ . '/templates/admin', 'client_profile_widget', [
        'clientId' => $clientId,
        'override' => $override,
        'default' => $default,
        'effective' => $effective,
        'csrfToken' => CsrfGuard::token(),
        'actionUrl' => 'addonmodules.php?module=accountreports',
    ]);
});

/* -----------------------------------------------------------------------
 * Housekeeping: drop a client's override row when the client is deleted,
 * so mod_accountreports_customer_settings doesn't accumulate orphans.
 * ---------------------------------------------------------------------*/

add_hook('ClientDelete', 1, function (array $vars) {
    $clientId = (int) ($vars['userid'] ?? 0);

    if ($clientId > 0) {
        (new ClientAccessService())->forgetClient($clientId);
    }
});
