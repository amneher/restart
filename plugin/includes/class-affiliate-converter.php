<?php

/**
 * Affiliate Link Converter
 *
 * Converts regular product URLs to affiliate links
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry_Affiliate_Converter {

    private $affiliate_configs;

    public function __construct() {
        $this->affiliate_configs = $this->get_affiliate_configs();
    }

    private function get_affiliate_configs() {
        $defaults = array(
            'amazon' => array(
                'enabled' => true,
                'tag' => get_option('restart_registry_amazon_tag', ''),
                'domains' => array('amazon.com', 'amazon.co.uk', 'amazon.ca', 'amazon.de', 'amazon.fr', 'amzn.to', 'amzn.com'),
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

    public function convert_url($url) {
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
            if (!$config['enabled']) continue;
            
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

    private function generate_affiliate_url($retailer, $url, $config) {
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

    private function generate_amazon_affiliate($url, $config) {
        if (empty($config['tag'])) {
            return $url;
        }

        $parsed = parse_url($url);
        $query_params = array();
        
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query_params);
        }

        $query_params['tag'] = $config['tag'];
        unset($query_params['ref']);

        $new_query = http_build_query($query_params);
        
        $affiliate_url = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['path'])) {
            $affiliate_url .= $parsed['path'];
        }
        $affiliate_url .= '?' . $new_query;

        return $affiliate_url;
    }

    private function generate_target_affiliate($url, $config) {
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

    private function generate_walmart_affiliate($url, $config) {
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

    private function generate_etsy_affiliate($url, $config) {
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

    private function generate_ebay_affiliate($url, $config) {
        if (empty($config['campaign_id'])) {
            return $url;
        }

        $rover_url = 'https://rover.ebay.com/rover/1/' . $config['campaign_id'] . '/1?mpre=' . urlencode($url);
        return $rover_url;
    }

    private function generate_bestbuy_affiliate($url, $config) {
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

    private function extract_retailer_name($host) {
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            return ucfirst($parts[count($parts) - 2]);
        }
        return ucfirst($host);
    }

    public function get_supported_retailers() {
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

    public function is_affiliate_link($url) {
        $result = $this->convert_url($url);
        return $result['is_affiliate'];
    }
}
