<?php

if (!function_exists('auditLogColumns')) {
    function auditLogColumns(PDO $pdo): array
    {
        static $columns = null;

        if ($columns !== null) {
            return $columns;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM activity_logs");
            $columns = [];

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $columns[$column['Field']] = true;
            }

            return $columns;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('auditClientIp')) {
    function auditClientIp(): ?string
    {
        $keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim((string)$_SERVER[$key]);

                if ($key === 'HTTP_X_FORWARDED_FOR') {
                    $parts = explode(',', $ip);
                    $ip = trim($parts[0] ?? '');
                }

                if ($ip !== '') {
                    return substr($ip, 0, 45);
                }
            }
        }

        return null;
    }
}

if (!function_exists('auditUserAgent')) {
    function auditUserAgent(): ?string
    {
        $agent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

        return $agent !== '' ? $agent : null;
    }
}

if (!function_exists('auditActorName')) {
    function auditActorName(?array $actor): string
    {
        if (!$actor) {
            return 'System';
        }

        $name = trim((string)($actor['family_name'] ?? $actor['user_name'] ?? $actor['name'] ?? ''));

        return $name !== '' ? $name : 'Unbekannt';
    }
}

if (!function_exists('auditActorId')) {
    function auditActorId(?array $actor): ?int
    {
        if (!$actor || empty($actor['id'])) {
            return null;
        }

        return (int)$actor['id'];
    }
}

if (!function_exists('auditNormalizeSeverity')) {
    function auditNormalizeSeverity(?string $severity): string
    {
        $severity = strtolower(trim((string)$severity));

        return in_array($severity, ['info', 'success', 'warning', 'danger'], true)
            ? $severity
            : 'info';
    }
}

if (!function_exists('auditLog')) {
    function auditLog(PDO $pdo, ?array $actor, string $action, array $options = []): void
    {
        try {
            $columns = auditLogColumns($pdo);

            $userId = $options['user_id'] ?? auditActorId($actor);
            $userName = $options['user_name'] ?? auditActorName($actor);

            $category = $options['category'] ?? null;
            $severity = auditNormalizeSeverity($options['severity'] ?? 'info');
            $targetType = $options['target_type'] ?? null;
            $targetId = $options['target_id'] ?? null;
            $ipAddress = $options['ip_address'] ?? auditClientIp();
            $userAgent = $options['user_agent'] ?? auditUserAgent();
            $meta = $options['meta'] ?? null;

            $metaJson = null;

            if ($meta !== null) {
                $metaJson = json_encode(
                    $meta,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            if (
                isset($columns['category']) &&
                isset($columns['severity']) &&
                isset($columns['target_type']) &&
                isset($columns['target_id']) &&
                isset($columns['ip_address']) &&
                isset($columns['user_agent']) &&
                isset($columns['meta_json'])
            ) {
                $stmt = $pdo->prepare("
                    INSERT INTO activity_logs
                    (
                        user_id,
                        user_name,
                        action,
                        category,
                        severity,
                        target_type,
                        target_id,
                        ip_address,
                        user_agent,
                        meta_json
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $userId,
                    $userName,
                    $action,
                    $category,
                    $severity,
                    $targetType,
                    $targetId,
                    $ipAddress,
                    $userAgent,
                    $metaJson
                ]);

                return;
            }

            $stmt = $pdo->prepare("
                INSERT INTO activity_logs
                (
                    user_id,
                    user_name,
                    action
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                $userName,
                $action
            ]);
        } catch (Throwable $e) {
            // Logging darf niemals die eigentliche Aktion blockieren.
        }
    }
}

if (!function_exists('auditSystemLog')) {
    function auditSystemLog(PDO $pdo, string $action, array $options = []): void
    {
        auditLog($pdo, null, $action, array_merge([
            'user_name' => 'System',
            'category' => 'system',
            'severity' => 'info'
        ], $options));
    }
}

if (!function_exists('auditDiff')) {
    function auditDiff(array $oldData, array $newData, array $labels = []): array
    {
        $changes = [];

        foreach ($newData as $key => $newValue) {
            $oldValue = $oldData[$key] ?? null;

            if ((string)$oldValue !== (string)$newValue) {
                $changes[$key] = [
                    'label' => $labels[$key] ?? $key,
                    'old' => $oldValue,
                    'new' => $newValue
                ];
            }
        }

        return $changes;
    }
}

if (!function_exists('auditLogChanges')) {
    function auditLogChanges(
        PDO $pdo,
        ?array $actor,
        string $action,
        array $oldData,
        array $newData,
        array $labels = [],
        array $options = []
    ): void {
        $changes = auditDiff($oldData, $newData, $labels);

        if (!$changes) {
            return;
        }

        auditLog($pdo, $actor, $action, array_merge($options, [
            'meta' => array_merge($options['meta'] ?? [], [
                'changes' => $changes
            ])
        ]));
    }
}

if (!function_exists('auditCurrentUserArray')) {
    function auditCurrentUserArray(): ?array
    {
        global $currentUser;

        return is_array($currentUser) ? $currentUser : null;
    }
}

if (!function_exists('auditCurrentUser')) {
    function auditCurrentUser(PDO $pdo, string $action, array $options = []): void
    {
        auditLog($pdo, auditCurrentUserArray(), $action, $options);
    }
}

if (!function_exists('writeActivityLog')) {
    function writeActivityLog(PDO $pdo, string $action, ?int $userId = null, ?string $userName = null): void
    {
        auditLog($pdo, [
            'id' => $userId,
            'family_name' => $userName ?? 'System'
        ], $action, [
            'user_id' => $userId,
            'user_name' => $userName ?? 'System',
            'category' => 'general',
            'severity' => 'info'
        ]);
    }
}