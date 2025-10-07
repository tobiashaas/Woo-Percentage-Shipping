# Woo Percentage Shipping

Modern shipping calculation plugin that computes shipping costs as a percentage of cart value with advanced filtering options.

## Requirements

- **WordPress:** 6.8 or higher
- **WooCommerce:** 10.0 or higher (automatically enforced by WordPress)
- **PHP:** 8.3 or higher
- **Database:** MySQL 8.0+ or MariaDB 10.6+

> **Note**: WordPress will prevent installation and activation of this plugin if WooCommerce is not installed or active, thanks to the `Requires Plugins` header.

## Key Features

- **Modern PHP Architecture**: Built with PHP 8.3+ features (enums, readonly properties, match expressions)
- **HPOS Compatible**: Full support for WooCommerce High-Performance Order Storage
- **Zero Dependencies**: No jQuery, no external libraries - pure vanilla JavaScript
- **Advanced Filtering**: Include/exclude digital products and specific categories
- **Real-time Preview**: Live calculation preview in admin interface
- **Performance Optimized**: Intelligent caching system with automatic invalidation
- **Comprehensive Logging**: Detailed debug logging with performance metrics
- **Security First**: CSRF protection, input validation, rate limiting, XSS protection

## Installation

1. Upload plugin files to `/wp-content/plugins/woo-percentage-shipping/`
2. Activate through **Plugins → Installed Plugins**
3. Configure settings at **WooCommerce → Percentage Shipping**
4. Add shipping method to your WooCommerce shipping zones

## Auto-Updates

This plugin supports automatic updates directly from GitHub releases:

### Automatic Updates (Recommended)
- **WordPress Dashboard**: Updates appear automatically in **Plugins → Installed Plugins**
- **One-Click Updates**: Update directly from the WordPress admin interface
- **Version Notifications**: Get notified when new versions are available

### Manual Updates
- **Download**: Get the latest release from GitHub
- **Upload**: Replace the plugin files or use WordPress admin upload
- **ZIP Installation**: Install via **Plugins → Add New → Upload Plugin**

> **Important**: When downloading from GitHub, the ZIP file contains a folder with the version number (e.g., `Woo-Percentage-Shipping-1.4.0`). After extracting, rename the folder to `woo-percentage-shipping` before uploading to WordPress.

