<?php

const SERVER_SECRET = 'Ihr_Sehr_Geheimer_Schluessel_Hier'; // Niemals im Code lassen! Aus Config laden.
const COOKIE_NAME = 'proof_of_work_passed';
const COOKIE_LIFETIME = 3600 * 24; // Gültigkeit des Cookies (hier 24 Stunden)
const CHALLENGE_DIFFICULTY = 4; // Anzahl führender Nullen im Hash (Anpassen!)
const CHALLENGE_SESSION_KEY = 'js_challenge_data';

// --- Hilfsfunktionen ---

function isWhitelistedBot(string $userAgent): bool {
    // SEHR WICHTIG: Implementieren Sie hier eine robuste Prüfung!
    // Mindestens User-Agent prüfen (Groß-/Kleinschreibung ignorieren).
    // Besser: Zusätzlich Reverse DNS Lookup für bekannte Suchmaschinen-IPs.
    $knownGoodBots = ['googlebot', 'bingbot', 'slurp', 'duckduckbot', /* ... mehr ... */];
    foreach ($knownGoodBots as $bot) {
        if (stripos($userAgent, $bot) !== false) {
            // Hier sollte idealerweise noch die IP per rDNS geprüft werden!
            return true;
        }
    }
    return false;
}

function validateProofCookie(string $cookieValue): bool {
    if (!str_contains($cookieValue, '.')) return false;

    [$timestamp, $signature] = explode('.', $cookieValue, 2);

    if (!is_numeric($timestamp)) return false;

    // Zeitliche Gültigkeit prüfen (optional, aber sinnvoll)
    if (time() > ($timestamp + COOKIE_LIFETIME)) {
        return false; // Abgelaufen
    }

    $expectedSignature = hash_hmac('sha256', $timestamp, SERVER_SECRET);
    return hash_equals($expectedSignature, $signature);
}

function sendForbidden(string $reason = 'Access Denied'): never
{
    // Optional: Logging
    error_log("JS Challenge Blocker: Forbidden access for " . ($_SERVER['REMOTE_ADDR'] ?? '?.?.?.?') . " - UA: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A') . " - Reason: " . $reason);
    header('HTTP/1.1 403 Forbidden');
    echo "403 Bot Blocked";
    exit;
}

// --- 0. Whitelisting für wichtige Bots ---
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (isWhitelistedBot($userAgent)) { // isWhitelistedBot() muss implementiert werden (UA + rDNS Check!)
    return; // Zugriff erlauben, nichts weiter tun
}

// --- 1. Prüfen, ob ein gültiges Proof-Cookie existiert ---
if (isset($_COOKIE[COOKIE_NAME])) {
    if (validateProofCookie($_COOKIE[COOKIE_NAME])) {
        // Gültiges Cookie, Zugriff erlauben
        return;
    } else {
        // Ungültiges oder manipuliertes Cookie
        unset($_COOKIE[COOKIE_NAME]);
        setcookie(COOKIE_NAME, '', time() - 3600); // Löschen
        // Weiter zur Challenge ...
    }
}



// --- 2. Verarbeiten einer eingesendeten Lösung ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['challenge_nonce'], $_POST['challenge_solution'])) {
    if (isset($_SESSION[CHALLENGE_SESSION_KEY])) {
        $challengeData = $_SESSION[CHALLENGE_SESSION_KEY];

        // Verhindere Timing-Attacken bei der String-Prüfung
        if (hash_equals($challengeData['nonce'], $_POST['challenge_nonce'])) {
            // Berechne den erwarteten Hash auf dem Server
            $hashToCheck = hash('sha256', $challengeData['nonce'] . $_POST['challenge_solution']);

            // Prüfe Schwierigkeit
            if (substr($hashToCheck, 0, CHALLENGE_DIFFICULTY) === str_repeat('0', CHALLENGE_DIFFICULTY)) {
                // Korrekte Lösung!
                unset($_SESSION[CHALLENGE_SESSION_KEY]); // Challenge verbraucht

                // Erzeuge und setze das signierte Cookie
                $cookieValue = time(); // Einfacher Zeitstempel als Payload
                $signature = hash_hmac('sha256', $cookieValue, SERVER_SECRET);
                setcookie(COOKIE_NAME, $cookieValue . '.' . $signature, time() + COOKIE_LIFETIME, '/', '', true, true); // Secure, HttpOnly

                // Weiterleiten zur ursprünglichen URL (oder einfach Request fortsetzen lassen)
                // Idealerweise die ursprüngliche URL speichern und dorthin leiten
                header('Location: ' . $_SERVER['REQUEST_URI']); // Einfaches Neuladen der aktuellen URI
                exit;
            }
        }
    }
    // Wenn etwas fehlschlägt (falsche Nonce, falsche Lösung) -> 403
    sendForbidden('Challenge Verification Failed');
    exit;
}

