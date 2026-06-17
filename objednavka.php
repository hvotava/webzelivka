<?php
/**
 * Příjem objednávky z e-shopu a odeslání e-mailem provozovateli i zákazníkovi.
 * Volá se přes fetch('objednavka.php') z index.html (metoda POST, JSON tělo).
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Neplatná metoda požadavku.', 405);
}

$raw = file_get_contents('php://input');
$o = json_decode($raw, true);
if (!is_array($o)) {
    fail('Neplatná data objednávky.');
}

// --- Validace ---
$c = $o['customer'] ?? [];
$name    = trim($c['name'] ?? '');
$surname = trim($c['surname'] ?? '');
$email   = trim($c['email'] ?? '');
$phone   = trim($c['phone'] ?? '');

if ($name === '' || $surname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Chybí povinné údaje (jméno, příjmení, platný e-mail).');
}
$items = $o['items'] ?? [];
if (!is_array($items) || count($items) === 0) {
    fail('Objednávka neobsahuje žádné položky.');
}

$d = $o['delivery'] ?? [];
$p = $o['payment'] ?? [];
$deliveryLabel = trim($d['label'] ?? '');
$paymentLabel  = trim($p['label'] ?? '');
$addr = '';
if (($d['method'] ?? '') === 'messenger') {
    $addr = trim($d['street'] ?? '') . ', ' . trim($d['zip'] ?? '') . ' ' . trim($d['city'] ?? '');
}

// --- Sestavení textu položek (ceny počítáme znovu na serveru) ---
$lines = '';
$subtotal = 0;
$totalItems = 0;
foreach ($items as $it) {
    $iname = trim($it['name'] ?? 'Víno');
    $qty   = max(0, (int)($it['qty'] ?? 0));
    $price = max(0, (int)($it['price'] ?? 0));
    if ($qty === 0) continue;
    $line = $price * $qty;
    $subtotal += $line;
    $totalItems += $qty;
    $lines .= sprintf("  %-32s %2d ks × %4d Kč = %6d Kč\n", $iname, $qty, $price, $line);
}
if ($totalItems === 0) {
    fail('Objednávka neobsahuje žádné položky.');
}

$discount = $totalItems >= 12 ? 0.10 : ($totalItems >= 6 ? 0.05 : 0);
$discountAmount = (int)round($subtotal * $discount);
$total = $subtotal - $discountAmount;

$num = date('YmdHis');

// --- E-mail provozovateli ---
$adminBody  = "Nová objednávka z webu (č. {$num})\n";
$adminBody .= "=======================================\n\n";
$adminBody .= "Zákazník:\n";
$adminBody .= "  {$name} {$surname}\n";
$adminBody .= "  E-mail: {$email}\n";
$adminBody .= "  Telefon: " . ($phone !== '' ? $phone : '—') . "\n\n";
$adminBody .= "Doručení: {$deliveryLabel}\n";
if ($addr !== '') $adminBody .= "  Adresa: {$addr}\n";
$adminBody .= "Platba: {$paymentLabel}\n\n";
$adminBody .= "Položky:\n{$lines}\n";
$adminBody .= str_repeat('-', 60) . "\n";
$adminBody .= sprintf("  Mezisoučet: %d Kč\n", $subtotal);
if ($discountAmount > 0) {
    $adminBody .= sprintf("  Sleva %d %% (%d lahví): -%d Kč\n", (int)round($discount * 100), $totalItems, $discountAmount);
}
$adminBody .= sprintf("  CELKEM (bez dopravy): %d Kč\n", $total);

$subject = SHOP_NAME . " — nová objednávka č. {$num}";
$headers = [
    'From: ' . SHOP_NAME . ' <' . FROM_EMAIL . '>',
    'Reply-To: ' . $name . ' ' . $surname . ' <' . $email . '>',
    'Content-Type: text/plain; charset=utf-8',
    'X-Mailer: PHP/' . phpversion(),
];
$encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$sentAdmin = @mail(ORDER_EMAIL, $encSubject, $adminBody, implode("\r\n", $headers));

// --- Potvrzení zákazníkovi (selhání neukončí objednávku) ---
$custBody  = "Dobrý den, {$name} {$surname},\n\n";
$custBody .= "děkujeme za Vaši objednávku v " . SHOP_NAME . " (č. {$num}).\n\n";
$custBody .= "Souhrn objednávky:\n{$lines}\n";
$custBody .= sprintf("Celkem (bez dopravy): %d Kč\n\n", $total);
$custBody .= "Doručení: {$deliveryLabel}\n";
if ($addr !== '') $custBody .= "Adresa: {$addr}\n";
$custBody .= "Platba: {$paymentLabel}\n\n";
if (($p['method'] ?? '') === 'transfer') {
    $custBody .= "Platební údaje pro převod:\n";
    $custBody .= "  Číslo účtu: 301019565/5500\n";
    $custBody .= sprintf("  Částka: %d Kč\n", $total);
    $custBody .= "  Variabilní symbol: {$num}\n\n";
}
$custBody .= "Brzy se Vám ozveme s potvrzením. V případě dotazů nás kontaktujte\n";
$custBody .= "na " . ORDER_EMAIL . " nebo na tel. 222 075 101.\n\n";
$custBody .= SHOP_NAME . "\n";

$custHeaders = [
    'From: ' . SHOP_NAME . ' <' . FROM_EMAIL . '>',
    'Reply-To: ' . ORDER_EMAIL,
    'Content-Type: text/plain; charset=utf-8',
];
$encCustSubject = '=?UTF-8?B?' . base64_encode(SHOP_NAME . ' — potvrzení objednávky č. ' . $num) . '?=';
@mail($email, $encCustSubject, $custBody, implode("\r\n", $custHeaders));

if (!$sentAdmin) {
    fail('Objednávku se nepodařilo odeslat e-mailem. Kontaktujte nás prosím přímo.', 500);
}

echo json_encode(['ok' => true, 'order' => $num], JSON_UNESCAPED_UNICODE);
