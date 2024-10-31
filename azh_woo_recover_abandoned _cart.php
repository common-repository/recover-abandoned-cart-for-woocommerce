<?php
/*
  Plugin Name: Recover Abandoned Cart for WooCommerce
  Description: Recover Abandoned Cart for WooCommerce
  Author: azexo
  Author URI: http://azexo.com
  Version: 1.27.1
  Text Domain: azm
 */

add_action('plugins_loaded', 'azm_woo_rac_plugins_loaded');

function azm_woo_rac_plugins_loaded() {
    load_plugin_textdomain('azm', FALSE, basename(dirname(__FILE__)) . '/languages/');
}

add_action('admin_notices', 'azm_woo_rac_admin_notices');

function azm_woo_rac_admin_notices() {
    if (!defined('AZM_VERSION')) {
        $plugin_data = get_plugin_data(__FILE__);
        print '<div class="updated notice error is-dismissible"><p>' . $plugin_data['Name'] . ': ' . __('please install <a href="https://codecanyon.net/item/marketing-automation-by-azexo/21402648">Marketing Automation by AZEXO</a> plugin.', 'azm') . '</p><button class="notice-dismiss" type="button"><span class="screen-reader-text">' . esc_html__('Dismiss this notice.', 'azm') . '</span></button></div>';
    }
}

register_activation_hook(__FILE__, 'azm_woo_rac_activate');

function azm_woo_rac_activate() {
    global $wpdb;
    $collate = '';

    if ($wpdb->has_cap('collation')) {
        $collate = $wpdb->get_charset_collate();
    }

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}azm_woo_abandoned_carts (
                visitor_id varchar(32) NOT NULL,
                abandoned_timestamp int(11) unsigned NOT NULL,
                recovered_timestamp int(11) unsigned,
                order_id int(11) unsigned,
                user_id int(11) unsigned,
                cart_contents text NOT NULL,
                cart_total decimal(10,2) unsigned,
                status varchar(20),
                PRIMARY KEY abandoned_cart (visitor_id, abandoned_timestamp),
                KEY user (user_id)
    ) $collate;");
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}azm_woo_abandoned_carts_rules (
                visitor_id varchar(32) NOT NULL,
                abandoned_timestamp int(11) unsigned NOT NULL,
                rule_id int(11) NOT NULL,
                status varchar(11),
                sent_timestamp int(11) unsigned,
                open_timestamp int(11) unsigned,
                click_timestamp int(11) unsigned,
                unsubscribe_timestamp int(11) unsigned,
                PRIMARY KEY abandoned_cart_rule (visitor_id, abandoned_timestamp, rule_id),
                KEY rule (rule_id)
    ) $collate;");

    if (!azm_woo_rac_get_defualt_campaign()) {
        azm_woo_rac_create_defualt_campaign();
    }
}

add_filter('azr_settings', 'azm_woo_rac_settings', 12);

function azm_woo_rac_settings($azr) {
    global $wpdb;
    $azr['conditions']['has_abandoned_cart'] = array(
        'name' => __('Has abandoned cart', 'azm'),
        'group' => __('Visitor', 'azm'),
        'helpers' => '<div class="azr-tokens"><label>' . __('Available tokens for template:', 'azm') . '</label><input type="text" value="{cart_abandoned_date}"/><input type="text" value="{cart_contents}"/></div>',
        'query_where' => true,
        'required_context' => array('visitors'),
        'parameters' => array(
            'cart_date' => array(
                'type' => 'dropdown',
                'label' => __('Cart date', 'azm'),
                'required' => true,
                'options' => array(
                    'is_within' => __('Is within', 'azm'),
                    'is_not_within' => __('Is not within', 'azm'),
                ),
                'where_clauses' => array(
                    'is_within' => "v.visitor_id IN (SELECT DISTINCT ac.visitor_id FROM {$wpdb->prefix}azm_woo_abandoned_carts as ac WHERE (ac.status = 'abandoned' OR (ac.user_id IS NULL AND ac.status = 'order-placed')) AND ac.abandoned_timestamp >= UNIX_TIMESTAMP(NOW() - INTERVAL {days} DAY) {{AND ac.visitor_id IN ({visitor_id})}})",
                    'is_not_within' => "v.visitor_id IN (SELECT DISTINCT ac.visitor_id FROM {$wpdb->prefix}azm_woo_abandoned_carts as ac WHERE (ac.status = 'abandoned' OR (ac.user_id IS NULL AND ac.status = 'order-placed')) AND ac.abandoned_timestamp < UNIX_TIMESTAMP(NOW() - INTERVAL {days} DAY) {{AND ac.visitor_id IN ({visitor_id})}})",
                ),
                'default' => 'is_within',
            ),
            'days' => array(
                'type' => 'days_with_units',
                'label' => __('Days', 'azm'),
                'required' => true,
            ),
        ),
    );
    return $azr;
}

add_action('woocommerce_add_to_cart', 'azm_woo_rac_add_to_cart', 30, 6);

