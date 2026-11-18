<?php
declare(strict_types=1);
/**
 * Warehouses + ounce grades (Z-Limit S01.T01.1).
 *
 * Both are generic entities (see entities.php registry): `warehouses`, `ounces`. Lots (`purchases` rows, incl. mixes)
 * point to them by UID: purchases.warehouse_uid / purchases.ounce_uid (NULL warehouse_uid == the default warehouse,
 * so old clients and old backups keep working). Numeric `purchases.ounce` stays as is.
 */

require_once __DIR__ . '/entities.php';

/** Returns the uid of the customer's default warehouse, creating «انبار اصلی» on first use (and adopting NULL lots). */
function pb_wh_default_uid(PDO $pdo, int $cid): string {
    $st = $pdo->prepare('SELECT uid FROM ent_warehouses WHERE customer_id=? AND is_default=1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
    $st->execute([$cid]);
    $uid = $st->fetchColumn();
    if (is_string($uid) && $uid !== '') return $uid;
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // re-check under lock-free race: unique (customer_id, uid) only; a rare duplicate default is harmless (lowest id wins)
        $uid = pb_uuid4();
        $rev = pb_entity_next_rev($pdo, $cid, 'warehouses');
        $pdo->prepare("INSERT INTO ent_warehouses (uid, customer_id, rev, created_by, name, type, active, is_default) VALUES (?,?,?,?,?,?,1,1)")
            ->execute([$uid, $cid, $rev, null, 'انبار اصلی', 'main']);
        $pdo->prepare('UPDATE purchases SET warehouse_uid=? WHERE customer_id=? AND warehouse_uid IS NULL')->execute([$uid, $cid]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $uid;
}

/** S08.T08.2: uids of the virtual «at consignees» warehouse(s) (type 'consignment'); never picked automatically, only consignments use them. */
function pb_wh_virtual_uids(PDO $pdo, int $cid): array {
    $st = $pdo->prepare("SELECT uid FROM ent_warehouses WHERE customer_id=? AND type='consignment' AND deleted_at IS NULL");
    $st->execute([$cid]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Validates a warehouse uid belongs to this customer (and is active); returns it. Calls bad() otherwise. */
function pb_wh_require(PDO $pdo, int $cid, $uid): string {
    if (!pb_is_uid($uid)) bad('انبار نامعتبر است');
    $st = $pdo->prepare('SELECT active FROM ent_warehouses WHERE customer_id=? AND uid=? AND deleted_at IS NULL');
    $st->execute([$cid, $uid]);
    $r = $st->fetch();
    if (!$r) bad('انبار انتخابی یافت نشد', 404);
    if ((int)$r['active'] !== 1) bad('انبار انتخابی غیرفعال است');
    return (string)$uid;
}

function pb_ounce_require(PDO $pdo, int $cid, $uid): string {
    if (!pb_is_uid($uid)) bad('درجه‌بندی اونس نامعتبر است');
    $st = $pdo->prepare('SELECT 1 FROM ent_ounces WHERE customer_id=? AND uid=? AND deleted_at IS NULL');
    $st->execute([$cid, $uid]);
    if (!$st->fetchColumn()) bad('درجه‌بندی اونس یافت نشد', 404);
    return (string)$uid;
}

/**
 * Called inside the purchase/mix transaction after the row exists. Optional additive params:
 * payload.warehouse_uid, payload.ounce_uid. New lot without warehouse -> default warehouse.
 * Edit without the param -> unchanged. Explicit null/'' ounce_uid clears it.
 */
function pb_wh_apply_lot(PDO $pdo, int $cid, int $purchaseId, array $payload, bool $isNew): void {
    $sets = [];
    $args = [];
    if (array_key_exists('warehouse_uid', $payload) && $payload['warehouse_uid'] !== null && $payload['warehouse_uid'] !== '') {
        $newWh = pb_wh_require($pdo, $cid, $payload['warehouse_uid']);
        if (!$isNew) {
            $cur = $pdo->prepare('SELECT warehouse_uid, net_weight, remaining FROM purchases WHERE id=? AND customer_id=?');
            $cur->execute([$purchaseId, $cid]);
            $c = $cur->fetch();
            if ($c) {
                $curWh = $c['warehouse_uid'] !== null ? (string)$c['warehouse_uid'] : pb_wh_default_uid($pdo, $cid);
                $hasTr = $pdo->prepare('SELECT 1 FROM stock_transfers WHERE customer_id=? AND lot_id=? LIMIT 1');
                $hasTr->execute([$cid, $purchaseId]);
                if ($curWh !== $newWh && (abs((float)$c['net_weight'] - (float)$c['remaining']) > 1e-6 || $hasTr->fetchColumn())) {
                    bad('این کالا بخشی مصرف/فروخته شده است؛ برای جابه‌جایی از «انتقال بین انبارها» استفاده کنید');
                }
            }
        }
        $sets[] = 'warehouse_uid=?';
        $args[] = $newWh;
    } elseif ($isNew) {
        $sets[] = 'warehouse_uid=?';
        $args[] = pb_wh_default_uid($pdo, $cid);
    }
    if (array_key_exists('ounce_uid', $payload)) {
        $ou = $payload['ounce_uid'];
        $sets[] = 'ounce_uid=?';
        $args[] = ($ou === null || $ou === '') ? null : pb_ounce_require($pdo, $cid, $ou);
    }
    if (!$sets) return;
    $args[] = $purchaseId;
    $args[] = $cid;
    $pdo->prepare('UPDATE purchases SET ' . implode(',', $sets) . ' WHERE id=? AND customer_id=?')->execute($args);
}

/** Sales: optional payload.warehouse_uid must be one of ours (stock in it is validated by pb_stock_pick_wh, T01.3). */
function pb_wh_check_sale(PDO $pdo, int $cid, array $payload, int $purchaseId): void {
    if (!array_key_exists('warehouse_uid', $payload) || $payload['warehouse_uid'] === null || $payload['warehouse_uid'] === '') return;
    pb_wh_require($pdo, $cid, $payload['warehouse_uid']);
}

/* ---------------- entity hooks (referenced by name from the registry) ---------------- */

/** Only one default warehouse per customer; the default must stay active. $vals = validated column values. */
function pb_wh_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    $isDef = array_key_exists('is_default', $vals) ? (int)$vals['is_default'] : (int)($existing['is_default'] ?? 0);
    $active = array_key_exists('active', $vals) ? (int)$vals['active'] : (int)($existing['active'] ?? 1);
    if ($isDef === 1 && $active !== 1) bad('انبار پیش‌فرض نمی‌تواند غیرفعال باشد');
    if ($existing !== null && (int)$existing['is_default'] === 1 && array_key_exists('is_default', $vals) && (int)$vals['is_default'] === 0) {
        bad('برای تغییر انبار پیش‌فرض، انبار دیگری را پیش‌فرض کنید');
    }
    if ($isDef === 1) {
        $uid = $existing['uid'] ?? '';
        $st = $pdo->prepare('SELECT uid FROM ent_warehouses WHERE customer_id=? AND is_default=1 AND deleted_at IS NULL AND uid<>?');
        $st->execute([$cid, $uid]);
        foreach ($st->fetchAll() as $o) {
            $rev = pb_entity_next_rev($pdo, $cid, 'warehouses');
            $pdo->prepare('UPDATE ent_warehouses SET is_default=0, rev=? WHERE customer_id=? AND uid=?')->execute([$rev, $cid, $o['uid']]);
        }
    }
}

function pb_wh_before_delete(PDO $pdo, int $cid, array $row): void {
    if ((int)$row['is_default'] === 1) bad('انبار پیش‌فرض قابل حذف نیست');
    if (($row['type'] ?? '') === 'consignment') { // S08.T08.2: the virtual «نزد امانت‌گیرندگان» warehouse is system-managed
        $mv = $pdo->prepare('SELECT 1 FROM stock_moves WHERE customer_id=? AND warehouse_uid=? LIMIT 1');
        $mv->execute([$cid, $row['uid']]);
        if ($mv->fetchColumn()) bad('این انبار سیستمیِ کالاهای امانیِ نزد دیگران است و قابل حذف نیست');
    }
    $st = $pdo->prepare('SELECT 1 FROM purchases WHERE customer_id=? AND warehouse_uid=? LIMIT 1');
    $st->execute([$cid, $row['uid']]);
    if ($st->fetchColumn()) bad('در این انبار کالا ثبت شده است؛ به‌جای حذف، آن را غیرفعال کنید');
}

function pb_ounce_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    $f = array_key_exists('from_val', $vals) ? $vals['from_val'] : ($existing['from_val'] ?? null);
    $t = array_key_exists('to_val', $vals) ? $vals['to_val'] : ($existing['to_val'] ?? null);
    if ($f !== null && $t !== null && (float)$f > (float)$t) bad('«از» نمی‌تواند بزرگ‌تر از «تا» باشد');
    if (array_key_exists('code', $vals)) {
        $st = $pdo->prepare('SELECT uid FROM ent_ounces WHERE customer_id=? AND code=? AND deleted_at IS NULL AND uid<>?');
        $st->execute([$cid, $vals['code'], $existing['uid'] ?? '']);
        if ($st->fetchColumn()) bad('این درجه‌بندی قبلاً ثبت شده است');
    }
}

function pb_ounce_before_delete(PDO $pdo, int $cid, array $row): void {
    $st = $pdo->prepare('SELECT 1 FROM purchases WHERE customer_id=? AND ounce_uid=? LIMIT 1');
    $st->execute([$cid, $row['uid']]);
    if ($st->fetchColumn()) bad('این درجه‌بندی روی کالاها استفاده شده و قابل حذف نیست');
}
