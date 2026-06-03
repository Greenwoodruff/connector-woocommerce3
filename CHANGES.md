# Greenwoodruff Fork - Änderungsdokumentation

Dieses Dokument beschreibt alle Anpassungen, die im Fork `Greenwoodruff/connector-woocommerce3` gegenüber dem Original-Connector vorgenommen wurden.

**Basis:** JTL WooCommerce Connector Version 2.4.1
**Fork-Version:** 2.4.1.1 (4. Stelle = Fork-Revision)
**Fork erstellt:** Januar 2026

> **Versionierungskonvention:** Die Fork-Version ist immer `<upstream-version>.<fork-revision>`.
> Beispiel: JTL bringt 2.5.0 → nach dem Merge wird unsere Version `2.5.0.1`.
> Geändert wird in: `build-config.yaml` (Zeile 1) und `woo-jtl-connector.php` (Plugin-Header).

**WordPress Plugin-Ordner:** `woo-jtl-connector-camplorer` (fest, unabhängig von der Version)

---

## Einmaliger Wechsel vom Original-Plugin zu diesem Fork

Da der Plugin-Ordner von `woo-jtl-connector` auf `woo-jtl-connector-camplorer` wechselt, muss das alte Plugin einmalig ersetzt werden. **Wichtig:** Alle Einstellungen und Sync-Daten bleiben erhalten — solange das alte Plugin nicht über WordPress gelöscht wird.

### ⚠ Was beim Löschen über WordPress verloren geht

- **Deaktivieren:** löscht nur das Connector-Passwort (API-Token) — alles andere bleibt
- **Löschen (über WP-Admin):** löscht alle Einstellungen (`jtlconnector_*`) **und alle Link-Tabellen** (`jtl_connector_link_product`, `jtl_connector_link_order` etc.) — danach weiß JTL Wawi nicht mehr welche Produkte/Bestellungen schon synchronisiert sind → kompletter Neu-Abgleich nötig

### Sichere Vorgehensweise

1. Altes Plugin in WP-Admin **nur deaktivieren** — nicht auf "Löschen" klicken
2. Neuen ZIP (`woo-jtl-connector-camplorer-*.zip`) in WP-Admin hochladen und installieren
3. Neues Plugin aktivieren
4. Connector-Passwort (API-Token) neu eintragen — das ist das einzige was neu gesetzt werden muss
5. Alle anderen Einstellungen, Lieferzeiten und Konfigurationen sind automatisch wieder da

> Das alte (deaktivierte) Plugin kann dauerhaft im Plugins-Ordner stehen bleiben.
> Wer es trotzdem entfernen möchte: Ordner `woo-jtl-connector/` direkt per FTP oder SSH
> aus `wp-content/plugins/` löschen — dann läuft `uninstall.php` nicht und die Daten bleiben erhalten.

---

## ZIP erstellen

```bash
ROOT="<pfad-zum-repo>"
DIST="$ROOT/_dist_tmp/woo-jtl-connector-camplorer"
ZIP="$ROOT/woo-jtl-connector-camplorer-<version>.zip"

# 1. Dist-Ordner aufbauen
rm -rf "$DIST"
mkdir -p "$DIST/config" "$DIST/db" "$DIST/logs" "$DIST/plugins/jtl" "$DIST/tmp"
for f in index.php woo-jtl-connector.php uninstall.php LICENSE CHANGELOG.md readme.txt build-config.yaml composer.json composer.lock; do
    [ -f "$ROOT/$f" ] && cp "$ROOT/$f" "$DIST/$f"
done
for f in config.json features.json.example .htaccess; do
    [ -f "$ROOT/config/$f" ] && cp "$ROOT/config/$f" "$DIST/config/$f"
done
[ -f "$ROOT/db/.htaccess" ]   && cp "$ROOT/db/.htaccess"   "$DIST/db/.htaccess"
[ -f "$ROOT/logs/.htaccess" ] && cp "$ROOT/logs/.htaccess" "$DIST/logs/.htaccess"
cp -r "$ROOT/includes" "$DIST/includes"
cp -r "$ROOT/src"      "$DIST/src"
[ -d "$ROOT/plugins/jtl" ] && cp -r "$ROOT/plugins/jtl" "$DIST/plugins/jtl"

# 2. Production-Dependencies installieren (kein dev-Ballast)
cd "$DIST" && composer install --no-dev --no-interaction --ignore-platform-reqs

# 3. ZIP erstellen (woo-jtl-connector-camplorer/ als Top-Level-Ordner)
powershell.exe -NoProfile -Command "
  Add-Type -AssemblyName System.IO.Compression.FileSystem
  [System.IO.Compression.ZipFile]::CreateFromDirectory('$ROOT/_dist_tmp', '$ZIP')
"
```

