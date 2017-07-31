jQuery(document).ready(function($){
	
	$(document).on('click', '.preview-transactional-email', function(){
	   window.open($(this).attr('href'), $(this).attr('title'), 'height=600, width=600');
       return false;
	});

});