// --- 3. Keine gültige Bestätigung, keine Lösung gesendet -> Challenge ausliefern ---

// Generiere neue Challenge
$challengeNonce = bin2hex(random_bytes(16));
$_SESSION[CHALLENGE_SESSION_KEY] = [
    'nonce' => $challengeNonce,
    'difficulty' => CHALLENGE_DIFFICULTY,
    'timestamp' => time()
];

// Sende die Challenge-Seite (HTML + JS)
header('HTTP/1.1 503 Service Unavailable'); // Oder anderen passenden Code
header('Retry-After: 10'); // Hinweis für Clients

?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Browser-Verifizierung</title>
        <style>
            body { font-family: sans-serif; padding: 2em; }
            #message { margin-bottom: 1em; }
            #spinner { /* Einfacher CSS Spinner... */ }
        </style>
    </head>
    <body>
    <h1>Bitte warten, Ihr Browser wird verifiziert...</h1>
    <p id="message">Dies ist eine Sicherheitsmaßnahme gegen automatisierte Zugriffe. Ihr Browser führt eine kurze Berechnung durch.</p>
    <div id="spinner"></div>

    <form id="challengeForm" method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
        <input type="hidden" name="challenge_nonce" value="<?php echo htmlspecialchars($challengeNonce); ?>">
        <input type="hidden" id="challengeSolution" name="challenge_solution" value="">
    </form>

    <script>
        async function solveChallenge() {
            const nonce = "<?php echo htmlspecialchars($challengeNonce); ?>";
            const difficulty = <?php echo CHALLENGE_DIFFICULTY; ?>;
            const targetPrefix = '0'.repeat(difficulty);
            let solution = 0;
            let hash = '';
            const encoder = new TextEncoder();

            console.log('Starting challenge...');
            const startTime = Date.now();

            while (true) {
                const dataToHash = nonce + solution;
                const buffer = encoder.encode(dataToHash);
                const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
                const hashArray = Array.from(new Uint8Array(hashBuffer));
                hash = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');

                if (hash.startsWith(targetPrefix)) {
                    const endTime = Date.now();
                    console.log(`Challenge solved in ${endTime - startTime}ms. Solution: ${solution}, Hash: ${hash}`);
                    document.getElementById('challengeSolution').value = solution;
                    document.getElementById('message').innerText = 'Verifizierung erfolgreich. Sie werden weitergeleitet...';
                    document.getElementById('challengeForm').submit();
                    break;
                }
                solution++;

                // Optional: Kurze Pause alle X Iterationen, um UI-Thread nicht zu blockieren
                if (solution % 10000 === 0) {
                    await new Promise(resolve => setTimeout(resolve, 0));
                }
            }
        }

        // Start solving immediately
        solveChallenge().catch(error => {
            console.error("Challenge solver error:", error);
            document.getElementById('message').innerText = 'Fehler bei der Verifizierung. Bitte aktivieren Sie JavaScript und versuchen Sie es erneut.';
        });
    </script>
    </body>
    </html>
<?php
exit; // Wichtig: Beende die Skriptausführung hier, damit die eigentliche Seite nicht geladen wird.

?>