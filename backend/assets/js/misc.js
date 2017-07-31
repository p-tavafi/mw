jQuery(document).ready(function($){
	
	var ajaxData = {};
	if ($('meta[name=csrf-token-name]').length && $('meta[name=csrf-token-value]').length) {
			var csrfTokenName = $('meta[name=csrf-token-name]').attr('content');
			var csrfTokenValue = $('meta[name=csrf-token-value]').attr('content');
			ajaxData[csrfTokenName] = csrfTokenValue;
	}
	
	$('.delete-app-log').on('click', function() {
		var $this = $(this);
		if (!confirm($this.data('message'))) {
			return false;
		}
	});
    
    $('.remove-sending-pid, .remove-bounce-pid, .remove-fbl-pid, .reset-campaigns, .reset-bounce-servers, .reset-fbl-servers').on('click', function(){
        if (!confirm($(this).data('confirm'))) {
            return false;
        }
        $.getJSON($(this).attr('href'), {}, function(json){
            notify.addSuccess($('#ea-box-wrapper').data('success')).show();
        });
        return false;
    });
    
    $('a.btn-delete-delivery-temporary-errors').on('click', function(){
        var $this = $(this);
        if (!confirm($this.data('confirm'))) {
            return false;
        }
    });

});