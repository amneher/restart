<?php

/**
 * Affiliate Link Converter
 *
 * Converts regular product URLs to affiliate links
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry_Affiliate_Converter
{

    private static ?self $instance = null;

    private $affiliate_configs;

    public function __construct()
    {
        $this->affiliate_configs = $this->get_affiliate_configs();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            // Wrap in lambdas so non-string filter values degrade gracefully
            // instead of throwing TypeError on typed parameters.
            add_filter(
                'restart_affiliate_url', function ($v): string {
                    return is_string($v) ? self::$instance->convert_url_string($v) : (string) $v;
                }
            );
            add_filter(
                'restart_affiliate_content', function ($v): string {
                    return is_string($v) ? self::$instance->convert_content($v) : (string) $v;
                }
            );
        }
        return self::$instance;
    }

    /**
     * Convert all <a href> values in an HTML fragment through the affiliate converter.
     * Uses DOMDocument rather than regex so attribute parsing is handled correctly.
     */
    public function convert_content(string $html): string
    {
        if (empty($html) || !str_contains($html, '<a')) {
            return $html;
        }

        $doc      = new DOMDocument();
        $prev_err = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<html><body>' . $html . '</body></html>',
            LIBXML_COMPACT | LIBXML_NONET | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev_err);

        foreach ($doc->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if (!$href) { continue;
            }
            $result = $this->convert_url($href);
            if ($result['is_affiliate']) {
                $link->setAttribute('href', $result['affiliate_url']);
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $html;
        }

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }
        return $output;
    }

    /**
     * Call $fn(...$args), cast to string, then apply affiliate conversion.
     * Plain URLs (no whitespace, has scheme) go through convert_url(); everything
     * else is treated as HTML and goes through convert_content().
     */
    public function wrap(callable $fn, ...$args): string
    {
        $output = (string) $fn(...$args);
        if (!preg_match('/\s/', $output) && parse_url($output, PHP_URL_SCHEME)) {
            return $this->convert_url($output)['affiliate_url'];
        }
        return $this->convert_content($output);
    }

    /**
     * Filter-safe wrapper: returns only the affiliate URL string. 
     */
    public function convert_url_string(string $url): string
    {
        return $this->convert_url($url)['affiliate_url'];
    }

    private function get_affiliate_configs()
    {
        $defaults = array(
            'amazon' => array(
                'enabled' => true,
                'tag' => get_option('restart_registry_amazon_tag', ''),
                'domains' => array('amazon.com', 'amazon.co.uk', 'amazon.ca', 'amazon.de', 'amazon.fr', 'amzn.to', 'amzn.com', 'a.co'),
            ),
            'target' => array(
                'enabled' => true,
                'affiliate_id' => get_option('restart_registry_target_id', ''),
                'domains' => array('target.com'),
            ),
            'walmart' => array(
                'enabled' => true,
                'affiliate_id' => get_option('restart_registry_walmart_id', ''),
                'domains' => array('walmart.com'),
            ),
            'etsy' => array(
                'enabled' => true,
                'affiliate_id' => get_option('restart_registry_etsy_id', ''),
                'domains' => array('etsy.com'),
            ),
            'ebay' => array(
                'enabled' => true,
                'campaign_id' => get_option('restart_registry_ebay_id', ''),
                'domains' => array('ebay.com', 'ebay.co.uk'),
            ),
            'bestbuy' => array(
                'enabled' => true,
                'affiliate_id' => get_option('restart_registry_bestbuy_id', ''),
                'domains' => array('bestbuy.com'),
            ),
            'homedepot' => array(
                'enabled' => true,
                'affiliate_id' => get_option('restart_registry_homedepot_id', ''),
                'domains' => array('homedepot.com'),
            ),
            'wayfair' => array(
                'enabled' => true,
                'affiliate_id' => get_option('restart_registry_wayfair_id', ''),
                'domains' => array('wayfair.com'),
            ),
            'shareasale' => array(
                'enabled' => true,
                'affiliate_id' => get_option('restart_registry_shareasale_id', ''),
                'merchant_id' => get_option('restart_registry_shareasale_merchant', ''),
            ),
            'cj' => array(
                'enabled' => true,
                'website_id' => get_option('restart_registry_cj_id', ''),
            ),
        );

        return apply_filters('restart_registry_affiliate_configs', $defaults);
    }

    public function convert_url($url)
    {
        $parsed_url = parse_url($url);
        if (!$parsed_url || !isset($parsed_url['host'])) {
            return array(
                'affiliate_url' => $url,
                'retailer' => 'Unknown',
                'is_affiliate' => false,
            );
        }

        $host = strtolower($parsed_url['host']);
        $host = preg_replace('/^www\./', '', $host);

        foreach ($this->affiliate_configs as $retailer => $config) {
            if (!$config['enabled']) { continue;
            }
            
            if (isset($config['domains'])) {
                foreach ($config['domains'] as $domain) {
                    if (strpos($host, $domain) !== false) {
                        $affiliate_url = $this->generate_affiliate_url($retailer, $url, $config);
                        return array(
                            'affiliate_url' => $affiliate_url,
                            'retailer' => ucfirst($retailer),
                            'is_affiliate' => ($affiliate_url !== $url),
                        );
                    }
                }
            }
        }

        return array(
            'affiliate_url' => $url,
            'retailer' => $this->extract_retailer_name($host),
            'is_affiliate' => false,
        );
    }

    private function generate_affiliate_url($retailer, $url, $config)
    {
        if (isset($config['url_template'])) {
            return $this->generate_custom_affiliate($url, $config);
        }
        switch ($retailer) {
        case 'amazon':
            return $this->generate_amazon_affiliate($url, $config);
        case 'target':
            return $this->generate_target_affiliate($url, $config);
        case 'walmart':
            return $this->generate_walmart_affiliate($url, $config);
        case 'etsy':
            return $this->generate_etsy_affiliate($url, $config);
        case 'ebay':
            return $this->generate_ebay_affiliate($url, $config);
        case 'bestbuy':
            return $this->generate_bestbuy_affiliate($url, $config);
        default:
            return $url;
        }
    }

    private function generate_custom_affiliate($url, $config)
    {
        $template     = $config['url_template'] ?? '';
        $affiliate_id = $config['affiliate_id'] ?? '';
        $merchant_id  = $config['merchant_id'] ?? '';

        if (empty($template) || empty($affiliate_id)) {
            return $url;
        }

        return str_replace(
            ['{url}', '{affiliate_id}', '{merchant_id}'],
            [urlencode($url), urlencode($affiliate_id), urlencode($merchant_id)],
            $template
        );
    }

    private function generate_amazon_affiliate($url, $config)
    {
        if (empty($config['tag'])) {
            return $url;
        }

        // Resolve short links (a.co) to get the full Amazon URL with ASIN in path.
        $resolved = preg_match('/^https?:\/\/a\.co\//i', $url) ? $this->resolve_url($url) : $url;

        // Build a clean URL from just the ASIN — drops all tracking/ref cruft.
        if (preg_match('/\/(?:dp|gp\/product)\/([A-Z0-9]{10})/i', $resolved, $m)) {
            $asin   = strtoupper($m[1]);
            $parsed = parse_url($resolved);
            return $parsed['scheme'] . '://' . $parsed['host'] . '/dp/' . $asin . '?tag=' . rawurlencode($config['tag']);
        }

        // Fallback for non-standard Amazon URLs: preserve path, replace tag param only.
        $parsed = parse_url($resolved);
        $query_params = array();
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query_params);
        }
        $query_params = array('tag' => $config['tag']);

        $affiliate_url = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['path'])) {
            $affiliate_url .= $parsed['path'];
        }
        $affiliate_url .= '?' . http_build_query($query_params);

        return $affiliate_url;
    }

    private function resolve_url(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array(
            $ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            ]
        );
        curl_exec($ch);
        $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return (is_string($final) && $final !== '') ? $final : $url;
    }

    private function generate_target_affiliate($url, $config)
    {
        if (empty($config['affiliate_id'])) {
            return $url;
        }

        $parsed = parse_url($url);
        $query_params = array();
        
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query_params);
        }

        $query_params['afid'] = $config['affiliate_id'];

        $new_query = http_build_query($query_params);
        
        $affiliate_url = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['path'])) {
            $affiliate_url .= $parsed['path'];
        }
        $affiliate_url .= '?' . $new_query;

        return $affiliate_url;
    }

    private function generate_walmart_affiliate($url, $config)
    {
        if (empty($config['affiliate_id'])) {
            return $url;
        }

        $parsed = parse_url($url);
        $query_params = array();
        
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query_params);
        }

        $query_params['affiliates_ad_id'] = $config['affiliate_id'];

        $new_query = http_build_query($query_params);
        
        $affiliate_url = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['path'])) {
            $affiliate_url .= $parsed['path'];
        }
        $affiliate_url .= '?' . $new_query;

        return $affiliate_url;
    }

    private function generate_etsy_affiliate($url, $config)
    {
        if (empty($config['affiliate_id'])) {
            return $url;
        }

        $parsed = parse_url($url);
        $query_params = array();
        
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query_params);
        }

        $query_params['ref'] = 'aff_' . $config['affiliate_id'];

        $new_query = http_build_query($query_params);
        
        $affiliate_url = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['path'])) {
            $affiliate_url .= $parsed['path'];
        }
        $affiliate_url .= '?' . $new_query;

        return $affiliate_url;
    }

    private function generate_ebay_affiliate($url, $config)
    {
        if (empty($config['campaign_id'])) {
            return $url;
        }

        $rover_url = 'https://rover.ebay.com/rover/1/' . $config['campaign_id'] . '/1?mpre=' . urlencode($url);
        return $rover_url;
    }

    private function generate_bestbuy_affiliate($url, $config)
    {
        if (empty($config['affiliate_id'])) {
            return $url;
        }

        $parsed = parse_url($url);
        $query_params = array();
        
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query_params);
        }

        $query_params['irclickid'] = $config['affiliate_id'];

        $new_query = http_build_query($query_params);
        
        $affiliate_url = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['path'])) {
            $affiliate_url .= $parsed['path'];
        }
        $affiliate_url .= '?' . $new_query;

        return $affiliate_url;
    }

    private function extract_retailer_name($host)
    {
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            return ucfirst($parts[count($parts) - 2]);
        }
        return ucfirst($host);
    }

    public function get_supported_retailers()
    {
        $retailers = array();
        foreach ($this->affiliate_configs as $key => $config) {
            if (isset($config['domains'])) {
                $retailers[$key] = array(
                    'name' => ucfirst($key),
                    'domains' => $config['domains'],
                    'enabled' => $config['enabled'],
                );
            }
        }
        return $retailers;
    }

    public function is_affiliate_link($url)
    {
        $result = $this->convert_url($url);
        return $result['is_affiliate'];
    }
}
