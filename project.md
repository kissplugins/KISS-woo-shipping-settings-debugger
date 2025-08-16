# Critical Security & Performance Issues Report

## Priority 1 (CRITICAL - Immediate Action Required)

### 1.1 Path Traversal Vulnerability in Scanner — Status: Fixed
**Location:** `scanner-trait.php`, lines 24-33
**Issue:** While there is a `realpath()` check, the path validation is insufficient. The code only checks if the path starts with the base directory but doesn't prevent symlink attacks or relative path manipulation.
```php
if ( strncmp( $real_norm, $base_norm, strlen( $base_norm ) ) === 0 && !in_array($real, $files_to_scan) ) {
    $files_to_scan[] = $real;
}
```
**Risk:** An attacker with admin access could potentially read sensitive files outside the intended directory through symlinks.
**Fix:** Implement stricter path validation and disable following symlinks.

## Priority 2 (HIGH - Address Soon)

### 2.1 Unescaped Dynamic Content in Admin Output — Status: Fixed
**Location:** `scanner-trait.php`, line 187
**Issue:** The `wp_kses_post()` function is used for scanner output which may contain user-controlled data from scanned files:
```php
printf('<li><strong>%s</strong> — %s %s</li>',
    esc_html( $this->short_explanation_label( $finding['key'] ) ),
    wp_kses_post( $desc ),  // Potential XSS if $desc contains malicious HTML
    ...
);
```
**Risk:** If scanned PHP files contain malicious strings that get parsed and displayed, they could execute JavaScript in the admin context.
**Fix:** Use `esc_html()` instead of `wp_kses_post()` for dynamic content from scanned files.

### 2.2 CSV Injection Vulnerability — Status: Fixed
**Location:** `kiss-woo-shipping-settings-debugger.php`, handle_export() method
**Issue:** The CSV export doesn't sanitize data that could contain formula injection attacks.
**Risk:** Exported CSVs could contain malicious formulas (=cmd|'/c calc'!A1) that execute when opened in spreadsheet applications.
**Fix:** Prefix cells starting with =, +, -, or @ with a single quote.

## Priority 3 (MEDIUM-HIGH)

### 3.1 Unbounded File Parsing with No Memory Limits — Status: Fixed
**Location:** `scanner-trait.php`, scan_and_render_custom_rules() method
**Issue:** The AST parser reads entire files into memory without size checks:
```php
$code = file_get_contents( $file );
$ast = $parser->parse( $code );
```
**Risk:** Large or maliciously crafted PHP files could cause memory exhaustion.
**Fix:** Add file size limits and memory usage monitoring.

### 3.2 Missing Rate Limiting on Export Function — Status: Fixed
**Location:** `kiss-woo-shipping-settings-debugger.php`, handle_export() method
**Issue:** No rate limiting on CSV export functionality.
**Risk:** Repeated export requests could cause database strain and resource exhaustion.
**Fix:** Implement rate limiting using transients.

## Priority 4 (MEDIUM)

### 4.1 Potential ReDoS in Regex Patterns — Status: Fixed
**Location:** `scanner-trait.php`, format_error_message() method, lines 225-242
**Issue:** Complex regex patterns without anchors could be vulnerable to ReDoS:
```php
$message = preg_replace(
    '/(\b[\w-]+(?:\s[\w-]+)?)\s+(products)\b/i',
    '<strong>$1</strong> $2',
    $message
);
```
**Risk:** Specially crafted input could cause CPU exhaustion.
**Fix:** Add timeouts and simplify regex patterns.

### 4.2 Database Query Performance Issues
**Location:** `preview-trait.php`, collect_zone_rows() method
**Issue:** Queries all zones and methods without pagination or caching:
```php
$zone_rows = \WC_Shipping_Zones::get_zones();
foreach ( $zone_ids as $zone_id ) {
    $zone = new \WC_Shipping_Zone( (int) $zone_id );
    $methods = $zone->get_shipping_methods();
}
```
**Risk:** Stores with hundreds of zones could experience slow page loads.
**Fix:** Implement proper pagination and caching strategy.

## Priority 5 (LOW-MEDIUM)

### 5.1 Weak Nonce Implementation
**Location:** Multiple AJAX handlers in `self-test.php`
**Issue:** Nonces are created inline in JavaScript without proper rotation:
```javascript
nonce: '<?php echo esc_js( wp_create_nonce( 'kiss_wse_ajax_nonce' ) ); ?>'
```
**Risk:** Fixed nonces in JavaScript are more vulnerable to CSRF attacks.
**Fix:** Use wp_localize_script() for nonce handling.

### 5.2 Information Disclosure in Error Messages
**Location:** Throughout the codebase
**Issue:** Detailed error messages expose file paths and system information.
**Risk:** Could aid attackers in reconnaissance.
**Fix:** Log detailed errors server-side, show generic messages to users.

## Priority 6 (LOW)

### 6.1 Missing Object Caching
**Location:** `kiss-woo-shipping-settings-debugger.php`
**Issue:** No use of WordPress object caching for expensive operations.
**Risk:** Repeated operations cause unnecessary database hits.
**Fix:** Implement wp_cache_* functions for frequently accessed data.

### 6.2 Inefficient String Concatenation
**Location:** Multiple locations in scanner-trait.php
**Issue:** Heavy use of string concatenation in loops without StringBuilder pattern.
**Risk:** Minor performance degradation with large datasets.
**Fix:** Use array joining for large string operations.

## Recommendations

1. **Immediate Actions (Priority 1-2):**
   - Fix path traversal vulnerability immediately
   - Sanitize all dynamic content properly
   - Add CSV injection protection

2. **Short-term (Priority 3-4):**
   - Implement file size limits
   - Add rate limiting
   - Optimize database queries

3. **Long-term (Priority 5-6):**
   - Improve nonce handling
   - Implement comprehensive caching
   - Optimize string operations

## Security Headers to Add
```php
// Add to export handler
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: default-src \'self\'');
```

## Testing Recommendations
- Perform security audit with RIPS or similar PHP security scanner
- Load test with 1000+ shipping zones
- Test with malformed/malicious PHP files in scanner
- Verify CSV export with formula injection payloads