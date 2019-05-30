<?php

if(!class_exists('lumise_connector')){

	class lumise_connector {
        
        protected $woo;
        
        public $platform;
        
        public function __construct() {

            global $lumise, $lumise_woo, $wpdb;
			
            $this->woo = $lumise_woo;
            $this->platform = 'woocommerce';
            
            $admin_url = admin_url('admin.php?page=lumise&');
            
            $this->config = array(
				"url" => $lumise_woo->tool_url,
				"tool_url" => $lumise_woo->tool_url,
				"logo" => $lumise_woo->assets_url . 'assets/images/logo.v3.png',
				"ajax_url" => $lumise_woo->ajax_url,
				"admin_ajax_url" => $lumise_woo->admin_ajax_url,
				"assets_url" => $lumise_woo->assets_url,
				"load_jquery" => true,
				"root_path" => $lumise_woo->app_path,
				"upload_path" => $lumise_woo->upload_path,
				"upload_url" => $lumise_woo->upload_url,
				"checkout_url" => $lumise_woo->checkout_url,
                
                //admin setting
                "admin_assets_url" => $lumise_woo->admin_assets_url,
                "admin_url" => $admin_url,
				
				"database" => array(
                    "host" => DB_HOST,
                    "user" => DB_USER,
                    "pass" => DB_PASSWORD,
                    "name" => DB_NAME,
					"prefix" => 'lumise_'
                )
			);
			
			$lumise->add_filter('product_price', array(&$this, 'filter_product_price'));
			$lumise->add_filter('product', array(&$this, 'filter_product'));
			$lumise->add_filter('products', array(&$this, 'filter_products'));
			$lumise->add_filter('query_products', array(&$this, 'query_products'));
			$lumise->add_filter('order_status', array(&$this, 'order_status_name'));
			$lumise->add_action('before_order_products', array(&$this, 'update_order_status'));
			$lumise->add_filter('product_base_price', array(&$this, 'product_base_price'));
			$lumise->add_filter('product_templates', array(&$this, 'product_templates'));
			$lumise->add_filter('settings_fields', array(&$this, 'settings_fields'));
			$lumise->add_filter('product_tabs', array(&$this, 'product_tabs'));
			$lumise->add_filter('init_settings', array(&$this, 'init_settings'));
			$lumise->add_filter('after_save_settings', array(&$this, 'after_save_settings'));
            
            if (!is_admin()) {
	            $lumise->add_action('js_init', array(&$this, 'js_init'));
            }
            
        }

        public function get_session($name) {
            return isset($_SESSION[$name]) ? $_SESSION[$name] : null;
        }

        public function set_session($name, $value) {
            $_SESSION[$name] = $value;
        }

        public function cookie($name) {
            return isset($_COOKIE[$name]) ? $_COOKIE[$name] : null;
        }
		
        public function is_admin() {
			
			return current_user_can('lumise_access') ? true : false;
		}
		
		public function is_login() {
			global $lumise;
			return $lumise->connector->cookie('uid') || 0;
			
		}
		
        public function capabilities($cap) {
	        
			$_cap = current_user_can($cap);
		    $_cap = apply_filters('lumise_woo_capabilities', $_cap, $cap);
		    
		    return $_cap;
		    
	    }
	    
		public function filter_product_price($price){
			
			if(function_exists('wc_price'))
				return wc_price( $price);
			else
				return $price;
		}
        
        public function filter_product($data) {
			
			/*
			*	$data['product'] is product CMS ID
			*	if not isset --> the product base can not checkout (missing cms product)
			*/
			
			if (
				(
					!isset($_GET['product_cms']) ||
					!isset($_POST['product_cms'])
				) && 
				(
					!isset($data['product']) || 
					empty($data['product'])
				)
			) return null;
			
			/*
			*	Incase force a product base with any product CMS 
			*/	
			
			if(isset($_GET['product_cms']) && $_GET['product_cms'] != $data['product'])
				$data['product'] = $_GET['product_cms'];
				
			if(isset($_POST['product_cms']) && $_POST['product_cms'] != $data['product'])
				$data['product'] = $_POST['product_cms'];
			
			global $wpdb, $lumise;
			
			$cms_product = $wpdb->get_results(
				sprintf(
					"SELECT * FROM `%s` WHERE `ID`=%d",
					$wpdb->prefix.'posts',
					(Int)$data['product']
				)
			);
			$cms_template = get_post_meta($data['product'], 'lumise_design_template', true );
		
			if (!isset($cms_product[0]))
				return null;
			
			$price_meta = $wpdb->get_results(
				"SELECT * FROM `{$wpdb->prefix}postmeta` WHERE `post_id` = {$data['product']} AND `meta_key` IN ('_regular_price', '_sale_price', '_price')"
			);
			
			if (count($price_meta) > 0) {
				
				$price = array(0, 0, 0);
				
				foreach ($price_meta as $pr) {
					if ($pr->meta_key == '_price')
						$price[0] = $pr->meta_value;
					if ($pr->meta_key == '_regular_price')
						$price[1] = $pr->meta_value;
					if ($pr->meta_key == '_sale_price')
						$price[2] = $pr->meta_value;
				}
				
				if ($price[2] !== 0 && $price[2] !== '')
					$data['price'] = $price[2];
				else if ($price[1] !== 0 && $price[1] !== '')
					$data['price'] = $price[1];
				else if ($price[0] !== 0 && $price[0] !== '')
					$data['price'] = $price[0];
					
			}
			
				
			$data['templates'] = array();
			
			if (
				isset($cms_template) && 
				!empty($cms_template) && 
				$cms_template != '%7B%7D'
			) {
				
				$cms_template = json_decode(urldecode($cms_template), true);
				
				if (isset($data['stages']->stages)) {
					foreach ($data['stages']->stages as $s => $d) {
						if (isset($cms_template[$s]))
							$data['stages']->stages->{$s}->template = $cms_template[$s];
						else unset($data['stages']->stages->{$s}->template);
					}
				} else {
					foreach ($data['stages'] as $s => $d) {
						if (isset($cms_template[$s]))
							$data['stages']->{$s}->template = $cms_template[$s];
						else unset($data['stages']->{$s}->template);
					}
				}
				
				$data['templates'] = $cms_template;
				
			}
			
			$data['name'] = $lumise->lang($cms_product[0]->post_title);
			$data['sku'] = get_post_meta($data['product'], '_sku', true);
			$data['description'] = $lumise->lang($cms_product[0]->post_content);
			
			return $data;
	        
        }
                
        public function filter_products($products) {
			
			$results = array();
			global $wpdb, $lumise;
			
			$task = $lumise->lib->esc('task');
			
			/*
			*	Select all product base	for task on CMS product
			*/
			
			if ($task == 'cms_product')
				return $products;
						
			if (count($products) > 0) {
				
				foreach ($products as $key => $data) {
					
					if (isset($data['product']) && !empty($data['product'])) {
						
						$cms_product = $wpdb->get_results("SELECT * FROM `{$wpdb->prefix}posts` WHERE `post_status` = 'publish' AND `id`=".$data['product']);
						
						if (isset($cms_product[0])) {
							
							$data['name'] = $lumise->lang($cms_product[0]->post_title);
							$data['description'] = $lumise->lang($cms_product[0]->post_content);
							if(function_exists('wc_get_product')){
								$_product = wc_get_product( $data['product'] );
								if(!empty($_product)) $data['price'] = $_product->get_price();
							}
							
							//get list of templates
							$cms_template = get_post_meta($data['product'], 'lumise_design_template', true );
							$data['templates'] = array();
							
							if (isset($cms_template) && !empty($cms_template) && $cms_template != '%7B%7D') {
								$cms_template = json_decode(urldecode($cms_template), true);
								foreach ($cms_template as $s => $n) {
									$template = $lumise->lib->get_template($cms_template[$s]['id']);
									$cms_template[$s]['upload'] = $template['upload'];
									$cms_template[$s]['price'] = isset($template['price']) ? $template['price'] : 0;
								}
								$data['templates'] = $cms_template;
							}
							
							array_push($results, $data);
							
						}
						
					}
				}
			}
			
	        return $results;
	        
        }
        
		public function query_products($query, $args) {
			
			global $wpdb, $lumise;
			
			$search = $args['s'];
			
			if (!empty($search)) {
				$search = " AND (LOWER(p.name) LIKE '%{$search}%' OR LOWER(wpp.post_title) LIKE '%{$search}%')";
			}
			
			return array(
				"SELECT SQL_CALC_FOUND_ROWS p.*",
				"FROM {$lumise->db->prefix}products p",
				"LEFT JOIN {$wpdb->prefix}posts wpp ON wpp.id = p.`product`",
				(!empty($args['category']) ?
					"LEFT JOIN {$lumise->db->prefix}categories_reference cref ON cref.item_id = p.id ".
					"WHERE `p`.`author` = '{$lumise->vendor_id}' AND p.active = 1 AND wpp.post_status = 'publish' AND cref.category_id = ".$args['category'].$search
					:
					"WHERE `p`.`author` = '{$lumise->vendor_id}' AND p.active = 1 AND wpp.post_status = 'publish'".$search
				),
				"GROUP BY p.id",
				"ORDER BY p.`order`, p.`id` ASC",
				"LIMIT {$args['index']}, {$args['limit']}"
			);
			
		}
        
        public function orders($filter, $orderby, $ordering, $limit, $limit_start) {

            global $wpdb;

            $items = array('rows' => array());

            $ops = $this->config['database']['prefix'] . 'order_products';
            $wois = $wpdb->prefix . 'woocommerce_order_items';
            $woim = $wpdb->prefix . 'woocommerce_order_itemmeta';
            $posts = $wpdb->prefix . 'posts';
            $pom = $wpdb->prefix . 'postmeta';
			
			$items['rows'] = array();
			$total_count = 0;
			
			if(class_exists( 'WooCommerce' )){
				$where = array();
				$where[] = " pos.post_status <> 'trash'";
				
	            if (is_array($filter) && isset($filter['keyword']) && !empty($filter['keyword'])) {

	                $fields = explode(',', $filter['fields']);
	                $arr_keyword = array();
	                for ($i = 0; $i < count($fields); $i++) {
	                    $arr_keyword[] = sprintf(" %s LIKE '%s' ", $fields[$i], $filter['keyword']);
	                }

	                $fields = implode(' OR ', $arr_keyword);

	                $where[] = $fields;
	            }
				
				if (count($where) > 0)
					$where = (count($where) > 0) ? ' WHERE ' . implode(' AND ', $where) : '';

	            $orderby_str = '';
	            if ($orderby != null && $ordering != null)
	                $orderby_str = ' ORDER BY ' . $orderby . ' ' . $ordering;

	            $sql = "SELECT SQL_CALC_FOUND_ROWS " 
	                . " wois.*, pos.ID as id, wois.order_item_name as product_name, pos.post_status as status, pos.post_date as created, pos.post_modified as updated, max( CASE WHEN pom.meta_key = '_order_total' and pos.ID = pom.post_id THEN pom.meta_value END ) as total, max( CASE WHEN pom.meta_key = '_order_currency' and pos.ID = pom.post_id THEN pom.meta_value END ) as currency"
	                . " FROM $ops as ops "
	                . " LEFT JOIN $wois as wois ON ops.order_id = wois.order_id"
	                . " LEFT JOIN $posts as pos ON pos.ID = ops.order_id"
	                . " LEFT JOIN $pom as pom on pos.ID = pom.post_id"
	                . $where
	                . " GROUP BY pos.ID "
	                . $orderby_str
	                . " LIMIT $limit_start, $limit";
					
	            $rows = $wpdb->get_results($sql);
				
	            foreach ($rows as $row)
	                $items['rows'][] = (array) $row;

	            //get total 
	            
	            $sql = "SELECT FOUND_ROWS() ";

	            $total_count = $wpdb->get_var($sql);
			}
            

            $items['total_count'] = $total_count;
            $items['total_page'] = ($limit != null) ? ceil($total_count / $limit) : 0;
            
            return $items;
            
        }

        public function products_order($order_id, $filter, $orderby, $ordering) {
	        
            global $wpdb, $lumise;

            $items = array('rows' => array());

            $ops = $this->config['database']['prefix'] . 'order_products';
            $wois = $wpdb->prefix . 'woocommerce_order_items';
            
            $where = array();
            
            $where[] = 'ops.order_id = '. $order_id;

            if (is_array($filter) && isset($filter['keyword']) && !empty($filter['keyword'])) {

                $where = array();
                $fields = explode(',', $filter['fields']);
                $arr_keyword = array();
                for ($i = 0; $i < count($fields); $i++) {
                    $arr_keyword[] = sprintf(" %s LIKE '%s' ", $fields[$i], $filter['keyword']);
                }

                $fields = '(' . implode(' OR ', $arr_keyword) . ')';

                $where[] = $fields;
                    
            }
            

            $orderby_str = '';
            if ($orderby != null && $ordering != null)
                $orderby_str = ' ORDER BY ' . $orderby . ' ' . $ordering;

            $sql = "SELECT "
                . "SQL_CALC_FOUND_ROWS *"
                . " FROM $ops as ops "
                . " JOIN $wois as wois ON wois.order_id = ops.order_id"
                . ' WHERE '. implode(' AND ', $where)
                . " GROUP BY ops.id "
                . $orderby_str;

            $rows = $wpdb->get_results($sql);

            foreach ($rows as $row)
                $items['rows'][] = (array) $row;

            //get total 
            $sql = "SELECT FOUND_ROWS() ";

            $total_count = $wpdb->get_var($sql);

            $items['total_count'] = $total_count;
            $items['total_page'] = 1;
			
			$order = new WC_Order( $order_id );
			$created_date = date('F j, Y, g:i a', strtotime($order->get_date_created()));
			$modified_date = date('F j, Y, g:i a', strtotime($order->get_date_modified()));
			
			$items['order'] = array(
				'created' => $created_date,
				'updated' => $modified_date,
				'total' => $order->get_total(),
				'status' => 'wc-' . $order->get_status()
			);
			
            return $items;
            
        }

		public function add_to_cart( $data ) {
			
			global $woocommerce, $lumise, $lumise_cart_adding;
			
			$lumise_cart_adding = true;
			
			if ( is_array($data) && isset($woocommerce) ) {
				
				//remove all items from editor
				
				foreach( WC()->cart->get_cart() as $cart_item_key => $item ){
					if( 
						isset($item['lumise_data'])
						&& 
						(
							( isset( $item['lumise_incart'] ) && !$item['lumise_incart'] ) ||
							!isset( $item['lumise_incart'] ) 
						)
					) { 
						$woocommerce->cart->remove_cart_item( $cart_item_key );
					}
				}
				
				if ( $lumise->connector->get_session('lumise_cart_removed') == null )
					$removed_items = array();
				else 
					$removed_items = $lumise->connector->get_session('lumise_cart_removed');
				
				
				/*
				*	Start to add item to WooCommerce Cart
				*/
				
				$cart_ids = array();
				
				foreach ( $data as $cart_id => $item ) {
					
					if ( !isset( $item[ 'incart' ] ) ) {
						
						$product_id = $item[ 'product_cms' ];
						
						$item['cart_id'] = $cart_id;
						
						$extra_option = array(
							'lumise_data' => $item
						);
						
						$woocommerce->cart->add_to_cart( $product_id, 1 , null, null, $extra_option );
						
					}
					
					$cart_ids[] = $item['cart_id'];
					
				}
				
				$removed_items = array_diff( $removed_items, $cart_ids );
				
				$lumise->connector->set_session('lumise_cart_removed', $removed_items );
				
				return wc_get_cart_url();
				
			}
			
			return false;
			
		}
		
		public function statuses() {
			return wc_get_order_statuses();
		}
		
		public function update_order_status($order_id) {
			if(isset($_POST['order_status'])){
				$status = str_replace('wc-', '', $_POST['order_status']);
				$order = new WC_Order($order_id);
				$order->update_status($status);
			}
		}
		
		public function order_status_name($status) {
			return (function_exists('wc_get_order_status_name'))? wc_get_order_status_name($status) : $status;
		}
		
		public function product_base_price($price, $product_id){
			
			global $lumise;
			
			if(function_exists('wc_get_product')){
				$product = wc_get_product( $product_id );
				$price = ($product)? $product->get_price(): 0;
			}
			
			return $price;
			
		}
		
		public function product_templates($data) {
			
			$item = $data['item'];
			
			if ($item->cms_id) {
				
				$cms_template = get_post_meta($item->cms_id, 'lumise_design_template', true );
				
				$template_price = 0;
				
				if (
					isset($cms_template) && 
					!empty($cms_template) && 
					$cms_template != '%7B%7D'
				) {
					
					$data['templates'] = array();
					$cms_template = json_decode(urldecode($cms_template), true);
					
					foreach ($cms_template as $stage){
						if(!in_array($stage['id'], $data['templates'])){
							$data['templates'][] = $stage['id'];
						}
					}
					
				}
			}
			
			return $data['templates'];
		}
		
		public function product_tabs($arg) {
			
			global $lumise, $wpdb;
			
			if (isset($_GET['id'])) {
				
				$product = $_GET['id'];

				foreach ($arg['tabs']['details:' . $lumise->lang('Details')] as $index => $field) {
					if(
					 	$field['name'] == 'product' &&
						$product > 0
					){
						//find all product assigned
						$args = array (
						    'post_type'		=> array( 'product' ),
						    'meta_query'	=> array(
						        array(
						            'key'       => 'lumise_product_base',
						            'value'     => $product,
						        ),
						    ),
						);
						$query = new WP_Query( $args );
						$products = array();
						if ( $query->have_posts() ) {
						    while ( $query->have_posts() ) {
						        $query->the_post();
						        $products[get_the_ID()] = get_the_title();
						    }
							
							$arg['tabs']['details:' . $lumise->lang('Details')][$index]['options'] = $products;
							$arg['tabs']['details:' . $lumise->lang('Details')][$index]['type'] = 'dropbox';
						}
						
						
					}
				}
			}
			return $arg;
		}
		
		public function settings_fields($arg){
			
			global $lumise;
			
			$pages = get_pages();
			
			$pages_list = array();
			
			foreach ($pages as $page) {
				$pages_list[$page->ID] = $page->post_title;
			}
			
			$arg['tabs']['shop:'.$lumise->lang('Shop')][] = array(
				'type' 		=> 'dropbox',
				'name' 		=> 'editor_page',
				'value' 	=> '',
				'label' 	=> $lumise->lang('Editor Page'),
				'desc' 		=> $lumise->lang('Page to display Lumise editor for design.'),
				'options' 	=> $pages_list
			);
			
			$arg['tabs']['shop:'.$lumise->lang('Shop')][] = array(
				'type' => 'input',
				'name' => 'btn_text',
				'value' => 'Customize',
				'label' => $lumise->lang('Button Text'),
				'desc' => $lumise->lang('Customize button text. Default: Customize'),
			);
			
			$arg['tabs']['shop:'.$lumise->lang('Shop')][] = array(
				'type' => 'toggle',
				'name' => 'btn_list',
				'value' => 1,
				'label' => $lumise->lang('Button Listing'),
				'desc' => $lumise->lang('Show Customize button on list products page or other listing'),
			);
			
			$arg['tabs']['shop:'.$lumise->lang('Shop')][] = array(
				'type' => 'toggle',
				'name' => 'btn_page',
				'value' => 1,
				'label' => $lumise->lang('Button Product Page'),
				'desc' => $lumise->lang('Show Customize button on product page'),
			);
			
			$arg['tabs']['shop:'.$lumise->lang('Shop')][] = array(
				'type' => 'toggle',
				'name' => 'email_design',
				'value' => 1,
				'label' => $lumise->lang('Designs details in email'),
				'desc' => $lumise->lang('Send details of designs in email for admin when orders are created, send to user when orders are completed'),
			);
			
			return $arg;
		}
		
		public function init_settings($settings){
			
			$settings = array_merge($settings, array(
				'editor_page' => get_option('lumise_editor_page', 0),
				'btn_list' => 1,
				'btn_text' => 'Customize',
				'btn_page' => 1,
				'email_design' => 1
			));
			
			return $settings;
		}
		
		public function after_save_settings($data){
			//update page editor
			$fields = array(
				'btn_list',
				'btn_text',
				'btn_page',
				'email_design',
			);
			
			$config = array();
			
			foreach($fields as $field){
				if (isset($data[$field]))
					$config[$field] = $data[$field];
				else $config[$field] = '';
			};
	
			if (isset($data['editor_page']))
				update_option('lumise_editor_page', $data['editor_page']);
	
			update_option('lumise_config', $config);
	
		}
		
		public function do_action($name = '', $params = '') {
			do_action('lumise_'.$name, $params);
		}
		
		public function apply_filters($name = '', $params = '', $params2 = null, $params3 = null) {
			return apply_filters('lumise_'.$name, $params, $params2, $params3);
		}
		
		public function setup() {
			
			if (!isset($_GET['step']))
				return;
			
			global $lumise;
				
			$step = $_GET['step'];
			
			if ($step == '1') {
			
				$data = file_get_contents($_FILES['file']['tmp_name']);
				$data = json_decode(urldecode($data));
				
				$data->logo = @json_decode(urldecode($data->logo));
				
				update_option('lumise_editor_page', $data->editor_page);
				
				if ($data->color != $lumise->cfg->settings['primary_color']) {
					$lumise->lib->render_css(array(
						'primary_color' => $data->color,
						'custom_css' => $lumise->cfg->settings['custom_css']
					));
				}
				
				if (is_object($data->logo)) {
					
					$path = $lumise->cfg->upload_path.'settings'.DS;
					$name = $data->logo->name;
					$img = explode(',', $data->logo->data);
					$img = base64_decode($img[1]);
					$count = 1;
					
					while (file_exists($path.$name)) {
						$name = $count.'-'.$data->logo->name;
						$count++;
					}
					
					@file_put_contents($path.$name, $img);
					
					$old_logo = str_replace(
						array($lumise->cfg->upload_url, 'settings/'),
						array($lumise->cfg->upload_path, 'settings'.DS), 
						$lumise->cfg->settings['logo']
					);
					
					@unlink($old_logo);
					$lumise->set_option('logo', $lumise->cfg->upload_url.'settings/'.$name);
					
				}
				
				$lumise->set_option('currency', $data->currency);
				$lumise->set_option('conditions', $data->terms);
				$lumise->set_option('primary_color', $data->color);
				$lumise->set_option('editor_page', $data->editor_page);
				$lumise->set_option('last_update', time());
				
				update_option('lumise_setup', 'done');
				
				echo 1;
				
			} else if ($step == '2') {
				
				$path = WP_CONTENT_DIR.DS;
				$upath = $lumise->cfg->upload_path.'user_data'.DS;
				
				$errors = array();
				
				if (is_dir($upath.'pack')) 
					$lumise->lib->remove_dir($upath.'pack');
				
				if ($_GET['theme'] == '1' || $_GET['kc'] == '1') {
					
					if (
						!is_dir($path.'plugins'.DS.'kingcomposer') || 
						!is_dir($path.'themes'.DS.'lumise')
					) {
						$download = $this->download(
							(is_ssl() ? 'https' : 'http').'://download.lumise.com/woocommerce/pack.zip',
							$upath,
							'pack'
						);
						
						if ($download !== true) {
							$errors[] = $download;
						} else {
							$unpack = $this->unpack('pack', $upath);
							if ($unpack !== true)
								array_push($errors, $unpack);
						}
					
					}
					
					if (is_dir($upath.'pack')) {
						
						if ($_GET['theme'] == '1' && !is_dir($path.'themes'.DS.'lumise'))
							rename($upath.'pack'.DS.'lumise', $path.'themes'.DS.'lumise');
						
						if ($_GET['kc'] == '1' && !is_dir($path.'plugins'.DS.'kingcomposer'))
							rename($upath.'pack'.DS.'kingcomposer', $path.'plugins'.DS.'kingcomposer');
						
						$lumise->lib->remove_dir($upath.'pack');
						
					}
					
					if (is_dir($path.'themes'.DS.'lumise')) {
						update_option('template', 'lumise');
						update_option('stylesheet', 'lumise');
						update_option('current_theme', 'Lumise');
					} else {
						$errors[] = 'Error: Could not download and active Lumise theme, please try again';
					}
					
					if (is_dir($path.'plugins'.DS.'kingcomposer')) {
						
						$current = get_option( 'active_plugins', array() );
						
						if (!in_array('kingcomposer'.DS.'kingcomposer.php', $current)) {
							$current[] = 'kingcomposer'.DS.'kingcomposer.php';
							sort($current);
							$current = array_filter($current, 'strlen');
							update_option('active_plugins', $current);
						}
						
					} else {
						$errors[] = 'Error: Could not download and active Kingcomposer Pagebuilder, please try again';
					}
				
				}
				
				if (count($errors) > 0) {
					echo json_encode($errors);
				} else {
					echo 1;
				}
				
				exit;
				
			}
		}
		
		private function download($url = '', $path = '', $name = '') {
			
			global $lumise;
			
			if (empty($url) || empty($path) || empty($name)) {
				return 'Error: Missing params for downloading package';
			}
			
			$error = true;
			
			$file = $path.$name.'.zip';
			
			if (is_dir($path.$name))
				return true;
				
			@set_time_limit(300);
				
			/*$fh = @fopen($url, false, true);
			
			$data = @file_put_contents($file, $fh);
			
			@fclose($fh);*/
			
			$data = $lumise->lib->remote_connect($url);
			@file_put_contents($file, $data);
			
			/*
			*	End downloading extentions
			*/
			
			if (strlen($data) === 0) {		
				$error = 'Error: Could not download file, make sure that the fopen() funtion on your server is enabled';
			} else if (strlen($data) < 250) {
				$error = 'Error: '.file_get_contents($file);
			}
			
			return $error;
			
		}
		
		private function unpack($name = '', $path = '') {
			
			if (empty($name) || !is_dir($path)) {
				return 'Error: Missing params for unpacking';
			}
			
			if (is_dir($path.$name))
				return true;
				
			global $lumise;
			
			$file = $path.DS.$name.'.zip';
			
			$error = true;
			
			if (!class_exists('ZipArchive')) {
				$error = 'Error: Your server does not support ZipArchive to extract extensions';
			} else {
				
				if (!isset($this->zip))
					$this->zip = new ZipArchive;
				
				$res = $this->zip->open($file);
				
				if ($res === TRUE) {
					
					$this->zip->extractTo($path);
					$this->zip->close();
					
					if (is_dir($path.'__MACOSX'))
						$lumise->lib->remove_dir($path.'__MACOSX');
					
				} else {
					$error = 'Error: Could not open file: '.$file;
				}
			}
			
			@unlink($file);
			
			return $error;
			
		}
		
		public function js_init() {
			
			if (isset($_GET['reorder'])) {
				
				$order_id = (int)$_GET['reorder'];
				$uid = get_post_meta($order_id, '_customer_user', true);
				
				global $lumise;
				
				if ($uid != get_current_user_id()) {
				
					$this->js_init_error($lumise->lang('Error: Could not reorder because it is not owned by you'));
						
				} else {
				
					$orders = $lumise->db->rawQuery(sprintf(
						"SELECT * FROM `%s` WHERE `order_id` = '%d'",
			            $lumise->lib->sql_esc($lumise->db->prefix."order_products"),
						$order_id
			        ));
			        
					$morder = new WC_Order( $order_id );
			        
			        if (count($orders) > 0 && $morder->get_id()) {
					        
				        echo 'var orders = '.json_encode($orders).';';
			        
			    ?>
			    	
				    lumise.f('<?php echo $lumise->lang('Loading order data'); ?>');
				    lumise.actions.add('db-ready', function() {
					    
					    lumise.post({
							action: 'list_products'
						}, function(res) {
							
							lumise.ops.products = res;
							localStorage.setItem('LUMISE-CART-DATA', '');
							
							var cart_data =  JSON.parse(localStorage.getItem('LUMISE-CART-DATA') || '{}'),
								first_cart_id = '',
								addcart = function(i) {
									
									$.get('<?php echo $lumise->cfg->upload_url; ?>designs/'+orders[i].design+'.lumi', function(res) {
										
										res = JSON.parse(res);
										
										res.id = new Date().getTime().toString(36).toUpperCase();
										
										if (first_cart_id === '')
											first_cart_id = res.id;
											
										orders[i].data = JSON.parse(decodeURIComponent(atob(orders[i].data)));
										
										data = {
										    product_data : lumise.ops.products.products.filter(function(p) {
										    	return p.id == orders[i].product_base
										    })[0],
										    price_total : <?php echo (float)$morder->get_total(); ?>,
										    updated : new Date().getTime(),
										    id : res.id,
										    template : res.template,
										    color : orders[i].data.color,
										    extra : 0,
										    color_name : orders[i].data.color_name,
										    options: orders[i].data.attributes,
										    states_data : {}
										};
										
										data.product_data.stages = lumise.fn.dejson(data.product_data.stages);
										
										cart_data[res.id] = lumise.fn.enjson(data);
										localStorage.setItem('LUMISE-CART-DATA', JSON.stringify(cart_data));
										lumise.indexed.save([res], 'cart');
										
										if (orders[i+1] !== undefined) {
											addcart(i+1);
										} else {
											lumise.fn.notice('<?php echo $lumise->lang('Your order has been successfully replicated'); ?>', 'success', 5000);
											lumise.fn.set_url('reorder', null);
											lumise.cart.edit_item(first_cart_id);
										}
										
									});
								};
							
							addcart(0);
							
						});
					});
				    
				    lumise.data.access_core = '';
				    
			    <?php   
			        
			        } else { // no order found
					
					$this->js_init_error($lumise->lang('Error: Could not found the order'));
						
			        }
			        
		        }
			}
		}
		
		public function js_init_error($msg) {
			global $lumise;
		?>	
			$('#lumise-main').html('<div id="lumise-no-product" style="display: block;">\
					<p>\
						<?php echo $msg; ?><br>\
						<?php echo $lumise->lang('Please select a product to start designing'); ?></p>\
					<button class="lumise-btn" id="lumise-select-product">\
						<i class="lumisex-android-apps"></i> <?php echo $lumise->lang('Select another product'); ?>\
					</button>\
				</div>');
			$('#lumise-select-product').on('click', function(e) {
				window.location.href = '<?php echo $lumise->cfg->tool_url; ?>refresh=1';
				e.preventDefault();
			});
			lumise.f(false);
			return false;
		<?php	
		}
		
		public function update() {
			
			global $lumise;
			
			$lumise_path = dirname(__FILE__);
			$update_path = $lumise->cfg->upload_path.'tmpl'.DS.'lumise';
			$backup_path = $lumise->cfg->upload_path.'update_backup';
			
			$lumise->lib->delete_dir($backup_path);
								
			return (
				@rename($lumise_path, $backup_path) && 
				@rename($update_path, $lumise_path)
			);
			
		}

    }

}
