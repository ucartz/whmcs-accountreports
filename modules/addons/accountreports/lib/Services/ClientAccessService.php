<?php

namespace WHMCS\Module\Addon\AccountReports\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Owns the mod_accountreports_customer_settings table: the per-customer
 * override that lets an admin enable/disable the Reports feature for one
 * client independent of the addon-wide default.
 *
 * effective_enabled = per-customer row if one exists, else the addon's
 * "Enable Reports for New Customers by Default" config value.
 */
class ClientAccessService
{
    const TABLE = 'mod_accountreports_customer_settings';

    /**
     * Creates the table if it doesn't already exist. Safe to call on
     * every activate() and upgrade() — idempotent.
     */
    public static function migrate(): void
    {
        if (Capsule::schema()->hasTable(self::TABLE)) {
            return;
        }

        Capsule::schema()->create(self::TABLE, function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->boolean('enabled')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique('client_id');
            $table->index('client_id');
        });
    }

    /**
     * @param bool $dropTable Only actually drops the table when true —
     *                        callers should gate this on the addon's
     *                        "Preserve data on deactivate" config setting
     *                        so uninstalling doesn't silently discard
     *                        admin-configured per-customer overrides.
     */
    public static function rollback(bool $dropTable): void
    {
        if ($dropTable && Capsule::schema()->hasTable(self::TABLE)) {
            Capsule::schema()->drop(self::TABLE);
        }
    }

    /**
     * @return bool|null true/false for an explicit override, null if the
     *                    client has no override row (defer to config default).
     */
    public function getOverride(int $clientId): ?bool
    {
        $row = Capsule::table(self::TABLE)->where('client_id', $clientId)->first();

        return $row === null ? null : (bool) $row->enabled;
    }

    /**
     * Resolves the effective enabled state for a client: their explicit
     * override if one exists, otherwise the addon-wide default.
     */
    public function isEnabledForClient(int $clientId, bool $defaultEnabled): bool
    {
        $override = $this->getOverride($clientId);

        return $override === null ? $defaultEnabled : $override;
    }

    /**
     * Creates or updates the per-customer override (upsert).
     */
    public function setOverride(int $clientId, bool $enabled): void
    {
        $now = date('Y-m-d H:i:s');

        $exists = Capsule::table(self::TABLE)->where('client_id', $clientId)->exists();

        if ($exists) {
            Capsule::table(self::TABLE)
                ->where('client_id', $clientId)
                ->update(['enabled' => $enabled, 'updated_at' => $now]);
        } else {
            Capsule::table(self::TABLE)->insert([
                'client_id' => $clientId,
                'enabled' => $enabled,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Removes a client's override, reverting them to the config default.
     */
    public function clearOverride(int $clientId): void
    {
        Capsule::table(self::TABLE)->where('client_id', $clientId)->delete();
    }

    /**
     * Deletes a client's override row entirely. Called from the
     * ClientDelete hook so we don't accumulate orphaned rows for
     * deleted WHMCS clients.
     */
    public function forgetClient(int $clientId): void
    {
        $this->clearOverride($clientId);
    }

    /**
     * Sets an explicit override for every client in tblclients at once
     * ("Enable for all" / "Disable for all"). Processed in chunks so an
     * installation with many thousands of clients doesn't require
     * loading them all into memory or building one enormous query.
     *
     * Uses upsert() (native MySQL "INSERT ... ON DUPLICATE KEY UPDATE"
     * under Capsule) so this is a small number of batched queries
     * rather than one query per client.
     *
     * @return int Number of clients affected.
     */
    public function bulkSetOverrideForAllClients(bool $enabled): int
    {
        $now = date('Y-m-d H:i:s');
        $affected = 0;

        Capsule::table('tblclients')
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($clients) use ($enabled, $now, &$affected) {
                $rows = [];
                foreach ($clients as $client) {
                    $rows[] = [
                        'client_id' => $client->id,
                        'enabled' => $enabled,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                Capsule::table(self::TABLE)->upsert($rows, ['client_id'], ['enabled', 'updated_at']);
                $affected += count($rows);
            });

        return $affected;
    }

    /**
     * Removes every per-customer override at once ("Reset all to
     * default"), so the "Enable Reports For New Customers By Default"
     * config value governs every client again.
     *
     * @return int Number of override rows removed.
     */
    public function clearAllOverrides(): int
    {
        return Capsule::table(self::TABLE)->delete();
    }

    /**
     * Counts of customers with an explicit per-customer override, for
     * the admin summary -- e.g. to see at a glance how many customers
     * are individually opted in when the account-wide default is off,
     * without having to search one by one.
     *
     * @return array{enabled: int, disabled: int, total: int}
     */
    public function countOverrides(): array
    {
        $enabled = Capsule::table(self::TABLE)->where('enabled', true)->count();
        $disabled = Capsule::table(self::TABLE)->where('enabled', false)->count();

        return ['enabled' => $enabled, 'disabled' => $disabled, 'total' => $enabled + $disabled];
    }

    /** WHMCS's actual tblclients.status values. */
    const CLIENT_STATUSES = ['Active', 'Inactive', 'Closed'];

    /**
     * Paginated, search-filtered client list annotated with their
     * effective enabled state, for the admin management UI.
     *
     * @param string $clientStatus One of self::CLIENT_STATUSES, or '' for
     *                              all statuses ("Active/Inactive/Closed
     *                              and all together").
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function searchClients(string $term, bool $defaultEnabled, int $page = 1, int $perPage = 20, string $clientStatus = ''): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));

        $query = Capsule::table('tblclients as c')
            ->leftJoin(self::TABLE . ' as s', 's.client_id', '=', 'c.id');

        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('c.firstname', 'like', $like)
                    ->orWhere('c.lastname', 'like', $like)
                    ->orWhere('c.email', 'like', $like)
                    ->orWhere('c.companyname', 'like', $like)
                    ->orWhere('c.id', 'like', $like);
            });
        }

        if ($clientStatus !== '' && in_array($clientStatus, self::CLIENT_STATUSES, true)) {
            $query->where('c.status', $clientStatus);
        }

        $total = (clone $query)->count('c.id');

        $rows = $query
            ->orderBy('c.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get([
                'c.id as client_id',
                'c.firstname',
                'c.lastname',
                'c.email',
                'c.companyname',
                'c.status as client_status',
                's.enabled as override_enabled',
            ]);

        $data = array_map(function ($row) use ($defaultEnabled) {
            $override = $row->override_enabled === null ? null : (bool) $row->override_enabled;

            return [
                'client_id' => (int) $row->client_id,
                'name' => trim($row->firstname . ' ' . $row->lastname),
                'email' => $row->email,
                'companyname' => $row->companyname,
                'client_status' => $row->client_status,
                'override' => $override,
                'effective_enabled' => $override === null ? $defaultEnabled : $override,
            ];
        }, $rows->all());

        return ['data' => $data, 'total' => $total];
    }
}
