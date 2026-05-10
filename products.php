<?php
require_once __DIR__ . '/config/config.php';

$currentUser = requireRank($pdo, 5);
$currentRank = (int) $currentUser['rank'];

function jsonResponse($success, $message, $data = [])
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'success' => (bool) $success,
        'message' => (string) $message,
        'data' => $data
    ]);
    exit;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatAriary($value)
{
    return number_format((int) $value, 0, ',', '.') . ' Ariary';
}


function appAuditLog(PDO $pdo, ?array $actor, string $action, array $options = []): void
{
    try {
        if (function_exists('auditLog')) {
            auditLog($pdo, $actor, $action, $options);
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_name, action) VALUES (?, ?, ?)");
        $stmt->execute([
            $actor['id'] ?? null,
            $actor['family_name'] ?? 'System',
            $action
        ]);
    } catch (Throwable $e) {
        // Logging darf die eigentliche Aktion niemals blockieren.
    }
}


/* AJAX Aktionen */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];

    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            jsonResponse(false, 'Bitte Kategorienamen eingeben.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO product_categories (name, active)
            VALUES (?, 1)
        ");
        $stmt->execute([$name]);
        $categoryId = (int) $pdo->lastInsertId();

        appAuditLog($pdo, $currentUser, 'Produktkategorie erstellt: ' . $name, [
            'category' => 'product',
            'severity' => 'success',
            'target_type' => 'product_category',
            'target_id' => $categoryId,
            'meta' => [
                'name' => $name,
                'active' => 1
            ]
        ]);

        jsonResponse(true, 'Kategorie wurde angelegt.', [
            'id' => $categoryId,
            'name' => $name,
            'active' => 1
        ]);
    }

    if ($action === 'update_category') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($id <= 0 || $name === '') {
            jsonResponse(false, 'Ungültige Kategorie.');
        }

        $stmt = $pdo->prepare("SELECT id, name, active FROM product_categories WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $oldCategory = $stmt->fetch();

        if (!$oldCategory) {
            jsonResponse(false, 'Kategorie wurde nicht gefunden.');
        }

        $stmt = $pdo->prepare("
            UPDATE product_categories
            SET name = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $id]);

        appAuditLog($pdo, $currentUser, 'Produktkategorie geändert: ' . $oldCategory['name'] . ' → ' . $name, [
            'category' => 'product',
            'severity' => 'warning',
            'target_type' => 'product_category',
            'target_id' => $id,
            'meta' => [
                'old' => $oldCategory,
                'new' => [
                    'id' => $id,
                    'name' => $name,
                    'active' => (int)$oldCategory['active']
                ]
            ]
        ]);

        jsonResponse(true, 'Kategorie wurde aktualisiert.', [
            'id' => $id,
            'name' => $name
        ]);
    }

    if ($action === 'deactivate_category') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(false, 'Ungültige Kategorie.');
        }

        $stmt = $pdo->prepare("SELECT id, name, active FROM product_categories WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $oldCategory = $stmt->fetch();

        if (!$oldCategory) {
            jsonResponse(false, 'Kategorie wurde nicht gefunden.');
        }

        $stmt = $pdo->prepare("
            UPDATE product_categories
            SET active = 0
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        appAuditLog($pdo, $currentUser, 'Produktkategorie deaktiviert: ' . $oldCategory['name'], [
            'category' => 'product',
            'severity' => 'warning',
            'target_type' => 'product_category',
            'target_id' => $id,
            'meta' => ['old' => $oldCategory, 'new_active' => 0]
        ]);

        jsonResponse(true, 'Kategorie wurde deaktiviert.', [
            'id' => $id
        ]);
    }

    if ($action === 'activate_category') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(false, 'Ungültige Kategorie.');
        }

        $stmt = $pdo->prepare("SELECT id, name, active FROM product_categories WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $oldCategory = $stmt->fetch();

        if (!$oldCategory) {
            jsonResponse(false, 'Kategorie wurde nicht gefunden.');
        }

        $stmt = $pdo->prepare("
            UPDATE product_categories
            SET active = 1
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        appAuditLog($pdo, $currentUser, 'Produktkategorie aktiviert: ' . $oldCategory['name'], [
            'category' => 'product',
            'severity' => 'success',
            'target_type' => 'product_category',
            'target_id' => $id,
            'meta' => ['old' => $oldCategory, 'new_active' => 1]
        ]);

        jsonResponse(true, 'Kategorie wurde aktiviert.', [
            'id' => $id
        ]);
    }

    if ($action === 'delete_category') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(false, 'Ungültige Kategorie.');
        }

        $stmt = $pdo->prepare("SELECT id, name, active FROM product_categories WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $oldCategory = $stmt->fetch();

        if (!$oldCategory) {
            jsonResponse(false, 'Kategorie wurde nicht gefunden.');
        }

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                UPDATE products
                SET category_id = NULL
                WHERE category_id = ?
            ");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("
                DELETE FROM product_categories
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            $pdo->commit();

            appAuditLog($pdo, $currentUser, 'Produktkategorie gelöscht: ' . $oldCategory['name'], [
                'category' => 'product',
                'severity' => 'danger',
                'target_type' => 'product_category',
                'target_id' => $id,
                'meta' => $oldCategory
            ]);

            jsonResponse(true, 'Kategorie wurde gelöscht. Zugeordnete Produkte haben jetzt keine Kategorie.', [
                'id' => $id
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            jsonResponse(false, 'Fehler beim Löschen der Kategorie.');
        }
    }

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $unit = $_POST['unit'] ?? 'Stück';
        $price = (int) ($_POST['price'] ?? 0);

        if (!in_array($unit, ['Stück', 'Kilogramm', 'Cups', 'Gramm', 'Liter', 'Milliliter', 'Staude'], true)) {
            $unit = 'Stück';
        }

        if ($name === '' || $price <= 0) {
            jsonResponse(false, 'Bitte alle Felder korrekt ausfüllen.');
        }

        if ($categoryId > 0) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM product_categories
                WHERE id = ?
                AND active = 1
                LIMIT 1
            ");
            $stmt->execute([$categoryId]);

            if (!$stmt->fetch()) {
                $categoryId = 0;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO products (category_id, name, unit, price)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $categoryId > 0 ? $categoryId : null,
            $name,
            $unit,
            $price
        ]);
        $productId = (int)$pdo->lastInsertId();

        appAuditLog($pdo, $currentUser, 'Produkt erstellt: ' . $name, [
            'category' => 'product',
            'severity' => 'success',
            'target_type' => 'product',
            'target_id' => $productId,
            'meta' => [
                'id' => $productId,
                'name' => $name,
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'unit' => $unit,
                'price' => $price
            ]
        ]);

        jsonResponse(true, 'Produkt wurde hinzugefügt.');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $unit = $_POST['unit'] ?? 'Stück';
        $price = (int) ($_POST['price'] ?? 0);

        if (!in_array($unit, ['Stück', 'Kilogramm', 'Cups', 'Gramm', 'Liter', 'Milliliter', 'Staude'], true)) {
            $unit = 'Stück';
        }

        if ($id <= 0 || $name === '' || $price <= 0) {
            jsonResponse(false, 'Bitte alle Felder korrekt ausfüllen.');
        }

        if ($categoryId > 0) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM product_categories
                WHERE id = ?
                AND active = 1
                LIMIT 1
            ");
            $stmt->execute([$categoryId]);

            if (!$stmt->fetch()) {
                $categoryId = 0;
            }
        }

        $stmt = $pdo->prepare("SELECT id, category_id, name, unit, price, active FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $oldProduct = $stmt->fetch();

        if (!$oldProduct) {
            jsonResponse(false, 'Produkt wurde nicht gefunden.');
        }

        $stmt = $pdo->prepare("
            UPDATE products
            SET category_id = ?, name = ?, unit = ?, price = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $categoryId > 0 ? $categoryId : null,
            $name,
            $unit,
            $price,
            $id
        ]);

        appAuditLog($pdo, $currentUser, 'Produkt geändert: ' . $oldProduct['name'], [
            'category' => 'product',
            'severity' => 'warning',
            'target_type' => 'product',
            'target_id' => $id,
            'meta' => [
                'old' => $oldProduct,
                'new' => [
                    'id' => $id,
                    'category_id' => $categoryId > 0 ? $categoryId : null,
                    'name' => $name,
                    'unit' => $unit,
                    'price' => $price,
                    'active' => (int)$oldProduct['active']
                ]
            ]
        ]);

        jsonResponse(true, 'Produkt wurde aktualisiert.');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(false, 'Ungültiges Produkt.');
        }

        $stmt = $pdo->prepare("SELECT id, category_id, name, unit, price, active FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $oldProduct = $stmt->fetch();

        if (!$oldProduct) {
            jsonResponse(false, 'Produkt wurde nicht gefunden.');
        }

        $stmt = $pdo->prepare("UPDATE products SET active = 0 WHERE id = ?");
        $stmt->execute([$id]);

        appAuditLog($pdo, $currentUser, 'Produkt deaktiviert/gelöscht: ' . $oldProduct['name'], [
            'category' => 'product',
            'severity' => 'danger',
            'target_type' => 'product',
            'target_id' => $id,
            'meta' => ['old' => $oldProduct, 'new_active' => 0]
        ]);

        jsonResponse(true, 'Produkt wurde gelöscht.', [
            'id' => $id
        ]);
    }

    jsonResponse(false, 'Unbekannte Aktion.');
}

