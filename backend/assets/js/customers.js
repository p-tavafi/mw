jQuery(document).ready(function($){

    $(document).on('click', 'a.reset-sending-quota', function() {
        if (!confirm($(this).data('message'))) {
            return false;
        }
		$.post($(this).attr('href'), ajaxData, function(){
			window.location.reload();
		});
		return false;
	});
    
});