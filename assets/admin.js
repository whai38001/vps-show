(function(){
  function getPositiveInt(qs, key, def){
    var v = parseInt(qs.get(key)||'', 10);
    return (isFinite(v) && v > 0) ? v : def;
  }
  function init(){
    try{
      // Auto-submit for selects with class js-auto-submit
      document.addEventListener('change', function(e){
        var t = e.target;
        if(!(t instanceof HTMLSelectElement)) return;
        if(!t.classList.contains('js-auto-submit')) return;
        var f = t.form; if(f){ f.submit(); }
      });
      // Confirm dialog for forms with data-confirm
      document.addEventListener('submit', function(e){
        var f = e.target;
        if(!(f instanceof HTMLFormElement)) return;
        var msg = f.getAttribute('data-confirm');
        if(msg && !window.confirm(msg)){
          e.preventDefault();
        }
      });
      // Auto-search on typing for inputs with .js-auto-search (debounced)
      (function(){
        var timer = null;
        document.addEventListener('input', function(e){
          var t = e.target; if(!(t instanceof HTMLInputElement)) return;
          if(!t.classList.contains('js-auto-search')) return;
          clearTimeout(timer);
          timer = setTimeout(function(){
            var f = t.form; if(f){
              // reset page to 1 when searching
              var pageInput = f.querySelector('input[name="plans_page"]'); if(pageInput){ pageInput.value = '1'; }
              f.submit();
            }
          }, 400);
        });
      })();
      // Select-all for bulk checkboxes
      document.addEventListener('change', function(e){
        var t = e.target;
        if(!(t instanceof HTMLInputElement)) return;
        if(!t.classList.contains('js-select-all')) return;
        var table = t.closest('table'); if(!table) return;
        var rows = table.querySelectorAll('input.js-row-check');
        rows.forEach(function(cb){ cb.checked = t.checked; });
      });
      // Toggle bulk sections visibility based on radio selection
      document.addEventListener('change', function(e){
        var t = e.target;
        if(!(t instanceof HTMLInputElement)) return;
        if(t.name !== 'do') return;
        var form = t.closest('form'); if(!form) return;
        var showSort = (form.querySelector('input[name="do"]:checked')?.value === 'sort');
        form.querySelectorAll('[data-bulk-section]')?.forEach(function(el){
          var kind = el.getAttribute('data-bulk-section');
          var shouldShow = (kind === 'sort') ? showSort : (kind === 'billing' || kind==='stock');
          // Only show billing/stock when corresponding radio selected
          if (kind==='billing' || kind==='stock') { shouldShow = (form.querySelector('input[name="do"]:checked')?.value === kind); }
          el.style.display = shouldShow ? '' : 'none';
        });
      });
    }catch(e){}
  }
  document.addEventListener('DOMContentLoaded', init);
})();
