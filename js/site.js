$(function() {
	/**
	 * initialize modal tabs to load content via ajax if needed
	 */
	$('#informationModal .modalMainTabs a').click(function (e) 
	{
		e.preventDefault()
		var source = $(this).data('tabsource');

		if(source)
		{
			var $target = $($(e.target).attr('href'));

			if(!$target.is('.contentLoaded'))
			{
				$target.load(source, function(x)
				{
					$target.addClass('contentLoaded');
				});
			}
			
		}

		$(this).tab('show');
	})
});
