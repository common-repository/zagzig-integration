<?php
/**
 * @package Zagzig-Integration
 */
/**
 * Plugin Name: Zagzig Integration
 * Plugin URI: https://github.com/zagzigltd/Zagzig.WooCommerce
 * Description: Zagzig Integration allows WooCommerce users to automatically update their Zagzig Partner Portal with sales information so that no leads needs to be updated manually.
 * Version: 1.1
 * Author: Zagzig Ltd
 * Author URI: https://www.zagzig.co.uk
 * License: GPL3
 */


defined( 'ABSPATH' ) or die( "No direct access available." );

class zagzig_repository {

	public static function set_discount_code($code) {
		zagzig_repository::set_value('zagzig_discount_code', $code);
	}

	public static function get_discount_code() {		
		return zagzig_repository::get_value('zagzig_discount_code');
	}

	public static function set_referral_id($referralId) {
		zagzig_repository::set_value('zagzig_referral_id', $referralId);			
	}

	public static function get_referral_id() {			
		return zagzig_repository::get_value('zagzig_referral_id');
	}

	public static function get_username() {
		return get_option("zagzig-username");
	}

	public static function set_username($username) {
		update_option("zagzig-username", $username);
	}

	public static function get_password() {
		return get_option("zagzig-password");
	}

	public static function set_password($password) {
		update_option("zagzig-password", $password);
	}

	public static function get_confirm_url() {
		$result = get_option("zagzig-confirm-url");

		if ( $result == null ) {
			$result = "https://api.zagzig.co.uk/api/Referral/Confirm";
			zagzig_repository::set_confirm_url($result);
		} 

		return $result;
	}

	public static function set_confirm_url($confirm_url) {
		update_option("zagzig-confirm-url", $confirm_url);
	}

	private static function get_value($key) {
		return $_COOKIE[$key];
	}

	private static function set_value($key, $value) {
		if ($value) {
			setcookie($key, $value, 0, '/');			
		} else {			
			setcookie($key, '', 0, '/');
		}
	}
}

class zagzig_integration {

	private $sale_amount = -1;

	public function handle_request() {
		
		$request_uri = $_SERVER['REQUEST_URI'];
		$url = $_GET['url'];
		$referral_id = $_GET['referralId'];
		$discount_code = $_GET['discountCode'];
		$gclid = $_GET['gclid'];
		$debug = $_GET['debug'];
		$command = $_GET['cmd'];

		if ( strpos( $request_uri, '/zagzig' ) !== FALSE ) {

			if ( $debug == 'true' ) {

				if ( $command == 'clear_data' ) {
					zagzig_integration::clear_zagzig_data();
				}

				if ( $command == 'test_connection' ) {

					print_r( zagzig_integration::referral_confirm('d047a2a2-de54-4c6e-93c0-066d44a73b24', 14.5));
				}

				print_r( "referralId: " . zagzig_repository::get_referral_id() . "<br />" );
				print_r( "discountCode: " . zagzig_repository::get_discount_code() . "<br />" );

				die();
			}
		
			if ( $url != null && $referral_id != null && $discount_code != null ) {

				zagzig_repository::set_discount_code( $discount_code );
				zagzig_repository::set_referral_id( $referral_id );

				header('HTTP/1.1 301 Moved Permanently');				
				header('Location: /' . $url . '?gclid=' . $gclid);				

				die();	
			}	
		}
	}

	public function handle_product_add() {

		$referral_id = zagzig_repository::get_referral_id();
		$discount_code = zagzig_repository::get_discount_code();

		if ( $referral_id != null && $discount_code != null) {

			WC()->cart->add_discount($discount_code);
		}
	}

	public function handle_begin_checkout() {

		$this->sale_amount = WC()->cart->subtotal - WC()->cart->discount_total;
	}