function azm_woo_rac_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    global $azm_woo_create_cart;
    if (!$azm_woo_create_cart) {
        global $wpdb;
        $visitor_id = azr_get_current_visitor();
        $user_id = get_current_user_id();
        $user_id = $user_id ? $user_id : 'NULL';
        $timestamp = time();
        $cart_contents = json_encode(wc_clean(WC()->cart->get_cart_for_session()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cart_total = WC()->cart->total;
//        if (is_user_logged_in()) {
//            $cart_contents = WC()->cart->get_cart_for_session();
//            $user_id = get_current_user_id();
//        } else {
//            $customer_id = WC()->session->get_session_cookie()[0];
//            $cart_contents = unserialize(WC()->session->get_session($customer_id)['cart']);
//        }
//        $cart = azm_woo_create_cart($cart_contents);
//        $cart_total = $cart->total;
        $last_abandoned_cart = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}azm_woo_abandoned_carts WHERE status = 'abandoned' AND visitor_id = '$visitor_id' AND abandoned_timestamp <= $timestamp ORDER BY abandoned_timestamp DESC LIMIT 1", ARRAY_A);
        if (empty($last_abandoned_cart)) {
            $wpdb->query("INSERT IGNORE INTO {$wpdb->prefix}azm_woo_abandoned_carts (visitor_id, abandoned_timestamp, status, user_id, cart_contents, cart_total) VALUES ('$visitor_id', $timestamp,  'abandoned', $user_id, '$cart_contents', $cart_total)");
        } else {
            $abandoned_timestamp = $last_abandoned_cart['abandoned_timestamp'];
            $wpdb->query("UPDATE {$wpdb->prefix}azm_woo_abandoned_carts SET abandoned_timestamp = $timestamp, user_id = $user_id, cart_contents = '$cart_contents', cart_total = $cart_total WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp");
        }


//        $cart_contents = array();
//        if ($user_id) {
//            if (get_current_user_id() == $user_id) {
//                $cart_contents = WC()->cart->get_cart_for_session();
//            } else {
//                $cart = get_user_meta($user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true);
//                if (isset($cart['cart'])) {
//                    $cart_contents = $cart['cart'];
//                }
//            }
//        } else {
//            if (!WC()->session) {
//                $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
//                WC()->session = new $session_class();
//                WC()->session->init();
//            }
//
//            $session = WC()->session->get_session($customer_id);
//            if (isset($session['cart'])) {
//                $cart_contents = unserialize($session['cart']);
//            }
//        }
    }
}

function azm_woo_rac_cart_contents_html($lines, $admin = false) {
    global $woocommerce;
    $cart_contents = '';
    if ($woocommerce) {
        $cart_url = add_query_arg('click', 'click', wc_get_page_permalink('cart'));
        $cart = azm_woo_create_cart($lines);
        $lines = $cart->get_cart_contents();
        $cart_contents = '<table class="' . ($admin ? 'widefat striped' : '') . '" border="0" cellpadding="10" cellspacing="0" style="width: 100%">
                <tr>
                <th>' . __("Item", 'azm') . '</th>
                <th>' . __("Name", 'azm') . '</th>
                <th>' . __("Price", 'azm') . '</th>
                <th>' . __("Quantity", 'azm') . '</th>
                <th>' . __("Line Subtotal", 'azm') . '</th>
                </tr>';
        foreach ($lines as $line) {
            $product_id = $line['product_id'];
            $product = wc_get_product($product_id);
            $product_name = $product->get_title();
            if (!empty($line['variation_id'])) {
                $variation_id = $line['variation_id'];
                $variation = wc_get_product($variation_id);
                $name = $variation->get_formatted_name();
                $explode_all = explode("&ndash;", $name);
                if (version_compare($woocommerce->version, '3.0.0', ">=")) {
                    $wcap_sku = '';
                    if ($variation->get_sku()) {
                        $wcap_sku = "SKU: " . $variation->get_sku() . "<br>";
                    }
                    $wcap_get_formatted_variation = wc_get_formatted_variation($variation, true);

                    $add_product_name = $product_name . ' - ' . $wcap_sku . $wcap_get_formatted_variation;

                    $pro_name_variation = (array) $add_product_name;
                } else {
                    $pro_name_variation = array_slice($explode_all, 1, -1);
                }
                $product_name_with_variable = '';
                $explode_many_varaition = array();
                foreach ($pro_name_variation as $pro_name_variation_key => $pro_name_variation_value) {
                    $explode_many_varaition = explode(",", $pro_name_variation_value);
                    if (!empty($explode_many_varaition)) {
                        foreach ($explode_many_varaition as $explode_many_varaition_key => $explode_many_varaition_value) {
                            $product_name_with_variable = $product_name_with_variable . html_entity_decode($explode_many_varaition_value) . "<br>";
                        }
                    } else {
                        $product_name_with_variable = $product_name_with_variable . html_entity_decode($explode_many_varaition_value) . "<br>";
                    }
                }
                $product_name = $product_name_with_variable;
            }
            $cart_contents .= '<tr align="center">
                    <td> <a href="' . $cart_url . '"> <img src="' . wp_get_attachment_url(get_post_thumbnail_id($product_id)) . '" alt="" height="42" width="42" /> </a></td>
                    <td> <a href="' . $cart_url . '">' . $product_name . '</a></td>
                    <td> ' . wc_price($line['data']->get_display_price()) . '</td>
                    <td> ' . $line['quantity'] . '</td>
                    <td> ' . wc_price($line['line_total']) . '</td>
                </tr>';
        }
        $cart_contents .= '<tr align="center">
                <td> </td>
                <td> </td>
                <td> </td>
                <td>' . __("Cart Total:", 'azm') . '</td>
                <td> ' . wc_price($cart->total) . '</td>
            </tr>';
        $cart_contents .= '</table>';
    }
    return $cart_contents;
}

add_filter('azm_send_email_template', 'azm_woo_rac_send_email_template', 10, 5);

