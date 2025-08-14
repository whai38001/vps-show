(function(){
  function qs(id){ return document.getElementById(id); }
  const btn = qs('btn-sync-stock');
  const btnRunCron = document.getElementById('btn-run-cron');
  const res = qs('sync-result');
  const log = qs('sync-log');
  const chkDryRun = document.querySelector('#stock-dry-run');
  const inputLimit = document.querySelector('#stock-limit');
  if (btn) {
    btn.addEventListener('click', async function(){
      if (!confirm('立即调用接口并更新库存状态？')) return;
      btn.disabled = true; if(res) res.textContent = '同步中...'; if(log){ log.style.display='none'; log.textContent=''; }
      try {
        const form = new FormData();
        const csrf = (document.querySelector('input[name="_csrf"]').value || '');
        form.append('_csrf', csrf);
        form.append('action', 'stock_sync_now');
        form.append('ajax', '1');
      // extra options
      if (chkDryRun && chkDryRun.checked) form.append('dry_run', '1');
      if (inputLimit && inputLimit.value) form.append('limit', String(inputLimit.value));
      const resp = await fetch(location.href, { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        let data = null; try { data = await resp.json(); } catch(e) {}
        if (!resp.ok || !data || typeof data.code === 'undefined') {
          if(res) res.textContent = '同步失败';
          if(log){ log.style.display='block'; log.textContent = 'HTTP ' + resp.status + '\n' + await resp.text(); }
        } else if (data.code !== 0) {
          if(res) res.textContent = '同步失败';
          if(log){ log.style.display='block'; log.textContent = data.message || '未知错误'; }
        } else {
        const isDry = !!data.data?.dry_run;
        if(res) res.textContent = (isDry?'演练成功':'同步成功') + '：更新 ' + (data.data?.updated||0) + '，未知 ' + (data.data?.unknown||0) + '，跳过 ' + (data.data?.skipped||0);
        if (!isDry) setTimeout(function(){ location.reload(); }, 600);
        }
      } catch (e) {
        console.error(e);
        if(res) res.textContent = '同步异常';
        if(log){ log.style.display='block'; log.textContent = String(e); }
      } finally {
        btn.disabled = false;
      }
    });
  }

  if (btnRunCron) {
    btnRunCron.addEventListener('click', async function(){
      btnRunCron.disabled = true;
      try {
        const form = new FormData();
        const csrf = (document.querySelector('input[name="_csrf"]').value || '');
        form.append('_csrf', csrf);
        form.append('action', 'stock_run_cron_once');
        form.append('ajax', '1');
        const resp = await fetch(location.href, { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        let data = null; try { data = await resp.json(); } catch(e) {}
        if (!resp.ok || !data || typeof data.code === 'undefined') {
          alert('执行失败: HTTP ' + resp.status);
        } else if (data.code !== 0) {
          alert('执行失败: ' + (data.message || 'Unknown'));
        } else {
          alert('执行成功\n' + (data.data?.output || ''));
          // 成功后刷新，展示最新“最近一次执行”与历史日志
          setTimeout(function(){ location.reload(); }, 500);
        }
      } catch (e) {
        alert('执行异常: ' + e);
      } finally {
        btnRunCron.disabled = false;
      }
    });
  }

  // Stock settings form: intercept submit to avoid inline onsubmit
  const form = qs('form-stock-settings');
  if (form) {
    form.addEventListener('submit', function(ev){
      if (!confirm('保存并可用于同步库存')) { ev.preventDefault(); }
    });
  }
})();
