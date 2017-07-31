/**
 * This file is part of the MailWizz EMA application.
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.5.2
 */
jQuery(document).ready(function($){
	
	var ajaxData = {};
	if ($('meta[name=csrf-token-name]').length && $('meta[name=csrf-token-value]').length) {
			var csrfTokenName = $('meta[name=csrf-token-name]').attr('content');
			var csrfTokenValue = $('meta[name=csrf-token-value]').attr('content');
			ajaxData[csrfTokenName] = csrfTokenValue;
	}
	
    $(document).on('click', 'a.unsubscribe, a.subscribe', function(){
		if (!confirm($(this).data('message'))) {
			return false;
		}
		$.post($(this).attr('href'), $.param(ajaxData) + '&' + $(this).serialize(), function(){
			window.location.reload();
		});
		return false;
	});

	// 
	$(document).on('click', '.toggle-filters-form', function(){
		$('#filters-form').toggle();
		return false;
	});
	
	$(document).on('submit', '#filters-form', function() {
		var action = $('#action', this).val();
		if (action == 'delete' && !confirm($(this).data('confirm'))) {
			return false;
		}
		return true;
	});

});