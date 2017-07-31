jQuery(document).ready(function($){
	
	var ajaxData = {};
	if ($('meta[name=csrf-token-name]').length && $('meta[name=csrf-token-value]').length) {
			var csrfTokenName = $('meta[name=csrf-token-name]').attr('content');
			var csrfTokenValue = $('meta[name=csrf-token-value]').attr('content');
			ajaxData[csrfTokenName] = csrfTokenValue;
	}
	
	$(document).on('click', '.blacklist-email', function() {
	    notify.remove();
		var $this = $(this);
		if (!confirm($this.data('message'))) {
			return false;
		}
		$.post($this.attr('href'), ajaxData, function(json){
			notify.addSuccess(json.message).show();
		}, 'json');
		return false;
	});
});