<?php

namespace WHMCS\Module\Addon\AccountReports\Export;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a report as a PDF using Dompdf.
 *
 * Dompdf ships bundled with WHMCS itself (used for invoice/quote PDFs),
 * so this was originally written to assume that copy is already on the
 * Composer autoload path for any module code. In production that turned
 * out not to hold -- "Class Dompdf\Options not found" when exporting --
 * so this module bundles its own copy (composer.json / vendor/ in the
 * module root) and loads that instead if Dompdf isn't already available
 * from wherever WHMCS's own autoloading left off. Same library, same
 * rendering engine either way -- this just stops depending on an
 * assumption about WHMCS's internal vendor setup that isn't reliable
 * across installs.
 */
class PdfExporter
{
    /**
     * @param string $html      Fully-rendered HTML for the report body
     *                          (typically rendered from a Smarty template
     *                          so it stays consistent with the on-screen
     *                          table markup).
     * @param string $filename  Suggested download filename (no path).
     * @param bool   $download  true = force download (Content-Disposition
     *                          attachment), false = inline in-browser view.
     */
    public function stream(string $html, string $filename, bool $download = true): void
    {
        $this->ensureDompdfIsLoaded();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $dompdf->stream($this->sanitizeFilename($filename), ['Attachment' => $download]);
    }

    /**
     * class_exists() itself triggers every registered autoloader
     * (WHMCS's included) before falling through -- so this only loads
     * this module's own bundled copy if nothing else already provides
     * the class, and never double-loads it if WHMCS's own copy turns
     * out to be available after all on some other install.
     */
    private function ensureDompdfIsLoaded(): void
    {
        if (class_exists(Dompdf::class)) {
            return;
        }

        $autoload = __DIR__ . '/../../vendor/autoload.php';

        if (!is_file($autoload)) {
            throw new \RuntimeException(
                'Dompdf is not available: WHMCS\'s own copy could not be autoloaded, and this '
                . 'module\'s bundled copy is missing. Run "composer install" inside '
                . 'modules/addons/accountreports/ and upload the resulting vendor/ directory.'
            );
        }

        require_once $autoload;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9_\-.]+/', '_', $filename);

        return $filename !== '' ? $filename : 'report.pdf';
    }
}
