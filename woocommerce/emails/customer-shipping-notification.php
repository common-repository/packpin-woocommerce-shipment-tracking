<?php
/**
 * Customer shipping order email
 *
 * @author     Packpin
 * @package    Packpin_Woocommerce_Shipment_Tracking/WooCommerce/Templates/Emails
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

?>

<?php do_action('woocommerce_email_header', $email_heading); ?>

<h2 style="text-align: center;"><?php printf(__("Your package status has been updated!", 'packpin'), get_option('blogname')); ?></h2>

<?php do_action('woocommerce_email_packpin_before_notification_table', $order, $sent_to_admin, $plain_text); ?>

<?php do_action('woocommerce_email_packpin_notification_table', $order, $sent_to_admin, $plain_text); ?>

<?php do_action('woocommerce_email_packpin_after_notification_table', $order, $sent_to_admin, $plain_text); ?>

<?php do_action('woocommerce_email_footer'); ?>
