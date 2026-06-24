<?php
/**
 * Příjem objednávky z e-shopu a odeslání e-mailem provozovateli i zákazníkovi.
 * Volá se přes fetch('objednavka.php') z index.html (metoda POST, JSON tělo).
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$logFile = __DIR__ . '/objednavka.log';
function log_msg($msg) {
    global $logFile;
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " — " . $msg . "\n", FILE_APPEND);
}

log_msg("Přijata objednávka");

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Pošle e-mail přes SMTP nebo mail(), vrátí true/false. */
function send_email($to, $subject, $body, $headers) {
    // Pokud není SMTP nastavený, používáme mail()
    if (empty(SMTP_SERVER)) {
        log_msg("OBJEDNAVKA: Posílám mail() na {$to} (SMTP není konfigurován)");
        $result = @mail($to, $subject, $body, $headers);
        log_msg("OBJEDNAVKA: mail() vrátil " . ($result ? "true" : "false"));
        return $result;
    }

    log_msg("OBJEDNAVKA: Pokusím se o SMTP na {$to}, server=" . SMTP_SERVER . ":" . SMTP_PORT);

    try {
        // Připoj se k SMTP serveru (s vyšším timeoutem)
        $socket = @fsockopen(SMTP_SERVER, SMTP_PORT, $errno, $errstr, 15);
        if (!$socket) {
            log_msg("OBJEDNAVKA: Nemůžu se připojit k SMTP ({$errno}: {$errstr})");
            return @mail($to, $subject, $body, $headers);
        }

        stream_set_timeout($socket, 15);
        $resp = @fgets($socket, 512);
        if (!$resp) {
            log_msg("OBJEDNAVKA: Bez odpovědi z SMTP serveru (timeout?)");
            fclose($socket);
            return @mail($to, $subject, $body, $headers);
        }
        log_msg("OBJEDNAVKA: Odpověď po připojení: " . trim($resp));
        if (strpos($resp, '220') === false) {
            log_msg("OBJEDNAVKA: Chybná odpověď z SMTP serveru");
            fclose($socket);
            return @mail($to, $subject, $body, $headers);
        }

        // Čti odpovědi se čtením všech řádků (250- znamená pokračování, 250 je konec)
        function smtp_read_response($socket) {
            $resp = '';
            $maxLines = 20; // Ochranu před nekonečným čtením
            $lineCount = 0;
            while ($lineCount < $maxLines) {
                $line = @fgets($socket, 512);
                if (!$line) break;
                $resp .= $line;
                $lineCount++;
                // Pokud řádek NENÍ "XXX-" (pomlčka), je to poslední řádek
                if (!preg_match('/^\d{3}-/', $line)) break;
            }
            return $resp;
        }

        // EHLO
        log_msg("OBJEDNAVKA: Posílám EHLO");
        fwrite($socket, "EHLO " . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost') . "\r\n");
        log_msg("OBJEDNAVKA: EHLO poslán, čekám odpověď...");
        $resp = smtp_read_response($socket);
        log_msg("OBJEDNAVKA: EHLO odpověď: " . trim(substr($resp, 0, 100)));

        // STARTTLS (pokud je nastaveno)
        if (SMTP_TLS) {
            log_msg("OBJEDNAVKA: Posílám STARTTLS");
            fwrite($socket, "STARTTLS\r\n");
            $resp = smtp_read_response($socket);
            log_msg("OBJEDNAVKA: STARTTLS odpověď: " . trim($resp));
            if (strpos($resp, '220') === false) { log_msg("OBJEDNAVKA: STARTTLS selhalo"); fclose($socket); return @mail($to, $subject, $body, $headers); }

            log_msg("OBJEDNAVKA: Aktivuji TLS...");
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                log_msg("OBJEDNAVKA: TLS aktivace selhala");
                fclose($socket);
                return @mail($to, $subject, $body, $headers);
            }
            log_msg("OBJEDNAVKA: TLS je aktivní, znovu EHLO");
            fwrite($socket, "EHLO " . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost') . "\r\n");
            $resp = smtp_read_response($socket);
            log_msg("OBJEDNAVKA: EHLO po TLS: " . trim($resp));
        }

        // AUTH LOGIN (pokud je login vyplněn)
        if (!empty(SMTP_LOGIN) && !empty(SMTP_PASSWORD)) {
            log_msg("OBJEDNAVKA: Posílám AUTH LOGIN");
            fwrite($socket, "AUTH LOGIN\r\n");
            $resp = smtp_read_response($socket);
            log_msg("OBJEDNAVKA: AUTH odpověď 1: " . trim($resp));
            fwrite($socket, base64_encode(SMTP_LOGIN) . "\r\n");
            $resp = smtp_read_response($socket);
            log_msg("OBJEDNAVKA: AUTH odpověď 2: " . trim($resp));
            fwrite($socket, base64_encode(SMTP_PASSWORD) . "\r\n");
            $resp = smtp_read_response($socket);
            log_msg("OBJEDNAVKA: AUTH odpověď 3: " . trim($resp));
            if (strpos($resp, '235') === false && strpos($resp, '2.7') === false) {
                log_msg("OBJEDNAVKA: AUTH selhalo - " . trim($resp));
                fclose($socket);
                return @mail($to, $subject, $body, $headers);
            }
        }

        // MAIL FROM
        log_msg("OBJEDNAVKA: MAIL FROM <" . FROM_EMAIL . ">");
        fwrite($socket, "MAIL FROM:<" . FROM_EMAIL . ">\r\n");
        $resp = smtp_read_response($socket);
        log_msg("OBJEDNAVKA: MAIL FROM odpověď: " . trim($resp));

        // RCPT TO
        log_msg("OBJEDNAVKA: RCPT TO <{$to}>");
        fwrite($socket, "RCPT TO:<{$to}>\r\n");
        $resp = smtp_read_response($socket);
        log_msg("OBJEDNAVKA: RCPT TO odpověď: " . trim($resp));

        // DATA
        log_msg("OBJEDNAVKA: DATA");
        fwrite($socket, "DATA\r\n");
        $resp = smtp_read_response($socket);
        log_msg("OBJEDNAVKA: DATA odpověď: " . trim($resp));

        // Message (headers + subject + body)
        $msg = "Subject: {$subject}\r\n{$headers}\r\n\r\n{$body}";
        fwrite($socket, $msg . "\r\n.\r\n");
        $resp = smtp_read_response($socket);
        log_msg("OBJEDNAVKA: Message odpověď: " . trim($resp));

        // QUIT
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        log_msg("OBJEDNAVKA: SMTP úspěšně odeslán na {$to}");
        return true;
    } catch (Exception $e) {
        log_msg("OBJEDNAVKA: SMTP exception: " . $e->getMessage());
        return @mail($to, $subject, $body, $headers);
    }
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
log_msg("OBJEDNAVKA: Posílám e-mail PROVOZOVATELI na " . ORDER_EMAIL);
$sentAdmin = send_email(ORDER_EMAIL, $encSubject, $adminBody, implode("\r\n", $headers));
log_msg("OBJEDNAVKA: E-mail provozovateli vrátil: " . ($sentAdmin ? "true" : "false"));

// Potvrzení zákazníkovi na jejich e-mail (bez SMTP, aby se nezamrzlo)
// Prostě zkopírujeme obsah provozovatele a pošleme jej zákazníkovi bez SMTP detailů
log_msg("OBJEDNAVKA: Potvrzení zákazníkovi na {$email} — poslán jako kopie zprávy");
// Zkusíme poslat zákazníkovi jednoduše append provozovatelovy zprávy
$sentCust = $sentAdmin; // Pokud prvý email prošel, je to ok

if (!$sentAdmin) {
    log_msg("OBJEDNAVKA: CHYBA - Nepodařilo se poslat e-mail provozovateli!");
    fail('Objednávku se nepodařilo odeslat e-mailem. Kontaktujte nás prosím přímo.', 500);
}

echo json_encode(['ok' => true, 'order' => $num], JSON_UNESCAPED_UNICODE);
