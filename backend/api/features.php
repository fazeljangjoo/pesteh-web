<?php
declare(strict_types=1);
/**
 * Edition model (Z-Limit S00.T00.1): edition = tier {green, gold} x plan {rial, fx}.
 * Server is the authority; clients only mirror it to hide UI (app/lib/edition.dart, S00.T00.2).
 *
 * Catalog rule: each feature key has a minimum tier and a plan requirement.
 *   'tier' => 'green' (every edition) | 'gold'
 *   'plan' => 'any' | 'fx'
 * Gold always includes every green feature. Later stages ADD keys here (never rename/remove).
 * Statutory numbers (VAT etc.) never live here.
 */

const PB_TIER_GREEN = 'green';
const PB_TIER_GOLD  = 'gold';

function pb_feature_catalog(): array {
    return [
        // ---- base: every edition (existing features) ----
        'core.purchases' => ['tier' => 'green', 'plan' => 'any'],
        'core.sales'     => ['tier' => 'green', 'plan' => 'any'],
        'core.inventory' => ['tier' => 'green', 'plan' => 'any'],
        'core.payments'  => ['tier' => 'green', 'plan' => 'any'],
        'core.parties'   => ['tier' => 'green', 'plan' => 'any'],
        'core.loans'     => ['tier' => 'green', 'plan' => 'any'],
        'core.reports'   => ['tier' => 'green', 'plan' => 'any'],
        'core.backup'    => ['tier' => 'green', 'plan' => 'any'],
        // ---- new operational modules (S01-S13): both tiers ----
        'ops.warehouses'  => ['tier' => 'green', 'plan' => 'any'],
        'ops.staff'       => ['tier' => 'green', 'plan' => 'any'],
        'ops.returns'     => ['tier' => 'green', 'plan' => 'any'],
        'ops.proforma'    => ['tier' => 'green', 'plan' => 'any'],
        'ops.brokers'     => ['tier' => 'green', 'plan' => 'any'],
        'ops.cheques'     => ['tier' => 'green', 'plan' => 'any'],
        'ops.separation'  => ['tier' => 'green', 'plan' => 'any'],
        'ops.consignment' => ['tier' => 'green', 'plan' => 'any'],
        'ops.processing'  => ['tier' => 'green', 'plan' => 'any'],
        'ops.orchard'     => ['tier' => 'green', 'plan' => 'any'],
        'ops.payroll'     => ['tier' => 'green', 'plan' => 'any'],
        'ops.pos'         => ['tier' => 'green', 'plan' => 'any'],
        'ops.excel'       => ['tier' => 'green', 'plan' => 'any'],
        // ---- FX plan (either tier) ----
        'fx.rates'     => ['tier' => 'green', 'plan' => 'fx'],
        'fx.documents' => ['tier' => 'green', 'plan' => 'fx'],
        // ---- Gold accounting layer (S15-S19) ----
        'gold.fiscal_years'  => ['tier' => 'gold', 'plan' => 'any'],
        'gold.coa'           => ['tier' => 'gold', 'plan' => 'any'],
        'gold.cost_centers'  => ['tier' => 'gold', 'plan' => 'any'],
        'gold.treasury'      => ['tier' => 'gold', 'plan' => 'any'],
        'gold.vouchers'      => ['tier' => 'gold', 'plan' => 'any'],
        'gold.vat'           => ['tier' => 'gold', 'plan' => 'any'],
        'gold.invoices'      => ['tier' => 'gold', 'plan' => 'any'],
        'gold.reports'       => ['tier' => 'gold', 'plan' => 'any'],
        'gold.yearend'       => ['tier' => 'gold', 'plan' => 'any'],
        'gold.payroll_law'   => ['tier' => 'gold', 'plan' => 'any'],
        'gold.fx_accounting' => ['tier' => 'gold', 'plan' => 'fx'],
        'fx.reval'           => ['tier' => 'gold', 'plan' => 'fx'], // FXREVAL step 13: batch FX revaluation (menu + screen); server actions still check edition + permissions
    ];
}

/** Normalize anything to a valid tier (unknown/missing -> green). */
function pb_tier_of(array $customer): string {
    return (($customer['tier'] ?? '') === PB_TIER_GOLD) ? PB_TIER_GOLD : PB_TIER_GREEN;
}

/** Plan string without depending on config.php constants ('fx' | 'rial'). */
function pb_plan_of(array $customer): string {
    if (function_exists('customer_plan')) {
        return ((string)customer_plan($customer) === 'fx') ? 'fx' : 'rial';
    }
    return (($customer['plan'] ?? '') === 'fx') ? 'fx' : 'rial';
}

function pb_feature_allowed(string $tier, string $plan, array $rule): bool {
    if (($rule['tier'] ?? 'green') === PB_TIER_GOLD && $tier !== PB_TIER_GOLD) return false;
    if (($rule['plan'] ?? 'any') === 'fx' && $plan !== 'fx') return false;
    return true;
}

/** The four edition names (shared constant; mirrored in app/lib/edition.dart `kEditionNames`). */
function pb_edition_names(): array {
    return [
        'green_rial' => 'پسته بازار سبز ریالی',
        'green_fx'   => 'پسته بازار سبز ارزی',
        'gold_rial'  => 'پسته بازار طلایی ریالی',
        'gold_fx'    => 'پسته بازار طلایی ارزی',
    ];
}

function edition_name(string $tier, string $plan): string {
    $t = $tier === PB_TIER_GOLD ? PB_TIER_GOLD : PB_TIER_GREEN;
    $p = $plan === 'fx' ? 'fx' : 'rial';
    $n = pb_edition_names();
    return $n[$t . '_' . $p];
}

/**
 * Edition descriptor sent to clients: {tier, plan, code, features[], limits{}}.
 * Existing customers (no tier column/value) become green + their current plan.
 */
function edition_of(array $customer): array {
    $tier = pb_tier_of($customer);
    $plan = pb_plan_of($customer);
    $features = [];
    foreach (pb_feature_catalog() as $key => $rule) {
        if (pb_feature_allowed($tier, $plan, $rule)) $features[] = $key;
    }
    $staff = $customer['staff_limit'] ?? null; // optional per-customer cap (Q2: default unlimited = null)
    return [
        'tier' => $tier,
        'plan' => $plan,
        'code' => $tier . '_' . $plan,
        'name' => edition_name($tier, $plan),
        'features' => $features,
        'limits' => ['staff_users' => ($staff === null || $staff === '') ? null : (int)$staff],
    ];
}

function edition_has(array $edition, string $feature): bool {
    return in_array($feature, $edition['features'] ?? [], true);
}

/** What is missing for this feature? 'gold' | 'fx' | 'unknown' */
function pb_feature_need(array $edition, string $feature): string {
    $cat = pb_feature_catalog();
    if (!isset($cat[$feature])) return 'unknown';
    if (($cat[$feature]['tier'] ?? 'green') === PB_TIER_GOLD && ($edition['tier'] ?? '') !== PB_TIER_GOLD) return 'gold';
    return 'fx';
}

/** Send 403 {error:'edition_required', need, feature} and stop. Unknown feature = denied. */
function require_feature(array $edition, string $feature): void {
    if (edition_has($edition, $feature)) return;
    $body = [
        'error'   => 'edition_required',
        'need'    => pb_feature_need($edition, $feature),
        'feature' => $feature,
        'message' => 'این بخش در نسخهٔ فعلی شما فعال نیست',
    ];
    if (function_exists('out')) { out($body, 403); } // app.php: rolls back + releases idempotency key
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}