function azm_woo_rac_send_email_template($content, $action, $context, $lead, $lead_meta) {
    $visitor_id = azm_get_lead_visitor_id($lead);
    if ($visitor_id) {
        $last_abandoned_cart = azm_woo_get_last_abandoned_cart($visitor_id);
        if (!empty($last_abandoned_cart)) {
            if (isset($last_abandoned_cart['abandoned_timestamp'])) {
                if (preg_match("{cart_abandoned_date}", $content)) {
                    $content = str_replace('{cart_abandoned_date}', date_i18n(get_option('date_format'), $last_abandoned_cart['abandoned_timestamp']), $content);
                }
            }
            if (isset($context['rule'])) {
                global $wpdb;
                $abandoned_timestamp = $last_abandoned_cart['abandoned_timestamp'];
                $rule_id = $context['rule'];
                $wpdb->query("INSERT IGNORE INTO {$wpdb->prefix}azm_woo_abandoned_carts_rules (visitor_id, abandoned_timestamp, rule_id) VALUES ('$visitor_id', $abandoned_timestamp, $rule_id)");
            }
            if (preg_match("{cart_contents}", $content)) {
                $cart_contents = json_decode($last_abandoned_cart['cart_contents'], true);
                $cart_contents = azm_woo_rac_cart_contents_html($cart_contents);
                $content = str_replace('{cart_contents}', $cart_contents, $content);
            }
        }
    }
    return $content;
}

add_action('admin_menu', 'azm_woo_rac_admin_menu', 10);

function azm_woo_rac_admin_menu() {
    $hook = add_submenu_page('edit.php?post_type=azr_rule', __('Carts', 'azm'), __('Carts', 'azm'), 'edit_pages', 'azh-woo-rac', 'azm_woo_rac_page');
    add_action("load-$hook", 'azm_woo_rac_add_options');
}

function azm_woo_rac_add_options() {
    global $abandoned_carts_table;

    $args = array(
        'label' => 'Rows',
        'default' => 10,
        'option' => 'carts_per_page'
    );
    add_screen_option('per_page', $args);

    $abandoned_carts_table = new AZM_Abandoned_Carts_Table();
}

add_filter('set-screen-option', function( $status, $option, $value ) {
    return ( $option == 'carts_per_page' ) ? (int) $value : $status;
}, 10, 3);

function azm_woo_rac_page() {
    global $abandoned_carts_table;

//    screen_icon();

    echo '<div class="wrap"><h2>' . __('Carts list', 'azm') . '</h2>';

    echo '<form method="post">';
    $abandoned_carts_table->prepare_items();
    $abandoned_carts_table->views();
//    $abandoned_carts_table->search_box(__('Search carts', 'azm'), 'carts');
    $abandoned_carts_table->display();
    echo '</form>';

    echo '</div>';
}

