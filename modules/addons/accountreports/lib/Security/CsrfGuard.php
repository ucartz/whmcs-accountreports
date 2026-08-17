<?php

namespace WHMCS\Module\Addon\AccountReports\Security;

/**
 * CSRF protection for this module's own POST actions (the export form
 * and the admin enable/disable toggles).
 *
 * This is fully self-contained and does NOT rely on any WHMCS-internal
 * session key. An earlier version of this class assumed WHMCS exposes
 * its own core CSRF token to third-party modules via
 * $_SESSION['csrfToken'] (mirroring the {$csrfToken} Smarty variable) --
 * that assumption was wrong and broke every export/toggle in
 * production ("Invalid or missing CSRF token" on every submission).
 * WHMCS does NOT currently provide a documented, supported way for
 * addon modules to reuse its internal CSRF token (confirmed against
 * WHMCS's own community/feature-request history -- there is an open
 * feature request literally asking for this to be exposed to addons).
 * So this module mints and validates its own token instead, under its
 * own session key, which needs no assumptions about WHMCS internals at
 * all and can't be broken by a WHMCS version change.
 */
class CsrfGuard
{
    const SESSION_KEY = 'accountreports_csrf_token';

    /**
     * Returns this session's CSRF token, generating and storing one on
     * first use. Assign this into every template that renders a POST
     * form for this module (e.g. 'csrfToken' => CsrfGuard::token()).
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * @param array $request Typically $_POST or $_REQUEST.
     */
    public static function validate(array $request): bool
    {
        $sessionToken = (string) ($_SESSION[self::SESSION_KEY] ?? '');
        $submittedToken = (string) ($request['token'] ?? '');

        if ($sessionToken === '' || $submittedToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }

    /**
     * Validates and, on failure, halts the request with a 403. Use for
     * POST-only module actions where there's no graceful fallback.
     */
    public static function requireValid(array $request): void
    {
        if (!self::validate($request)) {
            http_response_code(403);
            die('Invalid or missing CSRF token. Please go back, refresh the page, and try again.');
        }
    }
}
