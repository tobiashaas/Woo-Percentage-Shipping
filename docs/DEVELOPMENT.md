# WooCommerce Percentage Shipping - Development Guide

## 🏗️ **Architecture Overview**

The plugin follows a modular architecture with clear separation of concerns:

```
woocommerce-percentage-shipping.php    # Main plugin file
├── includes/
│   ├── class-wc-percentage-shipping-method.php      # WooCommerce Shipping Method
│   ├── class-wc-percentage-shipping-calculator.php  # Calculation logic
│   ├── class-wc-percentage-shipping-validator.php   # Input validation
│   ├── class-wc-percentage-shipping-cache.php       # Caching system
│   └── class-wc-percentage-shipping-logger.php      # Logging system
├── assets/
│   ├── admin.css     # Admin styles
│   └── admin.js      # Admin JavaScript (Vanilla JS)
├── languages/        # Translation files
└── tests/           # Test suite
```

## 🔧 **Development Environment Setup**

### Requirements
- PHP 8.3+
- WordPress 6.8+
- WooCommerce 10.0+
- MySQL 8.0+ or MariaDB 10.6+
- Composer (for dependencies)

### Installation
```bash
# Clone repository
git clone https://github.com/tobiashaas/Woo-Percentage-Shipping.git
cd Woo-Percentage-Shipping

# Install dependencies
composer install

# Run tests
composer test
```

## 🧪 **Testing**

### Test-Suite ausführen
```bash
# Alle Tests
composer test

# Mit Coverage-Report
composer test-coverage

# Tests im Watch-Modus
composer test-watch
```

### Test-Kategorien
- **Unit Tests**: Einzelne Klassen und Methoden
- **Integration Tests**: Plugin-Integration mit WooCommerce
- **Performance Tests**: Caching und Performance-Metriken

## 📊 **Performance-Optimierungen**

### Caching-System
Das Plugin implementiert ein intelligentes Caching-System:

```php
// Cache-Schlüssel generieren
$cache_key = WC_Percentage_Shipping_Cache::generate_cache_key($package, $options);

// Cache abrufen
$cached_cost = WC_Percentage_Shipping_Cache::get($cache_key);

// Cache setzen
WC_Percentage_Shipping_Cache::set($cache_key, $result, 3600);
```

### Performance-Metriken
- **Cache-Hit-Rate**: Überwachung der Cache-Effektivität
- **Execution-Time**: Messung der Berechnungszeit
- **Memory-Usage**: Überwachung des Speicherverbrauchs

## 📝 **Logging-System**

### Log-Levels
- **DEBUG**: Detaillierte Debug-Informationen
- **INFO**: Allgemeine Informationen
- **WARNING**: Warnungen und Performance-Probleme
- **ERROR**: Fehler und Exceptions

### Logging verwenden
```php
// Debug-Log
WC_Percentage_Shipping_Logger::debug('Calculation started', $context);

// Info-Log
WC_Percentage_Shipping_Logger::info('Settings updated');

// Warning-Log
WC_Percentage_Shipping_Logger::warning('Slow calculation detected', $metrics);

// Error-Log
WC_Percentage_Shipping_Logger::error('Calculation failed', $error_context);
```

## 🔒 **Sicherheits-Features**

### Implementierte Sicherheitsmaßnahmen
- **CSRF-Protection**: Nonce-Verification für alle Formulare
- **Input-Sanitization**: Validierung und Sanitization aller Eingaben
- **XSS-Protection**: Proper Output Escaping
- **Rate-Limiting**: Schutz vor DoS-Angriffen
- **Capability-Checks**: Berechtigungsprüfungen

### Rate-Limiting
```php
// AJAX-Requests limitieren
private function check_rate_limit(): bool
{
    $user_id = get_current_user_id();
    $key = 'wc_percentage_shipping_rate_limit_' . $user_id;
    $requests = get_transient($key) ?: 0;
    
    if ($requests >= (int) PluginSecurity::AJAX_RATE_LIMIT->value) {
        return false;
    }
    
    set_transient($key, $requests + 1, 60);
    return true;
}
```

## 🎨 **Frontend-Entwicklung**

