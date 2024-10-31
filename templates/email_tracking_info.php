<?php
$bg = get_option('woocommerce_email_background_color');
$base = get_option('woocommerce_email_base_color');
$base_text = wc_light_or_dark($base, '#202020', '#ffffff');
$bg_darker_10 = wc_hex_darker($bg, 10);
?>
<table>
    <tr>
        <td><?php echo __('Order number', 'packpin'); ?>:</td>
        <td><?php echo $order->id; ?></td>
        <td><?php echo __('Carrier', 'packpin'); ?>:</td>
        <td><?php echo $carrier['name'] ?></td>
    </tr>
    <tr>
        <td colspan="2"></td>
        <td><?php echo __('Tracking number', 'packpin'); ?>:</td>
        <td><?php echo $tableTrack['code']; ?></td>
    </tr>
    <tr>
        <td colspan="2"><?php echo __('For questions regarding your shipment contact carrier directly', 'packpin'); ?></td>
        <td colspan="2"><a
                style="box-shadow: 0 1px 4px rgba(0,0,0,0.1) !important; background-color: <?php echo esc_attr($base); ?>; color: <?php echo esc_attr($base_text); ?>; border-radius: 3px !important; text-decoration: none; padding: 5px 10px;"
                href="<?= $pptrackUrl; ?>"><?php echo __('SEE FULL TRACKING INFO', 'packpin'); ?></a></td>
    </tr>
</table>