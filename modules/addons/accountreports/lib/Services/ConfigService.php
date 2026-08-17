<?php

namespace WHMCS\Module\Addon\AccountReports\Services;

/**
 * Typed wrapper around the addon's config array (the $vars passed into
 * every accountreports_*() module function). Keeps field-name strings
 * and "yesno string to bool" coercion in one place instead of scattered
 * across the module glue code.
 */
class ConfigService
{
    /** @var array<string, mixed> */
    private $vars;

    public function __construct(array $vars)
    {
        $this->vars = $vars;
    }

    public function isDefaultEnabledForNewCustomers(): bool
    {
        return $this->yesNo('default_enabled', true);
    }

    public function getMaxRowsPerExport(): int
    {
        $value = (int) ($this->vars['max_rows'] ?? 500);

        return $value > 0 ? min($value, 1000) : 500;
    }

    public function isInvoiceReportEnabled(): bool
    {
        return $this->yesNo('report_type_invoices', true);
    }

    public function isServiceReportEnabled(): bool
    {
        return $this->yesNo('report_type_services', true);
    }

    public function isCsvExportAllowed(): bool
    {
        return $this->yesNo('export_format_csv', true);
    }

    public function isPdfExportAllowed(): bool
    {
        return $this->yesNo('export_format_pdf', true);
    }

    public function isExportFormatAllowed(string $format): bool
    {
        $format = strtolower($format);

        if ($format === 'csv') {
            return $this->isCsvExportAllowed();
        }

        if ($format === 'pdf') {
            return $this->isPdfExportAllowed();
        }

        return false;
    }

    /**
     * Maximum span (in days) a client is allowed to request between
     * due_date_from and due_date_to. 0 means unlimited.
     */
    public function getMaxDateRangeDays(): int
    {
        return max(0, (int) ($this->vars['max_date_range_days'] ?? 0));
    }

    /**
     * @return int[] tblproducts.gid values to restrict reports to, or an
     *               empty array for "no restriction".
     */
    public function getRestrictedProductGroupIds(): array
    {
        $raw = trim((string) ($this->vars['restrict_product_groups'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $ids = array_map('intval', explode(',', $raw));

        return array_values(array_filter($ids, static function ($id) {
            return $id > 0;
        }));
    }

    public function shouldPreserveDataOnDeactivate(): bool
    {
        return $this->yesNo('preserve_data_on_deactivate', true);
    }

    /**
     * Diagnostic snapshot of the raw values this instance was actually
     * constructed with, keyed by the requested field names -- for
     * logging when a computed value (e.g. isExportFormatAllowed())
     * doesn't match what's expected, to see whether the underlying
     * $vars simply doesn't contain what WHMCS was assumed to pass in.
     *
     * @return array<string, mixed>
     */
    public function debugRawValues(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = array_key_exists($key, $this->vars)
                ? $this->vars[$key]
                : '<missing>';
        }

        return $out;
    }

    private function yesNo(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $this->vars) || $this->vars[$key] === '') {
            return $default;
        }

        $value = $this->vars[$key];

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['on', '1', 'yes', 'true'], true);
    }
}
