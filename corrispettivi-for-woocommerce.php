<?php
/**
 * Plugin Name: Corrispettivi per WooCommerce
 * Plugin URI: https://ldav.it/plugin/corrispettivi-for-woocommerce/
 * Description: An aid for the compilation of the Register of Payments from WooCommerce sales.
 * Version: 0.7.1
 * Author: laboratorio d'Avanguardia
 * Author URI: https://ldav.it/
 * Text Domain: corrispettivi-for-woocommerce
 * WC requires at least: 3.0.0
 * WC tested up to: 8.0.1
 * License: GPLv2 or later
 * License URI: http://www.opensource.org/licenses/gpl-license.php
*/
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( !defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( !class_exists( 'Corrispettivi_for_WooCommerce' ) ) :
if ( !defined( 'corrispettivi_for_woocommerce_domain' ) ) define('corrispettivi_for_woocommerce_domain', 'corrispettivi-for-woocommerce');
class Corrispettivi_for_WooCommerce {
	public static $plugin_url;
	public static $plugin_path;
	public static $plugin_basename;
	public $version = '0.7.1';
	protected static $instance = null;

	private $is_wc_active;
	private $is_wcpdf_IT_active;
	private $is_wpo_wcpdf_active;
	private $tot_parz_s;
	private $tax_based_on;
	private $date_format;
	private $wc_status = array("wc-processing", "wc-on-hold", "wc-completed", "wc-refunded");

	public static function instance() {
		if ( is_null( self::$instance ) ) self::$instance = new self();
		return self::$instance;
	}

	public function __construct() {
		self::$plugin_basename = plugin_basename(__FILE__);
		self::$plugin_url = plugin_dir_url(self::$plugin_basename);
		self::$plugin_path = trailingslashit(dirname(__FILE__));
		$this->init_hooks();
	}
	
	public function init() {
		load_plugin_textdomain( corrispettivi_for_woocommerce_domain, false, dirname( self::$plugin_basename ) . "/languages" );
	}

	public function init_hooks() {
		add_action( 'init', array( $this, 'init' ), 0 );
		$this->check_active_plugins();
		if (!$this->is_wc_active) {
			add_action( 'admin_notices', array ( $this, 'check_wc' ) );
//		} elseif (!$this->is_wcpdf_IT_active && !$this->is_wpo_wcpdf_active) {
//			add_action( 'admin_notices', array ( $this, 'check_wcpdf_IT' ) );
		} else {
			$this->tax_based_on = get_option( 'woocommerce_tax_based_on' );
			$this->date_format = get_option( 'date_format' );
			add_action( 'admin_menu', array( $this, 'add_admin_menus' ), 20 );
			add_action( 'wp_ajax_corrispettivi_for_woocommerce_dismiss_notice', array( $this, 'dismiss_notice' ) );
			register_deactivation_hook(__FILE__, __CLASS__ . '::corrispettivi_for_woocommerce_uninstall');
			register_uninstall_hook(__FILE__, __CLASS__ . '::corrispettivi_for_woocommerce_uninstall');
			//delete_option("corrispettivi_for_woocommerce_wc_status");
			$s = get_option( 'corrispettivi_for_woocommerce_wc_status' );
			$this->wc_status = $s ? $s : $this->wc_status;
			update_option("corrispettivi_for_woocommerce_wc_status", $this->wc_status);
			add_action( 'before_woocommerce_init', function() {
				if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
				}
			} );
		}
	}
	
	static function corrispettivi_for_woocommerce_uninstall(){
		delete_option("corrispettivi_for_woocommerce_dismiss_notice");
	}
	
	public function dismiss_notice(){
		$nonce = $_REQUEST["_wpnonce"];
		if ( ! wp_verify_nonce( $nonce, "corrispettivi_for_woocommerce_send_nonce" . $_SERVER['HTTP_HOST'] ) ) {
			throw new Exception( __( 'Invalid nonce verification', corrispettivi_for_woocommerce_domain ) );
		} else {
			update_option("corrispettivi_for_woocommerce_dismiss_notice", 1);
		}
		wp_die();
	}
	
	public function check_active_plugins() {
		$active_plugins = get_site_option( 'active_plugins', array());
		$plugins = get_site_option( 'active_sitewide_plugins', array());
		$this->is_wc_active = (in_array('woocommerce/woocommerce.php', $active_plugins) || isset($plugins['woocommerce/woocommerce.php']));
		$this->is_wcpdf_IT_active = (in_array('woocommerce-italian-add-on/woocommerce-italian-add-on.php', $active_plugins) || isset($plugins['woocommerce-italian-add-on/woocommerce-italian-add-on.php']));
		$this->is_wpo_wcpdf_active = (in_array('woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php', $active_plugins) || isset($plugins['woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php']));
	}

	public function check_wc( $fields ) {
		printf( '<div class="error is-dismissible"><p>' . __( 'Corrispettivi for WooCommerce requires %sWooCommerce%s 3.0+ to be installed and activated!' , corrispettivi_for_woocommerce_domain ) . '</p></div>', '<a href="https://wordpress.org/plugins/woocommerce/">', '</a>' );
	}	
	
	public function check_wcpdf_IT( $fields ) {
		printf( '<div class="error is-dismissible"><p>' . __( 'Corrispettivi for WooCommerce requires <strong>%sWooCommerce PDF Invoices Italian Add-on%s</strong> or <strong>%sWooCommerce Italian Add-on Plus%s</strong> to be installed and activated!' , corrispettivi_for_woocommerce_domain ) . '</p></div>', '<a href="https://it.wordpress.org/plugins/woocommerce-pdf-invoices-italian-add-on/">', '</a>', '<a href="https://ldav.it/shop/plugin/woocommerce-italian-add-on/">', '</a>' );
	}

	public function add_admin_menus() {
		add_submenu_page(
			'woocommerce',
			__( 'Payments', corrispettivi_for_woocommerce_domain ),
			__( 'Payments', corrispettivi_for_woocommerce_domain ),
			'manage_woocommerce',
			'corrispettivi_for_woocommerce_invoice_list',
			array( $this, 'invoice_list' )
		);
	}
	
	private function add_js_and_fields(){
		wp_register_script( 'xlsx.core', self::$plugin_url.'js/xlsx.core.min.js', array(), $this->version );
		wp_enqueue_script( 'xlsx.core' );
		wp_register_script( 'FileSaver', self::$plugin_url.'js/FileSaver.min.js', array(), $this->version );
		wp_enqueue_script( 'FileSaver' );
		wp_register_script( 'tableexport', self::$plugin_url.'js/tableexport.min.js', array(), $this->version );
		wp_enqueue_script( 'tableexport' );
	}

	public function invoice_list(){
		$select = !empty( $_REQUEST['corrispettivi_for_woocommerce_select'] ) ? sanitize_text_field( $_REQUEST['corrispettivi_for_woocommerce_select'] ) : date("Y-m");
		$nonce = wp_create_nonce( "corrispettivi_for_woocommerce_send_nonce" . $_SERVER['HTTP_HOST'] );
?>
<div class="wrap woocommerce corrispettivi_for_woocommerce">
<h2><?php _e("Corrispettivi for WooCommerce", corrispettivi_for_woocommerce_domain)?> <sup><?php echo esc_attr($this->version) ?></sup></h2>
<?php
		if (!$this->is_wcpdf_IT_active && !$this->is_wpo_wcpdf_active) {
			$op = get_option("corrispettivi_for_woocommerce_dismiss_notice");
			if(!$op || $op != "1") {
?>
<div class="notice notice-warning is-dismissible ">
<p>
<?php
			printf( __( 'For the invoices recognition, Corrispettivi for WooCommerce requires <strong>%sWooCommerce PDF Invoices Italian Add-on%s</strong> or <strong>%sWooCommerce Italian Add-on Plus%s</strong> to be installed and activated!' , corrispettivi_for_woocommerce_domain ), '<a href="https://it.wordpress.org/plugins/woocommerce-pdf-invoices-italian-add-on/">', '</a>', '<a href="https://ldav.it/shop/plugin/woocommerce-italian-add-on/">', '</a>' );
			$ajax_url = admin_url('admin-ajax.php', 'relative');
?>
</p>
</div>
<script>
jQuery(document).on("click", ".corrispettivi_for_woocommerce button.notice-dismiss", function(){
	jQuery.post( '<?php echo $ajax_url ?>', { action: 'corrispettivi_for_woocommerce_dismiss_notice', "_wpnonce" : '<?php echo $nonce?>' } );
});
</script>
<?php
			}
		}
?>
<h2><?php _e("List of Payments", corrispettivi_for_woocommerce_domain) ?></h2>
<form method="get" action="" id="corrispettivi_for_woocommerce_invoice_list">
<input type="hidden" name="page" value="corrispettivi_for_woocommerce_invoice_list">
<p>
<?php
		global $wpdb;
		if(!empty($_REQUEST["corrispettivi_for_woocommerce_wc_status"])) {
			$this->wc_status = $_REQUEST["corrispettivi_for_woocommerce_wc_status"];
			update_option("corrispettivi_for_woocommerce_wc_status", $this->wc_status);
		}


		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$tsql = "SELECT DISTINCT YEAR(date_created_gmt) as anno, MONTH(date_created_gmt) as mese FROM {$wpdb->prefix}wc_orders WHERE status IN ('" . implode("','", $this->wc_status) . "') ORDER BY date_created_gmt DESC";
		} else {
			$tsql = "SELECT DISTINCT YEAR(post_date) as anno, MONTH(post_date) as mese FROM {$wpdb->prefix}posts WHERE post_type='shop_order' and post_status IN ('" . implode("','", $this->wc_status) . "') ORDER BY post_date DESC";
		}
		$results = $wpdb->get_results($tsql, "ARRAY_A");
		if(empty( $_REQUEST['corrispettivi_for_woocommerce_select'] ) && $results){
			$select = sprintf("%d-%02d", $results[0]["anno"], $results[0]["mese"]);
		}
?>
	<select id="corrispettivi_for_woocommerce_select" name="corrispettivi_for_woocommerce_select">
<?php
		foreach($results as $rs){
			printf('<option value="%d-%02d">%s</option>', $rs["anno"], $rs["mese"], strtolower(wp_date("F Y", strtotime($rs["anno"] . "-" . $rs["mese"] . "-01"))));
		}
?>
	</select>
	<input type="submit" class="button button-primary" value="<?php _e( "Filter payments", corrispettivi_for_woocommerce_domain ) ?>">
	<label><input type="checkbox" id="corrispettivi_for_woocommerce_show_0_days" name="corrispettivi_for_woocommerce_show_0_days"><?php _e( "Show days without payments", corrispettivi_for_woocommerce_domain ) ?></label>
<label><strong style="margin-left: 1rem"><?php  _e( "Order status", corrispettivi_for_woocommerce_domain ) ?>:</strong></label>
<input type="hidden" name="corrispettivi_for_woocommerce_wc_status[]" value="wc-completed">
<?php
		$statuses = wc_get_order_statuses();
		foreach($statuses as $k => $v){
			$allowed = array("wc-processing", "wc-on-hold", "wc-completed", "wc-refunded");
			if(!in_array($k, $allowed)) continue;
			$chk = in_array($k, $this->wc_status) ? " checked" : "";
			$chk .= ($k == "wc-completed" ? " disabled" : "");
			printf('<label><input type="checkbox" name="corrispettivi_for_woocommerce_wc_status[]" value="%1$s"%3$s>%2$s</label> ', $k, $v, $chk);
		}
?>
	</p>
<script>
	jQuery("#corrispettivi_for_woocommerce_select").val("<?php echo esc_attr($select) ?>");
	jQuery("#corrispettivi_for_woocommerce_show_0_days").prop("checked", <?php echo empty($_REQUEST["corrispettivi_for_woocommerce_show_0_days"]) ? "false" : "true" ?>);
</script>
</form>
<?php
		$from = strtotime(sprintf("%s-01", $select));
		$to = strtotime( date("c", strtotime(sprintf("%s-01 +1 month", $select)) ));

		$args = array(
			'date_created' => date("Y-m-d", $from) . "..." . date("Y-m-t", $from),
			'type' => 'shop_order',
			'status' => $this->wc_status,
			'limit' => -1,
		);
		$res = wc_get_orders($args);
		$rows = array();
		foreach($res as $order) {
			$order_id = $order->get_id();
			$date_created = $order->get_date_created();
			$rs = array(
				"order_id" => $order_id,
				"num" => "",
				"date" => $date_created->date("Y-m-d"),
				"type" => "",
				"data" => "",
				"tot_parz_s" => "",
			);
			$data = $this->get_order_data($order);
			if($data){
				$rs["data"] = $data["data"];
				$rs["tot_parz_s"] = $data["tot_parz_s"];
				if(!empty($data["num"])) {
					$rs["type"] = "invoice";
					$rs["num"] = $data["num"];
				}
			}
			$rows[] = $rs;
		}
		
		$data = array();
		$tax_rates = array(0 => 0, -1=> 0);
		foreach($rows as $rs){
			$dd = $rs["date"];
			if(empty($data[$dd])) $data[$dd] = array("date" => $dd);
			if(empty($data[$dd]["total"])) $data[$dd]["total"] = 0;
			foreach($rs["data"] as $s){
				if(!empty($s["num"]) && $s["type"] == "invoice") {
					if(empty($data[$dd]["min"]) || 
						 (!empty($data[$dd]["min"]) && $s["num"] < $data[$dd]["min"]) ){
						$data[$dd]["min"] = $s["num"];
					}
					if(empty($data[$dd]["max"]) || 
						 (!empty($data[$dd]["max"]) && $s["num"] > $data[$dd]["max"]) ){
						$data[$dd]["max"] = $s["num"];
					}
				}
			}
			foreach($rs["tot_parz_s"] as $tax_rate => $v){
				if(empty($data[$dd]["tax_rates"][$tax_rate])) $data[$dd]["tax_rates"][$tax_rate] = array("tax" => 0, "total" => 0);
				$tax_rates[$tax_rate] = 0;
				$data[$dd]["tax_rates"][$tax_rate]["tax"] += $v["tax"];
				$data[$dd]["tax_rates"][$tax_rate]["total"] += $v["total"];
				$data[$dd]["total"] += $v["total"];
			}
		}
		krsort($tax_rates);

		if(!empty($data) && !empty($_REQUEST["corrispettivi_for_woocommerce_show_0_days"])){
			$rs_org = reset($data);
			foreach($rs_org as $k => $s){
				$rs_org[$k] = 0;
			}
			$rs_org["min"] = $rs_org["max"] = "";
			
			for($k = $from; $k < $to; $k += 86400){
				$dd = date("Y-m-d", $k);
				if(!isset($data[$dd])){
					$rs = $rs_org;
					$rs["date"] = $dd;
					$data[$dd] = $rs;
				}
			}
		}

		
		$dates = array_column($data, 'date');
		array_multisort($dates, SORT_ASC, $data);
?>
<table id="corrispettivi_for_woocommerce_table" class="wp-list-table widefat fixed striped posts">
<thead>
	<tr>
		<th scope="col" id="day" class="manage-column tableexport-string" style="text-align:right; vertical-align: bottom"><span><?php _e("Date", corrispettivi_for_woocommerce_domain) ?></span></th>
		<th scope="col" id="total" class="manage-column" style="text-align:right; vertical-align: bottom"><span><?php _e("Total daily payments", corrispettivi_for_woocommerce_domain) ?></span></th>
<?php
		foreach($tax_rates as $k => $v){
			if($k > 0){
?>
		<th scope="col" id="tax_rate_<?php echo esc_attr($k)?>" class="manage-column" style="text-align:right; vertical-align: bottom"><span><?php printf("%s %d%%", __("Tax rate", corrispettivi_for_woocommerce_domain), $k)?></span></th>
<?php
			}
		}
?>
		<th scope="col" id="tax_rate_0" class="manage-column" style="text-align:right; vertical-align: bottom"><span><?php _e("Non-taxable or exempt transactions", corrispettivi_for_woocommerce_domain) ?></span></th>
		<th scope="col" id="tax_rate_-1" class="manage-column" style="text-align:right; vertical-align: bottom"><span><?php _e("Transactions not subject to VAT registration", corrispettivi_for_woocommerce_domain) ?></span></th>
		<th scope="col" id="invoice_number_from" class="manage-column" style="vertical-align: bottom"><span><?php _e("Invoice from No.", corrispettivi_for_woocommerce_domain) ?></span></th>
		<th scope="col" id="invoice_number_to" class="manage-column" style="vertical-align: bottom"><span><?php _e("Invoice to No.", corrispettivi_for_woocommerce_domain) ?></span></th>
	</tr>
</thead>
<tbody>
<?php
		$tot = $tax_rates;
		$tot["tot"] = $tot[-1] = $tot[0] = 0;
		foreach($data as $dd => $rs) {
			$total = $rs["total"];
			$tot["tot"] += $total;
?>
<tr>
<td style="text-align:right" class="tableexport-string"><?php echo esc_attr(wp_date($this->date_format, strtotime($dd))) ?></td>
<td style="text-align:right"><?php echo esc_attr(number_format_i18n($total, 2)) ?></td>
<?php
			foreach($tax_rates as $k => $v){
				$val = isset($rs["tax_rates"][$k]) ? $rs["tax_rates"][$k]["total"] : 0;
?>
<td style="text-align:right"><?php echo esc_attr(number_format_i18n($val, 2)) ?></td>
<?php
				$tot[$k] += $val;
			}
			$val = isset($rs["min"]) ? $rs["min"] : "";
?>
<td class="tableexport-string"><?php echo esc_attr($val) ?></td>
<?php
			$val = isset($rs["max"]) ? $rs["max"] : "";
?>
<td class="tableexport-string"><?php echo esc_attr($val) ?></td>
</tr>
<?php
		}
?>
	</tbody>
	<tfoot>
		<tr><td></td>
		<td style="text-align:right"><?php echo esc_attr(number_format_i18n($tot["tot"], 2)) ?></td>
<?php
		foreach($tax_rates as $k => $v){
?>
		<td style="text-align:right"><?php echo esc_attr(number_format_i18n($tot[$k], 2)) ?></td>
<?php
		}
?><td></td><td></td>
		</tr>
	</tfoot>
	</table>
</div>
<?php
		$this->add_js_and_fields();
?>
<style>
.tableexport-caption{text-align: left; margin-top: 20px}
.tableexport-caption .button-default{background: #2271b1; border-color: #2271b1; color: #fff; text-decoration: none; text-shadow: none; display: inline-block; font-size: 13px; line-height: 2.15384615; min-height: 30px; margin: 0 10px 0 0; padding: 0 10px; cursor: pointer; border-width: 1px; border-style: solid; -webkit-appearance: none; border-radius: 3px; white-space: nowrap; box-sizing: border-box;}
</style>
<script>
	jQuery(function() {
		//TableExport.prototype.defaultButton = "button";
		//TableExport.prototype.bootstrapConfig = ["button", "button-primary", "btn-toolbar"];
		TableExport.prototype.formatConfig.xlsx.buttonContent = '<?php _e( "Export to Excel", corrispettivi_for_woocommerce_domain ) ?>';
		TableExport.prototype.formatConfig.csv.buttonContent = '<?php _e( "Export to CSV", corrispettivi_for_woocommerce_domain ) ?>';
		var table = jQuery("#corrispettivi_for_woocommerce_table").tableExport({formats: ["xlsx", "csv"], filename: "corrispettivi-<?php echo esc_attr($select) ?>"});
	});
</script>
<?php
	}

	public function get_order_data($order){
		$order_data = array();
		$this->tot_parz_s = array();
		$res = $this->get_document_data($order);
		if($res) $order_data[] = $res;
		$order_refunds = $order->get_refunds();
		foreach($order_refunds as $refund) {
			$res = $this->get_document_data($refund, $order);
			if($res) $order_data[] = $res;
		}
		$res = array("data" => $order_data, "tot_parz_s" => $this->tot_parz_s);
		return $res;
	}

	public function get_document_data($order, $parent = null) {
		$document_type = $parent ? "credit_note" : "invoice";
		if($document_type == "invoice"){
			$numbering_enabled = class_exists("WooCommerce_Italian_add_on_plus") && WCPDF_IT()->settings->numerazione_settings->invoice_numbering_enabled;
			$wcpdf_document_type = "invoice";
			$wcpdf_exists = function_exists("wcpdf_get_document");
		} else {
			$numbering_enabled = class_exists("WooCommerce_Italian_add_on_plus") && WCPDF_IT()->settings->numerazione_settings->credit_note_numbering_enabled;
			$wcpdf_document_type = "credit-note";
			$wcpdf_exists = class_exists("WooCommerce_PDF_IPS_Pro");
		}
		$wcpdf_document = $wcpdf_exists ? wcpdf_get_document( $wcpdf_document_type, $order ) : "";

		$number_formatted = "";
		$date_formatted = "";
		$date = "";
		
		$order_id = $this->get_order_id($order);
		//$parent_id = $parent ? $this->get_order_id($parent) : $order_id;
		$parent = $parent ? $parent : $order;
		
		if(!$numbering_enabled && $wcpdf_exists && $wcpdf_document){ //WooCommerce PDF Invoice & Packing Slips
			if($number = $wcpdf_document->get_number($wcpdf_document_type)) {
				$number_formatted = $number->formatted_number;
			}
			$date= $wcpdf_document->get_date($wcpdf_document_type);
			if(is_date($date)) {
				$date = get_date_from_gmt($date, "Y-m-d");
			} elseif($date instanceof DateTime){
				$date = $date->format("Y-m-d");
			} else {
				$date = "";
			}
		} else {
			$document_data = $order->get_meta('_wcpdf_IT_document_data', true);
			if ($document_data) {
				$number_formatted = $document_data["number_formatted"];  
				$number= $document_data["number"];
				$date = $document_data["date"];
			} else {
				$number_formatted = $parent->get_meta('woo_pdf_' . $document_type . '_id', true); //
				if(!empty($number_formatted)){
					$date = $parent->get_meta('woo_pdf_' . $document_type . '_date', true);
				}
			}
		}
		
		if($document_type == "credit_note") {
			$items = $order->get_items('line_item');
			if(!$items) $items = array();
			$fee = $order->get_items('fee');
			if($fee) $items = array_merge($items, $fee);
			$shipping = $order->get_items('shipping');
			if($shipping) $items = array_merge($items, $shipping);
			if(!count($items) && (abs($order->get_total()) == $parent->get_total())) {
				$items = $parent->get_items(array( 'line_item', 'fee', 'shipping' ));
			}
			$country = $this->tax_based_on ? $parent->get_billing_country() : $parent->get_shipping_country();
		} else {
			$items = $order->get_items(array( 'line_item', 'fee', 'shipping' ));
			$country = $this->tax_based_on ? $order->get_billing_country() : $order->get_shipping_country();
		}
		//$tot_parz_s = array();

		if($items) {
			foreach($items as $item) {
				if($item->get_type() == "fee" && $item["name"] == "Imposta di bollo") {
					$impostabollo = $item->get_total();
					if(!isset($this->tot_parz_s[-1])) $this->tot_parz_s[-1] = array("tax" => 0, "total" => 0);
					$this->tot_parz_s[-1]["tax"] += 0;
					$this->tot_parz_s[-1]["total"] += $impostabollo;
					continue;
				}
				$line_total = $item->get_total();
				$tax_total = $item->get_total_tax();
				$tax_rate = 0;
				$tax_class = "";
				if(wc_tax_enabled()){
					$tax_class = $item->get_tax_class();
					$taxes = $item->get_taxes()["total"];
					$taxes = array_filter($taxes, function($v, $k) { return (!is_null($v) && $v !== '') ? $k : 0; }, ARRAY_FILTER_USE_BOTH);
					$taxes = $taxes ? array_keys($taxes) : array(0=>0);
					$tax_id = !empty($taxes) && is_array($taxes) ? reset($taxes) : 0;
					$tax_rate = $tax_id ? WC_Tax::get_rate_percent_value($tax_id) : 0;
					if(!$tax_rate) {
						$calculate_tax_for['country'] = $country;
						$calculate_tax_for['tax_class'] = $tax_class == "inherit" ? "" : $tax_class;
						$tax_rates = WC_Tax::find_rates( $calculate_tax_for );
						$tax_rates = array_shift($tax_rates);
						$tax_rate = ($line_total != 0 && $tax_total == 0) ? 0 : (int)$tax_rates["rate"];
						if($tax_total > 0 && $tax_rate == 0) $tax_rate = round($tax_total / $line_total * 100, 1);
					}
				}
				$tax_rate = ($line_total != 0 && $tax_total == 0) ? 0 : $tax_rate;
				if(!isset($this->tot_parz_s[$tax_rate])) $this->tot_parz_s[$tax_rate] = array("tax" => 0, "total" => 0);
				$this->tot_parz_s[$tax_rate]["tax"] += $tax_total;
				$this->tot_parz_s[$tax_rate]["total"] += ($line_total + $tax_total);
			}
		}

		$res = array();
		if($number_formatted) {
    	$date_formatted = empty($date) ? "" : date("Y-m-d", strtotime($date));
			$res = array("num" => $number_formatted, "data" => $date_formatted, "type" => $document_type);
		}
		return($res);
	}
	
	public function get_order_id($order) {
	if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '2.7', '<' ) ) {
		$order_id = $order->id;
	} else {
		$order_id = $order->get_id();
	}
	return($order_id);
}


}
endif;

$Corrispettivi_for_WooCommerce = new Corrispettivi_for_WooCommerce();

?>
