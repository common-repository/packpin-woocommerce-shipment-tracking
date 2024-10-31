jQuery(document).ready(function ($) {
    /**
     * Interrupt form submiting if there's a code entered but no carrier selected
     */
    jQuery('form#post').submit(function(){
       var packpin_code = jQuery('input#packpin_code');
       var packpin_reset = jQuery('input#packpin_reset');
       if(packpin_reset.attr('disabled')){
           if(typeof packpin_code != "undefined"){
               if(packpin_code.val().length > 0){
                   var packpin_carrier = jQuery('#packpin_carrier');
                   if(packpin_carrier.val() == null || packpin_carrier.val() == ""){
                       // TODO : https://codex.wordpress.org/Function_Reference/wp_localize_script
                       alert('Please select a carrier for the tracking code you have entered! Thanks!');
                       return false;
                   }
               }
           }
       }
    });

    /**
     * Functionality for reset link
     */
    jQuery('a#packpin_reset_info').click(function(){
        // TODO : https://codex.wordpress.org/Function_Reference/wp_localize_script
        var msg = 'Use reset in case some information in Packpin extensions database got corrupted. This will only clean information from your WooCommerce store, not from Packpin servers! Procceed with caution!';
        if (confirm(msg) == true) {
            var sure_msg = 'Are you sure?';
            if(confirm(sure_msg) == true){
                jQuery('input#packpin_reset').removeProp('disabled');
                jQuery('form#post').submit();
            }
        }
    });
});