### JavaScript-Architektur
Das Plugin verwendet moderne Vanilla JavaScript ohne jQuery:

```javascript
// Tooltip-System
class VanillaTooltip {
    constructor() {
        this.init();
    }
    
    showTooltip(event) {
        // Tooltip-Logik
    }
}

// Live-Preview-System
class LivePreview {
    updatePreview() {
        // Real-time Updates
    }
}
```

### CSS-Organisation
- **Mobile-First**: Responsive Design
- **CSS-Grid**: Moderne Layout-Techniken
- **CSS-Variables**: Konsistente Farben und Abstände
- **Accessibility**: ARIA-Labels und Keyboard-Navigation

## 🔄 **Code-Qualität**

### Coding-Standards
- **PSR-12**: PHP Coding Standards
- **Strict Types**: `declare(strict_types=1)`
- **Type Hints**: Vollständige Typisierung
- **DocBlocks**: Umfassende Dokumentation

### Code-Review-Checkliste
- [ ] PHP 8+ Features verwendet (Enums, Union Types, etc.)
- [ ] Proper Error Handling implementiert
- [ ] Security-Checks vorhanden
- [ ] Performance-Optimierungen berücksichtigt
- [ ] Tests geschrieben/aktualisiert
- [ ] Dokumentation aktualisiert

## 🚀 **Deployment**

### Version-Management
```php
// Plugin-Version in mehreren Stellen synchronisieren:
// 1. Plugin-Header
// 2. PluginConfig::VERSION
// 3. README.md
// 4. Changelog
```

### Release-Prozess
1. **Tests ausführen**: `composer test`
2. **Code-Quality prüfen**: PHPStan, PHPCS
3. **Version aktualisieren**: Alle Version-Referenzen
4. **Changelog aktualisieren**: README.md
5. **Tag erstellen**: `git tag v1.2.1`
6. **Release erstellen**: GitHub Release

## 🐛 **Debugging**

### Debug-Modus aktivieren
```php
// In den Plugin-Einstellungen
'debug_mode' => 'yes'
```

### Logs einsehen
- **WooCommerce → Status → Logs**
- **Filter**: `wc-percentage-shipping`
- **Log-Level**: Debug für detaillierte Informationen

### Häufige Probleme
1. **Cache-Probleme**: Cache löschen bei Einstellungsänderungen
2. **Performance-Issues**: Logs auf langsame Berechnungen prüfen
3. **Security-Warnings**: Rate-Limiting und Nonce-Verification prüfen

## 📚 **API-Referenz**

### Hauptklassen
- `WC_Percentage_Shipping_Plugin`: Haupt-Plugin-Klasse
- `WC_Percentage_Shipping_Method`: WooCommerce Shipping Method
- `WC_Percentage_Shipping_Calculator`: Berechnungslogik
- `WC_Percentage_Shipping_Validator`: Input-Validierung
- `WC_Percentage_Shipping_Cache`: Caching-System
- `WC_Percentage_Shipping_Logger`: Logging-System

### Hooks und Filter
```php
// Plugin-Hooks
add_action('woocommerce_shipping_init', 'include_shipping_method');
add_filter('woocommerce_shipping_methods', 'register_shipping_method');

// Cache-Hooks
add_action('woocommerce_settings_saved', 'clear_cache_on_settings_save');

// Cleanup-Hooks
add_action('wp_scheduled_delete', 'cleanup_old_logs');
```

## 🤝 **Contributing**

### Pull-Request-Prozess
1. **Fork** des Repositories
2. **Feature-Branch** erstellen
3. **Tests schreiben** für neue Features
4. **Code-Quality** sicherstellen
5. **Pull-Request** erstellen mit detaillierter Beschreibung

### Code-Review-Kriterien
- Funktionalität und Tests
- Sicherheit und Performance
- Code-Qualität und Standards
- Dokumentation und Kommentare
- Backward-Compatibility

## 📞 **Support**

Bei Fragen oder Problemen:
- **Issues**: GitHub Issues für Bug-Reports
- **Discussions**: GitHub Discussions für Fragen
- **Documentation**: Diese Entwicklungsdokumentation
- **Logs**: WooCommerce Logs für Debugging
