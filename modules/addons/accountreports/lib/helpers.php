<?php
/**
 * Shared procedural helpers used by BOTH accountreports.php and hooks.php.
 *
 * This file exists specifically so hooks.php never needs to
 * require/include accountreports.php itself. WHMCS's own core module
 * loader already loads accountreports.php directly (via a plain, non
 * "_once" include) whenever it needs to call one of the standard
 * module functions (config/activate/output/clientarea/etc). hooks.php
 * runs on every request too, and previously also required
 * accountreports.php on its own -- on any page where both loaders ran
 * (e.g. the admin Addon Modules list/Configure page), that caused a
 * fatal "Cannot redeclare accountreports_config()" error. Keeping
 * these helpers in their own file, loaded via require_once from both
 * places, avoids that entirely: this file is never loaded by WHMCS
 * core itself, only by our own require_once calls, which correctly
 * dedupe by resolved path regardless of call order.
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use WHMCS\Module\Addon\AccountReports\Services\ClientAccessService;
use WHMCS\Module\Addon\AccountReports\Services\ConfigService;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!function_exists('accountreports_get_saved_config')) {
    /**
     * Reads this module's currently saved config values directly from
     * tbladdonmodules, for use in contexts (activate/deactivate,
     * hooks.php) that WHMCS does not hand a $vars array to.
     *
     * @return array<string, string>
     */
    function accountreports_get_saved_config(): array
    {
        return Capsule::table('tbladdonmodules')
            ->where('module', 'accountreports')
            ->pluck('value', 'setting')
            ->toArray();
    }
}

if (!function_exists('accountreports_is_enabled_for_client')) {
    /**
     * Shared effective-enabled check for use outside
     * accountreports_clientarea() (hooks.php's nav injection and admin
     * client profile tab, which run on every page load rather than
     * only when the reports page itself is opened).
     */
    function accountreports_is_enabled_for_client(int $clientId): bool
    {
        if ($clientId < 1) {
            return false;
        }

        $configService = new ConfigService(accountreports_get_saved_config());
        $accessService = new ClientAccessService();

        return $accessService->isEnabledForClient($clientId, $configService->isDefaultEnabledForNewCustomers());
    }
}

if (!function_exists('accountreports_render_template')) {
    /**
     * Renders a Smarty template from a directory that is NOT the active
     * theme's directory (i.e. this module's own bundled templates),
     * using WHMCS's own bundled Smarty and its shared compile dir.
     *
     * The client area's *primary* template (client/reports.tpl) is
     * instead rendered by WHMCS itself via the 'templatefile' key
     * returned from accountreports_clientarea() -- that path goes
     * through WHMCS's normal ClientArea template resolution (module
     * default, theme override), so it is NOT rendered via this helper.
     * This helper exists only for output that must be generated
     * independently of that resolution flow: the admin management UI
     * (output() has no template resolution step), the admin client
     * profile widget (hooks.php), and the PDF export body (needs a
     * bare HTML string to hand to Dompdf, not a page wrapped in the
     * active theme's layout).
     */
    function accountreports_render_template(string $templateDir, string $template, array $vars): string
    {
        if (!class_exists('Smarty')) {
            require_once ROOTDIR . '/vendor/smarty/smarty/libs/Smarty.class.php';
        }

        $smarty = new \Smarty();
        $smarty->setTemplateDir($templateDir);
        $smarty->setCompileDir(ROOTDIR . '/templates_c');
        $smarty->assign($vars);

        return $smarty->fetch($template . '.tpl');
    }
}
