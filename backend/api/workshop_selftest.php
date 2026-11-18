<?php
declare(strict_types=1);
/**
 * تست خالص بهای کارگاه (مرحلهٔ ۴) — بدون دیتابیس. یک‌بار در مرورگر/CLI اجرا کنید؛ ok=true یعنی همه‌چیز درست است. بعد از اطمینان حذفش کنید.
 * کیس‌ها دقیقاً همان WINDOWS/test/workshop_cost_cases.json است (برای مرحلهٔ ۵ Dart).
 */
require_once __DIR__ . '/workshop_calc.php';
$doc = json_decode(<<<'JSON'
{
 "_doc": "کیس‌های مشترک بهای کارگاه (مرحلهٔ ۴). prices=V/X/Y؛ outs به ترتیب ثبت (price=قیمت هر کیلوی ردیف؛ null برای سالم). مبالغ گرد ۲رقم؛ cost_per_kg_sound گرد ۴رقم. tol=1e-6. PHP: backend/api/workshop_selftest.php؛ Dart: مرحلهٔ ۵.",
 "cases": [
  {
   "name": "no_custom",
   "input_kg": 100,
   "loss_kg": 5,
   "prices": {
    "buy_per_kg": 100000,
    "wage_per_kg": 2000,
    "other_cost": 50000
   },
   "legacy_same": true,
   "outs": [
    {
     "grade": "sound",
     "kg": 80,
     "price": null
    },
    {
     "grade": "deformed_fine",
     "kg": 5,
     "price": 30000
    },
    {
     "grade": "hull",
     "kg": 6,
     "price": 1000
    },
    {
     "grade": "kernel",
     "kg": 3,
     "price": 200000
    },
    {
     "grade": "soil",
     "kg": 1,
     "price": 0
    }
   ],
   "expect": {
    "balance_ok": true,
    "diff_kg": 0.0,
    "value_in": 10000000.0,
    "cost_total": 250000.0,
    "recovery_value": 756000.0,
    "cost_sound": 9494000.0,
    "cost_per_kg_sound": 118675.0,
    "warning": null,
    "lots": [
     9494000.0,
     150000.0,
     6000.0,
     600000.0,
     0.0
    ]
   }
  },
  {
   "name": "one_custom",
   "input_kg": 100,
   "loss_kg": 6,
   "prices": {
    "buy_per_kg": 100000,
    "wage_per_kg": 2000,
    "other_cost": 50000
   },
   "custom_names": {
    "5": "فندق"
   },
   "outs": [
    {
     "grade": "sound",
     "kg": 75,
     "price": null
    },
    {
     "grade": "deformed_fine",
     "kg": 5,
     "price": 30000
    },
    {
     "grade": "hull",
     "kg": 6,
     "price": 1000
    },
    {
     "grade": "kernel",
     "kg": 3,
     "price": 200000
    },
    {
     "grade": "soil",
     "kg": 1,
     "price": 0
    },
    {
     "grade": "custom",
     "kg": 4,
     "price": 15000
    }
   ],
   "expect": {
    "balance_ok": true,
    "diff_kg": 0.0,
    "value_in": 10000000.0,
    "cost_total": 250000.0,
    "recovery_value": 816000.0,
    "cost_sound": 9434000.0,
    "cost_per_kg_sound": 125786.6667,
    "warning": null,
    "lots": [
     9434000.0,
     150000.0,
     6000.0,
     600000.0,
     0.0,
     60000.0
    ]
   }
  },
  {
   "name": "two_custom",
   "input_kg": 100,
   "loss_kg": 9.5,
   "prices": {
    "buy_per_kg": 100000,
    "wage_per_kg": 2000,
    "other_cost": 50000
   },
   "outs": [
    {
     "grade": "sound",
     "kg": 70,
     "price": null
    },
    {
     "grade": "deformed_fine",
     "kg": 5,
     "price": 30000
    },
    {
     "grade": "hull",
     "kg": 6,
     "price": 1000
    },
    {
     "grade": "kernel",
     "kg": 3,
     "price": 200000
    },
    {
     "grade": "custom",
     "kg": 4,
     "price": 15000
    },
    {
     "grade": "custom",
     "kg": 2.5,
     "price": 40000
    }
   ],
   "expect": {
    "balance_ok": true,
    "diff_kg": 0.0,
    "value_in": 10000000.0,
    "cost_total": 250000.0,
    "recovery_value": 916000.0,
    "cost_sound": 9334000.0,
    "cost_per_kg_sound": 133342.8571,
    "warning": null,
    "lots": [
     9334000.0,
     150000.0,
     6000.0,
     600000.0,
     60000.0,
     100000.0
    ]
   }
  },
  {
   "name": "negative_cost",
   "input_kg": 10,
   "loss_kg": 0,
   "prices": {
    "buy_per_kg": 1000,
    "wage_per_kg": 0,
    "other_cost": 0
   },
   "outs": [
    {
     "grade": "sound",
     "kg": 5,
     "price": null
    },
    {
     "grade": "kernel",
     "kg": 5,
     "price": 5000
    }
   ],
   "expect": {
    "balance_ok": true,
    "diff_kg": 0.0,
    "value_in": 10000.0,
    "cost_total": 0.0,
    "recovery_value": 25000.0,
    "cost_sound": -15000.0,
    "cost_per_kg_sound": -3000.0,
    "warning": "negative_cost",
    "lots": [
     -15000.0,
     25000.0
    ]
   }
  },
  {
   "name": "zero_weight_custom",
   "input_kg": 10,
   "loss_kg": 0,
   "prices": {
    "buy_per_kg": 1000,
    "wage_per_kg": 0,
    "other_cost": 0
   },
   "outs": [
    {
     "grade": "sound",
     "kg": 10,
     "price": null
    },
    {
     "grade": "custom",
     "kg": 0,
     "price": 99999
    }
   ],
   "expect": {
    "balance_ok": true,
    "diff_kg": 0.0,
    "value_in": 10000.0,
    "cost_total": 0.0,
    "recovery_value": 0.0,
    "cost_sound": 10000.0,
    "cost_per_kg_sound": 1000.0,
    "warning": null,
    "lots": [
     10000.0,
     0.0
    ]
   }
  },
  {
   "name": "zero_sound",
   "input_kg": 10,
   "loss_kg": 0,
   "prices": {
    "buy_per_kg": 1000,
    "wage_per_kg": 0,
    "other_cost": 0
   },
   "outs": [
    {
     "grade": "kernel",
     "kg": 10,
     "price": 1000
    }
   ],
   "expect": {
    "balance_ok": true,
    "diff_kg": 0.0,
    "value_in": 10000.0,
    "cost_total": 0.0,
    "recovery_value": 10000.0,
    "cost_sound": 0.0,
    "cost_per_kg_sound": null,
    "warning": null,
    "lots": [
     10000.0
    ]
   }
  }
 ]
}
JSON, true);
$fail = [];
$eq = function ($a, $b): bool {
    if ($a === null || $b === null) return $a === $b;
    if (is_bool($a) || is_bool($b) || is_string($a)) return $a === $b;
    return abs((float)$a - (float)$b) < 1e-6;
};
foreach ($doc['cases'] as $c) {
    $by = []; $pr = $c['prices'];
    foreach ($c['outs'] as $o) $by[$o['grade']] = ($by[$o['grade']] ?? 0.0) + $o['kg'];
    $bal = pb_ws_balance($c['input_kg'], $by, $c['loss_kg']);
    $cost = pb_ws_cost($c['input_kg'], $by, $pr + ['outs' => $c['outs']]);
    $lots = pb_ws_alloc($c['outs'], $pr, $cost);
    $e = $c['expect'];
    $got = ['balance_ok' => $bal['ok'], 'diff_kg' => $bal['diff_kg'], 'value_in' => $cost['value_in'], 'cost_total' => $cost['cost_total'],
        'recovery_value' => $cost['recovery_value'], 'cost_sound' => $cost['cost_sound'], 'cost_per_kg_sound' => $cost['cost_per_kg_sound'], 'warning' => $cost['warning']];
    foreach ($got as $k => $v) if (!$eq($v, $e[$k])) $fail[] = $c['name'] . ': ' . $k . ' got ' . json_encode($v) . ' want ' . json_encode($e[$k]);
    foreach ($e['lots'] as $i => $v) if (!$eq($lots[$i] ?? null, $v)) $fail[] = $c['name'] . ': lot[' . $i . '] got ' . json_encode($lots[$i] ?? null) . ' want ' . json_encode($v);
    if (!empty($c['legacy_same'])) { // بدون outs و با کلیدهای قدیمی price_* باید نتیجهٔ یکسان بدهد
        $legacy = $pr + ['price_deformed' => 30000, 'price_hull' => 1000, 'price_kernel' => 200000, 'price_soil' => 0, 'price_discolored' => 0];
        $old = pb_ws_cost($c['input_kg'], $by, $legacy);
        foreach (['value_in', 'cost_total', 'recovery_value', 'cost_sound', 'cost_per_kg_sound'] as $k) if (!$eq($old[$k], $cost[$k])) $fail[] = $c['name'] . ': legacy ' . $k . ' differs';
    }
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => !$fail, 'cases' => count($doc['cases']), 'failed' => $fail], JSON_UNESCAPED_UNICODE);
