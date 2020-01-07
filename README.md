# BigWing ElasticPress Integration
Contributors: BigWing, morganestes  
Tags: elasticpress, elasticsearch, rest api  
Requires at least: 4.7  
Tested up to: 5.3  
Requires PHP: 7.0 
License: GPLv2 or later  
License URI: http://www.gnu.org/licenses/gpl-2.0.html 

Integrate and extend BigWing's Elasticsearch service using the ElasticPress plugin.

## Description
Use ElasticPress features with BigWing's custom Elasticsearch setup, including authenticated requests and hardened Autosuggest.

## Installation
1. Install, activate, and configure the ElasticPress plugin from 10up (https://wordpress.org/plugins/elasticpress/).
1. Install and activate this plugin using your preferred method.
1. Add your Elasticsearch credentials to `wp-config.php` using the `ES_SHIELD` PHP constant (e.g. `define( 'ES_SHIELD', '{username}:{password}' );`).
1. Enable the Autosuggest feature in ElasticPress.

## Changelog
### 0.5.0
* Initial development release.
* Adds an internal Autosuggest endpoint to the WP REST API.
