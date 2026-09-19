kodReady.push(function(){
	if(window.__elasticFulltextAdminReady)return;
	window.__elasticFulltextAdminReady=true;
	$('#elastic-fulltext-admin-style').remove();
	$('<style id="elastic-fulltext-admin-style">'+[
		'.elastic-fulltext-action.btn-sm{padding:5px 13px!important;font-size:13px!important;line-height:1.4!important;min-width:88px}',
		'.elastic-fulltext-action.is-busy{opacity:.85;pointer-events:none}',
		'.elastic-fulltext-status{line-height:1.55;font-size:13px;max-width:100%;box-sizing:border-box}',
		'.elastic-fulltext-es{margin-bottom:4px}',
		'.elastic-fulltext-metrics{display:grid;grid-template-columns:repeat(2,minmax(160px,1fr));gap:6px 22px;margin:8px 0 10px}',
		'.elastic-fulltext-stat{display:grid;grid-template-columns:5.5em minmax(0,1fr);align-items:baseline;column-gap:10px;min-width:0}',
		'.elastic-fulltext-stat.is-wide{grid-column:1/-1}',
		'.elastic-fulltext-stat .k{color:#888;white-space:nowrap}',
		'.elastic-fulltext-stat .v{min-width:0;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
		'.elastic-fulltext-hint{color:#888;line-height:1.55;margin:0 0 8px;font-size:12px}',
		'.elastic-fulltext-note{color:#666;margin:2px 0;display:flex;gap:10px}',
		'.elastic-fulltext-error{color:#c62828;margin-top:4px}',
		'.elastic-fulltext-status-actions{margin-top:8px}',
		'.elastic-fulltext-status-actions .btn+.btn{margin-left:6px}',
		'.elastic-fulltext-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#2196f3;margin-right:8px;vertical-align:middle}',
		'.elastic-fulltext-dot.is-loading{animation:elasticPulse 1s infinite alternate}',
		'.elastic-fulltext-progress{display:flex;flex-direction:column;gap:4px;margin:0 0 10px;padding:8px 10px;background:#eef6ff;border:1px solid #c5ddf7;border-radius:4px;color:#1565c0}',
		'.elastic-fulltext-progress.is-done{background:#eefbf2;border-color:#c6f0d3;color:#15803d}',
		'.elastic-fulltext-progress-head{display:flex;align-items:center;justify-content:space-between;gap:12px}',
		'.elastic-fulltext-progress-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;opacity:.9}',
		'.elastic-fulltext-test-msg{display:inline-block;margin-left:8px;min-height:20px;vertical-align:middle}',
		'.elastic-fulltext-test-msg.is-wait{color:#1565c0}',
		'.elastic-fulltext-test-msg.is-ok{color:#20a53a}',
		'.elastic-fulltext-test-msg.is-fail{color:#d9822b}',
		'@keyframes elasticPulse{to{opacity:.25}}',
		'@media (max-width:720px){.elastic-fulltext-metrics{grid-template-columns:1fr}}'
	].join('')+'</style>').appendTo('head');
	var loadingHtml='<span class="elastic-fulltext-dot is-loading"></span>正在读取运行情况…';
	var pending=null,watchTimer=0,watchTries=0,pollTimer=0,busy=false,runStartedMs=0;
	function msg(result,fallback){var data=result&&result.data;return (data&&data.message)||(typeof data==='string'?data:fallback);}
	function statusBoxes(){return $('.elastic-fulltext-status');}
	function testMsg(){return $('.elastic-fulltext-test-msg');}
	function actionButtons(){return $('.elastic-fulltext-action');}
	function isLoading(box){return box&&box.length&&(box.find('.is-loading').length||/正在读取运行情况/.test(box.text()||''))&&!box.find('.elastic-fulltext-progress').length;}
	function paint(html){statusBoxes().html(html);bindBusyState();}
	function bindBusyState(){
		actionButtons().each(function(){
			var button=$(this),op=button.data('operation');
			if(op==='test')return;
			if(busy){
				if(!button.data('label'))button.data('label',$.trim(button.text()));
				if(op==='run')button.addClass('is-busy').prop('disabled',true).text('处理中…');
				else button.prop('disabled',true);
			}else if(button.data('label')){
				button.removeClass('is-busy').prop('disabled',false).text(button.data('label'));
			}else button.prop('disabled',false).removeClass('is-busy');
		});
	}
	var lastRunning=0;
	function refreshStatus(options){
		options=options||{};
		var box=statusBoxes(); if(!box.length)return;
		if(pending&&pending.readyState<4){if(!options.fast)pending.abort();else return;}
		if(!options.silent)paint(loadingHtml);
		pending=$.ajax({url:'?plugin/elasticFulltext/status'+(options.fast?'&fast=1':''),dataType:'json',cache:false,timeout:options.fast?8000:15000})
		.done(function(result){
			lastRunning=!!(result&&result.data&&result.data.running);
			if(result&&result.code&&result.data&&result.data.html)paint(result.data.html);
			else if(!options.silent)paint(msg(result,'状态读取失败'));
			if(lastRunning)startPoll();
		})
		.fail(function(xhr,status){if(status==='abort'||options.silent)return;paint('<span style="color:#d9822b">● Elasticsearch 连接失败：</span>'+(xhr.statusText||'请求超时'));});
	}
	function watchStatus(){
		watchTries=0;
		if(watchTimer)clearInterval(watchTimer);
		watchTimer=setInterval(function(){
			var box=statusBoxes();
			if(!box.length){if(++watchTries>40){clearInterval(watchTimer);watchTimer=0;}return;}
			if(isLoading(box)&&!(pending&&pending.readyState<4)) refreshStatus();
			clearInterval(watchTimer);
			watchTimer=0;
		},200);
	}
	function startPoll(){
		if(pollTimer)return;
		pollTimer=setInterval(function(){refreshStatus({silent:true,fast:true});},1500);
	}
	function finishBusy(){
		busy=false;stopPoll();
		bindBusyState();
	}
	function watchUntilDone(){
		startPoll();
		lastRunning=1;
		var idle=0,t=setInterval(function(){
			if(lastRunning)idle=0;else idle++;
			if(idle>=4){clearInterval(t);finishBusy();refreshStatus();}
			if(Date.now()-runStartedMs>700000){clearInterval(t);finishBusy();}
		},1500);
	}
	function stopPoll(){if(pollTimer){clearInterval(pollTimer);pollTimer=0;}}
	function afterMin(start,ms,fn){setTimeout(fn,Math.max(0,ms-(Date.now()-start)));}
	function run(button,operation){
		var start=Date.now();
		if(operation==='test'){
			var note=testMsg();
			if(!note.length){button.after('<span class="elastic-fulltext-test-msg"></span>');note=testMsg();}
			if(!button.data('label'))button.data('label',$.trim(button.text()));
			button.addClass('is-busy').prop('disabled',true).text('检测中…');
			note.removeClass('is-ok is-fail').addClass('is-wait').text('正在连接 Elasticsearch…');
			$.ajax({url:'?plugin/elasticFulltext/manage',type:'POST',dataType:'json',data:{operation:'test'},timeout:8000})
			.done(function(result){
				afterMin(start,900,function(){
					var ok=!!(result&&result.code);
					note.removeClass('is-wait').toggleClass('is-ok',ok).toggleClass('is-fail',!ok).text(msg(result,ok?'连接正常':'连接失败'));
					Tips.tips(msg(result,ok?'连接正常':'连接失败'),ok);
					if(ok)refreshStatus({silent:true});
				});
			})
			.fail(function(xhr){
				afterMin(start,900,function(){
					note.removeClass('is-wait is-ok').addClass('is-fail').text(xhr.statusText||'连接失败');
					Tips.tips(xhr.responseText||'连接失败',false);
				});
			})
			.always(function(){
				afterMin(start,900,function(){
					button.removeClass('is-busy').prop('disabled',false).text(button.data('label')||'连接测试');
				});
			});
			return;
		}
		busy=true;runStartedMs=Date.now();bindBusyState();startPoll();
		refreshStatus({silent:true,fast:true});
		$.ajax({url:'?plugin/elasticFulltext/manage',type:'POST',dataType:'json',data:{operation:operation},timeout:20000})
		.done(function(result){
			var idle=!!(result&&result.data&&(result.data.idle||result.data.done));
			Tips.tips(msg(result,result&&result.code?'已开始处理':'操作失败'),!!(result&&result.code), idle?4000:2000);
			refreshStatus({silent:true,fast:true});
			if(result&&result.code&&!idle)watchUntilDone();
			else finishBusy();
		})
		.fail(function(xhr){Tips.tips(xhr.responseText||'操作失败',false);finishBusy();refreshStatus({silent:true});});
	}
	$(document).off('click.elasticFulltext').on('click.elasticFulltext','.elastic-fulltext-action',function(){
		var button=$(this),operation=button.data('operation');
		if(button.prop('disabled')||button.hasClass('is-busy'))return;
		if(operation==='rebuild'&&!window.confirm('这会删除并重新建立全文索引，确认继续吗？'))return;
		run(button,operation);
	});
	Events.bind('plugin.config.formBefore',function(data,options){
		if(_.get(options,'id')!='app-config-elasticFulltext')return;
		data.serviceCheck={type:'html',value:'<button type="button" class="btn btn-primary btn-sm elastic-fulltext-action" data-operation="test">连接测试</button><span class="elastic-fulltext-test-msg"></span>',display:'服务检测'};
		data.runStatus={type:'html',value:'<div class="elastic-fulltext-status">'+loadingHtml+'</div>',display:'运行情况'};
	});
	Events.bind('plugin.config.formAfter',function(_this){
		if(!_this.formelasticFulltext)return;
		watchStatus();
	});
	if(statusBoxes().length)watchStatus();
	if(window.MutationObserver&&document.body){
		var kick=0;
		new MutationObserver(function(records){
			for(var i=0;i<records.length;i++){
				var nodes=records[i].addedNodes;
				for(var j=0;j<nodes.length;j++){
					var el=nodes[j];
					if(!el||el.nodeType!==1)continue;
					if((el.classList&&el.classList.contains('elastic-fulltext-status'))||(el.querySelector&&el.querySelector('.elastic-fulltext-status'))){
						clearTimeout(kick);
						kick=setTimeout(watchStatus,80);
						return;
					}
				}
			}
		}).observe(document.body,{childList:true,subtree:true});
	}
});