### Update Methods
1. **GitHub Updater Plugin**: Install the [GitHub Updater](https://wordpress.org/plugins/github-updater/) plugin for enhanced GitHub integration
2. **Built-in Updater**: The plugin includes a built-in update checker that monitors GitHub releases
3. **Manual Installation**: Download and install releases manually as needed

## Configuration

### Basic Settings

- **Enable Plugin**: Toggle shipping calculation on/off
- **Shipping Percentage**: Percentage of cart value (0-100%)
- **Minimum Fee**: Lowest shipping cost regardless of percentage
- **Maximum Fee**: Highest shipping cost cap (0 = unlimited)

### Advanced Options

- **Include Digital Products**: Calculate shipping for virtual/downloadable items
- **Excluded Categories**: Remove specific product categories from calculation
- **Debug Mode**: Enable detailed logging for troubleshooting

## How It Works

1. **Cart Analysis**: Scans all products in customer cart
2. **Product Filtering**: Applies your inclusion/exclusion rules
3. **Percentage Calculation**: Computes shipping as percentage of filtered total
4. **Fee Limits**: Applies minimum/maximum constraints
5. **Shipping Rate**: Adds calculated rate to WooCommerce checkout

## Performance Features

- **Intelligent Caching**: Results cached for 1 hour with automatic invalidation
- **Rate Limiting**: AJAX requests limited to prevent abuse
- **Memory Optimization**: Efficient data structures and cleanup routines
- **Database Optimization**: Minimal queries with proper indexing

## Security Measures

- **CSRF Protection**: Nonce verification on all forms
- **Input Sanitization**: All user inputs validated and sanitized
- **XSS Prevention**: Proper output escaping throughout
- **Capability Checks**: Admin access restricted to authorized users
- **Rate Limiting**: AJAX requests throttled per user
- **Security Headers**: HTTP security headers implemented

## Debugging

Enable debug mode to access detailed logs:
- **Location**: WooCommerce → Status → Logs
- **Filter**: Select "wc-percentage-shipping" source
- **Information**: Calculation details, performance metrics, error tracking

## Development

### Architecture

- **Modular Design**: Separate classes for validation, calculation, caching, logging
- **Type Safety**: Full PHP 8.3 type declarations
- **Error Handling**: Comprehensive exception handling with graceful degradation
- **Testing**: PHPUnit test suite with 90%+ coverage

### Code Quality

- **PSR-12 Compliance**: Modern PHP coding standards
- **Documentation**: Comprehensive inline documentation
- **Performance**: Optimized for speed and memory usage
- **Maintainability**: Clean, readable, and extensible codebase

## Changelog

### 1.5.2
**Bug Fixes & Cleanup**
- **FIXED**: PHP fatal errors related to undefined methods
- **FIXED**: Removed all duplicate method definitions
- **FIXED**: Added all missing rendering methods
- **IMPROVED**: Clean code structure with no duplicate methods
- **IMPROVED**: Verified PHP syntax is correct
- **IMPROVED**: All admin interface tabs now working properly

### 1.5.5
**Enhanced Update Cache Clearing**
- **FIXED**: More aggressive cache clearing to prevent version inconsistencies
- **FIXED**: Clear all update-related transients including site transients
- **IMPROVED**: Force WordPress to re-check for updates with wp_update_plugins()
- **IMPROVED**: Clear plugin cache groups for complete refresh
- **IMPROVED**: Prevent showing old versions as available updates

### 1.5.4
**WordPress Update Cache Fix**
- **FIXED**: WordPress update cache now properly shows latest version
- **FIXED**: Enhanced cache clearing mechanism
- **IMPROVED**: Automatic cache clearing on plugin activation
- **IMPROVED**: More robust update detection system

### 1.5.3
**Code Cleanup & Branding**
- **REMOVED**: All Yoast references from code and documentation
- **IMPROVED**: Renamed to "Modern Vertical Sidebar Interface"
- **IMPROVED**: Clean, independent branding without external references
- **IMPROVED**: Updated all comments and documentation

### 1.5.1
**Modern Vertical Sidebar Interface**
- **NEW**: Complete admin interface redesign with modern vertical sidebar
- **NEW**: Search functionality with Ctrl+K shortcut
- **NEW**: Expandable navigation sections with clean organization
- **NEW**: WordPress form-table styling for professional appearance
- **IMPROVED**: Better user experience with smooth animations
- **IMPROVED**: Mobile-responsive design with collapsible sidebar
- **IMPROVED**: Keyboard shortcuts and accessibility enhancements

### 1.5.0
**Major Feature Update - All Advanced Features**
- **NEW**: Extended product filtering (tags, attributes, SKUs, stock status)
- **NEW**: Flexible calculation methods (per-product, tiered pricing, tax-inclusive)
- **NEW**: Advanced pricing options (free shipping threshold, flat rate addition, weekend surcharges)
- **NEW**: Customer group pricing for B2B scenarios
- **NEW**: Comprehensive analytics and performance metrics
- **NEW**: Settings backup and restore functionality
- **NEW**: Enhanced admin interface with 7 organized tabs
- **IMPROVED**: Better product filtering with multiple criteria
- **IMPROVED**: Professional pricing options for complex scenarios
- **IMPROVED**: Real-time statistics and performance monitoring

### 1.4.1
- **FIXED**: Updater class loading issue resolved
- **FIXED**: "Class not found" error on plugin activation
- **IMPROVED**: Auto-update functionality now works correctly

### 1.4.0
- **NEW**: Complete modern settings page redesign
- **NEW**: Tab-based interface (General, Advanced, Performance, Security)
- **NEW**: Live preview with real-time calculation updates
- **NEW**: Quick actions (Clear Cache, Export Settings, View Logs)
- **NEW**: Modern toggle switches and enhanced form controls
- **NEW**: Responsive design with mobile-first approach
- **NEW**: Enhanced accessibility with ARIA labels and keyboard shortcuts
- **NEW**: Sidebar widgets (Live Preview, System Info, Quick Links)
- **IMPROVED**: Better user experience with visual feedback and animations
- **IMPROVED**: Modern CSS Grid and Flexbox layout
- **IMPROVED**: Enhanced JavaScript with modern Fetch API

### 1.3.0
- **BREAKING**: Minimum PHP version raised to 8.3
- **BREAKING**: Minimum WordPress version raised to 6.8
- **BREAKING**: Minimum WooCommerce version raised to 10.0
- Enhanced HPOS compatibility with latest WooCommerce features
- Improved performance with advanced caching strategies
- Enhanced security with additional rate limiting and validation
- Modernized codebase with latest PHP 8.3 features
- Comprehensive error handling and logging system
- Zero-dependency architecture with vanilla JavaScript

### 1.2.0
- Added modular architecture with separate validator, calculator, and cache classes
- Implemented comprehensive testing framework
- Enhanced admin interface with real-time preview
- Added performance monitoring and logging
- Improved security with rate limiting and enhanced validation

## Support

- **Issues**: [GitHub Issues](https://github.com/tobiashaas/Woo-Percentage-Shipping/issues)
- **Documentation**: [Development Guide](docs/DEVELOPMENT.md)
- **Logs**: Check WooCommerce → Status → Logs for debugging

## License

GPL v2 or later - [Full License Text](LICENSE)
