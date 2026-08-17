<?php

namespace WHMCS\Module\Addon\AccountReports\Reports;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Builds the joined invoice/service report for a single client.
 *
 * This is the direct query-builder translation of:
 *
 *   SELECT c.id, CONCAT(c.firstname,' ',c.lastname), h.id, h.domain,
 *          h.dedicatedip, h.regdate, h.domainstatus, i.id, i.date,
 *          i.duedate, DATE_FORMAT(i.duedate,'%Y-%m'), ii.amount,
 *          i.total, i.status
 *   FROM tblclients c
 *   INNER JOIN tblhosting h ON h.userid = c.id
 *   INNER JOIN tblinvoiceitems ii ON ii.relid = h.id AND ii.type = 'Hosting'
 *   INNER JOIN tblinvoices i ON i.id = ii.invoiceid
 *   WHERE c.id = :clientId AND i.duedate <= CURDATE()
 *   ORDER BY h.id ASC, i.duedate ASC, i.id ASC
 *
 * expressed via Capsule/Eloquent's query builder, with optional
 * filters and mandatory pagination layered on top. No raw string
 * concatenation of user input is used anywhere in this class.
 *
 * customer_name and due_month (CONCAT()/DATE_FORMAT() in the SQL above)
 * are derived in PHP after fetch instead, and the CURDATE() bound is
 * PHP's own current date -- avoiding MySQL-only SQL keeps this query
 * portable across drivers and directly testable against an in-memory
 * SQLite connection.
 *
 * Deliberately has no knowledge of WHMCS module glue ($vars, hooks,
 * Smarty, etc.) so it can be constructed and asserted against in
 * isolation (see tests/InvoiceServiceReportServiceTest.php).
 */
class InvoiceServiceReportService
{
    /** Absolute ceiling on rows-per-page, regardless of admin config. */
    const HARD_MAX_PER_PAGE = 1000;

    const DEFAULT_PER_PAGE = 25;

    /** Valid tblinvoices.status values, for filter whitelisting. */
    const INVOICE_STATUSES = ['Unpaid', 'Paid', 'Overdue', 'Cancelled', 'Refunded', 'Collections'];

    /** @var int */
    private $clientId;

    /** @var int */
    private $maxRows;

    /**
     * @param int $clientId The owning client's ID. Callers MUST source this
     *                       from the authenticated session (or an active
     *                       admin impersonation target), never from request
     *                       input, so every query stays scoped to the
     *                       correct account.
     * @param int $maxRows  Ceiling on rows returned per page / per export,
     *                       normally sourced from the addon's "Max rows per
     *                       report export" config setting.
     */
    public function __construct(int $clientId, int $maxRows = self::DEFAULT_PER_PAGE)
    {
        if ($clientId < 1) {
            throw new InvalidArgumentException('A valid client ID is required.');
        }

        $this->clientId = $clientId;
        $this->maxRows = max(1, min($maxRows, self::HARD_MAX_PER_PAGE));
    }