> **Wichtig:** ZIP immer vom `_dist_tmp`-Ordner (Elternordner von `woo-jtl-connector-camplorer`) erstellen,
> damit WordPress beim Installieren `woo-jtl-connector-camplorer/` als Plugin-Ordner erkennt.

---

## Update-Strategie bei einer neuen JTL-Upstream-Version

```bash
git fetch upstream
git merge upstream/master
```

Git löst Merge-Konflikte automatisch; betroffene Dateien mit Konflikten anzeigen:

```bash
git diff --name-only --diff-filter=U
```

Alle Fork-Änderungen sind im Code mit Markern gekennzeichnet:
- Neue Blöcke: `// FORK ADDITION (PR #N):` … `// END FORK ADDITION`
- Fixes an bestehenden Zeilen: `// FORK FIX (PR #N):` … `// END FORK FIX`
- Entfernter Code (PR #1): kein Marker möglich — laut Tabelle unten und CHANGES.md manuell prüfen

Nach dem Merge alle Marker-Blöcke prüfen:

```bash
git diff upstream/master..HEAD -- src/Controllers/Product/ProductDeliveryTimeController.php
git diff upstream/master..HEAD -- src/Utilities/SqlTraits/CustomerOrderTrait.php
```

### Checkliste der zu prüfenden Dateien nach einem Upstream-Merge

| Datei | PR | Marker im Code |
|---|---|---|
| `src/Controllers/ProductController.php` | #1 | ⚠ kein Marker (Code wurde entfernt) |
| `src/Integrations/Plugins/PerfectWooCommerceBrands/PerfectWooCommerceBrands.php` | #2 | `FORK ADDITION (PR #2)` |
| `src/Utilities/SupportedPlugins.php` | #2 | `FORK ADDITION (PR #2)` |
| `woo-jtl-connector.php` | #3 | `FORK ADDITION (PR #3)` |
| `src/Controllers/Product/ProductManufacturerController.php` | #4 | `FORK ADDITION (PR #4)` |
| `src/Utilities/Config.php` | #5, #8 | `FORK ADDITION` |
| `includes/JtlConnectorAdmin.php` | #5, #8 | `FORK ADDITION` |
| `src/Controllers/Product/ProductDeliveryTimeController.php` | #5, #8 | `FORK ADDITION` |
| `src/Controllers/ManufacturerController.php` | #6 | `FORK ADDITION (PR #6)` |
| `src/Utilities/SqlTraits/CustomerOrderTrait.php` | #7 | `FORK FIX (PR #7)` |

**PR #1 (ACF) manuell prüfen:** In `src/Controllers/ProductController.php` darf kein Aufruf von `ProductAdvancedCustomFieldsController` stehen (weder im Pull- noch im Push-Abschnitt).

---

## Übersicht der Änderungen

| PR | Änderung | Commit |
|----|----------|--------|
| #1 | ACF-Synchronisation deaktiviert | `0f493e3` |
| #2 | Perfect WooCommerce Brands immer aktiv | `e26e96c` |
| #3 | Vendor/Autoload Fehlerbehandlung | `6594bfd` |
| #4 | PWB-Brand Sync Fix (Manufacturer Lookup) | `9a3ca54` |
| #5 | Individuelle Lieferzeit für Lagerware | `bd099bc` |
| #6 | Manufacturer Push TypeError-Fix | `593dd17` |
| #7 | Bestellungs-Sync Zeitzonenfehler Fix | `bbfb6e6` |
| #8 | "Erscheint am" als Lieferzeit in WooCommerce | aktuell |

---

## PR #1: ACF-Synchronisation deaktiviert

**Commit:** `0f493e33c5cc86984724998a55a35dca7b13e036`
**Zweck:** Die ACF (Advanced Custom Fields) Synchronisation wurde deaktiviert, da sie nicht benötigt wird.

> **⚠ Kein Code-Marker möglich:** Da Code entfernt wurde, gibt es keinen `FORK ADDITION`-Block im Quellcode.
> Nach einem Upstream-Merge prüfen mit:
> ```bash
> grep -n "ProductAdvancedCustomFields" src/Controllers/ProductController.php
> ```
> Das Ergebnis muss **leer** sein.

### Betroffene Datei
- `src/Controllers/ProductController.php`

### Änderungen im Detail

**Entfernter Import:**
```php
// ENTFERNT:
use JtlWooCommerceConnector\Controllers\Product\ProductAdvancedCustomFieldsController;
```

**Entfernter Code beim Pull (ca. Zeile 255):**
```php
// ENTFERNT:
if (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_ADVANCED_CUSTOM_FIELDS)) {
    (new ProductAdvancedCustomFieldsController($this->db, $this->util))->pullData($productModel, $product);
}
```

**Entfernter Code beim Push (ca. Zeile 407):**
```php
// ENTFERNT:
if (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_ADVANCED_CUSTOM_FIELDS)) {
    (new ProductAdvancedCustomFieldsController($this->db, $this->util))->pushData($model);
}
```

---

## PR #2: Perfect WooCommerce Brands immer aktiv

**Commit:** `e26e96c1a0e93dfc2ae27fdec7442d9e3fbaada9`
**Zweck:** Das Plugin geht nun davon aus, dass Perfect WooCommerce Brands immer installiert ist. Dadurch ist die `pwb-brand` Taxonomy immer verfügbar.

### Betroffene Dateien
1. `src/Integrations/Plugins/PerfectWooCommerceBrands/PerfectWooCommerceBrands.php`
2. `src/Utilities/SupportedPlugins.php`

### Änderungen im Detail

**PerfectWooCommerceBrands.php - Zeile 32:**
```php
// VORHER:
public function canBeUsed(): bool
{
    return SupportedPlugins::isPerfectWooCommerceBrandsActive();
}

// NACHHER:
public function canBeUsed(): bool
{
    return true;
}
```

**SupportedPlugins.php - Zeile 218:**
```php
// VORHER:
public static function isPerfectWooCommerceBrandsActive(): bool
{
    return (
        self::isActive(self::PLUGIN_PERFECT_WOO_BRANDS) ||
        self::isActive(self::PLUGIN_PERFECT_BRANDS_FOR_WOOCOMMERCE) ||
        self::isActive(self::PLUGIN_PERFECT_BRANDS_WOOCOMMERCE)
    );
}

// NACHHER:
public static function isPerfectWooCommerceBrandsActive(): bool
{
    return true;
}
```

---

## PR #3: Vendor/Autoload Fehlerbehandlung

**Commit:** `6594bfd2416e0d8b95e1d04f91d55214b2be5974`
**Zweck:** Verhindert einen Fatal Error, wenn das Plugin direkt von GitHub installiert wird und die `vendor/autoload.php` fehlt. Stattdessen wird eine hilfreiche Admin-Notice angezeigt.

### Betroffene Datei
- `woo-jtl-connector.php`

### Änderungen im Detail

**Am Anfang der Datei (nach den Includes):**
```php
// NEU HINZUGEFÜGT:
$jtlwcc_autoload_missing = false;
```

**Beim Laden des Autoloaders:**
```php
// VORHER:
} else {
    $loader = require(JTLWCC_CONNECTOR_DIR . '/vendor/autoload.php');
    ...
}

// NACHHER:
} elseif (file_exists(JTLWCC_CONNECTOR_DIR . '/vendor/autoload.php')) {
    $loader = require(JTLWCC_CONNECTOR_DIR . '/vendor/autoload.php');
    $loader->add('', JTLWCC_CONNECTOR_DIR . '/plugins');
    if (is_dir(JTLWCC_EXT_CONNECTOR_PLUGIN_DIR)) {
        $loader->add('', JTLWCC_EXT_CONNECTOR_PLUGIN_DIR);
    }
} else {
    $jtlwcc_autoload_missing = true;
}
```

**Nach dem try-catch Block:**
```php
// NEU HINZUGEFÜGT:
if ($jtlwcc_autoload_missing) {
    add_action('admin_notices', 'jtlwcc_vendor_missing_notice');
    return;
}

/**
 * Show admin notice when vendor/autoload.php is missing.
 *
 * @return void
 */
function jtlwcc_vendor_missing_notice(): void
{
    echo '<div class="error"><h3>JTL-Connector</h3>';
    echo '<p><strong>Fehler:</strong> Die Datei <code>vendor/autoload.php</code> fehlt.</p>';
    echo '<p>Wenn Sie das Plugin direkt von GitHub installiert haben, müssen Sie zuerst ';
    echo '<code>composer install</code> im Plugin-Verzeichnis ausführen.</p>';
    echo '<p>Alternativ können Sie die offizielle Version von ';
    echo '<a href="https://www.jtl-software.de" target="_blank">JTL-Software</a> herunterladen.</p>';
    echo '</div>';
}
```

---

## PR #4: PWB-Brand Sync Fix (Manufacturer Lookup)

**Commit:** `9a3ca5495d4d82565b54137e4437494a61c36c16`
**Zweck:** Behebt ein Problem, bei dem der Hersteller nicht synchronisiert wurde, obwohl er bereits in der Link-Tabelle vorhanden war. Die Endpoint-ID wird nun aus der `jtl_connector_link_manufacturer` Tabelle nachgeschlagen.

### Betroffene Datei
- `src/Controllers/Product/ProductManufacturerController.php`

### Änderungen im Detail

**In der `pushData()` Methode (nach Zeile 23):**
```php
// NEU HINZUGEFÜGT - nach Abrufen der manufacturerId:
// If endpoint ID is empty, try to look it up from the link table using host ID
if ($manufacturerId === '') {
    $hostId = $product->getManufacturerId()->getHost();
    if ($hostId > 0) {
        $manufacturerId = $this->getManufacturerEndpointId($hostId);
    }
}
```

**Neue private Methode hinzugefügt:**
```php
/**
 * Look up the manufacturer endpoint ID from the link table using the host ID.
 *
 * @param int $hostId
 * @return string
 */
private function getManufacturerEndpointId(int $hostId): string
{
    global $wpdb;
    $tableName = $wpdb->prefix . 'jtl_connector_link_manufacturer';

    $endpointId = $this->db->queryOne(
        "SELECT endpoint_id FROM {$tableName} WHERE host_id = {$hostId}"
    );

    return $endpointId !== null ? (string)$endpointId : '';
}
```

---

## PR #5: Individuelle Lieferzeit für Lagerware

**Commit:** `bd099bcc414acd3d8c9dd80cb9f1337c8e47619b`
**Zweck:** Fügt eine neue Option hinzu, mit der eine individuelle Lieferzeit für Produkte mit Lagerbestand (stock > 0) definiert werden kann, z.B. "im Camplorer Lager" oder "sofort lieferbar".

### Betroffene Dateien
1. `src/Utilities/Config.php`
2. `includes/JtlConnectorAdmin.php`
3. `src/Controllers/Product/ProductDeliveryTimeController.php`

### Änderungen im Detail

**Config.php - Neue Konstante (Zeile 44):**
```php
// NEU HINZUGEFÜGT:
OPTIONS_IN_STOCK_DELIVERY_TIME = 'jtlconnector_in_stock_delivery_time',
```

**Config.php - Default-Wert (Zeile 66):**
```php
// NEU HINZUGEFÜGT:
Config::OPTIONS_IN_STOCK_DELIVERY_TIME => '',
```

**Config.php - Typ-Definition (Zeile 109):**
```php
// NEU HINZUGEFÜGT:
Config::OPTIONS_IN_STOCK_DELIVERY_TIME => 'string',
```

**JtlConnectorAdmin.php - Neues Eingabefeld (nach Zeile 1315):**
```php
// NEU HINZUGEFÜGT:
//Add in-stock delivery time textinput field
$fields[] = [
    'title'     => __('Delivery time for in-stock products', JTLWCC_TEXT_DOMAIN),
    'type'      => 'jtl_text_input',
    'id'        => Config::OPTIONS_IN_STOCK_DELIVERY_TIME,
    'value'     => Config::get(Config::OPTIONS_IN_STOCK_DELIVERY_TIME),
    'helpBlock' => __(
        "Define a custom delivery time text for products that are in stock (stock > 0)." . PHP_EOL .
        "Example: 'im Camplorer Lager' or 'sofort lieferbar'." . PHP_EOL .
        "Leave empty to use the calculated delivery time.",
        JTLWCC_TEXT_DOMAIN
    ),
];
```

**ProductDeliveryTimeController.php - Logik für individuelle Lieferzeit:**
```php
// NEU HINZUGEFÜGT (nach Zeile 32):
//Check if product is in stock and custom in-stock delivery time is configured
/** @var string $inStockDeliveryTime */
$inStockDeliveryTime = Config::get(Config::OPTIONS_IN_STOCK_DELIVERY_TIME, '');
$useInStockDeliveryTime = $product->getStockLevel() > 0 && !empty(\trim($inStockDeliveryTime));

// GEÄNDERT (Zeile 66) - Offset nur anwenden wenn NICHT in-stock:
if ($offset !== 0 && !$useInStockDeliveryTime) {
    // ... bestehender Code
}

// GEÄNDERT (Zeile 76) - Zero-Check nur wenn NICHT in-stock:
if (
    $time === 0
    && Config::get(Config::OPTIONS_DISABLED_ZERO_DELIVERY_TIME)
    && Config::get(Config::OPTIONS_USE_DELIVERYTIME_CALC) === 'delivery_time_calc'
    && !$useInStockDeliveryTime
) {
    return;
}

// GEÄNDERT (Zeile 91) - Lieferzeit-String Erstellung:
//Build Term string - use custom in-stock delivery time if configured and product is in stock
if ($useInStockDeliveryTime) {
    $deliveryTimeString = \trim($inStockDeliveryTime);
} else {
    $deliveryTimeString = \trim(
        \sprintf(
            '%s %s %s',
            $prefixDeliveryTime,
            $time,
            $suffixDeliveryTime
        )
    );
}

// GEÄNDERT (Zeile 103) - delivery_status nur wenn NICHT in-stock:
if (
    !$useInStockDeliveryTime
    && (Config::get(Config::OPTIONS_USE_DELIVERYTIME_CALC) === 'delivery_status')
    // ... rest der Bedingung
) {
```

---

## PR #6: Manufacturer Push TypeError-Fix

**Commit:** `593dd17`
**Zweck:** Behebt einen Fehler, bei dem der Push eines Herstellers fehlschlug, wenn ein Drittanbieter-Plugin-Hook einen `TypeError` auslöste. Die Exception wird nun abgefangen und geloggt, statt den gesamten Sync-Vorgang abzubrechen.

### Betroffene Datei
- `src/Controllers/ProductManufacturerController.php` (oder verwandte Datei)

### Ursache
Drittanbieter-Plugins können WordPress-Hooks abfeuern, die unerwartete Datentypen zurückliefern. Das führte zu einem `TypeError`, der den gesamten Push-Prozess unterbrach.

### Lösung
`TypeError`-Exceptions werden nun im Manufacturer-Push-Vorgang abgefangen und als Warnung geloggt, statt den Sync abzubrechen.

---

## PR #7: Bestellungs-Sync Zeitzonenfehler Fix

**Commit:** aktuell (März 2026)
**Zweck:** Behebt einen Fehler, durch den neue Bestellungen erst nach 1 Stunde (Winterzeit) bzw. 2 Stunden (Sommerzeit) von JTL-Wawi synchronisiert wurden.

### Betroffene Datei
- `src/Utilities/SqlTraits/CustomerOrderTrait.php`

### Ursache
WordPress speichert `post_date` in der **lokalen Zeitzone** des Servers (z.B. MEZ = UTC+1, MESZ = UTC+2), während MySQL `NOW()` in **UTC** zurückgibt. Die SQL-Abfrage verglich diese beiden unterschiedlichen Zeitzonen:

```sql
-- PROBLEM: post_date ist lokale Zeit, NOW() ist UTC
AND p.post_date < DATE_SUB(NOW(), INTERVAL 60 SECOND)
```

Dadurch erschienen neue Bestellungen für MySQL bis zu 2 Stunden "in der Zukunft" und wurden erst nach Ablauf dieser Differenz als abrufbar erkannt.

### Änderung im Detail

**Datei:** `src/Utilities/SqlTraits/CustomerOrderTrait.php`, Zeile 38

```php
// VORHER:
$dateColumn = $hposEnabled ? 'date_created_gmt' : 'post_date';

// NACHHER:
$dateColumn = $hposEnabled ? 'date_created_gmt' : 'post_date_gmt';
```

`post_date_gmt` enthält die Bestellzeit in UTC — damit vergleichen beide Seiten der SQL-Bedingung UTC-Zeiten, unabhängig von Sommer- oder Winterzeit.

### Hinweis bei neuer Connector-Version
Bei einem Update auf eine neue Hersteller-Version muss diese Zeile erneut angepasst werden. Die Stelle ist leicht zu finden:
```bash
grep -n "post_date" src/Utilities/SqlTraits/CustomerOrderTrait.php
```

---

## Wiederherstellung der Änderungen

Falls der Fork verloren geht, können die Änderungen wie folgt wiederhergestellt werden:

### Schritt 1: Original-Connector klonen
```bash
git clone https://github.com/jtl-software/connector-woocommerce3.git
cd connector-woocommerce3
```

### Schritt 2: Änderungen manuell anwenden
Die oben dokumentierten Code-Änderungen können manuell in die entsprechenden Dateien eingefügt werden.

### Zusammenfassung der zu ändernden Dateien:
1. `src/Controllers/ProductController.php` - ACF-Aufrufe entfernen
2. `src/Integrations/Plugins/PerfectWooCommerceBrands/PerfectWooCommerceBrands.php` - `return true;`
3. `src/Utilities/SupportedPlugins.php` - `isPerfectWooCommerceBrandsActive()` auf `return true;`
4. `woo-jtl-connector.php` - Autoload-Fehlerbehandlung hinzufügen
5. `src/Controllers/Product/ProductManufacturerController.php` - Manufacturer-Lookup Methode + TypeError-Fix
6. `src/Utilities/Config.php` - Neue Option hinzufügen
7. `includes/JtlConnectorAdmin.php` - Admin-Eingabefeld hinzufügen
8. `src/Controllers/Product/ProductDeliveryTimeController.php` - In-Stock-Lieferzeit-Logik
9. `src/Utilities/SqlTraits/CustomerOrderTrait.php` - `post_date` → `post_date_gmt` (Zeile 38)
10. `src/Utilities/Config.php` - Neue Optionen für "Erscheint am"
11. `includes/JtlConnectorAdmin.php` - Admin-Felder für "Erscheint am"
12. `src/Controllers/Product/ProductDeliveryTimeController.php` - "Erscheint am"-Logik

---

## PR #8: "Erscheint am" (Lageroptionen) als Lieferzeit in WooCommerce

**Commit:** aktuell
**Zweck:** Ermöglicht es, das JTL-Feld "Erscheint am" aus den Lageroptionen als datumbasierte Lieferzeit in WooCommerce anzuzeigen (z.B. "Lieferbar ab 15.07.2025"), wenn ein Artikel nicht auf Lager ist.

### Hintergrund

In JTL Wawi kann im Tab "Lageroptionen" eines Artikels ein "Erscheint am"-Datum gesetzt werden. Dieses Datum (`availableFrom` im Connector-Modell) wird bisher nur für das WordPress-Veröffentlichungsdatum (`post_date`) genutzt. Bei nicht-vorrätigen Artikeln soll es stattdessen als lesbarer Lieferzeittext erscheinen.

Bisher: Lieferzeit bei Lager=0 → Anzahl Tage (aus Lieferantenbestellung oder Tage-bis-Versand)
Neu: Optional → "Lieferbar ab 15.07.2025" direkt aus dem JTL-Feld

### Betroffene Dateien
1. `src/Utilities/Config.php`
2. `includes/JtlConnectorAdmin.php`
3. `src/Controllers/Product/ProductDeliveryTimeController.php`

### Änderungen im Detail

**Config.php – Neue Konstanten (nach `OPTIONS_IN_STOCK_DELIVERY_TIME`):**
```php
// FORK ADDITION: "Erscheint am" from JTL stock options as delivery time string
OPTIONS_CONSIDER_ERSCHEINT_AM_DATE = 'jtlconnector_consider_erscheint_am_date',
OPTIONS_ERSCHEINT_AM_PREFIX        = 'jtlconnector_erscheint_am_prefix',
// END FORK ADDITION
```

**Config.php – Default-Werte (in `JTLWCC_CONFIG_DEFAULTS`):**
```php
// FORK ADDITION:
Config::OPTIONS_CONSIDER_ERSCHEINT_AM_DATE => false,
Config::OPTIONS_ERSCHEINT_AM_PREFIX        => 'Lieferbar ab',
// END FORK ADDITION
```

**Config.php – Typen (in `JTLWCC_CONFIG`):**
```php
// FORK ADDITION:
Config::OPTIONS_CONSIDER_ERSCHEINT_AM_DATE => 'bool',
Config::OPTIONS_ERSCHEINT_AM_PREFIX        => 'string',
// END FORK ADDITION
```

**JtlConnectorAdmin.php – Neue Admin-Felder (nach dem `OPTIONS_CONSIDER_SUPPLIER_INFLOW_DATE`-Block, vor `sectionend`):**
```php
// FORK ADDITION: "Erscheint am" from JTL stock options as delivery time string
$fields[] = [
    'title'     => __('Consider "Erscheint am" date as delivery time', JTLWCC_TEXT_DOMAIN),
    'type'      => 'active_true_false_radio',
    'desc'      => ...
    'id'        => Config::OPTIONS_CONSIDER_ERSCHEINT_AM_DATE,
    ...
];

$fields[] = [
    'title'     => __('Prefix for "Erscheint am" delivery time', JTLWCC_TEXT_DOMAIN),
    'type'      => 'jtl_text_input',
    'id'        => Config::OPTIONS_ERSCHEINT_AM_PREFIX,
    ...
];
// END FORK ADDITION
```

**ProductDeliveryTimeController.php – Neue Logik in `pushData()` (vor dem `get_term_by`-Aufruf):**
```php
// FORK ADDITION: "Erscheint am" from JTL stock options as delivery time string
// Overrides $deliveryTimeString when stock is 0 and availableFrom lies in the future.
// Priority: lower than in-stock time, higher than day-count string.
// Re-apply this block after upstream merges in pushData().
if (
    !$useInStockDeliveryTime
    && Config::get(Config::OPTIONS_CONSIDER_ERSCHEINT_AM_DATE, false)
    && $product->getStockLevel() <= 0
    && !\is_null($product->getAvailableFrom())
) {
    $erscheintAm = new \DateTime($product->getAvailableFrom()->format('Y-m-d'));
    $today       = new \DateTime((new \DateTime())->format('Y-m-d'));
    if ($erscheintAm->getTimestamp() > $today->getTimestamp()) {
        /** @var string $erscheintAmPrefix */
        $erscheintAmPrefix  = Config::get(Config::OPTIONS_ERSCHEINT_AM_PREFIX, 'Lieferbar ab');
        $deliveryTimeString = \trim($erscheintAmPrefix . ' ' . $erscheintAm->format('d.m.Y'));
    }
}
// END FORK ADDITION
```

### Priorität der Lieferzeitbestimmung (nach dem Change)

1. Artikel auf Lager (stock > 0) + Custom In-Stock-Text konfiguriert → Custom-Text
2. Artikel auf Lager → normale Tage-Berechnung
3. Artikel **nicht** auf Lager + "Erscheint am" in Zukunft + Option aktiv → **"Lieferbar ab TT.MM.YYYY"**
4. Artikel nicht auf Lager + Lieferantenzugang bekannt → Tage-Berechnung aus Lieferantenbestellung
5. Sonst → Standardberechnung (Tage-bis-Versand)

### Nach einem JTL-Upstream-Update
Alle Fork-Blöcke sind mit `// FORK ADDITION:` und `// END FORK ADDITION` markiert. Nach einem Merge:
```bash
git diff upstream/master..HEAD -- src/Controllers/Product/ProductDeliveryTimeController.php
```
zeigt genau die Blöcke, die ggf. neu angewendet werden müssen.

---

## Kontakt

Bei Fragen zu diesen Änderungen kann die Git-Historie des Forks konsultiert werden:
```bash
git log --oneline
```

Jeder Commit enthält eine ausführliche Beschreibung der vorgenommen Änderungen.
