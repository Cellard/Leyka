<?php if( !defined('WPINC') ) die;
/**
 * Leyka_Chronopay_Gateway class
 */

class Leyka_Chronopay_Gateway extends Leyka_Gateway {

    protected static $_instance;

    protected function _set_attributes() {

        $this->_id = 'chronopay';
        $this->_title = __('Chronopay', 'leyka');
        $this->_docs_link = '//leyka.te-st.ru/docs/chronopay/';
        $this->_admin_ui_column = 1;
        $this->_admin_ui_order = 20;

    }

    protected function _set_options_defaults() {

        if($this->_options) { // Create Gateway options, if needed
            return;
        }

        $this->_options = array(
            'chronopay_shared_sec' => array(
                'type' => 'text', // html, rich_html, select, radio, checkbox, multi_checkbox
                'value' => '',
                'default' => '',
                'title' => __('Chronopay shared_sec', 'leyka'),
                'description' => __('Please, enter your Chronopay shared_sec value here. It can be found in your contract.', 'leyka'),
                'required' => 1,
                'is_password' => true,
                'placeholder' => __('Ex., 4G0i8590sl5Da37I', 'leyka'),
                'list_entries' => array(), // For select, radio & checkbox fields
                'validation_rules' => array(), // List of regexp?..
            ),
            'chronopay_ip' => array(
                'type' => 'text', // html, rich_html, select, radio, checkbox, multi_checkbox
                'value' => '',
                'default' => '185.30.16.166',
                'title' => __('Chronopay IP', 'leyka'),
                'description' => __('IP address to check for requests.', 'leyka'),
                'required' => 1,
                'placeholder' => __('Ex., 185.30.16.166', 'leyka'),
                'list_entries' => array(), // For select, radio & checkbox fields
                'validation_rules' => array(), // List of regexp?..
            ),
            'chronopay_use_payment_uniqueness_control' => array(
                'type' => 'checkbox', // html, rich_html, select, radio, checkbox, multi_checkbox
                'value' => '',
                'default' => 0,
                'title' => __('Use the payments uniqueness control', 'leyka'),
                'description' => __('Check if you use Chronopay payment uniqueness control setting.', 'leyka'),
                'required' => false,
                'placeholder' => '',
                'list_entries' => array(), // For select, radio & checkbox fields
                'validation_rules' => array(), // List of regexp?..
            ),
        );

    }

    protected function _initialize_pm_list() {
        if(empty($this->_payment_methods['chronopay_card'])) {
            $this->_payment_methods['chronopay_card'] = Leyka_Chronopay_Card::get_instance();
        }
    }

    public function process_form($gateway_id, $pm_id, $donation_id, $form_data) {
    }

    public function submission_redirect_url($current_url, $pm_id) {
        return 'https://payments.chronopay.com/';
    }

    public function submission_form_data($form_data_vars, $pm_id, $donation_id) {

        if(false === strpos($pm_id, 'chronopay')) {
            return $form_data_vars; // It's not our PM
        }

        if(is_wp_error($donation_id)) { /** @var WP_Error $donation_id */
            return array('status' => 1, 'message' => $donation_id->get_error_message());
        } else if( !$donation_id ) {
            return array('status' => 1, 'message' => __('The donation was not created due to error.', 'leyka'));
        }

        $donation = new Leyka_Donation($donation_id);

        if(empty($_POST['leyka_recurring'])) { // Single donation

            $donation->payment_type = 'single';
            $chronopay_product_id = leyka_options()->opt($pm_id.'_product_id_'.$donation->currency);

        } else { // Recurring donation

            $donation->payment_type = 'rebill';
            $chronopay_product_id = leyka_options()->opt($pm_id.'_rebill_product_id_'.$donation->currency);

        }

        $sharedsec = leyka_options()->opt('chronopay_shared_sec');
        $price = number_format((float)$donation->amount, 2,'.','');

        $country = $donation->currency == 'rur' ? 'RUS' : '';

        $form_data_vars =  array(
            'product_id' => $chronopay_product_id,
            'product_price' => $price,
            'product_price_currency' => $this->_get_currency_id($donation->currency),
            'cs1' => esc_attr($donation->title), // Purpose of the donation
            'cs2' => $donation_id, // Purpose of the donation
            'order_id' => $donation_id,
            'cb_url' => home_url('leyka/service/'.$this->_id.'/response/'), // URL for the gateway callbacks
            'cb_type' => 'P',
            'success_url' => leyka_get_success_page_url(),
            'decline_url' => leyka_get_failure_page_url(),

            'sign' => md5($chronopay_product_id.'-'.$price
                .(leyka_options()->opt('chronopay_use_payment_uniqueness_control') ? '-'.$donation_id : '')
                .'-'.$sharedsec),
            'language' => get_locale() == 'ru_RU' ? 'ru' : 'en',
            'email' => $donation->donor_email,
        );

        if($country) {
            $form_data_vars['country'] = $country;
        }

        return $form_data_vars;

    }

