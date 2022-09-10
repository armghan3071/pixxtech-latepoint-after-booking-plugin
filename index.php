<?php /**
* Plugin Name: PixxTech LatePoint After Booking Plugin
* Plugin URI: https://myinstitute.pk/
* Description: PixxTech LatePoint After Booking to send api message to server.
* Version: 0.1
* Author: Armghan Saeed
* Author URI: https://myinstitute.pk/
**/

define( 'PI_API_URL', 'https://pos.pixxtech.com' );
define( 'PI_WORDPRESS_API_URL', 'https://pos.pixxtech.com/api/wordpress' );
function add_api_page_html() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }
    $pt_options = get_option('pt_options');
    $shops = [];
    $selected_shop = [];
    $con = wp_remote_get( PI_WORDPRESS_API_URL.'/get-all-warehouses');
    if($con){
        $shops = json_decode(wp_remote_retrieve_body($con), true);
    }
    
    ?>
<div class="wrap">
    <?php if(count($shops) > 0){ ?>
    <div class="notice notice-success is-dismissible">
        <p>Shops Loaded</p>
         <pre>
        <!-- <?php print_r($pt_options); ?> -->
    </pre>
    </div>

    <h1><?php echo esc_html( get_admin_page_title() ) ?></h1>
    <p>PixxTech Api Plugin to store booking data onto the server</p>
    <form action="<?php echo menu_page_url( 'pt_api' ) ?>" method="post">
        <?php
            // output security fields for the registered setting "wporg_options"
            settings_fields( 'pt_options' );
            // output setting sections and their fields
            // (sections are registered for "wporg", each field is registered to a specific section)
            do_settings_sections( 'pt_booking_api' );
        ?>
        <div style="margin-bottom:15px;">
            <div style='font-weight:bold; margin-bottom:5px;'>
                <label for="shop">Shop</label>
            </div>
            <div>
                <select class='regular-text' name='pt_options[shop]' required>
                    <option>Select Shop</option>
                    <?php foreach($shops as $s){
                        if($pt_options['shop'] == $s['id']) $selected_shop = $s;
                        ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo ($pt_options['shop'] == $s['id'])? 'selected':''; ?> ><?php echo $s['warehouse_name']; ?></option>
                    <?php } ?>
                    
                </select>
            </div>
        </div>
        <?php
            // output save settings button
            submit_button( __( 'Save Settings', 'SaveSettings' ) ); ?>
    </form>
    <?php }else{ ?>
    <div class="notice notice-error is-dismissible">
        <p>Shops Not Found</p>
    </div>
    <?php } ?>
    <?php if(!empty($selected_shop)){ ?>
        <div>
            <h3>Selected Shop</h3>
            <img src="<?php echo PI_API_URL.'/storage/'.$selected_shop['warehouse_image']; ?>" style="max-width:200px;" />
            <div>
                <p><strong>Name: </strong> <?php echo $selected_shop['warehouse_name']; ?></p>
                <p><strong>Address: </strong> <?php echo $selected_shop['warehouse_address']; ?></p>
                <p><strong>Phone: </strong> <?php echo $selected_shop['warehouse_phone']; ?></p>
                <p><strong>Email: </strong> <?php echo $selected_shop['warehouse_email']; ?></p>
                <p><strong>Website: </strong> <?php echo $selected_shop['warehouse_website']; ?></p>
            </div>
        </div>
    <?php } ?>
    
</div>
<?php   } ?>

<?php 
add_action( 'admin_menu', 'add_api_page' );
function add_api_page() {
    add_menu_page(
        'PixxTech Booking', //Page Title
        'PT Booking', //Menu Title
        'manage_options', //Permissions
        'pixxtech-after-booking', //Slug
        'add_api_page_html', //Callback
        'dashicons-rest-api', //Icon Dash Icons
        20 //Position
    );
}

register_activation_hook( __FILE__, 'plugin_create' );
function plugin_create() {
    add_option('pt_options', array(
        'shop' => '',
    ));
} 



if(isset($_POST['option_page']) && $_POST['option_page'] == 'pt_options'){
    $check = update_option('pt_options', $_POST['pt_options']);
    if($check){
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible">
                <p>Successfully Saved</p>
            </div>';
        });
    }else{
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error is-dismissible">
                <p>Cannot save Options</p>
            </div>';
        });
    }
}


add_action('latepoint_booking_created_frontend', 'SaveBookingOnServer');
function SaveBookingOnServer($booking){
    global $wpdb;
    $pt_options = get_option('pt_options');
    $booking_data['customer']['customer_name'] = $booking->customer->first_name.' '.$booking->customer->last_name;
    $booking_data['customer']['customer_phone'] = $booking->customer->phone;
    $booking_data['customer']['customer_email'] = $booking->customer->email;
    $booking_data['booking_code'] = $booking->booking_code;
    $booking_data['comments'] = $booking->customer_comment;
    $booking_data['price'] = $booking->price;
    $booking_data['duration'] = $booking->duration;
    $booking_data['coupon_discount'] = $booking->coupon_discount;
    $booking_data['payment_portion'] = $booking->payment_portion;
    $booking_data['start_time'] = $booking->start_datetime_utc;
    $booking_data['end_time'] = $booking->end_datetime_utc;

    $services_ids = [];
    if(!empty($booking->service_extras_ids)){
        $service_extras_ids = explode(",", $booking->service_extras_ids);
        foreach ($service_extras_ids as $tmp) {
            $ids = explode(":", $tmp);
            $services_ids[] = $ids[0];
        }
    }
    $serv_ids = implode(",", $services_ids);
    $query = "SELECT se.name, se.charge_amount as price, se.short_description, sbe.price AS total, sbe.quantity  FROM ".$wpdb->prefix."latepoint_service_extras AS se
    INNER JOIN ".$wpdb->prefix."latepoint_bookings_service_extras AS sbe ON sbe.service_extra_id=se.id
    WHERE sbe.booking_id=$booking->id AND se.id IN ($serv_ids)";

    $results = $wpdb->get_results($query);
    $booking_data['services'] = $results;
    //$res['query'] = $query;
    //$res['shop'] = $pt_options['shop'];
    

    if(!empty($pt_options['shop'])){
        $args = [
            'shop' => $pt_options['shop'],
            'booking' => $booking_data,
            
        ];
        
        $con_wa = wp_remote_post( PI_WORDPRESS_API_URL.'/save-booking-on-server', ['body' => $args] );
        //$c = update_option('pt_options', $res);
        if($con_wa){
            $check = json_decode(wp_remote_retrieve_body($con_wa), true);
            $res['body'] = $check;
            //$c = update_option('pt_options', $res);
            if($check['success'] == true){
                return true;
            }else{
                return false;
            }
        }
        
    }
    //$c = update_option('pt_options', $res);
    return false;
}


?>