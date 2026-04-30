
(function(){
  function ensureContainer(){
    let c = document.getElementById('toast-container');
    if(!c){
      c = document.createElement('div');
      c.id = 'toast-container';
      document.body.appendChild(c);
    }
    return c;
  }

  function toast(message, {duration=1800}={}){
    const c = ensureContainer();
    const el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role','status');
    el.setAttribute('aria-live','polite');
    el.textContent = message;
    c.appendChild(el);
    requestAnimationFrame(()=> el.classList.add('show'));
    setTimeout(()=>{
      el.classList.remove('show');
      el.classList.add('hide');
      setTimeout(()=> el.remove(), 250);
    }, duration);
  }

  async function copyText(text){
    try{
      if(navigator.clipboard && window.isSecureContext){
        await navigator.clipboard.writeText(text);
      }else{
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position='fixed';
        ta.style.opacity='0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }
      toast('コピーしました');
      return true;
    }catch(e){
      toast('コピーに失敗しました');
      return false;
    }
  }

  // data属性で自動コピー（共通化）
  document.addEventListener('click', (e)=>{
    const el = e.target.closest('[data-copy],[data-copy-target]');
    if(!el) return;
    e.preventDefault();
    let text = el.getAttribute('data-copy') || '';
    const sel = el.getAttribute('data-copy-target');
    if(!text && sel){
      const n = document.querySelector(sel);
      if(n) text = ('value' in n) ? n.value : (n.textContent || '');
    }
    if(text) copyText(text);
  });

  // グローバル公開（任意で直接呼び出し可）
  window.AppUI = { toast, copyText };
})();