    protected function _get_currency_id($leyka_currency_id){

        $chronopay_currencies = array('rur' => 'RUB', 'usd' => 'USD', 'eur' => 'EUR');

        return isset($chronopay_currencies[$leyka_currency_id]) ? $chronopay_currencies[$leyka_currency_id] : 'RUB';

    }

    public function log_gateway_fields($donation_id) {
    }

    public function _handle_service_calls($call_type = '') {

        $client_ip = leyka_get_client_ip();

        // Test for gateway's IP:
        if(
            leyka_options()->opt('chronopay_ip') &&
            !in_array($client_ip, explode(',', leyka_options()->opt('chronopay_ip')))
        ) { // Security fail

            $message = __("This message has been sent because a call to your ChronoPay function was made from an IP that did not match with the one in your Chronopay gateway setting. This could mean someone is trying to hack your payment website. The details of the call are below.", 'leyka')."\n\r\n\r";

            $message .= "POST:\n\r".print_r($_POST, true)."\n\r\n\r";
            $message .= "GET:\n\r".print_r($_GET, true)."\n\r\n\r";
            $message .= "SERVER:\n\r".print_r($_SERVER, true)."\n\r\n\r";
            $message .= "IP: ".print_r($client_ip, true)."\n\r\n\r";
            $message .= "Chronopay IP setting value: ".print_r(leyka_options()->opt('chronopay_ip'),true)."\n\r\n\r";

            wp_mail(get_option('admin_email'), __('Chronopay IP check failed!', 'leyka'), $message);
            status_header(200);
            die(1);
        }

        // Test for e-sign:
        $sharedsec = leyka_options()->opt('chronopay_shared_sec');
        $customer_id = isset($_POST['customer_id'])? trim(stripslashes($_POST['customer_id'])) : '';
        $transaction_id = isset($_POST['transaction_id']) ? trim(stripslashes($_POST['transaction_id'])): '';
        $transaction_type = isset($_POST['transaction_type']) ? trim(stripslashes($_POST['transaction_type'])) : '';
        $total = isset($_POST['total']) ? trim(stripslashes($_POST['total'])) : '';
        $sign = md5($sharedsec.$customer_id.$transaction_id.$transaction_type.$total);

        if(empty($_POST['sign']) || $sign != trim(stripslashes($_POST['sign']))) { // Security fail

            $message = __("This message has been sent because a call to your ChronoPay function was made by a server that did not have the correct security key.  This could mean someone is trying to hack your payment site.  The details of the call are below.", 'leyka')."\n\r\n\r";

            $message .= "POST:\n\r".print_r($_POST, true)."\n\r\n\r";
            $message .= "GET:\n\r".print_r($_GET, true)."\n\r\n\r";
            $message .= "SERVER:\n\r".print_r($_SERVER, true)."\n\r\n\r";

            wp_mail(get_option('admin_email'), __('Chronopay security key check failed!', 'leyka'), $message);
            status_header(200);
            die(2);
        }

        $_POST['cs2'] = (int)$_POST['cs2'];
        $donation = new Leyka_Donation($_POST['cs2']);

        if( !$donation->id || !$donation->campaign_id ) {

            $message = __("This message has been sent because a call to your ChronoPay callbacks URL was made with a donation ID parameter (POST['order_id']) that Leyka is unknown of. The details of the call are below.", 'leyka')."\n\r\n\r";

            $message .= "POST:\n\r".print_r($_POST, true)."\n\r\n\r";
            $message .= "GET:\n\r".print_r($_GET, true)."\n\r\n\r";
            $message .= "SERVER:\n\r".print_r($_SERVER, true)."\n\r\n\r";
            $message .= "Donation ID: ".$_POST['cs2']."\n\r\n\r";

            wp_mail(get_option('admin_email'), __('Chronopay gives unknown donation ID parameter!', 'leyka'), $message);
            status_header(200);
            die(3);
        }

        $_POST['currency'] = strtolower($_POST['currency']);
        if($_POST['currency'] == 'rub') {
            $currency_string = 'rur';
        } else if($_POST['currency'] == 'usd') {
            $currency_string = 'usd';
        } else if($_POST['currency'] == 'eur') {
            $currency_string = 'eur';
        } else {

            $message = __("This message has been sent because a call to your ChronoPay callbacks URL was made with a currency parameter (POST['currency']) that Leyka is unknown of. The details of the call are below.", 'leyka')."\n\r\n\r";

            $message .= "POST:\n\r".print_r($_POST, true)."\n\r\n\r";
            $message .= "GET:\n\r".print_r($_GET, true)."\n\r\n\r";
            $message .= "SERVER:\n\r".print_r($_SERVER, true)."\n\r\n\r";

            wp_mail(get_option('admin_email'), __('Chronopay gives unknown currency parameter!', 'leyka'), $message);
            status_header(200);
            die(4);
        }

        // Store donation data - rebill payment:
        if(apply_filters(
            'leyka_chronopay_callback_is_recurring',
            (
                leyka_options()->opt('chronopay_card_rebill_product_id_'.$currency_string) &&
                $_POST['product_id'] == leyka_options()->opt('chronopay_card_rebill_product_id_'.$currency_string)
            ),
            $_POST['product_id']
        )) {

            if($transaction_type == 'Purchase') { // Initial rebill payment

                if($donation->status != 'funded') {

                    $donation->add_gateway_response($_POST);
                    $donation->status = 'funded';
                    $donation->type = 'rebill';

                    if( !$donation->donor_email && !empty($_POST['email']) )
                        $donation->donor_email = $_POST['email'];

                    Leyka_Donation_Management::send_all_emails($donation->id);

                    // Save donor's customer_id parameter to link this donation to all others in this recurrent chain:
                    $donation->chronopay_customer_id = $customer_id;
                }

            } else if($transaction_type == 'Rebill') { // Rebill payment

                $donation_id = Leyka_Donation::add(array(
                    'status' => 'funded',
                    'payment_type' => 'rebill',
//                    '' => '',
                ));

                $donation = new Leyka_Donation($donation_id);

                $init_recurring_payment = $this->get_init_recurrent_donation($customer_id);

                $donation->add_gateway_response($_POST);
                $donation->chronopay_customer_id = $customer_id;
                $donation->payment_title = $init_recurring_payment->title;
                $donation->campaign_id = $init_recurring_payment->campaign_id;
                $donation->payment_method_id = $init_recurring_payment->pm_id;
                $donation->gateway_id = $init_recurring_payment->gateway_id;
                $donation->donor_name = $init_recurring_payment->donor_name;
                $donation->donor_email = $init_recurring_payment->donor_email;
                $donation->amount = $init_recurring_payment->amount;
                $donation->currency = $init_recurring_payment->currency;

                // If init donation was made before the commission was set, apply a commission to the recurring one:
                if(
                    $init_recurring_payment->amount == $init_recurring_payment->amount_total &&
                    $donation->amount == $donation->amount_total &&
                    leyka_get_pm_commission($donation->pm_full_id) > 0.0
                ) {
                    $donation->amount_total = leyka_calculate_donation_total_amount($donation);
                }
                

                if($donation->status != 'funded') {
                    $donation->status = 'funded';
                }
                if($donation->type != 'rebill') {
                    $donation->type = 'rebill';
                }

                Leyka_Donation_Management::send_all_emails($donation_id);
            }

        } else if( // Single payment. For now, processing is just like initial rebills
            leyka_options()->opt('chronopay_card_product_id_'.$currency_string) &&
            $_POST['product_id'] == leyka_options()->opt('chronopay_card_product_id_'.$currency_string)
        ) {

            if($donation->status != 'funded') {

                $donation->add_gateway_response($_POST);
                $donation->status = 'funded';
                if( !$donation->donor_email && !empty($_POST['email']) )
                    $donation->donor_email = $_POST['email'];

                Leyka_Donation_Management::send_all_emails($donation->id);

                // Save donor's customer_id parameter.. just because we're scrupulous 0:)
                $donation->chronopay_customer_id = $customer_id;
            }
        }

        status_header(200);
        die(0);

    }

