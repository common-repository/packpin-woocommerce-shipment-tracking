jQuery(document).ready(function ($) {
    if ($('.cs_banner_upload').length > 0) {
        $('.cs_banner_upload').click(function (e) {
            e.preventDefault();
            var custom_uploader = wp.media({
                title: 'Custom Image',
                button: {
                    text: 'Upload Image'
                },
                multiple: false  // Set this to true to allow multiple files to be selected
            })
                .on('select', function () {
                    var attachment = custom_uploader.state().get('selection').first().toJSON();
                    $('.cs_banner').attr('src', attachment.url);
                    $('.cs_banner_image').val(attachment.url);
                })
                .open();
        });
    }

    if ($('a.clear-pp-cache').length > 0) {
        $('a.clear-pp-cache').click( function (e){
            e.preventDefault();

            var _this = $(this);
            _this.addClass('loading');

            $.post(ajaxurl, {
                'action': 'clear_pp_cache'
            }, function(response) {
                if(response.cleared){
                    alert('The cache was cleared succesfuly!');
                }else{
                    alert('There was an error!')
                }
            }).always(function(){
                _this.removeClass('loading');
            });

            return false;
        });
    }
});