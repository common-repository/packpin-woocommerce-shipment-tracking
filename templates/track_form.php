<div class="pptrack-box-1">
    <form method="get" action="">
        <div class="pptrack-row">
            <div class="pptrack-form-controls">
                <label for="ppYourEmail"><?=__('Your email', 'packpin');?></label><br/>
                <input type="text" id="ppYourEmail" name="email" class="pptrack-input" value="<?=($email) ? $email:'';?>"/>
            </div>
            <div class="pptrack-form-controls">
                <label for="ppOrderNumber"><?=__('Order Number', 'packpin');?></label><br/>
                <input type="text" id="ppOrderNumber" name="order" class="pptrack-input" value="<?=($orderNumber) ? $orderNumber:'';?>"/>
            </div>
            <div class="pptrack-form-controls">
                <button class="btn pptrack-btn" type="submit"><?=__('Submit', 'packpin');?></button>
            </div>
        </div>
    </form>
</div>