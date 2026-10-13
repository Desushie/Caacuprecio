(function () {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function initToast(toast) {
    if (toast.dataset.toastReady) return;
    toast.dataset.toastReady = '1';

    let timer = null;
    const remove = function () {
      const stack = toast.parentElement;
      toast.remove();
      if (stack && !stack.children.length) stack.remove();
    };
    const dismiss = function () {
      window.clearTimeout(timer);
      if (toast.classList.contains('is-leaving')) return;
      toast.classList.add('is-leaving');
      if (reduceMotion) remove();
      else toast.addEventListener('transitionend', remove, { once: true });
    };
    const schedule = function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(dismiss, 6000);
    };
    const close = toast.querySelector('[data-auth-toast-close]');
    if (close) close.addEventListener('click', dismiss);
    toast.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
    toast.addEventListener('mouseleave', schedule);
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { toast.classList.add('is-visible'); });
    });
    schedule();
  }

  const stacks = document.querySelectorAll('.auth-toast-stack');
  for (let i = 1; i < stacks.length; i++) {
    while (stacks[i].firstElementChild) stacks[0].appendChild(stacks[i].firstElementChild);
    stacks[i].remove();
  }

  document.querySelectorAll('[data-auth-toast]').forEach(initToast);
})();
