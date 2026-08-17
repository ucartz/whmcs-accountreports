<?php

namespace WHMCS\Module\Addon\AccountReports\Export;

/**
 * Streams report rows as CSV directly to the response, via fputcsv on
 * an output stream -- never assembles the whole file as an in-memory
 * string. Accepts any iterable (plain array or a Generator), so if
 * ReportService is ever extended to yield rows via Capsule's cursor()
 * for very large exports, this class doesn't need to change.
 */
class CsvExporter
{
    /**
     * @param iterable<array<string, mixed>> $rows
     * @param array<string, string> $columns Map of source row key => CSV
     *                                        column header label, in the
     *                                        order columns should appear.
     * @param string $filename Suggested download filename (no path).
     */
    public function stream(iterable $rows, array $columns, string $filename): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('Cannot stream CSV: headers already sent.');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $this->sanitizeFilename($filename) . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');

        // UTF-8 BOM so Excel doesn't mangle non-ASCII customer/domain names.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array_values($columns));

        $i = 0;
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($out, $line);

            // Periodically flush so large exports don't sit fully buffered
            // in PHP's output buffer before reaching the client.
            if (++$i % 200 === 0) {
                flush();
            }
        }

        fclose($out);
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9_\-.]+/', '_', $filename);

        return $filename !== '' ? $filename : 'report.csv';
    }
}