if (!class_exists('WP_List_Table')) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class AZM_Abandoned_Carts_Table extends WP_List_Table {

    function __construct() {

        $this->index = 0;
        parent::__construct(array(
            'singular' => 'cart',
            'plural' => 'carts',
            'ajax' => false
        ));
    }

    function get_table_classes() {
        return array('widefat', 'auto', 'striped', $this->_args['plural']);
    }

    function get_columns() {
        $columns = array(
            'cb' => '<input type="checkbox" />',
            'abandoned_timestamp' => __('Abandoned date', 'azm'),
            'user_email' => __('Email', 'azm'),
            'user_name' => __('User name', 'azm'),
            'cart_total' => __('Cart Total', 'azm'),
            'status' => __('Status', 'azm'),
            'order_id' => __('Order ID', 'azm'),
            'recovered_timestamp' => __('Cart recovered date', 'azm'),
            'campaigns_results' => __('Campaigns results', 'azm'),
        );
        return $columns;
    }

    function column_cb($item) {
        return sprintf(
                '<input type="checkbox" name="%1$s[]" value="%2$s" />',
                /* $1%s */ 'id',
                /* $2%s */ $item->visitor_id . '|' . $item->abandoned_timestamp
        );
    }

    function get_views() {
        global $wpdb;
        $total = $wpdb->get_var("SELECT count(*) FROM {$wpdb->prefix}azm_woo_abandoned_carts");
        $recovered = $wpdb->get_var("SELECT count(*) FROM {$wpdb->prefix}azm_woo_abandoned_carts WHERE recovered_timestamp IS NOT NULL");
        $abandoned = $wpdb->get_var("SELECT count(*) FROM {$wpdb->prefix}azm_woo_abandoned_carts WHERE status = 'abandoned'");
        $guest = $wpdb->get_var("SELECT count(*) FROM {$wpdb->prefix}azm_woo_abandoned_carts WHERE user_id IS NULL");
        return array(
            'all' => '<a class="' . ($_GET['type'] == 'all' || empty($_GET['type']) ? 'current' : '') . '" href="' . admin_url('edit.php?post_type=azr_rule&page=azh-woo-rac&type=all') . '">' . __('All', 'azm') . ' <span class="count">(' . $total . ')</span></a>',
            'abandoned' => '<a class="' . ($_GET['type'] == 'abandoned' ? 'current' : '') . '" href="' . admin_url('edit.php?post_type=azr_rule&page=azh-woo-rac&type=abandoned') . '">' . __('Abandoned', 'azm') . ' <span class="count">(' . $abandoned . ')</span></a>',
            'guest' => '<a class="' . ($_GET['type'] == 'guest' ? 'current' : '') . '" href="' . admin_url('edit.php?post_type=azr_rule&page=azh-woo-rac&type=guest') . '">' . __('Guest', 'azm') . ' <span class="count">(' . $guest . ')</span></a>',
            'recovered' => '<a class="' . ($_GET['type'] == 'recovered' ? 'current' : '') . '" href="' . admin_url('edit.php?post_type=azr_rule&page=azh-woo-rac&type=recovered') . '">' . __('Recovered', 'azm') . ' <span class="count">(' . $recovered . ')</span></a>',
        );
    }

    function get_bulk_actions() {
        return array(
            'delete' => __('Delete', 'azm'),
        );
    }

    function extra_tablenav($which) {
        global $wpdb, $wp_locale;
        $gmt_offset = get_option('gmt_offset') * HOUR_IN_SECONDS;
        if ($which == "top") {
            $months = $wpdb->get_results("SELECT DATE_FORMAT(FROM_UNIXTIME(abandoned_timestamp + $gmt_offset),'%Y-%m') as m, count(*) as c FROM {$wpdb->prefix}azm_woo_abandoned_carts GROUP BY m", ARRAY_A);
            if (!empty($months)) {
                ?>
                <div class="alignleft actions bulkactions">
                    <select name="month">
                        <option value=""><?php esc_html_e('Filter by month', 'azm') ?></option>
                        <?php
                        foreach ($months as $month) {
                            $m = explode('-', $month['m']);
                            ?>
                            <option value="<?php print $month['m']; ?>" <?php print ($_REQUEST['month'] == $month['m'] ? 'selected' : ''); ?>><?php print $m[0] . ' ' . $wp_locale->get_month($m[1]) . ' (' . $month['c'] . ')'; ?></option>
                            <?php
                        }
                        ?>
                    </select>
                    <input type="submit" id="search-submit" class="button" value="<?php esc_html_e('Filter', 'azm') ?>">
                </div>
                <?php
            }
            if ($_REQUEST['action'] && $_REQUEST['action'] == 'default') {
                azm_woo_rac_create_defualt_campaign();
            }
            if (azm_woo_rac_get_defualt_campaign()) {
                print '<div class="alignleft actions bulkactions"><a href="' . get_edit_post_link(azm_woo_rac_get_defualt_campaign()) . '" class="button button-primary button-large" style="margin: 0">' . esc_html__('Edit default abandoned cart campaign', 'azm') . '</a></div>';
            } else {
                print '<div class="alignleft actions bulkactions"><a href="' . admin_url('edit.php?post_type=azr_rule&page=azh-woo-rac&action=default') . '" class="button button-primary button-large" style="margin: 0">' . esc_html__('Create default abandoned cart campaign', 'azm') . '</a></div>';
            }
            print '<div class="alignleft actions bulkactions"><a href="' . get_edit_post_link(azm_woo_rac_get_manual_campaign()) . '" class="button button-primary button-large" style="margin: 0">' . esc_html__('Send manual email to all abandoned carts', 'azm') . '</a></div>';
        }
        if ($which == "bottom") {
            $sql = $this->get_sql(array('ac.recovered_timestamp IS NOT NULL'));
            $sum = $wpdb->get_var("SELECT SUM(ac.cart_total) " . $sql);
            print '<div class="alignleft actions bulkactions" style="line-height: 30px;"><h2>' . __('Recovered Total:', 'azm') . ' ' . (function_exists('wc_price') ? wc_price($sum) : $sum) . '</h2></div>';
        }
    }

    function get_sql($where = array()) {
        global $wpdb;
        $sql = "FROM {$wpdb->prefix}azm_woo_abandoned_carts as ac"
                . " LEFT JOIN {$wpdb->users} as u ON ac.user_id = u.ID ";

        if ($_REQUEST['type'] == 'recovered') {
            $where[] = "ac.recovered_timestamp IS NOT NULL";
        }
        if ($_REQUEST['type'] == 'abandoned') {
            $where[] = "ac.status = 'abandoned'";
        }
        if ($_REQUEST['type'] == 'guest') {
            $where[] = "ac.user_id IS NULL";
        }
        if ($_REQUEST['month']) {
            $gmt_offset = get_option('gmt_offset') * HOUR_IN_SECONDS;
            $month = sanitize_text_field($_REQUEST['month']);
            $where[] = "DATE_FORMAT(FROM_UNIXTIME(ac.abandoned_timestamp + $gmt_offset),'%Y-%m') = '$month'";
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return $sql;
    }

    function process_actions() {
        global $wpdb;
        switch ($this->current_action()) {
            case 'delete':
                if ($_REQUEST['id'] && is_array($_REQUEST['id'])) {
                    foreach ($_REQUEST['id'] as $id) {
                        $id = explode('|', $id);
                        $visitor_id = $id[0];
                        $abandoned_timestamp = $id[1];
                        //remove from cron
                        $wpdb->query("DELETE FROM {$wpdb->prefix}azm_woo_abandoned_carts WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp");
                        $wpdb->query("DELETE FROM {$wpdb->prefix}azm_woo_abandoned_carts_rules WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp");
                    }
                } else {
                    $visitor_id = $_REQUEST['visitor_id'];
                    $abandoned_timestamp = $_REQUEST['abandoned_timestamp'];
                    //remove from cron
                    $wpdb->query("DELETE FROM {$wpdb->prefix}azm_woo_abandoned_carts WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp");
                    $wpdb->query("DELETE FROM {$wpdb->prefix}azm_woo_abandoned_carts_rules WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp");
                }
                break;
            case 'subscribe':
                $visitor_id = $_REQUEST['visitor_id'];
                $abandoned_timestamp = $_REQUEST['abandoned_timestamp'];
                $abandoned_cart = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}azm_woo_abandoned_carts WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp", ARRAY_A);
                if (empty($abandoned_cart['user_id'])) {
                    if ($abandoned_cart['order_id']) {
                        delete_post_meta($abandoned_cart['order_id'], '_unsubscribed');
                    }
                } else {
                    delete_user_meta($abandoned_cart['user_id'], '_unsubscribed');
                }
                break;
            case 'unsubscribe':
                $visitor_id = $_REQUEST['visitor_id'];
                $abandoned_timestamp = $_REQUEST['abandoned_timestamp'];
                $abandoned_cart = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}azm_woo_abandoned_carts WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp", ARRAY_A);
                $time = time();
                if (empty($abandoned_cart['user_id'])) {
                    if ($abandoned_cart['order_id']) {
                        update_post_meta($abandoned_cart['order_id'], '_unsubscribed', $time);
                    }
                } else {
                    update_user_meta($abandoned_cart['user_id'], '_unsubscribed', $time);
                }
                break;
            case 'recovered':
                $visitor_id = $_REQUEST['visitor_id'];
                $abandoned_timestamp = $_REQUEST['abandoned_timestamp'];
                $time = time();
                $wpdb->query("UPDATE {$wpdb->prefix}azm_woo_abandoned_carts SET status = 'recovered-manually', recovered_timestamp = $time WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp");
                break;
        }
    }

    function prepare_items() {
        global $wpdb;

        $_SERVER['REQUEST_URI'] = remove_query_arg('_wp_http_referer', $_SERVER['REQUEST_URI']);

        $this->process_actions();

        $per_page = $this->get_items_per_page('carts_per_page', 10);
        $current_page = $this->get_pagenum();

        $orderby = !empty($_REQUEST['orderby']) ? esc_attr($_REQUEST['orderby']) : 'abandoned_timestamp';
        $order = (!empty($_REQUEST['order']) && $_REQUEST['order'] == 'asc' ) ? 'ASC' : 'DESC';

        $this->_column_headers = $this->get_column_info();


        $sql = $this->get_sql();

        $max = $wpdb->get_var("SELECT COUNT(*) " . $sql);

        $sql = "SELECT ac.visitor_id, ac.abandoned_timestamp, ac.status, ac.recovered_timestamp, ac.order_id, ac.user_id, ac.cart_contents, ac.cart_total, u.user_email, u.display_name " . $sql;


        $offset = ( $current_page - 1 ) * $per_page;

        $sql .= " ORDER BY `{$orderby}` {$order} LIMIT {$offset}, {$per_page}";

        $this->items = $wpdb->get_results($sql);

        $this->set_pagination_args(array(
            'total_items' => $max,
            'per_page' => $per_page,
            'total_pages' => ceil($max / $per_page)
        ));
    }

    function get_sortable_columns() {
        $sortable_columns = array(
            'abandoned_timestamp' => array('abandoned_timestamp', false),
            'status' => array('status', false),
            'cart_total' => array('cart_total', false),
            'recovered_timestamp' => array('recovered_timestamp', false),
        );
        return $sortable_columns;
    }

    function column_default($item, $column_name) {
        switch ($column_name) {
            case 'user_email':
                $output = '';
                if (!empty($item->user_email)) {
                    $output .= '<div>' . $item->user_email . '</div>';
                    if (!empty($item->user_id)) {
                        if (get_user_meta($item->user_id, '_unsubscribed', true)) {
                            $output .= '<a href="' . admin_url("edit.php?post_type=azr_rule&page=azh-woo-rac&action=subscribe&visitor_id={$item->visitor_id}&abandoned_timestamp={$item->abandoned_timestamp}") . '" class="button">' . __("Subscribe", 'azm') . '</a>';
                        } else {
                            $output .= '<a href="' . admin_url("edit.php?post_type=azr_rule&page=azh-woo-rac&action=unsubscribe&visitor_id={$item->visitor_id}&abandoned_timestamp={$item->abandoned_timestamp}") . '" class="button">' . __("Unsubscribe", 'azm') . '</a>';
                        }
                    }
                } else if (!empty($item->order_id)) {
                    $output .= '<div>' . get_post_meta($item->order_id, '_billing_email', true) . '</div>';
                    if (get_post_meta($item->order_id, '_unsubscribed', true)) {
                        $output .= '<a href="' . admin_url("edit.php?post_type=azr_rule&page=azh-woo-rac&action=subscribe&visitor_id={$item->visitor_id}&abandoned_timestamp={$item->abandoned_timestamp}") . '" class="button">' . __("Subscribe", 'azm') . '</a>';
                    } else {
                        $output .= '<a href="' . admin_url("edit.php?post_type=azr_rule&page=azh-woo-rac&action=unsubscribe&visitor_id={$item->visitor_id}&abandoned_timestamp={$item->abandoned_timestamp}") . '" class="button">' . __("Unsubscribe", 'azm') . '</a>';
                    }
                }
                return $output;
                break;
            case 'status':
                if (!empty($item->status)) {
                    $output = '<div>' . $item->status . '</div>';
                    if (in_array($item->status, array('abandoned', 'order-placed'))) {
                        $output .= '<a href="' . admin_url("edit.php?post_type=azr_rule&page=azh-woo-rac&action=recovered&visitor_id={$item->visitor_id}&abandoned_timestamp={$item->abandoned_timestamp}") . '" class="button">' . __("Mark as recovered", 'azm') . '</a>';
                    }
                    return $output;
                }
                break;
            case 'user_name':
                if (!empty($item->user_id)) {
                    return '<a href="' . admin_url('user-edit.php?user_id=' . $item->user_id) . '">' . $item->display_name . '</a>';
                } else if (!empty($item->order_id)) {
                    return get_post_meta($item->order_id, '_billing_first_name', true) . ' ' . get_post_meta($item->order_id, '_billing_last_name', true);
                }
                break;
            case 'abandoned_timestamp':
                $visitor_id = $_REQUEST['visitor_id'];
                $abandoned_timestamp = $_REQUEST['abandoned_timestamp'];
                if ($this->current_action() == 'cart_contents' && $item->visitor_id == $visitor_id && $item->abandoned_timestamp == $abandoned_timestamp) {
                    if (!empty($item->cart_contents)) {
                        $cart_contents = json_decode($item->cart_contents, true);
                        if (is_array($cart_contents)) {
                            return azm_woo_rac_cart_contents_html($cart_contents, true);
                        }
                    }
                    return '';
                } else {
                    $row_actions = array();
                    $row_actions['edit'] = '<a href="' . wp_nonce_url(add_query_arg(array('action' => 'cart_contents', 'visitor_id' => $item->visitor_id, 'abandoned_timestamp' => $item->abandoned_timestamp), admin_url('edit.php?post_type=azr_rule&page=azh-woo-rac')), 'abandoned_cart_nonce') . '">' . __('View cart contents', 'azm') . '</a>';
                    $row_actions['delete'] = '<a href="' . wp_nonce_url(add_query_arg(array('action' => 'delete', 'visitor_id' => $item->visitor_id, 'abandoned_timestamp' => $item->abandoned_timestamp), admin_url('edit.php?post_type=azr_rule&page=azh-woo-rac')), 'abandoned_cart_nonce') . '">' . __('Delete', 'azm') . '</a>';
                    return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $item->abandoned_timestamp) . $this->row_actions($row_actions);
                }
                break;
            case 'recovered_timestamp':
                if (!empty($item->recovered_timestamp)) {
                    return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $item->recovered_timestamp);
                }
                break;
            case 'cart_total':
                return (function_exists('wc_price') ? wc_price((float) $item->cart_total) : (float) $item->cart_total);
                break;
            case 'campaigns_results':
                global $wpdb;
                $rules = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}azm_woo_abandoned_carts_rules WHERE visitor_id = '{$item->visitor_id}' AND abandoned_timestamp = {$item->abandoned_timestamp}", ARRAY_A);

                $results = '<table class="widefat striped"><thead>
                    <tr>
                    <th>' . __("Campaign", 'azm') . '</th>
                    <th>' . __("Status", 'azm') . '</th>
                    <th>' . __("Sent", 'azm') . '</th>
                    <th>' . __("Open", 'azm') . '</th>
                    <th>' . __("Clicked", 'azm') . '</th>
                    <th>' . __("Unsubscribed", 'azm') . '</th>
                    </tr></thead><tbody>';
                if (!empty($rules)) {
                    foreach ($rules as $rule) {
                        $post = get_post($rule['rule_id']);
                        $results .= '<tr>
                        <td><a href="' . get_edit_post_link($rule['rule_id']) . '">' . $post->post_title . '</a></td>
                        <td>' . $rule['status'] . '</td>
                        <td>' . ($rule['sent_timestamp'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $rule['sent_timestamp']) : '') . '</td>
                        <td>' . ($rule['open_timestamp'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $rule['open_timestamp']) : '') . '</td>
                        <td>' . ($rule['click_timestamp'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $rule['click_timestamp']) : '') . '</td>
                        <td>' . ($rule['unsubscribe_timestamp'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $rule['unsubscribe_timestamp']) : '') . '</td>
                        </tr>';
                    }
                }
                $results .= '<tbody></table>';
                return $results;
                break;
            case 'order_id':
                if (!empty($item->order_id)) {
                    return '<a href="' . get_edit_post_link($item->order_id) . '">' . $item->order_id . '</a>';
                }
                break;
        }
        return '';
    }

}