    public function get_init_recurrent_donation($recurring) {

        if(is_a($recurring, 'Leyka_Donation')) {
            $recurring = $recurring->chronopay_customer_id;
        } elseif(empty($recurring)) {
            return false;
        }

        $init_donation_post = get_posts(array( // Get init recurrent payment with customer_id given
            'posts_per_page' => 1,
            'post_type' => Leyka_Donation_Management::$post_type,
            'post_status' => 'funded',
//            'post_parent' => 0, /** @todo Until Chronopay recurring posts structure will be refactored */
            'meta_query' => array(
                'RELATION' => 'AND',
                array(
                    'key' => '_chronopay_customer_id',
                    'value' => $recurring,
                    'compare' => '=',
                ),
                array(
                    'key' => 'leyka_payment_type',
                    'value' => 'rebill',
                    'compare' => '=',
                ),
            ),
            'orderby' => 'date',
            'order' => 'ASC',
        ));

        return count($init_donation_post) ? new Leyka_Donation($init_donation_post[0]->ID) : false;

    }

    public function cancel_recurring_subscription(Leyka_Donation $donation) {

        $ch = curl_init();

        $product_id = leyka_options()->opt($donation->payment_method_id.'_product_id_'.$donation->currency);
        $hash = md5(leyka_options()->opt('chronopay_shared_sec').'-7-'.$product_id);

        curl_setopt_array($ch, array(
            CURLOPT_URL => 'https://gate.chronopay.com/',
            CURLOPT_HEADER => 0,
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => "<request>
                <Opcode>7</Opcode>
                <hash>$hash</hash>
                <Customer>{$donation->chronopay_customer_id}</Customer>
                <Product>$product_id</Product>
            </request>",
        ));

        $result = curl_exec($ch);
        if($result === false) {

            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            die( json_encode(array('status' => 0, 'message' => $error." ($errno)")) );

        } else {

            $donation->add_gateway_response($result);

            $p = xml_parser_create();
            $response_xml = array();
            xml_parse_into_struct($p, $result, $response_xml);
            xml_parser_free($p);

            $response_ok = false;
            $response_text = '';
            $response_code = 0;
            foreach($response_xml as $index => $tag) {

                if(strtolower($tag['tag']) == 'code' && $tag['type'] == 'complete') {
                    $response_ok = $tag['value'] == '000';
                    if( !$response_ok ) {
                        $response_code = $tag['value'];
                        $response_text = $response_xml[$index+1]['value'];
                    }

                    break;
                }
            }

            curl_close($ch);
            if($response_ok) {

                // Save the fact that recurrents has been cancelled:
                $init_recurrent_donation = $this->get_init_recurrent_donation($donation);
                $init_recurrent_donation->recurrents_cancelled = true;

                die(json_encode(array('status' => 1, 'message' => __('Recurring subscription cancelled.', 'leyka'))));

            } else {
                die(json_encode(array('status' => 0, 'message' => sprintf(__('Error on a gateway side: %s', 'leyka'), $response_text." (code $response_code)"))));
            }

        }

    }

