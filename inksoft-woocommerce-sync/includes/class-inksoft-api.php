<?php
/**
 * InkSoft API Handler
 * Handles all API communication with InkSoft
 */

if (!defined('ABSPATH')) {
    exit;
}

class INKSOFT_API {
    
    private $api_key;
    private $base_url;
    private $page_size = 100;
    
    public function __construct($api_key, $base_url = 'https://stores.inksoft.com/Devo_Designs') {
        $this->api_key = $api_key;
        $this->base_url = rtrim($base_url, '/');
    }
    
    /**
     * Make API request
     */
    public function request($endpoint, $params = array()) {
        $url = $this->base_url . '/Api2/' . $endpoint;
        
        $params['Format'] = 'JSON';
        $query_string = http_build_query($params);
        $url = $url . '?' . $query_string;
        
        $args = array(
            'headers' => array(
                'x-api-key' => $this->api_key,
            ),
            'timeout' => 30,
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'error' => 'Invalid JSON response: ' . json_last_error_msg(),
            );
        }
        
        return array(
            'success' => true,
            'data' => $data['Data'] ?? $data,
            'raw' => $data,
            'pagination' => $data['Pagination'] ?? null,
        );
    }
    
    /**
     * Test connection
     */
    public function test_connection() {
        $result = $this->request('GetStoreData');
        return $result['success'] ?? false;
    }
    
    /**
     * Get all products with pagination
     */
    public function get_all_products() {
        $all_products = array();
        $page = 0;
        
        while (true) {
            $result = $this->request('GetProductBaseList', array(
                'Page' => $page,
                'PageSize' => $this->page_size,
            ));
            
            if (!$result['success']) {
                return array(
                    'success' => false,
                    'error' => $result['error'] ?? 'Unknown error',
                );
            }
            
            $products = $result['data'] ?? array();
            
            if (empty($products)) {
                break;
            }
            
            $all_products = array_merge($all_products, $products);
            
            // If we got fewer products than page size, we're done
            if (count($products) < $this->page_size) {
                break;
            }
            
            $page++;
            
            // Prevent infinite loops (safety check)
            if ($page > 1000) {
                break;
            }
        }
        
        return array(
            'success' => true,
            'products' => $all_products,
            'count' => count($all_products),
        );
    }
    
    /**
     * Get product categories
     */
    public function get_categories() {
        $result = $this->request('GetProductCategories');
        
        if (!$result['success']) {
            return array(
                'success' => false,
                'error' => $result['error'] ?? 'Unknown error',
            );
        }
        
        return array(
            'success' => true,
            'categories' => $result['data'] ?? array(),
            'count' => count($result['data'] ?? array()),
        );
    }
    
    /**
     * Get store data
     */
    public function get_store_data() {
        return $this->request('GetStoreData');
    }
    
    /**
     * Get detailed product with pricing and sizes
     * Returns product with all styles, sizes, and pricing information
     */
    public function get_product_detail($product_id) {
        $result = $this->request('GetProduct', array(
            'ProductId' => $product_id,
            'IncludePricing' => 'true',
            'IncludeQuantityPacks' => 'true',
            'IncludeCategories' => 'true',
        ));
        
        if (!$result['success']) {
            return array(
                'success' => false,
                'error' => $result['error'] ?? 'Unknown error',
            );
        }
        
        return array(
            'success' => true,
            'product' => $result['data'],
        );
    }
}
