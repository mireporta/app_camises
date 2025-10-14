// app.js - petites utilitats per al scanner
document.addEventListener('DOMContentLoaded', ()=>{
  document.querySelectorAll('input[name="sku"]').forEach(i=>{
    i.addEventListener('keyup', (e)=>{
      if(e.key === 'Enter'){
        let f = i.closest('form');
        if(f) f.submit();
      }
    });
  });
});
