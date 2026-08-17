<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 16px; margin-bottom: 2px; }
        .meta { color: #666; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Account Report</h1>
    <div class="meta">Client #{$clientId} &mdash; Generated {$generatedAt}</div>

    <table>
        <thead>
            <tr>
                {foreach from=$columns item=label}
                    <th>{$label|escape}</th>
                {/foreach}
            </tr>
        </thead>
        <tbody>
            {foreach from=$rows item=row}
                <tr>
                    {foreach from=$columns key=col item=label}
                        <td>{$row.$col|escape}</td>
                    {/foreach}
                </tr>
            {foreachelse}
                <tr><td colspan="{$columns|@count}">No rows match your filters.</td></tr>
            {/foreach}
        </tbody>
    </table>
</body>
</html>