function azm_woo_get_last_abandoned_cart($visitor_id, $time = false) {
    global $wpdb;
    if (!$time) {
        $time = time();
    }
    return $wpdb->get_row("SELECT * FROM {$wpdb->prefix}azm_woo_abandoned_carts WHERE visitor_id = '$visitor_id' AND abandoned_timestamp <= $time ORDER BY abandoned_timestamp DESC LIMIT 1", ARRAY_A);
}

function azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, $status) {
    global $wpdb;
    $last_abandoned_cart = azm_woo_get_last_abandoned_cart($visitor_id, $time);
    if (!empty($last_abandoned_cart)) {
        $abandoned_timestamp = $last_abandoned_cart['abandoned_timestamp'];
        if ($status == 'failed') {
            $wpdb->query("UPDATE {$wpdb->prefix}azm_woo_abandoned_carts_rules SET status = 'failed' WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp AND rule_id = $rule_id");
        } else {
            $wpdb->query("UPDATE {$wpdb->prefix}azm_woo_abandoned_carts_rules SET " . $status . "_timestamp = $time, status = '$status' WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp AND rule_id = $rule_id");
        }
    }
}

add_action('azm_email_campaign_user_sent', 'azm_woo_rac_email_campaign_user_sent', 10, 3);

function azm_woo_rac_email_campaign_user_sent($user_id, $rule_id, $time) {
    $visitor_id = get_user_meta($user_id, 'azr-visitor', true);
    azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, 'sent');
}