    /**
     * Paginated report rows plus pagination metadata.
     *
     * Supported $filters keys (all optional):
     *   - due_date_from   string  Y-m-d, inclusive lower bound on i.duedate
     *   - due_date_to     string  Y-m-d, inclusive upper bound on i.duedate
     *   - service_id      int     restrict to a single tblhosting.id
     *   - invoice_status  string  one of self::INVOICE_STATUSES
     *   - product_group_ids int[] restrict to these tblproducts.gid values
     *   - page            int     1-based page number (default 1)
     *   - per_page         int     rows per page, capped by $maxRows
     *
     * @return array{data: array<int, array<string, mixed>>, pagination: array}
     */
    public function getReport(array $filters = []): array
    {
        $perPage = $this->resolvePerPage($filters['per_page'] ?? null);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $total = (clone $this->baseQuery($filters))->count();

        $rows = $this->baseQuery($filters)
            ->orderBy('h.id')
            ->orderBy('i.duedate')
            ->orderBy('i.id')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        return [
            'data' => $this->normalizeRows($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }

    /**
     * Full (unpaginated, but still hard-capped at $maxRows) result set,
     * for CSV/PDF export. Never returns more than $maxRows rows, so an
     * export can't be used to dump an unbounded table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExportRows(array $filters = []): array
    {
        $rows = $this->baseQuery($filters)
            ->orderBy('h.id')
            ->orderBy('i.duedate')
            ->orderBy('i.id')
            ->limit($this->maxRows)
            ->get();

        return $this->normalizeRows($rows);
    }

    /**
     * Distinct services (tblhosting rows) billed for this client, for
     * populating the "Service" filter dropdown.
     *
     * @return array<int, array{id: int, domain: string}>
     */
    public function getFilterableServices(): array
    {
        $rows = Capsule::table('tblhosting')
            ->where('userid', '=', $this->clientId)
            ->orderBy('domain')
            ->get(['id', 'domain']);

        return array_map(static function ($row) {
            return ['id' => (int) $row->id, 'domain' => (string) $row->domain];
        }, $rows->all());
    }

    /**
     * @param array $filters See getReport() for supported keys.
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Capsule::table('tblclients as c')
            ->join('tblhosting as h', 'h.userid', '=', 'c.id')
            ->join('tblinvoiceitems as ii', function ($join) {
                $join->on('ii.relid', '=', 'h.id')->where('ii.type', '=', 'Hosting');
            })
            ->join('tblinvoices as i', 'i.id', '=', 'ii.invoiceid')
            ->where('c.id', '=', $this->clientId)
            // Deliberately not whereRaw('i.duedate <= CURDATE()'): binding
            // PHP's own current date instead avoids a MySQL-only SQL
            // function, keeping this query portable across drivers (and
            // testable against an in-memory SQLite DB -- see tests/).
            ->where('i.duedate', '<=', date('Y-m-d'));

        if (!empty($filters['due_date_from'])) {
            $query->where('i.duedate', '>=', $this->assertDate($filters['due_date_from']));
        }

        if (!empty($filters['due_date_to'])) {
            $query->where('i.duedate', '<=', $this->assertDate($filters['due_date_to']));
        }

        if (!empty($filters['service_id'])) {
            $query->where('h.id', '=', (int) $filters['service_id']);
        }

        if (!empty($filters['invoice_status'])) {
            if (!in_array($filters['invoice_status'], self::INVOICE_STATUSES, true)) {
                throw new InvalidArgumentException('Unrecognized invoice status filter.');
            }
            $query->where('i.status', '=', $filters['invoice_status']);
        }

        if (!empty($filters['product_group_ids']) && is_array($filters['product_group_ids'])) {
            $groupIds = array_values(array_filter(array_map('intval', $filters['product_group_ids'])));
            if ($groupIds) {
                $query->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                    ->whereIn('p.gid', $groupIds);
            }
        }

        // customer_name and due_month are derived in normalizeRows()
        // rather than via CONCAT()/DATE_FORMAT() here, so this query has
        // no MySQL-only SQL in it and runs unchanged against any
        // Capsule-supported driver.
        return $query->select([
            'c.id as client_id',
            'c.firstname',
            'c.lastname',
            'h.id as service_id',
            'h.domain as service_domain',
            'h.dedicatedip as service_ip',
            'h.regdate as service_start_date',
            'h.domainstatus as service_status',
            'i.id as invoice_id',
            'i.date as invoice_date',
            'i.duedate as invoice_due_date',
            'ii.amount as service_invoice_amount',
            'i.total as invoice_total',
            'i.status as invoice_status',
        ]);
    }

    private function resolvePerPage($requested): int
    {
        $requested = (int) $requested;
        if ($requested < 1) {
            $requested = self::DEFAULT_PER_PAGE;
        }

        return min($requested, $this->maxRows);
    }

    private function assertDate(string $date): string
    {
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Dates must be in Y-m-d format.');
        }

        return $date;
    }

    /**
     * @param \Illuminate\Support\Collection $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows($rows): array
    {
        return array_map(static function ($row) {
            $row = (array) $row;

            $row['customer_name'] = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
            unset($row['firstname'], $row['lastname']);

            $dueDate = (string) ($row['invoice_due_date'] ?? '');
            $row['due_month'] = $dueDate !== '' ? substr($dueDate, 0, 7) : null;

            return $row;
        }, $rows->all());
    }
}