$pageTitle = 'Produkte';

$categories = $pdo->query("
    SELECT id, name
    FROM product_categories
    WHERE active = 1
    ORDER BY name ASC
")->fetchAll();

$allCategories = $pdo->query("
    SELECT id, name, active, created_at
    FROM product_categories
    ORDER BY active DESC, name ASC
")->fetchAll();

$products = $pdo->query("
    SELECT 
        p.*,
        COALESCE(pc.name, 'Ohne Kategorie') AS category_name
    FROM products p
    LEFT JOIN product_categories pc ON pc.id = p.category_id
    WHERE p.active = 1
    ORDER BY 
        CASE WHEN pc.name IS NULL THEN 1 ELSE 0 END ASC,
        COALESCE(pc.name, 'Ohne Kategorie') ASC,
        p.name ASC
")->fetchAll();

$productsByCategory = [];
foreach ($products as $product) {
    $categoryName = trim((string) ($product['category_name'] ?? ''));
    if ($categoryName === '') {
        $categoryName = 'Ohne Kategorie';
    }

    if (!isset($productsByCategory[$categoryName])) {
        $productsByCategory[$categoryName] = [
            'items' => [],
            'count' => 0
        ];
    }

    $productsByCategory[$categoryName]['items'][] = $product;
    $productsByCategory[$categoryName]['count']++;
}

ob_start();
?>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;">
    <div id="appToast" class="toast align-items-center text-bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage">
                Aktion erfolgreich.
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<div class="mb-4 d-flex justify-content-between align-items-start gap-3 flex-wrap">
    <div>
        <h1 class="h3 mb-1">Produkte</h1>
        <p class="text-muted mb-0">
            Produkte, Kategorien und Preise verwalten.
        </p>
    </div>

    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#categoryManagerModal">
        <i class="bi bi-tags me-1"></i>
        Kategorien verwalten
    </button>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="bi bi-plus-circle me-2"></i>Produkt hinzufügen
        </h5>
    </div>

    <div class="card-body">
        <form id="addProductForm" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Produktname</label>
                <input type="text" name="name" class="form-control" placeholder="z.B. Kartoffeln" required>
            </div>

            <div class="col-md-3">
                <label class="form-label">Kategorie</label>
                <div class="input-group">
                    <select name="category_id" id="categorySelect" class="form-select">
                        <option value="0">Ohne Kategorie</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>">
                                <?= h($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal"
                        data-bs-target="#categoryManagerModal" title="Kategorien verwalten">
                        <i class="bi bi-plus"></i>
                    </button>
                </div>
            </div>

            <div class="col-md-2">
                <label class="form-label">Einheit</label>
                <select name="unit" class="form-select" required>
                    <option value="Stück">Stück</option>
                    <option value="Kilogramm">Kilogramm</option>
                    <option value="Cups">Cups</option>
                    <option value="Gramm">Gramm</option>
                    <option value="Liter">Liter</option>
                    <option value="Milliliter">Milliliter</option>
                    <option value="Staude">Staude</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Preis in Ariary</label>
                <input type="number" name="price" class="form-control" min="1" required>
            </div>

            <div class="col-md-1 d-flex align-items-end">
                <button class="btn btn-primary w-100" type="submit">
                    <i class="bi bi-save"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 products-list-card">
    <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <h5 class="mb-0">
            <i class="bi bi-list-ul me-2"></i>Produktliste nach Kategorien
        </h5>
        <span class="text-muted small"><?= count($products) ?> Produkt(e)</span>
    </div>

    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle products-category-table">
            <thead>
                <tr>
                    <th>Produkt</th>
                    <th>Einheit</th>
                    <th>Preis</th>
                    <th style="width: 180px;">Aktionen</th>
                </tr>
            </thead>

            <tbody id="productTableBody">
                <?php if (!$products): ?>
                    <tr id="emptyRow">
                        <td colspan="4" class="text-center text-muted py-4">
                            Noch keine Produkte vorhanden.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($productsByCategory as $categoryName => $categoryData): ?>
                        <tr class="product-category-header-row">
                            <td colspan="4">
                                <div class="product-category-header-content">
                                    <span>
                                        <i class="bi bi-tag me-2"></i><?= h(mb_strtoupper($categoryName, 'UTF-8')) ?>
                                    </span>
                                    <span class="product-category-count"><?= (int) $categoryData['count'] ?> Produkt(e)</span>
                                </div>
                            </td>
                        </tr>

                        <?php foreach ($categoryData['items'] as $product): ?>
                            <tr data-id="<?= (int) $product['id'] ?>" class="product-row">
                                <td class="product-name"
                                    data-category-id="<?= (int) ($product['category_id'] ?? 0) ?>"
                                    data-category-name="<?= h($product['category_name'] ?? 'Ohne Kategorie') ?>">
                                    <?= h($product['name']) ?>
                                </td>
                                <td class="product-unit"><?= h($product['unit']) ?></td>
                                <td class="product-price" data-price="<?= (int) $product['price'] ?>">
                                    <?= formatAriary($product['price']) ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-warning btn-sm btn-edit" title="Bearbeiten">
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <button type="button" class="btn btn-danger btn-sm btn-delete" title="Löschen">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="categoryManagerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-tags me-2"></i>Kategorien verwalten
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <form id="addCategoryForm" class="row g-3 mb-4">
                    <div class="col-md-9">
                        <label class="form-label">Neue Kategorie</label>
                        <input type="text" name="name" class="form-control" placeholder="z.B. Obst, Gemüse, Fleisch"
                            required>
                    </div>

                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary w-100" type="submit">
                            <i class="bi bi-save me-1"></i>Erstellen
                        </button>
                    </div>
                </form>

                <h6 class="mb-3">Bestehende Kategorien</h6>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Erstellt</th>
                                <th style="width: 220px;">Aktion</th>
                            </tr>
                        </thead>

                        <tbody id="categoryTableBody">
                            <?php foreach ($allCategories as $category): ?>
                                <tr data-id="<?= (int) $category['id'] ?>">
                                    <td class="category-name"><?= h($category['name']) ?></td>
                                    <td class="category-status" data-active="<?= (int) $category['active'] ?>">
                                        <?php if ((int) $category['active'] === 1): ?>
                                            <span class="badge bg-success">Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Deaktiviert</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= !empty($category['created_at']) ? date('d.m.Y', strtotime($category['created_at'])) : '-' ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-warning btn-sm btn-edit-category"
                                            title="Bearbeiten">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <?php if ((int) $category['active'] === 1): ?>
                                            <button type="button" class="btn btn-secondary btn-sm btn-deactivate-category"
                                                title="Deaktivieren">
                                                <i class="bi bi-eye-slash"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-success btn-sm btn-activate-category"
                                                title="Aktivieren">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-danger btn-sm btn-delete-category"
                                            title="Löschen">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (!$allCategories): ?>
                                <tr id="emptyCategoryRow">
                                    <td colspan="4" class="text-center text-muted py-3">
                                        Noch keine Kategorien vorhanden.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <small class="text-muted d-block mt-3">
                    Deaktivieren blendet die Kategorie für neue Produkte aus. Löschen entfernt die Kategorie endgültig
                    und setzt betroffene Produkte auf „Ohne Kategorie“.
                </small>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Schließen
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.products-category-table thead th {
    background: #f8fafc;
    color: #475569;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.product-category-header-row td {
    background: #eef2f7 !important;
    color: #0f172a;
    border-top: 2px solid #cbd5e1;
    border-bottom: 1px solid #cbd5e1;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    font-size: .82rem;
}

.product-category-header-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.product-category-count {
    font-size: .75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: none;
    letter-spacing: 0;
}

.product-row td:first-child {
    padding-left: 1.25rem;
}

body.dark-mode .products-list-card,
body.dark-mode .products-list-card .card-header {
    background: #2b3035 !important;
    color: #d6d8db !important;
    border-color: #454d55 !important;
}

body.dark-mode .products-category-table thead th {
    background: #1f2937 !important;
    color: #e5e7eb !important;
}

body.dark-mode .product-category-header-row td {
    background: #1f2937 !important;
    color: #fff !important;
    border-color: #475569 !important;
}

body.dark-mode .product-category-count {
    color: #cbd5e1;
}
</style>

<script>
    const categories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;

    function formatAriary(value) {
        return Number(value).toLocaleString('de-DE') + ' Ariary';
    }

    function showToast(message, success = true) {
        const toastElement = document.getElementById('appToast');
        const toastMessage = document.getElementById('toastMessage');

        if (!toastElement || !toastMessage) {
            alert(message);
            return;
        }

        toastElement.classList.remove('text-bg-success', 'text-bg-danger');
        toastElement.classList.add(success ? 'text-bg-success' : 'text-bg-danger');

        toastMessage.textContent = message;

        const toast = new bootstrap.Toast(toastElement, {
            delay: 5000
        });

        toast.show();
    }

    function readJsonResponse(response) {
        return response.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Keine gültige JSON-Antwort:', text);
                return {
                    success: false,
                    message: 'Server-Antwort war ungültig. Bitte Seite neu laden.',
                    data: {}
                };
            }
        });
    }

    function postAjax(data) {
        return fetch('products.php', {
            method: 'POST',
            body: data
        }).then(readJsonResponse);
    }

    function reloadAfterSuccess(result, delay = 650) {
        showToast(result.message, result.success);

        if (result.success) {
            setTimeout(() => location.reload(), delay);
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function categoryOptions(selectedId = 0) {
        let html = `<option value="0">Ohne Kategorie</option>`;

        categories.forEach(category => {
            const selected = Number(selectedId) === Number(category.id) ? 'selected' : '';
            html += `<option value="${category.id}" ${selected}>${escapeHtml(category.name)}</option>`;
        });

        return html;
    }

    function categoryRow(category) {
        const active = Number(category.active) === 1;

        return `
        <tr data-id="${category.id}">
            <td class="category-name">${escapeHtml(category.name)}</td>
            <td class="category-status" data-active="${active ? 1 : 0}">
                ${active ? '<span class="badge bg-success">Aktiv</span>' : '<span class="badge bg-secondary">Deaktiviert</span>'}
            </td>
            <td>${category.created_at || '-'}</td>
            <td>
                <button type="button" class="btn btn-warning btn-sm btn-edit-category" title="Bearbeiten">
                    <i class="bi bi-pencil"></i>
                </button>

                ${active ? `
                    <button type="button" class="btn btn-secondary btn-sm btn-deactivate-category" title="Deaktivieren">
                        <i class="bi bi-eye-slash"></i>
                    </button>
                ` : `
                    <button type="button" class="btn btn-success btn-sm btn-activate-category" title="Aktivieren">
                        <i class="bi bi-eye"></i>
                    </button>
                `}

                <button type="button" class="btn btn-danger btn-sm btn-delete-category" title="Löschen">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;
    }

    function refreshCategorySelect() {
        const categorySelect = document.getElementById('categorySelect');
        if (!categorySelect) return;

        const currentValue = categorySelect.value;
        categorySelect.innerHTML = categoryOptions(currentValue);
    }

    document.getElementById('addCategoryForm')?.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(this);
        formData.append('ajax_action', 'add_category');

        postAjax(formData).then(result => {
            showToast(result.message, result.success);

            if (!result.success) {
                return;
            }

            const emptyCategoryRow = document.getElementById('emptyCategoryRow');
            if (emptyCategoryRow) {
                emptyCategoryRow.remove();
            }

            categories.push({
                id: result.data.id,
                name: result.data.name
            });

            refreshCategorySelect();

            document.getElementById('categoryTableBody')
                .insertAdjacentHTML('beforeend', categoryRow({
                    id: result.data.id,
                    name: result.data.name,
                    active: 1,
                    created_at: new Date().toLocaleDateString('de-DE')
                }));

            this.reset();
        });
    });

    document.getElementById('addProductForm')?.addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(this);
        formData.append('ajax_action', 'add');

        postAjax(formData).then(result => {
            if (result.success) this.reset();
            reloadAfterSuccess(result);
        });
    });

    document.addEventListener('click', function (e) {
        const editButton = e.target.closest('.btn-edit');
        const deleteButton = e.target.closest('.btn-delete');
        const saveButton = e.target.closest('.btn-save-edit');
        const cancelButton = e.target.closest('.btn-cancel-edit');

        const editCategoryButton = e.target.closest('.btn-edit-category');
        const saveCategoryButton = e.target.closest('.btn-save-category');
        const cancelCategoryButton = e.target.closest('.btn-cancel-category');
        const deactivateCategoryButton = e.target.closest('.btn-deactivate-category');
        const activateCategoryButton = e.target.closest('.btn-activate-category');
        const deleteCategoryButton = e.target.closest('.btn-delete-category');

        if (editCategoryButton) {
            const row = editCategoryButton.closest('tr');
            const name = row.querySelector('.category-name').textContent.trim();
            const active = row.querySelector('.category-status').dataset.active;

            row.dataset.original = row.innerHTML;

            row.innerHTML = `
            <td>
                <input type="text" class="form-control form-control-sm edit-category-name" value="${escapeHtml(name)}">
            </td>
            <td class="category-status" data-active="${active}">
                ${Number(active) === 1 ? '<span class="badge bg-success">Aktiv</span>' : '<span class="badge bg-secondary">Deaktiviert</span>'}
            </td>
            <td class="text-muted">Wird nicht geändert</td>
            <td>
                <button type="button" class="btn btn-success btn-sm btn-save-category" title="Speichern">
                    <i class="bi bi-check"></i>
                </button>

                <button type="button" class="btn btn-secondary btn-sm btn-cancel-category" title="Abbrechen">
                    <i class="bi bi-x"></i>
                </button>
            </td>
        `;
        }

        if (cancelCategoryButton) {
            const row = cancelCategoryButton.closest('tr');
            row.innerHTML = row.dataset.original;
        }

        if (saveCategoryButton) {
            const row = saveCategoryButton.closest('tr');
            const id = row.dataset.id;
            const name = row.querySelector('.edit-category-name').value.trim();

            const formData = new FormData();
            formData.append('ajax_action', 'update_category');
            formData.append('id', id);
            formData.append('name', name);

            postAjax(formData).then(result => reloadAfterSuccess(result));
        }

        if (deactivateCategoryButton) {
            const row = deactivateCategoryButton.closest('tr');
            const id = row.dataset.id;
            const name = row.querySelector('.category-name').textContent.trim();

            if (!confirm('Kategorie "' + name + '" wirklich deaktivieren?')) {
                return;
            }

            const formData = new FormData();
            formData.append('ajax_action', 'deactivate_category');
            formData.append('id', id);

            postAjax(formData).then(result => reloadAfterSuccess(result));
        }

        if (activateCategoryButton) {
            const row = activateCategoryButton.closest('tr');
            const id = row.dataset.id;

            const formData = new FormData();
            formData.append('ajax_action', 'activate_category');
            formData.append('id', id);

            postAjax(formData).then(result => reloadAfterSuccess(result));
        }

        if (deleteCategoryButton) {
            const row = deleteCategoryButton.closest('tr');
            const id = row.dataset.id;
            const name = row.querySelector('.category-name').textContent.trim();

            if (!confirm('Kategorie "' + name + '" wirklich endgültig löschen? Produkte bleiben erhalten, aber ohne Kategorie.')) {
                return;
            }

            const formData = new FormData();
            formData.append('ajax_action', 'delete_category');
            formData.append('id', id);

            postAjax(formData).then(result => reloadAfterSuccess(result));
        }

        if (editButton) {
            const row = editButton.closest('tr');
            const name = row.querySelector('.product-name').textContent.trim();
            const unit = row.querySelector('.product-unit').textContent.trim();
            const price = row.querySelector('.product-price').dataset.price;
            const categoryId = row.querySelector('.product-name').dataset.categoryId;

            row.dataset.original = row.innerHTML;

            row.innerHTML = `
            <td>
                <input type="text" class="form-control edit-name" value="${escapeHtml(name)}">
            </td>
            <td>
                <select class="form-select edit-unit">
                    <option value="Stück" ${unit === 'Stück' ? 'selected' : ''}>Stück</option>
                    <option value="Kilogramm" ${unit === 'Kilogramm' ? 'selected' : ''}>Kilogramm</option>
                    <option value="Cups" ${unit === 'Cups' ? 'selected' : ''}>Cups</option>
                    <option value="Gramm" ${unit === 'Gramm' ? 'selected' : ''}>Gramm</option>
                    <option value="Liter" ${unit === 'Liter' ? 'selected' : ''}>Liter</option>
                    <option value="Milliliter" ${unit === 'Milliliter' ? 'selected' : ''}>Milliliter</option>
                    <option value="Staude" ${unit === 'Staude' ? 'selected' : ''}>Staude</option>
                </select>
            </td>
            <td>
                <input type="number" class="form-control edit-price" min="1" value="${price}">
            </td>
            <td>
                <div class="d-flex flex-wrap gap-1">
                    <select class="form-select form-select-sm edit-category">
                        ${categoryOptions(categoryId)}
                    </select>

                    <button type="button" class="btn btn-success btn-sm btn-save-edit" title="Speichern">
                        <i class="bi bi-check"></i>
                    </button>

                    <button type="button" class="btn btn-secondary btn-sm btn-cancel-edit" title="Abbrechen">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </td>
        `;
        }

        if (cancelButton) {
            const row = cancelButton.closest('tr');
            row.innerHTML = row.dataset.original;
        }

        if (saveButton) {
            const row = saveButton.closest('tr');
            const id = row.dataset.id;
            const categoryId = row.querySelector('.edit-category').value;
            const name = row.querySelector('.edit-name').value.trim();
            const unit = row.querySelector('.edit-unit').value;
            const price = row.querySelector('.edit-price').value;

            const formData = new FormData();
            formData.append('ajax_action', 'update');
            formData.append('id', id);
            formData.append('category_id', categoryId);
            formData.append('name', name);
            formData.append('unit', unit);
            formData.append('price', price);

            postAjax(formData).then(result => reloadAfterSuccess(result));
        }

        if (deleteButton) {
            const row = deleteButton.closest('tr');
            const id = row.dataset.id;

            if (!confirm('Produkt wirklich löschen?')) {
                return;
            }

            const formData = new FormData();
            formData.append('ajax_action', 'delete');
            formData.append('id', id);

            postAjax(formData).then(result => reloadAfterSuccess(result));
        }
    });
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';