    public function get_gateway_response_formatted(Leyka_Donation $donation) {

        if( !$donation->gateway_response ) {
            return array();
        }

        $response_vars = maybe_unserialize($donation->gateway_response);
        if( !$response_vars || !is_array($response_vars) ) {
            return array();
        }

        return array(
            __('Operation status:', 'leyka') => $response_vars['transaction_type'],
            __('Transaction ID:', 'leyka') => $response_vars['transaction_id'],
            __('Full donation amount:', 'leyka') => $response_vars['total'].' '.$donation->currency_label,
            __("Gateway's donor ID:", 'leyka') => $response_vars['customer_id'],
            __('Response date:', 'leyka') => date('d.m.Y, H:i:s', strtotime($response_vars['date']))
        );

    }

    public function display_donation_specific_data_fields($donation = false) {

        if($donation) { // Edit donation page displayed

            $donation = leyka_get_validated_donation($donation);?>

            <label><?php _e('Chronopay customer ID', 'leyka');?>:</label>
            <div class="leyka-ddata-field">

                <?php if($donation->type == 'correction') {?>
                    <input type="text" id="chronopay-customer-id" name="chronopay-customer-id" placeholder="<?php _e('Enter Chronopay Customer ID', 'leyka');?>" value="<?php echo $donation->chronopay_customer_id;?>">
                <?php } else {?>
                    <span class="fake-input"><?php echo $donation->chronopay_customer_id;?></span>
                <?php }?>
            </div>

        <?php } else { // New donation page displayed ?>

            <label for="chronopay-customer-id"><?php _e('Chronopay customer ID', 'leyka');?>:</label>
            <div class="leyka-ddata-field">
                <input type="text" id="chronopay-customer-id" name="chronopay-customer-id" placeholder="<?php _e('Enter Chronopay Customer ID', 'leyka');?>" value="" />
            </div>
            <?php
        }

    }

