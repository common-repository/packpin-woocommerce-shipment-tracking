<div class="wrap">
    <form action="options.php" method="post">
        <h2><?php echo __('Packpin Woocommerce Shipment Tracking', 'packpin'); ?></h2>

        <div class="packpinLogo">
            <img class="logo" src="https://packpin.com/wp-content/themes/packpinv2/images/packpinLogo.svg" width="150">
        </div>
        <?php if($planInfo && $planInfo['body']):
            $info = $planInfo['body'];
            ?>
            <div class="planInfo">
                <div class="current">
                    <h3><?=__('Current plan', 'packpin');?></h3>
                    <span><?=$info['name'];?></span>
                </div>
                <div class="usage">
                    <h3><?=__('Used trackings', 'packpin');?></h3>
                    <span <?=($info['trackings']['used']>=$info['trackings']['count']) ? 'class="usedUp"' : '';?>><?=$info['trackings']['used'];?>/<?=$info['trackings']['count'];?></span>
                </div>
                <div class="extra">
                    <a target="_blank" href="https://panel.packpin.com/billing"><?=__('Manage', 'packpin');?></a>
                </div>
            </div>
            <div class="clear"></div>
            <?php if($info['trackings']['used']>=$info['trackings']['count']):?>
                <div class="bigNotice">
                    <span><?=__('You have ran out of trackings for this billing period! Please renew your plan or upgrade!', 'packpin');?></span>
                </div>
            <?php endif;?>
        <?php endif;?>
        <?php
        settings_fields('pluginPage');
        do_settings_sections('pluginPage');
        ?>
        <a href="#" class="clear-pp-cache"><?=__('Clear the plugin cache (use in case of emergency!)','packpin');?></a>
        <?php submit_button(); ?>
    </form>
</div>