add_action('azm_email_campaign_user_failed', 'azm_woo_rac_email_campaign_user_failed', 10, 3);

function azm_woo_rac_email_campaign_user_failed($user_id, $rule_id, $time) {
    $visitor_id = get_user_meta($user_id, 'azr-visitor', true);
    azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, 'failed');
}

add_action('azm_email_campaign_user_open', 'azm_woo_rac_email_campaign_user_open', 10, 3);

function azm_woo_rac_email_campaign_user_open($user_id, $rule_id, $time) {
    $visitor_id = get_user_meta($user_id, 'azr-visitor', true);
    azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, 'open');
}

add_action('azm_email_campaign_user_click', 'azm_woo_rac_email_campaign_user_click', 10, 3);

function azm_woo_rac_email_campaign_user_click($user_id, $rule_id, $time) {
    $visitor_id = get_user_meta($user_id, 'azr-visitor', true);
    azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, 'click');
}

add_action('azm_user_unsubscribe', 'azm_woo_rac_user_unsubscribe', 10, 3);

function azm_woo_rac_user_unsubscribe($user_id, $rule_id, $time) {
    $visitor_id = get_user_meta($user_id, 'azr-visitor', true);
    azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, 'unsubscribe');
}

add_action('azm_email_campaign_lead_sent', 'azm_woo_rac_email_campaign_lead_sent', 10, 3);

