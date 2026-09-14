kodReady.push(function(){
	if(window.__elasticFulltextAdminReady)return;
	window.__elasticFulltextAdminReady=true;
	if(!$('#elastic-fulltext-admin-style').length)$('<style id="elastic-fulltext-admin-style">.elastic-fulltext-action.btn-sm{padding:5px 13px!important;font-size:13px!important;line-height:1.4!important}.elastic-fulltext-status{line-height:1.9}.elastic-fulltext-status-actions{margin-top:8px}.elastic-fulltext-status-actions .btn+.btn{margin-left:6px}.elastic-fulltext-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#2196f3;margin-right:8px}.elastic-fulltext-dot.is-loading{animation:elasticPulse 1s infinite alternate}@keyframes elasticPulse{to{opacity:.25}}</style>').appendTo('head');
	function msg(result,fallback){var data=result&&result.data;return (data&&data.message)||(typeof data==='string'?data:fallback);}
	function refreshStatus(form){
		var box=form&&form.$ ? form.$('.elastic-fulltext-status') : $('.elastic-fulltext-status'); if(!box.length)return;
		box.html('<span class="elastic-fulltext-dot is-loading"></span>正在检测 Elasticsearch 服务…');
		$.ajax({url:'?plugin/elasticFulltext/status',dataType:'json',cache:false,timeout:6000})
		.done(function(result){box.html(result&&result.code&&result.data?result.data.html:msg(result,'状态读取失败'));})
		.fail(function(xhr){box.html('<span style="color:#d9822b">● Elasticsearch 连接失败：</span>'+(xhr.statusText||'请求超时'));});
	}
	function run(button,operation){
		button.prop('disabled',true);
		$.ajax({url:'?plugin/elasticFulltext/manage',type:'POST',dataType:'json',data:{operation:operation}})
		.done(function(result){Tips.tips(msg(result,result&&result.code?'操作完成':'操作失败'),!!(result&&result.code));if(result&&result.code)refreshStatus();})
		.fail(function(xhr){Tips.tips(xhr.responseText||'操作失败',false);})
		.always(function(){button.prop('disabled',false);});
	}
	$(document).off('click.elasticFulltext').on('click.elasticFulltext','.elastic-fulltext-action',function(){
		var button=$(this),operation=button.data('operation');
		if(operation==='rebuild'&&!window.confirm('这会删除并重新建立 Kodbox 全文索引，确认继续吗？'))return;
		run(button,operation);
	});
	Events.bind('plugin.config.formBefore',function(data,options){
		if(_.get(options,'id')!='app-config-elasticFulltext')return;
		data.runStatus={type:'html',value:'<div class="elastic-fulltext-status"><span class="elastic-fulltext-dot is-loading"></span>正在检测 Elasticsearch 服务…</div>',display:'运行情况'};
	});
	Events.bind('plugin.config.formAfter',function(_this){
		var form=_this.formelasticFulltext;
		if(form&&form.$el)refreshStatus(form);
	});
});
