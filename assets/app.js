(function(){
  function onScroll(){
    var nav = document.querySelector('.topnav');
    if(!nav) return;
    var hasScrolled = window.scrollY > 4;
    if(hasScrolled){ nav.classList.add('scrolled'); }
    else{ nav.classList.remove('scrolled'); }
  }
  function onResize(){
    // reserve space to avoid layout jump on sticky overlay background
    var nav = document.querySelector('.topnav');
    if(!nav) return;
    var rect = nav.getBoundingClientRect();
    document.documentElement.style.setProperty('--topnav-height', rect.height + 'px');
  }
  function initCompare(){
    var bar = document.getElementById('compare-bar');
    var modal = document.getElementById('compare-modal');
    if(!bar || !modal) return;
    var openBtn = bar.querySelector('[data-open]');
    var clearBtn = bar.querySelector('[data-clear]');
    var countEl = bar.querySelector('.count');
    var labelEl = bar.querySelector('.label');
    var rowsTbody = modal.querySelector('[data-rows]');
    var copyBtn = modal.querySelector('[data-copy]');
    var exportBtn = modal.querySelector('[data-export]');

    var MAX = 4;
    var selectedIds = new Set();
    // Restore from localStorage if any
    try{
      var saved = localStorage.getItem('compare:selected');
      if(saved){ JSON.parse(saved).slice(0,MAX).forEach(function(id){ selectedIds.add(String(id)); }); }
    }catch(e){}

    function updateBar(){
      var count = selectedIds.size;
      countEl.textContent = String(count);
      if(count > 0){ bar.classList.remove('hidden'); } else { bar.classList.add('hidden'); }
      if(openBtn){ openBtn.disabled = count < 2; }
      // Update label text like "已选 {n} 项"
      var tpl = labelEl ? (labelEl.getAttribute('data-template') || labelEl.textContent) : '';
      if(labelEl){ labelEl.textContent = tpl.replace(/\{n\}|\d+\s*selected/i, String(count)); }
      updateCopyExportState();
    }

    function syncCardCheckboxes(){
      document.querySelectorAll('.card[data-plan-id]').forEach(function(card){
        var id = card.getAttribute('data-plan-id');
        var cb = card.querySelector('.compare-toggle');
        if(!cb || !id) return;
        cb.checked = selectedIds.has(id);
        cb.disabled = !cb.checked && selectedIds.size >= MAX;
      });
      try{ localStorage.setItem('compare:selected', JSON.stringify(Array.from(selectedIds))); }catch(e){}
    }

    function collectPlanDataById(id){
      var card = document.querySelector('.card[data-plan-id="'+id+'"]');
      if(!card) return null;
      var vendor = card.querySelector('.card-header img')?.getAttribute('alt') || '';
      var title = card.querySelector('.card-header .size')?.textContent || '';
      var price = card.querySelector('.card-body .amount')?.textContent || '';
      var duration = card.querySelector('.card-body .duration')?.textContent || '';
      var location = card.querySelector('.card-body .meta')?.textContent?.replace(/^\s*📍\s*/, '') || '';
      // Prefer server-provided specs on label dataset, fallback to heuristics
      var label = card.querySelector('.compare-label');
      var cpu = label?.getAttribute('data-cpu') || '';
      var ram = label?.getAttribute('data-ram') || '';
      var storage = label?.getAttribute('data-storage') || '';
      // Try to read numeric specs from chips
      var cpuCores = null; var ramMb = null; var storageGb = null;
      var chips = card.querySelectorAll('.specs .chip');
      if (chips && chips.length) {
        var cpuText = chips[0]?.textContent || '';
        var ramText = chips[1]?.textContent || '';
        var storageText = chips[2]?.textContent || '';
        var m;
        if ((m = cpuText.match(/([0-9]+(?:\.[0-9]+)?)/))) cpuCores = parseFloat(m[1]);
        if ((m = ramText.match(/([0-9]+(?:\.[0-9]+)?)/))) ramMb = Math.round(parseFloat(m[1]) * 1024);
        if ((m = storageText.match(/([0-9]+)/))) storageGb = parseInt(m[1], 10);
      }
      if(!(cpu && ram && storage)){
        var features = Array.from(card.querySelectorAll('.card-body .features li')).map(function(li){ return li.textContent; });
        cpu = cpu || (features.find(function(f){ return /\bv\s*CPU|CPU\b|核心/i.test(f); }) || '');
        ram = ram || (features.find(function(f){ return /\bRAM\b|内存/i.test(f); }) || '');
        storage = storage || (features.find(function(f){ return /存储|SSD|NVMe|HDD|Storage/i.test(f); }) || '');
      }
      var orderHref = card.querySelector('.order-link')?.getAttribute('href') || '#';
      var logoSrc = card.querySelector('.card-header img')?.getAttribute('src') || '';
      return { id:id, vendor:vendor, title:title, price:price, duration:duration, location:location, cpu:cpu, ram:ram, storage:storage, cpuCores:cpuCores, ramMb:ramMb, storageGb:storageGb, orderHref:orderHref, logoSrc:logoSrc };
    }

    function renderModal(){
      rowsTbody.innerHTML = '';
      selectedIds.forEach(function(id){
        var p = collectPlanDataById(id);
        if(!p) return;
        var tr = document.createElement('tr');
        tr.innerHTML = ''+
          '<td>'+
            '<div class="row items-center gap8">'+
              (p.logoSrc ? '<img src="'+p.logoSrc+'" alt="" class="logo-xs">' : '')+
              '<div><div class="fw700">'+escapeHtml(p.title)+'</div><div class="small muted">'+escapeHtml(p.vendor)+'</div></div>'+
            '</div>'+
          '</td>'+
          '<td>'+escapeHtml(p.price)+' <span class="small muted">'+escapeHtml(p.duration)+'</span></td>'+
          '<td>'+escapeHtml(p.location)+'</td>'+
          '<td>'+formatCpu(p)+'</td>'+
          '<td>'+formatRam(p)+'</td>'+
          '<td>'+formatStorage(p)+'</td>'+
          '<td><a class="btn" href="'+p.orderHref+'" target="_blank" rel="nofollow noopener">'+(modal.getAttribute('data-order-label')||'Order')+'</a></td>';
        rowsTbody.appendChild(tr);
      });
    }

    function escapeHtml(s){
      return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]); });
    }

    function formatCpu(p){
      if (typeof p.cpuCores === 'number' && !isNaN(p.cpuCores) && p.cpuCores > 0) {
        return stripTrailingZeros(p.cpuCores) + ' ' + (modal.getAttribute('data-unit-vcpu')||'vCPU');
      }
      return escapeHtml(p.cpu||'');
    }
    function formatRam(p){
      if (typeof p.ramMb === 'number' && p.ramMb > 0) {
        var gb = p.ramMb / 1024;
        return stripTrailingZeros(gb.toFixed(1)) + ' ' + (modal.getAttribute('data-unit-gb')||'GB');
      }
      return escapeHtml(p.ram||'');
    }
    function formatStorage(p){
      if (typeof p.storageGb === 'number' && p.storageGb > 0) {
        return parseInt(p.storageGb,10) + ' ' + (modal.getAttribute('data-unit-gb')||'GB');
      }
      return escapeHtml(p.storage||'');
    }
    function stripTrailingZeros(num){
      var s = String(num);
      if (s.indexOf('.')>=0){ s = s.replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1'); }
      return s;
    }

    function formatCpuText(p){
      if (typeof p.cpuCores === 'number' && !isNaN(p.cpuCores) && p.cpuCores > 0) {
        return stripTrailingZeros(p.cpuCores) + ' ' + (modal.getAttribute('data-unit-vcpu')||'vCPU');
      }
      return String(p.cpu||'');
    }
    function formatRamText(p){
      if (typeof p.ramMb === 'number' && p.ramMb > 0) {
        var gb = p.ramMb / 1024;
        return stripTrailingZeros(gb.toFixed(1)) + ' ' + (modal.getAttribute('data-unit-gb')||'GB');
      }
      return String(p.ram||'');
    }
    function formatStorageText(p){
      if (typeof p.storageGb === 'number' && p.storageGb > 0) {
        return parseInt(p.storageGb,10) + ' ' + (modal.getAttribute('data-unit-gb')||'GB');
      }
      return String(p.storage||'');
    }

    function getCompareRows(){
      var rows = [];
      selectedIds.forEach(function(id){
        var p = collectPlanDataById(id);
        if(!p) return;
        rows.push({
          vendorPlan: (p.title||'') + (p.vendor?(' ('+p.vendor+')'):'') ,
          priceBilling: String(p.price||'') + (p.duration?(' '+p.duration):''),
          location: String(p.location||''),
          cpu: formatCpuText(p),
          ram: formatRamText(p),
          storage: formatStorageText(p),
          orderUrl: String(p.orderHref||'')
        });
      });
      return rows;
    }

    function getHeaderLabels(){
      var heads = Array.from(modal.querySelectorAll('thead th'));
      var labels = heads.slice(0, 6).map(function(th){ return (th.textContent||'').trim() || ''; });
      if (labels.length < 6 || labels.some(function(s){ return s === ''; })) {
        labels = ['Vendor / Plan','Price/Billing','Location','CPU','RAM','Storage'];
      }
      return labels;
    }

    function toCsv(rows){
      function esc(v){
        var s = String(v==null?'':v);
        if(/[",\n]/.test(s)){ s = '"' + s.replace(/"/g,'""') + '"'; }
        return s;
      }
      var header = getHeaderLabels().concat(['Order URL']);
      var lines = [header.map(esc).join(',')];
      rows.forEach(function(r){
        lines.push([r.vendorPlan,r.priceBilling,r.location,r.cpu,r.ram,r.storage,r.orderUrl].map(esc).join(','));
      });
      // Add BOM for Excel compatibility
      return '\uFEFF' + lines.join('\n');
    }

    function toTsv(rows){
      var header = getHeaderLabels().concat(['Order URL']);
      var lines = [header.join('\t')];
      rows.forEach(function(r){
        lines.push([r.vendorPlan,r.priceBilling,r.location,r.cpu,r.ram,r.storage,r.orderUrl].join('\t'));
      });
      return lines.join('\n');
    }

    function updateCopyExportState(){
      var has = selectedIds.size > 0;
      if(copyBtn){ copyBtn.disabled = !has; }
      if(exportBtn){ exportBtn.disabled = !has; }
    }

    function flashCopyFeedback(){
      if(!copyBtn) return;
      var original = copyBtn.textContent;
      var copied = copyBtn.getAttribute('data-copied-label') || 'Copied';
      copyBtn.textContent = copied;
      copyBtn.disabled = true;
      setTimeout(function(){ copyBtn.textContent = original; updateCopyExportState(); }, 1200);
    }

    function openModal(){
      renderModal();
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden','false');
    }
    function closeModal(){
      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden','true');
    }

    // Wire up card checkboxes
    document.addEventListener('change', function(e){
      var target = e.target;
      if(!(target instanceof HTMLInputElement)) return;
      if(!target.classList.contains('compare-toggle')) return;
      var card = target.closest('.card[data-plan-id]');
      if(!card) return;
      var id = card.getAttribute('data-plan-id');
      if(!id) return;
      if(target.checked){
        if(selectedIds.size >= MAX){ target.checked = false; return; }
        selectedIds.add(id);
      } else {
        selectedIds.delete(id);
      }
      syncCardCheckboxes();
      updateBar();
    });

    // Buttons
    clearBtn?.addEventListener('click', function(){
      selectedIds.clear();
      syncCardCheckboxes();
      updateBar();
    });
    openBtn?.addEventListener('click', function(){ if(!openBtn.disabled){ openModal(); } });
    modal.querySelectorAll('[data-close]').forEach(function(el){ el.addEventListener('click', closeModal); });

    copyBtn?.addEventListener('click', function(){
      var rows = getCompareRows();
      if(!rows.length) return;
      var tsv = toTsv(rows);
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(tsv).then(function(){ flashCopyFeedback(); }).catch(function(){
          // Fallback
          try {
            var ta = document.createElement('textarea');
            ta.value = tsv;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            flashCopyFeedback();
          } catch(e){}
        });
      } else {
        // Fallback
        try {
          var ta = document.createElement('textarea');
          ta.value = tsv;
          ta.style.position = 'fixed';
          ta.style.left = '-9999px';
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
          flashCopyFeedback();
        } catch(e){}
      }
    });

    exportBtn?.addEventListener('click', function(){
      var rows = getCompareRows();
      if(!rows.length) return;
      var csv = toCsv(rows);
      var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      var ts = new Date();
      var pad = function(n){ return n<10 ? '0'+n : ''+n; };
      var filename = 'compare-' + ts.getFullYear() + pad(ts.getMonth()+1) + pad(ts.getDate()) + '-' + pad(ts.getHours()) + pad(ts.getMinutes()) + pad(ts.getSeconds()) + '.csv';
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    });

    // Initial
    updateBar();
    syncCardCheckboxes();
  }
  function initCspSafeAutoSubmit(){
    try{
      document.addEventListener('change', function(e){
        var target = e.target;
        if(!(target instanceof HTMLSelectElement)) return;
        if(!target.classList.contains('js-auto-submit')) return;
        var form = target.form;
        if(form){
          // Reset page to 1 when per-page or filter changes
          var pageInput = form.querySelector('input[type="number"]');
          if(pageInput){ try{ pageInput.value = '1'; }catch(_e){} }
          form.submit();
        }
      });
      // Clear search button (front page)
      document.addEventListener('click', function(e){
        var t = e.target;
        if(!(t instanceof HTMLElement)) return;
        if(!t.matches('[data-clear-search]')) return;
        var form = t.closest('form'); if(!form) return;
        var input = form.querySelector('input[name="q"]'); if(!input) return;
        input.value = '';
        form.submit();
      });
    }catch(e){}
  }
  function initCspSafeConfirm(){
    try{
      document.addEventListener('submit', function(e){
        var form = e.target;
        if(!(form instanceof HTMLFormElement)) return;
        var msg = form.getAttribute('data-confirm');
        if(msg && !window.confirm(msg)){
          e.preventDefault();
        }
      });
    }catch(e){}
  }
  window.addEventListener('scroll', onScroll, {passive:true});
  window.addEventListener('resize', onResize);
  window.addEventListener('orientationchange', onResize);
  document.addEventListener('DOMContentLoaded', function(){ onResize(); onScroll(); initCompare(); initCspSafeAutoSubmit(); initCspSafeConfirm(); });
})();