function azm_woo_rac_email_campaign_lead_sent($lead_id, $rule_id, $time) {
    $visitor_id = get_post_meta($lead_id, '_azr_visitor', true);
    azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, 'sent');
}

add_action('azm_email_campaign_lead_failed', 'azm_woo_rac_email_campaign_lead_failed', 10, 3);

function azm_woo_rac_email_campaign_lead_failed($lead_id, $rule_id, $time) {
    $visitor_id = get_post_meta($lead_id, '_azr_visitor', true);
    azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, 'failed');
}

add_action('azm_email_campaign_lead_open', 'azm_woo_rac_email_campaign_lead_open', 10, 3);

function azm_woo_rac_email_campaign_lead_open($lead_id, $rule_id, $time) {
    $visitor_id = get_post_meta($lead_id, '_azr_visitor', true);
    azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, 'open');
}

add_action('azm_email_campaign_lead_click', 'azm_woo_rac_email_campaign_lead_click', 10, 3);

function azm_woo_rac_email_campaign_lead_click($lead_id, $rule_id, $time) {
    $visitor_id = get_post_meta($lead_id, '_azr_visitor', true);
    azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, 'click');
}

add_action('azm_lead_unsubscribe', 'azm_woo_rac_lead_unsubscribe', 10, 3);

function azm_woo_rac_lead_unsubscribe($lead_id, $rule_id, $time) {
    $visitor_id = get_post_meta($lead_id, '_azr_visitor', true);
    azm_woo_abandoned_cart_rule_update($visitor_id, $rule_id, $time, 'unsubscribe');
}

function azm_woo_rac_compare($order, $cart_contents) {
    $equal = false;
    $cart_products = array();
    foreach ($cart_contents as $item) {
        $cart_products[$item['product_id']] = true;
    }
    $cart_products = array_keys($cart_products);
    $order_products = array();
    foreach ($order->get_items() as $item) {
        if ($item->is_type('line_item')) {
            $order_products[$item->get_product_id()] = true;
        }
    }
    $order_products = array_keys($order_products);
    return count(array_intersect($order_products, $cart_products)) == count($cart_products);
}

add_action('woocommerce_checkout_order_processed', 'azm_woo_rac_checkout_order_processed', 10, 3);

function azm_woo_rac_checkout_order_processed($order_id, $posted_data, $order) {
    $visitor_id = azm_woo_get_order_visitor_id($order_id);
    if (!empty($visitor_id)) {
        $last_abandoned_cart = azm_woo_get_last_abandoned_cart($visitor_id);
        if (!empty($last_abandoned_cart)) {
            $cart_contents = json_decode($last_abandoned_cart['cart_contents'], true);
            if (azm_woo_rac_compare($order, $cart_contents)) {
                global $wpdb;
                $visitor_id = $last_abandoned_cart['visitor_id'];
                $abandoned_timestamp = $last_abandoned_cart['abandoned_timestamp'];
                $order_total = $order->get_total();
                $wpdb->query("UPDATE {$wpdb->prefix}azm_woo_abandoned_carts SET status = 'order-placed', order_id = $order_id, cart_total = $order_total WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp");
                $wpdb->query("UPDATE {$wpdb->prefix}azm_woo_abandoned_carts SET status = 'changed' WHERE visitor_id = '$visitor_id' AND abandoned_timestamp < $abandoned_timestamp AND status IS NULL");
            }
        }
    }
}

add_action('woocommerce_payment_complete', 'azm_woo_rac_payment_complete', 10, 1);
add_action('woocommerce_order_status_completed', 'azm_woo_rac_payment_complete', 10, 1);

