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

function load_json($file, $default = []) {
    if (!is_file($file)) return $default;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function save_json($file, $data) {
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

log_msg("Přijata objednávka");

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Pošle e-mail přes SMTP nebo mail(), vrátí true/false. */
function send_email($to, $subject, $body, $headers, $cc = null) {
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

        // RCPT TO (hlavní příjemce)
        log_msg("OBJEDNAVKA: RCPT TO <{$to}>");
        fwrite($socket, "RCPT TO:<{$to}>\r\n");
        $resp = smtp_read_response($socket);
        log_msg("OBJEDNAVKA: RCPT TO odpověď: " . trim($resp));

        // RCPT TO (CC - je-li zadán)
        if ($cc) {
            log_msg("OBJEDNAVKA: RCPT TO (Cc) <{$cc}>");
            fwrite($socket, "RCPT TO:<{$cc}>\r\n");
            $resp = smtp_read_response($socket);
            log_msg("OBJEDNAVKA: RCPT TO Cc odpověď: " . trim($resp));
        }

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

// Zaznamenání souhlasů
$souhlasy = load_json(SOUHLASY_FILE, []);
$souhlasy[] = [
    'cas' => date('Y-m-d H:i:s'),
    'jmeno' => $name,
    'prijmeni' => $surname,
    'email' => $email,
    'vop' => !empty($o['vop']),
    'gdpr' => !empty($o['gdpr']),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'neznámá'
];
save_json(SOUHLASY_FILE, $souhlasy);
log_msg("OBJEDNAVKA: Zaznamenání souhlasů pro {$email}");

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

// --- E-mail provozovateli (HTML) ---
$adminBody = <<<EOF
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; color: #2c1810; background: #faf8f4; margin: 0; padding: 0; }
        .email-container { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #b8963e; color: #fff; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
        .content { padding: 30px 20px; }
        .section { margin-bottom: 25px; }
        .section-title { color: #b8963e; font-size: 16px; font-weight: 600; border-bottom: 2px solid #d4b562; padding-bottom: 8px; margin-bottom: 12px; }
        .customer-info { background: #faf8f4; padding: 15px; border-radius: 4px; margin-bottom: 15px; }
        .customer-info p { margin: 5px 0; font-size: 14px; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .items-table th { background: #f5f1e8; text-align: left; padding: 10px; font-size: 13px; font-weight: 600; color: #2c1810; border-bottom: 1px solid #e5dccf; }
        .items-table td { padding: 10px; font-size: 14px; border-bottom: 1px solid #f0ede0; }
        .items-table .price { text-align: right; color: #b8963e; font-weight: 600; }
        .totals { margin-top: 20px; padding-top: 15px; border-top: 2px solid #e5dccf; }
        .total-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; }
        .total-final { display: flex; justify-content: space-between; padding: 12px 0; font-size: 16px; font-weight: 600; color: #b8963e; }
        .footer { background: #f5f1e8; padding: 20px; text-align: center; font-size: 12px; color: #6b5d4f; border-top: 1px solid #e5dccf; }
        .btn { display: inline-block; background: #b8963e; color: #fff; padding: 10px 20px; border-radius: 4px; text-decoration: none; font-weight: 600; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>Nová objednávka č. {$num}</h1>
        </div>
        <div class="content">
            <div class="section">
                <div class="section-title">Zákazník</div>
                <div class="customer-info">
                    <p><strong>{$name} {$surname}</strong></p>
                    <p>E-mail: {$email}</p>
                    <p>Telefon: " . ($phone !== '' ? $phone : '—') . "</p>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Doručení & Platba</div>
                <div class="customer-info">
                    <p><strong>Doručení:</strong> {$deliveryLabel}</p>
                    " . ($addr !== '' ? "<p><strong>Adresa:</strong> {$addr}</p>" : "") . "
                    <p><strong>Platba:</strong> {$paymentLabel}</p>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Položky</div>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Víno</th>
                            <th>Počet</th>
                            <th class="price">Cena</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$lines}
                    </tbody>
                </table>
            </div>

            <div class="totals">
                <div class="total-row">
                    <span>Mezisoučet:</span>
                    <span>{$subtotal} Kč</span>
                </div>
                " . ($discountAmount > 0 ? "<div class='total-row'><span>Sleva " . (int)round($discount * 100) . "% (" . $totalItems . " lahví):</span><span>-{$discountAmount} Kč</span></div>" : "") . "
                <div class=\"total-final\">
                    <span>CELKEM (bez dopravy):</span>
                    <span>{$total} Kč</span>
                </div>
            </div>
        </div>
        <div class="footer">
            <p>Vinařství Želivka | Hněvkovice nad Želivkou</p>
        </div>
    </div>
</body>
</html>
EOF;

$subject = SHOP_NAME . " — nová objednávka č. {$num}";
$headers = [
    'From: ' . SHOP_NAME . ' <' . FROM_EMAIL . '>',
    'To: ' . ORDER_EMAIL,
    'Cc: ' . $email,
    'Reply-To: ' . $name . ' ' . $surname . ' <' . $email . '>',
    'Content-Type: text/html; charset=utf-8',
    'X-Mailer: PHP/' . phpversion(),
];
$encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
log_msg("OBJEDNAVKA: Posílám e-mail PROVOZOVATELI na " . ORDER_EMAIL . " (Cc: {$email})");
$sentAdmin = send_email(ORDER_EMAIL, $encSubject, $adminBody, implode("\r\n", $headers), $email);
log_msg("OBJEDNAVKA: E-mail s CC vrátil: " . ($sentAdmin ? "true" : "false"));

if (!$sentAdmin) {
    log_msg("OBJEDNAVKA: CHYBA - Nepodařilo se poslat e-mail provozovateli!");
    fail('Objednávku se nepodařilo odeslat e-mailem. Kontaktujte nás prosím přímo.', 500);
}

echo json_encode(['ok' => true, 'order' => $num], JSON_UNESCAPED_UNICODE);