    public function get_specific_data_value($value, $field_name, Leyka_Donation $donation) {
        switch($field_name) {
            case 'chronopay_customer_id': return get_post_meta($donation->id, '_chronopay_customer_id', true);
            default: return $value;
        }
    }

    public function set_specific_data_value($field_name, $value, Leyka_Donation $donation) {
        switch($field_name) {
            case 'chronopay_customer_id':
                return update_post_meta($donation->id, '_chronopay_customer_id', $value);
            default: return false;
        }
    }

    public function save_donation_specific_data(Leyka_Donation $donation) {
        if(
            isset($_POST['chronopay-customer-id']) &&
            $donation->chronopay_customer_id != $_POST['chronopay-customer-id']
        ) {
            $donation->chronopay_customer_id = $_POST['chronopay-customer-id'];
        }
    }

    public function add_donation_specific_data($donation_id, array $donation_params) {
        if( !empty($donation_params['chronopay_customer_id']) ) {
            update_post_meta($donation_id, '_chronopay_customer_id', $donation_params['chronopay_customer_id']);
        }
    }

} // gateway class end


class Leyka_Chronopay_Card extends Leyka_Payment_Method {

    protected static $_instance;

    protected function _set_attributes() {

        $this->_id = 'chronopay_card';
        $this->_gateway_id = 'chronopay';

        $this->_label_backend = __('Bank card', 'leyka');
        $this->_label = __('Bank card', 'leyka');

        // The description won't be setted here - it requires the PM option being configured at this time (which is not)

        $this->_icons = apply_filters('leyka_icons_'.$this->_gateway_id.'_'.$this->_id, array(
            LEYKA_PLUGIN_BASE_URL.'gateways/chronopay/icons/visa.png',
            LEYKA_PLUGIN_BASE_URL.'gateways/chronopay/icons/master.png',
            LEYKA_PLUGIN_BASE_URL.'gateways/chronopay/icons/mir.png',
        ));

        $this->_submit_label = __('Donate', 'leyka');

        $this->_default_currency = 'rur';

    }