function azm_woo_rac_payment_complete($order_id) {
    $visitor_id = azm_woo_get_order_visitor_id($order_id);
    $order = wc_get_order($order_id);
    if (!empty($visitor_id)) {
        $last_abandoned_cart = azm_woo_get_last_abandoned_cart($visitor_id);
        if (!empty($last_abandoned_cart)) {
            $cart_contents = json_decode($last_abandoned_cart['cart_contents'], true);
            if (azm_woo_rac_compare($order, $cart_contents)) {
                global $wpdb;
                $visitor_id = $last_abandoned_cart['visitor_id'];
                $abandoned_timestamp = $last_abandoned_cart['abandoned_timestamp'];
                $order_total = $order->get_total();
                $time = time();
                $wpdb->query("UPDATE {$wpdb->prefix}azm_woo_abandoned_carts SET status = 'payment-complete', recovered_timestamp = $time, order_id = $order_id, cart_total = $order_total  WHERE visitor_id = '$visitor_id' AND abandoned_timestamp = $abandoned_timestamp");
                $wpdb->query("UPDATE {$wpdb->prefix}azm_woo_abandoned_carts SET status = 'changed' WHERE visitor_id = '$visitor_id' AND abandoned_timestamp < $abandoned_timestamp AND status IS NULL");
            }
        }
    }
}

function azm_woo_rac_get_defualt_campaign() {
    $rule_id = get_option('azm_woo_rac_defualt_campaign');
    if ($rule_id) {
        $post = get_post($rule_id);
        if ($post && $post->post_status != 'trash') {
            return $rule_id;
        }
    }
    return false;
}

function azm_woo_rac_create_defualt_campaign() {
    $rule_id = wp_insert_post(
            array(
        'post_title' => esc_html__('Abandoned cart (default)', 'azm'),
        'post_type' => 'azr_rule',
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
            ), true
    );
    update_post_meta($rule_id, '_rule', '
        {
            "event": {
                "type": "scheduler",
                "when": "every_hour"
            },
            "context": {
                "rule": ' . $rule_id . '
            },
            "conditions": [{
                "type": "has_abandoned_cart",
                "days": "1"
            }, {
                "type": "or",
                "conditions": [{
                    "type": "is_registered_user"
                }, {
                    "type": "guest_order_email_subscription",
                    "email_subscription_status": "subscribed"
                }]
            }, {
                "type": "or",
                "conditions": [{
                    "type": "email_campaign_sent_date",
                    "campaign": "' . $rule_id . '",
                    "sent": "is_not_within",
                    "days": "1"
                }, {
                    "type": "email_campaign_status",
                    "campaign": "' . $rule_id . '",
                    "status": "was_not_sent"
                }]
            }],
            "actions": [{
                "type": "send_html_email",
                "from_email": "' . get_bloginfo('admin_email') . '",
                "from_name": "' . get_bloginfo('name') . '",
                "reply_to": "' . get_bloginfo('admin_email') . '",                    
                "email_subject": "' . esc_html__('Did you have checkout trouble?', 'azm') . '",
                "email_template": "0",
                "email_body": "' . base64_encode('<p>Hello {first_name},</p><p>We are following up with you, because we noticed that on {cart_abandoned_date} you attempted to purchase the following products on admin.</p><p>{cart_contents}</p>') . '",
                "woocommerce_style": true
            }]
        }            
        ');
    update_option('azm_woo_rac_defualt_campaign', $rule_id);
    update_post_meta($rule_id, '_status', 'running');
    azr_run($rule_id);
    return $rule_id;
}

function azm_woo_rac_get_manual_campaign() {
    $rule_id = get_option('azm_woo_rac_manual_campaign');
    $insert = true;
    if ($rule_id) {
        $post = get_post($rule_id);
        if ($post && $post->post_status != 'trash') {
            $insert = false;
        }
    }
    if ($insert) {
        $rule_id = wp_insert_post(
                array(
            'post_title' => esc_html__('Abandoned cart (manual)', 'azm'),
            'post_type' => 'azr_rule',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
                ), true
        );
    }
    update_post_meta($rule_id, '_rule', '
        {
            "event": {
                "type": "scheduler",
                "when": "immediately"
            },
            "context": {
                "rule": ' . $rule_id . '
            },
            "conditions": [{
                "type": "has_abandoned_cart",
                "days": "1"
            }, {
                "type": "or",
                "conditions": [{
                    "type": "is_registered_user"
                }, {
                    "type": "guest_order_email_subscription",
                    "email_subscription_status": "subscribed"
                }]
            }, {
                "type": "or",
                "conditions": [{
                    "type": "email_campaign_sent_date",
                    "campaign": "' . $rule_id . '",
                    "sent": "is_not_within",
                    "days": "1"
                }, {
                    "type": "email_campaign_status",
                    "campaign": "' . $rule_id . '",
                    "status": "was_not_sent"
                }]
            }],
            "actions": [{
                "type": "send_html_email",
                "from_email": "' . get_bloginfo('admin_email') . '",
                "from_name": "' . get_bloginfo('name') . '",
                "reply_to": "' . get_bloginfo('admin_email') . '",  
                "email_subject": "' . esc_html__('Did you have checkout trouble?', 'azm') . '",
                "email_template": "0",
                "email_body": "' . base64_encode('<p>Hello {first_name},</p><p>We are following up with you, because we noticed that on {cart_abandoned_date} you attempted to purchase the following products on admin.</p><p>{cart_contents}</p>') . '",
                "woocommerce_style": true
            }]
        }            
        ');
    update_option('azm_woo_rac_manual_campaign', $rule_id);
    return $rule_id;
}
