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
     * Build a reverse map: InkSoft product ID => array of category entries
     * Each entry: [ 'parent' => parentName|null, 'name' => categoryName ]
     *
     * The InkSoft API stores categories as a tree with ItemIds on each node.
     * Product objects themselves never carry category data.
     */
    public function get_category_product_map() {
        $result = $this->request('GetProductCategories');

        if (!$result['success']) {
            return array(
                'success' => false,
                'error'   => $result['error'] ?? 'Unknown error',
                'map'     => array(),
            );
        }

        $tree = $result['data'] ?? array();
        if (!is_array($tree)) {
            return array('success' => true, 'map' => array());
        }

        $map = array();
        $this->walk_category_tree($tree, null, $map);

        return array('success' => true, 'map' => $map);
    }

    private function walk_category_tree(array $cats, $parent_name, array &$map) {
        foreach ($cats as $cat) {
            $name = trim($cat['Name'] ?? '');
            if (empty($name)) {
                continue;
            }

            // Accept any of the common field name variants the InkSoft API may use.
            $item_ids = $cat['ItemIds']
                     ?? $cat['ItemIDs']
                     ?? $cat['ProductIds']
                     ?? $cat['ProductIDs']
                     ?? $cat['Ids']
                     ?? array();

            foreach ($item_ids as $product_id) {
                $product_id = (int) $product_id;
                if (!isset($map[$product_id])) {
                    $map[$product_id] = array();
                }
                $map[$product_id][] = array(
                    'parent' => $parent_name,
                    'name'   => $name,
                );
            }

            if (!empty($cat['Children'])) {
                $this->walk_category_tree($cat['Children'], $name, $map);
            }
        }
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
            'ProductId'                          => $product_id,
            'IncludeAllPublisher'                => 'true',
            'IncludeCategories'                  => 'true',
            'IncludeCosts'                       => 'true',
            'IncludePricing'                     => 'true',
            'IncludeQuantityPacks'               => 'true',
            'IncludeStorePurchaseOptionOverrides' => 'true',
            'TierUniqueId'                       => '',
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