    protected function _set_dynamic_attributes() {

        if(leyka_options()->opt('chronopay_card_product_id_rur')) {
            $this->_supported_currencies[] = 'rur';
        }
        if(leyka_options()->opt('chronopay_card_product_id_usd')) {
            $this->_supported_currencies[] = 'usd';
        }
        if(leyka_options()->opt('chronopay_card_product_id_eur')) {
            $this->_supported_currencies[] = 'eur';
        }

    }

    protected function _set_options_defaults() {

        if($this->_options) {
            return;
        }

        $this->_options = array(
            $this->full_id.'_description' => array(
                'type' => 'html',
                'default' => __('Chronopay allows a simple and safe way to pay for goods and services with bank cards through internet. You will have to fill a payment form, you will be redirected to the <a href="http://www.chronopay.com/ru/">Chronopay</a> secure payment page to enter your bank card data and to confirm your payment.', 'leyka'),
                'title' => __('Chronopay bank card payment description', 'leyka'),
                'description' => __('Please, enter Chronopay gateway description that will be shown to the donor when this payment method will be selected for using.', 'leyka'),
                'required' => 0,
                'validation_rules' => array(), // List of regexp?..
            ),
            'chronopay_card_product_id_rur' => array(
                'type' => 'text',
                'default' => '',
                'title' => __('Chronopay product_id for RUR', 'leyka'),
                'description' => __('Please, enter Chronopay product_id for RUR currency.', 'leyka'),
                'required' => 0,
                'validation_rules' => array(), // List of regexp?..
            ),
            'chronopay_card_product_id_usd' => array(
                'type' => 'text',
                'default' => '',
                'title' => __('Chronopay product_id for USD', 'leyka'),
                'description' => __('Please, enter Chronopay product_id for USD currency.', 'leyka'),
                'required' => 0,
                'validation_rules' => array(), // List of regexp?..
            ),
            'chronopay_card_product_id_eur' => array(
                'type' => 'text',
                'default' => '',
                'title' => __('Chronopay product_id for EUR', 'leyka'),
                'description' => __('Please, enter Chronopay product_id for EUR currency.', 'leyka'),
                'required' => 0,
                'validation_rules' => array(), // List of regexp?..
            ),
            'chronopay_card_rebill_product_id_rur' => array(
                'type' => 'text',
                'default' => '',
                'title' => __('Chronopay product_id for rebills in RUR', 'leyka'),
                'description' => __('Please, enter Chronopay product_id for rebills in RUR currency.', 'leyka'),
                'required' => 0,
                'validation_rules' => array(), // List of regexp?..
            ),
            'chronopay_card_rebill_product_id_usd' => array(
                'type' => 'text',
                'default' => '',
                'title' => __('Chronopay product_id for rebills in USD', 'leyka'),
                'description' => __('Please, enter Chronopay product_id for rebills in USD currency.', 'leyka'),
                'required' => 0,
                'validation_rules' => array(), // List of regexp?..
            ),
            'chronopay_card_rebill_product_id_eur' => array(
                'type' => 'text',
                'default' => '',
                'title' => __('Chronopay product_id for rebills in EUR', 'leyka'),
                'description' => __('Please, enter Chronopay product_id for rebills in EUR currency.', 'leyka'),
                'required' => 0,
                'validation_rules' => array(), // List of regexp?..
            ),
        );

    }

    public function has_recurring_support() { // Support recurring donations only if both single & recurring options set
        return ( !!leyka_options()->opt('chronopay_card_rebill_product_id_rur') && !!leyka_options()->opt('chronopay_card_product_id_rur') ) ||
            ( !!leyka_options()->opt('chronopay_card_rebill_product_id_usd') && !!leyka_options()->opt('chronopay_card_product_id_usd') ) ||
            ( !!leyka_options()->opt('chronopay_card_rebill_product_id_eur') && !!leyka_options()->opt('chronopay_card_product_id_eur') );
    }

}

function leyka_add_gateway_chronopay() { // Use named function to leave a possibility to remove/replace it on the hook
    leyka()->add_gateway(Leyka_Chronopay_Gateway::get_instance());
}
add_action('leyka_init_actions', 'leyka_add_gateway_chronopay');