	public function handle_order_placed($result) {

		$referral_id = zagzig_repository::get_referral_id();       
		
		if ($referral_id != null) {
			
			zagzig_integration::referral_confirm($referral_id, $this->sale_amount);	
		}	

		zagzig_integration::clear_zagzig_data();

		return $result;
	}

	public function clear_zagzig_data() {
		zagzig_repository::set_referral_id(null);
		zagzig_repository::set_discount_code(null);		
	}

	public function referral_confirm($referral_id, $sale_amount) {
		$message = '{ "ReferralId": "' . $referral_id .'", "DiscountedAmount": ' . $sale_amount . ' }';
			
		$curl = curl_init();

		curl_setopt($curl, CURLOPT_POST, 1);

		curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($curl, CURLOPT_USERPWD, zagzig_repository::get_username() . ':' . zagzig_repository::get_password());

		curl_setopt($curl, CURLOPT_POSTFIELDS, $message);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-type: application/json'));

		curl_setopt($curl, CURLOPT_URL, zagzig_repository::get_confirm_url());			
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		
		if ( $curl_result = curl_exec($curl) ) {
			$result = $curl_result;			
		} else {
			$result = 'Curl error: ' . curl_error($curl);
		}

		curl_close($curl);

		return $result;
	}
}

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

	/** Step 2 (from text above). */
	add_action( 'admin_menu', 'zagzig_plugin_menu' );

	/** Step 1. */
	function zagzig_plugin_menu() {
		add_options_page( 'Zagzig Options', 'Zagzig', 'manage_options', 'zagzig-identifier', 'zagzig_plugin_options' );
	}

	/** Step 3. */
	function zagzig_plugin_options() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}		

		if ( isset( $_POST['zagzig-token'] ) && ( $_POST['zagzig-token'] == 'zagzig-token') ) {

			zagzig_repository::set_username( $_POST['zagzig-username'] );
			zagzig_repository::set_password( $_POST['zagzig-password'] );
			zagzig_repository::set_confirm_url( $_POST['zagzig-confirm-url'] );

			?>
			<div class="updated"><p><strong>Settings saved</strong></p></div>
			<?php
		}

		$username = zagzig_repository::get_username();
		$password = zagzig_repository::get_password();
		$confirm_url = zagzig_repository::get_confirm_url();


		?>

		<div class="wrap">
			<h2>Zagzig Settings</h2>
			<form name="zagzig-settings" method="post" action="">
				<input type="hidden" name="zagzig-token" value="zagzig-token">
				
				<h3>General<h3>

				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="zagzigUsername">Username</label>
							</th>
							<td>
								<input type="text" id="zagzigUsername" name="zagzig-username" value="<?php echo $username; ?>" class="regular-text">
								<p class="description">This is zagzig partner registration email</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="zagzigPassword">Password</label>
							</th>
							<td>
								<input type="password" id="zagzigPassword" name="zagzig-password" value="<?php echo $password; ?>" class="regular-text">
								<p class="description">This is zagzig partner registration password</p>
							</td>
						</tr>
					</tbody>
				</table>
				
				<h3>Advanced<h3>

				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="zagzigConfirmUrl">Confirm Url</label>
							</th>
							<td>								
								<input type="text" id="zagzigConfirmUrl" name="zagzig-confirm-url" value="<?php echo $confirm_url; ?>" class="regular-text code">
								<p class="description">This is zagzig REST API confirm method url</p>
							</td>
						</tr>						
					</tbody>
				</table>				

				<p class="submit">
					<input type="submit" name="Submit" class="button-primary" value="Update" />
				</p>


			</form>
		</div>

		<?php
	}




    
    $instance = new zagzig_integration();

	add_action( 'parse_request', array( $instance, 'handle_request' ));
	add_action( 'woocommerce_add_to_cart', array( $instance, 'handle_product_add' ));
	add_action( 'woocommerce_before_checkout_process', array( $instance, 'handle_begin_checkout' ));
	add_filter( 'woocommerce_payment_successful_result', array( $instance, 'handle_order_placed' ));

}

?>