kodReady.push(function(){
	if(window.__elasticFulltextSearchUI)return;
	window.__elasticFulltextSearchUI=true;
	var css='.file-list-list .file.file-search-match.has-file-cover .search-match-content .file-cover:not(:has(.picture)){display:none!important}'
		+'.file-list-list .file.file-search-match.has-file-cover .search-match-content:not(:has(.picture)) .match-text{display:block!important;height:auto!important;margin:0 5px 5px 25px!important}';
	if(!document.getElementById('elastic-fulltext-search-style')){
		var style=document.createElement('style');
		style.id='elastic-fulltext-search-style';
		style.type='text/css';
		style.appendChild(document.createTextNode(css));
		document.head.appendChild(style);
	}
	function hideTypeIconCover($root){
		($root&&$root.find?$root:$(document)).find('.file.file-search-match.has-file-cover').each(function(){
			var $file=$(this),$cover=$file.find('.search-match-content .file-cover');
			if(!$cover.length||$cover.find('.picture').length)return;
			$cover.remove();
			$file.removeClass('has-file-cover');
		});
	}
	Events.bind('explorer.path.list.after',function(){hideTypeIconCover($('.file-continer'));});
});
