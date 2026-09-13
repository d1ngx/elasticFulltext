kodReady.push(function(){
	function run(button, operation){
		button.prop('disabled', true);
		$.ajax({url:'?plugin/elasticFulltext/manage', type:'POST', dataType:'json', data:{operation:operation}})
			.done(function(result){
				var data=result&&result.data, message=(data&&data.message)||(typeof data==='string'?data:'');
				if(result&&result.code) Tips.tips(message||'操作完成', true); else Tips.tips(message||'操作失败', false);
			})
			.fail(function(xhr){Tips.tips(xhr.responseText||'操作失败', false);})
			.always(function(){button.prop('disabled', false);});
	}
	$(document).off('click.elasticFulltext').on('click.elasticFulltext', '.elastic-fulltext-action', function(){
		var button=$(this), operation=button.data('operation');
		if(operation==='rebuild' && !window.confirm('这会删除并重新建立 Kodbox 全文索引，确认继续吗？')) return;
		run(button, operation);
	});
});
